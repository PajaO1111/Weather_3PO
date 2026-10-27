<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\JsonResponse;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\ClimateService;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Povolena je pouze metoda GET.');
    return;
}
$year = $_GET['year'] ?? null;
if (array_diff(array_keys($_GET), ['year']) !== []
    || ($year !== null && (!is_string($year) || !preg_match('/^\d{4}$/D', $year)
        || (int) $year < 1800 || (int) $year >= (int) gmdate('Y')))) {
    JsonResponse::error(400, 'INVALID_QUERY', 'Povolen je pouze rok uzavřeného historického období (?year=RRRR).');
    return;
}
try {
    $directory = weather_settings()['chmi_cache_dir'] ?? null;
    $gateway = new ChmiGateway(is_string($directory) && $directory !== '' ? $directory : WEATHER_ROOT . '/storage/cache/chmi');
    JsonResponse::send((new ClimateService($gateway))->build($year === null ? null : (int) $year));
} catch (InvalidArgumentException $exception) {
    JsonResponse::error(400, 'INVALID_YEAR', $exception->getMessage());
} catch (\Throwable $exception) {
    error_log('OuBrix Weather klima Kopisty: ' . get_class($exception) . ' ' . $exception->getMessage());
    JsonResponse::error(503, 'CLIMATE_UNAVAILABLE', 'Historické klima ČHMÚ nelze bezpečně načíst. Zkuste to později.');
}
