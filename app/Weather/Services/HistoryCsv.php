<?php
/**
 * Převod výsledku HistoryService::build() na CSV soubor.
 *
 * - čas v místním čase (Europe/Prague), u dnů jen datum,
 * - desetinná čárka (český Excel), chybějící hodnota = prázdné pole,
 * - sloupce podle podrobnosti (surová měření / hodiny / dny).
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use OuBrix\Weather\Http\CsvResponse;

final class HistoryCsv
{
    /** Sloupce surových měření: klíč položky => [název sloupce, desetinná místa]. */
    private const RAW_COLUMNS = [
        'temp_c' => ['Teplota (°C)', 1], 'dew_point' => ['Rosný bod (°C)', 1],
        'heat_index' => ['Heat index (°C)', 1], 'wind_chill' => ['Wind chill (°C)', 1],
        'humidity' => ['Vlhkost (%)', 0], 'pressure' => ['Tlak (hPa)', 1],
        'wind_speed' => ['Vítr (km/h)', 1], 'wind_gust' => ['Náraz (km/h)', 1], 'winddir' => ['Směr větru (°)', 0],
        'precip_rate' => ['Intenzita srážek (mm/h)', 1], 'precip_total' => ['Srážky – denní čítač (mm)', 1],
        'uv' => ['UV index', 1], 'solar_radiation' => ['Sluneční záření (W/m²)', 0],
    ];

    /** Sloupce agregací (hodiny i dny). */
    private const AGGREGATE_COLUMNS = [
        'samples' => ['Počet měření', 0],
        'temp_avg' => ['Teplota průměr (°C)', 1], 'temp_min' => ['Teplota min (°C)', 1], 'temp_max' => ['Teplota max (°C)', 1],
        'dew_point_avg' => ['Rosný bod průměr (°C)', 1],
        'humidity_avg' => ['Vlhkost průměr (%)', 0], 'humidity_min' => ['Vlhkost min (%)', 0], 'humidity_max' => ['Vlhkost max (%)', 0],
        'pressure_avg' => ['Tlak průměr (hPa)', 1], 'pressure_min' => ['Tlak min (hPa)', 1], 'pressure_max' => ['Tlak max (hPa)', 1],
        'wind_avg' => ['Vítr průměr (km/h)', 1], 'wind_max' => ['Vítr max (km/h)', 1], 'gust_max' => ['Náraz max (km/h)', 1],
        'precip' => ['Srážky (mm)', 1], 'precip_rate_max' => ['Intenzita srážek max (mm/h)', 1],
        'uv_max' => ['UV index max', 1], 'solar_avg' => ['Záření průměr (W/m²)', 0], 'solar_max' => ['Záření max (W/m²)', 0],
    ];

    /** Názvy podrobností v názvu souboru. */
    private const SUFFIX = ['raw' => '5min', 'hour' => 'hodiny', 'day' => 'dny'];

    /**
     * @param array<string, mixed> $history výsledek HistoryService::build()
     */
    public static function response(array $history, DateTimeZone $timezone): CsvResponse
    {
        $resolution = (string) $history['resolution'];
        $columns = $resolution === 'raw' ? self::RAW_COLUMNS : self::AGGREGATE_COLUMNS;
        $header = [$resolution === 'day' ? 'Datum' : 'Čas (místní)'];
        foreach ($columns as $column) {
            $header[] = $column[0];
        }

        $rows = [];
        foreach ($history['items'] as $item) {
            $row = [self::time($item, $resolution, $timezone)];
            foreach ($columns as $key => $column) {
                $row[] = self::number(isset($item[$key]) ? $item[$key] : null, $column[1]);
            }
            $rows[] = $row;
        }

        $filename = sprintf(
            'oubrix-%s-%s_%s-%s.csv',
            $history['station'],
            $history['range']['fromDate'],
            $history['range']['toDate'],
            self::SUFFIX[$resolution] ?? $resolution
        );
        return new CsvResponse($filename, $header, $rows);
    }

    /**
     * Číslo s desetinnou čárkou; null → prázdné pole.
     *
     * @param mixed $value
     */
    public static function number($value, int $decimals): string
    {
        if ($value === null || !is_numeric($value)) {
            return '';
        }
        $text = number_format((float) $value, $decimals, ',', '');
        return $text === '-0' || preg_match('/^-0,0+$/', $text) === 1 ? ltrim($text, '-') : $text;
    }

    /** @param array<string, mixed> $item */
    private static function time(array $item, string $resolution, DateTimeZone $timezone): string
    {
        if ($resolution === 'day' && isset($item['date'])) {
            return (string) $item['date'];
        }
        $time = new DateTimeImmutable((string) $item['time']);
        return $time->setTimezone($timezone)->format('Y-m-d H:i');
    }
}
