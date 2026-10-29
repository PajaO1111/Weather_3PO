import { themeColors } from './charts.js';

const root = document.getElementById('phenology');
const form = document.getElementById('phenology-form');
const stationSelect = document.getElementById('phenology-station');
const speciesSelect = document.getElementById('phenology-species');
const phaseSelect = document.getElementById('phenology-phase');
const yearSelect = document.getElementById('phenology-year');
const compareOptions = document.getElementById('phenology-compare-options');
const error = document.getElementById('phenology-error');
const warning = document.getElementById('phenology-warning');
const loading = document.getElementById('phenology-loading');
const results = document.getElementById('phenology-results');
const chartIds = ['phenology-calendar-chart', 'phenology-series-chart', 'phenology-comparison-chart'];
const charts = new Map();
let payload = null;
let busy = false;

function option(value, label, selected) {
    const element = document.createElement('option');
    element.value = value;
    element.textContent = label;
    element.selected = value === String(selected);
    return element;
}

function stationLabel(station) {
    const elevation = station.elevationM === null ? 'výška neuvedena' : `${station.elevationM} m n. m.`;
    const distance = station.distanceKm === null ? '' : ` · ${station.distanceKm} km od Mostu`;
    return `${station.name} (${elevation}${distance})`;
}

function fillSelectors(data) {
    stationSelect.replaceChildren(...data.filters.stations.map(station =>
        option(station.code, stationLabel(station), data.selection.station.code)));
    speciesSelect.replaceChildren(...data.filters.species.map(species =>
        option(species.code, `${species.name} · ${species.category === 'herbs' ? 'bylina' : 'dřevina'}`, data.selection.species.code)));
    phaseSelect.replaceChildren(...data.filters.phases.map(phase =>
        option(phase.code, `${phase.name} (${phase.code})`, data.selection.phase.code)));
    yearSelect.replaceChildren(...data.filters.years.map(year =>
        option(String(year), String(year), data.selection.year)));
    for (const select of [stationSelect, speciesSelect, phaseSelect, yearSelect]) {
        select.disabled = false;
    }

    const selectedCodes = new Set(data.comparison.stationCodes.filter(code => code !== data.selection.station.code));
    compareOptions.replaceChildren();
    const alternatives = data.filters.compareStations.filter(station => station.code !== data.selection.station.code);
    if (alternatives.length === 0) {
        const note = document.createElement('span');
        note.className = 'small text-body-secondary';
        note.textContent = 'Pro tuto fenofázi nejsou se základní stanicí dostupné společné pozorované roky.';
        compareOptions.append(note);
        return;
    }
    for (const station of alternatives) {
        const wrapper = document.createElement('div');
        wrapper.className = 'form-check';
        const checkbox = document.createElement('input');
        checkbox.className = 'form-check-input';
        checkbox.type = 'checkbox';
        checkbox.id = `phenology-compare-${station.code}`;
        checkbox.value = station.code;
        checkbox.checked = selectedCodes.has(station.code);
        const label = document.createElement('label');
        label.className = 'form-check-label small';
        label.htmlFor = checkbox.id;
        label.textContent = stationLabel(station);
        wrapper.append(checkbox, label);
        compareOptions.append(wrapper);
    }
    updateCompareLimit();
}

function updateCompareLimit() {
    const checked = Array.from(compareOptions.querySelectorAll('input[type="checkbox"]:checked'));
    for (const checkbox of compareOptions.querySelectorAll('input[type="checkbox"]')) {
        checkbox.disabled = !checkbox.checked && checked.length >= 3;
    }
}

function currentQuery(includeComparison = true) {
    const query = {
        station: stationSelect.value,
        species: speciesSelect.value,
        phase: phaseSelect.value,
        year: yearSelect.value,
    };
    if (includeComparison && payload) {
        const codes = [payload.selection.station.code, ...Array.from(
            compareOptions.querySelectorAll('input[type="checkbox"]:checked'),
            checkbox => checkbox.value,
        )];
        if (codes.length > 1) query.compare = codes.join(',');
    }
    return query;
}

function statusLabel(status, date, sourceValues = null) {
    if (status === 'observed') return date;
    if (status === 'not_occurred') return 'Fenofáze nenastala';
    if (status === 'conflict') return `Rozporné duplicitní hodnoty: ${sourceValues || 'bez detailu'}`;
    return 'Bez záznamu';
}

function appendRow(body, values) {
    const row = document.createElement('tr');
    for (const value of values) {
        const cell = document.createElement('td');
        cell.textContent = value === null || value === undefined ? '—' : String(value);
        row.append(cell);
    }
    body.append(row);
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[character]);
}

function renderCalendar(data) {
    const body = document.getElementById('phenology-calendar-rows');
    body.replaceChildren();
    for (const item of data.calendar) {
        appendRow(body, [`${item.phaseName} (${item.phaseCode})`, statusLabel(item.status, item.date, item.sourceValues)]);
    }
}

function renderSeries(data) {
    const body = document.getElementById('phenology-series-rows');
    body.replaceChildren();
    for (const item of data.series.items) {
        appendRow(body, [item.year, statusLabel(item.status, item.date, item.sourceValues)]);
    }
    const medianText = data.series.medianLabel === null
        ? 'Pro tuto fázi nejsou k dispozici žádná pozorovaná data pro výpočet mediánu.'
        : `Medián stanice: ${data.series.medianLabel} z ${data.series.observedYears} pozorovaných nástupů. Nenastálé ani chybějící roky se nezapočítávají.`;
    document.getElementById('phenology-median').textContent = medianText;
}

function renderComparison(data) {
    const body = document.getElementById('phenology-comparison-rows');
    body.replaceChildren();
    for (const item of data.comparison.items) {
        appendRow(body, [
            item.stationName,
            item.elevationM === null ? 'neuvedena' : `${item.elevationM} m`,
            item.medianLabel,
            item.observedYears,
        ]);
    }
    document.getElementById('phenology-comparison-summary').textContent = data.comparison.warning
        || `Společné pozorované roky: ${data.comparison.commonYears.join(', ')}. Body jsou mediány z těchto stejných let; graf nevyjadřuje obecný vliv nadmořské výšky.`;
}

function dayLabel(day) {
    if (Math.abs(day - 59.5) < 0.001) return '28. 2. / 1. 3.';
    const date = new Date(Date.UTC(2021, 0, Math.round(day) - 1));
    return new Intl.DateTimeFormat('cs-CZ', { day: 'numeric', month: 'numeric', timeZone: 'UTC' }).format(date);
}

function showCharts(data) {
    if (!window.echarts) {
        error.textContent = 'Knihovna grafů se nenačetla. Stejná data jsou dostupná v tabulkách.';
        error.classList.remove('d-none');
        return;
    }
    const colors = themeColors();
    const instances = chartIds.map(id => {
        let chart = charts.get(id);
        if (!chart) {
            chart = window.echarts.init(document.getElementById(id));
            charts.set(id, chart);
        }
        return chart;
    });

    const calendarPoints = data.calendar
        .map((item, index) => item.status === 'observed' ? {
            value: [index, item.calendarDay],
            phaseName: item.phaseName,
            phaseCode: item.phaseCode,
            date: item.date,
        } : null)
        .filter(Boolean);
    instances[0].setOption({
        animation: false,
        textStyle: { color: colors.text },
        grid: { left: 60, right: 24, top: 20, bottom: 105 },
        tooltip: {
            trigger: 'item',
            formatter: item => `${escapeHtml(item.data.phaseName)} (${escapeHtml(item.data.phaseCode)})<br>${escapeHtml(item.data.date)}`,
        },
        xAxis: {
            type: 'category',
            data: data.calendar.map(item => item.phaseCode),
            axisLabel: { color: colors.muted, interval: 0, rotate: 35 },
            axisLine: { lineStyle: { color: colors.grid } },
        },
        yAxis: {
            type: 'value', min: 1, max: 365, inverse: true, name: 'Datum',
            axisLabel: { color: colors.muted, formatter: dayLabel },
            splitLine: { lineStyle: { color: colors.grid } },
        },
        series: [{
            type: 'scatter',
            data: calendarPoints,
            symbolSize: 13,
            itemStyle: { color: '#0b7285' },
        }],
    }, { notMerge: true });

    const medianLine = data.series.medianCalendarDay === null ? [] : [{
        yAxis: data.series.medianCalendarDay,
        lineStyle: { type: 'dashed', color: '#e8590c' },
        label: { formatter: `Medián ${data.series.medianLabel}` },
    }];
    instances[1].setOption({
        animation: false,
        textStyle: { color: colors.text },
        grid: { left: 64, right: 24, top: 28, bottom: 55 },
        tooltip: {
            trigger: 'axis',
            formatter: items => {
                const item = data.series.items[items[0].dataIndex];
                return `${item.year}: ${statusLabel(item.status, item.date)}`;
            },
        },
        xAxis: {
            type: 'category', data: data.series.items.map(item => String(item.year)),
            axisLabel: { color: colors.muted }, axisLine: { lineStyle: { color: colors.grid } },
        },
        yAxis: {
            type: 'value', min: 1, max: 365, inverse: true, name: 'Datum',
            axisLabel: { color: colors.muted, formatter: dayLabel },
            splitLine: { lineStyle: { color: colors.grid } },
        },
        series: [{
            type: 'line',
            data: data.series.items.map(item => item.calendarDay),
            connectNulls: false,
            showSymbol: true,
            symbolSize: 9,
            itemStyle: { color: '#1971c2' },
            lineStyle: { color: '#1971c2', width: 2 },
            markLine: { silent: true, symbol: 'none', data: medianLine },
        }],
    }, { notMerge: true });

    const comparisonPoints = data.comparison.items
        .filter(item => item.elevationM !== null && item.medianCalendarDay !== null)
        .map(item => ({
            value: [item.elevationM, item.medianCalendarDay],
            stationName: item.stationName,
            elevationM: item.elevationM,
            medianLabel: item.medianLabel,
            observedYears: item.observedYears,
        }));
    instances[2].setOption({
        animation: false,
        textStyle: { color: colors.text },
        grid: { left: 68, right: 35, top: 28, bottom: 55 },
        tooltip: {
            trigger: 'item',
            formatter: item => `${escapeHtml(item.data.stationName)}<br>${item.data.elevationM} m n. m.<br>Medián: ${escapeHtml(item.data.medianLabel)}<br>${item.data.observedYears} společných let`,
        },
        xAxis: {
            type: 'value', name: 'Nadmořská výška (m)',
            axisLabel: { color: colors.muted }, axisLine: { lineStyle: { color: colors.grid } },
            splitLine: { lineStyle: { color: colors.grid } },
        },
        yAxis: {
            type: 'value', min: 1, max: 365, inverse: true, name: 'Medián nástupu',
            axisLabel: { color: colors.muted, formatter: dayLabel },
            splitLine: { lineStyle: { color: colors.grid } },
        },
        series: [{
            type: 'scatter',
            data: comparisonPoints,
            symbolSize: 16,
            itemStyle: { color: '#6741d9' },
            label: { show: true, position: 'top', formatter: item => item.data.stationName },
        }],
    }, { notMerge: true });
}

function render(data) {
    fillSelectors(data);
    renderCalendar(data);
    renderSeries(data);
    renderComparison(data);
    const station = data.selection.station;
    const elevation = station.elevationM === null ? 'nadmořská výška neuvedena' : `${station.elevationM} m n. m.`;
    const coordinates = station.latitude === null || station.longitude === null
        ? 'souřadnice neuvedeny'
        : `${station.latitude}, ${station.longitude}`;
    document.getElementById('phenology-station-summary').textContent =
        `${station.name} (${station.code}), ${elevation}, ${coordinates}, ${station.distanceKm === null ? 'vzdálenost od Mostu neznámá' : `${station.distanceKm} km od Mostu`}. `
        + `${data.selection.species.name} · ${data.selection.phase.name} · ${data.selection.year}. `
        + `Import ČHMÚ: ${data.source.importedAt ? `${data.source.importedAt} UTC` : 'čas importu neuveden'}.`;
    const notices = [];
    if (data.comparison.warning) notices.push(data.comparison.warning);
    const conflicts = data.series.items.filter(item => item.status === 'conflict').length;
    if (conflicts > 0) {
        notices.push(`${conflicts} rok(y) obsahují rozporné duplicitní zdrojové hodnoty; jsou uvedeny v tabulce, ale nezapočítávají se.`);
    }
    notices.push(data.method.trend);
    warning.textContent = notices.join(' ');
    warning.classList.remove('d-none');
    results.classList.remove('d-none');
    showCharts(data);
}

async function load(query) {
    if (busy) return;
    busy = true;
    root.setAttribute('aria-busy', 'true');
    for (const select of [stationSelect, speciesSelect, phaseSelect, yearSelect]) {
        select.disabled = true;
    }
    for (const checkbox of compareOptions.querySelectorAll('input[type="checkbox"]')) {
        checkbox.disabled = true;
    }
    document.getElementById('phenology-submit').disabled = true;
    results.classList.add('d-none');
    loading.classList.remove('d-none');
    error.classList.add('d-none');
    warning.classList.add('d-none');
    try {
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(query)) {
            if (value !== null && value !== undefined && value !== '') params.set(key, String(value));
        }
        const response = await fetch(`${root.dataset.api}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        const body = await response.json();
        if (!response.ok) {
            throw new Error(body.message || 'Fenologická data nelze načíst.');
        }
        payload = body;
        render(payload);
    } catch (exception) {
        error.textContent = exception.message || 'Fenologická data nelze načíst.';
        error.classList.remove('d-none');
    } finally {
        busy = false;
        root.setAttribute('aria-busy', 'false');
        for (const select of [stationSelect, speciesSelect, phaseSelect, yearSelect]) {
            select.disabled = payload === null;
        }
        if (payload) updateCompareLimit();
        document.getElementById('phenology-submit').disabled = false;
        loading.classList.add('d-none');
    }
}

stationSelect.addEventListener('change', () => load({ station: stationSelect.value }));
speciesSelect.addEventListener('change', () => load({
    station: stationSelect.value,
    species: speciesSelect.value,
}));
phaseSelect.addEventListener('change', () => load({
    station: stationSelect.value,
    species: speciesSelect.value,
    phase: phaseSelect.value,
}));
yearSelect.addEventListener('change', () => load(currentQuery()));
compareOptions.addEventListener('change', () => {
    updateCompareLimit();
    load(currentQuery());
});
form.addEventListener('submit', event => {
    event.preventDefault();
    load(currentQuery());
});
document.addEventListener('weather:themechange', () => payload && !busy && showCharts(payload));
window.addEventListener('resize', () => charts.forEach(chart => chart.resize()));

load({});
