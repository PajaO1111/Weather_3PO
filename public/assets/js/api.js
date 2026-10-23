/**
 * Společné pomocné funkce: volání JSON API a české formátování hodnot.
 * ES modul – importuje ho dashboard.js (a budoucí stránky).
 */

/** Časové pásmo zobrazení (shodné s config/app.php). */
export const TIMEZONE = 'Europe/Prague';

/**
 * Chyba API s HTTP kódem a případnou bezpečnou diagnostikou ze serveru.
 */
export class ApiError extends Error {
    constructor(message, status, details) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.details = details || null;
    }
}

/**
 * Načte JSON z vlastního API.
 * Chybové odpovědi ({"error": {...}}) převede na ApiError.
 *
 * @param {string} url
 * @param {number} [timeoutMs=15000]
 * @returns {Promise<any>}
 */
export async function fetchJson(url, timeoutMs = 15000) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    let response;
    try {
        response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
    } catch (error) {
        throw new ApiError(error.name === 'AbortError' ? 'Server neodpověděl včas.' : 'Nelze se spojit se serverem.', 0);
    } finally {
        clearTimeout(timer);
    }
    let body = null;
    try {
        body = await response.json();
    } catch (error) {
        throw new ApiError(`Neplatná odpověď serveru (HTTP ${response.status}).`, response.status);
    }
    if (!response.ok || (body && body.error)) {
        const err = (body && body.error) || {};
        throw new ApiError(err.message || `Chyba serveru (HTTP ${response.status}).`, response.status, err.diagnostics);
    }
    return body;
}

/**
 * Vrátí hodnotu z objektu podle cesty „a.b.c“ (undefined, pokud neexistuje).
 */
export function pick(object, path) {
    return path.split('.').reduce((value, key) => (value == null ? undefined : value[key]), object);
}

const numberFormats = new Map();

/**
 * Číslo v české notaci s daným počtem desetinných míst; null → „–“.
 */
export function formatNumber(value, decimals = 1) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '–';
    }
    if (!numberFormats.has(decimals)) {
        numberFormats.set(decimals, new Intl.NumberFormat('cs-CZ', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }));
    }
    return numberFormats.get(decimals).format(Number(value));
}

const dateTimeFormat = new Intl.DateTimeFormat('cs-CZ', {
    timeZone: TIMEZONE, day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit',
});
const timeFormat = new Intl.DateTimeFormat('cs-CZ', { timeZone: TIMEZONE, hour: '2-digit', minute: '2-digit' });
const dateFormat = new Intl.DateTimeFormat('cs-CZ', { timeZone: TIMEZONE, day: 'numeric', month: 'numeric', year: 'numeric' });
const dayMonthFormat = new Intl.DateTimeFormat('cs-CZ', { timeZone: TIMEZONE, day: 'numeric', month: 'numeric' });

/**
 * Formátuje ISO čas: kind = 'datetime' | 'time' | 'date' | 'daymonth' (např. „23. 10.“).
 * Datum bez času („2026-10-22“) se bere jako místní den.
 */
export function formatTime(iso, kind = 'datetime') {
    if (!iso) {
        return '';
    }
    if ((kind === 'date' || kind === 'daymonth') && /^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        const [y, m, d] = iso.split('-').map(Number);
        return kind === 'date' ? `${d}. ${m}. ${y}` : `${d}. ${m}.`;
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    if (kind === 'time') {
        return timeFormat.format(date);
    }
    if (kind === 'daymonth') {
        return dayMonthFormat.format(date);
    }
    return kind === 'date' ? dateFormat.format(date) : dateTimeFormat.format(date);
}

/**
 * Vyplní prvky s atributem data-field uvnitř root hodnotami z data.
 *
 * <span data-field="current.temp_c" data-format="1">
 *   data-format = počet desetinných míst | "datetime" | "time" | "date" | (bez = text)
 * Chybějící hodnota se zobrazí jako „–“ (u časů prázdně).
 */
export function fillFields(root, data) {
    for (const element of root.querySelectorAll('[data-field]')) {
        const value = pick(data, element.dataset.field);
        const format = element.dataset.format;
        let text;
        if (format === 'datetime' || format === 'time' || format === 'date') {
            text = formatTime(value, format);
            if (element.tagName === 'TIME') {
                if (value) {
                    element.setAttribute('datetime', value);
                } else {
                    element.removeAttribute('datetime');
                }
            }
        } else if (format !== undefined) {
            text = formatNumber(value, Number(format));
        } else {
            text = value === null || value === undefined || value === '' ? '–' : String(value);
        }
        element.textContent = text;
    }
}

/**
 * Stáří dat slovně, např. „před 4 min“, „před 2 h 5 min“.
 */
export function formatAge(seconds) {
    if (seconds === null || seconds === undefined) {
        return '–';
    }
    if (seconds < 60) {
        return 'právě teď';
    }
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) {
        return `před ${minutes} min`;
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 48) {
        return `před ${hours} h ${minutes % 60} min`;
    }
    return `před ${Math.floor(hours / 24)} dny`;
}
