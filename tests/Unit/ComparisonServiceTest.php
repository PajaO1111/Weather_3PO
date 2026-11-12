<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\KopistyService;
use OuBrix\Weather\Weather\Services\ComparisonService;

return static function (): int {
    $now = new DateTimeImmutable('2026-10-25T12:35:00Z');
    $service = new ComparisonService();
    $local = ['station' => 'TEST', 'items' => [
        ['time' => '2026-10-25T00:05:00Z', 'temp_c' => 10.0],
        ['time' => '2026-10-25T00:55:00Z', 'temp_c' => 14.0],
        ['time' => '2026-10-25T01:05:00Z', 'temp_c' => 0.0],
        ['time' => '2026-10-25T12:05:00Z', 'temp_c' => 99.0],
    ]];
    $kopisty = ['station' => ['id' => KopistyService::WSI], 'items' => [
        ['time' => '2026-10-25T00:10:00Z', 'temp_c' => 8.0, 'quality' => 0.0, 'flag' => ''],
        ['time' => '2026-10-25T00:20:00Z', 'temp_c' => 10.0, 'quality' => 0.0, 'flag' => ''],
        ['time' => '2026-10-25T01:10:00Z', 'temp_c' => 5.0, 'quality' => 5.0, 'flag' => ''],
    ]];
    $result = $service->build($local, $kopisty, $now);
    $rows = array_column($result['items'], null, 'time');
    check(count($rows) === 24 && $result['range']['from'] === '2026-10-24T12:00:00Z'
        && $result['range']['to'] === '2026-10-25T12:00:00Z', 'fixed 24 closed UTC hours across daylight saving transition');
    check($rows['2026-10-25T00:00:00Z']['local'] === 12.0 && $rows['2026-10-25T00:00:00Z']['kopisty'] === 9.0, 'independently aggregate unequal sampling on shared UTC hour');
    check($rows['2026-10-25T00:00:00Z']['localSamples'] === 2 && $rows['2026-10-25T00:00:00Z']['difference'] === 3.0, 'counts and signed difference');
    check($rows['2026-10-25T01:00:00Z']['local'] === 0.0 && $rows['2026-10-25T01:00:00Z']['kopisty'] === null, 'zero is real value and unknown quality excluded');
    check($rows['2026-10-25T01:00:00Z']['kopistyExcluded'] === 1, 'quality exclusion visible');
    check($rows['2026-10-25T01:00:00Z']['kopistyUnverified'] === 5.0
        && $rows['2026-10-25T01:00:00Z']['kopistyUnknownSamples'] === 1
        && $rows['2026-10-25T01:00:00Z']['unverifiedDifference'] === -5.0, 'Unknown displayed separately without changing verified values');
    check($result['summary']['pairedHours'] === 1 && $result['summary']['meanDifference'] === 3.0, 'compare only common available hours');
    $partial = $service->build($local, null, $now, ['Kopisty nejsou dostupné.']);
    check($partial['meta']['localAvailable'] && !$partial['meta']['kopistyAvailable']
        && count($partial['meta']['warnings']) === 1, 'partial upstream failure preserves local series');
    $duplicate = $kopisty;
    $duplicate['items'][] = ['time' => '2026-10-25T00:10:00Z', 'temp_c' => 100.0, 'quality' => 0.0, 'flag' => ''];
    $conflict = $service->build(null, $duplicate, $now);
    $conflictRows = array_column($conflict['items'], null, 'time');
    check($conflictRows['2026-10-25T00:00:00Z']['kopisty'] === 10.0
        && $conflictRows['2026-10-25T00:00:00Z']['kopistyExcluded'] === 1, 'conflicting duplicate timestamp excluded');

    $weatherLocal = ['items' => []];
    $weatherOfficial = ['items' => []];
    for ($minute = 0; $minute <= 60; $minute += 5) {
        $time = (new DateTimeImmutable('2026-10-25T03:00:00Z'))->modify('+' . $minute . ' minutes')->format('Y-m-d\TH:i:s\Z');
        $weatherLocal['items'][] = ['time' => $time, 'wind_speed' => 18.0, 'precip_total' => 10.0 + $minute / 10];
        if ($minute > 0 && $minute % 10 === 0) {
            $weatherOfficial['items'][] = ['time' => $time, 'element' => 'SRA10M', 'precip_mm' => 1.0, 'quality' => 5.0, 'flag' => ''];
        }
    }
    $weatherOfficial['items'][] = ['time' => '2026-10-25T03:10:00Z', 'element' => 'F', 'wind_speed' => 5.0, 'quality' => 0.0, 'flag' => ''];
    $weatherOfficial['items'][] = ['time' => '2026-10-25T03:20:00Z', 'element' => 'F', 'wind_speed' => 10.0, 'quality' => 5.0, 'flag' => ''];
    $weatherOfficial['items'][] = ['time' => '2026-10-25T03:30:00Z', 'element' => 'F', 'wind_speed' => 99.0, 'quality' => 1.0, 'flag' => ''];
    $weatherOfficial['items'][] = ['time' => '2026-10-25T03:40:00Z', 'element' => 'F', 'wind_speed' => 99.0, 'quality' => 5.0, 'flag' => 'X'];
    $weather = $service->build($weatherLocal, $weatherOfficial, $now);
    $rain = array_column($weather['metrics']['precipitation']['items'], null, 'time')['2026-10-25T03:00:00Z'];
    $wind = array_column($weather['metrics']['wind']['items'], null, 'time')['2026-10-25T03:00:00Z'];
    check($rain['local'] === 6.0 && $rain['kopisty'] === null && $rain['kopistyUnverified'] === 6.0
        && $rain['kopistyUnverifiedSamples'] === 6, 'sum rain intervals including hour end; difference daily counter, not intensity or mean');
    check($wind['local'] === 18.0 && $wind['kopisty'] === 18.0 && $wind['kopistyUnverified'] === 27.0
        && $wind['kopistyUnverifiedExcluded'] === 2, 'convert official wind m/s to km/h; mix Good and Unknown only in provisional series');
    check($weather['metrics']['precipitation']['unit'] === 'mm' && $weather['metrics']['wind']['unit'] === 'km/h'
        && count($weather['metrics']['wind']['items']) === 24, 'all metrics share units and fixed 24-hour axis');
    $missingRain = $weatherOfficial;
    array_shift($missingRain['items']);
    $missing = $service->build($weatherLocal, $missingRain, $now);
    check(array_column($missing['metrics']['precipitation']['items'], null, 'time')['2026-10-25T03:00:00Z']['kopistyUnverified'] === null,
        'five rain intervals must not masquerade as full hourly total');
    $goodRain = $weatherOfficial;
    foreach ($goodRain['items'] as &$point) {
        if ($point['element'] === 'SRA10M') {
            $point['quality'] = 0.0;
        }
    }
    unset($point);
    $goodRain['items'][] = $goodRain['items'][0];
    $good = $service->build($weatherLocal, $goodRain, $now);
    $goodRow = array_column($good['metrics']['precipitation']['items'], null, 'time')['2026-10-25T03:00:00Z'];
    check($goodRow['kopisty'] === 6.0 && $goodRow['kopistyUnverified'] === null && $goodRow['difference'] === 0.0,
        'Good rain sum deduplicates exact repeats and produces verified difference');
    $goodRain['items'][0]['precip_mm'] = 3.0;
    $rainConflict = $service->build($weatherLocal, $goodRain, $now);
    check(array_column($rainConflict['metrics']['precipitation']['items'], null, 'time')['2026-10-25T03:00:00Z']['kopisty'] === null,
        'conflicting rain duplicate invalidates full hourly total');
    $lastHourRain = ['items' => []];
    for ($minute = 10; $minute <= 60; $minute += 10) {
        $lastHourRain['items'][] = [
            'time' => (new DateTimeImmutable('2026-10-25T11:00:00Z'))->modify('+' . $minute . ' minutes')->format('Y-m-d\TH:i:s\Z'),
            'element' => 'SRA10M', 'precip_mm' => 0.0, 'quality' => 0, 'flag' => '',
        ];
    }
    $lastHour = $service->build(null, $lastHourRain, $now);
    check($lastHour['metrics']['precipitation']['items'][23]['kopisty'] === 0.0,
        'final closed rain hour includes measurement exactly at range end and preserves zero');
    $gapLocal = $weatherLocal;
    array_splice($gapLocal['items'], 3, 3);
    $gap = $service->build($gapLocal, $weatherOfficial, $now);
    check(array_column($gap['metrics']['precipitation']['items'], null, 'time')['2026-10-25T03:00:00Z']['local'] === null,
        'local rain gap over ten minutes is not bridged');
    $resetLocal = $weatherLocal;
    $resetLocal['items'][6]['precip_total'] = 0.0;
    $reset = $service->build($resetLocal, $weatherOfficial, $now);
    check(array_column($reset['metrics']['precipitation']['items'], null, 'time')['2026-10-25T03:00:00Z']['local'] === null,
        'counter drop excludes rain hour rather than fabricating a reset total');
    $zeroLocal = $weatherLocal;
    foreach ($zeroLocal['items'] as &$point) {
        $point['precip_total'] = 0.0;
    }
    unset($point);
    $zero = $service->build($zeroLocal, null, $now);
    check(array_column($zero['metrics']['precipitation']['items'], null, 'time')['2026-10-25T03:00:00Z']['local'] === 0.0,
        'complete dry hour remains zero');
    $boundaryLocal = ['items' => []];
    for ($minute = 0; $minute <= 60; $minute += 5) {
        $boundaryLocal['items'][] = [
            'time' => (new DateTimeImmutable('2026-10-24T21:00:00Z'))->modify('+' . $minute . ' minutes')->format('Y-m-d\TH:i:s\Z'),
            'precip_total' => $minute === 60 ? 0.0 : 8.0 + $minute / 10,
        ];
    }
    $midnight = $service->build($boundaryLocal, null, $now);
    check(array_column($midnight['metrics']['precipitation']['items'], null, 'time')['2026-10-24T21:00:00Z']['local'] === null,
        'Prague midnight reset is not treated as a negative or dry interval');
    $boundaryLocal['items'][12]['precip_total'] = 14.0;
    $midnightWithoutDrop = $service->build($boundaryLocal, null, $now);
    check(array_column($midnightWithoutDrop['metrics']['precipitation']['items'], null, 'time')['2026-10-24T21:00:00Z']['local'] === null,
        'local date boundary excludes ambiguous rain even when next-day counter does not drop');

    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weather-kopisty-' . bin2hex(random_bytes(6));
    $table = static function (string $header, array $values): string {
        return json_encode(['datumVytvoreni' => '2026-10-25T00:01:00.000Z', 'verzeDat' => '1.0',
            'data' => ['data' => ['header' => $header, 'values' => $values]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    };
    $sources = [
        'meta1-20261025.json' => $table('WSI,FULL_NAME,GEOGR1,GEOGR2,ELEVATION', [[KopistyService::WSI, 'Kopisty', '13,62278', '50,54417', '240,0']]),
        'meta2-20261025.json' => $table('OBS_TYPE,WSI,EG_EL_ABBREVIATION,UN_DESCRIPTION', [
            ['10M', KopistyService::WSI, 'T', '°C'], ['10M', KopistyService::WSI, 'F', 'm/s'],
            ['10M', KopistyService::WSI, 'SRA10M', 'mm'],
        ]),
        'meta4-20261025.json' => $table('FLAG2,DESCRIPTION', [['0,0', 'Good/Kvalitní hodnota'], ['5,0', 'Unknown/Neznámá kvalita']]),
        '10m-0-20000-0-11433-20261024.json' => $table('STATION,ELEMENT,DT,VAL,FLAG,QUALITY', [
            [KopistyService::WSI, 'T', '2026-10-24T23:50:00Z', 1.2, '', 0.0],
        ]),
        '10m-0-20000-0-11433-20261025.json' => $table('STATION,ELEMENT,DT,VAL,FLAG,QUALITY', [
            [KopistyService::WSI, 'T', '2026-10-25T00:00:00Z', 2.5, '', 0.0],
            [KopistyService::WSI, 'T', '2026-10-25T00:10:00Z', 99.0, '', 5.0],
            [KopistyService::WSI, 'T', '2026-10-25T00:20:00Z', 9.0, 'X', 0.0],
            ['other', 'T', '2026-10-25T00:00:00Z', 100.0, '', 0.0],
            [KopistyService::WSI, 'P', '2026-10-25T00:00:00Z', 1000.0, '', 0.0],
            [KopistyService::WSI, 'F', '2026-10-25T00:00:00Z', '3,5', '', 5.0],
            [KopistyService::WSI, 'SRA10M', '2026-10-25T00:10:00Z', '0,2', '', 5.0],
        ]),
    ];
    $requests = [];
    $transport = static function (string $url, int $maxBytes) use (&$sources, &$requests): string {
        $name = basename(parse_url($url, PHP_URL_PATH));
        $requests[] = $name;
        return $sources[$name];
    };
    try {
        $gateway = new ChmiGateway($directory, $transport, $now);
        $provider = new KopistyService($gateway);
        $official = $provider->build($now);
        check($official['station']['name'] === 'Kopisty' && $official['station']['elevation'] === 240.0
            && $official['station']['latitude'] === 50.54417, 'decode decimal comma in current station metadata');
        check(count($requests) === 5 && count($official['items']) === 6, 'bounded five source requests and exact station-element selection');
        check($official['items'][1]['temp_c'] === 2.5 && $official['items'][2]['temp_c'] === 99.0
            && $official['items'][3]['temp_c'] === 9.0, 'preserve raw values with quality; aggregation decides eligibility');
        check($official['items'][4]['element'] === 'F' && $official['items'][4]['wind_speed'] === 3.5
            && $official['items'][5]['precip_mm'] === 0.2, 'provider parses official wind and rain with verified metadata units');
        check($official['items'][2]['quality'] === 5.0 && $official['items'][3]['flag'] === 'X', 'preserve quality and flags before aggregation');
        $merged = $service->build($local, $official, $now);
        check($merged['meta']['kopistyQualityCounts']['5']['count'] === 1
            && $merged['meta']['kopistyFlagCounts']['X'] === 1, 'API exposes quality and flag counts for selected period');
        $sources['10m-0-20000-0-11433-20261025.json'] = '{broken';
        $gateway->invalidate('kopisty_20261025');
        $partialDay = $provider->build($now);
        check(count($partialDay['items']) === 1 && count($partialDay['meta']['warnings']) === 1,
            'malformed day JSON preserves other day and reports failure');
        $blocked = false;
        try {
            $gateway->fetch('kopisty_20200101');
        } catch (RuntimeException $exception) {
            $blocked = true;
        }
        check($blocked, 'gateway disallows arbitrary dates outside fixed two-day window');
        $sources['meta2-20261025.json'] = $table('OBS_TYPE,WSI,EG_EL_ABBREVIATION,UN_DESCRIPTION', [['10M', KopistyService::WSI, 'T', 'K']]);
        $gateway->invalidate('climate_meta2_20261025');
        $failed = false;
        try {
            $provider->build($now);
        } catch (RuntimeException $exception) {
            $failed = true;
        }
        check($failed, 'reject undocumented temperature unit rather than assume Celsius');
        return 30;
    } finally {
        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match('/^(climate_meta[124]|kopisty)_\d{8}\.json$/', $name) === 1) {
                unlink($directory . DIRECTORY_SEPARATOR . $name);
            }
        }
        rmdir($directory);
    }
};
