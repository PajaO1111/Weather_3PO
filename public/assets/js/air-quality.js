/**
 * Bezpečné zobrazení aktuální kvality ovzduší ČHMÚ pro Most.
 */
import { fetchJson, formatNumber, formatTime } from './api.js';

const root = document.getElementById('air-quality');
const ui = {
    error: document.getElementById('air-quality-error'),
    warning: document.getElementById('air-quality-warning'),
    loading: document.getElementById('air-quality-loading'),
    station: document.getElementById('air-quality-station'),
    location: document.getElementById('air-quality-location'),
    index: document.getElementById('air-quality-index'),
    indexTime: document.getElementById('air-quality-index-time'),
    updated: document.getElementById('air-quality-updated'),
};
let state = null;

function setMessage(element, message) {
    element.textContent = message || '';
    element.classList.toggle('d-none', !message);
}

function renderMeasurement(component, measurement) {
    const card = root.querySelector(`[data-measurement="${component}"]`);
    const value = card.querySelector('[data-value]');
    const unit = card.querySelector('[data-unit]');
    const status = card.querySelector('[data-status]');
    const observed = card.querySelector('[data-observed]');
    value.textContent = measurement.value === null ? '–' : formatNumber(measurement.value, 1);
    unit.textContent = measurement.unit ? ` ${measurement.unit}` : '';
    status.textContent = measurement.statusText || 'Stav hodnoty není znám.';
    observed.textContent = measurement.observedAt
        ? `Měření: ${formatTime(measurement.observedAt, 'datetime')}${measurement.stale ? ' · zastaralé' : ''}`
        : '';
    if (measurement.stale && measurement.ageSeconds !== null) {
        const minutes = Math.floor(measurement.ageSeconds / 60);
        observed.textContent += ` · Údaj není aktuální, stáří ${Math.floor(minutes / 60)} h ${minutes % 60} min.`;
    }
    card.classList.toggle('border-warning', measurement.status === 'substituted' || measurement.stale);
    card.classList.toggle('border-danger', measurement.status === 'invalid' || measurement.status === 'unavailable');
}

function render(payload) {
    state = payload;
    const station = payload.station || {};
    ui.station.textContent = `${station.name || 'Most'} · ${station.code || 'UMOM'}`;
    const location = [];
    if (station.region) location.push(station.region);
    if (station.elevationMeters !== null && station.elevationMeters !== undefined) {
        location.push(`${formatNumber(station.elevationMeters, 0)} m n. m.`);
    }
    ui.location.textContent = location.join(' · ');

    for (const component of ['PM10', 'PM2_5', 'NO2', 'SO2', 'O3']) {
        renderMeasurement(component, payload.measurements[component]);
    }

    const index = payload.index || {};
    ui.index.textContent = index.available
        ? `${index.level}: ${index.label}`
        : (index.label || 'Index není dostupný.');
    ui.indexTime.textContent = index.observedAt
        ? `Měření: ${formatTime(index.observedAt, 'datetime')}`
        : '';

    const meta = payload.meta || {};
    const warnings = [...(meta.warnings || [])];
    if (meta.observationStale) {
        warnings.push('Poslední pozorování je starší než dvě hodiny.');
    }
    setMessage(ui.warning, [...new Set(warnings)].join(' '));
    ui.updated.textContent = `Metadata ČHMÚ vytvořena: ${
        meta.sourceCreatedAt ? formatTime(meta.sourceCreatedAt, 'datetime') : 'čas není uveden'
    }. Zdroj načten: ${formatTime(meta.sources.values.fetchedAt, 'datetime')}.`;
}

async function load() {
    root.setAttribute('aria-busy', 'true');
    ui.loading.classList.remove('d-none');
    setMessage(ui.error, '');
    try {
        render(await fetchJson(root.dataset.apiAirQuality, 15000));
    } catch (error) {
        setMessage(ui.error, error.message || 'Data kvality ovzduší se nepodařilo načíst.');
    } finally {
        root.setAttribute('aria-busy', 'false');
        ui.loading.classList.add('d-none');
    }
}

load();
