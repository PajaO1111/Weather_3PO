/**
 * Stránka „Statistiky a rekordy“: souhrny po měsících / letech a žebříčky z api/stats.php.
 *
 * - Výběr roku funguje i bez JS (GET na stats.php); zde načítáme bez obnovení
 *   stránky a aktualizujeme adresu (sdílitelný odkaz, Zpět/Vpřed).
 * - Seznam let v nabídce doplníme podle let, za která jsou v DB data.
 * - Období v tabulce i data rekordů odkazují na podrobnou historii.
 */
import { fetchJson, fillFields, formatNumber, formatTime } from './api.js';
import { themeColors } from './charts.js';
import { createWeekPanel } from './week.js';

const root = document.getElementById('stats');
const form = document.getElementById('stats-form');
const select = form.elements.year;
const ui = {
    error: document.getElementById('error'),
    loading: document.getElementById('loading'),
    rangeLabel: document.getElementById('range-label'),
    chartTitle: document.getElementById('chart-title'),
    tableTitle: document.getElementById('table-title'),
    table: document.getElementById('stats-table'),
    chart: document.getElementById('chart-stats'),
};
const apiUrl = root.dataset.apiStats;
const pageUrl = root.dataset.pageUrl;
const historyUrl = root.dataset.historyUrl;

const MONTHS = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
const MONTHS_SHORT = ['led', 'úno', 'bře', 'dub', 'kvě', 'čvn', 'čvc', 'srp', 'zář', 'říj', 'lis', 'pro'];

/** Sloupce tabulky: [klíč, nadpis, desetinná místa, titulek]. Objekt {value, date} → hodnota + datum. */
const COLUMNS = [
    ['days', 'Dnů', 0, 'Dnů s daty'],
    ['temp_avg', 'T prům.', 1, 'Průměrná teplota °C (úplné dny)'],
    ['temp_max_avg', 'Prům. max', 1, 'Průměr denních maxim °C'],
    ['temp_min_avg', 'Prům. min', 1, 'Průměr denních minim °C'],
    ['temp_max', 'Max', 1, 'Nejvyšší teplota °C'],
    ['temp_min', 'Min', 1, 'Nejnižší teplota °C'],
    ['precip', 'Srážky mm', 1, 'Úhrn srážek mm'],
    ['rain_days', 'Sráž. dny', 0, 'Dny s úhrnem alespoň 1 mm'],
    ['gust_max', 'Náraz km/h', 1, 'Nejsilnější náraz větru km/h'],
    ['frost_days', 'Mraz.', 0, 'Mrazové dny (Tmin < 0 °C)'],
    ['ice_days', 'Led.', 0, 'Ledové dny (Tmax < 0 °C)'],
    ['summer_days', 'Letní', 0, 'Letní dny (Tmax ≥ 25 °C)'],
    ['tropical_days', 'Trop.', 0, 'Tropické dny (Tmax ≥ 30 °C)'],
    ['tropical_nights', 'Trop. noci', 0, 'Tropické noci (Tmin ≥ 20 °C)'],
];

const state = { data: null, requestId: 0 };
let chart = null;

// ---------------------------------------------------------------------------
// Pomocné funkce
// ---------------------------------------------------------------------------

function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function historyLink(from, to, text, title = '') {
    const params = new URLSearchParams({ from, to });
    const titleAttr = title ? ` title="${escapeHtml(title)}"` : '';
    return `<a href="${escapeHtml(`${historyUrl}?${params}`)}"${titleAttr}>${escapeHtml(text)}</a>`;
}

/** Název řádku: „leden“ (rok po měsících) nebo „2026“ (celé období). */
function rowLabel(row, group, short = false) {
    if (group === 'month') {
        const month = Number(row.key.slice(5, 7)) - 1;
        return (short ? MONTHS_SHORT : MONTHS)[month];
    }
    return row.key;
}

function setError(message) {
    ui.error.textContent = message || '';
    ui.error.classList.toggle('d-none', !message);
}

function setLoading(on) {
    ui.loading.classList.toggle('d-none', !on);
    select.disabled = on;
    root.setAttribute('aria-busy', on ? 'true' : 'false');
}

// ---------------------------------------------------------------------------
// Načtení dat
// ---------------------------------------------------------------------------

async function load() {
    const requestId = ++state.requestId;
    setError('');
    setLoading(true);
    try {
        const data = await fetchJson(`${apiUrl}?${new URLSearchParams({ year: select.value })}`, 60000);
        if (requestId !== state.requestId) {
            return;
        }
        state.data = data;
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

/** Doplní do nabídky všechny roky, za které jsou data (nejnovější nahoře). */
function updateYears(years) {
    const current = select.value;
    const values = ['all', ...years.slice().sort((a, b) => b - a).map(String)];
    if (!values.includes(current)) {
        values.push(current);
    }
    select.innerHTML = values.map((value) => {
        const label = value === 'all' ? 'Celé období (po letech)' : `Rok ${value} (po měsících)`;
        return `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`;
    }).join('');
    select.value = current;
}

// ---------------------------------------------------------------------------
// Vykreslení
// ---------------------------------------------------------------------------

function render(data) {
    fillFields(root, data);
    updateYears(data.years || []);
    if (weekPanel) {
        weekPanel.setYears(data.years || []);
    }
    const period = data.period || {};
    const group = period.group;
    ui.rangeLabel.textContent = period.fromDate
        ? `${formatTime(period.fromDate, 'date')} – ${formatTime(period.toDate, 'date')}`
        : '';
    ui.tableTitle.textContent = group === 'month' ? `Rok ${period.year} po měsících` : 'Celé období po letech';
    ui.chartTitle.textContent = group === 'month' ? 'Teplota a srážky po měsících' : 'Teplota a srážky po letech';
    if (!data.total || data.total.days === 0) {
        setError('Za zvolené období nejsou v databázi žádná měření.');
    }
    renderTable(data);
    renderChart(data);
    renderRecords(data);
}

function cell(value, digits) {
    if (value !== null && typeof value === 'object') {
        return `<td class="text-end" title="${escapeHtml(formatTime(value.date, 'date'))}">${escapeHtml(formatNumber(value.value, digits))}</td>`;
    }
    return `<td class="text-end">${escapeHtml(formatNumber(value, digits))}</td>`;
}

function renderTable(data) {
    const group = data.period.group;
    const head = `<thead><tr><th scope="col">${group === 'month' ? 'Měsíc' : 'Rok'}</th>${
        COLUMNS.map(([, label, , title]) => `<th scope="col" class="text-end" title="${escapeHtml(title)}">${escapeHtml(label)}</th>`).join('')}</tr></thead>`;
    const rows = (data.rows || []).map((row) => {
        const label = rowLabel(row, group);
        const name = row.days > 0
            ? historyLink(row.from, row.to, label, `Historie ${formatTime(row.from, 'date')} – ${formatTime(row.to, 'date')}`)
            : escapeHtml(label);
        const cells = COLUMNS.map(([key, , digits]) => (row.days > 0 ? cell(row[key], digits) : '<td class="text-end text-body-secondary">–</td>')).join('');
        return `<tr><th scope="row" class="fw-normal text-nowrap">${name}</th>${cells}</tr>`;
    }).join('');
    const total = data.total || {};
    const foot = `<tfoot><tr class="fw-semibold"><th scope="row">Celkem</th>${COLUMNS.map(([key, , digits]) => cell(total[key], digits)).join('')}</tr></tfoot>`;
    ui.table.innerHTML = `${head}<tbody>${rows}</tbody>${foot}`;
}

function renderChart(data) {
    if (!data) {
        return;
    }
    if (!window.echarts) {
        setError('Knihovna grafů se nenačetla.');
        return;
    }
    if (!chart) {
        chart = window.echarts.init(ui.chart, null, { renderer: 'canvas' });
    }
    const colors = themeColors();
    const group = data.period.group;
    const rows = data.rows || [];
    const value = (row, key) => {
        if (row.days === 0) return null;
        const v = row[key];
        return v !== null && typeof v === 'object' ? v.value : (v ?? null);
    };
    const series = [
        { key: 'precip', name: 'Srážky', unit: 'mm', color: '#1971c2', type: 'bar', axis: 1 },
        { key: 'temp_max', name: 'Absolutní max', unit: '°C', color: '#e03131', type: 'scatter' },
        { key: 'temp_max_avg', name: 'Prům. maximum', unit: '°C', color: '#f76707' },
        { key: 'temp_avg', name: 'Průměr', unit: '°C', color: '#f59f00', width: 3 },
        { key: 'temp_min_avg', name: 'Prům. minimum', unit: '°C', color: '#1c7ed6' },
        { key: 'temp_min', name: 'Absolutní min', unit: '°C', color: '#15aabf', type: 'scatter' },
    ];
    const empty = rows.every((row) => row.days === 0);

    chart.setOption({
        animation: false,
        textStyle: { color: colors.text },
        title: empty
            ? { text: 'Žádná data', left: 'center', top: 'middle', textStyle: { color: colors.muted, fontSize: 14, fontWeight: 'normal' } }
            : { show: false },
        grid: { left: 48, right: 48, top: 52, bottom: 28 },
        legend: { type: 'scroll', top: 0, textStyle: { color: colors.text }, pageTextStyle: { color: colors.muted } },
        tooltip: {
            trigger: 'axis',
            formatter(params) {
                if (!params.length) return '';
                const row = rows[params[0].dataIndex];
                const lines = [`<strong>${escapeHtml(rowLabel(row, group))}${group === 'month' ? ` ${row.key.slice(0, 4)}` : ''}</strong>`];
                for (const p of params) {
                    if (p.value === null || p.value === undefined) continue;
                    const def = series[p.seriesIndex];
                    lines.push(`${p.marker} ${p.seriesName}: ${formatNumber(p.value, 1)} ${def.unit}`);
                }
                return lines.join('<br>');
            },
        },
        xAxis: {
            type: 'category',
            data: rows.map((row) => rowLabel(row, group, true)),
            axisLabel: { color: colors.muted },
            axisLine: { lineStyle: { color: colors.grid } },
        },
        yAxis: [
            { type: 'value', name: '°C', scale: true, nameTextStyle: { color: colors.muted }, axisLabel: { color: colors.muted }, splitLine: { lineStyle: { color: colors.grid } } },
            { type: 'value', name: 'mm', min: 0, position: 'right', nameTextStyle: { color: colors.muted }, axisLabel: { color: colors.muted }, splitLine: { show: false } },
        ],
        series: series.map((def) => ({
            name: def.name,
            type: def.type || 'line',
            yAxisIndex: def.axis || 0,
            data: rows.map((row) => value(row, def.key)),
            connectNulls: false,
            symbolSize: def.type === 'scatter' ? 7 : 5,
            barMaxWidth: 28,
            color: def.color,
            itemStyle: { color: def.color, opacity: def.type === 'bar' ? 0.55 : 1 },
            lineStyle: { color: def.color, width: def.width || 2 },
            z: def.type === 'bar' ? 1 : 2,
        })),
    }, { notMerge: true });
}

function renderRecords(data) {
    const records = data.records || {};
    for (const list of root.querySelectorAll('[data-record]')) {
        const items = records[list.dataset.record] || [];
        const unit = list.dataset.unit;
        const digits = Number(list.dataset.digits);
        list.innerHTML = items.length === 0
            ? '<li class="text-body-secondary">žádná data</li>'
            : items.map((item) => `<li><span class="records-value">${escapeHtml(formatNumber(item.value, digits))} ${escapeHtml(unit)}</span> ${
                historyLink(item.date, item.date, formatTime(item.date, 'date'), 'Zobrazit historii dne')}</li>`).join('');
    }
}

// ---------------------------------------------------------------------------
// Události
// ---------------------------------------------------------------------------

/** Adresa stránky se zvoleným rokem; ostatní parametry (např. week) zůstanou. */
function pageUrlFor(year) {
    const params = new URLSearchParams(window.location.search);
    params.set('year', year);
    return `${pageUrl}?${params}`;
}

select.addEventListener('change', () => {
    history.pushState(null, '', pageUrlFor(select.value));
    load();
});

form.addEventListener('submit', (event) => {
    event.preventDefault();
    select.dispatchEvent(new Event('change'));
});

window.addEventListener('popstate', () => {
    const year = new URLSearchParams(window.location.search).get('year');
    const fallback = String(new Date().getFullYear());
    const wanted = year || fallback;
    if (wanted === select.value && state.data) {
        return; // změnil se jen týden paprskového grafu
    }
    if (![...select.options].some((o) => o.value === wanted)) {
        select.add(new Option(wanted === 'all' ? 'Celé období (po letech)' : `Rok ${wanted} (po měsících)`, wanted));
    }
    select.value = wanted;
    load();
});

document.addEventListener('weather:themechange', () => renderChart(state.data));
window.addEventListener('resize', () => chart && chart.resize());

load();

// Paprskový graf 7 dní nezávisí na zvoleném roce (týden má vlastní parametr ?week=).
const weekSection = document.getElementById('week');
const weekPanel = weekSection ? createWeekPanel(weekSection) : null;
if (weekPanel) {
    weekPanel.load();
}
