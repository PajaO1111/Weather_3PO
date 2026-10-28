<?php
/**
 * Normalizace boxplotové předpovědi kvality ovzduší pro ORP Most.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use ZipArchive;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;

final class AirQualityForecastService
{
    /** @var array<string, string> */
    private const POLLUTANTS = [
        'PM10' => 'PM10',
        'PM2_5' => 'PM2,5',
        'NO2' => 'NO₂',
        'O3' => 'O₃',
        'SO2' => 'SO₂',
    ];

    /** @var ChmiGateway */
    private $gateway;

    /** @var DateTimeImmutable */
    private $now;

    public function __construct(ChmiGateway $gateway, ?DateTimeImmutable $now = null)
    {
        $this->gateway = $gateway;
        $this->now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $component): array
    {
        if (!isset(self::POLLUTANTS[$component])) {
            throw new RuntimeException('Požadovaná znečišťující látka není povolena.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Server nemá povolené rozšíření PHP ZipArchive.');
        }

        $index = $this->gateway->fetch('air_forecast_index');
        try {
            $candidates = $this->latestArchiveSlots($index['body'], $component);
        } catch (RuntimeException $exception) {
            $this->gateway->invalidate('air_forecast_index');
            throw $exception;
        }

        $valid = [];
        $warnings = [];
        $sourceStale = $index['cacheStatus'] === 'stale';
        foreach ($candidates as $candidate) {
            $resource = 'air_forecast_archive_' . $component . '_h_' . $candidate['slot'];
            try {
                $archive = $this->gateway->fetch($resource);
                $product = $this->parseArchive($archive['body'], $component);
                $valid[] = [
                    'product' => $product,
                    'source' => $archive,
                    'archiveName' => $candidate['filename'],
                ];
                $sourceStale = $sourceStale || $archive['cacheStatus'] === 'stale';
            } catch (Throwable $exception) {
                $this->gateway->invalidate($resource);
                error_log('OuBrix Weather předpověď ovzduší: ' . $exception->getMessage());
                $warnings[] = 'Jeden z nejnovějších archivů předpovědi se nepodařilo ověřit.';
            }
        }
        if ($valid === []) {
            throw new RuntimeException('ČHMÚ neposkytlo použitelný archiv předpovědi pro ORP Most.');
        }

        usort($valid, static function (array $left, array $right): int {
            return strcmp($right['product']['createdAt'], $left['product']['createdAt']);
        });
        $selected = $valid[0];
        $product = $selected['product'];
        $ageSeconds = max(0, $this->now->getTimestamp() - strtotime($product['createdAt']));
        $dataStale = $ageSeconds > 10800;
        if ($dataStale) {
            $warnings[] = 'Předpověď nebyla vydána během posledních tří hodin.';
        }
        if ($sourceStale) {
            $warnings[] = 'ČHMÚ nyní neodpovídá; alespoň část zobrazených dat pochází z uložené kopie.';
        }
        if (count($valid) < count($candidates)) {
            $warnings[] = 'Některé soubory v nejnovější publikační skupině nebyly dostupné.';
        }

        return [
            'area' => [
                'type' => 'ORP',
                'name' => 'Most',
                'code' => '892',
                'codeSystem' => 'CZ-RUIAN-ORP',
            ],
            'pollutant' => [
                'code' => $component,
                'label' => self::POLLUTANTS[$component],
                'unit' => null,
            ],
            'currentMap' => $product['currentMap'],
            'items' => $product['items'],
            'meta' => [
                'createdAt' => $product['createdAt'],
                'ageSeconds' => $ageSeconds,
                'stale' => $dataStale || $sourceStale || count($valid) < count($candidates),
                'availableHours' => count($product['items']),
                'warnings' => array_values(array_unique($warnings)),
                'source' => [
                    'url' => 'https://opendata.chmi.cz/air_quality/now/forecast/' . $selected['archiveName'],
                    'archiveName' => $selected['archiveName'],
                    'archiveFetchedAt' => $selected['source']['fetchedAt'],
                    'archiveCacheStatus' => $selected['source']['cacheStatus'],
                    'indexFetchedAt' => $index['fetchedAt'],
                    'indexCacheStatus' => $index['cacheStatus'],
                ],
            ],
        ];
    }

    /**
     * Seřadí poslední tři publikované archivy podle času publikace ze seznamu.
     * Výběr výsledného běhu pak probíhá podle datumVytvoreni uvnitř JSONů.
     *
     * @return array<int, array{slot: string, filename: string, modified: string}>
     */
    private function latestArchiveSlots(string $html, string $component): array
    {
        $pattern = '~<a href="airquality_FC-boxplots_ORP_' . preg_quote($component, '~')
            . '_h_(\d{2})\.zip">[^<]+</a>\s+(\d{2})-([A-Za-z]{3})-(\d{4})\s+(\d{2}):(\d{2})~';
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);
        $months = [
            'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
            'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
            'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12',
        ];
        $entries = [];
        foreach ($matches as $match) {
            $slot = (int) $match[1];
            $month = ucfirst(strtolower($match[3]));
            if ($slot > 23 || !isset($months[$month])) {
                continue;
            }
            $entries[] = [
                'slot' => $match[1],
                'filename' => 'airquality_FC-boxplots_ORP_' . $component . '_h_' . $match[1] . '.zip',
                'modified' => $match[4] . $months[$month] . $match[2] . $match[5] . $match[6],
            ];
        }
        usort($entries, static function (array $left, array $right): int {
            return strcmp($right['modified'], $left['modified']);
        });
        $unique = [];
        foreach ($entries as $entry) {
            if (!isset($unique[$entry['slot']])) {
                $unique[$entry['slot']] = $entry;
            }
        }
        if ($unique === []) {
            throw new RuntimeException('Index ČHMÚ neobsahuje archiv předpovědi pro vybranou látku.');
        }
        return array_slice(array_values($unique), 0, 3);
    }

    /**
     * Přečte pouze JSON pro ORP Most, bez extrakce cest z archivu.
     *
     * @return array{createdAt: string, currentMap: array<string, mixed>|null, items: array<int, array<string, mixed>>}
     */
    private function parseArchive(string $body, string $component): array
    {
        if (strlen($body) > 2500000) {
            throw new RuntimeException('Archiv předpovědi překračuje povolenou velikost.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'chmi-air-');
        if (!is_string($temporary)) {
            throw new RuntimeException('Dočasný soubor archivu předpovědi nelze vytvořit.');
        }
        $zip = new ZipArchive();
        try {
            if (@file_put_contents($temporary, $body, LOCK_EX) !== strlen($body)) {
                throw new RuntimeException('Archiv předpovědi nelze bezpečně uložit pro čtení.');
            }
            if ($zip->open($temporary) !== true) {
                throw new RuntimeException('Archiv předpovědi ČHMÚ není platný ZIP.');
            }
            if ($zip->numFiles < 1 || $zip->numFiles > 250) {
                throw new RuntimeException('Archiv předpovědi má neočekávaný počet souborů.');
            }

            $totalSize = 0;
            $targetIndex = null;
            $targetName = null;
            $pattern = '/^airquality_FC-boxplots_ORP-892_(\d{10})_' . preg_quote($component, '/') . '_h\.json$/D';
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !isset($stat['name'], $stat['size'])
                    || !is_string($stat['name']) || !is_int($stat['size']) || $stat['size'] < 0) {
                    throw new RuntimeException('Archiv obsahuje neplatný záznam.');
                }
                $totalSize += $stat['size'];
                if ($stat['size'] > 150000 || $totalSize > 8000000) {
                    throw new RuntimeException('Obsah archivu překračuje povolené limity.');
                }
                if (preg_match($pattern, $stat['name']) === 1) {
                    if ($targetIndex !== null) {
                        throw new RuntimeException('Archiv obsahuje duplicitní záznam ORP Most.');
                    }
                    $targetIndex = $index;
                    $targetName = $stat['name'];
                }
            }
            if ($targetIndex === null || $targetName === null) {
                throw new RuntimeException('Archiv neobsahuje předpověď pro ORP Most.');
            }
            $stream = $zip->getStream($targetName);
            if (!is_resource($stream)) {
                throw new RuntimeException('JSON předpovědi v archivu nelze přečíst.');
            }
            try {
                $json = stream_get_contents($stream, 150001);
            } finally {
                fclose($stream);
            }
            if (!is_string($json) || $json === '' || strlen($json) > 150000) {
                throw new RuntimeException('JSON předpovědi překračuje povolenou velikost.');
            }
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            return $this->normalize($decoded, $component);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('Archiv předpovědi má neplatnou strukturu.', 0, $exception);
        } finally {
            $zip->close();
            if (is_file($temporary) && !@unlink($temporary)) {
                error_log('OuBrix Weather předpověď ovzduší: dočasný archiv nelze odstranit.');
            }
        }
    }

    /**
     * @param mixed $document
     * @return array{createdAt: string, currentMap: array<string, mixed>|null, items: array<int, array<string, mixed>>}
     */
    private function normalize($document, string $component): array
    {
        if (!is_array($document) || !isset($document['datumVytvoreni'], $document['data']['metadata'], $document['data']['values'])
            || !is_array($document['data']['metadata']) || !is_array($document['data']['values'])) {
            throw new RuntimeException('JSON předpovědi nemá očekávanou strukturu.');
        }
        $metadata = $document['data']['metadata'];
        if (($metadata['AreaType'] ?? null) !== 'ORP'
            || (string) ($metadata['AreaCode'] ?? '') !== '892'
            || ($metadata['AreaCodeSystem'] ?? null) !== 'CZ-RUIAN-ORP'
            || ($metadata['AreaName'] ?? null) !== 'Most'
            || ($metadata['ComponentCode'] ?? null) !== $component) {
            throw new RuntimeException('Metadata neodpovídají látce nebo území ORP Most.');
        }
        $createdAt = $this->isoTime($document['datumVytvoreni']);
        if (strtotime($createdAt) > $this->now->getTimestamp() + 300) {
            throw new RuntimeException('Čas vydání předpovědi je v budoucnosti.');
        }

        $items = [];
        $currentMap = null;
        $seen = [];
        foreach ($document['data']['values'] as $value) {
            if (!is_array($value) || !isset($value['type'], $value['dt_beg_utc'], $value['dt_end_utc'])) {
                throw new RuntimeException('Hodinový záznam předpovědi nemá čas nebo typ.');
            }
            if (!in_array($value['type'], ['mapa', 'predpoved'], true)) {
                throw new RuntimeException('Předpověď obsahuje nepovolený typ záznamu.');
            }
            $start = $this->isoTime($value['dt_beg_utc']);
            $end = $this->isoTime($value['dt_end_utc']);
            if (strtotime($end) - strtotime($start) !== 3600 || isset($seen[$start])) {
                throw new RuntimeException('Předpověď obsahuje neplatný nebo duplicitní hodinový interval.');
            }
            $seen[$start] = true;
            $box = [];
            foreach (['min', 'p_25', 'p_50', 'p_75', 'max'] as $field) {
                $box[$field] = $this->concentration($value[$field] ?? null);
            }
            if (!($box['min'] <= $box['p_25'] && $box['p_25'] <= $box['p_50']
                && $box['p_50'] <= $box['p_75'] && $box['p_75'] <= $box['max'])) {
                throw new RuntimeException('Hodinové percentily nemají správné pořadí.');
            }
            $item = [
                'start' => $start,
                'end' => $end,
                'type' => $value['type'],
                'min' => $box['min'],
                'p25' => $box['p_25'],
                'median' => $box['p_50'],
                'p75' => $box['p_75'],
                'max' => $box['max'],
            ];
            if ($value['type'] === 'mapa') {
                if ($currentMap !== null) {
                    throw new RuntimeException('Předpověď obsahuje více současných mapových odhadů.');
                }
                $currentMap = $item;
            } else {
                $items[] = $item;
            }
        }
        usort($items, static function (array $left, array $right): int {
            return strcmp($left['start'], $right['start']);
        });
        if ($items === [] || count($items) > 120) {
            throw new RuntimeException('Archiv neobsahuje použitelný hodinový výhled.');
        }
        return ['createdAt' => $createdAt, 'currentMap' => $currentMap, 'items' => $items];
    }

    /**
     * @param mixed $value
     */
    private function concentration($value): float
    {
        if (!is_string($value) || preg_match('/^-?\d+(?:\.\d+)?$/D', $value) !== 1) {
            throw new RuntimeException('Hodnota koncentrace nemá platný číselný formát.');
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < 0) {
            throw new RuntimeException('Hodnota koncentrace je mimo povolený rozsah.');
        }
        return $number;
    }

    /**
     * @param mixed $value
     */
    private function isoTime($value): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/D', $value) !== 1) {
            throw new RuntimeException('Čas předpovědi není platný čas UTC.');
        }
        try {
            $time = new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new RuntimeException('Čas předpovědi nelze zpracovat.', 0, $exception);
        }
        $wholeSeconds = preg_replace('/\.\d+Z$/', 'Z', $value);
        if (!is_string($wholeSeconds) || $time->format('Y-m-d\TH:i:s\Z') !== $wholeSeconds) {
            throw new RuntimeException('Čas předpovědi obsahuje neplatné datum.');
        }
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
