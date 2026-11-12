<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/weather-ingest.php';

return static function (): int {
    $checks = 0;
    $assert = static function (bool $ok, string $message) use (&$checks): void {
        check($ok, $message);
        $checks++;
    };
    $now = strtotime('2026-11-09 12:00:00 UTC');
    $input = [
        'ID' => 'TEST', 'PASSWORD' => str_repeat('a', 32), 'dateutc' => '2026-11-09 11:59:50',
        'tempf' => '46.22', 'humidity' => '85', 'baromin' => '29.92',
        'dewptf' => '41', 'windchillf' => '32', 'heatindexf' => '50',
        'windspeedmph' => '10', 'windgustmph' => '20', 'winddir' => '270',
        'rainin' => '0.1', 'dailyrainin' => '0.5', 'solarradiation' => '140.5', 'UV' => '0.7',
    ];
    $row = WeatherIngest::station($input, 'IMOST71', $now);
    $assert(abs($row['temp_c'] - 7.9) < 0.000001, 'Fahrenheit preserves tenths');
    $assert(abs($row['wind_speed'] - 16.09344) < 0.000001, 'mph to km/h');
    $assert(abs($row['wind_gust'] - 32.18688) < 0.000001, 'gust conversion');
    $assert(abs($row['pressure'] - 1013.20749) < 0.0001, 'inHg to hPa');
    $assert(abs($row['precip_rate'] - 2.54) < 0.000001 && $row['precip_total'] === 12.7, 'rain conversion and daily counter');
    $assert($row['dew_point'] === 5.0 && $row['wind_chill'] === 0.0 && $row['heat_index'] === 10.0, 'derived uploaded temperatures');
    $assert($row['obs_time_local'] === '2026-11-09 12:59:50', 'Prague winter time');
    $assert($row['station_id'] === 'IMOST71' && $row['qc_status'] === null, 'canonical station, no fabricated WU QC');
    $assert($row['uv'] === 0.7 && $row['solar_radiation'] === 140.5, 'UV and solar units unchanged');
    $assert(WeatherIngest::authorized($input, 'TEST', str_repeat('a', 32)), 'separate upload credentials');
    $assert(!WeatherIngest::authorized($input, 'OTHER', str_repeat('a', 32)), 'wrong ID denied');
    $assert(!WeatherIngest::authorized($input, 'TEST', 'short'), 'short configured key denied');
    $assert(!WeatherIngest::authorized(array_merge($input, ['PASSWORD' => ['a']]), 'TEST', str_repeat('a', 32)), 'array credentials denied');
    $assert(!WeatherIngest::authorized($input, 'TEST', str_repeat('b', 32)), 'wrong key denied');
    $minimal = ['dateutc' => 'now', 'tempf' => '32', 'humidity' => '0', 'baromin' => '30'];
    $empty = WeatherIngest::station($minimal, 'IMOST71', $now);
    $assert($empty['wind_speed'] === null && $empty['precip_total'] === null, 'missing sensors remain null, not zero');
    $assert($empty['temp_c'] === 0.0 && $empty['humidity'] === 0.0, 'real zero readings retained');
    $assert($empty['obs_time_utc'] === gmdate('Y-m-d H:i:s', $now), 'dateutc now uses receipt UTC');
    $summer = WeatherIngest::station(array_merge($minimal, ['dateutc' => '2026-07-01 12:00:00']), 'IMOST71', strtotime('2026-07-01 12:00:00 UTC'));
    $assert($summer['obs_time_local'] === '2026-07-01 14:00:00', 'Prague summer time');
    $assert(WeatherIngest::slot($now - 1) === '2026-11-09 11:55:00', 'slot before boundary');
    $assert(WeatherIngest::slot($now) === '2026-11-09 12:00:00', 'slot at boundary');
    $assert(WeatherIngest::slot($now + 299) === '2026-11-09 12:00:00', 'slot end');
    $assert(WeatherIngest::slot($now + 300) === '2026-11-09 12:05:00', 'next slot');

    foreach ([
        ['tempf' => '-999'], ['tempf' => 'NaN'], ['tempf' => 'INF'], ['tempf' => ['32']],
        ['tempf' => ''], ['tempf' => '1e1000'], ['humidity' => '101'], ['baromin' => '0'],
        ['windspeedmph' => '-1'], ['dailyrainin' => '-1'], ['UV' => '100'],
        ['dateutc' => '2026-02-30 12:00:00'], ['dateutc' => '2026-11-09 11:50:00'],
        ['dateutc' => '2026-11-09 12:02:01'], ['dateutc' => ['now']],
        ['softwaretype' => "bad\nvalue"],
    ] as $invalid) {
        try {
            WeatherIngest::station(array_merge($input, $invalid), 'IMOST71', $now);
            $assert(false, 'invalid input must throw');
        } catch (InvalidArgumentException $e) {
            $assert($e->getMessage() !== '', 'invalid input has explicit reason');
        }
    }
    $state = ['last_direct_received_utc' => gmdate('Y-m-d H:i:s', $now - 599),
        'last_direct_obs_utc' => gmdate('Y-m-d H:i:s', $now - 599)];
    $assert(WeatherIngest::directFresh($state, $now), 'fresh at 599 seconds');
    $assert(!WeatherIngest::directFresh($state, $now + 1), 'fallback at exactly 600 seconds');
    $assert(!WeatherIngest::directFresh(null, $now), 'fallback before first upload');
    $assert(!WeatherIngest::directFresh(array_merge($state, ['last_direct_obs_utc' => gmdate('Y-m-d H:i:s', $now - 600)]), $now), 'receipt of old measurement does not suppress fallback');
    $wu = [
        'stationID' => 'IMOST71', 'obsTimeUtc' => '2026-11-09T11:59:50Z',
        'obsTimeLocal' => 'wrong', 'humidity' => 80, 'qcStatus' => 1,
        'neighborhood' => 'Most', 'country' => 'CZ',
        'metric' => ['temp' => 7.9, 'pressure' => 1013.2, 'windSpeed' => 16.1, 'precipTotal' => 12.7],
    ];
    $normalized = WeatherIngest::underground($wu, 'IMOST71', $now);
    $assert($normalized['temp_c'] === 7.9 && $normalized['wind_speed'] === 16.1, 'WU metric precision unchanged');
    $assert($normalized['obs_time_local'] === '2026-11-09 12:59:50', 'WU time normalized from UTC');
    $assert($normalized['precip_total'] === 12.7 && $normalized['wind_gust'] === null, 'WU optional fields');
    foreach ([
        array_merge($wu, ['stationID' => 'WRONG']),
        array_merge($wu, ['obsTimeUtc' => '2026-11-09T11:50:00Z']),
        array_merge($wu, ['metric' => ['temp' => null, 'pressure' => 1013]]),
        array_merge($wu, ['metric' => ['temp' => 10, 'pressure' => 0]]),
    ] as $invalid) {
        try {
            WeatherIngest::underground($invalid, 'IMOST71', $now);
            $assert(false, 'invalid WU response must throw');
        } catch (InvalidArgumentException $e) {
            $assert(true, 'invalid WU response rejected');
        }
    }
    return $checks;
};
