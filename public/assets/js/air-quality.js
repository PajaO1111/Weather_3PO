/**
 * Bezpečné zobrazení aktuální kvality ovzduší ČHMÚ pro Most.
 */
import { fetchJson, formatNumber, formatTime } from './api.js';
import { themeColors } from './charts.js';

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
    comparisonStation: document.getElementById('air-quality-comparison-station'),
    comparisonStatus: document.getElementById('air-quality-comparison-status'),
    comparisonRows: document.getElementById('air-quality-comparison-rows'),
};
let state = null;
let currentRequest = 0;
const historyPanel = document.getElementById('air-quality-history-panel');
const mapPanel = document.getElementById('air-quality-map-panel');
const historyUi = historyPanel ? {
    error: document.getElementById('air-quality-history-error'),
    warning: document.getElementById('air-quality-history-warning'),
    source: document.getElementById('air-quality-history-source'),
    count: document.getElementById('air-quality-history-count'),
    events: document.getElementById('air-quality-history-events'),
    chart: document.getElementById('air-quality-history-chart'),
    year: document.getElementById('air-quality-history-year'),
    pollutant: document.getElementById('air-quality-history-pollutant'),
    type: document.getElementById('air-quality-history-type'),
} : null;
let historyPayload = null;
let historyRequest = 0;
let historyChart = null;
const mapUi = mapPanel ? {
    component: document.getElementById('air-quality-map-component'),
    type: document.getElementById('air-quality-map-type'),
    opacity: document.getElementById('air-quality-map-opacity'),
    opacityValue: document.getElementById('air-quality-map-opacity-value'),
    map: document.getElementById('air-quality-map'),
    status: document.getElementById('air-quality-map-status'),
    source: document.getElementById('air-quality-map-source'),
    error: document.getElementById('air-quality-map-error'),
    warning: document.getElementById('air-quality-map-warning'),
    legendDescription: document.getElementById('air-quality-map-legend-description'),
    legend: document.getElementById('air-quality-map-legend'),
} : null;
const mapTypes = {
    PM10: [['hours', 'Hodinový průměr'], ['days', 'Denní průměr']],
    PM2_5: [['hours', 'Hodinový průměr'], ['days', 'Denní průměr']],
    NO2: [['hours', 'Hodinový průměr'], ['max1h', 'Maximální hodinový průměr za den']],
    O3: [['hours', 'Hodinový průměr'], ['max1h', 'Maximální hodinový průměr za den'],
        ['max8h', 'Maximální 8hodinový průměr za den']],
    SO2: [['hours', 'Hodinový průměr'], ['days', 'Denní průměr']],
};
let mapInstance = null;
let mapOverlay = null;
let mapPayload = null;
let mapRequest = 0;
const pollutantLabels = { PM10: 'PM10', O3: 'O₃', SO2: 'SO₂', NO2: 'NO₂' };
const eventLabels = { 'smogová situace': 'Smogová situace', regulace: 'Regulace', varování: 'Varování' };
const historyDateFormat = new Intl.DateTimeFormat('cs-CZ', {
    timeZone: 'Europe/Prague', day: 'numeric', month: 'numeric', year: 'numeric',
});
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

function renderComparison(payload) {
    const stations = payload.nearbyStations || [];
    const comparison = payload.comparison;
    ui.comparisonStation.replaceChildren();
    for (const station of stations) {
        const option = document.createElement('option');
        option.value = station.code;
        option.textContent = `${station.name} (${formatNumber(station.distanceKm, 1)} km)`;
        option.selected = Boolean(comparison && comparison.station.code === station.code);
        ui.comparisonStation.append(option);
    }
    ui.comparisonStation.disabled = stations.length === 0;
    if (stations.length === 0 || !comparison) {
        ui.comparisonStatus.textContent = 'V okolí nebyla nalezena aktivní stanice se srovnatelnou látkou, jednotkou a intervalem.';
        ui.comparisonRows.replaceChildren();
        return;
    }
    ui.comparisonStatus.textContent = `${comparison.station.name} · ${comparison.station.region || 'region neuveden'} · `
        + `${formatNumber(comparison.distanceKm, 1)} km od UMOM. Rozdíl jen při shodném čase měření.`;
    ui.comparisonRows.replaceChildren();
    for (const component of ['PM10', 'PM2_5', 'NO2', 'SO2', 'O3']) {
        const left = payload.measurements[component];
        const right = comparison.measurements[component];
        const relation = comparison.pollutants[component];
        const row = document.createElement('tr');
        const values = [
            left.label || pollutantLabels[component] || component,
            measurementText(left),
            measurementText(right),
            relation.status === 'comparable'
                ? formatNumber(relation.difference, 1)
                : comparisonText(relation.status),
        ];
        for (const text of values) {
            const cell = document.createElement('td');
            cell.textContent = text;
            row.append(cell);
        }
        ui.comparisonRows.append(row);
    }
}

function measurementText(measurement) {
    const value = measurement.value === null ? '–' : `${formatNumber(measurement.value, 1)} ${measurement.unit || ''}`;
    const time = measurement.observedAt ? ` · ${formatTime(measurement.observedAt, 'datetime')}` : '';
    const stale = measurement.stale ? ' · zastaralé' : '';
    return `${value}${time}${stale}`;
}

function comparisonText(status) {
    return ({
        unavailable: 'Nedostupné',
        unit_mismatch: 'Jiná jednotka',
        interval_mismatch: 'Jiný interval',
        time_mismatch: 'Jiný čas měření',
    })[status] || 'Nesrovnatelné';
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
    renderComparison(payload);

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

async function load(comparisonCode = null) {
    const requestId = ++currentRequest;
    root.setAttribute('aria-busy', 'true');
    ui.loading.classList.remove('d-none');
    setMessage(ui.error, '');
    try {
        const url = comparisonCode
            ? `${root.dataset.apiAirQuality}?compare=${encodeURIComponent(comparisonCode)}`
            : root.dataset.apiAirQuality;
        const payload = await fetchJson(url, 15000);
        if (requestId === currentRequest) render(payload);
    } catch (error) {
        if (requestId === currentRequest) setMessage(ui.error, error.message || 'Data kvality ovzduší se nepodařilo načíst.');
    } finally {
        if (requestId === currentRequest) {
            root.setAttribute('aria-busy', 'false');
            ui.loading.classList.add('d-none');
        }
    }
}

function renderHistory() {
    if (!historyUi || !historyPayload) return;
    const years = [...new Set(historyPayload.events.map(event => event.issuedAt.slice(0, 4)))].sort().reverse();
    const selectedYear = historyUi.year.value;
    historyUi.year.replaceChildren();
    const allYears = document.createElement('option');
    allYears.value = '';
    allYears.textContent = 'Všechny roky';
    historyUi.year.append(allYears);
    for (const year of years) {
        const option = document.createElement('option');
        option.value = year;
        option.textContent = year;
        historyUi.year.append(option);
    }
    historyUi.year.value = years.includes(selectedYear) ? selectedYear : '';
    const events = historyPayload.events.filter(event =>
        (!historyUi.year.value || event.issuedAt.slice(0, 4) === historyUi.year.value)
        && (!historyUi.pollutant.value || event.pollutant === historyUi.pollutant.value)
        && (!historyUi.type.value || event.type === historyUi.type.value));
    const chartEvents = events.filter(event => Number.isFinite(event.durationHours) && event.durationHours >= 0);
    renderHistoryChart(chartEvents);
    historyUi.events.replaceChildren();
    historyUi.count.textContent = `${events.length} ${events.length === 1 ? 'událost' : 'událostí'} pro ${historyPayload.area}; `
        + `${chartEvents.length} s uvedenou délkou trvání.`;
    for (const event of events) {
        const item = document.createElement('li');
        const article = document.createElement('article');
        article.classList.add('svrs-timeline-event');
        const heading = document.createElement('h3');
        heading.className = 'h6 mb-1';
        heading.textContent = `${eventLabels[event.type] || event.type} · ${pollutantLabels[event.pollutant] || event.pollutant}`;
        article.append(heading);
        const period = document.createElement('p');
        period.className = 'mb-1';
        period.textContent = `Vyhlášení: ${formatTime(event.issuedAt, 'datetime')} · `
            + `odvolání: ${event.endedAt ? formatTime(event.endedAt, 'datetime') : 'dosud neodvoláno'}`;
        article.append(period);
        const details = [];
        if (event.durationHours !== null) details.push(`délka ${formatNumber(event.durationHours, 1)} h`);
        if (event.note) details.push(event.note);
        if (details.length) {
            const note = document.createElement('p');
            note.className = 'small text-body-secondary mb-0';
            note.textContent = details.join(' · ');
            article.append(note);
        }
        item.append(article);
        historyUi.events.append(item);
    }
    if (events.length === 0) {
        const empty = document.createElement('li');
        empty.textContent = 'Pro zvolené filtry nebyla nalezena žádná událost.';
        historyUi.events.append(empty);
    }
    const source = historyPayload.meta.source;
    historyUi.source.textContent = `Údaje z ČHMÚ načteny ${formatTime(source.fetchedAt, 'datetime')}; `
        + `licencováno ${source.license}.`
        + (source.cacheStatus === 'stale' ? ' Zobrazuje se zastaralá cache.' : '');
    setMessage(historyUi.warning, (historyPayload.meta.warnings || []).join(' '));
}

function renderHistoryChart(events) {
    if (typeof echarts === 'undefined') {
        historyUi.chart.textContent = 'Graf se nepodařilo načíst. Délky a podrobnosti událostí zůstávají dostupné níže.';
        return;
    }
    if (!historyChart) {
        historyChart = echarts.init(historyUi.chart, null, { renderer: 'canvas' });
        window.addEventListener('resize', () => historyChart && historyChart.resize());
    }
    const colors = themeColors();
    const categories = events.map(event => {
        const date = historyDateFormat.format(new Date(event.issuedAt));
        return `${date} · ${eventLabels[event.type] || event.type} · ${pollutantLabels[event.pollutant] || event.pollutant}`;
    });
    const maxHeight = 1100;
    historyUi.chart.style.height = `${Math.max(320, Math.min(events.length * 34 + 80, maxHeight))}px`;
    historyChart.resize();
    historyUi.chart.setAttribute('aria-label',
        `Délka ${events.length} historických vyhlášení SVRS v hodinách pro ${historyPayload.area}`);
    historyChart.setOption({
        aria: {
            enabled: true,
            description: `Délka jednotlivých historických vyhlášení SVRS v hodinách pro ${historyPayload.area}.`,
        },
        animation: false,
        textStyle: { color: colors.text },
        grid: { left: 190, right: 45, top: 12, bottom: 38 },
        xAxis: {
            type: 'value',
            name: 'Hodiny',
            min: 0,
            axisLabel: { color: colors.muted },
            splitLine: { lineStyle: { color: colors.grid } },
        },
        yAxis: {
            type: 'category',
            inverse: true,
            data: categories,
            axisLabel: { color: colors.muted, width: 175, overflow: 'truncate' },
        },
        tooltip: {
            trigger: 'item',
            renderMode: 'richText',
            formatter: parameter => {
                const event = events[parameter.dataIndex];
                return `${eventLabels[event.type] || event.type} · ${pollutantLabels[event.pollutant] || event.pollutant}\n`
                    + `Vyhlášení: ${formatTime(event.issuedAt, 'datetime')}\n`
                    + `Odvolání: ${event.endedAt ? formatTime(event.endedAt, 'datetime') : 'dosud neodvoláno'}\n`
                    + `Délka: ${formatNumber(event.durationHours, 1)} h`;
            },
        },
        series: [{
            name: 'Délka vyhlášení',
            type: 'bar',
            data: events.map(event => event.durationHours),
            barMaxWidth: 22,
            label: {
                show: true,
                position: 'right',
                color: colors.text,
                formatter: parameter => `${formatNumber(parameter.value, 1)} h`,
            },
            itemStyle: { color: '#0b7285', borderRadius: [0, 3, 3, 0] },
        }],
    }, { notMerge: true });
}

function updateMapTypes() {
    if (!mapUi) return;
    const options = mapTypes[mapUi.component.value] || [];
    const previous = mapUi.type.value;
    mapUi.type.replaceChildren();
    for (const [value, label] of options) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        mapUi.type.append(option);
    }
    mapUi.type.value = options.some(([value]) => value === previous) ? previous : options[0][0];
}

function setupAirQualityMap() {
    if (mapInstance) return;
    if (!globalThis.L) throw new Error('Mapovou knihovnu nelze načíst. Zkontrolujte připojení nebo blokování CDN.');
    mapInstance = globalThis.L.map(mapUi.map, { scrollWheelZoom: false }).setView([50.51039, 13.64525], 8);
    globalThis.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).on('tileerror', () => {
        mapUi.status.textContent = 'Mapový podklad se nepodařilo načíst; ověřte polohu opatrně.';
    }).addTo(mapInstance);
    globalThis.L.circleMarker([50.51039, 13.64525], {
        radius: 5, color: '#202040', fillColor: '#ffffff', fillOpacity: 1, weight: 2,
    }).bindTooltip('Most · orientační poloha').addTo(mapInstance);
    mapInstance.attributionControl.addAttribution('Data mapy: ČHMÚ');
}

function renderAirQualityMap(payload) {
    mapPayload = payload;
    mapUi.status.textContent = `${payload.component.label} · ${payload.type.label} · `
        + `${formatTime(payload.period.start, 'datetime')}–${formatTime(payload.period.end, 'datetime')} · `
        + `stáří ${Math.floor(payload.period.ageSeconds / 3600)} h `
        + `${Math.floor((payload.period.ageSeconds % 3600) / 60)} min${payload.meta.stale ? ' · ZASTARALÁ MAPA' : ''}.`;
    mapUi.legendDescription.textContent = `${payload.legend.description}${payload.legend.units ? ` · ${payload.legend.units}` : ''}`;
    mapUi.legend.replaceChildren();
    for (const item of payload.legend.values) {
        const entry = document.createElement('li');
        const swatch = document.createElement('span');
        swatch.className = 'air-quality-map-legend-swatch';
        swatch.style.backgroundColor = item.color;
        swatch.setAttribute('aria-hidden', 'true');
        const label = document.createElement('span');
        label.textContent = item.label;
        entry.append(swatch, label);
        mapUi.legend.append(entry);
    }
    const source = payload.meta.sources.map;
    mapUi.source.textContent = `Zdroj vytvořen ${formatTime(payload.period.createdAt, 'datetime')}; `
        + `načten ${formatTime(source.fetchedAt, 'datetime')}. PNG ${payload.raster.width} × ${payload.raster.height} px; `
        + `krok vstupního gridu ${formatNumber(payload.raster.gridStepMeters, 0)} m; `
        + `zobrazený pixel přibližně ${formatNumber(payload.raster.pixelWidthMeters, 0)} × `
        + `${formatNumber(payload.raster.pixelHeightMeters, 0)} m (${payload.raster.crs}).`;
    setMessage(mapUi.warning, (payload.meta.warnings || []).join(' '));
}

async function loadAirQualityMap() {
    if (!mapUi) return;
    const requestId = ++mapRequest;
    mapPanel.setAttribute('aria-busy', 'true');
    mapUi.status.textContent = 'Načítám operativní mapu ČHMÚ…';
    setMessage(mapUi.error, '');
    setMessage(mapUi.warning, '');
    try {
        const query = new URLSearchParams({
            component: mapUi.component.value,
            type: mapUi.type.value,
        });
        const payload = await fetchJson(`${mapPanel.dataset.api}?${query.toString()}`, 30000);
        if (requestId !== mapRequest) return;
        setupAirQualityMap();
        const candidate = globalThis.L.imageOverlay(payload.map, payload.bounds, {
            opacity: Number(mapUi.opacity.value) / 100,
            alt: `Operativní mapa ČHMÚ ${payload.component.label}, ${payload.type.label}`,
        });
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                candidate.off('load');
                candidate.off('error');
                candidate.remove();
                reject(new Error('Mapový rastr ČHMÚ se nepodařilo zobrazit včas.'));
            }, 10000);
            candidate.once('load', () => { clearTimeout(timer); resolve(); });
            candidate.once('error', () => {
                clearTimeout(timer);
                candidate.remove();
                reject(new Error('Mapový rastr ČHMÚ se nepodařilo zobrazit.'));
            });
            candidate.addTo(mapInstance);
        });
        if (requestId !== mapRequest) {
            candidate.remove();
            return;
        }
        if (mapOverlay) mapOverlay.remove();
        mapOverlay = candidate;
        renderAirQualityMap(payload);
        mapInstance.invalidateSize();
    } catch (exception) {
        if (requestId === mapRequest) {
            setMessage(mapUi.error, exception.message || 'Operativní mapu ČHMÚ se nepodařilo načíst.');
            mapUi.status.textContent = 'Mapa není dostupná.';
        }
    } finally {
        if (requestId === mapRequest) mapPanel.setAttribute('aria-busy', 'false');
    }
}

async function loadHistory() {
    if (!historyUi) return;
    const requestId = ++historyRequest;
    setMessage(historyUi.error, '');
    try {
        const payload = await fetchJson(historyPanel.dataset.api, 15000);
        if (requestId !== historyRequest) return;
        historyPayload = payload;
        renderHistory();
    } catch (error) {
        if (requestId === historyRequest) {
            setMessage(historyUi.error, error.message || 'Historii SVRS se nepodařilo načíst.');
        }
    }
}

const tabs = [
    { button: document.getElementById('air-quality-live-tab'), panel: document.getElementById('air-quality-live-panel') },
    { button: document.getElementById('air-quality-history-tab'), panel: historyPanel },
    { button: document.getElementById('air-quality-map-tab'), panel: mapPanel },
].filter(tab => tab.button && tab.panel);

function activateTab(index, focus = false) {
    tabs.forEach((tab, tabIndex) => {
        const selected = index === tabIndex;
        tab.button.setAttribute('aria-selected', selected ? 'true' : 'false');
        tab.button.setAttribute('tabindex', selected ? '0' : '-1');
        tab.button.classList.toggle('active', selected);
        tab.panel.hidden = !selected;
    });
    if (focus) tabs[index].button.focus();
    if (tabs[index].panel === historyPanel && !historyPayload) loadHistory();
    if (tabs[index].panel === mapPanel && !mapPayload) loadAirQualityMap();
}

tabs.forEach((tab, index) => {
    tab.button.addEventListener('click', () => activateTab(index));
    tab.button.addEventListener('keydown', event => {
        let next = index;
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        else if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = tabs.length - 1;
        else return;
        event.preventDefault();
        activateTab(next, true);
    });
});
if (ui.comparisonStation) {
    ui.comparisonStation.addEventListener('change', () => load(ui.comparisonStation.value || null));
}
if (historyUi) {
    [historyUi.year, historyUi.pollutant, historyUi.type].forEach(select =>
        select.addEventListener('change', renderHistory));
    document.addEventListener('weather:themechange', () => historyPayload && renderHistory());
}
if (mapUi) {
    mapUi.component.addEventListener('change', () => {
        updateMapTypes();
        loadAirQualityMap();
    });
    mapUi.type.addEventListener('change', loadAirQualityMap);
    mapUi.opacity.addEventListener('input', () => {
        mapUi.opacityValue.textContent = `${mapUi.opacity.value} %`;
        if (mapOverlay) mapOverlay.setOpacity(Number(mapUi.opacity.value) / 100);
    });
    updateMapTypes();
    window.addEventListener('resize', () => mapInstance && mapInstance.invalidateSize());
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
