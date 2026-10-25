<?php
/**
 * Normalizace aktuální kvality ovzduší pro stanici ČHMÚ Most (UMOM).
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Repositories\AirQualityLastValidRepository;

final class AirQualityService
{
    /** @var array<string, string> */
    private const POLLUTANTS = [
        'PM10' => 'Prachové částice PM10',
        'PM2_5' => 'Jemné částice PM2,5',
        'NO2' => 'Oxid dusičitý',
        'SO2' => 'Oxid siřičitý',
        'O3' => 'Přízemní ozon',
    ];

    /** @var ChmiGateway */
    private $gateway;

    /** @var DateTimeImmutable */
    private $now;

    /** @var AirQualityLastValidRepository|null */
    private $lastValid;

    public function __construct(ChmiGateway $gateway, ?DateTimeImmutable $now = null, ?AirQualityLastValidRepository $lastValid = null)
    {
        $this->gateway = $gateway;
        $this->now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $this->lastValid = $lastValid;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $metadataSource = $this->gateway->fetch('air_metadata');
        $valuesSource = $this->gateway->fetch('air_values');
        $typesSource = $this->gateway->fetch('value_types');
        $indexSource = $this->gateway->fetch('index_levels');

        try {
            $metadata = json_decode($metadataSource['body'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($metadata) || !isset($metadata['data']['Localities']) || !is_array($metadata['data']['Localities'])) {
                throw new RuntimeException('Metadata ČHMÚ nemají očekávanou strukturu.');
            }
            $valueTypes = $this->parseCsvMap($typesSource['body'], 'ID_VALUE_TYPE', 'NAME');
            $indexLevels = $this->parseIndexLevels($indexSource['body']);
            $rows = $this->parseValues($valuesSource['body']);
        } catch (Throwable $exception) {
            $this->gateway->invalidate('air_metadata');
            $this->gateway->invalidate('air_values');
            $this->gateway->invalidate('value_types');
            $this->gateway->invalidate('index_levels');
            throw new RuntimeException('Odpověď ČHMÚ nemá očekávaný formát.', 0, $exception);
        }

        $locality = $this->findMost($metadata['data']['Localities']);
        if ($locality === null) {
            throw new RuntimeException('Aktivní stanice kvality ovzduší UMOM nebyla v metadatech nalezena.');
        }

        $registrations = $this->findRegistrations($locality);
        $observations = [];
        foreach ($rows as $row) {
            $registration = (string) $row['idRegistration'];
            if (!isset($registrations['byId'][$registration])) {
                continue;
            }
            if (!isset($observations[$registration]) || $row['timestamp'] > $observations[$registration]['timestamp']) {
                $observations[$registration] = $row;
            }
        }

        $measurements = [];
        foreach (self::POLLUTANTS as $component => $label) {
            $registration = isset($registrations['byComponent'][$component])
                ? $registrations['byComponent'][$component]
                : null;
            $measurements[$component] = $this->measurement(
                $component,
                $label,
                $registration,
                $registration !== null && isset($observations[$registration['id']]) ? $observations[$registration['id']] : null,
                $valueTypes
            );
        }

        $indexRegistration = isset($registrations['byComponent']['INDX'])
            ? $registrations['byComponent']['INDX']
            : null;
        $index = $this->airQualityIndex(
            $indexRegistration,
            $indexRegistration !== null && isset($observations[$indexRegistration['id']])
                ? $observations[$indexRegistration['id']]
                : null,
            $valueTypes,
            $indexLevels
        );

        $sources = [
            'metadata' => $metadataSource,
            'values' => $valuesSource,
            'valueTypes' => $typesSource,
            'indexLevels' => $indexSource,
        ];
        $warnings = [];
        if ($this->lastValid !== null) {
            try {
                $saved = $this->lastValid->merge($measurements);
                foreach ($measurements as $component => &$measurement) {
                    if (isset($saved[$component])
                        && ($measurement['value'] === null || $saved[$component]['observedAt'] > $measurement['observedAt'])
                        && in_array($measurement['status'], ['available', 'substituted', 'invalid', 'no_data'], true)) {
                        $reason = $measurement['value'] === null
                            ? $measurement['statusText']
                            : 'Dostupný snímek obsahuje starší měření než uložená hodnota.';
                        $latestStatus = $measurement['status'];
                        $latestObservedAt = $measurement['observedAt'];
                        $measurement = $saved[$component];
                        $measurement['status'] = 'last_valid';
                        $measurement['statusText'] = 'Poslední platná hodnota. ' . $reason;
                        $measurement['latestStatus'] = $latestStatus;
                        $measurement['latestObservedAt'] = $latestObservedAt;
                        $measurement['stale'] = true;
                    }
                }
                unset($measurement);
            } catch (RuntimeException $exception) {
                error_log('OuBrix Weather poslední platné měření: ' . $exception->getMessage());
                $warnings[] = 'Úložiště posledních platných hodnot není dostupné. Uchování hodnot při výpadku nelze zaručit.';
            }
        }
        foreach ($measurements as &$measurement) {
            $measurement['ageSeconds'] = $measurement['observedAt'] === null
                ? null
                : max(0, $this->now->getTimestamp() - strtotime($measurement['observedAt']));
        }
        unset($measurement);
        foreach ($sources as $source) {
            if ($source['upstreamError'] !== null) {
                $ageMinutes = (int) floor($source['ageSeconds'] / 60);
                $ageText = $ageMinutes < 60
                    ? $ageMinutes . ' min'
                    : (int) floor($ageMinutes / 60) . ' h ' . ($ageMinutes % 60) . ' min';
                $warnings[] = $source['upstreamError'] . ' Poslední úspěšné načtení před ' . $ageText . '.';
            }
        }

        $lastObservation = null;
        foreach ($observations as $registrationId => $observation) {
            if (!isset($registrations['byId'][$registrationId])
                || !$registrations['byId'][$registrationId]['hasRealTimeData']
                || $registrations['byId'][$registrationId]['component'] === 'INDX') {
                continue;
            }
            if ($lastObservation === null || $observation['timestamp'] > $lastObservation) {
                $lastObservation = $observation['timestamp'];
            }
        }
        $ageSeconds = $lastObservation === null ? null : max(0, $this->now->getTimestamp() - strtotime($lastObservation));

        return [
            'station' => [
                'code' => 'UMOM',
                'name' => (string) ($locality['Name'] ?? 'Most'),
                'active' => true,
                'region' => (string) ($locality['BasicInfo']['Region'] ?? ''),
                'district' => (string) ($locality['BasicInfo']['District'] ?? ''),
                'latitude' => $this->numberOrNull($locality['Localization']['LatAsNumber'] ?? null),
                'longitude' => $this->numberOrNull($locality['Localization']['LonAsNumber'] ?? null),
                'elevationMeters' => $this->numberOrNull($locality['Localization']['Alt'] ?? null),
            ],
            'measurements' => $measurements,
            'index' => $index,
            'meta' => [
                'generatedAt' => $this->now->format('Y-m-d\TH:i:s\Z'),
                'sourceCreatedAt' => $this->validDate($metadata['datumVytvoreni'] ?? null),
                'sourceVersion' => isset($metadata['verzeDat']) ? (string) $metadata['verzeDat'] : null,
                'lastObservation' => $lastObservation,
                'observationAgeSeconds' => $ageSeconds,
                'observationStale' => $ageSeconds !== null && $ageSeconds > 7200,
                'sources' => [
                    'metadata' => $this->sourceStatus($sources['metadata']),
                    'values' => $this->sourceStatus($sources['values']),
                    'valueTypes' => $this->sourceStatus($sources['valueTypes']),
                    'indexLevels' => $this->sourceStatus($sources['indexLevels']),
                ],
                'warnings' => array_values(array_unique($warnings)),
            ],
        ];
    }

    /**
     * @param array<int, mixed> $localities
     * @return array<string, mixed>|null
     */
    private function findMost(array $localities): ?array
    {
        foreach ($localities as $locality) {
            if (is_array($locality) && isset($locality['LocalityCode']) && $locality['LocalityCode'] === 'UMOM') {
                if (!$this->isAffirmative($locality['Active'] ?? false)) {
                    throw new RuntimeException('Stanice kvality ovzduší UMOM není v metadatech aktivní.');
                }
                return $locality;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $locality
     * @return array{byId: array<string, array<string, mixed>>, byComponent: array<string, array<string, mixed>>}
     */
    private function findRegistrations(array $locality): array
    {
        $byId = [];
        $byComponent = [];
        foreach (($locality['MeasuringPrograms'] ?? []) as $program) {
            foreach (($program['Measurements'] ?? []) as $measurement) {
                if (!is_array($measurement) || !isset($measurement['IdRegistration'], $measurement['ComponentCode'])) {
                    continue;
                }
                $component = (string) $measurement['ComponentCode'];
                if (!isset(self::POLLUTANTS[$component]) && $component !== 'INDX') {
                    continue;
                }
                $id = (string) $measurement['IdRegistration'];
                if (preg_match('/^\d{1,12}$/', $id) !== 1) {
                    continue;
                }
                $entry = [
                    'id' => $id,
                    'component' => $component,
                    'unit' => isset($measurement['UnitAsASCII']) ? trim((string) $measurement['UnitAsASCII']) : '',
                    'interval' => isset($measurement['Interval']) ? (string) $measurement['Interval'] : '',
                    'hasRealTimeData' => $this->isAffirmative($measurement['HasRealTimeData'] ?? false),
                    'dateFrom' => $this->validDate($measurement['DateFrom'] ?? null),
                ];
                $byId[$id] = $entry;
                if (!isset($byComponent[$component]) || $entry['dateFrom'] > $byComponent[$component]['dateFrom']) {
                    $byComponent[$component] = $entry;
                }
            }
        }
        return ['byId' => $byId, 'byComponent' => $byComponent];
    }

    /**
     * @param array<string, mixed>|null $registration
     * @param array<string, mixed>|null $row
     * @param array<int, string> $valueTypes
     * @return array<string, mixed>
     */
    private function measurement(string $component, string $label, ?array $registration, ?array $row, array $valueTypes): array
    {
        $result = [
            'component' => $component,
            'registrationId' => $registration === null ? null : $registration['id'],
            'label' => $label,
            'value' => null,
            'unit' => null,
            'observedAt' => null,
            'status' => 'unavailable',
            'statusText' => 'Měření pro stanici není v metadatech dostupné.',
            'valueTypeId' => null,
            'valueType' => null,
            'interval' => null,
            'stale' => false,
        ];
        if ($registration === null || !$registration['hasRealTimeData']) {
            return $result;
        }
        $result['interval'] = $registration['interval'];
        $result['unit'] = $this->normalizeUnit($registration['unit']);
        if ($result['unit'] === null) {
            $result['status'] = 'unsupported_unit';
            $result['statusText'] = 'Jednotka měření není podporována; hodnota se nezobrazuje.';
            $result['unit'] = $registration['unit'];
            return $result;
        }
        if ($row === null) {
            $result['status'] = 'no_data';
            $result['statusText'] = 'V aktuálním souboru ČHMÚ není hodnota.';
            return $result;
        }

        $result['observedAt'] = $row['timestamp'];
        $result['valueTypeId'] = $row['idValueType'];
        $result['valueType'] = isset($valueTypes[$row['idValueType']])
            ? $valueTypes[$row['idValueType']]
            : 'Neznámý typ hodnoty';
        $result['stale'] = max(0, $this->now->getTimestamp() - strtotime($row['timestamp'])) > 7200;

        if (!in_array($row['idValueType'], [8, 9, 10, 11], true)) {
            $result['status'] = 'invalid';
            $result['statusText'] = 'Neplatný nebo chybový typ dat; koncentrace se nezobrazuje.';
            return $result;
        }
        if ($row['value'] < 0) {
            $result['status'] = 'invalid';
            $result['statusText'] = 'Zdroj obsahuje zápornou hodnotu; koncentrace se nezobrazuje.';
            return $result;
        }

        $result['value'] = $row['value'];
        $result['status'] = in_array($row['idValueType'], [10, 11], true) ? 'substituted' : 'available';
        $result['statusText'] = $result['status'] === 'substituted'
            ? 'Hodnota je substituována podle dat ČHMÚ.'
            : $result['valueType'];
        return $result;
    }

    /**
     * @param array<string, mixed>|null $registration
     * @param array<string, mixed>|null $row
     * @param array<int, string> $valueTypes
     * @param array<int, array<string, string>> $indexLevels
     * @return array<string, mixed>
     */
    private function airQualityIndex(?array $registration, ?array $row, array $valueTypes, array $indexLevels): array
    {
        if ($registration === null || !$registration['hasRealTimeData']) {
            return [
                'available' => false,
                'value' => null,
                'level' => null,
                'label' => 'Index není pro UMOM publikován jako aktuální měření.',
                'observedAt' => null,
                'status' => 'not_realtime',
                'valueType' => null,
            ];
        }
        if ($row === null) {
            return [
                'available' => false,
                'value' => null,
                'level' => null,
                'label' => 'Aktuální index není v datovém souboru.',
                'observedAt' => null,
                'status' => 'no_data',
                'valueType' => null,
            ];
        }
        if ($row['idValueType'] !== 148) {
            return [
                'available' => false,
                'value' => null,
                'level' => null,
                'label' => 'Typ dat nepotvrzuje platný index kvality ovzduší.',
                'observedAt' => $row['timestamp'],
                'status' => 'invalid',
                'valueType' => isset($valueTypes[$row['idValueType']]) ? $valueTypes[$row['idValueType']] : null,
            ];
        }
        if ($row['value'] === 0.0) {
            return [
                'available' => false,
                'value' => null,
                'level' => null,
                'label' => 'Index má neúplná data; hodnota se nezobrazuje.',
                'observedAt' => $row['timestamp'],
                'status' => 'incomplete',
                'valueType' => $valueTypes[148],
            ];
        }
        $indexId = (int) round($row['value']);
        if (!isset($indexLevels[$indexId])) {
            return [
                'available' => false,
                'value' => null,
                'level' => null,
                'label' => 'Úroveň indexu není popsána v metadatech ČHMÚ.',
                'observedAt' => $row['timestamp'],
                'status' => 'invalid',
                'valueType' => $valueTypes[148],
            ];
        }
        return [
            'available' => true,
            'value' => $row['value'],
            'level' => $indexLevels[$indexId]['level'],
            'label' => $indexLevels[$indexId]['airQuality'],
            'observedAt' => $row['timestamp'],
            'status' => 'available',
            'valueType' => $valueTypes[148],
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function sourceStatus(array $source): array
    {
        return [
            'fetchedAt' => $source['fetchedAt'],
            'ageSeconds' => $source['ageSeconds'],
            'cacheStatus' => $source['cacheStatus'],
            'lastModified' => $source['sourceLastModified'],
            'upstreamError' => $source['upstreamError'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseCsvMap(string $csv, string $keyName, string $valueName): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (!is_array($lines) || count($lines) < 2) {
            throw new RuntimeException('Číselník typů hodnot je prázdný.');
        }
        $header = array_map('trim', str_getcsv($this->withoutBom($lines[0])));
        $keyIndex = array_search($keyName, $header, true);
        $valueIndex = array_search($valueName, $header, true);
        if ($keyIndex === false || $valueIndex === false) {
            throw new RuntimeException('Číselník typů hodnot nemá očekávané sloupce.');
        }
        $map = [];
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = str_getcsv($line);
            if (!isset($fields[$keyIndex], $fields[$valueIndex]) || !ctype_digit(trim($fields[$keyIndex]))) {
                continue;
            }
            $map[(int) trim($fields[$keyIndex])] = trim((string) $fields[$valueIndex]);
        }
        if ($map === []) {
            throw new RuntimeException('Číselník typů hodnot neobsahuje záznamy.');
        }
        return $map;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseIndexLevels(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (!is_array($lines) || count($lines) < 2) {
            throw new RuntimeException('Číselník indexu kvality ovzduší je prázdný.');
        }
        $header = array_map('trim', str_getcsv($this->withoutBom($lines[0])));
        $indices = [];
        foreach (['ID', 'Level', 'AirQuality'] as $name) {
            $indices[$name] = array_search($name, $header, true);
            if ($indices[$name] === false) {
                throw new RuntimeException('Číselník indexu nemá očekávané sloupce.');
            }
        }
        $map = [];
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = str_getcsv($line);
            $id = isset($fields[$indices['ID']]) ? trim($fields[$indices['ID']]) : '';
            if (!ctype_digit($id) || (int) $id === 0) {
                continue;
            }
            $map[(int) $id] = [
                'level' => trim((string) $fields[$indices['Level']]),
                'airQuality' => trim((string) $fields[$indices['AirQuality']]),
            ];
        }
        return $map;
    }

    /**
     * @return list<array{idRegistration: int, timestamp: string, idValueType: int, value: float}>
     */
    private function parseValues(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (!is_array($lines) || count($lines) < 2 || count($lines) > 100001) {
            throw new RuntimeException('Soubor měření má neplatný počet řádků.');
        }
        $header = array_map('trim', str_getcsv($this->withoutBom($lines[0])));
        $expected = ['idRegistration', 'startTime', 'idValueType', 'value'];
        if ($header !== $expected) {
            throw new RuntimeException('Soubor měření nemá očekávanou hlavičku.');
        }
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = array_map('trim', str_getcsv($line));
            if (count($fields) !== 4
                || preg_match('/^\d{1,12}$/', $fields[0]) !== 1
                || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $fields[1]) !== 1
                || preg_match('/^\d{1,4}$/', $fields[2]) !== 1
                || preg_match('/^-?(?:\d+\.?\d*|\.\d+)$/', $fields[3]) !== 1) {
                throw new RuntimeException('Soubor měření obsahuje neplatný řádek.');
            }
            $value = (float) $fields[3];
            if (!is_finite($value)) {
                throw new RuntimeException('Soubor měření obsahuje neplatnou číselnou hodnotu.');
            }
            $rows[] = [
                'idRegistration' => (int) $fields[0],
                'timestamp' => $fields[1],
                'idValueType' => (int) $fields[2],
                'value' => $value,
            ];
        }
        return $rows;
    }

    private function normalizeUnit(string $unit): ?string
    {
        $normalized = strtolower(str_replace([' ', 'μ', 'µ'], ['', 'u', 'u'], $unit));
        return in_array($normalized, ['ug/m^3', 'ug/m3'], true) ? 'µg/m³' : null;
    }

    private function isAffirmative($value): bool
    {
        return $value === true || $value === 1 || in_array(strtolower(trim((string) $value)), ['ano', 'yes', 'true', '1'], true);
    }

    private function numberOrNull($value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function validDate($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function withoutBom(string $value): string
    {
        return substr($value, 0, 3) === "\xEF\xBB\xBF" ? substr($value, 3) : $value;
    }
}
