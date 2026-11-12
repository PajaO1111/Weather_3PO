import assert from 'node:assert/strict';

const elements = new Map();
function element() {
    const classes = new Set(['d-none']);
    return {
        textContent: '', value: '', children: [], events: new Map(), dataset: {}, attributes: new Map(),
        classList: {
            add: key => classes.add(key), remove: key => classes.delete(key),
            toggle: (key, force) => force ? classes.add(key) : classes.delete(key),
            contains: key => classes.has(key),
        },
        append(child) { this.children.push(child); },
        replaceChildren() { this.children = []; },
        setAttribute(key, value) { this.attributes.set(key, value); },
        addEventListener(key, callback) { this.events.set(key, callback); },
    };
}
globalThis.document = {
    documentElement: {},
    getElementById(id) {
        if (!elements.has(id)) elements.set(id, element());
        return elements.get(id);
    },
    createElement: element,
    addEventListener() {},
};
globalThis.getComputedStyle = () => ({ getPropertyValue: () => '' });
let options;
globalThis.window = {
    echarts: { init: () => ({ setOption(value) { options = value; }, resize() {} }) },
    addEventListener() {},
};
document.getElementById('comparison').dataset.api = '/api/comparison.php';
const row = {
    time: '2026-11-02T12:00:00Z', local: 8.0, kopisty: null, difference: null,
    localSamples: 12, kopistySamples: 0, kopistyExcluded: 6,
    kopistyUnverified: 7.0, kopistyUnknownSamples: 6, kopistyUnverifiedSamples: 6,
    kopistyUnverifiedExcluded: 0, unverifiedDifference: 1.0,
};
const series = items => ({ items, summary: { pairedHours: 0, meanDifference: null, unverifiedPairedHours: 1 } });
const payload = {
    metrics: {
        temperature: series([row]),
        precipitation: series([{ ...row, local: 0.45, kopistyUnverified: 0.2, unverifiedDifference: 0.25 }]),
        wind: series([{ ...row, local: 18.0, kopistyUnverified: 14.4, unverifiedDifference: 3.6 }]),
    },
    kopistyStation: null,
    meta: { warnings: [] },
};
let requests = 0;
globalThis.fetch = async () => {
    requests++;
    return { ok: true, json: async () => payload };
};
await import('../../public/assets/js/comparison.js');
await new Promise(resolve => setImmediate(resolve));
const cells = () => document.getElementById('comparison-rows').children[0].children;
assert.equal(cells()[3].textContent, '7,0');
assert.match(cells()[7].textContent, /Neověřené.*6/);
assert.match(document.getElementById('comparison-warning').textContent, /neznámou kvalitu/);
assert.equal(options.series[1].data[0], null);
assert.equal(options.series[2].data[0], 7);
assert.equal(options.series[2].lineStyle.type, 'dashed');

const selector = document.getElementById('comparison-metric');
selector.value = 'precipitation';
selector.events.get('change')();
assert.equal(cells()[1].textContent, '0,45');
assert.equal(cells()[6].textContent, '0,25');
assert.equal(document.getElementById('comparison-local-heading').textContent, 'OuBrix mm');
assert.equal(options.tooltip.valueFormatter(0.2), '0,20 mm');
assert.equal(options.yAxis.scale, false);

selector.value = 'wind';
selector.events.get('change')();
assert.equal(options.yAxis.name, 'km/h');
assert.equal(options.series[0].data[0], 18);
assert.equal(cells()[3].textContent, '14,4');
assert.equal(requests, 1, 'metric switching uses one API response');

payload.metrics.wind.items = [{ ...row, local: null, kopistyUnverified: null, kopistyUnknownSamples: 0 }];
selector.events.get('change')();
assert.match(document.getElementById('comparison-warning').textContent, /pokrytí/);
assert.match(cells()[7].textContent, /Bez dat/);
assert.equal(document.getElementById('comparison').attributes.get('aria-busy'), 'false');
assert.equal(document.getElementById('comparison-error').textContent, '');
console.log('PASS comparison frontend: temperature, rain, wind, units, provisional quality, gaps and switching.');
