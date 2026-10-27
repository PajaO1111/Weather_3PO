import assert from 'node:assert/strict';
import { fillFields, formatNumber } from '../../public/assets/js/api.js';

const field = { dataset: { field: 'current.temp_c', format: '1' }, tagName: 'SPAN', textContent: '' };
fillFields({ querySelectorAll: () => [field] }, { current: { temp_c: 7.9 } });
assert.equal(field.textContent, '7,9');
assert.equal(formatNumber(8, 1), '8,0');
assert.equal(formatNumber(-7.9, 1), '-7,9');
assert.equal(formatNumber(null, 1), '–');

const elements = new Map();
const element = () => ({
    textContent: '', children: [], dataset: {}, classList: { add() {}, remove() {}, toggle() {} },
    setAttribute() {}, append(child) { this.children.push(child); },
    replaceChildren() { this.children = []; },
});
globalThis.document = {
    documentElement: {},
    getElementById(id) {
        if (!elements.has(id)) elements.set(id, element());
        return elements.get(id);
    },
    createElement: element,
    addEventListener() {},
};
document.getElementById('comparison').dataset.api = '/api/comparison.php';
globalThis.getComputedStyle = () => ({ getPropertyValue: () => '' });
let chartOptions = null;
globalThis.window = {
    echarts: { init: () => ({ setOption(options) { chartOptions = options; } }) },
    addEventListener() {},
};
globalThis.fetch = async () => ({
    ok: true,
    json: async () => ({
        items: [{ time: '2026-10-27T18:00:00Z', local: 7.9, kopisty: 8.0,
            localSamples: 12, kopistySamples: 6, kopistyExcluded: 0, difference: -0.1 }],
        kopistyStation: null,
        summary: { pairedHours: 1, meanDifference: -0.1 },
        meta: { warnings: [], kopistyQualityCounts: {}, kopistyFlagCounts: {} },
    }),
});
await import('../../public/assets/js/comparison.js');
await new Promise(resolve => setTimeout(resolve, 0));
const cells = elements.get('comparison-rows').children[0].children;
assert.equal(cells[1].textContent, '7,9');
assert.equal(cells[3].textContent, '8,0');
assert.equal(cells[6].textContent, '-0,1');
assert.equal(chartOptions.series[0].data[0], 7.9);
assert.equal(chartOptions.tooltip.valueFormatter(7.9), '7,9 °C');
assert.equal(elements.get('comparison-error').textContent, '');
console.log('PASS temperature precision: dashboard, comparison table, chart values and tooltip.');
