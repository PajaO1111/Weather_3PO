<?php
/**
 * Router pro vestavěný PHP server při lokálním testu BEZ produkční DB:
 *   php -S 127.0.0.1:8765 -t public tests/support/router.php
 *
 * Spouští skutečné soubory z public/, jen jim podstrčí SQLite data z Fixtures.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require $root . '/bootstrap.php';
require __DIR__ . '/Fixtures.php';

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strncmp($path, '/assets/', 8) === 0) {
    return false; // statické soubory obslouží vestavěný server
}

// Stejné konstanty, jaké definuje produkční config.php (bez hesel).
define('WU_STATION_ID', 'TEST');
// Testovací data nesmí skončit ve skutečné cache (storage/cache).
putenv('WEATHER_CACHE_DIR=off');
OuBrix\Weather\Infrastructure\Database::useConnection(Fixtures::browserDatabase('TEST'));

$scripts = ['/' => '/index.php', '/index.php' => '/index.php', '/api/current.php' => '/api/current.php',
    '/api/observations.php' => '/api/observations.php', '/api/health.php' => '/api/health.php',
    '/history.php' => '/history.php', '/api/history.php' => '/api/history.php',
    '/stats.php' => '/stats.php', '/api/stats.php' => '/api/stats.php'];
if (!isset($scripts[$path])) {
    http_response_code(404);
    echo 'Not found';
    return;
}
$_SERVER['SCRIPT_NAME'] = $scripts[$path];
require $root . '/public' . $scripts[$path];
