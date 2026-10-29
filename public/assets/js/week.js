/**
 * Paprskový graf 7 dní na stránce „Statistiky“.
 *
 * - Období: posledních 7 dní (výchozí) nebo zvolený ISO týden po–ne
 *   (parametr adresy ?week=RRRR-Www, funguje Zpět/Vpřed v prohlížeči).
 * - Radar: 24 paprsků = hodiny místního dne (0 h nahoře, po směru hodin),
 *   každý ze 7 dní je jedna křivka; dnešek je zvýrazněn. Data jsou hodinové
 *   souhrny z api/history.php?resolution=hour (používá cache souhrnů).
 * - Vítr: větrná růžice (16 směrů × třídy rychlosti) z api/windrose.php.
 */
import { fetchJson, formatNumber } from './api.js';
import { themeColors } from './charts.js';
import { scaleColor } from './gauges.js';
import { isoWeekOf, parseWeek, shiftDate, weekList } from './isoweek.js';

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

/** Kalendářní týden: rozlišitelné barvy po–ne (dnešek probíhajícího týdne se zvýrazní tloušťkou). */
const WEEKDAY_COLORS = ['#4dabf7', '#20c997', '#94d82d', '#fab005', '#fd7e14', '#e64980', '#7950f2'];

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

/** Krátké datum „19. 10.“ (případně s rokem). */
function shortDate(isoDate, withYear = false) {
    const [y, m, d] = isoDate.split('-').map(Number);
    return withYear ? `${d}. ${m}. ${y}` : `${d}. ${m}.`;
}

/** Písmo, poloměr a hustota popisků podle skutečné velikosti grafu (velký monitor = čitelnější). */
function chartScale(element) {
    const size = Math.min(element.clientWidth || 0, element.clientHeight || 0);
    if (size >= 820) {
        return { key: 'xl', font: 15, legend: 14, radius: '74%', allHours: true, symbol: 6 };
    }
    if (size >= 600) {
        return { key: 'lg', font: 13, legend: 13, radius: '72%', allHours: true, symbol: 5 };
    }
    return { key: 'md', font: 11, legend: 12, radius: '68%', allHours: false, symbol: 4 };
}

/**
 * @param {HTMLElement} root  sekce #week s data-api-history, data-api-windrose, data-timezone, data-first-year
 */
export function createWeekPanel(root) {
    const element = document.getElementById('week-chart');
    const note = document.getElementById('week-note');
    const title = document.getElementById('week-title');
    const buttons = document.getElementById('week-metrics');
    const periodBox = document.getElementById('week-period');
    const select = document.getElementById('week-select');
    const prev = document.getElementById('week-prev');
    const next = document.getElementById('week-next');
    const latest = document.getElementById('week-latest');
    const status = document.getElementById('week-status');
    const timezone = root.dataset.timezone || 'Europe/Prague';
    const roseBase = (root.dataset.apiWindrose || '').split('?')[0];
    /** Odpovědi API podle druhu a období: klíč → { data, at }. */
    const cache = new Map();
    let firstYear = Number(root.dataset.firstYear) || isoWeekOf(todayDate()).year - 1;
    let chart = null;
    let metric = 'temp';
    let period = null;
    let hourly = null;
    let rose = null;
    let requestId = 0;
    let keys = [''];
    let scaleKey = '';

    function todayDate() {
        return localParts(new Date(), timezone).date;
    }

    /**
     * Období grafu: posledních 7 dní (key '') nebo ISO týden po–ne.
     * Budoucí nebo neplatný týden → posledních 7 dní s příznakem invalid.
     */
    function buildPeriod(key) {
        const today = todayDate();
        const week = key ? parseWeek(key) : null;
        if (!week || week.days[0] > today) {
            const days = Array.from({ length: 7 }, (_, i) => shiftDate(today, i - 6));
            return { key: '', today, days, from: days[0], to: today, week: null, current: true, invalid: Boolean(key), label: 'Posledních 7 dní' };
        }
        const current = week.days.includes(today);
        return {
            key: week.key,
            today,
            days: week.days,
            from: week.days[0],
            to: current ? today : week.days[6],
            week,
            current,
            invalid: false,
            label: `Týden ${week.week}/${week.year} (${shortDate(week.days[0])} – ${shortDate(week.days[6], true)})`,
        };
    }

    function periodText() {
        return period.key ? `týden ${period.week.week}/${period.week.year}` : 'posledních 7 dní';
    }

    function fillSelect() {
        const weeks = weekList(todayDate(), firstYear);
        select.replaceChildren(new Option('Posledních 7 dní', ''));
        keys = [''];
        let group = null;
        for (const week of weeks) {
            if (!group || group.dataset.year !== String(week.year)) {
                group = document.createElement('optgroup');
                group.label = `ISO rok ${week.year}`;
                group.dataset.year = String(week.year);
                select.append(group);
            }
            group.append(new Option(`Týden ${week.week} · ${shortDate(week.days[0])} – ${shortDate(week.days[6])}`, week.key));
            keys.push(week.key);
        }
        // Starší týden z adresy, který není v seznamu, se doplní na konec.
        if (period.key && !keys.includes(period.key)) {
            select.append(new Option(period.label, period.key));
            keys.push(period.key);
        }
    }

    function updateControls() {
        if (period.key && !keys.includes(period.key)) {
            fillSelect();
        }
        select.value = period.key;
        const index = keys.indexOf(period.key);
        prev.disabled = index >= keys.length - 1;
        next.disabled = index <= 0;
        latest.hidden = !period.key;
        title.textContent = `${period.label} – denní chod`;
        element.setAttribute('aria-label', `Paprskový graf – ${period.label.toLowerCase()}`);
    }

    function setUrl(key, push) {
        const url = new URL(window.location.href);
        if (key) {
            url.searchParams.set('week', key);
        } else {
            url.searchParams.delete('week');
        }
        if (url.href !== window.location.href) {
            window.history[push ? 'pushState' : 'replaceState'](window.history.state, '', url);
        }
    }

    function choose(key) {
        period = buildPeriod(key);
        status.textContent = '';
        setUrl(period.key, true);
        updateControls();
        load();
    }

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
        // Budoucí dny probíhajícího týdne se nekreslí vůbec.
        const days = period.days.filter((day) => day <= period.today);
        const data = byDayHour(hourly, def, days, timezone);
        const values = [...data.values()].flat().filter((v) => v !== null);
        if (!values.length) {
            instance().clear();
            note.textContent = `Za ${periodText()} nejsou pro tuto veličinu data.`;
            return;
        }
        const scale = chartScale(element);
        scaleKey = scale.key;
        const [low, high] = niceRange(Math.min(...values), Math.max(...values), def);
        const colors = themeColors();
        const unit = def.unit ? ` ${def.unit}` : '';
        const palette = period.key ? WEEKDAY_COLORS : DAY_COLORS;
        const series = days.map((day, index) => {
            const today = day === period.today;
            const color = period.key ? palette[period.days.indexOf(day)] : (palette[index] || palette[0]);
            return {
                name: dayLabel(day, period.today),
                // Chybějící hodina (výpadek, budoucí hodiny dneška) = '-' → bod se nevykreslí.
                value: HOUR_ORDER.map((hour) => {
                    const v = data.get(day)[hour];
                    return v === null ? '-' : Number(v.toFixed(2));
                }),
                day,
                symbol: today ? 'circle' : 'none',
                symbolSize: scale.symbol,
                lineStyle: { width: today ? 3.5 : (period.key ? 2 : 1.5), color, opacity: today ? 1 : 0.9 },
                itemStyle: { color },
                areaStyle: today ? { color, opacity: 0.12 } : undefined,
                z: today ? 10 : index,
            };
        });
        instance().setOption({
            animation: false,
            textStyle: { color: colors.text, fontSize: scale.font },
            color: palette,
            legend: {
                bottom: 0,
                itemGap: scale.font,
                textStyle: { color: colors.text, fontSize: scale.legend },
                data: series.map((s) => s.name),
            },
            tooltip: {
                trigger: 'item',
                confine: true,
                textStyle: { fontSize: scale.font },
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
                    if (nums.length < 24 && def.merge !== 'sum') {
                        lines.push(`<small>data za ${nums.length} z 24 h</small>`);
                    }
                    return lines.join('<br>');
                },
            },
            radar: {
                center: ['50%', '48%'],
                radius: scale.radius,
                startAngle: 90,
                splitNumber: 4,
                shape: 'circle',
                indicator: HOUR_ORDER.map((hour) => ({
                    name: scale.allHours || hour % 3 === 0 ? `${hour} h` : '',
                    min: low,
                    max: high,
                })),
                axisName: { color: colors.muted, fontSize: scale.font },
                axisLine: { lineStyle: { color: colors.grid } },
                splitLine: { lineStyle: { color: colors.grid } },
                splitArea: { show: false },
                axisLabel: { show: false },
            },
            // Popisky stupnice na paprsku 0 h (střed = minimum osy).
            graphic: [{
                type: 'text', right: 8, top: 4,
                style: { text: `střed ${formatNumber(low, 0)}${unit} · okraj ${formatNumber(high, 0)}${unit}`, fill: colors.muted, fontSize: scale.font },
            }],
            series: [{ type: 'radar', data: series }],
        }, { notMerge: true });

        const parts = [`${def.name}, místní čas (0 h nahoře, po směru hodinových ručiček).`];
        if (!period.key) {
            parts.push('Dnešek je zvýrazněn oranžově.');
        } else if (period.current) {
            parts.push('Probíhající týden – data do teď, dnešek je zvýrazněn.');
        } else {
            parts.push('Barvy odlišují dny po–ne.');
        }
        const missing = days.filter((day) => data.get(day).every((v) => v === null)).length;
        if (missing) {
            parts.push(`Bez dat ${missing} z ${days.length} dní – neúplné období; chybějící hodiny ani dny se nevykreslí (nejde o nulu).`);
        } else {
            parts.push('Chybějící hodiny se nevykreslí.');
        }
        note.textContent = parts.join(' ');
    }

    function renderRose() {
        const colors = themeColors();
        const scale = chartScale(element);
        scaleKey = scale.key;
        const n = rose.classes.length;
        instance().setOption({
            animation: false,
            textStyle: { color: colors.text, fontSize: scale.font },
            legend: { bottom: 0, textStyle: { color: colors.text, fontSize: scale.legend }, data: rose.classes.map((c) => c.label) },
            tooltip: {
                trigger: 'item',
                confine: true,
                textStyle: { fontSize: scale.font },
                formatter: (p) => `<strong>${p.name}</strong><br>${p.marker} ${p.seriesName}: ${formatNumber(p.value, 1)} % měření`,
            },
            polar: { center: ['50%', '48%'], radius: scale.radius },
            // Kategorie 0 (S) je uprostřed horní výseče: posun o polovinu výseče (11,25°).
            angleAxis: {
                type: 'category',
                data: rose.sectors,
                startAngle: 90 + 11.25,
                clockwise: true,
                axisLabel: { color: colors.muted, fontWeight: 'bold', fontSize: scale.font + 1 },
                axisLine: { lineStyle: { color: colors.grid } },
                axisTick: { show: false },
                splitLine: { show: true, lineStyle: { color: colors.grid } },
            },
            radiusAxis: {
                type: 'value',
                axisLabel: { color: colors.muted, formatter: '{value} %', fontSize: scale.font - 1 },
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
        if (!rose.samples) {
            note.textContent = `Za ${periodText()} nejsou data o větru.`;
            return;
        }
        const scope = period.current && period.key ? `${periodText()} (probíhající, do teď)` : periodText();
        const parts = [`Podíl měření podle směru a rychlosti za ${scope} (${rose.samples} měření)`];
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

    /** Uzavřený (minulý) týden se už nemění – z cache se bere bez omezení. */
    async function cached(key, closed, url, force) {
        const hit = cache.get(key);
        if (hit && !force && (closed || Date.now() - hit.at < RELOAD_MS)) {
            return hit.data;
        }
        const data = await fetchJson(url, 30000);
        cache.delete(key);
        cache.set(key, { data, at: Date.now() });
        if (cache.size > 40) {
            cache.delete(cache.keys().next().value);
        }
        return data;
    }

    async function load(force = false) {
        const id = ++requestId;
        // Znovu spočítat (po půlnoci se posune „dnes“ i posledních 7 dní).
        period = buildPeriod(period.key);
        updateControls();
        const current = period;
        const closed = Boolean(current.key) && !current.current;
        const tag = current.key || `latest:${current.to}`;
        try {
            if (metric === 'windrose') {
                const url = current.key ? `${roseBase}?from=${current.from}&to=${current.to}` : root.dataset.apiWindrose;
                const data = await cached(`rose|${tag}`, closed, url, force);
                if (id !== requestId) {
                    return;
                }
                rose = data;
            } else {
                const url = `${root.dataset.apiHistory}?from=${current.from}&to=${current.to}&resolution=hour`;
                const data = await cached(`hour|${tag}`, closed, url, force);
                if (id !== requestId) {
                    return;
                }
                hourly = data.items;
            }
            render();
        } catch (error) {
            if (id === requestId) {
                note.textContent = `Paprskový graf: ${error.message}`;
            }
        }
    }

    /** Roky s daty (z api/stats.php) – výběr týdnů začne prvním z nich. */
    function setYears(years) {
        const numbers = (years || []).map(Number).filter((y) => Number.isInteger(y) && y >= 2000);
        if (!numbers.length || Math.min(...numbers) === firstYear) {
            return;
        }
        firstYear = Math.min(...numbers);
        fillSelect();
        updateControls();
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
    select.addEventListener('change', () => choose(select.value));
    prev.addEventListener('click', () => choose(keys[keys.indexOf(period.key) + 1]));
    next.addEventListener('click', () => choose(keys[keys.indexOf(period.key) - 1]));
    latest.addEventListener('click', () => choose(''));
    window.addEventListener('popstate', () => {
        const key = new URLSearchParams(window.location.search).get('week') || '';
        if (key !== period.key) {
            period = buildPeriod(key);
            status.textContent = '';
            load();
        }
    });
    document.addEventListener('weather:themechange', render);
    let resizeTimer = 0;
    window.addEventListener('resize', () => {
        if (!chart) {
            return;
        }
        chart.resize();
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (chartScale(element).key !== scaleKey) {
                render();
            }
        }, 150);
    });

    // Výchozí období z adresy (?week=RRRR-Www); neplatné se nahradí posledními 7 dny.
    period = buildPeriod(new URLSearchParams(window.location.search).get('week'));
    if (period.invalid) {
        setUrl('', false);
        status.textContent = 'Týden z adresy není platný – zobrazeno posledních 7 dní.';
    }
    fillSelect();
    updateControls();
    periodBox.hidden = false;

    return { load, setYears };
}
