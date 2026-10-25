<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\AnalyticsService;
use OuBrix\Weather\Weather\Services\HistoryService;

return static function (): int {
    $pdo = Fixtures::database();
    $observations = [
        ['2026-04-05 08:00:00', 1.2],
        ['2026-04-05 11:00:00', 2.0],
        ['2026-04-03 10:00:00', 0.0],
        ['2026-03-10 10:00:00', 1.5],
        ['2026-01-10 10:00:00', 5.0],
    ];
    foreach ($observations as $index => [$time, $rain]) {
        $row = Fixtures::row($index + 1, $time);
        $row['precip_total'] = $rain;
        Fixtures::insert($pdo, $row);
    }

    $timezone = new DateTimeZone('Europe/Prague');
    $now = new DateTimeImmutable('2026-04-05 12:00:00', $timezone);
    $history = new HistoryService(new ObservationRepository($pdo), $timezone);
    $result = (new AnalyticsService($history, $timezone))->build('TEST', $now);

    check($result['periods']['30']['rainfallMm'] === 3.5, '30-day measured rainfall total');
    check($result['periods']['60']['rainfallMm'] === 3.5, '60-day measured rainfall total');
    check($result['periods']['90']['rainfallMm'] === 8.5, '90-day measured rainfall total');
    check($result['periods']['30']['daysWithKnownPrecip'] === 3 && $result['periods']['30']['daysMissing'] === 27, 'known zero rainfall counts as observed; missing days remain missing');
    check($result['periods']['60']['coveragePercent'] === 5.0 && $result['periods']['90']['coveragePercent'] === 4.4, 'coverage percent reflects days with rainfall observations');
    check($result['periods']['30']['fromDate'] === '2026-03-07' && $result['periods']['30']['toDate'] === '2026-04-05', 'local inclusive window dates');
    check($result['meta']['timezone'] === 'Europe/Prague' && $result['meta']['lastObservation'] !== null, 'timezone and latest observation metadata');

    $emptyPdo = Fixtures::database();
    $emptyHistory = new HistoryService(new ObservationRepository($emptyPdo), $timezone);
    $empty = (new AnalyticsService($emptyHistory, $timezone))->build('TEST', $now);
    check($empty['periods']['30']['rainfallMm'] === null && $empty['periods']['30']['coveragePercent'] === 0.0, 'no observations are not represented as zero rainfall');
    check($empty['periods']['30']['daysMissing'] === 30, 'empty period explicitly reports missing days');

    return 9;
};
