<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\JsonResponse;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\ForecastService;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Povolena je pouze metoda GET.');
    return;
}
if ($_GET !== []) {
    JsonResponse::error(400, 'INVALID_QUERY', 'Textová předpověď nemá volitelné parametry oblasti ani stanice.');
    return;
}
try {
    $directory = weather_settings()['chmi_cache_dir'] ?? null;
    $gateway = new ChmiGateway(is_string($directory) && $directory !== '' ? $directory : WEATHER_ROOT . '/storage/cache/chmi');
    JsonResponse::send((new ForecastService($gateway))->build());
} catch (\Throwable $exception) {
    error_log('OuBrix Weather předpověď ČHMÚ: ' . get_class($exception) . ' ' . $exception->getMessage());
    JsonResponse::error(503, 'FORECAST_UNAVAILABLE', 'Textovou předpověď ČHMÚ nyní nelze bezpečně načíst. Zkuste to později.');
}
