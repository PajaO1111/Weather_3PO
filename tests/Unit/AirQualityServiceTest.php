<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\AirQualityService;
use OuBrix\Weather\Weather\Repositories\AirQualityLastValidRepository;

return static function (): int {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weather-chmi-test-' . bin2hex(random_bytes(6));
    $now = new DateTimeImmutable('2026-10-25 11:00:00', new DateTimeZone('UTC'));
    $metadata = [
        'datumVytvoreni' => '2026-10-25T06:42:01Z',
        'verzeDat' => '1.2',
        'data' => [
            'Localities' => [[
                'LocalityCode' => 'UMOM',
                'Name' => 'Most',
                'Active' => true,
                'BasicInfo' => ['Region' => 'Ústecký', 'District' => 'Most'],
                'Localization' => ['LatAsNumber' => 50.5, 'LonAsNumber' => 13.64, 'Alt' => 240],
                'MeasuringPrograms' => [[
                    'Measurements' => [
                        ['IdRegistration' => 40356, 'ComponentCode' => 'PM10', 'UnitAsASCII' => 'ug/m^3', 'Interval' => '1h', 'HasRealTimeData' => 'Ano', 'DateFrom' => '2004-01-01T00:00:00Z'],
                        ['IdRegistration' => 40369, 'ComponentCode' => 'PM2_5', 'UnitAsASCII' => 'ug/m^3', 'Interval' => '1h', 'HasRealTimeData' => 'Ano', 'DateFrom' => '2004-01-01T00:00:00Z'],
                        ['IdRegistration' => 40352, 'ComponentCode' => 'NO2', 'UnitAsASCII' => 'ug/m^3', 'Interval' => '1h', 'HasRealTimeData' => 'Ano', 'DateFrom' => '2004-01-01T00:00:00Z'],
                        ['IdRegistration' => 10221, 'ComponentCode' => 'SO2', 'UnitAsASCII' => 'ug/m^3', 'Interval' => '1h', 'HasRealTimeData' => 'Ano', 'DateFrom' => '2021-09-01T00:00:00Z'],
                        ['IdRegistration' => 40354, 'ComponentCode' => 'O3', 'UnitAsASCII' => 'ug/m^3', 'Interval' => '1h', 'HasRealTimeData' => 'Ano', 'DateFrom' => '2004-01-01T00:00:00Z'],
                        ['IdRegistration' => 1648760, 'ComponentCode' => 'INDX', 'UnitAsASCII' => '', 'Interval' => '1h', 'HasRealTimeData' => 'Ne', 'DateFrom' => '2019-01-01T00:00:00Z'],
                    ],
                ]],
            ], [
                'LocalityCode' => 'LIT',
                'Name' => 'Litvínov',
                'Active' => true,
                'BasicInfo' => ['Region' => 'Ústecký', 'District' => 'Most'],
                'Localization' => ['LatAsNumber' => 50.604, 'LonAsNumber' => 13.609, 'Alt' => 300],
                'MeasuringPrograms' => [[
                    'Measurements' => [
                        ['IdRegistration' => 50001, 'ComponentCode' => 'PM10', 'UnitAsASCII' => 'ug/m3', 'Interval' => '1h', 'HasRealTimeData' => 'Ano', 'DateFrom' => '2020-01-01T00:00:00Z'],
                        ['IdRegistration' => 50002, 'ComponentCode' => 'NO2', 'UnitAsASCII' => 'ug/m3', 'Interval' => '30min', 'HasRealTimeData' => 'Ano', 'DateFrom' => '2020-01-01T00:00:00Z'],
                    ],
                ]],
            ]],
        ],
    ];
    $sources = [
        'metadata.json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'airquality_1h_avg_CZ.csv' => "idRegistration, startTime, idValueType, value\n"
            . "40356, 2026-10-25T10:00:00Z, 8, 12.5\n"
            . "40369, 2026-10-25T10:00:00Z, 6, -5009.0\n"
            . "40352, 2026-10-25T10:00:00Z, 9, 8.2\n"
            . "10221, 2026-10-25T10:00:00Z, 11, 1.4\n"
            . "1648760, 2026-10-25T10:00:00Z, 148, 0\n"
            . "50001, 2026-10-25T10:00:00Z, 8, 20.5\n"
            . "50002, 2026-10-25T10:00:00Z, 8, 4.2\n",
        'ValueType.csv' => "ID_VALUE_TYPE,NAME\n5,\"Vyřazeno pro nevěrohodnost\"\n6,\"Chybový kód operativních dat\"\n8,\"Operativní data\"\n9,\"Verifikovaná data\"\n11,\"Substituce pod limitem\"\n148,\"Index kvality ovzduší\"\n",
        'AirQualityIndex.csv' => "ID,Level,IndexRange,AirQuality\n1,1A,\"≥ 0.00 and < 0.34\",\"velmi dobrá až dobrá\"\n0,,\"Neúplná data\",\"Neúplná data\"\n",
    ];
    $calls = 0;
    $transport = static function (string $url, int $maxBytes) use (&$calls, $sources): string {
        $calls++;
        $name = basename(parse_url($url, PHP_URL_PATH));
        if (!isset($sources[$name]) || strlen($sources[$name]) > $maxBytes) {
            throw new RuntimeException('Unexpected fixture request.');
        }
        return $sources[$name];
    };

    try {
        $gateway = new ChmiGateway($directory, $transport, $now);
        $service = new AirQualityService($gateway, $now);
        $result = $service->build();
        check($result['station']['code'] === 'UMOM' && $result['station']['name'] === 'Most', 'select exact active Most station');
        check($result['measurements']['PM10']['value'] === 12.5 && $result['measurements']['PM10']['status'] === 'available', 'display operational PM10 value');
        check($result['measurements']['NO2']['value'] === 8.2 && $result['measurements']['NO2']['valueTypeId'] === 9, 'display verified NO2 value');
        check($result['measurements']['PM2_5']['value'] === null && $result['measurements']['PM2_5']['status'] === 'invalid', 'never display error code as particulate concentration');
        check($result['measurements']['SO2']['value'] === 1.4 && $result['measurements']['SO2']['status'] === 'substituted', 'mark substituted values distinctly');
        check($result['measurements']['O3']['value'] === null && $result['measurements']['O3']['status'] === 'no_data', 'missing pollutant row is not zero');
        check($result['index']['status'] === 'not_realtime' && $result['index']['value'] === null, 'do not invent a real-time air quality index');
        check($result['meta']['observationStale'] === false && $result['meta']['sourceVersion'] === '1.2', 'include freshness and metadata version');
        check($result['nearbyStations'][0]['code'] === 'LIT'
            && $result['comparison']['station']['code'] === 'LIT'
            && $result['comparison']['pollutants']['PM10']['status'] === 'comparable'
            && $result['comparison']['pollutants']['PM10']['difference'] === -8.0,
            'offer nearest compatible station and compare same-time pollutant values only');
        check($result['comparison']['pollutants']['NO2']['status'] === 'interval_mismatch',
            'do not compare pollutants with different measurement intervals');
        $invalidStation = false;
        try {
            $service->build('OUTSIDE');
        } catch (InvalidArgumentException $exception) {
            $invalidStation = true;
        }
        check($invalidStation, 'reject stations outside the nearby metadata allowlist');

        $service->build();
        check($calls === 4, 'cache each allowlisted resource while its TTL is valid');

        $later = $now->modify('+2 days');
        $offline = new ChmiGateway($directory, static function (): string {
            throw new RuntimeException('upstream offline');
        }, $later);
        $cached = (new AirQualityService($offline, $later))->build();
        check($cached['meta']['sources']['values']['cacheStatus'] === 'stale', 'fallback cache is explicitly stale');
        check(count($cached['meta']['warnings']) > 0, 'upstream failure is visible with stale cache');

        $indexSources = $sources;
        $indexSources['metadata.json'] = str_replace('"HasRealTimeData":"Ne"', '"HasRealTimeData":"Ano"', $sources['metadata.json']);
        $indexTransport = static function (string $url, int $maxBytes) use ($indexSources): string {
            $name = basename(parse_url($url, PHP_URL_PATH));
            if (!isset($indexSources[$name]) || strlen($indexSources[$name]) > $maxBytes) {
                throw new RuntimeException('Unexpected fixture request.');
            }
            return $indexSources[$name];
        };
        $indexDirectory = $directory . '-index';
        $indexResult = (new AirQualityService(new ChmiGateway($indexDirectory, $indexTransport, $now), $now))->build();
        check($indexResult['index']['status'] === 'incomplete' && $indexResult['index']['value'] === null,
            'zero index with valid index type is treated as incomplete (' . $indexResult['index']['status'] . ')');

        $emptyDirectory = $directory . '-empty';
        $unavailable = new ChmiGateway($emptyDirectory, static function (): string {
            throw new RuntimeException('upstream offline');
        }, $now);
        $failed = false;
        try {
            (new AirQualityService($unavailable, $now))->build();
        } catch (RuntimeException $exception) {
            $failed = true;
        }
        check($failed, 'report upstream failure when no successful cache exists');

        $lastDirectory = $directory . '-last';
        $sequenceSources = $sources;
        $sequenceTransport = static function (string $url, int $maxBytes) use (&$sequenceSources): string {
            return $sequenceSources[basename(parse_url($url, PHP_URL_PATH))];
        };
        $buildSnapshot = static function (DateTimeImmutable $at) use ($lastDirectory, $sequenceTransport): array {
            $gateway = new ChmiGateway($lastDirectory, $sequenceTransport, $at);
            $gateway->invalidate('air_values');
            return (new AirQualityService($gateway, $at, new AirQualityLastValidRepository($lastDirectory)))->build();
        };
        $first = $buildSnapshot($now);
        check($first['measurements']['PM10']['value'] === 12.5, 'save first valid concentration');
        check(is_file($lastDirectory . DIRECTORY_SEPARATOR . 'air_last_valid_umom.json')
            && is_file($lastDirectory . DIRECTORY_SEPARATOR . 'air_last_valid_lit.json'),
            'persist last-valid measurements in separate locality stores');
        $sequenceSources['airquality_1h_avg_CZ.csv'] = "idRegistration, startTime, idValueType, value\n"
            . "40356, 2026-10-25T11:00:00Z, 6, -5009.0\n"
            . "40369, 2026-10-25T11:00:00Z, 6, -5009.0\n";
        $fallback = $buildSnapshot($now->modify('+2 hours'));
        $pm10 = $fallback['measurements']['PM10'];
        check($pm10['value'] === 12.5 && $pm10['status'] === 'last_valid' && $pm10['stale'], 'error snapshot retains valid value across service instances');
        check($pm10['observedAt'] === '2026-10-25T10:00:00Z' && $pm10['ageSeconds'] === 10800, 'fallback age uses original observation time, not download time');
        check($pm10['latestStatus'] === 'invalid' && $pm10['latestObservedAt'] === '2026-10-25T11:00:00Z', 'preserve reason and timestamp of current failed observation');
        check($fallback['measurements']['NO2']['value'] === 8.2 && $fallback['measurements']['NO2']['latestStatus'] === 'no_data', 'missing CSV row retains last valid value for its own pollutant');
        check($fallback['measurements']['PM2_5']['value'] === null && $fallback['measurements']['O3']['value'] === null, 'never create fallback for pollutants without valid history');
        check($fallback['measurements']['SO2']['value'] === 1.4 && $fallback['measurements']['SO2']['valueTypeId'] === 11, 'fallback preserves original substituted value type');
        $sequenceSources['airquality_1h_avg_CZ.csv'] = "idRegistration, startTime, idValueType, value\n"
            . "40356, 2026-10-25T13:00:00Z, 8, 0.0\n";
        $recovered = $buildSnapshot($now->modify('+2 hours 30 minutes'));
        check($recovered['measurements']['PM10']['value'] === 0.0 && $recovered['measurements']['PM10']['status'] === 'available', 'new valid zero replaces old value and clears fallback');
        $sequenceSources['airquality_1h_avg_CZ.csv'] = $sources['airquality_1h_avg_CZ.csv'];
        $older = $buildSnapshot($now->modify('+3 hours'));
        check($older['measurements']['PM10']['value'] === 0.0
            && $older['measurements']['PM10']['observedAt'] === '2026-10-25T13:00:00Z', 'older valid snapshot cannot overwrite newer saved observation');
        $changed = $older['measurements'];
        $changed['PM10']['registrationId'] = '999999';
        $changed['PM10']['value'] = null;
        $changed['PM10']['status'] = 'no_data';
        $isolated = (new AirQualityLastValidRepository($lastDirectory))->merge($changed);
        check(!isset($isolated['PM10']), 'do not reuse a previous registration after station measurement changes');

        $storageFile = $lastDirectory . DIRECTORY_SEPARATOR . 'air_last_valid_umom.json';
        $brokenStorage = new AirQualityLastValidRepository($storageFile);
        $warning = (new AirQualityService(
            new ChmiGateway($lastDirectory, $sequenceTransport, $now),
            $now,
            $brokenStorage
        ))->build();
        check($warning['measurements']['PM10']['value'] === 12.5 && count($warning['meta']['warnings']) > 0,
            'storage failures keep live data usable and surface a warning');
        return 28;
    } finally {
        foreach ([$directory, $directory . '-empty', $directory . '-index', $directory . '-last'] as $path) {
            if (is_dir($path)) {
                foreach (scandir($path) ?: [] as $file) {
                    if ($file !== '.' && $file !== '..' && preg_match('/^[a-z_]+\.json$/', $file) === 1) {
                        unlink($path . DIRECTORY_SEPARATOR . $file);
                    }
                }
                rmdir($path);
            }
        }
    }
};
