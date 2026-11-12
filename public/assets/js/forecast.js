import { fetchJson, formatNumber, formatTime, TIMEZONE } from './api.js';

const root = document.getElementById('forecast');
const error = document.getElementById('forecast-error');
const warning = document.getElementById('forecast-warning');
const loading = document.getElementById('forecast-loading');
const items = document.getElementById('forecast-items');
const button = document.getElementById('forecast-reload');
let busy = false;
const iconBase = 'https://www.chmi.cz/o/chmu-theme/images/icon/';
const directionLabels = ['S', 'SSV', 'SV', 'VSV', 'V', 'VJV', 'JV', 'JJV',
    'J', 'JJZ', 'JZ', 'ZJZ', 'Z', 'ZSZ', 'SZ', 'SSZ'];
const localDateFormatter = new Intl.DateTimeFormat('en-GB', {
    timeZone: TIMEZONE, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', hourCycle: 'h23',
});
const dayFormatter = new Intl.DateTimeFormat('cs-CZ', {
    timeZone: TIMEZONE, weekday: 'long', day: 'numeric', month: 'long',
});

function append(parent, tag, text, className = '') {
    const element = document.createElement(tag);
    element.textContent = text;
    if (className) element.className = className;
    parent.append(element);
    return element;
}

function localDateAndHour(isoTime) {
    const parts = Object.fromEntries(localDateFormatter.formatToParts(new Date(isoTime))
        .map(part => [part.type, part.value]));
    return { date: `${parts.year}-${parts.month}-${parts.day}`, hour: Number(parts.hour) };
}

function numericValues(rows, key) {
    return rows.map(row => row[key]).filter(Number.isFinite);
}

function minMax(rows, key) {
    const values = numericValues(rows, key);
    return values.length ? [Math.min(...values), Math.max(...values)] : null;
}

function sum(rows, key) {
    const values = numericValues(rows, key);
    return values.length ? values.reduce((total, value) => total + value, 0) : null;
}

function average(rows, key) {
    const values = numericValues(rows, key);
    return values.length ? values.reduce((total, value) => total + value, 0) / values.length : null;
}

export function summarizeDays(hours, now = Date.now()) {
    const relevant = hours.filter(hour => new Date(hour.time).getTime() >= now - 60 * 60 * 1000);
    const days = new Map();
    for (const hour of relevant) {
        const { date, hour: localHour } = localDateAndHour(hour.time);
        if (!days.has(date)) days.set(date, { date, hours: [], firstHour: localHour, lastHour: localHour });
        const day = days.get(date);
        day.hours.push(hour);
        day.firstHour = Math.min(day.firstHour, localHour);
        day.lastHour = Math.max(day.lastHour, localHour);
    }

    return [...days.values()].map(day => {
        const iconHour = day.hours.reduce((best, hour) =>
            Math.abs(localDateAndHour(hour.time).hour - 12)
                < Math.abs(localDateAndHour(best.time).hour - 12) ? hour : best);
        const strongestWind = day.hours.reduce((best, hour) =>
            (hour.windSpeedMs ?? -1) > (best.windSpeedMs ?? -1) ? hour : best, day.hours[0]);
        const temperatures = minMax(day.hours, 'temperatureC');
        const pressures = minMax(day.hours, 'pressureHpa');
        return {
            ...day,
            label: dayFormatter.format(new Date(`${day.date}T12:00:00Z`)),
            partial: day.firstHour > 0 || day.lastHour < 23,
            icon: iconHour.icon,
            temperatureMin: temperatures?.[0] ?? null,
            temperatureMax: temperatures?.[1] ?? null,
            precipitationMm: sum(day.hours, 'precipitationMm'),
            snowMm: sum(day.hours, 'snowMm'),
            windSpeedMs: strongestWind.windSpeedMs,
            windGustMs: maxValue(day.hours, 'windGustMs'),
            windDirectionDeg: strongestWind.windDirectionDeg,
            pressureMin: pressures?.[0] ?? null,
            pressureMax: pressures?.[1] ?? null,
            cloudCoverPct: average(day.hours, 'cloudCoverPct'),
        };
    });
}

function maxValue(rows, key) {
    const values = numericValues(rows, key);
    return values.length ? Math.max(...values) : null;
}

function temperatureText(day) {
    if (day.temperatureMin === null || day.temperatureMax === null) return 'Teplota není k dispozici';
    return `${formatNumber(day.temperatureMin)} až ${formatNumber(day.temperatureMax)} °C`;
}

function rangeText(minimum, maximum, unit, decimals = 1) {
    if (minimum === null || maximum === null) return '–';
    return `${formatNumber(minimum, decimals)} až ${formatNumber(maximum, decimals)} ${unit}`;
}

function metric(parent, label, value) {
    const row = append(parent, 'div', '', 'forecast-day-metric');
    append(row, 'dt', label);
    append(row, 'dd', value, 'mb-0 text-end');
}

function renderDay(day) {
    const column = document.createElement('div');
    column.className = 'col';
    const card = document.createElement('article');
    card.className = 'card forecast-day-card h-100';
    column.append(card);
    const body = append(card, 'div', '', 'card-body');
    const header = append(body, 'div', '', 'd-flex align-items-center gap-3 mb-3');
    if (Number.isInteger(day.icon) && day.icon >= 0 && day.icon <= 999) {
        const icon = document.createElement('img');
        icon.src = `${iconBase}${day.icon}.svg`;
        icon.alt = 'Symbol předpovědi ČHMÚ';
        icon.width = 56;
        icon.height = 56;
        icon.loading = 'lazy';
        icon.decoding = 'async';
        header.append(icon);
    }
    const title = append(header, 'div', '', 'flex-grow-1');
    append(title, 'h2', day.label, 'h5 mb-1 text-capitalize');
    append(title, 'p', `Teplota ${temperatureText(day)}`, 'forecast-day-temperature mb-0');
    if (day.partial) append(body, 'p', 'Částečný výhled', 'badge text-bg-secondary mb-3');

    const metrics = append(body, 'dl', '', 'forecast-day-metrics mb-0');
    metric(metrics, 'Srážky', day.precipitationMm === null ? '–' : `${formatNumber(day.precipitationMm)} mm`);
    metric(metrics, 'Sníh · vodní hodnota', day.snowMm === null ? '–' : `${formatNumber(day.snowMm)} mm`);
    const wind = day.windSpeedMs === null ? '–' : `${formatNumber(day.windSpeedMs)} m/s`;
    const direction = Number.isFinite(day.windDirectionDeg)
        ? ` ${directionLabels[Math.round(day.windDirectionDeg / 22.5) % 16]}` : '';
    metric(metrics, 'Vítr · maximum', `${wind}${direction}`);
    metric(metrics, 'Nárazy', day.windGustMs === null ? '–' : `${formatNumber(day.windGustMs)} m/s`);
    metric(metrics, 'Tlak', rangeText(day.pressureMin, day.pressureMax, 'hPa'));
    metric(metrics, 'Oblačnost · průměr', day.cloudCoverPct === null ? '–' : `${formatNumber(day.cloudCoverPct, 0)} %`);
    const hoursLabel = day.hours.length === 1 ? 'hodinová hodnota'
        : (day.hours.length >= 2 && day.hours.length <= 4 ? 'hodinové hodnoty' : 'hodinových hodnot');
    append(body, 'p', `${day.hours.length} ${hoursLabel}`, 'small text-body-secondary mt-3 mb-0');
    items.append(column);
}

function render(payload) {
    warning.textContent = (payload.meta.warnings || []).join(' ');
    warning.classList.toggle('d-none', !payload.meta.warnings?.length);
    for (const day of summarizeDays(payload.hours)) renderDay(day);

    const source = payload.meta.source;
    document.getElementById('forecast-source').textContent =
        `Model ALADIN · bod ČHMÚ ${payload.location.pointId}, ${payload.location.pointElevationM} m n. m. · `
        + `data načtena ${formatTime(source.fetchedAt)}`
        + (source.cacheStatus === 'stale' ? ' · stará cache při výpadku ČHMÚ' : '') + '.';
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
