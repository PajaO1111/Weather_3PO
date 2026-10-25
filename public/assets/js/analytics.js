/**
 * Zobrazení srážkových úhrnů a jejich datového pokrytí.
 */
import { fetchJson, formatNumber, formatTime } from './api.js';
import { themeColors } from './charts.js';

const root = document.getElementById('analytics');
const ui = {
    error: document.getElementById('analytics-error'),
    loading: document.getElementById('analytics-loading'),
    station: document.getElementById('analytics-station'),
    range: document.getElementById('analytics-range'),
    lastObservation: document.getElementById('last-observation'),
    updated: document.getElementById('analytics-updated'),
    chart: document.getElementById('analytics-chart'),
};
let chart = null;
let data = null;

function setError(message) {
    ui.error.textContent = message || '';
    ui.error.classList.toggle('d-none', !message);
}

function render(payload) {
    data = payload;
    ui.station.textContent = payload.station || '–';
    ui.lastObservation.textContent = payload.meta.lastObservation
        ? `Poslední měření: ${formatTime(payload.meta.lastObservation, 'datetime')}`
        : 'Čas posledního měření není k dispozici.';
    ui.range.textContent = `Časové pásmo: ${payload.meta.timezone}`;
    ui.updated.textContent = `Výpočet vytvořen: ${formatTime(payload.meta.generatedAt, 'datetime')}.`;

    const windows = [30, 60, 90];
    const chartValues = [];
    for (const days of windows) {
        const period = payload.periods[String(days)];
        const card = root.querySelector(`[data-rainfall-period="${days}"]`);
        card.querySelector('[data-rainfall-value]').textContent = period.rainfallMm === null
            ? 'Bez dat'
            : formatNumber(period.rainfallMm, 1);
        card.querySelector('[data-rainfall-dates]').textContent =
            `${formatTime(period.fromDate, 'date')} – ${formatTime(period.toDate, 'date')}`;
        card.querySelector('[data-rainfall-coverage]').textContent = String(period.daysWithKnownPrecip);
        card.querySelector('[data-rainfall-percent]').textContent = formatNumber(period.coveragePercent, 1);
        card.querySelector('[data-rainfall-weather-days]').textContent = String(period.daysWithData);
        chartValues.push(period.rainfallMm);
    }
    renderChart(windows, chartValues);
}

function renderChart(windows, values) {
    if (!window.echarts) {
        setError('Grafická knihovna se nenačetla. Souhrnné hodnoty jsou uvedené výše.');
        return;
    }
    if (!chart) {
        chart = window.echarts.init(ui.chart, null, { renderer: 'canvas' });
    }
    const colors = themeColors();
    const hasData = values.some((value) => value !== null);
    chart.setOption({
        animation: false,
        textStyle: { color: colors.text },
        title: hasData
            ? { show: false }
            : { text: 'Žádné známé srážkové úhrny', left: 'center', top: 'middle', textStyle: { color: colors.muted, fontSize: 14, fontWeight: 'normal' } },
        grid: { left: 48, right: 24, top: 24, bottom: 32 },
        xAxis: {
            type: 'category',
            data: windows.map((days) => `${days} dní`),
            axisLabel: { color: colors.muted },
            axisLine: { lineStyle: { color: colors.grid } },
        },
        yAxis: {
            type: 'value',
            name: 'mm',
            min: 0,
            axisLabel: { color: colors.muted },
            nameTextStyle: { color: colors.muted },
            splitLine: { lineStyle: { color: colors.grid } },
        },
        tooltip: {
            trigger: 'axis',
            valueFormatter: (value) => value === null ? 'bez dat' : `${formatNumber(value, 1)} mm`,
        },
        series: [{
            type: 'bar',
            name: 'Známý úhrn',
            data: values,
            barMaxWidth: 44,
            itemStyle: { color: '#1971c2', opacity: 0.75 },
        }],
    }, { notMerge: true });
}

async function load() {
    root.setAttribute('aria-busy', 'true');
    ui.loading.classList.remove('d-none');
    setError('');
    try {
        render(await fetchJson(root.dataset.apiAnalytics));
    } catch (error) {
        setError(error.message || 'Analytická data se nepodařilo načíst.');
    } finally {
        root.setAttribute('aria-busy', 'false');
        ui.loading.classList.add('d-none');
    }
}

document.addEventListener('weather:themechange', () => {
    if (data) {
        renderChart([30, 60, 90], [30, 60, 90].map((days) => data.periods[String(days)].rainfallMm));
    }
});
window.addEventListener('resize', () => chart && chart.resize());
load();
