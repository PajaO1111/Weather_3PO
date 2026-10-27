<?php
/**
 * Ověření metadat a teploty Kopist z denních desetiminutových JSON souborů.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;

final class KopistyService
{
    public const WSI = '0-20000-0-11433';

    /** @var ChmiGateway */
    private $gateway;

    public function __construct(ChmiGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /** @return array<string, mixed> */
    public function build(DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $date = $now->format('Ymd');
        $sources = [];
        $meta1 = $this->load('climate_meta1_' . $date, $sources);
        $meta2 = $this->load('climate_meta2_' . $date, $sources);
        $meta4 = $this->load('climate_meta4_' . $date, $sources);
        $stations = $this->rows($meta1, ['WSI', 'FULL_NAME', 'GEOGR1', 'GEOGR2', 'ELEVATION']);
        $station = null;
        foreach ($stations as $row) {
            if ($row['WSI'] === self::WSI) {
                $station = [
                    'id' => self::WSI,
                    'name' => (string) $row['FULL_NAME'],
                    'longitude' => $this->number($row['GEOGR1']),
                    'latitude' => $this->number($row['GEOGR2']),
                    'elevation' => $this->number($row['ELEVATION']),
                ];
            }
        }
        if ($station === null) {
            throw new RuntimeException('Kopisty nejsou v aktuálních metadatech dostupné.');
        }
        $unit = null;
        foreach ($this->rows($meta2, ['OBS_TYPE', 'WSI', 'EG_EL_ABBREVIATION', 'UN_DESCRIPTION']) as $row) {
            if ($row['WSI'] === self::WSI && $row['OBS_TYPE'] === '10M' && $row['EG_EL_ABBREVIATION'] === 'T') {
                $unit = trim((string) $row['UN_DESCRIPTION']);
            }
        }
        if ($unit !== '°C') {
            throw new RuntimeException('Jednotka teploty Kopist není potvrzena jako °C.');
        }
        $qualities = [];
        foreach ($this->rows($meta4, ['FLAG2', 'DESCRIPTION']) as $row) {
            $qualities[(string) $this->number($row['FLAG2'])] = (string) $row['DESCRIPTION'];
        }
        if (!isset($qualities['0']) || strpos($qualities['0'], 'Good/') !== 0) {
            throw new RuntimeException('Číselník kvality nepotvrzuje kvalitní měření.');
        }
        $points = [];
        $warnings = [];
        foreach ([$now->modify('-1 day'), $now] as $day) {
            try {
                $document = $this->load('kopisty_' . $day->format('Ymd'), $sources);
                $dailyPoints = [];
                foreach ($this->rows($document, ['STATION', 'ELEMENT', 'DT', 'VAL', 'FLAG', 'QUALITY']) as $row) {
                    if ($row['STATION'] !== self::WSI || $row['ELEMENT'] !== 'T') {
                        continue;
                    }
                    $time = $this->time($row['DT']);
                    if (substr($time, 0, 10) !== $day->format('Y-m-d')) {
                        throw new RuntimeException('Datum měření neodpovídá souboru Kopist.');
                    }
                    $quality = $this->number($row['QUALITY']);
                    $value = $row['VAL'] === null ? null : $this->number($row['VAL']);
                    $dailyPoints[] = [
                        'time' => $time,
                        'temp_c' => $quality === 0.0 && $row['FLAG'] === '' ? $value : null,
                        'quality' => $quality,
                        'qualityLabel' => $qualities[(string) $quality] ?? 'Neznámá kvalita',
                        'flag' => (string) $row['FLAG'],
                    ];
                }
                $points = array_merge($points, $dailyPoints);
            } catch (RuntimeException $exception) {
                error_log('OuBrix Weather Kopisty: ' . $exception->getMessage());
                $warnings[] = 'Data Kopist pro ' . $day->format('Y-m-d') . ' nejsou dostupná nebo mají neplatný formát.';
            }
        }
        foreach ($sources as $source) {
            if ($source['cacheStatus'] === 'stale') {
                $warnings[] = 'Část dat Kopist pochází ze starší cache; ČHMÚ nyní neodpovídá.';
            }
        }
        return [
            'station' => $station,
            'items' => $points,
            'meta' => [
                'unit' => $unit,
                'sourceCreatedAt' => $this->time($meta1['datumVytvoreni'] ?? null),
                'sourceVersion' => (string) ($meta1['verzeDat'] ?? ''),
                'sources' => $sources,
                'warnings' => array_values(array_unique($warnings)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $sources
     * @return array<string, mixed>
     */
    private function load(string $resource, array &$sources): array
    {
        $response = $this->gateway->fetch($resource);
        $source = $response;
        unset($source['body']);
        $sources[$resource] = $source;
        $document = json_decode($response['body'], true, 64);
        if (!is_array($document) || json_last_error() !== JSON_ERROR_NONE) {
            $this->gateway->invalidate($resource);
            throw new RuntimeException('Neplatný JSON ČHMÚ.');
        }
        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $required
     * @return list<array<string, mixed>>
     */
    private function rows(array $document, array $required): array
    {
        $data = $document['data']['data'] ?? null;
        if (!is_array($data) || !is_string($data['header'] ?? null) || !is_array($data['values'] ?? null)) {
            throw new RuntimeException('Neočekávaná tabulková struktura ČHMÚ.');
        }
        $header = array_map('trim', explode(',', $data['header']));
        if (array_diff($required, $header) !== [] || count(array_unique($header)) !== count($header)) {
            throw new RuntimeException('Chybějící nebo duplicitní sloupce ČHMÚ.');
        }
        $rows = [];
        foreach ($data['values'] as $values) {
            if (!is_array($values) || count($values) !== count($header)) {
                throw new RuntimeException('Neplatná délka řádku ČHMÚ.');
            }
            $rows[] = array_combine($header, $values);
        }
        return $rows;
    }

    private function number($value): float
    {
        $value = is_string($value) ? str_replace(',', '.', $value) : $value;
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new RuntimeException('Neplatná číselná hodnota ČHMÚ.');
        }
        return (float) $value;
    }

    private function time($value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('Chybějící čas ČHMÚ.');
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($time === false || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            // Čas vytvoření metadat může mít zlomky sekund.
            $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC'));
            if ($time === false || $time->format('Y-m-d\TH:i:s.v\Z') !== $value) {
                throw new RuntimeException('Neplatný UTC čas ČHMÚ.');
            }
        }
        return $time->format('Y-m-d\TH:i:s\Z');
    }
}
