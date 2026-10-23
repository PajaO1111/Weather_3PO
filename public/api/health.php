<?php
/**
 * GET /Weather/public/api/health.php
 *
 * Diagnostika nasazení bez prozrazení tajných údajů:
 * verze PHP, dostupnost PDO MySQL, nalezení config.php a test čtení z DB.
 * Slouží k rychlému ověření po nahrání souborů na hosting.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\JsonResponse;
use OuBrix\Weather\Infrastructure\Database;
use OuBrix\Weather\Weather\Repositories\AggregateCache;

$report = [
    'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    'pdoMysql' => extension_loaded('pdo_mysql'),
    'config' => 'unknown',
    'database' => 'not_tested',
    // Souborová cache souhrnů: ok | disabled | not_writable (pak vše živě z DB, jen pomaleji).
    'cache' => AggregateCache::status(weather_settings()),
];

$stage = 'start';
try {
    $pdo = Database::connection(WEATHER_ROOT, $stage);
    $report['config'] = 'ok';
    $stage = 'read_test';
    // Stejný typ dotazu jako původní history.php – jen ověří, že tabulka je čitelná.
    $statement = $pdo->prepare('SELECT obs_time_utc FROM pws_observations WHERE station_id = :station ORDER BY obs_time_utc DESC LIMIT 1');
    $statement->bindValue(':station', Database::stationId(), PDO::PARAM_STR);
    $statement->execute();
    $latest = $statement->fetchColumn();
    $report['database'] = 'ok';
    $report['latestObservationUtc'] = $latest === false ? null : (string) $latest;
    JsonResponse::send(['status' => 'ok'] + $report);
} catch (Throwable $exception) {
    $details = Database::failureDetails($exception, $stage);
    if ($stage !== 'configuration') {
        $report['config'] = 'ok';
    }
    $report['database'] = $stage === 'configuration' ? 'not_tested' : 'failed';
    error_log('OuBrix Weather health: ' . get_class($exception) . ' ' . json_encode($details));
    JsonResponse::send(['status' => 'error'] + $report + ['diagnostics' => $details], 503);
}
