<?php
/**
 * GET /Weather/public/api/windrose.php?hours=168
 *
 * Větrná růžice za posledních N hodin (výchozí 7 dní): podíl měření (%)
 * v 16 směrech × třídách rychlosti, bezvětří, převládající směr.
 *
 * Parametr ?hours=N  1..series_max_hours (config/app.php), výchozí 168.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\Api;
use OuBrix\Weather\Http\Validation\ObservationQuery;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\WindRoseService;

Api::run(static function (PDO $pdo, string $station) {
    $settings = weather_settings();
    $maxHours = (int) $settings['series_max_hours'];
    $hours = ObservationQuery::hours($_GET, $maxHours, $maxHours);
    $service = new WindRoseService(new ObservationRepository($pdo));
    return $service->build($station, $hours, new DateTimeImmutable('now', new DateTimeZone('UTC')), (int) $settings['history_raw_max_rows']);
});
