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
use OuBrix\Weather\Weather\Support\ChmiJson;

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
        $stations = ChmiJson::rows($meta1, ['WSI', 'FULL_NAME', 'GEOGR1', 'GEOGR2', 'ELEVATION']);
        $station = null;
        foreach ($stations as $row) {
            if ($row['WSI'] === self::WSI) {
                $station = [
                    'id' => self::WSI,
                    'name' => (string) $row['FULL_NAME'],
                    'longitude' => ChmiJson::number($row['GEOGR1']),
                    'latitude' => ChmiJson::number($row['GEOGR2']),
                    'elevation' => ChmiJson::number($row['ELEVATION']),
                ];
            }
        }
        if ($station === null) {
            throw new RuntimeException('Kopisty nejsou v aktuálních metadatech dostupné.');
        }
        $unit = null;
        foreach (ChmiJson::rows($meta2, ['OBS_TYPE', 'WSI', 'EG_EL_ABBREVIATION', 'UN_DESCRIPTION']) as $row) {
            if ($row['WSI'] === self::WSI && $row['OBS_TYPE'] === '10M' && $row['EG_EL_ABBREVIATION'] === 'T') {
                $unit = trim((string) $row['UN_DESCRIPTION']);
            }
        }
        if ($unit !== '°C') {
            throw new RuntimeException('Jednotka teploty Kopist není potvrzena jako °C.');
        }
        $qualities = [];
        foreach (ChmiJson::rows($meta4, ['FLAG2', 'DESCRIPTION']) as $row) {
            $qualities[(string) ChmiJson::number($row['FLAG2'])] = (string) $row['DESCRIPTION'];
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
                foreach (ChmiJson::rows($document, ['STATION', 'ELEMENT', 'DT', 'VAL', 'FLAG', 'QUALITY']) as $row) {
                    if ($row['STATION'] !== self::WSI || $row['ELEMENT'] !== 'T') {
                        continue;
                    }
                    $time = ChmiJson::time($row['DT']);
                    if (substr($time, 0, 10) !== $day->format('Y-m-d')) {
                        throw new RuntimeException('Datum měření neodpovídá souboru Kopist.');
                    }
                    $quality = ChmiJson::number($row['QUALITY']);
                    $value = $row['VAL'] === null ? null : ChmiJson::number($row['VAL']);
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
                'sourceCreatedAt' => ChmiJson::time($meta1['datumVytvoreni'] ?? null),
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

}
