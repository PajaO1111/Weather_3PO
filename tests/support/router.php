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
$database = Fixtures::browserDatabase('TEST');
$database->exec('CREATE TABLE weather_daily (
    station_id TEXT NOT NULL, local_date TEXT NOT NULL, tmin NUMERIC NULL, tmax NUMERIC NULL,
    tavg NUMERIC NULL, dew_point_avg NUMERIC NULL, humidity_min NUMERIC NULL, humidity_max NUMERIC NULL,
    humidity_avg NUMERIC NULL, pressure_min NUMERIC NULL, pressure_max NUMERIC NULL, pressure_avg NUMERIC NULL,
    wind_avg NUMERIC NULL, wind_max NUMERIC NULL, gust_max NUMERIC NULL, rain_sum NUMERIC NULL,
    precip_rate_max NUMERIC NULL, uv_max NUMERIC NULL, radiation_max NUMERIC NULL, solar_avg NUMERIC NULL,
    solar_max NUMERIC NULL, sample_count INTEGER NOT NULL DEFAULT 0, hours_count INTEGER NOT NULL DEFAULT 0,
    temp_sample_count INTEGER NOT NULL DEFAULT 0, dew_point_sample_count INTEGER NOT NULL DEFAULT 0,
    humidity_sample_count INTEGER NOT NULL DEFAULT 0, pressure_sample_count INTEGER NOT NULL DEFAULT 0,
    wind_sample_count INTEGER NOT NULL DEFAULT 0, solar_sample_count INTEGER NOT NULL DEFAULT 0,
    calculated_at TEXT NOT NULL, PRIMARY KEY (station_id, local_date))');
$database->exec('CREATE TABLE weather_monthly (
    station_id TEXT NOT NULL, year_num INTEGER NOT NULL, month_num INTEGER NOT NULL,
    tmin NUMERIC NULL, tmax NUMERIC NULL, tavg NUMERIC NULL, rain_sum NUMERIC NULL,
    wind_avg NUMERIC NULL, wind_max NUMERIC NULL, sample_count INTEGER NOT NULL,
    temp_sample_count INTEGER NOT NULL, wind_sample_count INTEGER NOT NULL, calculated_at TEXT NOT NULL,
    PRIMARY KEY (station_id, year_num, month_num))');
$database->exec('CREATE TABLE weather_yearly (
    station_id TEXT NOT NULL, year_num INTEGER NOT NULL, tmin NUMERIC NULL, tmax NUMERIC NULL,
    tavg NUMERIC NULL, rain_sum NUMERIC NULL, wind_max NUMERIC NULL, sample_count INTEGER NOT NULL,
    temp_sample_count INTEGER NOT NULL, wind_sample_count INTEGER NOT NULL, calculated_at TEXT NOT NULL,
    PRIMARY KEY (station_id, year_num))');
OuBrix\Weather\Infrastructure\Database::useConnection($database);
require_once __DIR__ . '/PhenologyFixtures.php';
PhenologyFixtures::seed($database);

$scripts = ['/' => '/index.php', '/index.php' => '/index.php', '/api/current.php' => '/api/current.php',
    '/api/observations.php' => '/api/observations.php', '/api/health.php' => '/api/health.php',
    '/history.php' => '/history.php', '/api/history.php' => '/api/history.php',
    '/analytics.php' => '/analytics.php', '/api/analytics.php' => '/api/analytics.php',
    '/air-quality.php' => '/air-quality.php', '/api/air-quality.php' => '/api/air-quality.php',
    '/api/air-quality-history.php' => '/api/air-quality-history.php',
    '/api/air-quality-forecast.php' => '/api/air-quality-forecast.php',
    '/api/air-quality-map.php' => '/api/air-quality-map.php',
    '/api/air-quality-five-year.php' => '/api/air-quality-five-year.php',
    '/comparison.php' => '/comparison.php', '/api/comparison.php' => '/api/comparison.php',
    '/climate.php' => '/climate.php', '/api/climate.php' => '/api/climate.php',
    '/phenology.php' => '/phenology.php', '/api/phenology.php' => '/api/phenology.php',
    '/forecast.php' => '/forecast.php', '/api/forecast.php' => '/api/forecast.php',
    '/warnings.php' => '/warnings.php', '/api/warnings.php' => '/api/warnings.php',
    '/radar.php' => '/radar.php', '/api/radar.php' => '/api/radar.php',
    '/stats.php' => '/stats.php', '/api/stats.php' => '/api/stats.php', '/api/windrose.php' => '/api/windrose.php'];
if (!isset($scripts[$path])) {
    http_response_code(404);
    echo 'Not found';
    return;
}
$_SERVER['SCRIPT_NAME'] = $scripts[$path];
require $root . '/public' . $scripts[$path];
