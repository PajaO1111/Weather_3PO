/**
 * Paprskový graf posledních 7 dní na stránce „Statistiky“.
 *
 * - Radar: 24 paprsků = hodiny místního dne (0 h nahoře, po směru hodin),
 *   každý ze 7 dní je jedna křivka; dnešek je zvýrazněn. Data jsou hodinové
 *   souhrny z api/history.php?resolution=hour (používá cache souhrnů).
 * - Vítr: větrná růžice (16 směrů × třídy rychlosti) z api/windrose.php.
 */
import { fetchJson, formatNumber } from './api.js';
import { themeColors } from './charts.js';
import { scaleColor } from './gauges.js';

/** Jak často se data znovu načtou (hodinové souhrny se mění pomalu). */
const RELOAD_MS = 15 * 60 * 1000;

/** Veličiny: pole v hodinových položkách, sloučení dvou položek do jedné hodiny (podzimní změna času). */
const METRICS = {
    temp: { field: 'temp_avg', name: 'Teplota (průměr hodiny)', unit: '°C', decimals: 1, merge: 'avg' },
    dew: { field: 'dew_point_avg', name: 'Rosný bod (průměr hodiny)', unit: '°C', decimals: 1, merge: 'avg' },
    humidity: { field: 'humidity_avg', name: 'Vlhkost (průměr hodiny)', unit: '%', decimals: 0, merge: 'avg', max: 100 },
    pressure: { field: 'pressure_avg', name: 'Tlak (průměr hodiny)', unit: 'hPa', decimals: 1, merge: 'avg' },
    gust: { field: 'gust_max', name: 'Nárazy (maximum hodiny)', unit: 'km/h', decimals: 0, merge: 'max', zero: true },
    precip: { field: 'precip', name: 'Srážky (úhrn hodiny)', unit: 'mm', decimals: 1, merge: 'sum', zero: true },
    solar: { field: 'solar_avg', name: 'Sluneční záření (průměr hodiny)', unit: 'W/m²', decimals: 0, merge: 'avg', zero: true },
    uv: { field: 'uv_max', name: 'UV index (maximum hodiny)', unit: '', decimals: 1, merge: 'max', zero: true },
};

/** Barvy dnů od nejstaršího po dnešek (dnešek výrazně oranžový). */
const DAY_COLORS = ['#ced4da', '#a5b4fc', '#91a7ff', '#74c0fc', '#3bc9db', '#38d9a9', '#f76707'];

/** Pořadí paprsků: ECharts kreslí radar proti směru hodin, proto 0, 23, 22 … 1. */
const HOUR_ORDER = [0, ...Array.from({ length: 23 }, (_, i) => 23 - i)];

function localParts(date, timezone) {
    const parts = {};
    for (const p of new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', hourCycle: 'h23',
    }).formatToParts(date)) {
        parts[p.type] = p.value;
    }
    return { date: `${parts.year}-${parts.month}-${parts.day}`, hour: Number(parts.hour) % 24 };
}

function shiftDate(isoDate, days) {
    const [y, m, d] = isoDate.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}

function dayLabel(isoDate, today) {
    if (isoDate === today) {
        return 'dnes';
    }
    const [y, m, d] = isoDate.split('-').map(Number);
    return new Intl.DateTimeFormat('cs-CZ', { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'numeric' })
        .format(new Date(Date.UTC(y, m - 1, d)));
}

/**
 * Rozdělí hodinové položky do dnů × 24 hodin (místní čas).
 * @returns {Map<string, (number|null)[]>}
 */
function byDayHour(items, def, days, timezone) {
    const acc = new Map(days.map((day) => [day, Array.from({ length: 24 }, () => [])]));
    for (const item of items) {
        const value = item[def.field];
        if (value === null || value === undefined) {
            continue;
        }
        const { date, hour } = localParts(new Date(item.time), timezone);
        if (acc.has(date)) {
            acc.get(date)[hour].push(Number(value));
        }
    }
    const result = new Map();
    for (const [day, hours] of acc) {
        result.set(day, hours.map((values) => {
            if (!values.length) {
                return null;
            }
            if (def.merge === 'max') {
                return Math.max(...values);
            }
            const sum = values.reduce((a, b) => a + b, 0);
            return def.merge === 'sum' ? sum : sum / values.length;
        }));
    }
    return result;
}

/** Zaokrouhlí rozsah osy na „hezká“ čísla. */
function niceRange(min, max, def) {
    if (def.zero) {
        min = 0;
    }
    const span = Math.max(max - min, def.unit === 'hPa' ? 4 : 1);
    const step = span <= 5 ? 1 : span <= 20 ? 2 : span <= 50 ? 5 : span <= 200 ? 20 : 100;
    let low = Math.floor(min / step) * step;
    let high = Math.ceil(max / step) * step;
    if (high === low) {
        high = low + step;
    }
    if (def.max !== undefined) {
        high = Math.min(high, def.max);
    }
    if (def.zero) {
        low = 0;
    }
    return [low, high];
}

/**
 * @param {HTMLElement} root  sekce #week s data-api-history, data-api-windrose, data-timezone
 */
export function createWeekPanel(root) {
    const element = document.getElementById('week-chart');
    const note = document.getElementById('week-note');
    const buttons = document.getElementById('week-metrics');
    const timezone = root.dataset.timezone || 'Europe/Prague';
    let chart = null;
    let metric = 'temp';
    let hourly = null;
    let rose = null;
    let range = null;
    let loadedAt = 0;
    let roseAt = 0;

    function instance() {
        if (!window.echarts) {
            throw new Error('Knihovna grafů se nenačetla.');
        }
        if (!chart) {
            chart = window.echarts.init(element, null, { renderer: 'canvas' });
        }
        return chart;
    }

    function renderRadar() {
        const def = METRICS[metric];
        const data = byDayHour(hourly, def, range.days, timezone);
        const values = [...data.values()].flat().filter((v) => v !== null);
        if (!values.length) {
            instance().clear();
            note.textContent = 'Za posledních 7 dní nejsou pro tuto veličinu data.';
            return;
        }
        const [low, high] = niceRange(Math.min(...values), Math.max(...values), def);
        const colors = themeColors();
        const unit = def.unit ? ` ${def.unit}` : '';
        const series = range.days.map((day, index) => {
            const today = index === range.days.length - 1;
            const color = DAY_COLORS[index] || DAY_COLORS[0];
            return {
                name: dayLabel(day, range.today),
                // Chybějící hodina (výpadek, budoucí hodiny dneška) = '-' → bod se nevykreslí.
                value: HOUR_ORDER.map((hour) => {
                    const v = data.get(day)[hour];
                    return v === null ? '-' : Number(v.toFixed(2));
                }),
                day,
                symbol: today ? 'circle' : 'none',
                symbolSize: 4,
                lineStyle: { width: today ? 3 : 1.5, color, opacity: today ? 1 : 0.85 },
                itemStyle: { color },
                areaStyle: today ? { color, opacity: 0.12 } : undefined,
                z: today ? 10 : index,
            };
        });
        instance().setOption({
            animation: false,
            textStyle: { color: colors.text },
            color: DAY_COLORS,
            legend: { bottom: 0, textStyle: { color: colors.text }, data: series.map((s) => s.name) },
            tooltip: {
                trigger: 'item',
                confine: true,
                formatter(p) {
                    const nums = data.get(p.data.day).filter((v) => v !== null);
                    if (!nums.length) {
                        return `<strong>${p.name}</strong><br>bez dat`;
                    }
                    const lines = [`<strong>${p.name}</strong> – ${def.name.toLowerCase()}`];
                    if (def.merge === 'sum') {
                        lines.push(`úhrn dne: ${formatNumber(nums.reduce((a, b) => a + b, 0), def.decimals)}${unit}`);
                    } else {
                        lines.push(`min ${formatNumber(Math.min(...nums), def.decimals)}${unit} · max ${formatNumber(Math.max(...nums), def.decimals)}${unit}`);
                        if (def.merge === 'avg') {
                            lines.push(`průměr ${formatNumber(nums.reduce((a, b) => a + b, 0) / nums.length, def.decimals)}${unit}`);
                        }
                    }
                    return lines.join('<br>');
                },
            },
            radar: {
                center: ['50%', '48%'],
                radius: '68%',
                startAngle: 90,
                splitNumber: 4,
                shape: 'circle',
                indicator: HOUR_ORDER.map((hour) => ({ name: hour % 3 === 0 ? `${hour} h` : '', min: low, max: high })),
                axisName: { color: colors.muted, fontSize: 11 },
                axisLine: { lineStyle: { color: colors.grid } },
                splitLine: { lineStyle: { color: colors.grid } },
                splitArea: { show: false },
                axisLabel: { show: false },
            },
            // Popisky stupnice na paprsku 0 h (střed = minimum osy).
            graphic: [{
                type: 'text', right: 8, top: 4,
                style: { text: `střed ${formatNumber(low, 0)}${unit} · okraj ${formatNumber(high, 0)}${unit}`, fill: colors.muted, fontSize: 11 },
            }],
            series: [{ type: 'radar', data: series }],
        }, { notMerge: true });
        note.textContent = `${def.name}, místní čas (0 h nahoře, po směru hodinových ručiček). Dnešek je zvýrazněn oranžově, chybějící hodiny se nevykreslí.`;
    }

    function renderRose() {
        const colors = themeColors();
        const n = rose.classes.length;
        instance().setOption({
            animation: false,
            textStyle: { color: colors.text },
            legend: { bottom: 0, textStyle: { color: colors.text }, data: rose.classes.map((c) => c.label) },
            tooltip: {
                trigger: 'item',
                confine: true,
                formatter: (p) => `<strong>${p.name}</strong><br>${p.marker} ${p.seriesName}: ${formatNumber(p.value, 1)} % měření`,
            },
            polar: { center: ['50%', '48%'], radius: '68%' },
            // Kategorie 0 (S) je uprostřed horní výseče: posun o polovinu výseče (11,25°).
            angleAxis: {
                type: 'category',
                data: rose.sectors,
                startAngle: 90 + 11.25,
                clockwise: true,
                axisLabel: { color: colors.muted, fontWeight: 'bold' },
                axisLine: { lineStyle: { color: colors.grid } },
                axisTick: { show: false },
                splitLine: { show: true, lineStyle: { color: colors.grid } },
            },
            radiusAxis: {
                type: 'value',
                axisLabel: { color: colors.muted, formatter: '{value} %', fontSize: 10 },
                axisLine: { show: false },
                splitLine: { lineStyle: { color: colors.grid } },
            },
            series: rose.classes.map((c, i) => ({
                type: 'bar',
                name: c.label,
                coordinateSystem: 'polar',
                stack: 'rose',
                data: c.percent,
                itemStyle: { color: scaleColor(n > 1 ? i / (n - 1) : 0) },
                barCategoryGap: '8%',
            })),
        }, { notMerge: true });
        const parts = [`Podíl měření podle směru a rychlosti za 7 dní (${rose.samples} měření)`];
        if (rose.prevailing) {
            parts.push(`převládá ${rose.prevailing}`);
        }
        parts.push(`bezvětří ${formatNumber(rose.calm, 1)} %`);
        if (rose.speedAvg !== null) {
            parts.push(`průměr ${formatNumber(rose.speedAvg, 1)} km/h, max ${formatNumber(rose.speedMax, 0)} km/h`);
        }
        note.textContent = parts.join(' · ');
    }

    function render() {
        try {
            if (metric === 'windrose') {
                if (rose) {
                    renderRose();
                }
            } else if (hourly) {
                renderRadar();
            }
        } catch (error) {
            note.textContent = error.message;
        }
    }

    async function loadHourly(force) {
        if (!force && hourly && Date.now() - loadedAt < RELOAD_MS) {
            return;
        }
        const today = localParts(new Date(), timezone).date;
        const days = Array.from({ length: 7 }, (_, i) => shiftDate(today, i - 6));
        const url = `${root.dataset.apiHistory}?from=${days[0]}&to=${today}&resolution=hour`;
        const response = await fetchJson(url, 30000);
        hourly = response.items;
        range = { today, days };
        loadedAt = Date.now();
    }

    async function loadRose(force) {
        if (!force && rose && Date.now() - roseAt < RELOAD_MS) {
            return;
        }
        rose = await fetchJson(root.dataset.apiWindrose, 30000);
        roseAt = Date.now();
    }

    async function load(force = false) {
        try {
            if (metric === 'windrose') {
                await loadRose(force);
            } else {
                await loadHourly(force);
            }
            render();
        } catch (error) {
            note.textContent = `Paprskový graf: ${error.message}`;
        }
    }

    buttons.addEventListener('click', (event) => {
        const button = event.target.closest('[data-metric]');
        if (!button) {
            return;
        }
        metric = button.dataset.metric;
        for (const other of buttons.querySelectorAll('[data-metric]')) {
            const active = other === button;
            other.classList.toggle('active', active);
            other.setAttribute('aria-pressed', String(active));
        }
        load();
    });
    document.addEventListener('weather:themechange', render);
    window.addEventListener('resize', () => chart && chart.resize());

    return { load };
}
