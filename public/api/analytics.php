<?php
/**
 * GET /Weather/public/api/analytics.php
 *
 * Naměřené srážkové úhrny za 30, 60 a 90 místních kalendářních dní.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\Api;
use OuBrix\Weather\Weather\Repositories\AggregateCache;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Repositories\WeatherAggregateRepository;
use OuBrix\Weather\Weather\Services\AnalyticsService;
use OuBrix\Weather\Weather\Services\HistoryService;

Api::run(static function (PDO $pdo, string $station) {
    $settings = weather_settings();
    $timezone = new DateTimeZone((string) $settings['timezone']);
    $repository = new ObservationRepository($pdo);
    $dailyAggregates = !empty($settings['weather_daily_aggregate_cache'])
        ? new WeatherAggregateRepository($pdo, $timezone)
        : null;
    $history = new HistoryService(
        $repository,
        $timezone,
        AggregateCache::fromSettings($repository, $timezone, $settings),
        $dailyAggregates
    );
    return (new AnalyticsService($history, $timezone))->build(
        $station,
        new DateTimeImmutable('now', $timezone)
    );
});
