<?php
/**
 * Drobné meteorologické převody a slovní popisy pro zobrazení.
 *
 * Čisté funkce bez přístupu k DB – snadno testovatelné.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Support;

final class Meteo
{
    /** 16 směrů větružice v české notaci (S = sever, V = východ, J = jih, Z = západ). */
    private const COMPASS = ['S', 'SSV', 'SV', 'VSV', 'V', 'VJV', 'JV', 'JJV', 'J', 'JJZ', 'JZ', 'ZJZ', 'Z', 'ZSZ', 'SZ', 'SSZ'];

    /**
     * Převede směr větru ve stupních na zkratku (např. 225 → „JZ“).
     */
    public static function compass(?float $degrees): ?string
    {
        if ($degrees === null || $degrees < 0 || $degrees > 360) {
            return null;
        }
        $index = (int) round($degrees / 22.5) % 16;
        return self::COMPASS[$index];
    }

    /**
     * Pocitová teplota pro zobrazení.
     *
     * Za chladu a větru (T ≤ 10 °C, vítr > 4,8 km/h) použije wind chill,
     * za horka (T ≥ 27 °C) heat index, jinak skutečnou teplotu.
     * Obě hodnoty počítá a ukládá stanice/WU, zde se jen vybírá.
     */
    public static function feelsLike(?float $temperature, ?float $windSpeed, ?float $windChill, ?float $heatIndex): ?float
    {
        if ($temperature === null) {
            return null;
        }
        if ($temperature <= 10.0 && $windSpeed !== null && $windSpeed > 4.8 && $windChill !== null) {
            return $windChill;
        }
        if ($temperature >= 27.0 && $heatIndex !== null) {
            return $heatIndex;
        }
        return $temperature;
    }

    /**
     * Slovní popis tlakové tendence za ~3 hodiny (orientační hranice).
     *
     * @param float $change změna tlaku v hPa (nyní − dříve)
     */
    public static function pressureTendency(float $change): string
    {
        $abs = abs($change);
        if ($abs < 0.5) {
            return 'ustálený';
        }
        $strength = $abs <= 2.0 ? 'mírně' : 'výrazně';
        return $strength . ($change > 0 ? ' stoupá' : ' klesá');
    }

    /**
     * Kategorie UV indexu podle WHO.
     */
    public static function uvCategory(?float $uv): ?string
    {
        if ($uv === null) {
            return null;
        }
        if ($uv < 3) {
            return 'nízký';
        }
        if ($uv < 6) {
            return 'střední';
        }
        if ($uv < 8) {
            return 'vysoký';
        }
        if ($uv < 11) {
            return 'velmi vysoký';
        }
        return 'extrémní';
    }

    /** Horní hranice stupňů Beaufortovy stupnice v km/h (stupeň 12 = vše nad 117 km/h). */
    private const BEAUFORT = [
        [1.0, 'bezvětří'], [6.0, 'vánek'], [12.0, 'větřík'], [20.0, 'slabý vítr'],
        [29.0, 'mírný vítr'], [39.0, 'čerstvý vítr'], [50.0, 'silný vítr'], [62.0, 'prudký vítr'],
        [75.0, 'bouřlivý vítr'], [89.0, 'vichřice'], [103.0, 'silná vichřice'], [118.0, 'mohutná vichřice'],
    ];

    /**
     * Stupeň Beaufortovy stupnice z rychlosti větru v km/h.
     *
     * @return array{force: int, label: string}|null
     */
    public static function beaufort(?float $speed): ?array
    {
        if ($speed === null || $speed < 0) {
            return null;
        }
        foreach (self::BEAUFORT as $force => [$limit, $label]) {
            if ($speed < $limit) {
                return ['force' => $force, 'label' => $label];
            }
        }
        return ['force' => 12, 'label' => 'orkán'];
    }

    /**
     * Slovní intenzita srážek (mm/h) podle běžných hranic.
     */
    public static function precipIntensity(?float $rate): ?string
    {
        if ($rate === null) {
            return null;
        }
        if ($rate <= 0.0) {
            return 'bez srážek';
        }
        if ($rate < 2.5) {
            return 'slabé';
        }
        if ($rate < 10.0) {
            return 'mírné';
        }
        if ($rate < 50.0) {
            return 'silné';
        }
        return 'přívalové';
    }

    /**
     * Odhad stavu počasí z měření stanice (stanice nemá čidlo oblačnosti).
     *
     * Srážky podle intenzity (při teplotě ≤ 1 °C jako sníh), mlha podle vlhkosti
     * a rosného bodu, ve dne oblačnost z poměru naměřeného a teoretického
     * slunečního záření za jasné oblohy (jen při Slunci výš než 10°).
     *
     * @param float|null $clearSky teoretické záření za jasna (W/m²), null = neznámé
     * @return array{code: string, label: string}
     */
    public static function condition(?float $precipRate, ?float $temperature, ?float $humidity, ?float $dewPoint, bool $isDay, ?float $solar, ?float $clearSky, ?float $sunElevation): array
    {
        if ($precipRate !== null && $precipRate > 0.0) {
            if ($temperature !== null && $temperature <= 1.0) {
                return ['code' => 'snow', 'label' => 'sněžení'];
            }
            return $precipRate >= 10.0
                ? ['code' => 'heavy-rain', 'label' => 'silný déšť']
                : ['code' => 'rain', 'label' => $precipRate < 2.5 ? 'slabý déšť' : 'déšť'];
        }
        if ($humidity !== null && $humidity >= 97.0 && $temperature !== null && $dewPoint !== null && $temperature - $dewPoint <= 1.0) {
            return ['code' => 'fog', 'label' => 'mlha nebo opar'];
        }
        if (!$isDay) {
            return ['code' => 'night', 'label' => 'noc'];
        }
        if ($solar === null || $clearSky === null || $clearSky <= 0.0 || $sunElevation === null || $sunElevation < 10.0) {
            return ['code' => 'day', 'label' => 'den'];
        }
        $ratio = $solar / $clearSky;
        if ($ratio >= 0.75) {
            return ['code' => 'clear', 'label' => 'jasno'];
        }
        if ($ratio >= 0.4) {
            return ['code' => 'partly', 'label' => 'polojasno'];
        }
        return ['code' => 'cloudy', 'label' => 'zataženo'];
    }

    /**
     * Převod hodnoty z DB (řetězec/číslo/NULL) na float nebo null.
     *
     * @param mixed $value
     */
    public static function toFloat($value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }
}
