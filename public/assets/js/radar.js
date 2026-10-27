import { fetchJson, formatTime } from './api.js';

const root = document.getElementById('radar');
const error = document.getElementById('radar-error');
const warning = document.getElementById('radar-warning');
const mapError = document.getElementById('radar-map-error');
const loading = document.getElementById('radar-loading');
const button = document.getElementById('radar-reload');
const product = document.getElementById('radar-product');
const opacity = document.getElementById('radar-opacity');
const time = document.getElementById('radar-time');
const description = document.getElementById('radar-description');
const legend = document.getElementById('radar-legend');
const legendPanel = document.getElementById('radar-legend-panel');
let map;
let overlay;
let busy = false;
let dataBounds;

function mapSetup(payload) {
    if (map) return;
    if (!globalThis.L) throw new Error('Mapovou knihovnu nelze načíst. Zkontrolujte připojení nebo blokování CDN.');
    map = L.map('radar-map', { scrollWheelZoom: false }).setView([50.51039, 13.64525], 8);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).on('tileerror', () => {
        mapError.textContent = 'Část mapového podkladu se nepodařila načíst. Radarová vrstva může být dostupná samostatně; polohu ověřte opatrně.';
        mapError.classList.remove('d-none');
    }).addTo(map);
    map.attributionControl.addAttribution('Radar: ČHMÚ');
    for (const station of payload.stations) {
        const label = document.createElement('span');
        label.textContent = station.name;
        L.circleMarker([station.latitude, station.longitude], {
            radius: 5, color: '#202040', fillColor: '#ffffff', fillOpacity: 1, weight: 2,
        }).bindTooltip(label, { permanent: true, direction: station.name === 'OuBrix' ? 'bottom' : 'top' }).addTo(map);
    }
}

function loadOverlay(payload) {
    return new Promise((resolve, reject) => {
        const layer = L.imageOverlay(payload.image, payload.bounds, { opacity: Number(opacity.value) / 100, alt: payload.product.name });
        const timer = setTimeout(() => {
            layer.off('load');
            layer.off('error');
            layer.remove();
            reject(new Error('Radarový obrázek se nepodařilo zobrazit včas.'));
        }, 10000);
        layer.once('load', () => { clearTimeout(timer); overlay = layer; resolve(); });
        layer.once('error', () => {
            clearTimeout(timer);
            layer.off('load');
            layer.remove();
            reject(new Error('Radarový obrázek se nepodařilo zobrazit. Neznamená to stav bez srážek.'));
        });
        layer.addTo(map);
    });
}

function render(payload) {
    dataBounds = payload.dataBounds;
    const merge = payload.product.id === 'merge';
    const age = Math.floor(payload.frame.ageSeconds / 60);
    time.textContent = `${payload.product.name} · ${payload.meta.stale ? 'ZASTARALÝ SNÍMEK · ' : ''}${
        formatTime(payload.frame.start)} – ${formatTime(payload.frame.end)} · stáří ${Math.floor(age / 60)} h ${age % 60} min`;
    description.textContent = merge
        ? 'MERGE: plošný odhad srážkového úhrnu za uvedenou hodinu, kombinace radaru a srážkoměrů (mm). Nový výpočet po 10 minutách; překrývající se hodinové úhrny se NESČÍTAJÍ.'
        : 'MAX_Z_MASK: maximální radarová odrazivost (dBZ), nikoli naměřený srážkový úhrn. Světlejší barvy označují oblasti, kde dopad na zem nepotvrzuje PseudoCAPPI 2 km (práh 7 dBZ). Krok 5 minut.';
    warning.textContent = payload.meta.warnings.join(' ');
    warning.classList.toggle('d-none', payload.meta.warnings.length === 0);
    legend.alt = merge ? 'Barevná stupnice ČHMÚ: srážkový úhrn v mm' : 'Barevná stupnice ČHMÚ: dBZ a orientační převod mm/h';
    legend.src = payload.legend;
    legendPanel.classList.remove('d-none');
    document.getElementById('radar-legend-note').textContent = merge
        ? 'Jednotka mm za hodinu uvedenou u snímku, nikoli intenzita v jednom okamžiku.'
        : 'Primární veličina je dBZ. Převod v legendě na mm/h je orientační radarový odhad, ne měření srážkoměrem.';
    document.getElementById('radar-source').textContent = `ČHMÚ: ${payload.frame.filename}\nZdroj načten: ${
        formatTime(payload.meta.sources.image.fetchedAt)} · EPSG:3857 · ${payload.frame.width} × ${payload.frame.height} px.`;
}

async function load() {
    if (busy) return;
    busy = true;
    button.disabled = true;
    product.disabled = true;
    root.setAttribute('aria-busy', 'true');
    if (overlay) { overlay.remove(); overlay = null; }
    dataBounds = null;
    for (const node of [error, warning, legendPanel]) node.classList.add('d-none');
    for (const node of [time, description, document.getElementById('radar-source')]) node.textContent = '';
    loading.classList.remove('d-none');
    try {
        const payload = await fetchJson(`${root.dataset.api}?product=${encodeURIComponent(product.value)}`, 30000);
        if (payload.product.id !== product.value || payload.crs !== 'EPSG:3857') throw new Error('Nesouhlasí produkt nebo projekce radarových dat.');
        mapSetup(payload);
        await loadOverlay(payload);
        render(payload);
    } catch (exception) {
        if (overlay) { overlay.remove(); overlay = null; }
        const message = exception.message || 'Radar nelze načíst.';
        error.textContent = `${message}${message.includes('Neznamená to stav bez srážek') ? '' : ' Neznamená to stav bez srážek.'}`;
        error.classList.remove('d-none');
    } finally {
        busy = false;
        button.disabled = false;
        product.disabled = false;
        root.setAttribute('aria-busy', 'false');
        loading.classList.add('d-none');
    }
}
legend.addEventListener('error', () => {
    error.textContent = 'Legendu se nepodařilo zobrazit; barvy nelze bezpečně interpretovat.';
    error.classList.remove('d-none');
});
opacity.addEventListener('input', () => {
    document.getElementById('radar-opacity-value').textContent = `${opacity.value} %`;
    if (overlay) overlay.setOpacity(Number(opacity.value) / 100);
});
document.getElementById('radar-home').addEventListener('click', () => { if (map) map.setView([50.51039, 13.64525], 9); });
document.getElementById('radar-country').addEventListener('click', () => { if (map && dataBounds) map.fitBounds(dataBounds); });
button.addEventListener('click', load);
product.addEventListener('change', load);
load();
