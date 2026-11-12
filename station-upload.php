<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: 0');
ini_set('display_errors', '0');

require_once __DIR__ . '/weather-ingest.php';

/**
 * Why authentication failed, without logging any secret value:
 * only parameter names, lengths and match flags.
 *
 * @return array<string, string|int>
 */
function station_upload_auth_diagnostics(array $input, string $id, string $key): array
{
    $sentId = isset($input['ID']) && is_string($input['ID']) ? $input['ID'] : null;
    $sentKey = isset($input['PASSWORD']) && is_string($input['PASSWORD']) ? $input['PASSWORD'] : null;
    $keyState = 'missing';
    if ($sentKey !== null) {
        if (hash_equals($key, $sentKey)) {
            $keyState = 'ok';
        } elseif ($sentKey !== '' && strncmp($key, $sentKey, strlen($sentKey)) === 0) {
            $keyState = 'truncated';
        } elseif (trim($sentKey) !== $sentKey && hash_equals($key, trim($sentKey))) {
            $keyState = 'whitespace';
        } else {
            $keyState = 'mismatch';
        }
    }
    $names = array_slice(array_map(static function ($name): string {
        return substr((string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $name), 0, 20);
    }, array_keys($input)), 0, 30);
    $agent = substr((string) preg_replace('/[^\x20-\x7E]/', '', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 60);
    return [
        'id' => $sentId === null ? 'missing' : (hash_equals($id, $sentId) ? 'ok' : 'mismatch'),
        'id_len' => $sentId === null ? 0 : strlen($sentId),
        'key' => $keyState,
        'key_len' => $sentKey === null ? 0 : strlen($sentKey),
        'expected_key_len' => strlen($key),
        'params' => implode(',', $names),
        'path' => substr((string) preg_replace('/[^A-Za-z0-9_\/.\-]/', '', (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)), 0, 80),
        'ua' => str_replace(' ', '_', $agent),
    ];
}
$log = __DIR__ . '/../station-upload.log';

try {
    require_once __DIR__ . '/../config.php';
    ini_set('display_errors', '0');
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method !== 'GET' && $method !== 'POST') {
        header('Allow: GET, POST');
        http_response_code(405);
        weather_ingest_log($log, 'DENIED', ['reason' => 'method'], false);
        echo "invalid method\n";
        exit;
    }
    $input = $method === 'POST' ? $_POST : $_GET;
    $key = defined('STATION_UPLOAD_KEY') ? (string) STATION_UPLOAD_KEY : '';
    $id = defined('STATION_UPLOAD_ID') ? (string) STATION_UPLOAD_ID : (string) WU_STATION_ID;
    if (strlen($key) < 32) {
        throw new RuntimeException('station_upload_key_missing_or_short');
    }
    if (!WeatherIngest::authorized($input, $id, $key)) {
        http_response_code(403);
        weather_ingest_log($log, 'DENIED', ['reason' => 'credentials'] + station_upload_auth_diagnostics($input, $id, $key), false);
        echo "invalid credentials\n";
        exit;
    }
    $now = time();
    $row = WeatherIngest::station($input, (string) WU_STATION_ID, $now);
    $result = WeatherIngest::store(WeatherIngest::connect(), $row, 'direct');
    weather_ingest_log($log, $result, ['source' => 'direct', 'obs' => $row['obs_time_utc']], false);
    echo "success\n";
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    weather_ingest_log($log, 'ERROR', ['reason' => $e->getMessage()], false);
    echo "invalid observation\n";
} catch (Throwable $e) {
    ini_set('display_errors', '0');
    http_response_code(503);
    // Neither request parameters nor exception messages (possibly secrets) leave the collector.
    error_log('station-upload: ' . get_class($e) . ' code=' . $e->getCode());
    $reason = in_array($e->getMessage(), [
        'station_upload_key_missing_or_short', 'ingest_tables_require_InnoDB', 'missing_linked_observation',
    ], true) ? $e->getMessage() : 'configuration_or_database';
    weather_ingest_log($log, 'ERROR', ['reason' => $reason, 'code' => $e->getCode()], false);
    echo "temporarily unavailable\n";
}
