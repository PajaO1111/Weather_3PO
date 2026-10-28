<?php
/**
 * GET /public/api/air-quality-map.php – operativní mapa kvality ovzduší ČHMÚ.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\JsonResponse;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\AirQualityMapService;
use OuBrix\Weather\Weather\Support\AirQualityMapProduct;

$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Povolena je pouze metoda GET.');
    return;
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$allowedKeys = ['component', 'type'];
if (array_diff(array_keys($_GET), $allowedKeys) !== []) {
    JsonResponse::error(400, 'INVALID_QUERY', 'Povoleny jsou pouze parametry component a type.');
    return;
}
$component = isset($_GET['component']) ? $_GET['component'] : 'PM10';
$type = isset($_GET['type']) ? $_GET['type'] : 'hours';
if (!is_string($component) || !is_string($type)) {
    JsonResponse::error(400, 'INVALID_QUERY', 'Látka a typ mapy musí být textové hodnoty.');
    return;
}
try {
    $definition = AirQualityMapProduct::definition($component);
    if (!isset($definition['types'][$type])) {
        JsonResponse::error(400, 'INVALID_MAP_TYPE', 'Zvolená kombinace látky a typu mapy není podporována.');
        return;
    }
} catch (\RuntimeException $exception) {
    JsonResponse::error(400, 'INVALID_COMPONENT', 'Zvolená látka pro mapu není podporována.');
    return;
}

try {
    $settings = weather_settings();
    $cacheDirectory = $settings['chmi_cache_dir'] ?? null;
    if (!is_string($cacheDirectory) || $cacheDirectory === '') {
        $cacheDirectory = WEATHER_ROOT . '/storage/cache/chmi';
    }
    $payload = (new AirQualityMapService(new ChmiGateway($cacheDirectory)))->build($component, $type);
    JsonResponse::send($payload);
} catch (\Throwable $exception) {
    error_log('OuBrix Weather API air-quality-map: ' . get_class($exception) . ' ' . $exception->getMessage());
    JsonResponse::error(
        503,
        'AIR_QUALITY_MAP_UNAVAILABLE',
        'Operativní mapa kvality ovzduší ČHMÚ se nyní nepodařila bezpečně načíst.'
    );
}
