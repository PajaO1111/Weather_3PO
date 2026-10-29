<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use PDO;
use RuntimeException;
use Throwable;

final class PhenologyImportService
{
    private const CATEGORIES = ['wood_species', 'herbs'];

    /** @var PDO */
    private $pdo;

    /** @var array<string, \PDOStatement> */
    private $upserts = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Rebuilds the phenology snapshot atomically from the official CSV directory.
     *
     * @return array{files:int,stations:int,species:int,observations:int,conflicts:int,importedAt:string}
     */
    public function importDirectory(string $root): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if ($root === '' || !is_dir($root)) {
            throw new RuntimeException('Adresář se zdrojovými CSV fenologie neexistuje.');
        }

        $definitions = [];
        $phaseDefinitions = null;
        foreach (self::CATEGORIES as $category) {
            $categoryRoot = $root . DIRECTORY_SEPARATOR . $category;
            $dataDirectory = $categoryRoot . DIRECTORY_SEPARATOR . 'data';
            $metadataDirectory = $categoryRoot . DIRECTORY_SEPARATOR . 'metadata';
            $dataFiles = glob($dataDirectory . DIRECTORY_SEPARATOR . '*.csv');
            if (!is_array($dataFiles) || $dataFiles === []) {
                throw new RuntimeException('Chybí CSV data pro kategorii ' . $category . '.');
            }
            $phasePath = $metadataDirectory . DIRECTORY_SEPARATOR . 'Seznam_fenofaze.csv';
            $categoryPhases = self::parsePhaseCsv($this->readFile($phasePath));
            if ($phaseDefinitions !== null && $categoryPhases !== $phaseDefinitions) {
                throw new RuntimeException('Číselníky fenofází ČHMÚ se mezi kategoriemi liší.');
            }
            $phaseDefinitions = $categoryPhases;

            sort($dataFiles, SORT_STRING);
            foreach ($dataFiles as $dataPath) {
                $speciesCode = pathinfo($dataPath, PATHINFO_FILENAME);
                if (preg_match('/^[A-Za-z0-9_]{1,80}$/D', $speciesCode) !== 1) {
                    throw new RuntimeException('Název souboru druhu obsahuje nepovolené znaky.');
                }
                $stationPath = $metadataDirectory . DIRECTORY_SEPARATOR . $speciesCode . '_stanice.csv';
                $dataCsv = $this->readFile($dataPath);
                $stationCsv = $this->readFile($stationPath);
                try {
                    $parsedData = self::parseObservationCsv($dataCsv);
                } catch (RuntimeException $exception) {
                    throw new RuntimeException('Soubor ' . basename($dataPath) . ': ' . $exception->getMessage(), 0, $exception);
                }
                try {
                    $stations = self::parseStationCsv($stationCsv, $categoryPhases);
                } catch (RuntimeException $exception) {
                    throw new RuntimeException('Soubor ' . basename($stationPath) . ': ' . $exception->getMessage(), 0, $exception);
                }
                if (strtolower($parsedData['speciesName']) !== strtolower($stations['speciesName'])) {
                    throw new RuntimeException('Název druhu v datech a metadatech nesouhlasí: ' . $speciesCode . '.');
                }
                $definitions[] = [
                    'category' => $category,
                    'speciesCode' => $speciesCode,
                    'speciesName' => $parsedData['speciesName'],
                    'dataPath' => $dataPath,
                    'stationPath' => $stationPath,
                    'data' => $parsedData['rows'],
                    'stations' => $stations['stations'],
                ];
            }
        }
        if (!is_array($phaseDefinitions) || $definitions === []) {
            throw new RuntimeException('Zdrojová data fenologie neobsahují žádné druhy nebo fenofáze.');
        }

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->upserts = [];
        $importedAt = gmdate('Y-m-d H:i:s');
        $fileCount = 0;
        $stationCount = 0;
        $observationCount = 0;
        $conflictCount = 0;
        try {
            $this->pdo->beginTransaction();
            $this->clearSnapshot();
            foreach ($phaseDefinitions as $phaseCode => $phaseName) {
                $this->upsert('phenology_phases', ['phase_code' => $phaseCode, 'phase_name' => $phaseName], ['phase_code'], $driver);
            }

            foreach ($definitions as $definition) {
                $this->upsert('phenology_species', [
                    'species_code' => $definition['speciesCode'],
                    'species_name' => $definition['speciesName'],
                    'category' => $definition['category'],
                ], ['species_code'], $driver);

                foreach ($definition['stations'] as $station) {
                    $this->upsert('phenology_stations', [
                        'station_code' => $station['code'],
                        'station_name' => $station['name'],
                        'elevation_m' => $station['elevation'],
                        'latitude' => $station['latitude'],
                        'longitude' => $station['longitude'],
                    ], ['station_code'], $driver);
                    $this->upsert('phenology_station_species', [
                        'station_code' => $station['code'],
                        'species_code' => $definition['speciesCode'],
                        'declared_start_year' => $station['startYear'],
                        'declared_end_year' => $station['endYear'],
                        'declared_periods' => $station['declaredPeriods'],
                    ], ['station_code', 'species_code'], $driver);
                    $stationCount++;
                }

                $grouped = [];
                foreach ($definition['data'] as $row) {
                    $key = $row['stationCode'] . '|' . $row['phaseCode'] . '|' . $row['year'];
                    if (!isset($definition['stations'][$row['stationCode']])) {
                        throw new RuntimeException('Pozorování odkazuje na stanici bez metadat: ' . $row['stationCode'] . '.');
                    }
                    if (!isset($phaseDefinitions[$row['phaseCode']])) {
                        throw new RuntimeException('Pozorování odkazuje na neznámou fenofázi: ' . $row['phaseCode'] . '.');
                    }
                    if (!isset($grouped[$key])) {
                        $grouped[$key] = ['row' => $row, 'rawValues' => [$row['rawValue']]];
                    } else {
                        $grouped[$key]['rawValues'][] = $row['rawValue'];
                        if (count($grouped[$key]['rawValues']) > 255) {
                            throw new RuntimeException('Příliš mnoho duplicitních hodnot jednoho pozorování.');
                        }
                    }
                }
                foreach ($grouped as $group) {
                    $row = $group['row'];
                    $rawValues = $group['rawValues'];
                    $distinctValues = array_values(array_unique($rawValues));
                    $conflict = count($distinctValues) > 1;
                    if ($conflict) {
                        $conflictCount++;
                    }
                    $this->upsert('phenology_observations', [
                        'station_code' => $row['stationCode'],
                        'species_code' => $definition['speciesCode'],
                        'phase_code' => $row['phaseCode'],
                        'year_num' => $row['year'],
                        'day_of_year' => $conflict || $row['status'] !== 'observed' ? null : $row['dayOfYear'],
                        'status' => $conflict ? 'conflict' : $row['status'],
                        'source_count' => count($rawValues),
                        'source_values' => implode(',', $rawValues),
                    ], ['station_code', 'species_code', 'phase_code', 'year_num'], $driver);
                    $observationCount++;
                }

                foreach ([$definition['dataPath'], $definition['stationPath']] as $sourcePath) {
                    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $sourcePath);
                    $rowCount = $sourcePath === $definition['dataPath']
                        ? count($definition['data'])
                        : count($definition['stations']);
                    $this->upsert('phenology_import_files', [
                        'source_path' => str_replace(DIRECTORY_SEPARATOR, '/', $relative),
                        'sha256' => hash_file('sha256', $sourcePath),
                        'row_count' => $rowCount,
                        'imported_at' => $importedAt,
                    ], ['source_path'], $driver);
                    $fileCount++;
                }
            }

            foreach (self::CATEGORIES as $category) {
                $phasePath = $root . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . 'metadata'
                    . DIRECTORY_SEPARATOR . 'Seznam_fenofaze.csv';
                $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $phasePath);
                $this->upsert('phenology_import_files', [
                    'source_path' => str_replace(DIRECTORY_SEPARATOR, '/', $relative),
                    'sha256' => hash_file('sha256', $phasePath),
                    'row_count' => count($phaseDefinitions),
                    'imported_at' => $importedAt,
                ], ['source_path'], $driver);
                $fileCount++;
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'files' => $fileCount,
            'stations' => $stationCount,
            'species' => count($definitions),
            'observations' => $observationCount,
            'conflicts' => $conflictCount,
            'importedAt' => $importedAt,
        ];
    }

    /**
     * @return array{speciesName:string,rows:array<int,array{stationCode:string,year:int,phaseCode:string,dayOfYear:?int,status:string,rawValue:string}>}
     */
    public static function parseObservationCsv(string $contents): array
    {
        $rows = self::csvRows($contents);
        $header = array_shift($rows);
        // ČHMÚ uses the species name instead of "Plodina" in one published herb file.
        if (!is_array($header) || count($header) !== 5 || $header[0] !== 'Stanice'
            || $header[1] !== 'Rok' || trim($header[2]) === ''
            || $header[3] !== 'Fenofáze' || $header[4] !== 'Julian') {
            throw new RuntimeException('CSV pozorování ČHMÚ má neočekávanou hlavičku.');
        }
        $speciesName = null;
        $observations = [];
        foreach ($rows as $row) {
            if (count($row) !== 5) {
                throw new RuntimeException('CSV pozorování ČHMÚ obsahuje řádek s chybným počtem sloupců.');
            }
            [$stationCode, $yearText, $name, $phaseCode, $dayText] = $row;
            if (preg_match('/^[A-Z0-9]{4,16}$/D', $stationCode) !== 1
                || !preg_match('/^\d{4}$/D', $yearText)
                || preg_match('/^[A-Z0-9]{2,10}$/D', $phaseCode) !== 1
                || trim($name) === '') {
                throw new RuntimeException('CSV pozorování ČHMÚ obsahuje neplatný identifikátor.');
            }
            $year = (int) $yearText;
            if ($year < 1800 || $year > 2200) {
                throw new RuntimeException('Rok v CSV pozorování ČHMÚ je mimo podporovaný rozsah.');
            }
            if ($speciesName === null) {
                $speciesName = trim($name);
            } elseif ($speciesName !== trim($name)) {
                throw new RuntimeException('CSV pozorování obsahuje více názvů druhu.');
            }
            if ($dayText === '444') {
                $dayOfYear = null;
                $status = 'not_occurred';
            } else {
                if (!preg_match('/^\d{1,3}$/D', $dayText)) {
                    throw new RuntimeException('Den v roce musí být platné číslo nebo zvláštní hodnota 444.');
                }
                $dayOfYear = (int) $dayText;
                $maxDay = self::isLeapYear($year) ? 366 : 365;
                if ($dayOfYear < 1 || $dayOfYear > $maxDay) {
                    throw new RuntimeException('Den v roce neodpovídá délce uvedeného roku.');
                }
                $status = 'observed';
            }
            $observations[] = [
                'stationCode' => $stationCode,
                'year' => $year,
                'phaseCode' => $phaseCode,
                'dayOfYear' => $dayOfYear,
                'status' => $status,
                'rawValue' => $dayText,
            ];
        }
        if ($speciesName === null || $observations === []) {
            throw new RuntimeException('CSV pozorování ČHMÚ neobsahuje žádné řádky s daty.');
        }
        return ['speciesName' => $speciesName, 'rows' => $observations];
    }

    /**
     * @param array<string,string> $knownPhases
     * @return array{speciesName:string,stations:array<string,array{code:string,name:string,elevation:?int,latitude:?float,longitude:?float,startYear:int,endYear:int,declaredPeriods:string}>}
     */
    public static function parseStationCsv(string $contents, array $knownPhases): array
    {
        $rows = self::csvRows($contents);
        $header = array_shift($rows);
        if (!is_array($header) || count($header) !== 7
            || array_slice($header, 0, 6) !== ['Název stanice', 'Indikativ stanice', 'Nadmořská výška (m)', 'z.š.', 'z.d.', 'Období']
            || strpos($header[6], 'Fenofáze (') !== 0) {
            throw new RuntimeException('CSV metadat stanic ČHMÚ má neočekávanou hlavičku.');
        }
        $speciesName = trim(substr($header[6], strlen('Fenofáze (')));
        $speciesName = rtrim($speciesName, ')');
        if ($speciesName === '') {
            throw new RuntimeException('V metadatech stanic ČHMÚ chybí název druhu.');
        }
        $stations = [];
        foreach ($rows as $row) {
            if (count($row) !== 7) {
                throw new RuntimeException('CSV metadat stanic ČHMÚ obsahuje řádek s chybným počtem sloupců.');
            }
            [$name, $code, $elevationText, $latitudeText, $longitudeText, $period, $phasesText] = $row;
            if (trim($name) === '' || preg_match('/^[A-Z0-9]{4,16}$/D', $code) !== 1
                || !preg_match('/^\d{4}(?:-\d{4})?(?:,\s*\d{4}(?:-\d{4})?)*$/D', $period)) {
                throw new RuntimeException('CSV metadat stanic ČHMÚ obsahuje neplatnou stanici nebo období.');
            }
            preg_match_all('/(\d{4})(?:-(\d{4}))?/', $period, $periodMatches, PREG_SET_ORDER);
            $periods = [];
            foreach ($periodMatches as $periodMatch) {
                $periodStart = (int) $periodMatch[1];
                $periodEnd = isset($periodMatch[2]) && $periodMatch[2] !== '' ? (int) $periodMatch[2] : $periodStart;
                if ($periodStart > $periodEnd || $periodStart < 1800 || $periodEnd > 2200) {
                    throw new RuntimeException('Období stanice je neplatné.');
                }
                $periods[] = [$periodStart, $periodEnd];
            }
            $startYear = min(array_column($periods, 0));
            $endYear = max(array_column($periods, 1));
            $elevation = self::nullableInteger($elevationText, -500, 9000);
            $latitude = self::nullableDecimal($latitudeText, -90, 90);
            $longitude = self::nullableDecimal($longitudeText, -180, 180);
            $phaseCodes = array_map('trim', explode(',', $phasesText));
            foreach ($phaseCodes as $phaseCode) {
                if ($phaseCode !== '' && !isset($knownPhases[$phaseCode])) {
                    throw new RuntimeException('Metadata stanice obsahují neznámou fenofázi: ' . $phaseCode . '.');
                }
            }
            if (isset($stations[$code])) {
                throw new RuntimeException('CSV metadat stanic obsahuje duplicitní indikativ.');
            }
            $stations[$code] = [
                'code' => $code,
                'name' => trim($name),
                'elevation' => $elevation,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'startYear' => $startYear,
                'endYear' => $endYear,
                'declaredPeriods' => $period,
            ];
        }
        if ($stations === []) {
            throw new RuntimeException('CSV metadat stanic ČHMÚ neobsahuje žádné stanice.');
        }
        return ['speciesName' => $speciesName, 'stations' => $stations];
    }

    /** @return array<string,string> */
    public static function parsePhaseCsv(string $contents): array
    {
        $rows = self::csvRows($contents);
        if (array_shift($rows) !== ['Zkratka fenofáze', 'Název fenofáze']) {
            throw new RuntimeException('Číselník fenofází ČHMÚ má neočekávanou hlavičku.');
        }
        $phases = [];
        foreach ($rows as $row) {
            if (count($row) !== 2 || preg_match('/^[A-Z0-9]{2,10}$/D', $row[0]) !== 1
                || trim($row[1]) === '' || isset($phases[$row[0]])) {
                throw new RuntimeException('Číselník fenofází ČHMÚ obsahuje neplatný nebo duplicitní kód.');
            }
            $phases[$row[0]] = trim($row[1]);
        }
        if ($phases === []) {
            throw new RuntimeException('Číselník fenofází ČHMÚ je prázdný.');
        }
        return $phases;
    }

    private function clearSnapshot(): void
    {
        foreach ([
            'phenology_observations',
            'phenology_station_species',
            'phenology_stations',
            'phenology_species',
            'phenology_phases',
            'phenology_import_files',
        ] as $table) {
            $this->pdo->exec('DELETE FROM ' . $table);
        }
    }

    /** @param array<string,mixed> $values @param string[] $keys */
    private function upsert(string $table, array $values, array $keys, string $driver): void
    {
        if (!isset($this->upserts[$table])) {
            $columns = array_keys($values);
            $names = implode(', ', $columns);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $updates = [];
            foreach ($columns as $column) {
                if (!in_array($column, $keys, true)) {
                    $updates[] = $column . ' = VALUES(' . $column . ')';
                }
            }
            if ($driver === 'sqlite') {
                $conflict = implode(', ', $keys);
                $assignments = [];
                foreach ($columns as $column) {
                    if (!in_array($column, $keys, true)) {
                        $assignments[] = $column . ' = excluded.' . $column;
                    }
                }
                $sql = 'INSERT INTO ' . $table . ' (' . $names . ') VALUES (' . $placeholders . ')'
                    . ' ON CONFLICT (' . $conflict . ') DO UPDATE SET ' . implode(', ', $assignments);
            } else {
                $sql = 'INSERT INTO ' . $table . ' (' . $names . ') VALUES (' . $placeholders . ')'
                    . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
            }
            $this->upserts[$table] = $this->pdo->prepare($sql);
        }
        $this->upserts[$table]->execute(array_values($values));
    }

    private function readFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Chybí povinný CSV soubor: ' . basename($path) . '.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '' || strlen($contents) > 2000000) {
            throw new RuntimeException('CSV soubor je prázdný nebo překračuje limit 2 MB: ' . basename($path) . '.');
        }
        return $contents;
    }

    /** @return array<int,array<int,string>> */
    private static function csvRows(string $contents): array
    {
        if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
            $contents = substr($contents, 3);
        }
        if (preg_match('//u', $contents) !== 1) {
            throw new RuntimeException('CSV fenologie není v platném UTF-8.');
        }
        $stream = fopen('php://temp', 'r+');
        if ($stream === false || fwrite($stream, $contents) !== strlen($contents)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new RuntimeException('CSV fenologie nelze načíst.');
        }
        rewind($stream);
        $rows = [];
        try {
            while (($row = fgetcsv($stream, 65536, ',', '"', '\\')) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }
                $rows[] = array_map(static function ($value): string {
                    return is_string($value) ? trim($value) : '';
                }, $row);
            }
        } finally {
            fclose($stream);
        }
        return $rows;
    }

    private static function nullableInteger(string $value, int $min, int $max): ?int
    {
        if (trim($value) === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/D', $value)) {
            throw new RuntimeException('Metadata stanice obsahují neplatnou nadmořskou výšku.');
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new RuntimeException('Nadmořská výška stanice je mimo povolený rozsah.');
        }
        return $number;
    }

    private static function nullableDecimal(string $value, float $min, float $max): ?float
    {
        if (trim($value) === '') {
            return null;
        }
        $value = str_replace(',', '.', $value);
        if (!is_numeric($value)) {
            throw new RuntimeException('Metadata stanice obsahují neplatné souřadnice.');
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < $min || $number > $max) {
            throw new RuntimeException('Souřadnice stanice jsou mimo povolený rozsah.');
        }
        return $number;
    }

    private static function isLeapYear(int $year): bool
    {
        return $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
    }
}
