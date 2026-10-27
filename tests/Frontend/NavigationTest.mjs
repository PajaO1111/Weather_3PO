import assert from 'node:assert/strict';

const events = new Map();
let focused = false;
const inside = {};
const menu = {
    open: true,
    contains: target => target === inside,
    querySelector: () => ({ focus() { focused = true; } }),
};
globalThis.document = {
    querySelector: () => menu,
    addEventListener: (key, callback) => events.set(key, callback),
};
await import('../../public/assets/js/navigation.js');
events.get('click')({ target: inside });
assert.equal(menu.open, true);
events.get('keydown')({ key: 'Enter' });
assert.equal(menu.open, true);
events.get('keydown')({ key: 'Escape' });
assert.equal(menu.open, false);
assert.equal(focused, true);
menu.open = true;
events.get('click')({ target: {} });
assert.equal(menu.open, false);
globalThis.document.querySelector = () => null;
await import('../../public/assets/js/navigation.js?absent');
console.log('PASS navigation enhancement: internal/outside click, Escape, focus and absent menu.');
