<?php
/**
 * Společný start aplikace OuBrix Weather.
 *
 * Načítá ho každý vstupní bod (public/index.php, public/api/*.php, bin/*, tests/*).
 * Úkoly:
 *  1. ověřit minimální verzi PHP (dřív, než se načte kód s novější syntaxí),
 *  2. vypnout zobrazování chyb do prohlížeče (chyby jdou jen do error logu),
 *  3. zaregistrovat autoloader tříd OuBrix\Weather\* ze složky app/,
 *  4. zpřístupnit nastavení aplikace přes weather_settings().
 *
 * Soubor je záměrně psán syntaxí kompatibilní se starším PHP, aby při
 * nevhodné verzi vrátil srozumitelnou chybu místo prázdné HTTP 500.
 */
declare(strict_types=1);

/** Kořenový adresář projektu (na hostingu /www/domains/oubrix.eu/Weather). */
define('WEATHER_ROOT', __DIR__);

/** Minimální podporovaná verze PHP (typované vlastnosti, arrow funkce). */
define('WEATHER_MIN_PHP_ID', 70400);

// Tento soubor musí jít načíst i na starém PHP (bez návratových typů),
// jinak by místo této hlášky vznikla prázdná HTTP 500.
if (PHP_VERSION_ID < WEATHER_MIN_PHP_ID) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'OuBrix Weather vyzaduje PHP 7.4 nebo novejsi, server bezi na PHP ' . PHP_VERSION . '.';
    exit;
}

// Chyby nikdy nevypisujeme návštěvníkovi – mohly by obsahovat cesty nebo údaje k DB.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * Autoloader tříd OuBrix\Weather\* → app/*.php.
 *
 * Linux rozlišuje velikost písmen. Některé FTP klienty při nahrávání převedou
 * názvy na malá písmena (app/weather/services/...). Proto se zkouší postupně:
 *   1. přesná cesta            app/Weather/Services/ObservationService.php
 *   2. složky malými písmeny   app/weather/services/ObservationService.php
 *   3. vše malými písmeny      app/weather/services/observationservice.php
 */
spl_autoload_register(function ($class) {
    $prefix = 'OuBrix\\Weather\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $parts = explode('\\', substr($class, strlen($prefix)));
    $name = array_pop($parts);
    $dir = implode('/', $parts);
    $dirPrefix = $dir === '' ? '' : $dir . '/';
    $lowerDirPrefix = strtolower($dirPrefix);
    $candidates = array(
        $dirPrefix . $name . '.php',
        $lowerDirPrefix . $name . '.php',
        $lowerDirPrefix . strtolower($name) . '.php',
    );
    foreach ($candidates as $candidate) {
        $file = WEATHER_ROOT . '/app/' . $candidate;
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

/**
 * Vrátí veřejné (netajné) nastavení aplikace z config/app.php.
 *
 * Výsledek se načte jen jednou za požadavek.
 *
 * @return array<string, mixed>
 */
function weather_settings()
{
    static $settings = null;
    if ($settings === null) {
        $settings = require WEATHER_ROOT . '/config/app.php';
    }
    return $settings;
}
