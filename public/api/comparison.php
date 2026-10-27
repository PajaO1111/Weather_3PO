<?php
/**
 * Porovnání teploty OuBrix a Kopist bez závislosti dostupnosti jedné řady na druhé.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\JsonResponse;
use OuBrix\Weather\Infrastructure\Database;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\ComparisonService;
use OuBrix\Weather\Weather\Services\KopistyService;
use OuBrix\Weather\Weather\Services\ObservationService;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Povolena je pouze metoda GET.');
    return;
}
if ($_GET !== []) {
    JsonResponse::error(400, 'INVALID_QUERY', 'Porovnání má pevné období posledních 24 uzavřených hodin.');
    return;
}
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$local = null;
$kopisty = null;
$warnings = [];
try {
    $repository = new ObservationRepository(Database::connection(WEATHER_ROOT));
    $local = (new ObservationService($repository))->window(Database::stationId(), 25, $now, 3000);
    if ($local['meta']['truncated']) {
        $warnings[] = 'Vlastní řada dosáhla limitu počtu měření; pokrytí může být neúplné.';
    }
} catch (\Throwable $exception) {
    error_log('OuBrix Weather porovnání DB: ' . get_class($exception));
    $warnings[] = 'Vlastní měření nelze načíst; řada Kopist zůstává dostupná.';
}
try {
    $directory = weather_settings()['chmi_cache_dir'] ?? null;
    $gateway = new ChmiGateway(is_string($directory) && $directory !== '' ? $directory : WEATHER_ROOT . '/storage/cache/chmi');
    $kopisty = (new KopistyService($gateway))->build($now);
} catch (\Throwable $exception) {
    error_log('OuBrix Weather porovnání ČHMÚ: ' . $exception->getMessage());
    $warnings[] = 'ČHMÚ Kopisty nelze načíst; vlastní měření zůstává dostupné.';
}
if ($local === null && $kopisty === null) {
    JsonResponse::error(503, 'COMPARISON_UNAVAILABLE', 'Nelze načíst ani jednu srovnávací řadu.');
    return;
}
try {
    JsonResponse::send((new ComparisonService())->build($local, $kopisty, $now, $warnings));
} catch (\Throwable $exception) {
    error_log('OuBrix Weather porovnání: ' . get_class($exception));
    JsonResponse::error(503, 'COMPARISON_UNAVAILABLE', 'Srovnávací data nelze bezpečně zpracovat.');
}
