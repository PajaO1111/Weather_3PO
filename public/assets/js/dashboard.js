/**
 * Řízení stránky „Aktuálně“.
 *
 * 1. načte aktuální stav z api/current.php a vyplní prvky s atributem data-field,
 * 2. načte řadu za 24 h z api/observations.php – graf, mini-grafy a značky
 *    min / ø / max na měřidlech,
 * 3. vykreslí měřidla (gauges.js), ikonu stavu, dráhu Slunce a fázi Měsíce,
 * 4. každých 5 minut (a po návratu na kartu prohlížeče) data obnoví.
 *
 * Formát prvku: <span data-field="current.temp_c" data-format="1">
 *   data-format = počet desetinných míst | "datetime" | "time" | "date" | (bez = text)
 */
import { fetchJson, fillFields, formatNumber, formatAge } from './api.js';
import { createSeriesChart } from './charts.js';
import {
    arcGauge, compass, rainTube, uvBar, uvColor, sunPath, moonIcon, conditionIcon, sparkline, seriesStats,
} from './gauges.js';

const REFRESH_MS = 5 * 60 * 1000;

/** Rozsahy stupnic měřidel. */
const SCALES = {
    temp: { min: -20, max: 40 },
    humidity: { min: 0, max: 100 },
    pressure: { min: 960, max: 1050 },
    solar: { min: 0, max: 1000 },
    windMax: 60,
    rainMax: 20,
};

const root = document.getElementById('dashboard');
const hero = document.getElementById('hero');
const errorBox = document.getElementById('error');
const freshness = document.getElementById('freshness');
const refreshButton = document.getElementById('refresh');
const chartNote = document.getElementById('chart-note');
const gapMs = Number(root.dataset.chartGapMinutes) * 60 * 1000;
const chart = createSeriesChart(document.getElementById('chart'), { gapMs });
const $ = (id) => document.getElementById(id);

let currentMetric = 'temperature';
let seriesItems = [];
let lastMeta = null;
let lastCurrent = null;
let lastLoad = 0;

/** Zobrazí nebo skryje chybovou hlášku (data zůstávají z posledního úspěšného načtení). */
function showError(message) {
    errorBox.textContent = message || '';
    errorBox.classList.toggle('d-none', !message);
}

/** Indikátor aktuálnosti dat podle stáří posledního měření. */
function updateFreshness(observed) {
    let state = 'ok';
    let label = `online · ${formatAge(observed.ageSeconds)}`;
    if (observed.futureTimestamp) {
        state = 'warn';
        label = 'čas měření je v budoucnosti';
    } else if (observed.stale) {
        state = 'warn';
        label = `neaktuální · poslední data ${formatAge(observed.ageSeconds)}`;
    }
    freshness.className = `wx-live wx-live-${state}`;
    freshness.textContent = label;
}

/** Tendence tlaku textem a šipkou. */
function updatePressureTrend(trend) {
    const text = $('pressure-trend');
    const arrow = $('pressure-arrow');
    if (!trend) {
        text.textContent = 'nedostupná';
        arrow.textContent = '–';
        return;
    }
    const sign = trend.change > 0 ? '+' : '';
    text.textContent = `${sign}${formatNumber(trend.change, 1)} hPa / ${trend.hours} h`;
    const symbol = trend.change >= 0.5 ? '↗' : trend.change <= -0.5 ? '↘' : '→';
    arrow.textContent = `${symbol} ${trend.label}`;
}

/** Značky 24h minima, průměru a maxima pro měřidlo. */
function marks(key) {
    const stats = seriesStats(seriesItems, key);
    if (!stats) {
        return [];
    }
    return [
        { value: stats.min, label: 'min' },
        { value: stats.avg, label: 'ø' },
        { value: stats.max, label: 'max' },
    ];
}

/** Popis měřidla pro čtečky obrazovky. */
function describe(name, value, unit, key, decimals = 1) {
    let text = `${name} ${formatNumber(value, decimals)} ${unit}`;
    const stats = key ? seriesStats(seriesItems, key) : null;
    if (stats) {
        text += `, za 24 h min ${formatNumber(stats.min, decimals)}, průměr ${formatNumber(stats.avg, decimals)}, max ${formatNumber(stats.max, decimals)}`;
    }
    return text;
}

function formatDuration(minutes) {
    if (minutes === null || minutes === undefined) {
        return '–';
    }
    return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

/** Ikona a pozadí hlavičky podle odhadu stavu počasí a dne/noci. */
function renderHero(data) {
    const condition = data.condition || { code: 'day', label: '–' };
    const isDay = data.astro && data.astro.sun ? data.astro.sun.isDay : condition.code !== 'night';
    hero.dataset.condition = condition.code;
    hero.dataset.daytime = isDay ? 'day' : 'night';
    $('condition-label').textContent = condition.label;
    conditionIcon($('condition-icon'), {
        code: condition.code,
        moonPhase: data.astro && data.astro.moon ? data.astro.moon.phase : null,
        label: `Stav počasí: ${condition.label} (odhad)`,
    });
}

/** Dráha Slunce (překresluje se i průběžně), Měsíc a délka dne. */
function renderSky(data) {
    const sun = data.astro ? data.astro.sun : null;
    const moon = data.astro ? data.astro.moon : null;
    if (sun) {
        sunPath($('sun-path'), {
            sunrise: sun.sunrise,
            sunset: sun.sunset,
            label: 'Dráha Slunce mezi východem a západem',
        });
        $('day-length').textContent = formatDuration(sun.dayLengthMinutes);
    }
    if (moon) {
        moonIcon($('moon-icon'), { phase: moon.phase, label: `Měsíc: ${moon.name}, osvětleno ${moon.illumination} %` });
        $('moon-trend').textContent = moon.waxing ? 'dorůstá' : 'couvá';
    }
}

/** Všechna měřidla a mini-grafy; volá se po načtení aktuálních dat i řady. */
function renderGauges() {
    if (!lastCurrent) {
        return;
    }
    const c = lastCurrent.current;
    const today = lastCurrent.today || {};

    arcGauge($('gauge-temp'), {
        ...SCALES.temp, value: c.temp_c, marks: marks('temp_c'), endLabels: ['−20', '40'],
        label: describe('Teplota', c.temp_c, '°C', 'temp_c'),
    });
    arcGauge($('gauge-humidity'), {
        ...SCALES.humidity, value: c.humidity, marks: marks('humidity'), start: -180, end: 180,
        label: describe('Vlhkost', c.humidity, '%', 'humidity', 0),
    });
    compass($('gauge-wind'), {
        direction: c.winddir, speed: c.wind_speed, maxSpeed: SCALES.windMax,
        label: `Vítr ${formatNumber(c.wind_speed, 1)} km/h ${c.wind_direction ? `ze směru ${c.wind_direction}` : ''}`,
    });
    arcGauge($('gauge-pressure'), {
        ...SCALES.pressure, value: c.pressure, marks: marks('pressure'), needle: true, endLabels: ['960', '1050'],
        label: describe('Tlak', c.pressure, 'hPa', 'pressure'),
    });
    rainTube($('gauge-rain'), {
        total: today.rainCounter, max: SCALES.rainMax,
        label: `Srážky dnes ${formatNumber(today.rainCounter, 1)} mm`,
    });
    uvBar($('gauge-uv'), { value: c.uv, todayMax: today.uv_max, label: `UV index ${formatNumber(c.uv, 1)}` });
    const uvBadge = $('uv-badge');
    uvBadge.style.background = c.uv === null || c.uv === undefined ? '' : uvColor(Number(c.uv));
    uvBadge.style.color = c.uv !== null && c.uv >= 3 && c.uv < 6 ? '#212529' : '#fff';
    const solarMax = Math.max(SCALES.solar.max, Math.ceil((Number(today.solar_max) || 0) / 100) * 100);
    arcGauge($('gauge-solar'), {
        min: 0, max: solarMax, value: c.solar_radiation, marks: marks('solar_radiation'), endLabels: ['0', String(solarMax)],
        label: describe('Sluneční záření', c.solar_radiation, 'W/m²', 'solar_radiation', 0),
    });

    const sparks = [
        ['spark-temp', 'temp_c', 'Teplota'],
        ['spark-humidity', 'humidity', 'Vlhkost'],
        ['spark-wind', 'wind_speed', 'Rychlost větru'],
        ['spark-pressure', 'pressure', 'Tlak'],
        ['spark-rain', 'precip_total', 'Denní úhrn srážek'],
        ['spark-uv', 'uv', 'UV index'],
        ['spark-solar', 'solar_radiation', 'Sluneční záření'],
    ];
    for (const [id, key, name] of sparks) {
        sparkline($(id), seriesItems, key, { gapMs, label: `${name} – průběh za 24 hodin` });
    }
}

/** Popisek pod grafem (počet bodů, typický interval, upozornění k srážkám). */
function updateChartNote(meta) {
    const parts = [`${meta.points} měření`];
    if (meta.medianIntervalMinutes) {
        parts.push(`typicky po ${formatNumber(meta.medianIntervalMinutes, 0)} min`);
    }
    if (meta.duplicates) {
        parts.push(`${meta.duplicates} duplicit vynecháno`);
    }
    if (meta.truncated) {
        parts.push('zobrazena jen nejnovější část okna');
    }
    if (currentMetric === 'rain') {
        parts.push('úhrn je denní čítač stanice (nuluje se o půlnoci), ne ověřený srážkoměrný úhrn');
    }
    chartNote.textContent = parts.join(' · ');
}

function renderChart() {
    try {
        chart.show(seriesItems, currentMetric, lastMeta);
        if (lastMeta) {
            updateChartNote(lastMeta);
        }
    } catch (error) {
        chartNote.textContent = error.message;
    }
}

/** Načte aktuální stav i řadu; chyba jedné části neblokuje druhou. */
async function load() {
    lastLoad = Date.now();
    refreshButton.disabled = true;
    const [current, series] = await Promise.allSettled([
        fetchJson(root.dataset.apiCurrent),
        fetchJson(root.dataset.apiSeries),
    ]);
    refreshButton.disabled = false;

    const errors = [];
    if (series.status === 'fulfilled') {
        seriesItems = series.value.items;
        lastMeta = series.value.meta;
        renderChart();
    } else {
        errors.push(`Graf: ${series.reason.message}`);
        console.warn('api/observations', series.reason.status, series.reason.details);
    }
    if (current.status === 'fulfilled') {
        lastCurrent = current.value;
        fillFields(root, lastCurrent);
        updateFreshness(lastCurrent.observed);
        updatePressureTrend(lastCurrent.pressureTrend);
        renderHero(lastCurrent);
        renderSky(lastCurrent);
    } else {
        errors.push(current.reason.message);
        freshness.className = 'wx-live wx-live-error';
        freshness.textContent = 'data nedostupná';
        console.warn('api/current', current.reason.status, current.reason.details);
    }
    try {
        renderGauges();
    } catch (error) {
        console.error('měřidla', error);
    }
    showError([...new Set(errors)].join(' '));
}

// Přepínání veličiny v grafu.
$('chart-metrics').addEventListener('click', (event) => {
    const button = event.target.closest('[data-metric]');
    if (!button) {
        return;
    }
    currentMetric = button.dataset.metric;
    for (const other of button.parentElement.querySelectorAll('[data-metric]')) {
        const active = other === button;
        other.classList.toggle('active', active);
        other.setAttribute('aria-pressed', String(active));
    }
    renderChart();
});

refreshButton.addEventListener('click', load);

// Automatické obnovení; na skryté kartě se nenačítá zbytečně.
setInterval(() => {
    if (!document.hidden) {
        load();
    }
}, REFRESH_MS);
// Poloha Slunce se posouvá i mezi načteními dat.
setInterval(() => {
    if (!document.hidden && lastCurrent) {
        renderSky(lastCurrent);
    }
}, 60 * 1000);
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && Date.now() - lastLoad > REFRESH_MS) {
        load();
    }
});

load();
