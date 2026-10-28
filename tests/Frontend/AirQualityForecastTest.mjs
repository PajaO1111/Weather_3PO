import assert from 'node:assert/strict';

const elements = new Map();
const measurements = new Map();

function element(value = '') {
    const classes = new Set(['d-none']);
    return {
        textContent: '',
        value,
        checked: true,
        dataset: {},
        style: {},
        children: [],
        attributes: new Map(),
        events: new Map(),
        classList: {
            add: key => classes.add(key),
            remove: key => classes.delete(key),
            toggle: (key, force) => force ? classes.add(key) : classes.delete(key),
            contains: key => classes.has(key),
        },
        setAttribute(key, text) { this.attributes.set(key, text); },
        addEventListener(key, callback) { this.events.set(key, callback); },
        append(...children) {
            for (const child of children) {
                this.children.push(child);
                if (child.selected) this.value = child.value;
            }
        },
        replaceChildren() { this.children = []; this.value = ''; },
        focus() { this.focused = true; },
        querySelector(selector) {
            if (!this.queryChildren) this.queryChildren = new Map();
            if (!this.queryChildren.has(selector)) this.queryChildren.set(selector, element());
            return this.queryChildren.get(selector);
        },
    };
}

for (const [id, value] of [
    ['air-quality', ''],
    ['air-quality-forecast-pollutant', 'PM10'],
    ['air-quality-forecast-horizon', '72'],
]) {
    elements.set(id, element(value));
}
for (const id of [
    'air-quality-error', 'air-quality-warning', 'air-quality-loading', 'air-quality-station',
    'air-quality-location', 'air-quality-index', 'air-quality-index-time', 'air-quality-updated',
    'air-quality-forecast',
    'air-quality-forecast-error', 'air-quality-forecast-warning', 'air-quality-forecast-loading',
    'air-quality-forecast-meta', 'air-quality-forecast-map', 'air-quality-forecast-chart',
    'air-quality-comparison-station', 'air-quality-comparison-status', 'air-quality-comparison-rows',
    'air-quality-live-tab', 'air-quality-history-tab', 'air-quality-live-panel', 'air-quality-history-panel',
    'air-quality-history-error', 'air-quality-history-warning', 'air-quality-history-source',
    'air-quality-history-count', 'air-quality-history-events', 'air-quality-history-year',
    'air-quality-history-pollutant', 'air-quality-history-type', 'air-quality-history-chart',
    'air-quality-map-tab', 'air-quality-map-panel', 'air-quality-map-component', 'air-quality-map-type',
    'air-quality-map-opacity', 'air-quality-map-opacity-value', 'air-quality-map', 'air-quality-map-status',
    'air-quality-map-source', 'air-quality-map-error', 'air-quality-map-warning',
    'air-quality-map-legend-description', 'air-quality-map-legend',
]) {
    elements.set(id, element());
}
elements.set('air-quality-forecast-range', element());
elements.get('air-quality').dataset.apiAirQuality = '/api/air-quality.php';
elements.get('air-quality-comparison-station').value = '';
elements.get('air-quality-history-panel').dataset.api = '/api/air-quality-history.php';
elements.get('air-quality-map-panel').dataset.api = '/api/air-quality-map.php';
elements.get('air-quality-map-component').value = 'PM10';
elements.get('air-quality-map-type').value = 'hours';
elements.get('air-quality-map-opacity').value = '75';
elements.get('air-quality').querySelector = selector => {
    const component = selector.match(/data-measurement="([^"]+)"/)[1];
    if (!measurements.has(component)) measurements.set(component, element());
    return measurements.get(component);
};
elements.get('air-quality-forecast').dataset = { api: '/api/air-quality-forecast.php' };
elements.get('air-quality-forecast-horizon').value = '72';

globalThis.document = {
    getElementById: id => elements.get(id) || null,
    createElement: () => element(),
    documentElement: {},
    addEventListener() {},
};
globalThis.window = { addEventListener() {} };
globalThis.getComputedStyle = () => ({
    getPropertyValue: () => '#212529',
});
let displayedMap = null;
globalThis.L = {
    map() {
        return {
            attributionControl: { addAttribution() {} },
            setView() { return this; },
            invalidateSize() {},
        };
    },
    tileLayer() {
        return { on() { return this; }, addTo() { return this; } };
    },
    circleMarker() {
        return { bindTooltip() { return this; }, addTo() { return this; } };
    },
    imageOverlay(image, bounds, options) {
        const handlers = {};
        displayedMap = { image, bounds, options, opacity: options.opacity };
        return {
            once(name, callback) { handlers[name] = callback; return this; },
            off(name) { delete handlers[name]; return this; },
            addTo() { if (handlers.load) handlers.load(); return this; },
            remove() { this.removed = true; return this; },
            setOpacity(value) { displayedMap.opacity = value; },
        };
    },
};
let chartOption = null;
let historyChartOption = null;
let chartClears = 0;
globalThis.echarts = {
    init: element => ({
        clear() { chartClears++; },
        resize() {},
        setOption(option) {
            if (element === elements.get('air-quality-history-chart')) historyChartOption = option;
            else chartOption = option;
        },
    }),
};

const observation = {
    value: 12.5,
    unit: 'ug/m^3',
    statusText: 'Operativní měření.',
    observedAt: '2026-10-28T00:00:00Z',
    stale: false,
    ageSeconds: 30,
};
const current = {
    station: { name: 'Most', code: 'UMOM', region: 'Ústecký', elevationMeters: 240 },
    measurements: { PM10: observation, PM2_5: observation, NO2: observation, SO2: observation, O3: observation },
    nearbyStations: [{ code: 'LIT', name: 'Litvínov', distanceKm: 5.2 }],
    comparison: {
        station: { code: 'LIT', name: 'Litvínov', region: 'Ústecký' },
        distanceKm: 5.2,
        measurements: { PM10: observation, PM2_5: observation, NO2: observation, SO2: observation, O3: observation },
        pollutants: {
            PM10: { status: 'comparable', difference: -8 },
            PM2_5: { status: 'time_mismatch', difference: null },
            NO2: { status: 'unavailable', difference: null },
            SO2: { status: 'unit_mismatch', difference: null },
            O3: { status: 'interval_mismatch', difference: null },
        },
    },
    index: { available: true, level: '1A', label: 'Velmi dobrá', observedAt: observation.observedAt },
    meta: { warnings: [], observationStale: false, sourceCreatedAt: observation.observedAt, sources: { values: { fetchedAt: observation.observedAt } } },
};
const items = Array.from({ length: 72 }, (_, hour) => {
    const start = new Date(Date.parse('2026-10-28T01:00:00Z') + hour * 3600000).toISOString().replace('.000Z', 'Z');
    return {
        start,
        end: new Date(Date.parse(start) + 3600000).toISOString().replace('.000Z', 'Z'),
        type: 'predpoved',
        min: 10,
        p25: 11,
        median: 12,
        p75: 13,
        max: 14,
    };
});
const forecast = {
    pollutant: { code: 'PM10', label: 'PM10', unit: null },
    currentMap: { start: '2026-10-28T00:00:00Z', end: '2026-10-28T01:00:00Z', median: 9, type: 'mapa' },
    items,
    meta: { createdAt: '2026-10-28T00:15:00Z', stale: false, warnings: [], availableHours: 72 },
};
const history = {
    area: 'Ústecký kraj',
    events: [
        { pollutant: 'PM10', type: 'smogová situace', issuedAt: '2010-12-03T15:20:00Z',
            endedAt: '2010-12-05T23:08:00Z', durationHours: 55.8, note: null },
        { pollutant: 'PM10', type: 'regulace', issuedAt: '2010-12-03T18:10:00Z',
            endedAt: '2010-12-05T23:08:00Z', durationHours: 53, note: '<img>' },
        { pollutant: 'O3', type: 'varování', issuedAt: '2025-07-14T10:00:00Z',
            endedAt: null, durationHours: null, note: null },
    ],
    meta: {
        source: { fetchedAt: '2026-10-28T10:00:00Z', license: 'CC BY 4.0', cacheStatus: 'fresh' },
        warnings: [],
    },
};
const map = {
    component: { code: 'PM10', label: 'PM10' },
    type: { code: 'hours', label: 'Hodinový průměr' },
    period: {
        start: '2026-10-28T09:00:00Z', end: '2026-10-28T10:00:00Z',
        createdAt: '2026-10-28T10:01:00Z', ageSeconds: 2100,
    },
    map: 'data:image/png;base64,abc',
    bounds: [[48.109, 11.5074], [51.4484, 19.5518]],
    raster: {
        width: 3000, height: 1929, gridStepMeters: 100,
        pixelWidthMeters: 298.5, pixelHeightMeters: 298.6, crs: 'EPSG:3857',
    },
    legend: {
        description: 'hodinový průměr', units: 'µg∙m−3',
        values: [{ color: '#C7EAFB', label: '<20' }, { color: '#FFFFFF', label: 'Neúplná data' }],
    },
    meta: {
        stale: false, warnings: [],
        sources: { map: { fetchedAt: '2026-10-28T10:33:00Z' } },
    },
};
let forecastFailure = false;
const forecastUrls = [];
const comparisonUrls = [];
let historyRequests = 0;
const mapUrls = [];

globalThis.fetch = async url => {
    if (url.startsWith('/api/air-quality.php')) {
        comparisonUrls.push(url);
        return { ok: true, status: 200, json: async () => structuredClone(current) };
    }
    if (url === '/api/air-quality-history.php') {
        historyRequests++;
        return { ok: true, status: 200, json: async () => structuredClone(history) };
    }
    if (url.startsWith('/api/air-quality-map.php')) {
        mapUrls.push(url);
        const selected = structuredClone(map);
        const params = new URL(url, 'https://local').searchParams;
        selected.component.code = params.get('component');
        selected.type.code = params.get('type');
        return { ok: true, status: 200, json: async () => selected };
    }
    forecastUrls.push(url);
    if (forecastFailure) {
        return {
            ok: false,
            status: 503,
            json: async () => ({ error: { message: 'Předpověď je dočasně nedostupná.' } }),
        };
    }
    return { ok: true, status: 200, json: async () => structuredClone(forecast) };
};

const settle = () => new Promise(resolve => setImmediate(resolve));
await import('../../public/assets/js/air-quality.js');
await settle();
assert.equal(elements.get('air-quality-forecast').attributes.get('aria-busy'), 'false');
assert.equal(chartOption.xAxis.data.length, 72);
assert.equal(chartOption.series[4].name, 'Medián (P50)');
assert.equal(chartOption.series[1].areaStyle.opacity, 0.28);
assert.match(elements.get('air-quality-forecast-map').textContent, /Nejde o měření stanice/);
assert.match(chartOption.tooltip.formatter([{ dataIndex: 0 }]), /Minimum–maximum: 10–14/);
assert.equal(elements.get('air-quality-comparison-rows').children.length, 5, elements.get('air-quality-error').textContent);
assert.equal(elements.get('air-quality-comparison-rows').children[0].children[3].textContent, '-8,0');
assert.equal(elements.get('air-quality-comparison-rows').children[1].children[3].textContent, 'Jiný čas měření');
assert.match(elements.get('air-quality-comparison-status').textContent, /5,2 km/);

elements.get('air-quality-comparison-station').value = 'LIT';
elements.get('air-quality-comparison-station').events.get('change')();
await settle();
assert.match(comparisonUrls[comparisonUrls.length - 1], /compare=LIT$/);

elements.get('air-quality-history-tab').events.get('click')();
await settle();
assert.equal(historyRequests, 1);
assert.equal(elements.get('air-quality-history-events').children.length, 3);
assert.deepEqual(historyChartOption.series[0].data, [55.8, 53]);
assert.equal(historyChartOption.xAxis.name, 'Hodiny');
assert.match(historyChartOption.yAxis.data[0], /3\. 12\. 2010/);
assert.match(historyChartOption.tooltip.formatter({ dataIndex: 0 }), /Délka: 55,8 h/);
assert.match(elements.get('air-quality-history-events').children[1].children[0].children[2].textContent, /<img>/);
assert.match(elements.get('air-quality-history-source').textContent, /CC BY 4.0/);
elements.get('air-quality-history-year').value = '2010';
elements.get('air-quality-history-year').events.get('change')();
assert.equal(elements.get('air-quality-history-events').children.length, 2);
assert.deepEqual(historyChartOption.series[0].data, [55.8, 53].slice(0, 2));
elements.get('air-quality-history-tab').events.get('keydown')({
    key: 'ArrowLeft', preventDefault() {},
});
assert.equal(elements.get('air-quality-live-tab').focused, true);

elements.get('air-quality-map-tab').events.get('click')();
await settle();
assert.equal(mapUrls.length, 1);
assert.match(mapUrls[0], /component=PM10&type=hours$/);
assert.equal(displayedMap.image, 'data:image/png;base64,abc');
assert.deepEqual(displayedMap.bounds, [[48.109, 11.5074], [51.4484, 19.5518]]);
assert.equal(elements.get('air-quality-map-legend').children.length, 2);
assert.equal(elements.get('air-quality-map-legend').children[0].children[1].textContent, '<20');
assert.match(elements.get('air-quality-map-source').textContent, /100 m.*299.*299 m/);
elements.get('air-quality-map-component').value = 'NO2';
elements.get('air-quality-map-component').events.get('change')();
await settle();
assert.equal(elements.get('air-quality-map-type').value, 'hours');
elements.get('air-quality-map-type').value = 'max1h';
elements.get('air-quality-map-type').events.get('change')();
await settle();
assert.match(mapUrls[mapUrls.length - 1], /component=NO2&type=max1h$/);
elements.get('air-quality-map-opacity').value = '40';
elements.get('air-quality-map-opacity').events.get('input')();
assert.equal(displayedMap.opacity, 0.4);

elements.get('air-quality-forecast-horizon').value = '24';
elements.get('air-quality-forecast-horizon').events.get('change')();
assert.equal(chartOption.xAxis.data.length, 24);
elements.get('air-quality-forecast-range').checked = false;
elements.get('air-quality-forecast-range').events.get('change')();
assert.equal(chartOption.series[1].areaStyle.opacity, 0);

elements.get('air-quality-forecast-pollutant').value = 'NO2';
forecast.pollutant = { code: 'NO2', label: 'NO₂', unit: null };
elements.get('air-quality-forecast-pollutant').events.get('change')();
await settle();
assert.match(forecastUrls[forecastUrls.length - 1], /pollutant=NO2$/);
assert.ok(chartClears > 0);

forecastFailure = true;
elements.get('air-quality-forecast-pollutant').value = 'SO2';
elements.get('air-quality-forecast-pollutant').events.get('change')();
await settle();
assert.equal(elements.get('air-quality-forecast-error').textContent, 'Předpověď je dočasně nedostupná.');
assert.equal(elements.get('air-quality-forecast').attributes.get('aria-busy'), 'false');
console.log('PASS air-quality frontend: station comparison, SVRS filters/timeline, forecast ranges, source distinctions and failure states.');
