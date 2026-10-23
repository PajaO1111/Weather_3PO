<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Support\Astro;

return static function (): int {
    $tz = new DateTimeZone('Europe/Prague');
    $lat = 50.5069;
    $lon = 13.6431;

    // Most, 23. 10. 2026 (ještě letní čas): východ ~7:40, kulminace ~12:50, západ ~18:00.
    $day = new DateTimeImmutable('2026-10-23 00:00:00', $tz);
    $sun = Astro::sun($day, new DateTimeImmutable('2026-10-23 12:50:00', $tz), $lat, $lon, $tz);
    check(substr((string) $sun['sunrise'], 0, 16) >= '2026-10-23T07:35' && substr((string) $sun['sunrise'], 0, 16) <= '2026-10-23T07:45', 'sunrise');
    check(substr((string) $sun['sunset'], 0, 16) >= '2026-10-23T17:55' && substr((string) $sun['sunset'], 0, 16) <= '2026-10-23T18:05', 'sunset');
    check(substr((string) $sun['sunset'], -6) === '+02:00' && $sun['polar'] === null, 'local offset');
    check($sun['dayLengthMinutes'] > 610 && $sun['dayLengthMinutes'] < 630, 'day length');
    check($sun['isDay'] === true && $sun['elevation'] > 27 && $sun['elevation'] < 29, 'noon elevation (90 - 50.5 - 11.5)');
    $night = Astro::sun($day, new DateTimeImmutable('2026-10-23 23:00:00', $tz), $lat, $lon, $tz);
    check($night['isDay'] === false && $night['elevation'] < -20, 'night');

    // Letní slunovrat: výška v poledne ≈ 90 − 50,5 + 23,44 = 62,9°
    $summer = new DateTimeImmutable('2026-06-21 13:15:00', $tz);
    check(abs(Astro::solarElevation($summer->getTimestamp(), $lat, $lon) - 62.9) < 0.5, 'solstice elevation');
    check(Astro::clearSkyRadiation(-5.0) === 0.0 && Astro::clearSkyRadiation(60.0) > 850 && Astro::clearSkyRadiation(60.0) < 950, 'clear sky');

    // Fáze Měsíce: nov 10. 10. 2026, úplněk 26. 10. 2026
    $new = Astro::moon(new DateTimeImmutable('2026-10-10 17:50:00', $tz), $tz);
    check($new['name'] === 'nov' && $new['illumination'] <= 2, 'new moon');
    $full = Astro::moon(new DateTimeImmutable('2026-10-26 05:12:00', $tz), $tz);
    check($full['name'] === 'úplněk' && $full['illumination'] >= 98, 'full moon');
    $waxing = Astro::moon(new DateTimeImmutable('2026-10-23 20:00:00', $tz), $tz);
    check($waxing['waxing'] === true && $waxing['name'] === 'dorůstající Měsíc' && $waxing['nextFull'] === '2026-10-26' && $waxing['nextNew'] === '2026-11-09', 'waxing gibbous');
    check(Astro::moonName(0.1) === 'dorůstající srpek' && Astro::moonName(0.25) === 'první čtvrť' && Astro::moonName(0.9) === 'couvající srpek', 'moon names');

    return 12;
};
