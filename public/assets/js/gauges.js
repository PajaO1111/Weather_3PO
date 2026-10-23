/**
 * Měřidla stránky „Aktuálně“ – čisté SVG bez knihoven.
 *
 * Každá funkce vykreslí grafiku do kontejneru (obsah přepíše), hodnoty jako
 * text doplňuje šablona přes data-field. Barvy grafiky používají CSS proměnné,
 * takže přepnutí světlého/tmavého motivu nevyžaduje nové vykreslení.
 *
 * Barevná stupnice měřidel: zelená (minimum) → žlutá (střed) → červená (maximum).
 */

const NS = 'http://www.w3.org/2000/svg';
const GREEN = [47, 158, 68];
const YELLOW = [242, 192, 0];
const RED = [224, 49, 49];

/** Barva na stupnici zelená → žlutá → červená pro t v intervalu 0…1. */
export function scaleColor(t) {
    const x = Math.min(1, Math.max(0, Number.isFinite(t) ? t : 0));
    const [from, to, k] = x < 0.5 ? [GREEN, YELLOW, x * 2] : [YELLOW, RED, (x - 0.5) * 2];
    const mix = from.map((c, i) => Math.round(c + (to[i] - c) * k));
    return `rgb(${mix.join(',')})`;
}

/** Barvy UV indexu podle WHO (0–2, 3–5, 6–7, 8–10, 11+). */
export function uvColor(uv) {
    if (uv < 3) return '#2f9e44';
    if (uv < 6) return '#f2c200';
    if (uv < 8) return '#f76707';
    if (uv < 11) return '#e03131';
    return '#7048e8';
}

function node(tag, attrs, parent) {
    const element = document.createElementNS(NS, tag);
    for (const [key, value] of Object.entries(attrs || {})) {
        element.setAttribute(key, String(value));
    }
    if (parent) {
        parent.appendChild(element);
    }
    return element;
}

function svg(container, width, height, label) {
    container.querySelector(':scope > svg.wx-svg')?.remove();
    const root = node('svg', { viewBox: `0 0 ${width} ${height}`, class: 'wx-svg', 'aria-hidden': 'true', focusable: 'false' });
    container.prepend(root);
    if (label) {
        container.setAttribute('aria-label', label);
    }
    return root;
}

/** Bod na kružnici; úhel 0° = nahoře, kladný směr = po směru hodinových ručiček. */
function polar(cx, cy, r, degrees) {
    const rad = ((degrees - 90) * Math.PI) / 180;
    return [cx + r * Math.cos(rad), cy + r * Math.sin(rad)];
}

function arcPath(cx, cy, r, from, to) {
    const [x0, y0] = polar(cx, cy, r, from);
    const [x1, y1] = polar(cx, cy, r, to);
    const large = to - from > 180 ? 1 : 0;
    return `M${x0.toFixed(2)} ${y0.toFixed(2)}A${r} ${r} 0 ${large} 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`;
}

const isNumber = (v) => v !== null && v !== undefined && Number.isFinite(Number(v));
const ratio = (v, min, max) => (Number(v) - min) / (max - min);
const clamp01 = (x) => Math.min(1, Math.max(0, x));

/**
 * Obloukové měřidlo (teplota, vlhkost, tlak, záření).
 *
 * @param {HTMLElement} container
 * @param {object} o
 * @param {number} o.min, o.max   rozsah stupnice
 * @param {number|null} o.value   aktuální hodnota
 * @param {Array<{value:number,label:string}>} [o.marks]  značky (např. 24h min / ø / max)
 * @param {number} [o.start=-120], [o.end=120]  úhel začátku a konce oblouku
 * @param {boolean} [o.needle=false]  ručička místo bodu (barometr)
 * @param {string[]} [o.endLabels]  popisky konců stupnice
 * @param {string} [o.label]  aria-label
 */
export function arcGauge(container, o) {
    const start = o.start ?? -120;
    const end = o.end ?? 120;
    const full = end - start >= 360;
    const root = svg(container, 220, full ? 220 : 175, o.label);
    const cx = 110;
    const cy = 110;
    const r = 82;
    const segments = 72;
    const step = (end - start) / segments;
    const t = isNumber(o.value) ? clamp01(ratio(o.value, o.min, o.max)) : null;

    for (let i = 0; i < segments; i++) {
        const a0 = start + i * step;
        const a1 = a0 + step + (i < segments - 1 ? 0.6 : 0);
        const active = t !== null && (i + 0.5) / segments <= t;
        node('path', {
            d: arcPath(cx, cy, r, a0, a1),
            class: active ? 'wx-arc wx-arc-on' : 'wx-arc',
            stroke: scaleColor((i + 0.5) / segments),
        }, root);
    }

    // Blízké značky (např. min = ø = max) sloučit, aby se popisky nepřekrývaly.
    const placed = [];
    for (const mark of o.marks || []) {
        if (!isNumber(mark.value)) continue;
        const angle = start + clamp01(ratio(mark.value, o.min, o.max)) * (end - start);
        const near = placed.find((p) => Math.abs(p.angle - angle) < 14);
        if (near) {
            near.text.textContent += '/' + mark.label;
            continue;
        }
        const [x0, y0] = polar(cx, cy, r + 10, angle);
        const [x1, y1] = polar(cx, cy, r + 17, angle - 3.2);
        const [x2, y2] = polar(cx, cy, r + 17, angle + 3.2);
        node('path', { d: `M${x0} ${y0}L${x1} ${y1}L${x2} ${y2}Z`, class: 'wx-mark' }, root);
        const [lx, ly] = polar(cx, cy, r + 25, angle);
        const text = node('text', { x: lx, y: ly, class: 'wx-mark-label', 'text-anchor': 'middle', 'dominant-baseline': 'middle' }, root);
        text.textContent = mark.label;
        placed.push({ angle, text });
    }

    if (!full && o.endLabels) {
        const [sx, sy] = polar(cx, cy, r, start);
        const [ex, ey] = polar(cx, cy, r, end);
        node('text', { x: sx, y: sy + 22, class: 'wx-end-label', 'text-anchor': 'middle' }, root).textContent = o.endLabels[0];
        node('text', { x: ex, y: ey + 22, class: 'wx-end-label', 'text-anchor': 'middle' }, root).textContent = o.endLabels[1];
    }

    if (t === null) return;
    const angle = start + t * (end - start);
    if (o.needle) {
        const [nx, ny] = polar(cx, cy, r - 16, angle);
        node('line', { x1: cx, y1: cy, x2: nx, y2: ny, class: 'wx-needle' }, root);
        node('circle', { cx, cy, r: 6, class: 'wx-hub' }, root);
    } else {
        const [kx, ky] = polar(cx, cy, r, angle);
        node('circle', { cx: kx, cy: ky, r: 9, class: 'wx-knob', fill: scaleColor(t) }, root);
    }
}

const COMPASS_LABELS = [['S', 0], ['V', 90], ['J', 180], ['Z', 270]];

/**
 * Větrná růžice: šipka na obvodu ukazuje, ODKUD vítr vane, a míří do středu.
 * Barva šipky podle rychlosti na stupnici 0…maxSpeed.
 */
export function compass(container, { direction, speed, maxSpeed = 60, label }) {
    const root = svg(container, 220, 220, label);
    const c = 110;
    const r = 84;
    node('circle', { cx: c, cy: c, r, class: 'wx-ring' }, root);
    for (let i = 0; i < 72; i++) {
        const angle = i * 5;
        const major = angle % 90 === 0;
        const minor = angle % 22.5 === 0;
        const [x0, y0] = polar(c, c, r - 6, angle);
        const [x1, y1] = polar(c, c, r - (major ? 16 : minor ? 12 : 9), angle);
        node('line', { x1: x0, y1: y0, x2: x1, y2: y1, class: major ? 'wx-tick wx-tick-major' : 'wx-tick' }, root);
    }
    for (const [text, angle] of COMPASS_LABELS) {
        const [x, y] = polar(c, c, r - 28, angle);
        node('text', { x, y, class: 'wx-compass-label', 'text-anchor': 'middle', 'dominant-baseline': 'central' }, root).textContent = text;
    }
    if (!isNumber(direction) || !(Number(speed) > 0)) {
        return;
    }
    const group = node('g', { transform: `rotate(${Number(direction)} ${c} ${c})` }, root);
    node('path', {
        d: `M${c} ${c - r + 22}L${c - 11} ${c - r - 6}L${c} ${c - r}L${c + 11} ${c - r - 6}Z`,
        class: 'wx-wind-arrow',
        fill: scaleColor(Number(speed) / maxSpeed),
    }, group);
}

/** Srážkoměr: válec plněný podle denního úhrnu (stupnice se rozšíří při vyšším úhrnu). */
export function rainTube(container, { total, max = 20, label }) {
    const top = Math.max(max, isNumber(total) ? Math.ceil(Number(total) / 5) * 5 : 0);
    const root = svg(container, 120, 180, label);
    const x = 30;
    const y = 12;
    const w = 44;
    const h = 150;
    node('rect', { x, y, width: w, height: h, rx: 10, class: 'wx-tube' }, root);
    if (isNumber(total) && Number(total) > 0) {
        const t = clamp01(Number(total) / top);
        const fill = Math.max(4, t * (h - 4));
        node('rect', { x: x + 3, y: y + h - 2 - fill, width: w - 6, height: fill, rx: 7, fill: scaleColor(t), class: 'wx-tube-fill' }, root);
    }
    const stepValue = top <= 20 ? 5 : top <= 50 ? 10 : 25;
    for (let v = 0; v <= top; v += stepValue) {
        const ty = y + h - 2 - (v / top) * (h - 4);
        node('line', { x1: x + w, y1: ty, x2: x + w + 8, y2: ty, class: 'wx-tick' }, root);
        node('text', { x: x + w + 12, y: ty, class: 'wx-end-label', 'dominant-baseline': 'middle' }, root).textContent = String(v);
    }
}

/** UV stupnice 0–11+ v barvách WHO s ukazatelem hodnoty a dnešního maxima. */
export function uvBar(container, { value, todayMax, label }) {
    const root = svg(container, 240, 64, label);
    const left = 10;
    const width = 220;
    const cell = width / 12;
    for (let i = 0; i < 12; i++) {
        const active = isNumber(value) && Number(value) >= i;
        node('rect', {
            x: left + i * cell + 1, y: 26, width: cell - 2, height: 16, rx: 3,
            fill: uvColor(i), class: active ? 'wx-uv wx-uv-on' : 'wx-uv',
        }, root);
        node('text', { x: left + i * cell + cell / 2, y: 58, class: 'wx-end-label', 'text-anchor': 'middle' }, root)
            .textContent = i === 11 ? '11+' : String(i);
    }
    const pointer = (v, cls) => {
        if (!isNumber(v)) return;
        const px = left + Math.min(11.5, Number(v) + 0.5) * cell;
        node('path', { d: `M${px} 22L${px - 6} 12L${px + 6} 12Z`, class: cls }, root);
    };
    pointer(todayMax, 'wx-mark');
    pointer(value, 'wx-uv-pointer');
}

/**
 * Dráha Slunce mezi východem a západem; poloha podle aktuálního času.
 */
export function sunPath(container, { sunrise, sunset, now = Date.now(), label }) {
    const root = svg(container, 240, 110, label);
    const x0 = 20;
    const x1 = 220;
    const base = 92;
    const height = 74;
    const path = `M${x0} ${base}C${x0 + 40} ${base - height * 1.33} ${x1 - 40} ${base - height * 1.33} ${x1} ${base}`;
    node('line', { x1: 6, y1: base, x2: 234, y2: base, class: 'wx-horizon' }, root);
    node('path', { d: path, class: 'wx-sun-track' }, root);
    const rise = Date.parse(sunrise);
    const set = Date.parse(sunset);
    if (!Number.isFinite(rise) || !Number.isFinite(set) || set <= rise) {
        return;
    }
    const t = (now - rise) / (set - rise);
    if (t <= 0 || t >= 1) {
        return;
    }
    // Bod na Bézierově křivce pro parametr t (dostatečně blízko času).
    const bez = (p0, p1, p2, p3) => (1 - t) ** 3 * p0 + 3 * (1 - t) ** 2 * t * p1 + 3 * (1 - t) * t ** 2 * p2 + t ** 3 * p3;
    const cx1 = x0 + 40;
    const cx2 = x1 - 40;
    const cy1 = base - height * 1.33;
    const sx = bez(x0, cx1, cx2, x1);
    const sy = bez(base, cy1, cy1, base);
    const clip = node('clipPath', { id: 'wx-sun-clip' }, node('defs', {}, root));
    node('rect', { x: 0, y: 0, width: sx, height: base }, clip);
    node('path', { d: path, class: 'wx-sun-done', 'clip-path': 'url(#wx-sun-clip)' }, root);
    node('circle', { cx: sx, cy: sy, r: 16, class: 'wx-sun-glow' }, root);
    node('circle', { cx: sx, cy: sy, r: 9, class: 'wx-sun' }, root);
}

/**
 * Ikona Měsíce podle fáze (0 = nov, 0,5 = úplněk); dorůstající je osvětlen
 * zprava (pohled ze severní polokoule).
 */
export function moonIcon(container, { phase, size = 100, label }) {
    const root = svg(container, size, size, label);
    drawMoon(root, size / 2, size / 2, size / 2 - 4, phase);
}

function drawMoon(parent, cx, cy, r, phase) {
    node('circle', { cx, cy, r, class: 'wx-moon-dark' }, parent);
    if (!isNumber(phase)) return;
    const p = Number(phase);
    const lit = (1 - Math.cos(2 * Math.PI * p)) / 2;
    if (lit < 0.01) return;
    if (lit > 0.99) {
        node('circle', { cx, cy, r, class: 'wx-moon-lit' }, parent);
        return;
    }
    const rx = r * Math.abs(1 - 2 * lit);
    const waxing = p < 0.5;
    const outer = waxing ? 1 : 0;
    const inner = waxing ? (lit < 0.5 ? 0 : 1) : (lit < 0.5 ? 1 : 0);
    node('path', {
        d: `M${cx} ${cy - r}A${r} ${r} 0 0 ${outer} ${cx} ${cy + r}A${rx.toFixed(2)} ${r} 0 0 ${inner} ${cx} ${cy - r}Z`,
        class: 'wx-moon-lit',
    }, parent);
}

const CLOUD = 'M28 74H72A17 17 0 0 0 72 40A23 23 0 0 0 31 35A20 20 0 0 0 28 74Z';

function sunShape(parent, cx, cy, r, cls = 'wx-icon-sun') {
    const group = node('g', { class: cls }, parent);
    node('circle', { cx, cy, r }, group);
    for (let i = 0; i < 8; i++) {
        const [x0, y0] = polar(cx, cy, r + 5, i * 45);
        const [x1, y1] = polar(cx, cy, r + 12, i * 45);
        node('line', { x1: x0, y1: y0, x2: x1, y2: y1 }, group);
    }
}

/**
 * Ikona stavu počasí (kódy z api/current.php → condition.code).
 * V noci se zobrazí Měsíc v aktuální fázi.
 */
export function conditionIcon(container, { code, moonPhase, label }) {
    const root = svg(container, 100, 100, label);
    switch (code) {
        case 'clear':
            sunShape(root, 50, 50, 20);
            break;
        case 'day':
            sunShape(root, 50, 50, 20, 'wx-icon-sun wx-icon-sun-low');
            break;
        case 'partly':
            sunShape(root, 38, 36, 15);
            node('path', { d: CLOUD, class: 'wx-icon-cloud', transform: 'translate(8 8)' }, root);
            break;
        case 'cloudy':
            node('path', { d: CLOUD, class: 'wx-icon-cloud wx-icon-cloud-back', transform: 'translate(10 -8) scale(.8)' }, root);
            node('path', { d: CLOUD, class: 'wx-icon-cloud', transform: 'translate(0 4)' }, root);
            break;
        case 'rain':
        case 'heavy-rain': {
            node('path', { d: CLOUD, class: 'wx-icon-cloud', transform: 'translate(0 -8)' }, root);
            const drops = code === 'heavy-rain' ? [30, 42, 54, 66] : [36, 50, 64];
            drops.forEach((x, i) => node('line', { x1: x, y1: 74 + (i % 2) * 4, x2: x - 5, y2: 88 + (i % 2) * 4, class: 'wx-icon-rain' }, root));
            break;
        }
        case 'snow':
            node('path', { d: CLOUD, class: 'wx-icon-cloud', transform: 'translate(0 -8)' }, root);
            [[36, 80], [50, 88], [64, 80]].forEach(([x, y]) => {
                for (let a = 0; a < 180; a += 60) {
                    const [x0, y0] = polar(x, y, 5, a);
                    const [x1, y1] = polar(x, y, 5, a + 180);
                    node('line', { x1: x0, y1: y0, x2: x1, y2: y1, class: 'wx-icon-snow' }, root);
                }
            });
            break;
        case 'fog':
            node('path', { d: CLOUD, class: 'wx-icon-cloud', transform: 'translate(0 -14)' }, root);
            [70, 80, 90].forEach((y, i) => node('line', { x1: 20 + i * 6, y1: y, x2: 80 - (2 - i) * 6, y2: y, class: 'wx-icon-fog' }, root));
            break;
        default: // noc
            drawMoon(root, 52, 52, 26, isNumber(moonPhase) ? moonPhase : 0.5);
            [[20, 22, 2.2], [82, 18, 1.6], [16, 66, 1.4], [86, 78, 1.8]].forEach(([x, y, s]) => node('circle', { cx: x, cy: y, r: s, class: 'wx-icon-star' }, root));
    }
}

/**
 * Mini-graf (sparkline) časové řady; mezery v datech delší než gapMs přeruší čáru.
 *
 * @param {Array<{time:string}>} items  body z api/observations.php
 * @param {string} key  veličina (např. temp_c)
 */
export function sparkline(container, items, key, { gapMs = 30 * 60 * 1000, color, label } = {}) {
    const width = 300;
    const height = 44;
    const root = svg(container, width, height, label);
    root.setAttribute('preserveAspectRatio', 'none');
    const points = items
        .map((item) => [Date.parse(item.time), item[key]])
        .filter(([t, v]) => Number.isFinite(t) && isNumber(v))
        .map(([t, v]) => [t, Number(v)]);
    if (points.length < 2) {
        return;
    }
    const t0 = points[0][0];
    const t1 = points[points.length - 1][0];
    let lo = Math.min(...points.map((p) => p[1]));
    let hi = Math.max(...points.map((p) => p[1]));
    if (hi - lo < 1e-9) {
        lo -= 1;
        hi += 1;
    }
    const pad = 4;
    const sx = (t) => ((t - t0) / Math.max(1, t1 - t0)) * width;
    const sy = (v) => height - pad - ((v - lo) / (hi - lo)) * (height - 2 * pad);
    const runs = [[]];
    points.forEach((p, i) => {
        if (i > 0 && p[0] - points[i - 1][0] > gapMs) runs.push([]);
        runs[runs.length - 1].push(p);
    });
    for (const run of runs) {
        if (run.length < 2) continue;
        const line = run.map(([t, v], i) => `${i ? 'L' : 'M'}${sx(t).toFixed(1)} ${sy(v).toFixed(1)}`).join('');
        const first = sx(run[0][0]).toFixed(1);
        const last = sx(run[run.length - 1][0]).toFixed(1);
        node('path', { d: `${line}L${last} ${height}L${first} ${height}Z`, class: 'wx-spark-area', fill: color || 'currentColor' }, root);
        node('path', { d: line, class: 'wx-spark-line', stroke: color || 'currentColor', 'vector-effect': 'non-scaling-stroke' }, root);
    }
}

/** Minimum, průměr a maximum veličiny v řadě (null, pokud chybí data). */
export function seriesStats(items, key) {
    const values = items.map((item) => item[key]).filter(isNumber).map(Number);
    if (!values.length) {
        return null;
    }
    return {
        min: Math.min(...values),
        max: Math.max(...values),
        avg: values.reduce((a, b) => a + b, 0) / values.length,
    };
}
