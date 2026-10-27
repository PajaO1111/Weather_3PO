/**
 * Společná časová osa s mezerami a samostatně dostupnými řadami.
 */
import { fetchJson, formatNumber } from './api.js';
import { themeColors } from './charts.js';

const root = document.getElementById('comparison');
const error = document.getElementById('comparison-error');
let chart = null;
let payload = null;
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
    chart.setOption({
        animation: false,
        textStyle: { color: colors.text },
        legend: { textStyle: { color: colors.text } },
        grid: { left: 55, right: 20, bottom: 70 },
        tooltip: { trigger: 'axis', renderMode: 'richText', valueFormatter: value => value === null ? 'bez dat' : `${formatNumber(value)} °C` },
        xAxis: { type: 'category', data: payload.items.map(row => label(row.time)), axisLabel: { color: colors.muted, rotate: 30 } },
        yAxis: { type: 'value', name: '°C', scale: true, splitLine: { lineStyle: { color: colors.grid } } },
        series: [
            { name: 'OuBrix', type: 'line', connectNulls: false, data: payload.items.map(row => row.local) },
            { name: 'ČHMÚ Kopisty (kvalita Good)', type: 'line', connectNulls: false, data: payload.items.map(row => row.kopisty) },
        ],
    }, { notMerge: true });
}

async function load() {
    try {
        payload = await fetchJson(root.dataset.api, 60000);
        const warning = document.getElementById('comparison-warning');
        const warnings = [...payload.meta.warnings];
        if (!payload.items.some(row => row.kopisty !== null)) warnings.push('Kopisty nemají v období žádnou teplotu s přijatou kvalitou.');
        if (!payload.items.some(row => row.local !== null)) warnings.push('OuBrix nemá v období dostupnou teplotu.');
        warning.textContent = warnings.join(' ');
        warning.classList.toggle('d-none', warnings.length === 0);
        const station = payload.kopistyStation;
        document.getElementById('comparison-station').textContent = station
            ? `${station.name} (${station.id}), ${station.latitude} N, ${station.longitude} E, ${station.elevation} m n. m. Metadata: ${payload.meta.kopisty.sourceCreatedAt}.`
            : 'Metadata stanice Kopisty nejsou dostupná.';
        document.getElementById('comparison-summary').textContent =
            `Společné hodiny: ${payload.summary.pairedHours}/24. Průměrný rozdíl OuBrix − Kopisty: ${formatNumber(payload.summary.meanDifference, 1)} °C.`;
        const quality = Object.entries(payload.meta.kopistyQualityCounts)
            .map(([code, item]) => `${code} (${item.label}): ${item.count}`);
        const flags = Object.entries(payload.meta.kopistyFlagCounts).map(([code, count]) => `${code}: ${count}`);
        document.getElementById('comparison-quality').textContent =
            `Kvalita vzorků Kopist v období: ${quality.join('; ') || 'bez záznamů'}. Příznaky: ${flags.join('; ') || 'žádné'}.`;
        const body = document.getElementById('comparison-rows');
        body.replaceChildren();
        for (const row of payload.items) {
            const tr = document.createElement('tr');
            for (const text of [label(row.time), formatNumber(row.local, 1), row.localSamples,
                formatNumber(row.kopisty, 1), row.kopistySamples, row.kopistyExcluded, formatNumber(row.difference, 1)]) {
                const td = document.createElement('td');
                td.textContent = String(text);
                tr.append(td);
            }
            body.append(tr);
        }
        showChart();
    } catch (exception) {
        error.textContent = exception.message || 'Porovnání se nepodařilo načíst.';
        error.classList.remove('d-none');
    } finally {
        root.setAttribute('aria-busy', 'false');
        document.getElementById('comparison-loading').classList.add('d-none');
    }
}
document.addEventListener('weather:themechange', () => payload && showChart());
window.addEventListener('resize', () => chart && chart.resize());
load();
