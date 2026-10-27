import assert from 'node:assert/strict';

const elements = new Map();
function element() {
    const classes = new Set(['d-none']);
    return {
        textContent: '', children: [], events: new Map(), dataset: {}, attributes: new Map(), value: '',
        classList: {
            add: key => classes.add(key), remove: key => classes.delete(key),
            toggle: (key, force) => force ? classes.add(key) : classes.delete(key),
            contains: key => classes.has(key),
        },
        append(child) { this.children.push(child); },
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
document.getElementById('radar').dataset.api = '/api/radar.php';
document.getElementById('radar-product').value = 'maxz';
document.getElementById('radar-opacity').value = '70';
const overlays = [];
const markers = [];
let tileError;
let imageError = false;
let lastView;
let fitBounds;
const map = {
    setView(center, zoom) { lastView = { center, zoom }; return this; },
    fitBounds(bounds) { fitBounds = bounds; },
    attributionControl: { addAttribution() {} },
};
globalThis.L = {
    map: () => map,
    tileLayer(url, options) {
        assert.equal(url, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
        assert.match(options.attribution, /OpenStreetMap/);
        return { on(name, callback) { tileError = callback; return this; }, addTo() {} };
    },
    circleMarker(position) {
        const marker = { position, bindTooltip(label) { this.label = label.textContent; return this; }, addTo() {} };
        markers.push(marker);
        return marker;
    },
    imageOverlay(image, bounds, options) {
        const events = new Map();
        const layer = {
            image, bounds, options, removed: false,
            once(name, callback) { events.set(name, callback); return this; },
            off(name) { events.delete(name); return this; },
            addTo() { queueMicrotask(() => events.get(imageError ? 'error' : 'load')()); return this; },
            remove() { this.removed = true; },
            setOpacity(value) { this.opacity = value; },
        };
        overlays.push(layer);
        return layer;
    },
};
const payload = {
    product: { id: 'maxz', name: 'MAX_Z_MASK' }, crs: 'EPSG:3857',
    bounds: [[48.047, 11.267], [52.167, 20.770]], dataBounds: [[48.047, 11.267], [51.458, 19.624]],
    image: 'data:image/png;base64,test', legend: 'data:image/png;base64,legend',
    frame: { filename: 'test.png', start: '2026-10-27T22:35:00Z', end: '2026-10-27T22:40:00Z',
        ageSeconds: 3660, width: 680, height: 460 },
    stations: [{ name: 'OuBrix', latitude: 50.51039, longitude: 13.64525 },
        { name: 'ČHMÚ Kopisty', latitude: 50.54417, longitude: 13.62278 }],
    meta: { stale: false, warnings: [], sources: { image: { fetchedAt: '2026-10-27T22:42:00Z' } } },
};
let fail = false;
let calls = 0;
globalThis.fetch = async url => {
    calls++;
    assert.equal(url, `/api/radar.php?product=${document.getElementById('radar-product').value}`);
    return fail
        ? { ok: false, status: 503, json: async () => ({ error: { message: 'Výpadek ČHMÚ. Neznamená to stav bez srážek.' } }) }
        : { ok: true, json: async () => structuredClone(payload) };
};
const settle = () => new Promise(resolve => setImmediate(resolve));
await import('../../public/assets/js/radar.js');
await settle();
assert.equal(overlays.length, 1);
assert.deepEqual(overlays[0].bounds, payload.bounds);
assert.equal(overlays[0].options.opacity, 0.7);
assert.equal(markers.length, 2);
assert.equal(markers[1].label, 'ČHMÚ Kopisty');
assert.equal(lastView.zoom, 8);
assert.match(document.getElementById('radar-time').textContent, /stáří 1 h 1 min/);
assert.match(document.getElementById('radar-description').textContent, /nikoli naměřený srážkový úhrn/);
assert.match(document.getElementById('radar-legend').alt, /dBZ/);
assert.equal(document.getElementById('radar').attributes.get('aria-busy'), 'false');
const event = (id, name = 'click') => document.getElementById(id).events.get(name)();
document.getElementById('radar-opacity').value = '35';
event('radar-opacity', 'input');
assert.equal(overlays[0].opacity, 0.35);
assert.equal(document.getElementById('radar-opacity-value').textContent, '35 %');
event('radar-home');
assert.equal(lastView.zoom, 9);
event('radar-country');
assert.deepEqual(fitBounds, payload.dataBounds);
payload.product = { id: 'merge', name: 'MERGE' };
payload.frame.start = '2026-10-27T21:40:00Z';
document.getElementById('radar-product').value = 'merge';
event('radar-product', 'change');
event('radar-reload');
await settle();
assert.equal(calls, 2);
assert.equal(overlays[0].removed, true);
assert.equal(overlays[1].options.opacity, 0.35);
assert.match(document.getElementById('radar-description').textContent, /NESČÍTAJÍ/);
assert.match(document.getElementById('radar-legend').alt, /úhrn v mm/);
assert.equal(markers.length, 2);
payload.meta.stale = true;
payload.meta.warnings = ['Stará cache při výpadku.'];
event('radar-reload');
await settle();
assert.match(document.getElementById('radar-time').textContent, /ZASTARALÝ SNÍMEK/);
assert.equal(document.getElementById('radar-warning').classList.contains('d-none'), false);
tileError();
assert.equal(document.getElementById('radar-map-error').classList.contains('d-none'), false);
fail = true;
event('radar-reload');
await settle();
assert.equal(overlays.at(-1).removed, true);
assert.equal(document.getElementById('radar-time').textContent, '');
assert.equal(document.getElementById('radar-legend-panel').classList.contains('d-none'), true);
assert.match(document.getElementById('radar-error').textContent, /Neznamená to stav bez srážek/);
assert.equal(document.getElementById('radar-product').disabled, false);
fail = false;
imageError = true;
event('radar-reload');
await settle();
assert.equal(overlays.at(-1).removed, true);
assert.match(document.getElementById('radar-error').textContent, /obrázek se nepodařilo zobrazit/);
imageError = false;
event('radar-reload');
await settle();
assert.equal(document.getElementById('radar-error').classList.contains('d-none'), true);
assert.equal(overlays.at(-1).removed, false);
payload.crs = 'EPSG:4326';
event('radar-reload');
await settle();
assert.match(document.getElementById('radar-error').textContent, /projekce/);
console.log('PASS radar frontend: full bounds, station markers, opacity, product switch, legend, stale state, map/image/API failures and retry.');
