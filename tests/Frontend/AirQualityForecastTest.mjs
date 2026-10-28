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
        querySelector(selector) {
            if (!this.children) this.children = new Map();
            if (!this.children.has(selector)) this.children.set(selector, element());
            return this.children.get(selector);
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
]) {
    elements.set(id, element());
}
elements.set('air-quality-forecast-range', element());
elements.get('air-quality').dataset.apiAirQuality = '/api/air-quality.php';
elements.get('air-quality').querySelector = selector => {
    const component = selector.match(/data-measurement="([^"]+)"/)[1];
    if (!measurements.has(component)) measurements.set(component, element());
    return measurements.get(component);
};
elements.get('air-quality-forecast').dataset = { api: '/api/air-quality-forecast.php' };
elements.get('air-quality-forecast-horizon').value = '72';

globalThis.document = {
    getElementById: id => elements.get(id) || null,
};
globalThis.window = { addEventListener() {} };
let chartOption = null;
let chartClears = 0;
globalThis.echarts = {
    init: () => ({
        clear() { chartClears++; },
        resize() {},
        setOption(option) { chartOption = option; },
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
let forecastFailure = false;
const forecastUrls = [];

globalThis.fetch = async url => {
    if (url === '/api/air-quality.php') {
        return { ok: true, status: 200, json: async () => structuredClone(current) };
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
console.log('PASS air-quality forecast frontend: horizons, percentile band, safe map distinction, pollutant selection and API failure.');
