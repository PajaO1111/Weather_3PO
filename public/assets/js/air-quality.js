/**
 * Bezpečné zobrazení aktuální kvality ovzduší ČHMÚ pro Most.
 */
import { fetchJson, formatNumber, formatTime } from './api.js';

const root = document.getElementById('air-quality');
const ui = {
    error: document.getElementById('air-quality-error'),
    warning: document.getElementById('air-quality-warning'),
    loading: document.getElementById('air-quality-loading'),
    station: document.getElementById('air-quality-station'),
    location: document.getElementById('air-quality-location'),
    index: document.getElementById('air-quality-index'),
    indexTime: document.getElementById('air-quality-index-time'),
    updated: document.getElementById('air-quality-updated'),
};
let state = null;
const forecastRoot = document.getElementById('air-quality-forecast');
const forecastUi = forecastRoot ? {
    pollutant: document.getElementById('air-quality-forecast-pollutant'),
    horizon: document.getElementById('air-quality-forecast-horizon'),
    range: document.getElementById('air-quality-forecast-range'),
    chart: document.getElementById('air-quality-forecast-chart'),
    error: document.getElementById('air-quality-forecast-error'),
    warning: document.getElementById('air-quality-forecast-warning'),
    loading: document.getElementById('air-quality-forecast-loading'),
    meta: document.getElementById('air-quality-forecast-meta'),
    map: document.getElementById('air-quality-forecast-map'),
} : null;
let forecastChart = null;
let forecastRequest = 0;
let forecastPayload = null;

function setMessage(element, message) {
    element.textContent = message || '';
    element.classList.toggle('d-none', !message);
}

function renderMeasurement(component, measurement) {
    const card = root.querySelector(`[data-measurement="${component}"]`);
    const value = card.querySelector('[data-value]');
    const unit = card.querySelector('[data-unit]');
    const status = card.querySelector('[data-status]');
    const observed = card.querySelector('[data-observed]');
    value.textContent = measurement.value === null ? '–' : formatNumber(measurement.value, 1);
    unit.textContent = measurement.unit ? ` ${measurement.unit}` : '';
    status.textContent = measurement.statusText || 'Stav hodnoty není znám.';
    observed.textContent = measurement.observedAt
        ? `Měření: ${formatTime(measurement.observedAt, 'datetime')}${measurement.stale ? ' · zastaralé' : ''}`
        : '';
    if (measurement.stale && measurement.ageSeconds !== null) {
        const minutes = Math.floor(measurement.ageSeconds / 60);
        observed.textContent += ` · Údaj není aktuální, stáří ${Math.floor(minutes / 60)} h ${minutes % 60} min.`;
    }
    card.classList.toggle('border-warning', measurement.status === 'substituted' || measurement.stale);
    card.classList.toggle('border-danger', measurement.status === 'invalid' || measurement.status === 'unavailable');
}

function render(payload) {
    state = payload;
    const station = payload.station || {};
    ui.station.textContent = `${station.name || 'Most'} · ${station.code || 'UMOM'}`;
    const location = [];
    if (station.region) location.push(station.region);
    if (station.elevationMeters !== null && station.elevationMeters !== undefined) {
        location.push(`${formatNumber(station.elevationMeters, 0)} m n. m.`);
    }
    ui.location.textContent = location.join(' · ');

    for (const component of ['PM10', 'PM2_5', 'NO2', 'SO2', 'O3']) {
        renderMeasurement(component, payload.measurements[component]);
    }

    const index = payload.index || {};
    ui.index.textContent = index.available
        ? `${index.level}: ${index.label}`
        : (index.label || 'Index není dostupný.');
    ui.indexTime.textContent = index.observedAt
        ? `Měření: ${formatTime(index.observedAt, 'datetime')}`
        : '';

    const meta = payload.meta || {};
    const warnings = [...(meta.warnings || [])];
    if (meta.observationStale) {
        warnings.push('Poslední pozorování je starší než dvě hodiny.');
    }
    setMessage(ui.warning, [...new Set(warnings)].join(' '));
    ui.updated.textContent = `Metadata ČHMÚ vytvořena: ${
        meta.sourceCreatedAt ? formatTime(meta.sourceCreatedAt, 'datetime') : 'čas není uveden'
    }. Zdroj načten: ${formatTime(meta.sources.values.fetchedAt, 'datetime')}.`;
}

async function load() {
    root.setAttribute('aria-busy', 'true');
    ui.loading.classList.remove('d-none');
    setMessage(ui.error, '');
    try {
        render(await fetchJson(root.dataset.apiAirQuality, 15000));
    } catch (error) {
        setMessage(ui.error, error.message || 'Data kvality ovzduší se nepodařilo načíst.');
    } finally {
        root.setAttribute('aria-busy', 'false');
        ui.loading.classList.add('d-none');
    }
}

function renderForecast(payload) {
    forecastPayload = payload;
    const items = (payload.items || []).filter(item => item.type === 'predpoved');
    const horizon = Number(forecastUi.horizon.value);
    const visible = items.slice(0, horizon);
    if (visible.length === 0) {
        throw new Error('ČHMÚ neposkytlo hodinové předpovědi pro vybranou látku.');
    }

    forecastUi.chart.setAttribute('aria-label',
        `Hodinová modelová předpověď ${payload.pollutant.label} pro ORP Most, ${visible.length} hodin.`);
    const createdAt = payload.meta && payload.meta.createdAt;
    forecastUi.meta.textContent = `Vydáno: ${createdAt ? formatTime(createdAt, 'datetime') : 'čas není uveden'} · `
        + `${visible.length} z ${items.length} dostupných hodin${payload.meta && payload.meta.stale ? ' · data mohou být zastaralá' : ''}.`;
    const map = payload.currentMap;
    forecastUi.map.textContent = map
        ? `Samostatný plošný odhad typu „mapa“ (${formatTime(map.start, 'datetime')}–${formatTime(map.end, 'datetime')}): medián ${formatNumber(map.median, 1)}. Nejde o měření stanice.`
        : 'Zdroj neobsahuje samostatný plošný odhad typu „mapa“.';
    setForecastMessage(forecastUi.warning, ((payload.meta && payload.meta.warnings) || []).join(' '));

    if (!forecastChart) {
        if (typeof echarts === 'undefined') {
            throw new Error('Grafickou knihovnu se nepodařilo načíst.');
        }
        forecastChart = echarts.init(forecastUi.chart);
        if (typeof window !== 'undefined') {
            window.addEventListener('resize', () => forecastChart && forecastChart.resize());
        }
    }

    const labels = visible.map(item => item.start);
    const low = visible.map(item => item.p25);
    const band = visible.map(item => item.p75 - item.p25);
    const showRange = forecastUi.range.checked;
    const numberFormat = new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 1 });
    const timeFormat = new Intl.DateTimeFormat('cs-CZ', {
        timeZone: 'Europe/Prague', day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit',
    });
    const bodyColor = typeof getComputedStyle === 'function'
        ? getComputedStyle(document.documentElement).getPropertyValue('--bs-body-color').trim()
        : '#495057';
    const borderColor = typeof getComputedStyle === 'function'
        ? getComputedStyle(document.documentElement).getPropertyValue('--bs-border-color').trim()
        : '#dee2e6';
    forecastChart.setOption({
        aria: {
            enabled: true,
            description: `Hodinová modelová předpověď koncentrace ${payload.pollutant.label} pro ORP Most. Medián a percentilové hodnoty.`,
        },
        animation: false,
        textStyle: { color: bodyColor },
        tooltip: {
            trigger: 'axis',
            textStyle: { color: '#212529' },
            formatter: params => {
                const index = params[0] ? params[0].dataIndex : -1;
                const item = visible[index];
                if (!item) return '';
                const lines = [
                    `${timeFormat.format(new Date(item.start))}–${timeFormat.format(new Date(item.end))}`,
                    `Medián (P50): ${numberFormat.format(item.median)}`,
                    `P25–P75: ${numberFormat.format(item.p25)}–${numberFormat.format(item.p75)}`,
                    `Minimum–maximum: ${numberFormat.format(item.min)}–${numberFormat.format(item.max)}`,
                ];
                return lines.join('<br>');
            },
        },
        grid: { left: 58, right: 20, top: 24, bottom: 56 },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: labels,
            axisLine: { lineStyle: { color: borderColor } },
            axisLabel: {
                hideOverlap: true,
                color: bodyColor,
                formatter: value => timeFormat.format(new Date(value)),
            },
        },
        yAxis: {
            type: 'value',
            name: payload.pollutant.unit || 'jednotka neuvedena',
            nameLocation: 'middle',
            nameGap: 44,
            axisLine: { lineStyle: { color: borderColor } },
            axisLabel: { color: bodyColor, formatter: value => numberFormat.format(value) },
            splitLine: { lineStyle: { color: borderColor } },
            scale: true,
        },
        series: [
            {
                name: 'P25',
                type: 'line',
                stack: 'percentile-range',
                data: low,
                symbol: 'none',
                lineStyle: { opacity: 0 },
                areaStyle: { opacity: 0 },
                emphasis: { disabled: true },
                tooltip: { show: false },
                silent: !showRange,
            },
            {
                name: 'P25–P75',
                type: 'line',
                stack: 'percentile-range',
                data: band,
                symbol: 'none',
                lineStyle: { opacity: 0 },
                itemStyle: { color: '#89a9d8' },
                areaStyle: { color: '#89a9d8', opacity: showRange ? 0.28 : 0 },
                emphasis: { disabled: true },
                tooltip: { show: false },
                silent: !showRange,
            },
            {
                name: 'Minimum',
                type: 'line',
                data: visible.map(item => item.min),
                symbol: 'none',
                lineStyle: { color: '#8994a3', type: 'dotted', width: 1, opacity: 0.55 },
                tooltip: { show: false },
            },
            {
                name: 'Maximum',
                type: 'line',
                data: visible.map(item => item.max),
                symbol: 'none',
                lineStyle: { color: '#8994a3', type: 'dotted', width: 1, opacity: 0.55 },
                tooltip: { show: false },
            },
            {
                name: 'Medián (P50)',
                type: 'line',
                data: visible.map(item => item.median),
                symbol: 'circle',
                symbolSize: 4,
                lineStyle: { color: '#0b7285', width: 2 },
                itemStyle: { color: '#0b7285' },
            },
        ],
    }, true);
}

function setForecastMessage(element, message) {
    element.textContent = message || '';
    element.classList.toggle('d-none', !message);
}

async function loadForecast() {
    if (!forecastRoot || !forecastUi) return;
    const requestId = ++forecastRequest;
    forecastRoot.setAttribute('aria-busy', 'true');
    forecastUi.loading.classList.remove('d-none');
    setForecastMessage(forecastUi.error, '');
    setForecastMessage(forecastUi.warning, '');
    forecastUi.meta.textContent = '';
    forecastUi.map.textContent = '';
    forecastPayload = null;
    if (forecastChart) forecastChart.clear();
    try {
        const url = `${forecastRoot.dataset.api}?pollutant=${encodeURIComponent(forecastUi.pollutant.value)}`;
        const payload = await fetchJson(url, 20000);
        if (requestId === forecastRequest) renderForecast(payload);
    } catch (error) {
        if (requestId === forecastRequest) {
            setForecastMessage(forecastUi.error, error.message || 'Předpověď kvality ovzduší se nepodařilo načíst.');
        }
    } finally {
        if (requestId === forecastRequest) {
            forecastRoot.setAttribute('aria-busy', 'false');
            forecastUi.loading.classList.add('d-none');
        }
    }
}

if (forecastUi) {
    forecastUi.pollutant.addEventListener('change', loadForecast);
    forecastUi.horizon.addEventListener('change', () => {
        if (forecastPayload) renderForecast(forecastPayload);
    });
    forecastUi.range.addEventListener('change', () => {
        if (forecastPayload) renderForecast(forecastPayload);
    });
}

load();
loadForecast();
