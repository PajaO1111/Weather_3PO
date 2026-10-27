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

    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weather-kopisty-' . bin2hex(random_bytes(6));
    $table = static function (string $header, array $values): string {
        return json_encode(['datumVytvoreni' => '2026-10-25T00:01:00.000Z', 'verzeDat' => '1.0',
            'data' => ['data' => ['header' => $header, 'values' => $values]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    };
    $sources = [
        'meta1-20261025.json' => $table('WSI,FULL_NAME,GEOGR1,GEOGR2,ELEVATION', [[KopistyService::WSI, 'Kopisty', '13,62278', '50,54417', '240,0']]),
        'meta2-20261025.json' => $table('OBS_TYPE,WSI,EG_EL_ABBREVIATION,UN_DESCRIPTION', [['10M', KopistyService::WSI, 'T', '°C']]),
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
        check(count($requests) === 5 && count($official['items']) === 4, 'bounded five source requests and exact station-element selection');
        check($official['items'][1]['temp_c'] === 2.5 && $official['items'][2]['temp_c'] === null
            && $official['items'][3]['temp_c'] === null, 'allow Good only; exclude Unknown and flagged values');
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
        return 16;
    } finally {
        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match('/^(climate_meta[124]|kopisty)_\d{8}\.json$/', $name) === 1) {
                unlink($directory . DIRECTORY_SEPARATOR . $name);
            }
        }
        rmdir($directory);
    }
};
