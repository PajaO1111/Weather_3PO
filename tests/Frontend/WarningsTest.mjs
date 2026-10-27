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
document.getElementById('warnings').dataset.api = '/api/warnings.php';
const item = {
    message: { identifier: 'CAP-1', sender: 'chmi@chmi.cz', sent: '2026-10-27T21:00:00Z',
        status: 'Actual', msgType: 'Update', scope: 'Public', note: null,
        references: [{ sender: 'chmi@chmi.cz', identifier: 'CAP-0', sent: '2026-10-27T20:00:00Z' }] },
    info: { language: 'cs', categories: ['Env'], event: 'Smogová situace PM10', headline: null,
        severity: 'Severe', certainty: 'Observed', urgency: 'Immediate', onset: '2026-10-27T21:00:00Z',
        effective: null, expires: null, eventEndingTime: null,
        description: '<img src=x onerror=alert(1)>', instruction: 'Omezte námahu.\nNevětrejte.',
        areas: [{ description: 'Ústecký kraj', geocodes: [{ name: 'CISORP', value: '4209' }], altitude: '1000', ceiling: null },
            { description: 'Jiná oblast', geocodes: [{ name: 'CISORP', value: '4208' }], altitude: null, ceiling: null }],
        eventCodes: [{ name: 'SVRS', value: 'PM10' }], parameters: [{ name: 'situation', value: 'Smogová situace' }] },
    state: 'active', noWarning: false, ageSeconds: 3660, stale: false,
};
const payload = {
    summary: { state: 'warnings', active: 1, upcoming: 0, noWarningReports: 0 },
    items: [item], notices: [], meta: { warnings: [], latestSent: '2026-10-27T21:00:00Z',
        source: { fetchedAt: '2026-10-27T22:00:00Z', cacheStatus: 'fresh' } },
};
let fail = false;
let calls = 0;
globalThis.fetch = async url => {
    calls++;
    assert.equal(url, '/api/warnings.php');
    return fail
        ? { ok: false, status: 503, json: async () => ({ error: { message: 'Výpadek ČHMÚ. Neznamená to stav bez výstrah.' } }) }
        : { ok: true, json: async () => structuredClone(payload) };
};
const settle = () => new Promise(resolve => setImmediate(resolve));
await import('../../public/assets/js/warnings.js');
await settle();
const cards = document.getElementById('warnings-items');
const allText = node => [node.textContent, ...node.children.map(allText)].join('\n');
assert.equal(cards.children.length, 1);
let card = cards.children[0];
assert.match(card.className, /warning-severity-severe/);
let text = allText(card);
assert.match(text, /Vysoký stupeň nebezpečí/);
assert.match(text, /Pozorovaný jev.*Okamžitá reakce/);
assert.match(text, /stáří 1 h 1 min/);
assert.match(text, /Konec platnosti zdroj neuvádí/);
assert.match(text, /1000 stop/);
assert.match(text, /Reference: chmi@chmi.cz, CAP-0/);
assert.match(text, /SVRS: PM10/);
assert.doesNotMatch(text, /Jiná oblast/);
assert.equal(card.children[0].children.find(node => node.textContent === '<img src=x onerror=alert(1)>').children.length, 0);
assert.match(text, /Omezte námahu.\nNevětrejte./);
assert.equal(document.getElementById('warnings').attributes.get('aria-busy'), 'false');
const reload = () => document.getElementById('warnings-reload').events.get('click')();
item.state = 'upcoming';
item.info.expires = '2026-10-28T12:00:00Z';
reload();
reload();
await settle();
assert.equal(calls, 2);
assert.match(allText(cards), /Začíná později/);
assert.match(allText(cards), /Platnost zprávy do:/);
item.state = 'non_actual';
item.message.status = 'Test';
reload();
await settle();
assert.equal(cards.children.length, 0);
assert.match(allText(document.getElementById('warnings-other-items')), /Test/);
assert.equal(document.getElementById('warnings-other').classList.contains('d-none'), false);
item.state = 'no_warning';
item.noWarning = true;
item.message.status = 'Actual';
payload.summary = { state: 'no_warning_reported', active: 0, upcoming: 0, noWarningReports: 18 };
reload();
await settle();
assert.match(document.getElementById('warnings-summary').textContent, /18 hlášení bez výstrahy/);
assert.equal(cards.children.length, 0);
payload.summary.state = 'unverified';
payload.meta.warnings = ['Zastaralý zdroj.'];
payload.meta.source.cacheStatus = 'stale';
reload();
await settle();
assert.match(document.getElementById('warnings-summary').textContent, /nelze potvrdit/);
assert.equal(document.getElementById('warnings-warning').classList.contains('d-none'), false);
assert.match(document.getElementById('warnings-source').textContent, /stará cache/);
fail = true;
reload();
await settle();
assert.equal(cards.children.length, 0);
assert.equal(document.getElementById('warnings-other-items').children.length, 0);
assert.equal(document.getElementById('warnings-summary').classList.contains('d-none'), true);
assert.match(document.getElementById('warnings-error').textContent, /Neznamená to stav bez výstrah/);
assert.equal(document.getElementById('warnings-reload').disabled, false);
fail = false;
reload();
await settle();
assert.equal(document.getElementById('warnings-error').classList.contains('d-none'), true);
assert.equal(document.getElementById('warnings-other-items').children.length, 1);
console.log('PASS warnings frontend: severity/text safety, dates, ORP, SVRS, test/no-warning/stale states, outage and retry.');
