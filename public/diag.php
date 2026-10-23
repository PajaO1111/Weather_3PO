<?php
/**
 * DOČASNÁ diagnostika nasazení – po vyřešení problému SMAZAT.
 *
 * URL: https://oubrix.eu/Weather/public/diag.php
 *
 * Záměrně psáno syntaxí PHP 5.3 (array(), žádné typy), aby fungovalo
 * i tam, kde zbytek aplikace kvůli staré verzi PHP spadne s HTTP 500.
 *
 * Kroky:
 *  1. verze PHP, rozšíření pdo_mysql, open_basedir,
 *  2. existence a velikost souborů aplikace (odhalí nenahrané soubory),
 *  3. nalezení a načtení původního config.php (vypíše jen NÁZVY konstant),
 *  4. připojení k DB přesně jako history.php a jeden SELECT,
 *  5. načtení bootstrap.php a třídy Database (zachytí i fatální chybu).
 *
 * Nikdy nevypisuje heslo, jméno uživatele, host DB ani text chyby DB.
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(dirname(__FILE__));          // .../oubrix.eu/Weather
$domains = dirname(dirname($root));          // .../www/domains
$configPath = $domains . '/config.php';
$report = array();
$step = 'start';

/** Zkrátí cesty, aby výstup neprozrazoval celou strukturu serveru. */
function diag_mask($text)
{
    global $domains;
    return str_replace(array($domains, str_replace('/', '\\', $domains)), '[domains]', (string) $text);
}

/** Vypíše dosavadní výsledky (volá se i při fatální chybě). */
function diag_finish()
{
    global $report, $step;
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], array(E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR), true)) {
        $report['FATAL'] = array(
            'step' => $step,
            'message' => diag_mask($error['message']),
            'file' => diag_mask($error['file']),
            'line' => $error['line'],
        );
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    foreach ($report as $key => $value) {
        echo str_pad($key, 22) . ': ' . (is_array($value) ? json_encode($value) : $value) . "\n";
    }
}
register_shutdown_function('diag_finish');
ob_start();

// 1) Prostředí
$step = 'environment';
$report['php_version'] = PHP_VERSION;
$report['php_sapi'] = PHP_SAPI;
$report['pdo_mysql'] = extension_loaded('pdo_mysql') ? 'ano' : 'NE';
$report['open_basedir'] = diag_mask(ini_get('open_basedir') === '' ? '(neomezeno)' : ini_get('open_basedir'));
$report['php_ok_for_app'] = PHP_VERSION_ID >= 70400 ? 'ano' : 'NE - aplikace vyzaduje PHP 7.4+, prepnout v administraci WEDOS';

// 2) Soubory aplikace
$step = 'files';
$files = array(
    'bootstrap.php', 'config/app.php',
    'app/Infrastructure/Database.php', 'app/Http/Api.php', 'app/Http/JsonResponse.php', 'app/Http/View.php',
    'app/Http/Validation/ObservationQuery.php', 'app/Weather/Repositories/ObservationRepository.php',
    'app/Weather/Services/CurrentConditionsService.php', 'app/Weather/Services/ObservationService.php',
    'app/Weather/Support/Meteo.php', 'public/api/health.php', 'public/api/current.php',
);
$missing = array();
foreach ($files as $file) {
    $slash = strrpos($file, '/');
    $lowerDir = $slash === false ? $file : strtolower(substr($file, 0, $slash)) . substr($file, $slash);
    if (!is_file($root . '/' . $file) && !is_file($root . '/' . $lowerDir) && !is_file($root . '/' . strtolower($file))) {
        $missing[] = $file;
    }
}
$report['missing_files'] = $missing === array() ? 'zadne' : implode(', ', $missing);

/**
 * Vypíše skutečný obsah adresáře (rekurzivně, max. 3 úrovně) – odhalí
 * špatně pojmenované složky (velikost písmen) nebo soubory nahrané jinam.
 */
function diag_tree($dir, $prefix, $depth)
{
    $out = array();
    $items = @scandir($dir);
    if ($items === false) {
        return array($prefix . '(nelze cist)');
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $out[] = $prefix . $item . '/';
            if ($depth > 0) {
                $out = array_merge($out, diag_tree($path, $prefix . $item . '/', $depth - 1));
            }
        } else {
            $out[] = $prefix . $item;
        }
    }
    return $out;
}

if ($missing !== array()) {
    $report['weather_root'] = diag_mask($root);
    $report['root_listing'] = implode(', ', diag_tree($root, '', 0));
    $report['app_listing'] = is_dir($root . '/app')
        ? implode(', ', diag_tree($root . '/app', 'app/', 3))
        : 'slozka app/ NEEXISTUJE';
}

// 3) Původní config.php
$step = 'config';
$report['config_path'] = diag_mask($configPath);
$report['config_exists'] = @is_file($configPath) ? 'ano' : 'NE';
$report['config_readable'] = @is_readable($configPath) ? 'ano' : 'NE';
if ($report['config_readable'] === 'ano') {
    require_once $configPath;
    ini_set('display_errors', '0');
    $constants = array();
    foreach (array('DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'WU_STATION_ID') as $name) {
        $constants[] = $name . (defined($name) ? '=ok' : '=CHYBI');
    }
    $report['config_constants'] = implode(' ', $constants);
}

// 4) DB stejně jako history.php
$step = 'database';
if (defined('DB_HOST') && defined('DB_PORT') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $report['db_connect'] = 'ok';
        $statement = $pdo->prepare('SELECT MAX(obs_time_utc) FROM pws_observations WHERE station_id = :station');
        $statement->bindValue(':station', defined('WU_STATION_ID') ? WU_STATION_ID : '', PDO::PARAM_STR);
        $statement->execute();
        $report['db_select'] = 'ok, posledni mereni UTC ' . $statement->fetchColumn();
    } catch (Exception $exception) {
        $info = isset($exception->errorInfo) && is_array($exception->errorInfo) ? $exception->errorInfo : array();
        $report['db_error'] = 'SQLSTATE ' . (isset($info[0]) ? $info[0] : $exception->getCode())
            . (isset($info[1]) ? ', kod ' . $info[1] : '');
    }
}

// 5) Bootstrap a třídy aplikace (jen na podporovaném PHP)
$step = 'app_bootstrap';
if (PHP_VERSION_ID >= 70400 && $missing === array()) {
    try {
        require_once $root . '/bootstrap.php';
        $step = 'app_classes';
        $settings = weather_settings();
        $report['app_settings'] = is_array($settings) ? 'ok' : 'NE';
        $step = 'app_database';
        $stage = 'start';
        \OuBrix\Weather\Infrastructure\Database::connection($root, $stage);
        $report['app_database'] = 'ok';
    } catch (PDOException $exception) {
        $report['app_error'] = 'PDOException v kroku ' . $step . ', SQLSTATE ' . $exception->getCode();
    } catch (Exception $exception) {
        $report['app_error'] = get_class($exception) . ' v kroku ' . $step . ': ' . diag_mask($exception->getMessage());
    } catch (Throwable $exception) {
        $report['app_error'] = get_class($exception) . ' v kroku ' . $step . ': ' . diag_mask($exception->getMessage())
            . ' (' . diag_mask($exception->getFile()) . ':' . $exception->getLine() . ')';
    }
}

// 6) Otisky souborů (porovnání s lokální verzí – odhalí staré/poškozené soubory)
$step = 'fingerprints';
$prints = array();
foreach (array('bootstrap.php', 'public/api/health.php', 'app/Http/JsonResponse.php', 'app/Infrastructure/Database.php') as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        $slash = strrpos($file, '/');
        $path = $root . '/' . strtolower(substr($file, 0, $slash)) . substr($file, $slash);
    }
    $prints[] = $file . '=' . (is_file($path) ? substr(md5_file($path), 0, 8) . '/' . filesize($path) . 'B' : 'chybi');
}
$report['fingerprints'] = implode(' ', $prints);

// 7) Spuštění health.php uvnitř diagnostiky – zachytí chybu, kterou server skrývá za HTTP 500
/** Vlastní scope, aby health.php nepřepsal proměnné diagnostiky. */
function diag_run_health($file)
{
    include $file;
}
$step = 'health_simulation';
if (PHP_VERSION_ID >= 70400 && $missing === array()) {
    ob_start();
    try {
        diag_run_health($root . '/public/api/health.php');
        $out = ob_get_clean();
        $report['health_output'] = diag_mask(substr($out, 0, 400));
    } catch (Throwable $exception) {
        ob_end_clean();
        $report['health_error'] = get_class($exception) . ': ' . diag_mask($exception->getMessage())
            . ' (' . diag_mask($exception->getFile()) . ':' . $exception->getLine() . ')';
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code(200);
}

$step = 'done';
$report['result'] = 'diagnostika dokoncena';
