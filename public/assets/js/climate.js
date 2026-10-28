import { fetchJson, formatNumber, formatTime } from './api.js';
import { themeColors } from './charts.js';

const root = document.getElementById('climate');
const select = document.getElementById('climate-year');
const error = document.getElementById('climate-error');
const loading = document.getElementById('climate-loading');
const results = document.getElementById('climate-results');
const charts = new Map();
const monthNames = Array.from({ length: 12 }, (_, index) =>
    new Intl.DateTimeFormat('cs-CZ', { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2020, index, 1))));
let payload = null;
let busy = false;

function showCharts() {
    if (!window.echarts) {
        error.textContent = 'Grafická knihovna se nenačetla; hodnoty jsou dostupné v tabulce.';
        error.classList.remove('d-none');
        return;
    }
    const colors = themeColors();
    for (const [key, unit] of [['temperature', '°C'], ['precipitation', 'mm']]) {
        if (!charts.has(key)) charts.set(key, window.echarts.init(document.getElementById(`climate-${key}-chart`)));
        charts.get(key).setOption({
            animation: false,
            textStyle: { color: colors.text },
            legend: { textStyle: { color: colors.text } },
            grid: { left: 55, right: 15, bottom: 65 },
            tooltip: { trigger: 'axis', renderMode: 'richText',
                valueFormatter: value => value === null ? 'bez dat' : `${formatNumber(value, 1)} ${unit}` },
            xAxis: { type: 'category', data: monthNames, axisLabel: { color: colors.muted, rotate: 35 } },
            yAxis: { type: 'value', name: unit, scale: key === 'temperature',
                splitLine: { lineStyle: { color: colors.grid } } },
            series: [
                { name: String(payload.year), type: key === 'temperature' ? 'line' : 'bar',
                    connectNulls: false, data: payload.items.map(row => row[key].value) },
                { name: 'Oficiální normál ČHMÚ 1991–2020', type: 'line', connectNulls: false,
                    lineStyle: { width: 3 }, data: payload.officialNormal.months.map(row => row[key]) },
                { name: 'Vlastní ref. průměr z archivu', type: 'line', connectNulls: false,
                    data: payload.reference.months.map(row => row[key].value) },
            ],
        }, { notMerge: true });
    }
}

function statusText(item, label) {
    if (item.status === 'available') return `${label}: bez příznaku`;
    if (item.status === 'missing') return `${label}: chybí`;
    return `${label}: vyloučeno, původní údaj ${formatNumber(item.rawValue, 1)}, FLAG_REPEAT=${item.flagRepeat || 'prázdný'}, FLAG_INTERRUPTED=${item.flagInterrupted || 'prázdný'}`;
}

function render() {
    select.replaceChildren();
    for (const year of payload.years) {
        const option = document.createElement('option');
        option.value = String(year);
        option.textContent = String(year);
        option.selected = year === payload.year;
        select.append(option);
    }
    const warnings = [...payload.meta.warnings];
    if (payload.items.some(row => row.temperature.status !== 'available' || row.precipitation.status !== 'available')) {
        warnings.push('Zvolený rok obsahuje chybějící nebo vyloučené měsíce. Podrobnosti jsou v tabulce.');
    }
    const warning = document.getElementById('climate-warning');
    warning.textContent = warnings.join(' ');
    warning.classList.toggle('d-none', warnings.length === 0);
    const sourceDetails = Object.entries(payload.meta.sources).map(([key, source]) =>
        `${key}: vytvořeno ${formatTime(source.createdAt)}, verze ${source.version}, načteno ${formatTime(source.fetchedAt)}${source.cacheStatus === 'stale' ? ' (stará cache)' : ''}`);
    document.getElementById('climate-source').textContent =
        `${payload.station.name} (${payload.station.id}). ${sourceDetails.join('. ')}.`;
    document.getElementById('climate-summary').textContent =
        `Rok ${payload.year}: roční srážky ${formatNumber(payload.rainfallAnnual, 1)} mm; `
        + `oficiální roční normál ČHMÚ ${formatNumber(payload.officialNormal.annual.precipitation, 1)} mm; `
        + `roční rozdíl ${formatNumber(payload.officialComparison.annualRainfallDifference, 1)} mm `
        + `(${formatNumber(payload.officialComparison.annualRainfallPercent, 1)} % normálu). `
        + `Vlastní referenční průměr archivu: ${formatNumber(payload.reference.rainfallAnnual, 1)} mm `
        + `(${payload.reference.completeYears}/30 úplných let).`;
    const body = document.getElementById('climate-rows');
    body.replaceChildren();
    for (const row of payload.items) {
        const reference = payload.reference.months[row.month - 1];
        const tr = document.createElement('tr');
        for (const text of [
            monthNames[row.month - 1],
            formatNumber(row.temperature.value, 1),
            formatNumber(row.officialNormal.temperature, 1),
            formatNumber(row.officialNormal.temperatureDifference, 1),
            formatNumber(reference.temperature.value, 1),
            `${reference.temperature.count}/30`,
            formatNumber(row.precipitation.value, 1),
            formatNumber(row.officialNormal.precipitation, 1),
            formatNumber(row.officialNormal.precipitationDifference, 1),
            formatNumber(row.officialNormal.precipitationPercent, 1),
            formatNumber(reference.precipitation.value, 1),
            `${reference.precipitation.count}/30`,
            `${statusText(row.temperature, 'T')}; ${statusText(row.precipitation, 'SRA')}`,
        ]) {
            const td = document.createElement('td');
            td.textContent = text;
            tr.append(td);
        }
        body.append(tr);
    }
    results.classList.remove('d-none');
    showCharts();
}

async function load(year = null) {
    if (busy) return;
    busy = true;
    root.setAttribute('aria-busy', 'true');
    select.disabled = true;
    document.getElementById('climate-submit').disabled = true;
    results.classList.add('d-none');
    loading.classList.remove('d-none');
    error.classList.add('d-none');
    document.getElementById('climate-warning').classList.add('d-none');
    try {
        payload = await fetchJson(root.dataset.api + (year === null ? '' : `?year=${encodeURIComponent(year)}`), 60000);
        render();
    } catch (exception) {
        error.textContent = exception.message || 'Historické klima nelze načíst.';
        error.classList.remove('d-none');
    } finally {
        busy = false;
        root.setAttribute('aria-busy', 'false');
        select.disabled = payload === null;
        document.getElementById('climate-submit').disabled = false;
        loading.classList.add('d-none');
    }
}

document.getElementById('climate-form').addEventListener('submit', event => {
    event.preventDefault();
    load(select.value || null);
});
document.addEventListener('weather:themechange', () => payload && !busy && showCharts());
window.addEventListener('resize', () => charts.forEach(chart => chart.resize()));
load();
