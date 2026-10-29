/**
 * Kalendářní týdny podle ISO 8601 (pondělí–neděle, týden 1 obsahuje 4. ledna).
 *
 * Pracuje jen s místními daty „RRRR-MM-DD“ (bez času), proto ji neovlivní
 * změna letního času. ISO rok se na přelomu roku může lišit od kalendářního
 * (např. 2027-01-01 patří do týdne 2026-W53).
 */

function toUtc(isoDate) {
    const [y, m, d] = isoDate.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d));
}

function fromUtc(date) {
    return date.toISOString().slice(0, 10);
}

/** Posun data o N dní. */
export function shiftDate(isoDate, days) {
    const date = toUtc(isoDate);
    date.setUTCDate(date.getUTCDate() + days);
    return fromUtc(date);
}

/** ISO rok a číslo týdne pro místní datum. */
export function isoWeekOf(isoDate) {
    const date = toUtc(isoDate);
    const dow = (date.getUTCDay() + 6) % 7; // po = 0 … ne = 6
    date.setUTCDate(date.getUTCDate() - dow + 3); // čtvrtek téhož týdne určuje ISO rok
    const year = date.getUTCFullYear();
    const week = 1 + Math.floor((date - Date.UTC(year, 0, 1)) / 86400000 / 7);
    return { year, week };
}

/** Počet ISO týdnů v roce (52 nebo 53). */
export function weeksInYear(year) {
    return isoWeekOf(`${year}-12-28`).week;
}

/** Pondělí daného ISO týdne. */
export function weekStart(year, week) {
    const jan4 = new Date(Date.UTC(year, 0, 4));
    jan4.setUTCDate(jan4.getUTCDate() - ((jan4.getUTCDay() + 6) % 7) + (week - 1) * 7);
    return fromUtc(jan4);
}

/** Klíč týdne „RRRR-Www“ (např. 2026-W05). */
export function weekKey(year, week) {
    return `${year}-W${String(week).padStart(2, '0')}`;
}

/**
 * Rozbor klíče „RRRR-Www“; vrátí null pro neplatný týden.
 * @returns {{year:number, week:number, key:string, days:string[]}|null}
 */
export function parseWeek(key) {
    const match = /^(\d{4})-W(\d{2})$/.exec(String(key || ''));
    if (!match) {
        return null;
    }
    const year = Number(match[1]);
    const week = Number(match[2]);
    if (year < 2000 || week < 1 || week > weeksInYear(year)) {
        return null;
    }
    const monday = weekStart(year, week);
    return { year, week, key: weekKey(year, week), days: Array.from({ length: 7 }, (_, i) => shiftDate(monday, i)) };
}

/**
 * Seznam týdnů od týdne s dneškem zpět po 1. týden ISO roku firstYear (nejnovější první).
 * @returns {{year:number, week:number, key:string, days:string[]}[]}
 */
export function weekList(today, firstYear) {
    const result = [];
    let { year, week } = isoWeekOf(today);
    while (year > firstYear || (year === firstYear && week >= 1)) {
        result.push(parseWeek(weekKey(year, week)));
        week -= 1;
        if (week < 1) {
            year -= 1;
            week = weeksInYear(year);
        }
    }
    return result;
}
