<?php
/**
 * GET /Weather/public/api/history.php?from=2026-10-01&to=2026-10-23&resolution=auto
 *
 * Měření za zvolené období (místní data včetně obou krajních dnů).
 *
 * Parametry (všechny volitelné):
 *   from, to    RRRR-MM-DD; výchozí = posledních 7 dní
 *   resolution  auto (výchozí) | raw (po 5 min) | hour | day
 *   format      json (výchozí) | csv (soubor ke stažení pro Excel)
 *
 * Limity délky období jsou v config/app.php (history_*). Chyba parametrů → 400.
 * Bez cache („no-store“) – viz poznámka v current.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\Api;
use OuBrix\Weather\Http\Validation\HistoryQuery;
use OuBrix\Weather\Weather\Repositories\AggregateCache;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\HistoryCsv;
use OuBrix\Weather\Weather\Services\HistoryService;

Api::run(static function (PDO $pdo, string $station) {
    $settings = weather_settings();
    $timezone = new DateTimeZone((string) $settings['timezone']);
    $query = HistoryQuery::parse($_GET, new DateTimeImmutable('now', $timezone), $settings);

    $repository = new ObservationRepository($pdo);
    $service = new HistoryService($repository, $timezone, AggregateCache::fromSettings($repository, $timezone, $settings));
    $history = $service->build($station, $query['from'], $query['to'], $query['resolution'], (int) $settings['history_raw_max_rows']);

    return $query['format'] === 'csv' ? HistoryCsv::response($history, $timezone) : $history;
});
