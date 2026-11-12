<?php
/**
 * CRON fallback: read WU only after 10 minutes without fresh direct uploads.
 * Deploy beside weather-ingest.php; configure secrets in ../config.php.
 * URL: https://oubrix.eu/fetch_weather2.php?token=<FETCH_TOKEN>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Type: text/plain; charset=utf-8');
}
ini_set('display_errors', '0');
require_once __DIR__ . '/weather-ingest.php';

const FETCH_LOG = __DIR__ . '/../fetch_weather2.log';
const FETCH_TIMEOUT_SECONDS = 20;

function fetch_log(string $result, array $info = []): void
{
    weather_ingest_log(FETCH_LOG, $result, $info);
}

$lock = null;
try {
    require_once __DIR__ . '/../config.php';
    ini_set('display_errors', '0');
    if (PHP_SAPI !== 'cli') {
        $expected = defined('FETCH_TOKEN') ? (string) FETCH_TOKEN : '';
        $given = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
        if (strlen($expected) < 32 || !hash_equals($expected, $given)) {
            http_response_code(403);
            fetch_log('DENIED', ['reason' => 'cron_token']);
            exit;
        }
    }

    $started = microtime(true);
    $lock = fopen(sys_get_temp_dir() . '/fetch_weather2_' . md5(__FILE__) . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('cron_lock_unavailable');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fetch_log('SKIP', ['reason' => 'predchozi_beh_stale_bezi']);
        exit;
    }
    $pdo = WeatherIngest::connect();
    if (WeatherIngest::hasFreshDirect($pdo, (string) WU_STATION_ID, time())) {
        fetch_log('SKIP', ['reason' => 'direct_fresh']);
        exit;
    }
    $url = sprintf(
        'https://api.weather.com/v2/pws/observations/current?stationId=%s&format=json&units=m&numericPrecision=decimal&apiKey=%s',
        rawurlencode(WU_STATION_ID),
        rawurlencode(WU_API_KEY)
    );
    $context = stream_context_create(['http' => [
        'timeout' => FETCH_TIMEOUT_SECONDS,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\nAccept-Encoding: identity\r\n",
    ]]);
    $json = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int) $match[1];
        }
    }
    if ($json === false) {
        throw new RuntimeException('WU_download_failed');
    }
    if ($status === 204) {
        fetch_log('NODATA', ['reason' => 'WU_nema_aktualni_mereni', 'http' => $status]);
        exit;
    }
    if ($status !== 200) {
        http_response_code(502);
        fetch_log('ERROR', ['reason' => 'WU_http', 'http' => $status]);
        exit;
    }
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $observation = $payload['observations'][0] ?? null;
    if (!is_array($observation)) {
        throw new InvalidArgumentException('invalid_WU_response');
    }
    $now = time();
    $row = WeatherIngest::underground($observation, (string) WU_STATION_ID, $now);
    // Rechecks direct freshness under the database lock: a station upload may
    // have arrived while the WU request was in flight.
    $result = WeatherIngest::store($pdo, $row, 'wu');
    fetch_log($result, [
        'source' => 'wu', 'obs' => $row['obs_time_utc'],
        'age_min' => (int) round(($now - $row['epoch']) / 60),
        'ms' => (int) ((microtime(true) - $started) * 1000),
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(502);
    fetch_log('ERROR', ['reason' => $e->getMessage()]);
} catch (Throwable $e) {
    ini_set('display_errors', '0');
    http_response_code(503);
    error_log('fetch_weather2: ' . get_class($e) . ' code=' . $e->getCode());
    $reason = in_array($e->getMessage(), [
        'cron_lock_unavailable', 'WU_download_failed', 'ingest_tables_require_InnoDB', 'missing_linked_observation',
    ], true) ? $e->getMessage() : 'configuration_database_or_WU';
    fetch_log('ERROR', ['reason' => $reason, 'code' => $e->getCode()]);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
