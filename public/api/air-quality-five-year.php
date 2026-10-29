<?php
/**
 * GET /public/api/air-quality-five-year.php – předzpracované pětileté mapy ČHMÚ pro okres Most.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\JsonResponse;
use OuBrix\Weather\Weather\Services\AirQualityFiveYearMapService;

$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Povolena je pouze metoda GET.');
    return;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (array_diff(array_keys($_GET), ['component']) !== []) {
    JsonResponse::error(400, 'INVALID_QUERY', 'Povolen je pouze parametr component.');
    return;
}
$component = isset($_GET['component']) ? $_GET['component'] : 'PM10';
if (!is_string($component)) {
    JsonResponse::error(400, 'INVALID_QUERY', 'Látka musí být textová hodnota.');
    return;
}
if (!AirQualityFiveYearMapService::supports($component)) {
    JsonResponse::error(400, 'INVALID_COMPONENT', 'Zvolená látka pro pětiletou mapu není podporována.');
    return;
}

try {
    $payload = (new AirQualityFiveYearMapService(
        WEATHER_ROOT . '/data/air_quality/most-2020-2024.json'
    ))->build($component);
    JsonResponse::send($payload, 200, 86400);
} catch (\Throwable $exception) {
    error_log('OuBrix Weather API air-quality-five-year: ' . get_class($exception) . ' ' . $exception->getMessage());
    JsonResponse::error(
        503,
        'AIR_QUALITY_FIVE_YEAR_UNAVAILABLE',
        'Pětiletou mapu kvality ovzduší ČHMÚ se nyní nepodařilo bezpečně načíst.'
    );
}
