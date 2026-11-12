<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\JsonResponse;
use OuBrix\Weather\Infrastructure\Database;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\PhenologyUpdateService;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Aktualizace vyžaduje metodu POST.');
    return;
}
// A custom header cannot be submitted by a cross-origin HTML form.
$encodedPassword = $_SERVER['HTTP_X_PHENOLOGY_PASSWORD'] ?? '';
$password = is_string($encodedPassword) ? rawurldecode($encodedPassword) : '';
if ($password === '' || strlen($password) > 1024) {
    JsonResponse::error(401, 'AUTH_REQUIRED', 'Zadejte správcovské heslo.');
    return;
}

$lock = null;
try {
    Database::loadConfig(WEATHER_ROOT, Database::configuredOverride());
    $hash = defined('PHENOLOGY_ADMIN_PASSWORD_HASH') ? constant('PHENOLOGY_ADMIN_PASSWORD_HASH') : '';
    if (!is_string($hash) || $hash === '') {
        JsonResponse::error(503, 'ADMIN_NOT_CONFIGURED', 'Správcovské heslo pro fenologii zatím není nastaveno.');
        return;
    }
    $directory = weather_settings()['phenology_update_dir'] ?? WEATHER_ROOT . '/storage/phenology-update';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Nelze vytvořit složku kontroly fenologie.');
    }
    $lock = fopen($directory . '/update.lock', 'c+');
    if ($lock === false) {
        throw new RuntimeException('Nelze otevřít zámek aktualizace.');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        JsonResponse::error(409, 'UPDATE_BUSY', 'Jiná kontrola právě probíhá. Zkuste to později.');
        return;
    }
    $attemptPath = $directory . '/attempts.json';
    $attempts = [];
    if (is_file($attemptPath)) {
        $stored = file_get_contents($attemptPath);
        $attempts = is_string($stored) ? json_decode($stored, true) : null;
        if (!is_array($attempts)) {
            throw new RuntimeException('Nelze načíst ochranu přihlášení.');
        }
    }
    $attempts = array_values(array_filter($attempts, static function ($time): bool {
        return is_int($time) && $time > time() - 300;
    }));
    if (count($attempts) >= 5) {
        header('Retry-After: 300');
        JsonResponse::error(429, 'AUTH_LIMIT', 'Příliš mnoho chybných hesel. Počkejte pět minut.');
        return;
    }
    if (!password_verify($password, $hash)) {
        $attempts[] = time();
        if (file_put_contents($attemptPath, json_encode($attempts)) === false) {
            throw new RuntimeException('Nelze uložit ochranu přihlášení.');
        }
        error_log('OuBrix Weather fenologie: odmítnuté správcovské heslo.');
        JsonResponse::error(401, 'AUTH_INVALID', 'Nesprávné správcovské heslo.');
        return;
    }
    $password = '';
    if (file_put_contents($attemptPath, '[]') === false) {
        throw new RuntimeException('Nelze obnovit ochranu přihlášení.');
    }
    if (!set_time_limit(180)) {
        throw new RuntimeException('Hosting neumožňuje nastavit časový limit importu.');
    }
    $pdo = Database::connection(WEATHER_ROOT);
    $service = new PhenologyUpdateService($pdo, new ChmiGateway($directory));
    JsonResponse::send($service->update($directory . '/run-' . bin2hex(random_bytes(8))));
} catch (Throwable $exception) {
    error_log('OuBrix Weather fenologie aktualizace: ' . get_class($exception) . ' ' . $exception->getMessage());
    JsonResponse::error(503, 'UPDATE_FAILED', 'Kontrola nebo import selhaly. Obnovte stránku a ověřte stav; podrobnosti jsou v logu serveru.');
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
