<?php
/**
 * GET /Weather/public/api/current.php
 *
 * Aktuální stav stanice: poslední měření, údaje o stanici, stáří dat,
 * tlaková tendence a souhrn dnešního dne.
 *
 * Posílá se „no-store“: hosting WEDOS ke každé cachovatelné odpovědi přidává
 * max-age=3600 a jeho CDN by pak hodinu servírovala stará data.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\Api;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\CurrentConditionsService;

Api::run(static function (PDO $pdo, string $station) {
    $service = new CurrentConditionsService(new ObservationRepository($pdo), weather_settings());
    return $service->build($station, new DateTimeImmutable('now', new DateTimeZone('UTC')));
});
