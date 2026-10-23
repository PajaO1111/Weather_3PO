<?php
/**
 * Připojení k původní databázi meteostanice.
 *
 * Připojení záměrně kopíruje funkční vzor z původního history.php:
 * stejné DSN, stejné konstanty z config.php, stejný ovladač PDO MySQL.
 * Neprovádí žádné SET příkazy ani zápisy – aplikace data pouze čte,
 * tabulku pws_observations dál plní beze změny původní CRON.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Infrastructure;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    /** Konstanty, které musí původní config.php definovat. */
    public const REQUIRED_CONSTANTS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'WU_STATION_ID'];

    /** Bezpečné kódy chyb konfigurace, které smí odejít v diagnostice. */
    private const CONFIG_ERRORS = ['CONFIG_NOT_FOUND', 'CONFIG_NOT_READABLE', 'CONFIG_INCOMPLETE'];

    /** @var PDO|null Jedno sdílené spojení na požadavek. */
    private static $connection = null;

    /**
     * Určí cestu k tajnému config.php.
     *
     * Pořadí: explicitní cesta (proměnná WEATHER_CONFIG / nastavení) →
     * výchozí umístění dvě úrovně nad projektem.
     *
     * @param string      $root     kořen projektu (WEATHER_ROOT)
     * @param string|null $override vlastní cesta, prázdná/null = výchozí
     */
    public static function configPath(string $root, ?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }
        return dirname($root, 2) . DIRECTORY_SEPARATOR . 'config.php';
    }

    /**
     * Načte původní config.php a ověří, že definuje potřebné konstanty.
     *
     * @return string skutečně použitá cesta
     * @throws RuntimeException s kódem CONFIG_* při chybě
     */
    public static function loadConfig(string $root, ?string $override = null): string
    {
        $path = self::configPath($root, $override);
        if (!is_file($path)) {
            throw new RuntimeException('CONFIG_NOT_FOUND');
        }
        if (!is_readable($path)) {
            throw new RuntimeException('CONFIG_NOT_READABLE');
        }
        require_once $path;
        // Původní config.php zapíná display_errors – pro novou aplikaci jej hned vypneme.
        ini_set('display_errors', '0');
        foreach (self::REQUIRED_CONSTANTS as $name) {
            if (!defined($name)) {
                throw new RuntimeException('CONFIG_INCOMPLETE');
            }
        }
        return $path;
    }

    /**
     * Cesta ke config.php podle prostředí: WEATHER_CONFIG → config/app.php → výchozí.
     */
    public static function configuredOverride(): ?string
    {
        $env = getenv('WEATHER_CONFIG');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $setting = weather_settings()['secret_config_path'];
        return is_string($setting) && $setting !== '' ? $setting : null;
    }

    /**
     * Vrátí PDO spojení (při prvním volání načte konfiguraci a připojí se).
     *
     * @param string      $root  kořen projektu
     * @param string|null $stage výstupní parametr: fáze, ve které případně došlo k chybě
     *                           (configuration → connection → connected)
     */
    public static function connection(string $root, ?string &$stage = null): PDO
    {
        if (self::$connection instanceof PDO) {
            $stage = 'connected';
            return self::$connection;
        }

        $stage = 'configuration';
        self::loadConfig($root, self::configuredOverride());

        $stage = 'connection';
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        self::$connection = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        $stage = 'connected';
        return self::$connection;
    }

    /** ID stanice z původního config.php (např. IMOST71). */
    public static function stationId(): string
    {
        return (string) constant('WU_STATION_ID');
    }

    /**
     * Převede výjimku na bezpečné diagnostické údaje pro JSON odpověď.
     *
     * Nikdy nevrací text chyby ovladače – ten může obsahovat jméno serveru,
     * uživatele nebo cesty. Vrací jen fázi, kód konfigurace a SQLSTATE / kód ovladače.
     *
     * @return array<string, string|int>
     */
    public static function failureDetails(Throwable $exception, string $stage): array
    {
        $details = ['stage' => $stage];
        if ($exception instanceof RuntimeException && in_array($exception->getMessage(), self::CONFIG_ERRORS, true)) {
            $details['reason'] = $exception->getMessage();
        }
        if ($exception instanceof PDOException) {
            $info = is_array($exception->errorInfo) ? $exception->errorInfo : [];
            $state = isset($info[0]) ? $info[0] : (string) $exception->getCode();
            if (is_string($state) && preg_match('/^[A-Z0-9]{5}$/D', $state) === 1) {
                $details['sqlState'] = $state;
            }
            if (isset($info[1]) && is_int($info[1])) {
                $details['driverCode'] = $info[1];
            }
        }
        return $details;
    }

    /** Pouze pro testy: podstrčí připravené spojení (např. SQLite v paměti), null = reset. */
    public static function useConnection(?PDO $pdo): void
    {
        self::$connection = $pdo;
    }
}
