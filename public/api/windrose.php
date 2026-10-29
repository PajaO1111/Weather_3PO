<?php
/**
 * GET /Weather/public/api/windrose.php?hours=168
 * GET /Weather/public/api/windrose.php?from=2026-10-19&to=2026-10-25
 *
 * Větrná růžice za posledních N hodin (výchozí 7 dní) nebo za zvolené
 * místní dny včetně obou krajních (nejvýš 7 dní, např. kalendářní týden):
 * podíl měření (%) v 16 směrech × třídách rychlosti, bezvětří, převládající směr.
 *
 * Parametr ?hours=N  1..series_max_hours (config/app.php), výchozí 168.
 * Parametry ?from=&to=  RRRR-MM-DD (oba povinné, nelze kombinovat s hours).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\Api;
use OuBrix\Weather\Http\Validation\HistoryQuery;
use OuBrix\Weather\Http\Validation\ObservationQuery;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\WindRoseService;

const WINDROSE_MAX_DAYS = 7;

Api::run(static function (PDO $pdo, string $station) {
    $settings = weather_settings();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $service = new WindRoseService(new ObservationRepository($pdo));
    $maxRows = (int) $settings['history_raw_max_rows'];

    if (array_key_exists('from', $_GET) || array_key_exists('to', $_GET)) {
        if (!array_key_exists('from', $_GET) || !array_key_exists('to', $_GET) || array_key_exists('hours', $_GET)) {
            throw new InvalidArgumentException('Zadejte oba parametry from a to, nebo pouze hours.');
        }
        $range = HistoryQuery::parse(['from' => $_GET['from'], 'to' => $_GET['to']], $now, $settings);
        if ($range['days'] > WINDROSE_MAX_DAYS) {
            throw new InvalidArgumentException('Období růžice může mít nejvýš ' . WINDROSE_MAX_DAYS . ' dní.');
        }
        // Konec = místní půlnoc po posledním dni; budoucnost se nedotazuje.
        $end = $range['to']->add(new DateInterval('P1D'));
        $rose = $service->buildRange($station, $range['from'], $end < $now ? $end : $now, $maxRows);
        $rose['fromDate'] = $range['from']->format('Y-m-d');
        $rose['toDate'] = $range['to']->format('Y-m-d');
        return $rose;
    }

    $maxHours = (int) $settings['series_max_hours'];
    $hours = ObservationQuery::hours($_GET, $maxHours, $maxHours);
    return $service->build($station, $hours, $now, $maxRows);
});
