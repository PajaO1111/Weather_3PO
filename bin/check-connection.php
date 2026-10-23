<?php
/**
 * CLI kontrola připojení (spouští se přes SSH / plánovač, NE přes prohlížeč):
 *   php bin/check-connection.php
 *
 * Vypíše jen bezpečné údaje: verzi PHP, fázi a poslední čas měření.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use OuBrix\Weather\Infrastructure\Database;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;

$stage = 'start';
try {
    $pdo = Database::connection(WEATHER_ROOT, $stage);
    $stage = 'query';
    $latest = (new ObservationRepository($pdo))->latest(Database::stationId());
    echo 'PHP ' . PHP_VERSION . PHP_EOL;
    echo 'OK: pripojeni funguje, stanice ' . Database::stationId() . PHP_EOL;
    echo 'Posledni mereni (UTC): ' . ($latest === null ? 'zadne' : $latest['obs_time_utc']) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'CHYBA: ' . json_encode(Database::failureDetails($exception, $stage)) . PHP_EOL);
    exit(1);
}
