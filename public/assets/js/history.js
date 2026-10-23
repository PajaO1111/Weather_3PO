/**
 * Stránka „Historie“: výběr období, souhrn, grafy a tabulka z api/history.php.
 *
 * - Formulář funguje i bez JS (GET na history.php); zde jen načítáme data bez
 *   obnovení stránky a adresu v prohlížeči aktualizujeme (sdílitelný odkaz, Zpět/Vpřed).
 * - Grafy jsou propojené (echarts.connect): přiblížení jednoho přiblíží všechny.
 * - Tabulka se vykreslí až po rozbalení, nejnovější hodnoty nahoře.
 */
import { fetchJson, fillFields, formatNumber, formatTime } from './api.js';
import { themeColors } from './charts.js';

const root = document.getElementById('history');
const form = document.getElementById('history-form');
const inputs = { from: form.elements.from, to: form.elements.to, resolution: form.elements.resolution };
const ui = {
    error: document.getElementById('error'),
    loading: document.getElementById('loading'),
    rangeLabel: document.getElementById('range-label'),
    chartNote: document.getElementById('chart-note'),
    csv: document.getElementById('csv'),
    submit: document.getElementById('submit'),
    tableDetails: document.getElementById('table-details'),
    table: document.getElementById('data-table'),
    tableNote: document.getElementById('table-note'),
};
const apiUrl = root.dataset.apiHistory;
const pageUrl = root.dataset.pageUrl;
const today = root.dataset.today;
const limits = JSON.parse(root.dataset.limits || '{}');
const rawGapMs = (Number(root.dataset.chartGapMinutes) || 30) * 60 * 1000;

const HOUR = 3600 * 1000;
const DAY = 24 * HOUR;
const RESOLUTION_LABELS = { raw: 'po 5 minutách', hour: 'po hodinách', day: 'po dnech' };

/**
 * Definice grafů. Pro každý graf dvě varianty: raw (jednotlivá měření)
 * a agg (hodinové/denní agregáty). field = klíč v items z API.
 */
const CHARTS = {
    temperature: {
        raw: {
            axes: [{ name: '°C' }],
            series: [
                { field: 'temp_c', name: 'Teplota', unit: '°C', color: '#d9480f' },
                { field: 'dew_point', name: 'Rosný bod', unit: '°C', color: '#1c7ed6' },
            ],
        },
        agg: {
            axes: [{ name: '°C' }],
            series: [
                { field: 'temp_max', name: 'Maximum', unit: '°C', color: '#e03131' },
                { field: 'temp_avg', name: 'Průměr', unit: '°C', color: '#f08c00' },
                { field: 'temp_min', name: 'Minimum', unit: '°C', color: '#1c7ed6' },
                { field: 'dew_point_avg', name: 'Rosný bod', unit: '°C', color: '#15aabf', dashed: true },
            ],
        },
    },
    humidity: {
        raw: {
            axes: [{ name: '%', min: 0, max: 100 }],
            series: [{ field: 'humidity', name: 'Vlhkost', unit: '%', color: '#1098ad', digits: 0 }],
        },
        agg: {
            axes: [{ name: '%', min: 0, max: 100 }],
            series: [
                { field: 'humidity_max', name: 'Maximum', unit: '%', color: '#1864ab', digits: 0, thin: true },
                { field: 'humidity_avg', name: 'Průměr', unit: '%', color: '#1098ad', digits: 0 },
                { field: 'humidity_min', name: 'Minimum', unit: '%', color: '#66d9e8', digits: 0, thin: true },
            ],
        },
    },
    pressure: {
        raw: {
            axes: [{ name: 'hPa' }],
            series: [{ field: 'pressure', name: 'Tlak', unit: 'hPa', color: '#7048e8' }],
        },
        agg: {
            axes: [{ name: 'hPa' }],
            series: [
                { field: 'pressure_max', name: 'Maximum', unit: 'hPa', color: '#9775fa', thin: true },
                { field: 'pressure_avg', name: 'Průměr', unit: 'hPa', color: '#7048e8' },
                { field: 'pressure_min', name: 'Minimum', unit: 'hPa', color: '#b197fc', thin: true },
            ],
        },
    },
    wind: {
        raw: {
            axes: [{ name: 'km/h', min: 0 }],
            series: [
                { field: 'wind_speed', name: 'Vítr', unit: 'km/h', color: '#0ca678' },
                { field: 'wind_gust', name: 'Nárazy', unit: 'km/h', color: '#f08c00', type: 'scatter' },
            ],
        },
        agg: {
            axes: [{ name: 'km/h', min: 0 }],
            series: [
                { field: 'wind_avg', name: 'Průměr', unit: 'km/h', color: '#0ca678' },
                { field: 'wind_max', name: 'Max. průměrný', unit: 'km/h', color: '#087f5b', thin: true },
                { field: 'gust_max', name: 'Nejsilnější náraz', unit: 'km/h', color: '#f08c00', type: 'scatter' },
            ],
        },
    },
    rain: {
        raw: {
            axes: [{ name: 'mm', min: 0 }, { name: 'mm/h', min: 0 }],
            series: [
                { field: 'precip_total', name: 'Denní úhrn (čítač)', unit: 'mm', color: '#1971c2', area: true },
                { field: 'precip_rate', name: 'Intenzita', unit: 'mm/h', color: '#66d9e8', type: 'bar', axis: 1 },
            ],
        },
        agg: {
            axes: [{ name: 'mm', min: 0 }, { name: 'mm/h', min: 0 }],
            series: [
                { field: 'precip', name: 'Úhrn', unit: 'mm', color: '#1971c2', type: 'bar' },
                { field: 'precip_rate_max', name: 'Max. intenzita', unit: 'mm/h', color: '#66d9e8', axis: 1, thin: true },
            ],
        },
    },
    sun: {
        raw: {
            axes: [{ name: 'W/m²', min: 0 }, { name: 'UV', min: 0 }],
            series: [
                { field: 'solar_radiation', name: 'Záření', unit: 'W/m²', color: '#fab005', area: true, digits: 0 },
                { field: 'uv', name: 'UV index', unit: '', color: '#ae3ec9', axis: 1 },
            ],
        },
        agg: {
            axes: [{ name: 'W/m²', min: 0 }, { name: 'UV', min: 0 }],
            series: [
                { field: 'solar_max', name: 'Max. záření', unit: 'W/m²', color: '#f59f00', digits: 0, thin: true },
                { field: 'solar_avg', name: 'Prům. záření', unit: 'W/m²', color: '#fab005', area: true, digits: 0 },
                { field: 'uv_max', name: 'Max. UV', unit: '', color: '#ae3ec9', axis: 1 },
            ],
        },
    },
};

/** Sloupce tabulky: [klíč, nadpis, počet desetinných míst]. */
const TABLE_COLUMNS = {
    raw: [
        ['temp_c', 'Teplota °C', 1], ['dew_point', 'Rosný bod °C', 1], ['humidity', 'Vlhkost %', 0],
        ['pressure', 'Tlak hPa', 1], ['wind_speed', 'Vítr km/h', 1], ['wind_gust', 'Náraz km/h', 1],
        ['winddir', 'Směr °', 0], ['precip_rate', 'Intenzita mm/h', 1], ['precip_total', 'Úhrn dne mm', 1],
        ['uv', 'UV', 1], ['solar_radiation', 'Záření W/m²', 0],
    ],
    agg: [
        ['temp_avg', 'T prům. °C', 1], ['temp_min', 'T min °C', 1], ['temp_max', 'T max °C', 1],
        ['dew_point_avg', 'Rosný bod °C', 1], ['humidity_avg', 'Vlhkost %', 0], ['pressure_avg', 'Tlak hPa', 1],
        ['wind_avg', 'Vítr km/h', 1], ['gust_max', 'Náraz max km/h', 1], ['precip', 'Srážky mm', 1],
        ['uv_max', 'UV max', 1], ['solar_max', 'Záření max W/m²', 0], ['samples', 'Měření', 0],
    ],
};

const state = { data: null, requestId: 0, tableDirty: true };
const charts = new Map();

// ---------------------------------------------------------------------------
// Data a stav formuláře
// ---------------------------------------------------------------------------

/** Aktuální hodnoty formuláře jako URLSearchParams (bez prázdných). */
function formParams() {
    const params = new URLSearchParams();
    params.set('from', inputs.from.value);
    params.set('to', inputs.to.value);
    params.set('resolution', inputs.resolution.value || 'auto');
    return params;
}

/** Nastaví formulář podle parametrů v URL (pro Zpět/Vpřed); chybějící = výchozích 7 dní jako na serveru. */
function applyParams(params) {
    inputs.to.value = params.get('to') || today;
    inputs.from.value = params.get('from') || addDays(inputs.to.value, -6);
    inputs.resolution.value = ['auto', 'raw', 'hour', 'day'].includes(params.get('resolution')) ? params.get('resolution') : 'auto';
}

function updateCsvLink(params) {
    const csv = new URLSearchParams(params);
    csv.set('format', 'csv');
    ui.csv.href = `${apiUrl}?${csv}`;
}

function setError(message) {
    ui.error.textContent = message || '';
    ui.error.classList.toggle('d-none', !message);
}

function setLoading(on) {
    ui.loading.classList.toggle('d-none', !on);
    ui.submit.disabled = on;
    root.setAttribute('aria-busy', on ? 'true' : 'false');
}

/** Načte data pro aktuální formulář a vše překreslí. */
async function load() {
    const params = formParams();
    updateCsvLink(params);
    if (inputs.from.value && inputs.to.value && inputs.from.value > inputs.to.value) {
        setError('Datum „Od“ nesmí být po datu „Do“.');
        return;
    }
    const requestId = ++state.requestId;
    setError('');
    setLoading(true);
    try {
        const data = await fetchJson(`${apiUrl}?${params}`, 30000);
        if (requestId !== state.requestId) {
            return; // mezitím byl odeslán novější dotaz
        }
        state.data = data;
        state.tableDirty = true;
        render(data);
    } catch (error) {
        if (requestId === state.requestId) {
            setError(error.message || 'Data se nepodařilo načíst.');
        }
    } finally {
        if (requestId === state.requestId) {
            setLoading(false);
        }
    }
}

// ---------------------------------------------------------------------------
// Rychlá volba období (data jako RRRR-MM-DD, počítáno v UTC, aby nevadil posun času)
// ---------------------------------------------------------------------------

function parseDate(text) {
    const [y, m, d] = text.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d));
}

function toDateText(date) {
    return date.toISOString().slice(0, 10);
}

function addDays(text, days) {
    const date = parseDate(text);
    date.setUTCDate(date.getUTCDate() + days);
    return toDateText(date);
}

function presetRange(key) {
    const t = parseDate(today);
    const y = t.getUTCFullYear();
    const m = t.getUTCMonth();
    switch (key) {
        case 'today': return [today, today];
        case 'yesterday': return [addDays(today, -1), addDays(today, -1)];
        case '7d': return [addDays(today, -6), today];
        case '30d': return [addDays(today, -29), today];
        case 'month': return [toDateText(new Date(Date.UTC(y, m, 1))), today];
        case 'prev-month': return [toDateText(new Date(Date.UTC(y, m - 1, 1))), toDateText(new Date(Date.UTC(y, m, 0)))];
        case 'year': return [toDateText(new Date(Date.UTC(y, 0, 1))), today];
        case '365d': return [addDays(today, -((limits.maxDays || 366) - 1)), today];
        default: return null;
    }
}

// ---------------------------------------------------------------------------
// Vykreslení
// ---------------------------------------------------------------------------

function pluralDays(n) {
    return plural(n, 'den', 'dny', 'dní');
}

/** České skloňování podle počtu: 1 den, 2–4 dny, 5+ dní. */
function plural(n, one, few, many) {
    if (n === 1) return `1 ${one}`;
    if (n >= 2 && n <= 4) return `${n} ${few}`;
    return `${n} ${many}`;
}

function render(data) {
    fillFields(root, data);
    const range = data.range || {};
    const parts = [
        range.fromDate === range.toDate
            ? formatTime(range.fromDate, 'date')
            : `${formatTime(range.fromDate, 'date')} – ${formatTime(range.toDate, 'date')}`,
        pluralDays(range.days || 0),
        RESOLUTION_LABELS[data.resolution] || data.resolution,
    ];
    ui.rangeLabel.textContent = parts.join(' · ');

    const notes = [];
    const meta = data.meta || {};
    if (!data.items || data.items.length === 0) {
        notes.push('Za zvolené období nejsou v databázi žádná měření.');
    }
    if (meta.truncated) {
        notes.push('Zobrazena jen část měření (limit počtu bodů) – zvolte kratší období nebo hodinovou podrobnost.');
    }
    if (meta.conflicts) {
        notes.push(`Rozporná duplicitní měření (${meta.conflicts}) jsou vynechána.`);
    }
    ui.chartNote.textContent = notes.join(' ');

    renderCharts(data);
    if (ui.tableDetails.open) {
        renderTable();
    }
}

/** Posun bodu na ose X: denní hodnota uprostřed dne, hodinová uprostřed hodiny. */
function offsetFor(resolution) {
    if (resolution === 'day') return DAY / 2;
    if (resolution === 'hour') return HOUR / 2;
    return 0;
}

function gapFor(resolution) {
    if (resolution === 'day') return 2 * DAY;
    if (resolution === 'hour') return 3 * HOUR;
    return rawGapMs;
}

/** Body [čas, hodnota]; v mezeře delší než gapMs vloží null, aby se čára přerušila. */
function toPoints(items, field, offset, gapMs) {
    const points = [];
    let previous = null;
    for (const item of items) {
        const time = Date.parse(item.time) + offset;
        if (previous !== null && time - previous > gapMs) {
            points.push([previous + 1, null]);
        }
        points.push([time, item[field] ?? null]);
        previous = time;
    }
    return points;
}

function ensureChart(id) {
    if (!window.echarts) {
        throw new Error('Knihovna grafů se nenačetla.');
    }
    let chart = charts.get(id);
    if (!chart) {
        chart = window.echarts.init(document.getElementById(`chart-${id}`), null, { renderer: 'canvas' });
        chart.group = 'history';
        charts.set(id, chart);
        window.echarts.connect('history');
    }
    return chart;
}

function renderCharts(data) {
    if (!data) {
        return;
    }
    try {
        for (const id of Object.keys(CHARTS)) {
            renderChart(id, data);
        }
    } catch (error) {
        setError(error.message);
    }
}

function renderChart(id, data) {
    const resolution = data.resolution;
    const config = CHARTS[id][resolution === 'raw' ? 'raw' : 'agg'];
    const items = data.items || [];
    const offset = offsetFor(resolution);
    const gapMs = gapFor(resolution);
    const colors = themeColors();
    const range = data.range || {};
    const spanMs = Date.parse(range.to) - Date.parse(range.from);
    const shortSpan = spanMs <= 2 * DAY;
    const series = config.series.map((def) => ({ def, points: toPoints(items, def.field, offset, gapMs) }));
    const empty = series.every((s) => s.points.every((p) => p[1] === null));

    ensureChart(id).setOption({
        animation: false,
        textStyle: { color: colors.text },
        title: empty
            ? { text: 'Žádná data', left: 'center', top: 'middle', textStyle: { color: colors.muted, fontSize: 14, fontWeight: 'normal' } }
            : { show: false },
        grid: { left: 52, right: config.axes.length > 1 ? 48 : 16, top: 52, bottom: id === 'temperature' ? 56 : 28 },
        // Jeden řádek legendy (na mobilu posuvný), aby nepřekrýval graf.
        legend: { type: 'scroll', top: 0, textStyle: { color: colors.text }, pageTextStyle: { color: colors.muted } },
        tooltip: {
            trigger: 'axis',
            formatter(params) {
                if (!params.length) {
                    return '';
                }
                const start = new Date(params[0].value[0] - offset).toISOString();
                let heading;
                if (resolution === 'day') {
                    heading = formatTime(start, 'date');
                } else if (resolution === 'hour') {
                    heading = `${formatTime(start, 'datetime')}–${formatTime(new Date(Date.parse(start) + HOUR).toISOString(), 'time')}`;
                } else {
                    heading = formatTime(start, 'datetime');
                }
                const lines = [`<strong>${heading}</strong>`];
                for (const p of params) {
                    const def = config.series[p.seriesIndex];
                    if (p.value[1] === null || p.value[1] === undefined) {
                        continue;
                    }
                    lines.push(`${p.marker} ${p.seriesName}: ${formatNumber(p.value[1], def.digits ?? 1)} ${def.unit}`);
                }
                return lines.join('<br>');
            },
        },
        xAxis: {
            type: 'time',
            min: Date.parse(range.from),
            max: Date.parse(range.to),
            axisLabel: {
                color: colors.muted,
                hideOverlap: true,
                formatter: (value) => formatTime(new Date(value).toISOString(), shortSpan ? 'time' : 'daymonth'),
            },
            axisLine: { lineStyle: { color: colors.grid } },
        },
        yAxis: config.axes.map((axis, index) => ({
            type: 'value',
            name: axis.name,
            min: axis.min,
            max: axis.max,
            scale: axis.min === undefined,
            position: index === 0 ? 'left' : 'right',
            nameTextStyle: { color: colors.muted },
            axisLabel: { color: colors.muted },
            splitLine: { show: index === 0, lineStyle: { color: colors.grid } },
        })),
        // Stejná id ve všech grafech – propojené přiblížení (echarts.connect).
        dataZoom: [
            { id: 'zoom-inside', type: 'inside', zoomOnMouseWheel: 'shift', moveOnMouseWheel: false },
            { id: 'zoom-slider', type: 'slider', show: id === 'temperature', height: 18, bottom: 8 },
        ],
        series: series.map(({ def, points }) => ({
            name: def.name,
            type: def.type || 'line',
            yAxisIndex: def.axis || 0,
            data: points,
            showSymbol: items.length <= 60,
            symbolSize: def.type === 'scatter' ? 5 : 4,
            connectNulls: false,
            barMaxWidth: 18,
            color: def.color,
            itemStyle: { color: def.color },
            lineStyle: { color: def.color, width: def.thin ? 1 : 2, type: def.dashed ? 'dashed' : 'solid' },
            areaStyle: def.area ? { opacity: 0.15 } : undefined,
        })),
    }, { notMerge: true });
}

// ---------------------------------------------------------------------------
// Tabulka
// ---------------------------------------------------------------------------

function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function renderTable() {
    const data = state.data;
    if (!data || !state.tableDirty) {
        return;
    }
    state.tableDirty = false;
    const resolution = data.resolution;
    const columns = TABLE_COLUMNS[resolution === 'raw' ? 'raw' : 'agg'];
    const items = (data.items || []).slice().reverse();
    const maxRows = limits.tableMaxRows || 2000;
    const shown = items.slice(0, maxRows);

    const head = `<thead><tr><th scope="col">${resolution === 'day' ? 'Datum' : 'Čas'}</th>${
        columns.map(([, label]) => `<th scope="col" class="text-end">${escapeHtml(label)}</th>`).join('')}</tr></thead>`;
    const rows = shown.map((item) => {
        const time = resolution === 'day' ? formatTime(item.date, 'date') : formatTime(item.time, 'datetime');
        const cells = columns.map(([key, , digits]) => `<td class="text-end">${escapeHtml(formatNumber(item[key], digits))}</td>`).join('');
        return `<tr><th scope="row" class="text-nowrap fw-normal">${escapeHtml(time)}</th>${cells}</tr>`;
    }).join('');
    ui.table.innerHTML = `${head}<tbody>${rows}</tbody>`;

    ui.tableNote.textContent = items.length === 0
        ? 'Žádná data.'
        : items.length > shown.length
            ? `Zobrazeno nejnovějších ${shown.length} z ${items.length} řádků. Celé období stáhnete jako CSV.`
            : `${plural(items.length, 'řádek', 'řádky', 'řádků')}, nejnovější nahoře.`;
}

// ---------------------------------------------------------------------------
// Události
// ---------------------------------------------------------------------------

form.addEventListener('submit', (event) => {
    event.preventDefault();
    const params = formParams();
    history.pushState(null, '', `${pageUrl}?${params}`);
    load();
});

for (const button of document.querySelectorAll('[data-preset]')) {
    button.addEventListener('click', () => {
        const range = presetRange(button.dataset.preset);
        if (!range) {
            return;
        }
        [inputs.from.value, inputs.to.value] = range;
        // Předvolba = automatická podrobnost (jinak např. „Dnes“ po dnech = jediný bod).
        inputs.resolution.value = 'auto';
        form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
    });
}

window.addEventListener('popstate', () => {
    applyParams(new URLSearchParams(window.location.search));
    load();
});

ui.tableDetails.addEventListener('toggle', () => {
    if (ui.tableDetails.open) {
        renderTable();
    }
});

document.addEventListener('weather:themechange', () => renderCharts(state.data));
window.addEventListener('resize', () => {
    for (const chart of charts.values()) {
        chart.resize();
    }
});

load();
