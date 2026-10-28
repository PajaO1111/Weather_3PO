<?php
/**
 * GET /public/api/air-quality-history.php – historická vyhlášení SVRS pro Ústecký kraj.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\JsonResponse;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\AirQualityHistoryService;

$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Povolena je pouze metoda GET.');
    return;
}
if ($_GET !== []) {
    JsonResponse::error(400, 'INVALID_QUERY', 'Historie SVRS nepřijímá parametry.');
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
    $payload = (new AirQualityHistoryService(new ChmiGateway($cacheDirectory)))->build();
    JsonResponse::send($payload);
} catch (\Throwable $exception) {
    error_log('OuBrix Weather API air-quality-history: ' . get_class($exception) . ' ' . $exception->getMessage());
    JsonResponse::error(
        503,
        'CHMI_UNAVAILABLE',
        'Historii vyhlášení SVRS ČHMÚ se nyní nepodařilo načíst. Zkuste to prosím později.'
    );
}
