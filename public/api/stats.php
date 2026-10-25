<?php
/**
 * GET /Weather/public/api/stats.php?year=2026
 *
 * Statistiky a rekordy:
 *   year  RRRR (výchozí = letošní rok) → řádky po měsících
 *         all                          → řádky po letech za celé období měření
 *
 * Vrací měsíční/roční souhrny, absolutní extrémy s časem, žebříčky
 * nej… dnů (top 5) a seznam let s daty. Chyba parametrů → 400.
 * Bez cache („no-store“) – viz poznámka v current.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\Api;
use OuBrix\Weather\Http\Validation\StatsQuery;
use OuBrix\Weather\Weather\Repositories\AggregateCache;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Repositories\WeatherAggregateRepository;
use OuBrix\Weather\Weather\Services\StatsService;

Api::run(static function (PDO $pdo, string $station) {
    $settings = weather_settings();
    $timezone = new DateTimeZone((string) $settings['timezone']);
    $now = new DateTimeImmutable('now', $timezone);
    $query = StatsQuery::parse($_GET, $now, $settings);

    $repository = new ObservationRepository($pdo);
    $dailyAggregates = !empty($settings['weather_daily_aggregate_cache'])
        ? new WeatherAggregateRepository($pdo, $timezone)
        : null;
    $service = new StatsService(
        $repository,
        $timezone,
        AggregateCache::fromSettings($repository, $timezone, $settings),
        $dailyAggregates
    );
    return $service->build($station, $query['period'], $now);
});
