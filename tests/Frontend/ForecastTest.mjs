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
const item = {
    mainHeadline: 'Předpověď na noc', sent: '2026-10-27T21:00:00Z', startTime: '2026-10-27T21:00:00Z',
    endTime: null, ageSeconds: 3660, stale: false, status: 'published',
    sections: [{ headline: null, text: '<img src=x onerror=alert(1)>' },
        { headline: 'Počasí:', text: 'Slabý vítr.\nMísty mlhy.' }],
};
const payload = {
    items: [item],
    meta: { warnings: [], source: { fetchedAt: '2026-10-27T22:00:00Z', cacheStatus: 'fresh' } },
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
await import('../../public/assets/js/forecast.js');
await settle();
const cards = document.getElementById('forecast-items');
let body = cards.children[0].children[0];
assert.equal(body.children[0].textContent, 'Předpověď na noc');
assert.match(body.children[2].textContent, /stáří 1 h 1 min/);
assert.match(body.children[2].textContent, /Konec platnosti zdroj neuvádí/);
assert.equal(body.children[3].textContent, '<img src=x onerror=alert(1)>');
assert.equal(body.children[4].textContent, 'Počasí:');
assert.equal(body.children[5].textContent, 'Slabý vítr.\nMísty mlhy.');
assert.equal(document.getElementById('forecast').attributes.get('aria-busy'), 'false');
item.status = 'stale';
item.mainHeadline = null;
payload.meta.warnings = ['Zastaralé vydání.'];
payload.meta.source.cacheStatus = 'stale';
const reload = () => document.getElementById('forecast-reload').events.get('click')();
reload();
reload();
await settle();
assert.equal(calls, 2);
body = cards.children[0].children[0];
assert.equal(body.children[0].textContent, 'Předpověď ČHMÚ');
assert.equal(body.children[1].textContent, 'Zastaralé vydání');
assert.match(cards.children[0].className, /border-warning/);
assert.equal(document.getElementById('forecast-warning').classList.contains('d-none'), false);
assert.match(document.getElementById('forecast-source').textContent, /stará cache/);
fail = true;
reload();
await settle();
assert.equal(cards.children.length, 0);
assert.equal(document.getElementById('forecast-error').textContent, 'Výpadek ČHMÚ.');
assert.equal(document.getElementById('forecast-reload').disabled, false);
fail = false;
reload();
await settle();
assert.equal(cards.children.length, 1);
assert.equal(document.getElementById('forecast-error').classList.contains('d-none'), true);
console.log('PASS forecast frontend: safe text, optional headings, timestamps, stale state, failure and retry.');
