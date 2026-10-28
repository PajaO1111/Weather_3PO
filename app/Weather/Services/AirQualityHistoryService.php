<?php
/**
 * Normalizace historických vyhlášení SVRS ČHMÚ.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;

final class AirQualityHistoryService
{
    private const AREA = 'Ústecký kraj';

    /** @var ChmiGateway */
    private $gateway;

    public function __construct(ChmiGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $source = $this->gateway->fetch('air_svrs_history');
        try {
            $events = $this->parse($source['body']);
        } catch (Throwable $exception) {
            $this->gateway->invalidate('air_svrs_history');
            throw new RuntimeException('Historický přehled SVRS ČHMÚ nemá očekávaný formát.', 0, $exception);
        }
        usort($events, static function (array $left, array $right): int {
            return strcmp($right['issuedAt'], $left['issuedAt'])
                ?: strcmp($left['pollutant'], $right['pollutant'])
                ?: strcmp($left['type'], $right['type']);
        });

        $warnings = [];
        if ($source['upstreamError'] !== null) {
            $warnings[] = $source['upstreamError'];
        }
        return [
            'area' => self::AREA,
            'events' => $events,
            'meta' => [
                'source' => [
                    'url' => 'https://opendata.chmi.cz/air_quality/historical/smog/historicky_prehled_smogovych_situaci.csv',
                    'referenceUrl' => 'https://ovzdusi.chmi.cz/SVRS/historie.php',
                    'license' => 'CC BY 4.0',
                    'fetchedAt' => $source['fetchedAt'],
                    'cacheStatus' => $source['cacheStatus'],
                    'ageSeconds' => $source['ageSeconds'],
                ],
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * @return list<array{pollutant:string,type:string,area:string,issuedAt:string,endedAt:string|null,durationHours:float|null,note:string|null}>
     */
    private function parse(string $csv): array
    {
        if ($csv === '' || strlen($csv) > 250000) {
            throw new RuntimeException('CSV SVRS je prázdné nebo překračuje limit.');
        }
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('CSV SVRS nelze zpracovat.');
        }
        try {
            if (fwrite($stream, $csv) !== strlen($csv)) {
                throw new RuntimeException('CSV SVRS nelze přečíst.');
            }
            rewind($stream);
            $header = fgetcsv($stream, 4096, ',', '"', '\\');
            if ($header !== ['latka', 'typ', 'oblast', 'vyhlaseni', 'odvolani', 'delka_trvani', 'poznamka']) {
                throw new RuntimeException('CSV SVRS má neočekávanou hlavičku.');
            }
            $events = [];
            $keys = [];
            while (($row = fgetcsv($stream, 4096, ',', '"', '\\')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== 7
                    || !in_array($row[0], ['PM10', 'O3', 'SO2', 'NO2'], true)
                    || !in_array($row[1], ['smogová situace', 'regulace', 'varování'], true)
                    || trim($row[2]) === '') {
                    throw new RuntimeException('CSV SVRS obsahuje neplatný řádek.');
                }
                $issuedAt = $this->utcDate($row[3]);
                $endedAt = $row[4] === '' ? null : $this->utcDate($row[4]);
                $duration = null;
                if ($row[5] !== '') {
                    if (!preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)$/D', $row[5])) {
                        throw new RuntimeException('CSV SVRS obsahuje neplatnou délku trvání.');
                    }
                    $duration = (float) $row[5];
                    if (!is_finite($duration) || $duration < 0) {
                        throw new RuntimeException('CSV SVRS obsahuje délku trvání mimo rozsah.');
                    }
                }
                $key = implode('|', [$row[0], $row[1], $row[2], $issuedAt]);
                if (isset($keys[$key])) {
                    throw new RuntimeException('CSV SVRS obsahuje duplicitní událost.');
                }
                $keys[$key] = true;
                if ($row[2] !== self::AREA) {
                    continue;
                }
                $events[] = [
                    'pollutant' => $row[0],
                    'type' => $row[1],
                    'area' => $row[2],
                    'issuedAt' => $issuedAt,
                    'endedAt' => $endedAt,
                    'durationHours' => $duration,
                    'note' => $row[6] === '' ? null : trim($row[6]),
                ];
            }
            return $events;
        } finally {
            fclose($stream);
        }
    }

    private function utcDate(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new RuntimeException('CSV SVRS obsahuje čas bez explicitního časového pásma.');
        }
        try {
            $date = new DateTimeImmutable($value);
            $roundTrip = substr($value, -1) === 'Z'
                ? $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')
                : $date->format('Y-m-d\TH:i:sP');
            if ($roundTrip !== $value) {
                throw new RuntimeException('CSV SVRS obsahuje neplatný kalendářní čas.');
            }
            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('CSV SVRS obsahuje neplatný čas.', 0, $exception);
        }
    }
}
