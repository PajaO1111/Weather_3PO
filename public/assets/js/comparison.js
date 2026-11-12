/**
 * Společná časová osa s mezerami a samostatně dostupnými řadami.
 */
import { fetchJson, formatNumber } from './api.js';
import { themeColors } from './charts.js';

const root = document.getElementById('comparison');
const error = document.getElementById('comparison-error');
let chart = null;
let payload = null;
const selector = document.getElementById('comparison-metric');
const definitions = {
    temperature: { title: 'Hodinová průměrná teplota', unit: '°C', digits: 1 },
    precipitation: { title: 'Hodinový úhrn srážek', unit: 'mm', digits: 2 },
    wind: { title: 'Hodinová průměrná rychlost větru', unit: 'km/h', digits: 1 },
};
const metricName = () => selector.value || 'temperature';
const metric = () => payload.metrics[metricName()];
const definition = () => definitions[metricName()];
const dates = new Intl.DateTimeFormat('cs-CZ', {
    timeZone: 'Europe/Prague', day: 'numeric', month: 'numeric',
    hour: '2-digit', minute: '2-digit', timeZoneName: 'shortOffset',
});
const label = (time) => dates.format(new Date(time));

function showChart() {
    if (!window.echarts) {
        error.textContent = 'Grafická knihovna se nenačetla; hodnoty jsou v tabulce.';
        error.classList.remove('d-none');
        return;
    }
    chart = chart || window.echarts.init(document.getElementById('comparison-chart'));
    const colors = themeColors();
    const data = metric();
    const config = definition();
    chart.setOption({
        animation: false,
        textStyle: { color: colors.text },
        legend: { textStyle: { color: colors.text } },
        grid: { left: 55, right: 20, bottom: 70 },
        tooltip: { trigger: 'axis', renderMode: 'richText', valueFormatter: value => value === null ? 'bez dat' : `${formatNumber(value, config.digits)} ${config.unit}` },
        xAxis: { type: 'category', data: data.items.map(row => label(row.time)), axisLabel: { color: colors.muted, rotate: 30 } },
        yAxis: { type: 'value', name: config.unit, scale: metricName() !== 'precipitation', splitLine: { lineStyle: { color: colors.grid } } },
        series: [
            { name: 'OuBrix', type: 'line', connectNulls: false, data: data.items.map(row => row.local) },
            { name: 'ČHMÚ Kopisty (kvalita Good)', type: 'line', connectNulls: false,
                data: data.items.map(row => row.kopistyUnverified !== null ? null : row.kopisty) },
            { name: 'ČHMÚ Kopisty (neověřené, Unknown)', type: 'line', connectNulls: false,
                lineStyle: { type: 'dashed' }, data: data.items.map(row => row.kopistyUnverified) },
        ],
    }, { notMerge: true });
}

function render() {
    const data = metric();
    const config = definition();
    const warning = document.getElementById('comparison-warning');
    const warnings = [...payload.meta.warnings];
    if (!data.items.some(row => row.kopisty !== null || row.kopistyUnverified !== null)) {
        warnings.push('Kopisty nemají pro tuto veličinu hodnoty s použitelnou kvalitou a pokrytím.');
    }
    if (data.items.some(row => row.kopistyUnverified !== null)) {
        warnings.push('Část měření ČHMÚ má neznámou kvalitu (Unknown). Přerušovaná řada a její rozdíly nejsou ověřené.');
    }
    if (!data.items.some(row => row.local !== null)) warnings.push('OuBrix nemá pro tuto veličinu dostatečné pokrytí.');
    warning.textContent = warnings.join(' ');
    warning.classList.toggle('d-none', warnings.length === 0);
    document.getElementById('comparison-chart-title').textContent = config.title;
    document.getElementById('comparison-chart').setAttribute('aria-label', `${config.title}: OuBrix a Kopisty`);
    document.getElementById('comparison-local-heading').textContent = `OuBrix ${config.unit}`;
    document.getElementById('comparison-kopisty-heading').textContent = `Kopisty ${config.unit}`;
    document.getElementById('comparison-difference-heading').textContent = `Rozdíl ${config.unit}`;
    document.getElementById('comparison-summary').textContent =
        `Společné hodiny s kvalitou Good: ${data.summary.pairedHours}/24. Průměrný rozdíl OuBrix − Kopisty (Good): ${formatNumber(data.summary.meanDifference, config.digits)} ${config.unit}. ` +
        `Společné hodiny s neověřenou řadou: ${data.summary.unverifiedPairedHours}/24.`;
    const unknownSamples = data.items.reduce((sum, row) => sum + row.kopistyUnknownSamples, 0);
    const excluded = data.items.reduce((sum, row) => sum + row.kopistyUnverifiedExcluded, 0);
    document.getElementById('comparison-quality').textContent =
        `Zvolená veličina ČHMÚ: použitelných vzorků Unknown: ${unknownSamples}; vyloučených hodnot: ${excluded}. Počty a kvalita jsou uvedeny po hodinách.`;
    const body = document.getElementById('comparison-rows');
    body.replaceChildren();
    for (const row of data.items) {
        const unverified = row.kopistyUnverified !== null;
        const value = unverified ? row.kopistyUnverified : row.kopisty;
        const difference = unverified ? row.unverifiedDifference : row.difference;
        const tr = document.createElement('tr');
        for (const text of [label(row.time), formatNumber(row.local, config.digits), row.localSamples,
            formatNumber(value, config.digits), unverified ? row.kopistyUnverifiedSamples : row.kopistySamples,
            row.kopistyUnverifiedExcluded, formatNumber(difference, config.digits),
            unverified ? `Neověřené (Unknown: ${row.kopistyUnknownSamples})` : value !== null ? 'Good' : 'Bez dat / neúplné']) {
            const td = document.createElement('td');
            td.textContent = String(text);
            tr.append(td);
        }
        body.append(tr);
    }
    showChart();
}

async function load() {
    try {
        payload = await fetchJson(root.dataset.api, 60000);
        const station = payload.kopistyStation;
        document.getElementById('comparison-station').textContent = station
            ? `${station.name} (${station.id}), ${station.latitude} N, ${station.longitude} E, ${station.elevation} m n. m. Metadata: ${payload.meta.kopisty.sourceCreatedAt}.`
            : 'Metadata stanice Kopisty nejsou dostupná.';
        render();
    } catch (exception) {
        error.textContent = exception.message || 'Porovnání se nepodařilo načíst.';
        error.classList.remove('d-none');
    } finally {
        root.setAttribute('aria-busy', 'false');
        document.getElementById('comparison-loading').classList.add('d-none');
    }
}
document.addEventListener('weather:themechange', () => payload && showChart());
selector.addEventListener('change', () => payload && render());
window.addEventListener('resize', () => chart && chart.resize());
load();
