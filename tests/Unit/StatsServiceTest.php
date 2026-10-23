<?php
declare(strict_types=1);

use OuBrix\Weather\Http\Validation\StatsQuery;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\StatsService;

return static function (): int {
    $tz = new DateTimeZone('Europe/Prague');
    $settings = ['timezone' => 'Europe/Prague'];
    $now = new DateTimeImmutable('2026-03-15 12:00:00', $tz);

    // --- StatsQuery ---
    check(StatsQuery::parse([], $now, $settings) === ['period' => '2026'], 'default year');
    check(StatsQuery::parse(['year' => 'all'], $now, $settings) === ['period' => 'all'], 'all period');
    check(StatsQuery::parse(['year' => '2025'], $now, $settings) === ['period' => '2025'], 'explicit year');
    foreach (['2027', '1999', '25', '2025x', ['2025'], ''] as $invalid) {
        try {
            StatsQuery::parse(['year' => $invalid], $now, $settings);
            check(false, 'invalid year accepted: ' . json_encode($invalid));
        } catch (InvalidArgumentException $exception) {
            // očekáváno
        }
    }

    // --- StatsService ---
    $pdo = Fixtures::database();
    $id = 0;
    // Celý místní den: 24 hodinových měření, teplota lineárně min→max, čítač srážek skokem na precip.
    $fullDay = static function (string $date, float $min, float $max, float $precip, float $gust) use ($pdo, $tz, &$id): void {
        $start = new DateTimeImmutable($date . ' 00:00:00', $tz);
        for ($h = 0; $h < 24; $h++) {
            $time = $start->modify("+$h hours")->setTimezone(new DateTimeZone('UTC'));
            $row = Fixtures::row(++$id, $time->format('Y-m-d H:i:s'), round($min + ($max - $min) * $h / 23, 3));
            $row['precip_total'] = $h >= 20 ? $precip : 0.0;
            $row['wind_gust'] = $h === 12 ? $gust : 5.0;
            Fixtures::insert($pdo, $row);
        }
    };
    $fullDay('2025-07-10', 21.0, 31.0, 5.0, 60.0);   // tropický den i noc, letní, srážkový
    $fullDay('2026-01-15', -5.0, -1.0, 0.0, 30.0);   // mrazový i ledový
    $fullDay('2026-02-02', 0.0, 10.0, 12.0, 45.0);   // srážkový
    // Neúplný den 5. 12. 2025 (3 měření v noci): mrazový ano, ledový ne (neúplný).
    foreach (['2025-12-04 23:00:00' => -2.0, '2025-12-05 00:00:00' => -1.5, '2025-12-05 01:00:00' => -1.0] as $time => $t) {
        $row = Fixtures::row(++$id, $time, $t);
        $row['precip_total'] = 0.0;
        Fixtures::insert($pdo, $row);
    }
    Fixtures::insert($pdo, Fixtures::row(++$id, '2024-06-01 12:00:00', 40.0, 'OTHER'));

    $repository = new ObservationRepository($pdo);
    check($repository->bounds('TEST') === ['first' => '2025-07-09 22:00:00', 'last' => '2026-02-02 22:00:00'], 'bounds');
    check($repository->bounds('NONE') === null, 'bounds without data');

    $service = new StatsService($repository, $tz);

    $year = $service->build('TEST', '2026', $now);
    check($year['period'] === ['type' => 'year', 'year' => 2026, 'group' => 'month', 'fromDate' => '2026-01-01', 'toDate' => '2026-03-15'], 'year period capped at today');
    check($year['years'] === [2025, 2026] && $year['dataFrom'] === '2025-07-10' && $year['dataTo'] === '2026-02-02', 'years with data');
    check(array_column($year['rows'], 'key') === ['2026-01', '2026-02', '2026-03'], 'month rows');
    check($year['rows'][0]['from'] === '2026-01-01' && $year['rows'][0]['to'] === '2026-01-31' && $year['rows'][2]['to'] === '2026-03-15', 'month row ranges');
    $jan = $year['rows'][0];
    check($jan['days'] === 1 && $jan['temp_avg'] === -3.0 && $jan['temp_max'] === ['value' => -1.0, 'date' => '2026-01-15'], 'january aggregates');
    check($jan['frost_days'] === 1 && $jan['ice_days'] === 1 && $jan['rain_days'] === 0 && $jan['precip'] === 0.0, 'january climate days');
    $feb = $year['rows'][1];
    check($feb['precip'] === 12.0 && $feb['rain_days'] === 1 && $feb['gust_max'] === ['value' => 45.0, 'date' => '2026-02-02'], 'february precip and gust');
    check($year['rows'][2]['days'] === 0 && $year['rows'][2]['temp_avg'] === null && $year['rows'][2]['precip'] === null, 'empty month');
    check($year['total']['days'] === 2 && $year['total']['precip'] === 12.0 && $year['total']['temp_min'] === ['value' => -5.0, 'date' => '2026-01-15'], 'year total');
    check($year['extremes']['temp_max']['value'] === 10.0 && $year['extremes']['gust_max']['value'] === 45.0, 'extremes with time');

    $all = $service->build('TEST', 'all', $now);
    check($all['period']['group'] === 'year' && $all['period']['fromDate'] === '2025-07-10' && $all['period']['toDate'] === '2026-02-02', 'all period range');
    check(array_column($all['rows'], 'key') === ['2025', '2026'] && $all['rows'][0]['from'] === '2025-07-10', 'year rows');
    $y2025 = $all['rows'][0];
    check($y2025['days'] === 2 && $y2025['days_complete'] === 1 && $y2025['frost_days'] === 1 && $y2025['ice_days'] === 0, 'incomplete day: frost yes, ice no');
    check($y2025['tropical_days'] === 1 && $y2025['tropical_nights'] === 1 && $y2025['summer_days'] === 1, 'tropical and summer days');
    check($y2025['temp_avg'] === 26.0, 'average only from complete days');
    check($all['total']['days'] === 4 && $all['total']['precip'] === 17.0 && $all['total']['rain_days'] === 2, 'all total');

    $records = $all['records'];
    check(array_column($records['temp_max_high'], 'date') === ['2025-07-10', '2026-02-02', '2025-12-05', '2026-01-15'], 'warmest days order');
    check($records['temp_min_low'][0] === ['date' => '2026-01-15', 'value' => -5.0], 'coldest night');
    check(count($records['temp_avg_high']) === 3 && !in_array('2025-12-05', array_column($records['temp_avg_high'], 'date'), true), 'complete days only for means');
    check(array_column($records['precip_high'], 'value') === [12.0, 5.0], 'zero precipitation is not a record');
    check($records['range_high'][0] === ['date' => '2025-07-10', 'value' => 10.0] && $records['gust_high'][0]['value'] === 60.0, 'range and gust records');

    $empty = $service->build('NONE', '2026', $now);
    check($empty['years'] === [] && $empty['total']['days'] === 0 && $empty['extremes']['temp_max'] === null && count($empty['rows']) === 3, 'station without data');
    return 28;
};
