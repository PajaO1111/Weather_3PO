import assert from 'node:assert/strict';

const elements = new Map();
function element() {
    const classes = new Set(['d-none']);
    return {
        textContent: '', value: '', dataset: {}, children: [], events: new Map(), attributes: new Map(),
        classList: {
            add: name => classes.add(name), remove: name => classes.delete(name),
            toggle: (name, force) => force ? classes.add(name) : classes.delete(name),
            contains: name => classes.has(name),
        },
        append(child) {
            this.children.push(child);
            if (child.selected) this.value = child.value;
        },
        replaceChildren() { this.children = []; this.value = ''; },
        setAttribute(name, value) { this.attributes.set(name, value); },
        addEventListener(name, callback) { this.events.set(name, callback); },
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
const options = [];
globalThis.window = {
    echarts: { init: () => ({ setOption: value => options.push(value), resize() {} }) },
    addEventListener() {},
};
const root = document.getElementById('climate');
root.dataset.api = '/api/climate.php';
const reading = value => ({ value, rawValue: value, status: value === null ? 'missing' : 'available',
    flagRepeat: '', flagInterrupted: '' });
const payload = {
    station: { name: 'Kopisty', id: '0-20000-0-11433' }, year: 2025, years: [2025, 2024],
    rainfallAnnual: null,
    items: Array.from({ length: 12 }, (_, index) => ({
        month: index + 1, temperature: reading(7.9), precipitation: reading(0),
        temperatureDifference: -0.1, precipitationDifference: null,
        officialNormal: { temperature: 8, temperatureDifference: -0.1, precipitation: 30,
            precipitationDifference: -30, precipitationPercent: 0 },
    })),
    reference: {
        rainfallAnnual: 493.67, completeYears: 30,
        months: Array.from({ length: 12 }, (_, index) => ({ month: index + 1,
            temperature: { value: 8, count: 30 }, precipitation: { value: null, count: 29 } })),
    },
    officialNormal: {
        annual: { temperature: 9.4, precipitation: 493.5 },
        months: Array.from({ length: 12 }, (_, index) => ({ month: index + 1,
            temperature: 8, precipitation: 30 })),
    },
    officialComparison: { annualRainfallDifference: null, annualRainfallPercent: null },
    meta: {
        warnings: ['Neúplná reference.'],
        sources: { kopisty_monthly: { createdAt: '2026-05-05T10:32:27Z', version: '1.0',
            fetchedAt: '2026-10-27T18:00:00Z', cacheStatus: 'stale' } },
    },
};
payload.items[1].temperature = { ...reading(null), rawValue: -3.2, status: 'flagged', flagRepeat: '<img>' };
const requests = [];
let fail = false;
globalThis.fetch = async url => {
    requests.push(url);
    if (fail) return { ok: false, status: 503, json: async () => ({ error: { message: 'Zdroj není dostupný.' } }) };
    return { ok: true, json: async () => structuredClone(payload) };
};
const settle = () => new Promise(resolve => setImmediate(resolve));
await import('../../public/assets/js/climate.js');
await settle();
assert.equal(root.attributes.get('aria-busy'), 'false');
assert.equal(elements.get('climate-results').classList.contains('d-none'), false);
assert.equal(elements.get('climate-year').value, '2025');
assert.equal(elements.get('climate-rows').children.length, 12);
const cells = elements.get('climate-rows').children[0].children;
assert.equal(cells[1].textContent, '7,9');
assert.equal(cells[2].textContent, '8,0');
assert.equal(cells[3].textContent, '-0,1');
assert.equal(cells[4].textContent, '8,0');
assert.equal(cells[5].textContent, '30/30');
assert.equal(cells[6].textContent, '0,0');
assert.equal(cells[7].textContent, '30,0');
assert.equal(cells[8].textContent, '-30,0');
assert.equal(cells[9].textContent, '0,0');
assert.equal(cells[10].textContent, '–');
assert.equal(cells[11].textContent, '29/30');
assert.match(elements.get('climate-rows').children[1].children[12].textContent, /FLAG_REPEAT=<img>/);
assert.match(elements.get('climate-source').textContent, /stará cache/);
assert.equal(options[0].series[0].data[0], 7.9);
assert.equal(options[0].series[0].data[1], null);
assert.equal(options[0].series[0].connectNulls, false);
assert.equal(options[0].series[1].name, 'Oficiální normál ČHMÚ 1991–2020');
assert.equal(options[0].series[1].data[0], 8);
assert.equal(options[0].series[2].name, 'Vlastní ref. průměr z archivu');
assert.equal(options[0].tooltip.valueFormatter(7.9), '7,9 °C');
const form = elements.get('climate-form');
const submit = () => form.events.get('submit')({ preventDefault() {} });
elements.get('climate-year').value = '2024';
payload.year = 2024;
submit();
await settle();
assert.equal(requests.at(-1), '/api/climate.php?year=2024');
assert.equal(options.at(-1).series[0].name, '2024');
fail = true;
submit();
await settle();
assert.equal(elements.get('climate-error').textContent, 'Zdroj není dostupný.');
assert.equal(elements.get('climate-error').classList.contains('d-none'), false);
assert.equal(elements.get('climate-results').classList.contains('d-none'), true);
assert.equal(root.attributes.get('aria-busy'), 'false');
assert.equal(elements.get('climate-submit').disabled, false);
fail = false;
submit();
await settle();
assert.equal(elements.get('climate-results').classList.contains('d-none'), false);
assert.equal(elements.get('climate-error').classList.contains('d-none'), true);
console.log('PASS climate frontend: decimals, missing values, flags, stale source, year selection, failure and retry.');
