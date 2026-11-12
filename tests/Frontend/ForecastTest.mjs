import assert from 'node:assert/strict';

const elements = new Map();
function element() {
    const classes = new Set(['d-none']);
    return {
        textContent: '', children: [], events: new Map(), dataset: {}, attributes: new Map(),
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
    getElementById(id) {
        if (!elements.has(id)) elements.set(id, element());
        return elements.get(id);
    },
    createElement: element,
};
document.getElementById('forecast').dataset.api = '/api/forecast.php';

const now = Date.now();
const firstHour = new Date(now - 30 * 60 * 1000);
firstHour.setMinutes(0, 0, 0);
const hours = Array.from({ length: 28 }, (_, index) => ({
    time: new Date(firstHour.getTime() + index * 60 * 60 * 1000).toISOString(),
    temperatureC: index - 4,
    precipitationMm: index === 1 ? 0.4 : 0,
    snowMm: 0,
    windSpeedMs: index === 3 ? 8 : 2,
    windGustMs: index === 3 ? 11 : 3,
    windDirectionDeg: 45,
    pressureHpa: 1020 + index / 10,
    cloudCoverPct: 50,
    icon: 40,
}));
const payload = {
    location: { name: 'Most', model: 'ALADIN', pointElevationM: 280, pointId: 282 },
    hours,
    meta: { warnings: [], source: { fetchedAt: new Date(now).toISOString(), ageSeconds: 0, cacheStatus: 'fresh' } },
};
let fail = false;
let calls = 0;
globalThis.fetch = async url => {
    calls++;
    assert.equal(url, '/api/forecast.php');
    return fail
        ? { ok: false, status: 503, json: async () => ({ error: { message: 'Výpadek ČHMÚ.' } }) }
        : { ok: true, json: async () => structuredClone(payload) };
};
const settle = () => new Promise(resolve => setImmediate(resolve));
const textTree = node => [node.textContent, ...node.children.map(textTree)].join(' ');
const findImageSource = node => node.src || node.children.map(findImageSource).find(Boolean);
await import('../../public/assets/js/forecast.js');
await settle();
const cards = document.getElementById('forecast-items');
assert.ok(cards.children.length >= 2, 'hourly forecast is grouped into local-day cards');
assert.match(textTree(cards), /Teplota/);
assert.match(textTree(cards), /Srážky/);
assert.match(textTree(cards), /Sníh/);
assert.match(textTree(cards), /Vítr/);
assert.match(textTree(cards), /Tlak/);
assert.equal(findImageSource(cards), 'https://www.chmi.cz/o/chmu-theme/images/icon/40.svg');
assert.match(document.getElementById('forecast-source').textContent, /Model ALADIN.*bod ČHMÚ 282, 280 m n\. m\./);
assert.equal(document.getElementById('forecast').attributes.get('aria-busy'), 'false');

const reload = () => document.getElementById('forecast-reload').events.get('click')();
payload.meta.warnings = ['Zastaralá cache.'];
reload();
reload();
await settle();
assert.equal(calls, 2);
assert.equal(document.getElementById('forecast-warning').classList.contains('d-none'), false);

fail = true;
reload();
await settle();
assert.equal(cards.children.length, 0);
assert.equal(document.getElementById('forecast-error').textContent, 'Výpadek ČHMÚ.');
assert.equal(document.getElementById('forecast-reload').disabled, false);
fail = false;
reload();
await settle();
assert.ok(cards.children.length >= 2);
assert.equal(document.getElementById('forecast-error').classList.contains('d-none'), true);
console.log('PASS Most forecast frontend: daily ALADIN cards, metrics, icons, source, stale warning and retry.');
