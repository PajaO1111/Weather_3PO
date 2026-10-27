<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\JsonResponse;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\RadarService;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Povolena je pouze metoda GET.');
    return;
}
$product = $_GET['product'] ?? 'maxz';
if (array_diff(array_keys($_GET), ['product']) !== [] || !is_string($product)
    || !in_array($product, ['maxz', 'merge'], true)) {
    JsonResponse::error(400, 'INVALID_QUERY', 'Povolené produkty jsou maxz a merge; další parametry nejsou podporovány.');
    return;
}
try {
    $directory = weather_settings()['chmi_cache_dir'] ?? null;
    $gateway = new ChmiGateway(is_string($directory) && $directory !== '' ? $directory : WEATHER_ROOT . '/storage/cache/chmi');
    JsonResponse::send((new RadarService($gateway))->build($product));
} catch (\Throwable $exception) {
    error_log('OuBrix Weather radar ČHMÚ: ' . get_class($exception) . ' ' . $exception->getMessage());
    JsonResponse::error(503, 'RADAR_UNAVAILABLE', 'Radar ČHMÚ nyní nelze bezpečně načíst. Neznamená to stav bez srážek.');
}
