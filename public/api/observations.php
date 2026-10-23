<?php
/**
 * GET /Weather/public/api/observations.php?hours=24
 *
 * Časová řada měření pro graf, seřazená od nejstaršího.
 *
 * Parametry (vzájemně se vylučují, výchozí je hours=24):
 *   ?hours=N  měření za posledních N hodin (1..series_max_hours v config/app.php)
 *   ?limit=N  posledních N řádků bez ohledu na čas (starší režim)
 *
 * Bez cache („no-store“) – viz poznámka v current.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\Api;
use OuBrix\Weather\Http\Validation\ObservationQuery;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\ObservationService;

Api::run(static function (PDO $pdo, string $station) {
    $settings = weather_settings();
    $service = new ObservationService(new ObservationRepository($pdo));
    if (array_key_exists('limit', $_GET)) {
        if (array_key_exists('hours', $_GET)) {
            throw new InvalidArgumentException('Použijte buď hours, nebo limit – ne obojí.');
        }
        $limit = ObservationQuery::limit($_GET, (int) $settings['series_default_limit'], (int) $settings['series_max_limit']);
        return $service->series($station, $limit);
    }
    $hours = ObservationQuery::hours($_GET, (int) $settings['series_default_hours'], (int) $settings['series_max_hours']);
    return $service->window($station, $hours, new DateTimeImmutable('now', new DateTimeZone('UTC')), (int) $settings['series_max_rows']);
});
