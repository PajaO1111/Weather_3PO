<?php
/**
 * Serverový klient pro pevně vymezené zdroje OpenData ČHMÚ.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Gateways;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use OuBrix\Weather\Weather\Support\RadarProduct;

final class ChmiGateway
{
    private const BASE_URL = 'https://opendata.chmi.cz';

    /** @var array<string, array{path: string, ttl: int, maxBytes: int, url?: string}> */
    private const RESOURCES = [
        'warnings_current' => [
            'path' => '/data2/XOCZ50_OKPR.xml',
            'url' => 'https://vystrahy-cr.chmi.cz/data2/XOCZ50_OKPR.xml',
            'ttl' => 300,
            'maxBytes' => 4000000,
        ],
        'forecast_today' => [
            'path' => '/api/data/txtf/meteo.today',
            'url' => 'https://data-provider.chmi.cz/api/data/txtf/meteo.today',
            'ttl' => 1800,
            'maxBytes' => 1000000,
        ],
        'climate_history_station' => [
            'path' => '/meteorology/climate/historical/metadata/meta1.json',
            'ttl' => 604800,
            'maxBytes' => 1500000,
        ],
        'climate_history_elements' => [
            'path' => '/meteorology/climate/historical/metadata/meta2.json',
            'ttl' => 604800,
            'maxBytes' => 15000000,
        ],
        'kopisty_monthly' => [
            'path' => '/meteorology/climate/historical/data/monthly/mly-0-20000-0-11433.json',
            'ttl' => 86400,
            'maxBytes' => 12000000,
        ],
        'air_metadata' => [
            'path' => '/air_quality/now/metadata/metadata.json',
            'ttl' => 86400,
            'maxBytes' => 2500000,
        ],
        'air_values' => [
            'path' => '/air_quality/now/data/airquality_1h_avg_CZ.csv',
            'ttl' => 900,
            'maxBytes' => 250000,
        ],
        'air_forecast_index' => [
            'path' => '/air_quality/now/forecast/',
            'ttl' => 900,
            'maxBytes' => 150000,
        ],
        'value_types' => [
            'path' => '/air_quality/now/metadata/ValueType.csv',
            'ttl' => 2592000,
            'maxBytes' => 25000,
        ],
        'index_levels' => [
            'path' => '/air_quality/now/metadata/AirQualityIndex.csv',
            'ttl' => 2592000,
            'maxBytes' => 25000,
        ],
    ];

    /** @var string|null */
    private $cacheDirectory;

    /** @var Closure(string, int): string */
    private $transport;

    /** @var DateTimeImmutable */
    private $now;

    /**
     * @param callable(string, int): string|null $transport Testovací přenos; produkčně se používá HTTPS.
     */
    public function __construct(string $cacheDirectory, ?callable $transport = null, ?DateTimeImmutable $now = null)
    {
        $this->cacheDirectory = self::prepareDirectory($cacheDirectory) ? $cacheDirectory : null;
        $this->transport = $transport === null
            ? Closure::fromCallable([$this, 'request'])
            : Closure::fromCallable($transport);
        $this->now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Načte povolený zdroj, použije časově omezenou cache a při chybě vrátí
     * poslední úspěšnou kopii s výslovným označením zastaralosti.
     *
     * @return array{body: string, fetchedAt: string, ageSeconds: int, cacheStatus: string, sourceLastModified: string|null, upstreamError: string|null}
     */
    public function fetch(string $resource): array
    {
        $resources = $this->resources($resource);
        if (!isset($resources[$resource])) {
            throw new RuntimeException('Požadovaný zdroj ČHMÚ není povolen.');
        }

        $definition = $resources[$resource];
        $cached = $this->readCache($resource);
        if ($cached !== null) {
            $age = $this->ageSeconds($cached['fetchedAt']);
            if ($age < $definition['ttl']) {
                return $this->result($cached, $age, 'fresh', null);
            }
        }

        try {
            $body = ($this->transport)($definition['url'] ?? self::BASE_URL . $definition['path'], $definition['maxBytes']);
            if (!is_string($body) || $body === '' || strlen($body) > $definition['maxBytes']) {
                throw new RuntimeException('Odpověď ČHMÚ je prázdná nebo překračuje povolenou velikost.');
            }
            $entry = [
                'body' => $body,
                'fetchedAt' => $this->now->format('Y-m-d\TH:i:s\Z'),
                'sourceLastModified' => null,
            ];
            $this->writeCache($resource, $entry);
            return $this->result($entry, 0, 'fresh', null);
        } catch (Throwable $exception) {
            error_log('OuBrix Weather ČHMÚ gateway: ' . get_class($exception) . ' ' . $exception->getMessage());
            if ($cached !== null) {
                return $this->result(
                    $cached,
                    $this->ageSeconds($cached['fetchedAt']),
                    'stale',
                    'ČHMÚ nyní neodpovídá; zobrazuje se poslední uložená verze.'
                );
            }
            throw new RuntimeException('Datový zdroj ČHMÚ není dostupný a není k dispozici uložená kopie.', 0, $exception);
        }
    }

    /** Odstraní poškozenou nebo nevalidní odpověď z cache. */
    public function invalidate(string $resource): void
    {
        if (!isset($this->resources($resource)[$resource]) || $this->cacheDirectory === null) {
            return;
        }
        $file = $this->cacheFile($resource);
        if (is_file($file) && !@unlink($file)) {
            error_log('OuBrix Weather ČHMÚ gateway: nepodařilo se odstranit neplatnou cache.');
        }
    }

    /**
     * @return array{body: string, fetchedAt: string, sourceLastModified: string|null}|null
     */
    private function readCache(string $resource): ?array
    {
        if ($this->cacheDirectory === null) {
            return null;
        }
        $contents = @file_get_contents($this->cacheFile($resource));
        if (!is_string($contents) || $contents === '') {
            return null;
        }
        $data = json_decode($contents, true);
        if (!is_array($data) || !isset($data['body'], $data['fetchedAt']) || !is_string($data['body']) || !is_string($data['fetchedAt'])) {
            $this->invalidate($resource);
            return null;
        }
        $body = base64_decode($data['body'], true);
        if (!is_string($body) || $body === '' || strlen($body) > $this->resources($resource)[$resource]['maxBytes']) {
            $this->invalidate($resource);
            return null;
        }
        try {
            new DateTimeImmutable($data['fetchedAt']);
        } catch (Throwable $exception) {
            $this->invalidate($resource);
            return null;
        }
        return [
            'body' => $body,
            'fetchedAt' => $data['fetchedAt'],
            'sourceLastModified' => isset($data['sourceLastModified']) && is_string($data['sourceLastModified'])
                ? $data['sourceLastModified']
                : null,
        ];
    }

    /**
     * @param array{body: string, fetchedAt: string, sourceLastModified: string|null} $entry
     */
    private function writeCache(string $resource, array $entry): void
    {
        if ($this->cacheDirectory === null) {
            return;
        }
        $temporary = null;
        try {
            $data = json_encode([
                'body' => base64_encode($entry['body']),
                'fetchedAt' => $entry['fetchedAt'],
                'sourceLastModified' => $entry['sourceLastModified'],
            ]);
            if (!is_string($data)) {
                error_log('OuBrix Weather ČHMÚ gateway: nepodařilo se serializovat cache.');
                return;
            }
            $temporary = $this->cacheFile($resource) . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($temporary, $data, LOCK_EX) !== false
                && @rename($temporary, $this->cacheFile($resource))) {
                $this->pruneRadarFrames($resource);
                $this->pruneAirForecastArchives($resource);
                return;
            }

        } catch (Throwable $exception) {
            error_log('OuBrix Weather ČHMÚ gateway: zápis cache selhal; data byla předána bez uložení.');
        }
        if ($temporary !== null && is_file($temporary)) {
            @unlink($temporary);
        }
        if ($temporary !== null) {
            error_log('OuBrix Weather ČHMÚ gateway: cache nelze zapsat; data byla předána bez uložení.');
        }
    }

    private function pruneRadarFrames(string $resource): void
    {
        if ($this->cacheDirectory === null || !preg_match('/^radar_frame_(maxz|merge)_\d{12}$/D', $resource, $match)) return;
        $files = glob($this->cacheDirectory . DIRECTORY_SEPARATOR . 'radar_frame_' . $match[1] . '_*.json') ?: [];
        rsort($files, SORT_STRING);
        foreach (array_slice($files, 3) as $file) {
            if (preg_match('/^radar_frame_' . $match[1] . '_\d{12}\.json$/D', basename($file))
                && !@unlink($file)) {
                error_log('OuBrix Weather ČHMÚ gateway: starou radarovou cache nelze odstranit.');
            }
        }
    }

    private function pruneAirForecastArchives(string $resource): void
    {
        if ($this->cacheDirectory === null
            || !preg_match('/^air_forecast_archive_(PM10|PM2_5|NO2|O3|SO2)_h_\d{2}$/D', $resource, $match)) {
            return;
        }
        $files = glob($this->cacheDirectory . DIRECTORY_SEPARATOR . 'air_forecast_archive_' . $match[1] . '_h_*.json') ?: [];
        usort($files, static function (string $left, string $right): int {
            return (int) @filemtime($right) <=> (int) @filemtime($left);
        });
        foreach (array_slice($files, 3) as $file) {
            if (preg_match('/^air_forecast_archive_(PM10|PM2_5|NO2|O3|SO2)_h_\d{2}\.json$/D', basename($file))
                && !@unlink($file)) {
                error_log('OuBrix Weather ČHMÚ gateway: starou cache předpovědi ovzduší nelze odstranit.');
            }
        }
    }

    /**
     * Pevné HTTPS adresy a odvozené radarové názvy brání použití klienta jako proxy.
     */
    private function request(string $url, int $maxBytes): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'header' => "Accept: application/json, application/zip, application/xml, image/png, text/html, text/csv;q=0.9\r\nUser-Agent: OuBrixWeather/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            throw new RuntimeException('HTTPS spojení s ČHMÚ se nepodařilo navázat.');
        }

        try {
            $headers = isset($http_response_header) ? $http_response_header : [];
            $status = 0;
            foreach ($headers as $header) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                    $status = (int) $matches[1];
                }
            }
            if ($status !== 200) {
                throw new RuntimeException('ČHMÚ vrátilo neočekávaný HTTP stav.');
            }
            $body = stream_get_contents($handle, $maxBytes + 1);
            if (!is_string($body) || strlen($body) > $maxBytes) {
                throw new RuntimeException('Odpověď ČHMÚ překračuje povolenou velikost.');
            }
            return $body;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{body: string, fetchedAt: string, sourceLastModified: string|null} $entry
     * @return array{body: string, fetchedAt: string, ageSeconds: int, cacheStatus: string, sourceLastModified: string|null, upstreamError: string|null}
     */
    private function result(array $entry, int $age, string $status, ?string $error): array
    {
        return [
            'body' => $entry['body'],
            'fetchedAt' => $entry['fetchedAt'],
            'ageSeconds' => max(0, $age),
            'cacheStatus' => $status,
            'sourceLastModified' => $entry['sourceLastModified'],
            'upstreamError' => $error,
        ];
    }

    private function ageSeconds(string $fetchedAt): int
    {
        return max(0, $this->now->getTimestamp() - (new DateTimeImmutable($fetchedAt))->getTimestamp());
    }

    private function cacheFile(string $resource): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . $resource . '.json';
    }

    /** @return array<string, array{path: string, ttl: int, maxBytes: int, url?: string}> */
    private function resources(?string $requested = null): array
    {
        $resources = self::RESOURCES;
        foreach (['maxz', 'merge'] as $product) {
            $definition = RadarProduct::definition($product);
            $resources['radar_index_' . $product] = [
                'path' => RadarProduct::ROOT . $definition['path'], 'ttl' => $definition['step'], 'maxBytes' => 2000000,
            ];
            $resources['radar_legend_' . $product] = [
                'path' => RadarProduct::ROOT . $definition['legend'], 'ttl' => 2592000, 'maxBytes' => 100000,
            ];
        }
        if ($requested !== null && preg_match('/^air_forecast_archive_(PM10|PM2_5|NO2|O3|SO2)_h_(\d{2})$/D', $requested, $match)
            && (int) $match[2] <= 23) {
            $resources[$requested] = [
                'path' => '/air_quality/now/forecast/airquality_FC-boxplots_ORP_' . $match[1] . '_h_' . $match[2] . '.zip',
                'ttl' => 900,
                'maxBytes' => 2500000,
            ];
        }
        if ($requested !== null && preg_match('/^radar_frame_(maxz|merge)_(\d{12})$/D', $requested, $match)) {
            $product = $match[1];
            $time = RadarProduct::time($match[2], $product);
            $age = $this->now->getTimestamp() - $time->getTimestamp();
            if ($age >= -300 && $age <= 8 * 86400) {
                $definition = RadarProduct::definition($product);
                $resources[$requested] = ['path' => RadarProduct::ROOT . $definition['path']
                    . RadarProduct::filename($product, $match[2]), 'ttl' => $definition['step'], 'maxBytes' => 1000000];
            }
        }
        $date = $this->now->format('Ymd');
        foreach ([1, 2, 4] as $number) {
            $resources['climate_meta' . $number . '_' . $date] = [
                'path' => '/meteorology/climate/now/metadata/meta' . $number . '-' . $date . '.json',
                'ttl' => 86400,
                'maxBytes' => 15000000,
            ];
        }
        foreach ([$this->now, $this->now->modify('-1 day')] as $day) {
            $key = $day->format('Ymd');
            $resources['kopisty_' . $key] = [
                'path' => '/meteorology/climate/now/data/10m-0-20000-0-11433-' . $key . '.json',
                'ttl' => 900,
                'maxBytes' => 1500000,
            ];
        }
        return $resources;
    }

    private static function prepareDirectory(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            error_log('OuBrix Weather ČHMÚ gateway: složku cache nelze vytvořit.');
            return false;
        }
        if (!is_writable($directory)) {
            error_log('OuBrix Weather ČHMÚ gateway: složka cache není zapisovatelná.');
            return false;
        }
        return true;
    }
}
