<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Support\ChmiJson;

final class ClimateService
{
    private const ELEMENTS = [
        'T' => ['key' => 'temperature', 'time' => 'AVG', 'function' => 'AVG', 'unit' => '°C'],
        'SRA' => ['key' => 'precipitation', 'time' => '06:00', 'function' => 'SUM', 'unit' => 'mm'],
    ];

    /** @var ChmiGateway */
    private $gateway;

    public function __construct(ChmiGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function build(?int $year = null): array
    {
        $sources = [];
        try {
            $station = $this->station($this->load('climate_history_station', 'Open.Data.Metadata', $sources));
            $elements = $this->elements($this->load('climate_history_elements', 'Open.Data.Metadata', $sources));
            $document = $this->load('kopisty_monthly', 'Open.Data.MLY', $sources);
            $series = $this->series($document, $station['periods'], $elements);
            $officialNormals = [
                'temperature' => $this->normal(
                    $this->loadNormal('kopisty_normal_temperature', $sources),
                    'TAVG',
                    'Normal.AVG',
                    '°C'
                ),
                'precipitation' => $this->normal(
                    $this->loadNormal('kopisty_normal_precipitation', $sources),
                    'SRA',
                    'Normal.SUM',
                    'mm'
                ),
            ];
        } catch (RuntimeException $exception) {
            foreach (array_keys($sources) as $resource) {
                $this->gateway->invalidate($resource);
            }
            throw $exception;
        }
        if ($series === []) {
            throw new RuntimeException('Archiv Kopist neobsahuje vybrané měsíční řady.');
        }
        $years = array_keys($series);
        rsort($years, SORT_NUMERIC);
        $year = $year ?? $years[0];
        if (!isset($series[$year])) {
            throw new InvalidArgumentException('Zvolený rok není v publikovaném archivu Kopist.');
        }
        $reference = [];
        $warnings = [];
        for ($month = 1; $month <= 12; $month++) {
            $entry = ['month' => $month];
            foreach (self::ELEMENTS as $definition) {
                $key = $definition['key'];
                $values = [];
                for ($referenceYear = 1991; $referenceYear <= 2020; $referenceYear++) {
                    $value = $series[$referenceYear][$month][$key]['value'] ?? null;
                    if ($value !== null) $values[] = $value;
                }
                $entry[$key] = [
                    'value' => count($values) === 30 ? array_sum($values) / 30 : null,
                    'count' => count($values), 'expected' => 30, 'unit' => $definition['unit'],
                ];
                if (count($values) !== 30) {
                    $warnings[] = 'Referenční období není úplné; průměr se z méně než 30 hodnot nezobrazuje.';
                }
            }
            $reference[] = $entry;
        }
        $annual = [];
        for ($referenceYear = 1991; $referenceYear <= 2020; $referenceYear++) {
            $sum = $this->annualRainfall($series[$referenceYear] ?? []);
            if ($sum !== null) $annual[] = $sum;
        }
        $items = [];
        for ($month = 1; $month <= 12; $month++) {
            $item = ['month' => $month];
            foreach (self::ELEMENTS as $definition) {
                $key = $definition['key'];
                $item[$key] = $series[$year][$month][$key] ?? $this->missing();
                $base = $reference[$month - 1][$key]['value'];
                $value = $item[$key]['value'];
                $item[$key . 'Difference'] = $base !== null && $value !== null ? $value - $base : null;
            }
            $item['officialNormal'] = [
                'temperature' => $officialNormals['temperature']['months'][$month],
                'temperatureDifference' => $item['temperature']['value'] === null
                    ? null
                    : $item['temperature']['value'] - $officialNormals['temperature']['months'][$month],
                'precipitation' => $officialNormals['precipitation']['months'][$month],
                'precipitationDifference' => $item['precipitation']['value'] === null
                    ? null
                    : $item['precipitation']['value'] - $officialNormals['precipitation']['months'][$month],
                'precipitationPercent' => $item['precipitation']['value'] === null
                    ? null
                    : $item['precipitation']['value'] / $officialNormals['precipitation']['months'][$month] * 100,
            ];
            $items[] = $item;
        }
        foreach ($sources as $source) {
            if ($source['cacheStatus'] === 'stale') {
                $warnings[] = $source['upstreamError'];
            }
        }
        return [
            'station' => $station, 'year' => $year, 'years' => $years, 'items' => $items,
            'rainfallAnnual' => $this->annualRainfall($series[$year]),
            'reference' => [
                'label' => 'Vypočtený referenční průměr ČHMÚ Kopisty 1991–2020',
                'fromYear' => 1991, 'toYear' => 2020, 'months' => $reference,
                'rainfallAnnual' => count($annual) === 30 ? array_sum($annual) / 30 : null,
                'completeYears' => count($annual),
            ],
            'officialNormal' => [
                'label' => 'Oficiální staniční klimatologický normál ČHMÚ 1991–2020',
                'fromYear' => 1991,
                'toYear' => 2020,
                'months' => array_map(static function (int $month) use ($officialNormals): array {
                    return [
                        'month' => $month,
                        'temperature' => $officialNormals['temperature']['months'][$month],
                        'precipitation' => $officialNormals['precipitation']['months'][$month],
                    ];
                }, range(1, 12)),
                'annual' => [
                    'temperature' => $officialNormals['temperature']['annual'],
                    'precipitation' => $officialNormals['precipitation']['annual'],
                ],
                'sources' => [
                    'temperature' => 'https://opendata.chmi.cz' . $this->sourcePath('kopisty_normal_temperature'),
                    'precipitation' => 'https://opendata.chmi.cz' . $this->sourcePath('kopisty_normal_precipitation'),
                ],
            ],
            'officialComparison' => [
                'annualRainfallDifference' => $this->annualRainfall($series[$year]) === null
                    ? null
                    : $this->annualRainfall($series[$year]) - $officialNormals['precipitation']['annual'],
                'annualRainfallPercent' => $this->annualRainfall($series[$year]) === null
                    ? null
                    : $this->annualRainfall($series[$year]) / $officialNormals['precipitation']['annual'] * 100,
            ],
            'meta' => [
                'sourceCreatedAt' => ChmiJson::time($document['datumVytvoreni']),
                'sourceVersion' => $document['verzeDat'], 'sources' => $sources,
                'units' => ['temperature' => '°C', 'precipitation' => 'mm'],
                'functions' => ['temperature' => 'T / AVG / AVG', 'precipitation' => 'SRA / 06:00 / SUM'],
                'warnings' => array_values(array_unique($warnings)),
            ],
        ];
    }

    private function load(string $resource, string $stream, array &$sources): array
    {
        $response = $this->gateway->fetch($resource);
        $body = $response['body'];
        unset($response['body']);
        $sources[$resource] = $response;
        $document = json_decode($body, true, 64);
        if (!is_array($document) || json_last_error() !== JSON_ERROR_NONE
            || ($document['datovyTokID'] ?? null) !== $stream
            || !is_string($document['verzeDat'] ?? null) || $document['verzeDat'] === '') {
            throw new RuntimeException('Neplatný klimatologický JSON nebo datový tok ČHMÚ.');
        }
        $sources[$resource]['createdAt'] = ChmiJson::time($document['datumVytvoreni'] ?? null);
        $sources[$resource]['version'] = $document['verzeDat'];
        return $document;
    }

    private function loadNormal(string $resource, array &$sources): string
    {
        $response = $this->gateway->fetch($resource);
        $body = $response['body'];
        unset($response['body']);
        $sources[$resource] = $response;
        $sources[$resource]['createdAt'] = null;
        $sources[$resource]['version'] = 'klimatologický normál 1991–2020';
        if ($body === '' || strlen($body) > 25000) {
            throw new RuntimeException('Soubor staničního normálu je prázdný nebo překračuje limit.');
        }
        return $body;
    }

    /**
     * @return array{months: array<int, float>, annual: float}
     */
    private function normal(string $csv, string $element, string $valueHeader, string $unit): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('CSV staničního normálu nelze zpracovat.');
        }
        try {
            if (fwrite($stream, $csv) !== strlen($csv)) {
                throw new RuntimeException('CSV staničního normálu nelze přečíst.');
            }
            rewind($stream);
            $header = fgetcsv($stream, 4096, ',', '"', '\\');
            if (!is_array($header) || $header !== ['Eg.Gh.Id', 'Eg.El.Abbreviation', 'Month', $valueHeader]) {
                throw new RuntimeException('CSV staničního normálu má neočekávaný formát.');
            }
            $months = [];
            $annual = null;
            while (($row = fgetcsv($stream, 4096, ',', '"', '\\')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== 4 || $row[0] !== KopistyService::WSI || $row[1] !== $element
                    || !preg_match('/^(?:[1-9]|1[0-3])$/D', $row[2])
                    || !preg_match('/^-?(?:\d+(?:\.\d*)?|\.\d+)$/D', $row[3])) {
                    throw new RuntimeException('CSV staničního normálu obsahuje neplatný řádek.');
                }
                $month = (int) $row[2];
                $value = (float) $row[3];
                if (!is_finite($value) || ($unit === 'mm' && $value < 0)) {
                    throw new RuntimeException('Hodnota staničního normálu je mimo povolený rozsah.');
                }
                if ($month === 13) {
                    if ($annual !== null) {
                        throw new RuntimeException('CSV staničního normálu obsahuje duplicitní roční hodnotu.');
                    }
                    $annual = $value;
                } else {
                    if (isset($months[$month])) {
                        throw new RuntimeException('CSV staničního normálu obsahuje duplicitní měsíc.');
                    }
                    $months[$month] = $value;
                }
            }
            if (count($months) !== 12 || $annual === null) {
                throw new RuntimeException('CSV staničního normálu neobsahuje všech 12 měsíců a roční hodnotu.');
            }
            ksort($months, SORT_NUMERIC);
            return ['months' => $months, 'annual' => $annual];
        } finally {
            fclose($stream);
        }
    }

    private function sourcePath(string $resource): string
    {
        $paths = [
            'kopisty_normal_temperature' => '/meteorology/products/climate_normal_stations/period_1991_2020/temperature/0-20000-0-11433_T_1991_2020_normal.csv',
            'kopisty_normal_precipitation' => '/meteorology/products/climate_normal_stations/period_1991_2020/precipitation/0-20000-0-11433_SRA_1991_2020_normal.csv',
        ];
        if (!isset($paths[$resource])) {
            throw new RuntimeException('Neznámý zdroj klimatologického normálu.');
        }
        return $paths[$resource];
    }

    private function station(array $document): array
    {
        $periods = [];
        $name = null;
        foreach (ChmiJson::rows($document, ['WSI', 'FULL_NAME', 'BEGIN_DATE', 'END_DATE', 'GEOGR1', 'GEOGR2', 'ELEVATION']) as $row) {
            if ($row['WSI'] !== KopistyService::WSI) continue;
            if (!is_string($row['FULL_NAME']) || trim($row['FULL_NAME']) === '') {
                throw new RuntimeException('Chybí název stanice Kopisty.');
            }
            $name = $row['FULL_NAME'];
            $periods[] = [
                'from' => ChmiJson::time($row['BEGIN_DATE']), 'to' => ChmiJson::time($row['END_DATE']),
                'latitude' => ChmiJson::number($row['GEOGR2']), 'longitude' => ChmiJson::number($row['GEOGR1']),
                'elevation' => ChmiJson::number($row['ELEVATION']),
            ];
        }
        if ($name === null) throw new RuntimeException('Kopisty chybí v historických metadatech.');
        return ['id' => KopistyService::WSI, 'name' => $name, 'periods' => $periods];
    }

    private function elements(array $document): array
    {
        $periods = ['T' => [], 'SRA' => []];
        foreach (ChmiJson::rows($document, ['OBS_TYPE', 'WSI', 'BEGIN_DATE', 'END_DATE', 'EG_EL_ABBREVIATION', 'UN_DESCRIPTION', 'SCHEDULE']) as $row) {
            $element = $row['EG_EL_ABBREVIATION'];
            if ($row['WSI'] !== KopistyService::WSI || $row['OBS_TYPE'] !== 'DLY'
                || !is_string($element) || !isset(self::ELEMENTS[$element])) continue;
            $definition = self::ELEMENTS[$element];
            if (!is_string($row['UN_DESCRIPTION']) || trim($row['UN_DESCRIPTION']) !== $definition['unit']
                || !is_string($row['SCHEDULE'])
                || !in_array($definition['time'], explode(',', $row['SCHEDULE']), true)) {
                throw new RuntimeException('Historická metadata nepotvrzují jednotku nebo termín prvku ' . $element . '.');
            }
            // MLY jsou měsíční statistiky denních prvků; metadata publikují jejich jednotky jako DLY.
            $periods[$element][] = ['from' => ChmiJson::time($row['BEGIN_DATE']), 'to' => ChmiJson::time($row['END_DATE'])];
        }
        return $periods;
    }

    private function series(array $document, array $stationPeriods, array $elementPeriods): array
    {
        $series = [];
        foreach (ChmiJson::rows($document, ['STATION', 'ELEMENT', 'YEAR', 'MONTH', 'TIMEFUNCTION', 'MDFUNCTION', 'VALUE', 'FLAG_REPEAT', 'FLAG_INTERRUPTED']) as $row) {
            $element = $row['ELEMENT'];
            if ($row['STATION'] !== KopistyService::WSI || !is_string($element) || !isset(self::ELEMENTS[$element])) continue;
            $definition = self::ELEMENTS[$element];
            if ($row['TIMEFUNCTION'] !== $definition['time'] || $row['MDFUNCTION'] !== $definition['function']) continue;
            if (!is_scalar($row['YEAR']) || !preg_match('/^\d{4}$/D', (string) $row['YEAR'])
                || !is_scalar($row['MONTH']) || !preg_match('/^(0?[1-9]|1[0-2])$/D', (string) $row['MONTH'])
                || !is_string($row['FLAG_REPEAT']) || !is_string($row['FLAG_INTERRUPTED'])) {
                throw new RuntimeException('Neplatné období nebo příznak měsíčního měření.');
            }
            $year = (int) $row['YEAR'];
            $month = (int) $row['MONTH'];
            if ($year < 1800 || $year >= (int) gmdate('Y')) {
                throw new RuntimeException('Historický archiv obsahuje neuzavřený nebo nepodporovaný rok.');
            }
            $raw = $row['VALUE'] === '' || $row['VALUE'] === null ? null : ChmiJson::number($row['VALUE']);
            if ($raw !== null && (!$this->described($year, $month, $stationPeriods)
                || !$this->described($year, $month, $elementPeriods[$element]))) {
                throw new RuntimeException(sprintf('Měsíční hodnota %s %04d-%02d nemá pokrytí v historických metadatech.', $element, $year, $month));
            }
            if ($element === 'SRA' && $raw !== null && $raw < 0) {
                throw new RuntimeException('Záporný srážkový úhrn není platná hodnota.');
            }
            $status = $raw === null ? 'missing'
                : ($row['FLAG_REPEAT'] !== '' || $row['FLAG_INTERRUPTED'] !== '' ? 'flagged' : 'available');
            $item = [
                'value' => $status === 'available' ? $raw : null, 'rawValue' => $raw, 'status' => $status,
                'flagRepeat' => $row['FLAG_REPEAT'], 'flagInterrupted' => $row['FLAG_INTERRUPTED'],
            ];
            $key = $definition['key'];
            if (isset($series[$year][$month][$key])) {
                throw new RuntimeException('Duplicitní měsíc nelze bezpečně sloučit.');
            }
            $series[$year][$month][$key] = $item;
        }
        return $series;
    }

    private function described(int $year, int $month, array $periods): bool
    {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01T00:00:00Z', $year, $month), new DateTimeZone('UTC'));
        $end = $start->modify('+1 month')->getTimestamp();
        // DLY metadata potvrzují význam/jednotku, nikoli úplnost podkladů publikované MLY statistiky.
        foreach ($periods as $period) {
            if (strtotime($period['from']) < $end && strtotime($period['to']) >= $start->getTimestamp()) {
                return true;
            }
        }
        return false;
    }

    private function missing(): array
    {
        return ['value' => null, 'rawValue' => null, 'status' => 'missing', 'flagRepeat' => '', 'flagInterrupted' => ''];
    }

    private function annualRainfall(array $months): ?float
    {
        $sum = 0.0;
        for ($month = 1; $month <= 12; $month++) {
            $value = $months[$month]['precipitation']['value'] ?? null;
            if ($value === null) return null;
            $sum += $value;
        }
        return $sum;
    }
}
