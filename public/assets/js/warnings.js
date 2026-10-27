import { fetchJson, formatTime } from './api.js';

const root = document.getElementById('warnings');
const error = document.getElementById('warnings-error');
const warning = document.getElementById('warnings-warning');
const loading = document.getElementById('warnings-loading');
const summary = document.getElementById('warnings-summary');
const items = document.getElementById('warnings-items');
const other = document.getElementById('warnings-other');
const otherItems = document.getElementById('warnings-other-items');
const button = document.getElementById('warnings-reload');
let busy = false;
const severities = {
    Extreme: 'Extrémní nebezpečí', Severe: 'Vysoký stupeň nebezpečí', Moderate: 'Nízký stupeň nebezpečí',
    Minor: 'Minimální nebo žádné nebezpečí', Unknown: 'Neznámá závažnost',
};
const certainties = {
    Observed: 'Pozorovaný jev', Likely: 'Pravděpodobný jev (> 50 %)', Possible: 'Možný jev (≤ 50 %)',
    Unlikely: 'Nepravděpodobný jev', Unknown: 'Neznámá pravděpodobnost',
};
const urgencies = {
    Immediate: 'Okamžitá reakce', Expected: 'Reakce brzy', Future: 'Reakce později',
    Past: 'Reakce již není potřeba', Unknown: 'Naléhavost neznámá',
};
const states = {
    active: 'Výstraha v platnosti', upcoming: 'Začíná později', expired: 'Platnost nebo výskyt jevu skončil',
    stale: 'Zastaralé vydání – aktuálnost nepotvrzena', non_actual: 'Není skutečnou veřejnou výstrahou',
    unknown_time: 'Neúplná časová platnost', no_warning: 'Zdroj pro tento jev neuvádí výstrahu',
};

function append(parent, tag, text, className = '') {
    const node = document.createElement(tag);
    node.textContent = text;
    if (className) node.className = className;
    parent.append(node);
    return node;
}

function card(item) {
    const { info, message } = item;
    const article = document.createElement('article');
    const severity = Object.hasOwn(severities, info.severity) ? info.severity.toLowerCase() : 'unknown';
    article.className = `card mb-3 warning-severity warning-severity-${severity}`;
    const body = document.createElement('div');
    body.className = 'card-body forecast-text';
    article.append(body);
    append(body, 'h2', info.headline || info.event, 'h5');
    if (info.headline) append(body, 'p', info.event, 'fw-semibold');
    append(body, 'p', `${severities[info.severity]} · ${states[item.state]}`, 'fw-semibold');
    append(body, 'p', `${certainties[info.certainty]} · ${urgencies[info.urgency]}.`, 'small');
    append(body, 'p', `Vydáno: ${formatTime(message.sent)} · stáří ${Math.floor(item.ageSeconds / 3600)} h ${
        Math.floor(item.ageSeconds / 60) % 60} min.`, 'small text-body-secondary');
    append(body, 'p', `Výskyt jevu od: ${info.onset ? formatTime(info.onset) : 'neuvedeno'}. ${
        info.eventEndingTime ? `Předpokládaný konec výskytu: ${formatTime(info.eventEndingTime)}.` : 'Konec výskytu jevu zdroj neuvádí.'}`, 'small');
    append(body, 'p', info.expires ? `Platnost zprávy do: ${formatTime(info.expires)}.` : 'Konec platnosti zdroj neuvádí.', 'small');
    if (info.effective) append(body, 'p', `Účinnost zprávy od: ${formatTime(info.effective)}.`, 'small');
    for (const area of info.areas) {
        if (!area.geocodes.some(code => code.name === 'CISORP' && code.value === '4209')) continue;
        append(body, 'p', `Oblast zdroje: ${area.description} (zahrnuje ORP Most).${
            area.altitude !== null ? ` Dolní výška: ${area.altitude} stop.` : ''}${
            area.ceiling !== null ? ` Horní výška: ${area.ceiling} stop.` : ''}`, 'small');
    }
    if (info.description) append(body, 'p', info.description);
    if (info.instruction) {
        append(body, 'h3', 'Doporučení ČHMÚ', 'h6');
        append(body, 'p', info.instruction);
    }
    const details = append(body, 'details', '');
    append(details, 'summary', 'Identifikace a parametry zdroje');
    append(details, 'p', `CAP: ${message.identifier} · ${message.sender} · ${message.status} / ${message.msgType} / ${
        message.scope}. Kategorie: ${info.categories.join(', ')}. Jazyk: ${info.language}.`, 'small');
    if (message.note) append(details, 'p', message.note, 'small');
    for (const ref of message.references) {
        append(details, 'p', `Reference: ${ref.sender}, ${ref.identifier}, ${formatTime(ref.sent)}.`, 'small');
    }
    for (const pair of [...info.eventCodes, ...info.parameters]) {
        append(details, 'p', `${pair.name}: ${pair.value}`, 'small');
    }
    return article;
}

function render(payload) {
    warning.textContent = payload.meta.warnings.join(' ');
    warning.classList.toggle('d-none', payload.meta.warnings.length === 0);
    const status = payload.summary;
    const texts = {
        warnings: `Pro ORP Most: ${status.active} výstrah v platnosti, ${status.upcoming} nadcházejících.`,
        no_warning_reported: `Aktuálně načtený zdroj pro ORP Most uvádí ${status.noWarningReports} hlášení bez výstrahy. Nejde o garanci nepřítomnosti nebezpečí.`,
        unverified: 'Aktuální stav výstrah pro ORP Most nelze potvrdit. Neznamená to stav bez výstrah; ověřte oficiální web ČHMÚ.',
    };
    summary.textContent = texts[status.state];
    summary.classList.remove('d-none');
    for (const item of payload.items) {
        const hidden = item.noWarning || ['expired', 'non_actual'].includes(item.state);
        (hidden ? otherItems : items).append(card(item));
    }
    for (const notice of payload.notices) {
        append(otherItems, 'p', `CAP zpráva ${notice.msgType}: ${notice.identifier} · ${notice.status} / ${
            notice.scope} · ${formatTime(notice.sent)}. Samotné odvolání nepotvrzuje celkový stav bez výstrah.`, 'small forecast-text');
    }
    other.classList.toggle('d-none', otherItems.children.length === 0);
    const source = payload.meta.source;
    document.getElementById('warnings-source').textContent =
        `Vydání CAP: ${formatTime(payload.meta.latestSent)}. Zdroj načten: ${formatTime(source.fetchedAt)}${
            source.cacheStatus === 'stale' ? ' · stará cache při výpadku ČHMÚ' : ''}.`;
}

async function load() {
    if (busy) return;
    busy = true;
    root.setAttribute('aria-busy', 'true');
    button.disabled = true;
    loading.classList.remove('d-none');
    for (const node of [error, warning, summary, other]) node.classList.add('d-none');
    items.replaceChildren();
    otherItems.replaceChildren();
    document.getElementById('warnings-source').textContent = '';
    try {
        render(await fetchJson(root.dataset.api, 15000));
    } catch (exception) {
        items.replaceChildren();
        otherItems.replaceChildren();
        for (const node of [warning, summary, other]) node.classList.add('d-none');
        error.textContent = exception.message || 'Výstrahy se nepodařilo načíst. Neznamená to stav bez výstrah.';
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
