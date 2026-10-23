<?php
/**
 * Společný běhový obal pro soubory v public/api/.
 *
 * Každý endpoint jen předá funkci, která vrátí data. Api::run zajistí:
 *  - povolenou metodu GET (jinak 405),
 *  - připojení k DB,
 *  - převod výjimek na bezpečné JSON chyby (400 / 503) a zápis do error logu.
 *
 * Příklad (public/api/current.php):
 *   Api::run(function (PDO $pdo, string $station) { return [...]; });
 */
declare(strict_types=1);

namespace OuBrix\Weather\Http;

use InvalidArgumentException;
use OuBrix\Weather\Infrastructure\Database;
use PDO;
use Throwable;

final class Api
{
    /**
     * @param callable(PDO, string): (array<string, mixed>|CsvResponse|null) $handler vrací data (JSON), CSV soubor nebo null (= 404)
     * @param int $maxAge sekundy krátkého cachování úspěšné odpovědi
     */
    public static function run(callable $handler, int $maxAge = 0): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($method !== 'GET' && $method !== 'HEAD') {
            header('Allow: GET, HEAD');
            JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Povolena je pouze metoda GET.');
            return;
        }

        $stage = 'start';
        try {
            $pdo = Database::connection(WEATHER_ROOT, $stage);
            $stage = 'query';
            $data = $handler($pdo, Database::stationId());
            if ($data === null) {
                JsonResponse::error(404, 'NO_DATA', 'Pro stanici zatím nejsou žádná měření.');
                return;
            }
            if ($data instanceof CsvResponse) {
                $data->send();
                return;
            }
            JsonResponse::send($data, 200, $maxAge);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::error(400, 'INVALID_QUERY', $exception->getMessage());
        } catch (Throwable $exception) {
            // Podrobnosti jen do logu serveru; klient dostane bezpečný souhrn.
            $details = Database::failureDetails($exception, $stage);
            error_log('OuBrix Weather API: ' . get_class($exception) . ' ' . json_encode($details));
            JsonResponse::error(503, 'DATA_UNAVAILABLE', 'Měření nelze načíst. Ověřte konfiguraci a dostupnost DB.', ['diagnostics' => $details]);
        }
    }
}
