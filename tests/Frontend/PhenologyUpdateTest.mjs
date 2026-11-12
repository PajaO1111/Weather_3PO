import assert from 'node:assert/strict';

const elements = new Map();
function element() {
    return {
        value: '', textContent: '', disabled: false, dataset: {}, events: new Map(),
        classList: { add() {}, remove() {} },
        setAttribute() {}, append() {}, replaceChildren() {},
        querySelectorAll: () => [],
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
globalThis.window = { addEventListener() {} };
const root = document.getElementById('phenology');
root.dataset.api = '/api/phenology.php';
root.dataset.updateApi = '/api/phenology-update.php';
const requests = [];
let response = { ok: true, json: async () => ({ status: 'unchanged', message: 'Nová data nejsou.' }) };
let release = null;
globalThis.fetch = async (url, options) => {
    requests.push({ url, options });
    if (!options?.method) {
        return { ok: false, json: async () => ({ error: { message: 'Data unavailable' } }) };
    }
    if (release !== null) await new Promise(resolve => { release = resolve; });
    return response;
};
await import('../../public/assets/js/phenology.js');
const settle = () => new Promise(resolve => setImmediate(resolve));
await settle();
const form = document.getElementById('phenology-update-form');
const button = document.getElementById('phenology-update-submit');
const password = document.getElementById('phenology-password');
const status = document.getElementById('phenology-update-status');
const submit = () => form.events.get('submit')({ preventDefault() {} });
password.value = 'heslo-žluťoučké-%';
await submit();
const request = requests.find(item => item.options?.method === 'POST');
assert.equal(request.url, '/api/phenology-update.php');
assert.equal(decodeURIComponent(request.options.headers['X-Phenology-Password']), 'heslo-žluťoučké-%');
assert.equal(request.options.credentials, 'same-origin');
assert.equal(request.options.cache, 'no-store');
assert.equal(password.value, '');
assert.equal(button.disabled, false);
assert.equal(status.textContent, 'Nová data nejsou.');

response = { ok: false, json: async () => ({ error: { message: 'Nesprávné heslo <test>' } }) };
password.value = 'bad';
await submit();
assert.match(status.textContent, /Nesprávné heslo <test>/);
assert.equal(password.value, '');
assert.equal(password.disabled, false);

response = { ok: true, json: async () => ({ status: 'updated', message: 'Aktualizováno.' }) };
const before = requests.filter(item => !item.options?.method).length;
password.value = 'test-password';
await submit();
assert.equal(requests.filter(item => !item.options?.method).length, before + 1);
assert.equal(status.textContent, 'Aktualizováno.');

release = () => {};
password.value = 'test-password';
const pending = submit();
assert.equal(button.disabled, true);
assert.equal(password.value, '');
const count = requests.length;
await submit();
assert.equal(requests.length, count, 'ignore duplicate submissions during update');
release();
await pending;
assert.equal(button.disabled, false);
console.log('PASS phenology update: protected POST, unchanged, errors, reload, password clearing and duplicate prevention.');
