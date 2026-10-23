<?php
/**
 * Astronomické údaje pro stránku „Aktuálně“: východ a západ Slunce, výška
 * Slunce nad obzorem, teoretické záření za jasné oblohy a fáze Měsíce.
 *
 * Čisté výpočty bez externího API. Přesnost (minuty u Slunce, hodiny u fází
 * Měsíce) pro zobrazení na webu stačí.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Support;

use DateTimeImmutable;
use DateTimeZone;

final class Astro
{
    /** Délka synodického měsíce ve dnech. */
    public const SYNODIC_MONTH = 29.530588853;

    /** Referenční nov 6. 1. 2000 18:14 UTC (Unix timestamp). */
    private const NEW_MOON_REFERENCE = 947182440;

    /** Výška středu Slunce při východu/západu (refrakce + poloměr disku). */
    private const SUNRISE_ELEVATION = -0.833;

    /**
     * Východ, kulminace a západ Slunce pro místní den a výška Slunce v čase $at.
     *
     * @return array<string, mixed>
     */
    public static function sun(DateTimeImmutable $day, DateTimeImmutable $at, float $lat, float $lon, DateTimeZone $timezone): array
    {
        $noon = $day->setTimezone($timezone)->setTime(12, 0, 0);
        $info = date_sun_info($noon->getTimestamp(), $lat, $lon);
        $polar = null;
        if ($info['sunrise'] === true || $info['sunset'] === true) {
            $polar = 'day';
        } elseif ($info['sunrise'] === false || $info['sunset'] === false) {
            $polar = 'night';
        }
        $time = static function ($value) use ($timezone): ?string {
            return is_int($value) ? (new DateTimeImmutable('@' . $value))->setTimezone($timezone)->format('Y-m-d\TH:i:sP') : null;
        };
        $dayLength = null;
        if (is_int($info['sunrise']) && is_int($info['sunset'])) {
            $dayLength = (int) round(($info['sunset'] - $info['sunrise']) / 60);
        } elseif ($polar !== null) {
            $dayLength = $polar === 'day' ? 1440 : 0;
        }
        $elevation = self::solarElevation($at->getTimestamp(), $lat, $lon);
        return [
            'sunrise' => $time($info['sunrise']),
            'transit' => $time($info['transit']),
            'sunset' => $time($info['sunset']),
            'dayLengthMinutes' => $dayLength,
            'polar' => $polar,
            'elevation' => round($elevation, 1),
            'isDay' => $elevation > self::SUNRISE_ELEVATION,
        ];
    }

    /**
     * Výška Slunce nad obzorem ve stupních (zjednodušený algoritmus NOAA/Astronomical Almanac).
     */
    public static function solarElevation(int $timestamp, float $lat, float $lon): float
    {
        $n = $timestamp / 86400 + 2440587.5 - 2451545.0;
        $meanLongitude = fmod(280.460 + 0.9856474 * $n, 360.0);
        $anomaly = deg2rad(fmod(357.528 + 0.9856003 * $n, 360.0));
        $eclipticLongitude = deg2rad($meanLongitude + 1.915 * sin($anomaly) + 0.020 * sin(2 * $anomaly));
        $obliquity = deg2rad(23.439 - 0.0000004 * $n);
        $declination = asin(sin($obliquity) * sin($eclipticLongitude));
        $rightAscension = atan2(cos($obliquity) * sin($eclipticLongitude), cos($eclipticLongitude));
        $siderealHours = fmod(18.697374558 + 24.06570982441908 * $n, 24.0);
        $hourAngle = deg2rad($siderealHours * 15.0 + $lon) - $rightAscension;
        $latitude = deg2rad($lat);
        $sinElevation = sin($latitude) * sin($declination) + cos($latitude) * cos($declination) * cos($hourAngle);
        return rad2deg(asin(max(-1.0, min(1.0, $sinElevation))));
    }

    /**
     * Teoretické globální záření za jasné oblohy (W/m², model Haurwitz).
     */
    public static function clearSkyRadiation(float $elevation): float
    {
        $cosZenith = sin(deg2rad($elevation));
        if ($cosZenith <= 0.0) {
            return 0.0;
        }
        return 1098.0 * $cosZenith * exp(-0.057 / $cosZenith);
    }

    /**
     * Fáze Měsíce (střední, bez poruch – přesnost řádově hodiny).
     *
     * phase: 0 = nov, 0,25 = první čtvrť, 0,5 = úplněk, 0,75 = poslední čtvrť.
     *
     * @return array<string, mixed>
     */
    public static function moon(DateTimeImmutable $at, DateTimeZone $timezone): array
    {
        $days = ($at->getTimestamp() - self::NEW_MOON_REFERENCE) / 86400;
        $age = fmod($days, self::SYNODIC_MONTH);
        if ($age < 0) {
            $age += self::SYNODIC_MONTH;
        }
        $phase = $age / self::SYNODIC_MONTH;
        $illumination = (1 - cos(2 * M_PI * $phase)) / 2;
        $toFull = ($phase < 0.5 ? 0.5 - $phase : 1.5 - $phase) * self::SYNODIC_MONTH;
        $toNew = (1 - $phase) * self::SYNODIC_MONTH;
        $date = static function (float $daysAhead) use ($at, $timezone): string {
            return (new DateTimeImmutable('@' . (int) round($at->getTimestamp() + $daysAhead * 86400)))
                ->setTimezone($timezone)->format('Y-m-d');
        };
        return [
            'phase' => round($phase, 3),
            'illumination' => (int) round($illumination * 100),
            'waxing' => $phase < 0.5,
            'name' => self::moonName($phase),
            'ageDays' => round($age, 1),
            'nextFull' => $date($toFull),
            'nextNew' => $date($toNew),
        ];
    }

    /**
     * Český název fáze; nov, čtvrti a úplněk mají okno ±1 den.
     */
    public static function moonName(float $phase): string
    {
        $window = 1.0 / self::SYNODIC_MONTH;
        $names = [
            [0.0, 'nov'], [0.25, 'první čtvrť'], [0.5, 'úplněk'], [0.75, 'poslední čtvrť'], [1.0, 'nov'],
        ];
        foreach ($names as [$center, $name]) {
            if (abs($phase - $center) <= $window) {
                return $name;
            }
        }
        if ($phase < 0.25) {
            return 'dorůstající srpek';
        }
        if ($phase < 0.5) {
            return 'dorůstající Měsíc';
        }
        if ($phase < 0.75) {
            return 'couvající Měsíc';
        }
        return 'couvající srpek';
    }
}
