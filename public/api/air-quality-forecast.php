<?php
/**
 * GET /public/api/air-quality-forecast.php – modelová předpověď pro ORP Most.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\JsonResponse;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\AirQualityForecastService;

$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Povolena je pouze metoda GET.');
    return;
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$allowedKeys = ['pollutant'];
if (array_diff(array_keys($_GET), $allowedKeys) !== []) {
    JsonResponse::error(400, 'INVALID_QUERY', 'Povolena je pouze volba znečišťující látky.');
    return;
}
$component = isset($_GET['pollutant']) ? $_GET['pollutant'] : 'PM10';
if (!is_string($component) || !in_array($component, ['PM10', 'PM2_5', 'NO2', 'O3', 'SO2'], true)) {
    JsonResponse::error(400, 'INVALID_POLLUTANT', 'Vybraná znečišťující látka není podporována.');
    return;
}

try {
    $settings = weather_settings();
    $cacheDirectory = $settings['chmi_cache_dir'] ?? null;
    if (!is_string($cacheDirectory) || $cacheDirectory === '') {
        $cacheDirectory = WEATHER_ROOT . '/storage/cache/chmi';
    }
    $payload = (new AirQualityForecastService(new ChmiGateway($cacheDirectory)))->build($component);
    JsonResponse::send($payload);
} catch (\Throwable $exception) {
    error_log('OuBrix Weather API air-quality-forecast: ' . get_class($exception) . ' ' . $exception->getMessage());
    JsonResponse::error(
        503,
        'AIR_QUALITY_FORECAST_UNAVAILABLE',
        'Modelovou předpověď kvality ovzduší ČHMÚ se nyní nepodařilo ověřit.'
    );
}
