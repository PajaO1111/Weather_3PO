import { fetchJson, formatTime } from './api.js';

const root = document.getElementById('forecast');
const error = document.getElementById('forecast-error');
const warning = document.getElementById('forecast-warning');
const loading = document.getElementById('forecast-loading');
const items = document.getElementById('forecast-items');
const button = document.getElementById('forecast-reload');
let busy = false;
const statusLabels = {
    published: 'Zveřejněná předpověď; konec platnosti neuveden',
    active: 'V uvedeném intervalu platnosti',
    upcoming: 'Platnost začíná později',
    expired: 'Platnost skončila',
    stale: 'Zastaralé vydání',
};

function append(parent, tag, text, className = '') {
    const element = document.createElement(tag);
    element.textContent = text;
    if (className) element.className = className;
    parent.append(element);
    return element;
}

function render(payload) {
    warning.textContent = payload.meta.warnings.join(' ');
    warning.classList.toggle('d-none', payload.meta.warnings.length === 0);
    for (const item of payload.items) {
        const card = document.createElement('article');
        card.className = `card mb-3${['stale', 'expired'].includes(item.status) ? ' border-warning' : ''}`;
        const body = document.createElement('div');
        body.className = 'card-body';
        card.append(body);
        append(body, 'h2', item.mainHeadline || 'Předpověď ČHMÚ', 'h5');
        append(body, 'p', statusLabels[item.status], 'fw-semibold');
        const ageMinutes = Math.floor(item.ageSeconds / 60);
        append(body, 'p',
            `Vydáno: ${formatTime(item.sent)} · stáří ${Math.floor(ageMinutes / 60)} h ${ageMinutes % 60} min. Platnost od: ${formatTime(item.startTime)}. ${
                item.endTime ? `Platnost do: ${formatTime(item.endTime)}.` : 'Konec platnosti zdroj neuvádí.'}`,
            'small text-body-secondary');
        for (const section of item.sections) {
            if (section.headline) append(body, 'h3', section.headline, 'h6');
            append(body, 'p', section.text, 'forecast-text');
        }
        items.append(card);
    }
    const source = payload.meta.source;
    document.getElementById('forecast-source').textContent =
        `Zdroj načten: ${formatTime(source.fetchedAt)}${source.cacheStatus === 'stale' ? ' · stará cache při výpadku ČHMÚ' : ''}.`;
}

async function load() {
    if (busy) return;
    busy = true;
    root.setAttribute('aria-busy', 'true');
    button.disabled = true;
    loading.classList.remove('d-none');
    error.classList.add('d-none');
    warning.classList.add('d-none');
    items.replaceChildren();
    document.getElementById('forecast-source').textContent = '';
    try {
        render(await fetchJson(root.dataset.api, 15000));
    } catch (exception) {
        error.textContent = exception.message || 'Předpověď se nepodařilo načíst.';
        error.classList.remove('d-none');
    } finally {
        busy = false;
        button.disabled = false;
        root.setAttribute('aria-busy', 'false');
        loading.classList.add('d-none');
    }
}
button.addEventListener('click', load);
load();
