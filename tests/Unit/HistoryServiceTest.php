<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\HistoryCsv;
use OuBrix\Weather\Weather\Services\HistoryService;

return static function (): int {
    $tz = new DateTimeZone('Europe/Prague');
    $pdo = Fixtures::database();
    // 24. 10. (letní čas, UTC+2) a 25. 10. 2026 (přechod na zimní čas, den má 25 h).
    $rows = [
        ['2026-10-23 21:55:00', 30.0, 9.9],  // místně 23. 10. – mimo období
        ['2026-10-23 22:00:00', 5.0, 0.0],   // místně 24. 10. 00:00
        ['2026-10-23 22:30:00', 7.0, 0.5],
        ['2026-10-23 23:10:00', 3.0, 0.8],
        ['2026-10-24 21:50:00', 10.0, 2.0],  // místně 24. 10. 23:50
        ['2026-10-24 22:05:00', -1.0, 0.3],  // místně 25. 10. 00:05, čítač se vynuloval
        ['2026-10-25 22:30:00', 26.0, 1.5],  // místně 25. 10. 23:30 (už SEČ)
        ['2026-10-25 23:10:00', 4.0, 0.0],   // místně 26. 10. – mimo období
    ];
    foreach ($rows as $i => [$time, $temperature, $precip]) {
        $row = Fixtures::row($i + 1, $time, $temperature);
        $row['precip_total'] = $precip;
        $row['wind_gust'] = $i === 4 ? 40.0 : 12.0;
        Fixtures::insert($pdo, $row);
    }
    Fixtures::insert($pdo, Fixtures::row(99, '2026-10-24 12:00:00', 50.0, 'OTHER'));

    $service = new HistoryService(new ObservationRepository($pdo), $tz);
    $from = new DateTimeImmutable('2026-10-24', $tz);
    $to = new DateTimeImmutable('2026-10-25', $tz);

    $hour = $service->build('TEST', $from, $to, 'hour', 100);
    check($hour['range']['from'] === '2026-10-23T22:00:00Z' && $hour['range']['to'] === '2026-10-25T23:00:00Z' && $hour['range']['days'] === 2, 'range in UTC across DST');
    check(count($hour['items']) === 5 && $hour['items'][0]['time'] === '2026-10-23T22:00:00Z', 'hourly buckets');
    check($hour['items'][0]['samples'] === 2 && $hour['items'][0]['temp_avg'] === 6.0 && $hour['items'][0]['temp_min'] === 5.0 && $hour['items'][0]['temp_max'] === 7.0, 'hour aggregates');
    check(array_column($hour['items'], 'precip') === [0.5, 0.3, 1.2, 0.3, 1.2], 'hourly precip from daily counter with reset');

    $day = $service->build('TEST', $from, $to, 'day', 100);
    $items = $day['items'];
    check(count($items) === 2 && $items[0]['date'] === '2026-10-24' && $items[1]['date'] === '2026-10-25', 'local days');
    check($items[1]['time'] === '2026-10-24T22:00:00Z' && $items[0]['samples'] === 4 && $items[1]['samples'] === 2, 'day start and samples');
    check($items[0]['temp_min'] === 3.0 && $items[0]['temp_max'] === 10.0 && $items[0]['precip'] === 2.0 && $items[1]['precip'] === 1.5, 'day extremes and precip');

    $summary = $day['summary'];
    check($summary['samples'] === 6 && $summary['precip_sum'] === 3.5, 'summary totals');
    check($summary['precip_wettest_day'] === ['value' => 2.0, 'date' => '2026-10-24'], 'wettest day');
    check($summary['temp_max'] === ['value' => 26.0, 'time' => '2026-10-25T22:30:00Z'] && $summary['temp_min']['time'] === '2026-10-24T22:05:00Z', 'temperature extremes with time');
    check($summary['gust_max']['value'] === 40.0, 'gust max');
    check($summary['days']['withData'] === 2 && $summary['days']['frost'] === 1 && $summary['days']['summer'] === 1 && $summary['days']['rain'] === 2 && $summary['days']['ice'] === 0, 'climate days');

    $raw = $service->build('TEST', $from, $to, 'raw', 100);
    check($raw['resolution'] === 'raw' && count($raw['items']) === 6 && $raw['items'][0]['temp_c'] === 5.0 && $raw['meta']['truncated'] === false, 'raw items');
    check($service->build('TEST', $from, $to, 'raw', 3)['meta']['truncated'] === true, 'raw row cap');

    $empty = $service->build('TEST', new DateTimeImmutable('2026-09-01', $tz), new DateTimeImmutable('2026-09-02', $tz), 'day', 100);
    check($empty['items'] === [] && $empty['summary']['samples'] === 0 && $empty['summary']['temp_max'] === null && $empty['summary']['precip_sum'] === null, 'empty period');

    // CSV: BOM, středník, desetinná čárka, místní čas, název souboru.
    $csv = HistoryCsv::response($hour, $tz);
    $body = $csv->body();
    $lines = explode("\r\n", rtrim($body, "\r\n"));
    check(strpos($body, "\xEF\xBB\xBF") === 0 && count($lines) === 6, 'csv bom and rows');
    check(strpos($lines[0], 'Čas (místní);Počet měření;Teplota průměr (°C)') !== false, 'csv header');
    check(strpos($lines[1], '2026-10-24 00:00;2;6,0;5,0;7,0') === 0, 'csv local time and decimal comma');
    check(HistoryCsv::number(-0.04, 1) === '0,0' && HistoryCsv::number(null, 1) === '', 'csv number format');
    return 18;
};
