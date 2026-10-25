<?php
/**
 * GET /public/api/air-quality.php – normalizovaná aktuální data ČHMÚ pro UMOM.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\JsonResponse;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Repositories\AirQualityLastValidRepository;
use OuBrix\Weather\Weather\Services\AirQualityService;

$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Povolena je pouze metoda GET.');
    return;
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

try {
    $settings = weather_settings();
    $cacheDirectory = $settings['chmi_cache_dir'] ?? null;
    if (!is_string($cacheDirectory) || $cacheDirectory === '') {
        $cacheDirectory = WEATHER_ROOT . '/storage/cache/chmi';
    }
    $gateway = new ChmiGateway($cacheDirectory);
    $payload = (new AirQualityService($gateway, null, new AirQualityLastValidRepository($cacheDirectory)))->build();
    JsonResponse::send($payload);
} catch (\Throwable $exception) {
    error_log('OuBrix Weather API air-quality: ' . get_class($exception) . ' ' . $exception->getMessage());
    JsonResponse::error(
        503,
        'CHMI_UNAVAILABLE',
        'Data kvality ovzduší ČHMÚ se nyní nepodařilo načíst. Zkuste to prosím později.'
    );
}
