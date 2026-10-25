<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Repositories\WeatherAggregateRepository;
use OuBrix\Weather\Weather\Services\HistoryService;
use OuBrix\Weather\Weather\Services\StatsService;

return static function (): int {
    $pdo = Fixtures::database();
    $pdo->exec('CREATE TABLE weather_daily (
        station_id TEXT NOT NULL, local_date TEXT NOT NULL, tmin NUMERIC NULL, tmax NUMERIC NULL,
        tavg NUMERIC NULL, dew_point_avg NUMERIC NULL, humidity_min NUMERIC NULL, humidity_max NUMERIC NULL,
        humidity_avg NUMERIC NULL, pressure_min NUMERIC NULL, pressure_max NUMERIC NULL, pressure_avg NUMERIC NULL,
        wind_avg NUMERIC NULL, wind_max NUMERIC NULL, gust_max NUMERIC NULL, rain_sum NUMERIC NULL,
        precip_rate_max NUMERIC NULL, uv_max NUMERIC NULL, radiation_max NUMERIC NULL, solar_avg NUMERIC NULL,
        solar_max NUMERIC NULL, sample_count INTEGER NOT NULL DEFAULT 0, hours_count INTEGER NOT NULL DEFAULT 0,
        temp_sample_count INTEGER NOT NULL DEFAULT 0, dew_point_sample_count INTEGER NOT NULL DEFAULT 0,
        humidity_sample_count INTEGER NOT NULL DEFAULT 0, pressure_sample_count INTEGER NOT NULL DEFAULT 0,
        wind_sample_count INTEGER NOT NULL DEFAULT 0, solar_sample_count INTEGER NOT NULL DEFAULT 0,
        calculated_at TEXT NOT NULL, PRIMARY KEY (station_id, local_date))');
    $pdo->exec('CREATE TABLE weather_monthly (
        station_id TEXT NOT NULL, year_num INTEGER NOT NULL, month_num INTEGER NOT NULL,
        tmin NUMERIC NULL, tmax NUMERIC NULL, tavg NUMERIC NULL, rain_sum NUMERIC NULL,
        wind_avg NUMERIC NULL, wind_max NUMERIC NULL, sample_count INTEGER NOT NULL,
        temp_sample_count INTEGER NOT NULL, wind_sample_count INTEGER NOT NULL, calculated_at TEXT NOT NULL,
        PRIMARY KEY (station_id, year_num, month_num))');
    $pdo->exec('CREATE TABLE weather_yearly (
        station_id TEXT NOT NULL, year_num INTEGER NOT NULL, tmin NUMERIC NULL, tmax NUMERIC NULL,
        tavg NUMERIC NULL, rain_sum NUMERIC NULL, wind_max NUMERIC NULL, sample_count INTEGER NOT NULL,
        temp_sample_count INTEGER NOT NULL, wind_sample_count INTEGER NOT NULL, calculated_at TEXT NOT NULL,
        PRIMARY KEY (station_id, year_num))');

    $tz = new DateTimeZone('Europe/Prague');
    $utc = new DateTimeZone('UTC');
    $observations = [
        ['2026-03-27 23:05:00', 4.0, 0.0],
        ['2026-03-28 23:10:00', 5.5, 0.2],
        ['2026-03-29 22:10:00', 8.5, 0.0],
        ['2026-03-29 22:40:00', 9.5, 0.4],
    ];
    foreach ($observations as $index => [$time, $temperature, $rain]) {
        $row = Fixtures::row($index + 1, $time, $temperature);
        $row['precip_total'] = $rain;
        Fixtures::insert($pdo, $row);
    }
    $missingMetrics = Fixtures::row(5, '2026-03-29 22:50:00', null);
    foreach (['dew_point', 'humidity', 'pressure', 'wind_speed', 'uv', 'solar_radiation', 'precip_rate', 'precip_total'] as $column) {
        $missingMetrics[$column] = null;
    }
    Fixtures::insert($pdo, $missingMetrics);

    $repository = new ObservationRepository($pdo);
    $now = new DateTimeImmutable('2026-04-05 12:00:00', $utc);
    $daily = new WeatherAggregateRepository($pdo, $tz, $now);
    $from = new DateTimeImmutable('2026-03-28', $tz);
    $to = new DateTimeImmutable('2026-03-31', $tz);
    $plain = (new HistoryService($repository, $tz))->build('TEST', $from, $to, 'day', 100);
    $cachedService = new HistoryService($repository, $tz, null, $daily);
    $cached = $cachedService->build('TEST', $from, $to, 'day', 100);
    check($cached == $plain, 'daily aggregate cache matches live output across DST');

    $stored = $daily->days('TEST', '2026-03-28', '2026-03-31');
    check(count($stored) === 4 && (int) $stored['2026-03-31']['sample_count'] === 0, 'empty settled day stored as marker');
    check((int) $stored['2026-03-29']['hours_count'] === 1 && (int) $stored['2026-03-29']['sample_count'] === 1, 'daily sample and hour counts stored');
    check((int) $stored['2026-03-30']['sample_count'] === 3 && (int) $stored['2026-03-30']['temp_sample_count'] === 2, 'null metrics retain their independent sample counts');
    $monthly = $pdo->query('SELECT * FROM weather_monthly WHERE station_id = "TEST"')->fetch(PDO::FETCH_ASSOC);
    $yearly = $pdo->query('SELECT * FROM weather_yearly WHERE station_id = "TEST"')->fetch(PDO::FETCH_ASSOC);
    check($monthly !== false && (int) $monthly['sample_count'] === 5, 'monthly aggregate refreshed from daily rows');
    check($yearly !== false && (int) $yearly['sample_count'] === 5, 'yearly aggregate refreshed from daily rows');

    $plainStats = (new StatsService($repository, $tz))->build('TEST', '2026', $now);
    $cachedStats = (new StatsService($repository, $tz, null, $daily))->build('TEST', '2026', $now);
    check($cachedStats == $plainStats, 'statistics match live calculation using daily rows');

    // Při druhém běhu se hotové dny načtou z agregací, i když se zdrojová historie změnila.
    $pdo->exec('DELETE FROM pws_observations');
    $fromCachedRows = $cachedService->build('TEST', $from, $to, 'day', 100);
    check($fromCachedRows['items'] === $cached['items'], 'settled daily output is served from aggregate rows');

    $unsettled = new WeatherAggregateRepository(
        $pdo,
        $tz,
        new DateTimeImmutable('2026-04-01 10:00:00', $utc)
    );
    check(!$unsettled->isSettled('2026-03-31'), 'day inside the 24-hour settle window is not reusable');
    check($daily->isSettled('2026-03-31'), 'day past the settle window is reusable');
    return 8;
};
