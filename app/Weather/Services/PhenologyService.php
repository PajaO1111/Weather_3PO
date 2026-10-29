<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class PhenologyService
{
    private const DEFAULT_STATION = 'U1DOKS31';

    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function build(array $query): array
    {
        $allowed = ['station', 'species', 'phase', 'year', 'compare'];
        if (array_diff(array_keys($query), $allowed) !== []) {
            throw new InvalidArgumentException('Použijte pouze parametry stanice, druhu, fenofáze, roku a srovnání.');
        }
        foreach ($query as $key => $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException('Parametr ' . $key . ' musí být neprázdný text.');
            }
        }

        $stations = $this->stations();
        if ($stations === []) {
            throw new RuntimeException('Fenologická data zatím nejsou v databázi. Spusťte import ČHMÚ.');
        }
        $stationMap = [];
        foreach ($stations as $station) {
            $stationMap[$station['code']] = $station;
        }
        $stationCode = isset($query['station']) ? $query['station'] : $this->defaultStation($stations);
        if (!isset($stationMap[$stationCode])) {
            throw new InvalidArgumentException('Zvolená stanice nemá v importovaných datech žádná pozorování.');
        }

        $species = $this->speciesForStation($stationCode);
        if ($species === []) {
            throw new RuntimeException('Pro zvolenou stanici nejsou dostupné druhy.');
        }
        $speciesCode = isset($query['species']) ? $query['species'] : $species[0]['code'];
        $speciesMap = $this->mapByCode($species);
        if (!isset($speciesMap[$speciesCode])) {
            throw new InvalidArgumentException('Zvolený druh nemá pro vybranou stanici dostupná data.');
        }

        $phases = $this->phasesFor($stationCode, $speciesCode);
        if ($phases === []) {
            throw new RuntimeException('Pro zvolený druh nejsou dostupné fenofáze.');
        }
        $phaseCode = isset($query['phase']) ? $query['phase'] : $this->defaultPhase($phases);
        $phaseMap = $this->mapByCode($phases);
        if (!isset($phaseMap[$phaseCode])) {
            throw new InvalidArgumentException('Zvolená fenofáze nemá pro vybranou kombinaci data.');
        }

        $years = $this->yearsFor($stationCode, $speciesCode, $phaseCode);
        if ($years === []) {
            throw new RuntimeException('Pro zvolenou kombinaci nejsou dostupné roky.');
        }
        $year = isset($query['year']) ? $this->parseYear($query['year']) : max($years);
        if (!in_array($year, $years, true)) {
            throw new InvalidArgumentException('Zvolený rok pro tuto kombinaci není dostupný.');
        }

        $series = $this->series($stationCode, $speciesCode, $phaseCode);
        $observedDays = [];
        foreach ($series as $entry) {
            if ($entry['status'] === 'observed') {
                $observedDays[] = $entry['calendarDay'];
            }
        }
        $median = $this->median($observedDays);
        $calendar = $this->calendar($stationCode, $speciesCode, $year, $phases);

        $matchingStations = $this->stationsFor($speciesCode, $phaseCode);
        $matchingMap = $this->mapByCode($matchingStations);
        $observedByStation = $this->comparisonObservations($speciesCode, $phaseCode, array_keys($matchingMap));
        $compareOptions = $this->comparableStations($stationCode, $matchingStations, $observedByStation);
        $compareCodes = isset($query['compare'])
            ? $this->parseComparison($query['compare'], $stationCode, $compareOptions)
            : $this->defaultComparison($stationCode, $compareOptions);
        $comparison = $this->comparison($compareCodes, $matchingMap, $observedByStation);

        return [
            'selection' => [
                'station' => $stationMap[$stationCode],
                'species' => $speciesMap[$speciesCode],
                'phase' => $phaseMap[$phaseCode],
                'year' => $year,
            ],
            'filters' => [
                'stations' => $stations,
                'species' => $species,
                'phases' => $phases,
                'years' => $years,
                'compareStations' => $compareOptions,
            ],
            'calendar' => $calendar,
            'series' => [
                'items' => $series,
                'medianCalendarDay' => $median,
                'medianLabel' => $median === null ? null : self::calendarDayLabel($median),
                'observedYears' => count($observedDays),
            ],
            'comparison' => $comparison,
            'source' => [
                'label' => 'ČHMÚ – fenologická pozorování',
                'url' => 'https://opendata.chmi.cz/meteorology/phenology/',
                'importedAt' => $this->latestImportTime(),
            ],
            'method' => [
                'reference' => 'Medián pouze z pozorovaných nástupů; hodnoty „nenastala“ a chybějící záznamy jsou vyloučeny.',
                'calendar' => 'Přestupné roky se pro meziroční srovnání převádějí na společnou osu 365 dní.',
                'trend' => 'Řada 2014–2025 je krátká pro klimatický normál nebo spolehlivý trend.',
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function stations(): array
    {
        $statement = $this->pdo->query(
            'SELECT DISTINCT s.station_code, s.station_name, s.elevation_m, s.latitude, s.longitude '
            . 'FROM phenology_stations s INNER JOIN phenology_observations o ON o.station_code = s.station_code'
        );
        $stations = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $latitude = $row['latitude'] === null ? null : (float) $row['latitude'];
            $longitude = $row['longitude'] === null ? null : (float) $row['longitude'];
            $stations[] = [
                'code' => (string) $row['station_code'],
                'name' => (string) $row['station_name'],
                'elevationM' => $row['elevation_m'] === null ? null : (int) $row['elevation_m'],
                'latitude' => $latitude,
                'longitude' => $longitude,
                'distanceKm' => $latitude === null || $longitude === null ? null : self::distanceKm($latitude, $longitude),
            ];
        }
        usort($stations, static function (array $left, array $right): int {
            if ($left['distanceKm'] === null && $right['distanceKm'] !== null) return 1;
            if ($left['distanceKm'] !== null && $right['distanceKm'] === null) return -1;
            if ($left['distanceKm'] !== $right['distanceKm']) {
                return ($left['distanceKm'] ?? 0) <=> ($right['distanceKm'] ?? 0);
            }
            return strcasecmp($left['name'], $right['name']);
        });
        return $stations;
    }

    /** @return array<int,array{code:string,name:string,category:string}> */
    private function speciesForStation(string $stationCode): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT s.species_code, s.species_name, s.category '
            . 'FROM phenology_species s INNER JOIN phenology_observations o ON o.species_code = s.species_code '
            . 'WHERE o.station_code = ? ORDER BY s.category, s.species_name'
        );
        $statement->execute([$stationCode]);
        $species = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $species[] = [
                'code' => (string) $row['species_code'],
                'name' => (string) $row['species_name'],
                'category' => (string) $row['category'],
            ];
        }
        return $species;
    }

    /** @return array<int,array{code:string,name:string}> */
    private function phasesFor(string $stationCode, string $speciesCode): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT p.phase_code, p.phase_name '
            . 'FROM phenology_phases p INNER JOIN phenology_observations o ON o.phase_code = p.phase_code '
            . 'WHERE o.station_code = ? AND o.species_code = ? ORDER BY p.phase_name, p.phase_code'
        );
        $statement->execute([$stationCode, $speciesCode]);
        return array_map(static function (array $row): array {
            return ['code' => (string) $row['phase_code'], 'name' => (string) $row['phase_name']];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return int[] */
    private function yearsFor(string $stationCode, string $speciesCode, string $phaseCode): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT year_num FROM phenology_observations '
            . 'WHERE station_code = ? AND species_code = ? AND phase_code = ? ORDER BY year_num'
        );
        $statement->execute([$stationCode, $speciesCode, $phaseCode]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,array<string,mixed>> */
    private function series(string $stationCode, string $speciesCode, string $phaseCode): array
    {
        $statement = $this->pdo->prepare(
            'SELECT year_num, day_of_year, status, source_count, source_values FROM phenology_observations '
            . 'WHERE station_code = ? AND species_code = ? AND phase_code = ? ORDER BY year_num'
        );
        $statement->execute([$stationCode, $speciesCode, $phaseCode]);
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $year = (int) $row['year_num'];
            $day = $row['day_of_year'] === null ? null : (int) $row['day_of_year'];
            $items[] = [
                'year' => $year,
                'status' => (string) $row['status'],
                'dayOfYear' => $day,
                'calendarDay' => $day === null ? null : self::normalizedDay($year, $day),
                'date' => $day === null ? null : self::dateLabel($year, $day),
                'sourceCount' => (int) $row['source_count'],
                'sourceValues' => (string) $row['source_values'],
            ];
        }
        return $items;
    }

    /**
     * @param array<int,array{code:string,name:string}> $phases
     * @return array<int,array<string,mixed>>
     */
    private function calendar(string $stationCode, string $speciesCode, int $year, array $phases): array
    {
        $statement = $this->pdo->prepare(
            'SELECT phase_code, day_of_year, status, source_count, source_values FROM phenology_observations '
            . 'WHERE station_code = ? AND species_code = ? AND year_num = ?'
        );
        $statement->execute([$stationCode, $speciesCode, $year]);
        $byPhase = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byPhase[(string) $row['phase_code']] = $row;
        }
        $items = [];
        foreach ($phases as $phase) {
            $row = $byPhase[$phase['code']] ?? null;
            $day = $row === null || $row['day_of_year'] === null ? null : (int) $row['day_of_year'];
            $items[] = [
                'phaseCode' => $phase['code'],
                'phaseName' => $phase['name'],
                'status' => $row === null ? 'missing' : (string) $row['status'],
                'dayOfYear' => $day,
                'calendarDay' => $day === null ? null : self::normalizedDay($year, $day),
                'date' => $day === null ? null : self::dateLabel($year, $day),
                'sourceCount' => $row === null ? 0 : (int) $row['source_count'],
                'sourceValues' => $row === null ? null : (string) $row['source_values'],
            ];
        }
        return $items;
    }

    /** @param string[] $stationCodes @return array<string,array<int,int>> */
    private function comparisonObservations(string $speciesCode, string $phaseCode, array $stationCodes): array
    {
        if ($stationCodes === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($stationCodes), '?'));
        $statement = $this->pdo->prepare(
            'SELECT station_code, year_num, day_of_year FROM phenology_observations '
            . 'WHERE species_code = ? AND phase_code = ? AND status = \'observed\' '
            . 'AND station_code IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$speciesCode, $phaseCode], $stationCodes));
        $observations = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $observations[(string) $row['station_code']][(int) $row['year_num']] = (int) $row['day_of_year'];
        }
        return $observations;
    }

    /**
     * @param array<int,array<string,mixed>> $stations
     * @param array<string,array<int,int>> $observations
     * @return array<int,array<string,mixed>>
     */
    private function comparableStations(string $baseCode, array $stations, array $observations): array
    {
        $baseYears = isset($observations[$baseCode]) ? array_keys($observations[$baseCode]) : [];
        $result = [];
        foreach ($stations as $station) {
            $code = $station['code'];
            if ($code !== $baseCode && array_intersect($baseYears, array_keys($observations[$code] ?? [])) === []) {
                continue;
            }
            $result[] = $station;
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function stationsFor(string $speciesCode, string $phaseCode): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT s.station_code, s.station_name, s.elevation_m, s.latitude, s.longitude '
            . 'FROM phenology_stations s INNER JOIN phenology_observations o ON o.station_code = s.station_code '
            . 'WHERE o.species_code = ? AND o.phase_code = ?'
        );
        $statement->execute([$speciesCode, $phaseCode]);
        $stations = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $latitude = $row['latitude'] === null ? null : (float) $row['latitude'];
            $longitude = $row['longitude'] === null ? null : (float) $row['longitude'];
            $stations[] = [
                'code' => (string) $row['station_code'],
                'name' => (string) $row['station_name'],
                'elevationM' => $row['elevation_m'] === null ? null : (int) $row['elevation_m'],
                'latitude' => $latitude,
                'longitude' => $longitude,
                'distanceKm' => $latitude === null || $longitude === null ? null : self::distanceKm($latitude, $longitude),
            ];
        }
        usort($stations, static function (array $left, array $right): int {
            if ($left['distanceKm'] === null && $right['distanceKm'] !== null) return 1;
            if ($left['distanceKm'] !== null && $right['distanceKm'] === null) return -1;
            if ($left['distanceKm'] !== $right['distanceKm']) {
                return ($left['distanceKm'] ?? 0) <=> ($right['distanceKm'] ?? 0);
            }
            return strcasecmp($left['name'], $right['name']);
        });
        return $stations;
    }

    /** @param array<int,array<string,mixed>> $options @return string[] */
    private function parseComparison(string $value, string $baseCode, array $options): array
    {
        $codes = explode(',', $value);
        if (count($codes) < 2 || count($codes) > 4 || count(array_unique($codes)) !== count($codes)
            || !in_array($baseCode, $codes, true)) {
            throw new InvalidArgumentException('Srovnání musí obsahovat základní stanici a celkem 2 až 4 různé stanice.');
        }
        $allowed = array_fill_keys(array_column($options, 'code'), true);
        foreach ($codes as $code) {
            if (!isset($allowed[$code])) {
                throw new InvalidArgumentException('Vybraná stanice nemá se základní stanicí společná pozorovaná data pro tuto fenofázi.');
            }
        }
        return $codes;
    }

    /** @param array<int,array<string,mixed>> $options @return string[] */
    private function defaultComparison(string $baseCode, array $options): array
    {
        $codes = [$baseCode];
        foreach ($options as $station) {
            if ($station['code'] !== $baseCode && count($codes) < 4) {
                $codes[] = $station['code'];
            }
        }
        return $codes;
    }

    /**
     * @param string[] $codes
     * @param array<string,array<string,mixed>> $stationMap
     * @param array<string,array<int,int>> $observations
     * @return array<string,mixed>
     */
    private function comparison(array $codes, array $stationMap, array $observations): array
    {
        if (count($codes) < 2) {
            return [
                'stationCodes' => $codes,
                'commonYears' => [],
                'items' => [],
                'warning' => 'Pro tuto kombinaci není dostupná druhá stanice se společnými pozorovanými roky.',
            ];
        }
        $commonYears = null;
        foreach ($codes as $code) {
            $years = array_map('intval', array_keys($observations[$code] ?? []));
            $commonYears = $commonYears === null ? $years : array_values(array_intersect($commonYears, $years));
        }
        $commonYears = $commonYears ?? [];
        sort($commonYears, SORT_NUMERIC);
        $items = [];
        if ($commonYears !== []) {
            foreach ($codes as $code) {
                $values = [];
                foreach ($commonYears as $year) {
                    if (isset($observations[$code][$year])) {
                        $values[] = self::normalizedDay($year, $observations[$code][$year]);
                    }
                }
                $median = self::median($values);
                $station = $stationMap[$code];
                $items[] = [
                    'stationCode' => $code,
                    'stationName' => $station['name'],
                    'elevationM' => $station['elevationM'],
                    'medianCalendarDay' => $median,
                    'medianLabel' => $median === null ? null : self::calendarDayLabel($median),
                    'observedYears' => count($values),
                ];
            }
        }
        return [
            'stationCodes' => $codes,
            'commonYears' => $commonYears,
            'items' => $items,
            'warning' => $commonYears === []
                ? 'Vybrané stanice nemají pro tuto fenofázi žádný společný rok s pozorovaným nástupem.'
                : null,
        ];
    }

    private function latestImportTime(): ?string
    {
        $statement = $this->pdo->query('SELECT MAX(imported_at) FROM phenology_import_files');
        $value = $statement->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function defaultStation(array $stations): string
    {
        foreach ($stations as $station) {
            if ($station['code'] === self::DEFAULT_STATION) {
                return self::DEFAULT_STATION;
            }
        }
        return (string) $stations[0]['code'];
    }

    private function defaultPhase(array $phases): string
    {
        foreach (['PK50', 'PK10', 'BT'] as $preferred) {
            foreach ($phases as $phase) {
                if ($phase['code'] === $preferred) {
                    return $preferred;
                }
            }
        }
        return (string) $phases[0]['code'];
    }

    /** @return array<string,array<string,mixed>> */
    private function mapByCode(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $map[$item['code']] = $item;
        }
        return $map;
    }

    private function parseYear(string $value): int
    {
        if (!preg_match('/^\d{4}$/D', $value)) {
            throw new InvalidArgumentException('Rok musí být zadán jako čtyřmístné číslo.');
        }
        return (int) $value;
    }

    private static function normalizedDay(int $year, int $dayOfYear): float
    {
        if (!self::isLeapYear($year) || $dayOfYear < 60) {
            return (float) $dayOfYear;
        }
        return $dayOfYear === 60 ? 59.5 : (float) ($dayOfYear - 1);
    }

    private static function dateLabel(int $year, int $dayOfYear): string
    {
        $date = (new DateTimeImmutable($year . '-01-01', new DateTimeZone('UTC')))
            ->modify('+' . ($dayOfYear - 1) . ' days');
        return $date->format('j. n.');
    }

    private static function calendarDayLabel(float $day): string
    {
        if ($day === 59.5) {
            return '28. 2. / 1. 3.';
        }
        $lowerDay = (int) floor($day);
        $upperDay = (int) ceil($day);
        $base = new DateTimeImmutable('2021-01-01', new DateTimeZone('UTC'));
        $lower = $base->modify('+' . ($lowerDay - 1) . ' days');
        if ($lowerDay === $upperDay) {
            return $lower->format('j. n.');
        }
        $upper = $base->modify('+' . ($upperDay - 1) . ' days');
        if ($lower->format('n') === $upper->format('n')) {
            return $lower->format('j.') . '–' . $upper->format('j. n.');
        }
        return $lower->format('j. n.') . ' / ' . $upper->format('j. n.');
    }

    /** @param float[] $values */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        if (count($values) % 2 === 1) {
            return (float) $values[$middle];
        }
        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    private static function isLeapYear(int $year): bool
    {
        return $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
    }

    private static function distanceKm(float $latitude, float $longitude): float
    {
        $toRadians = static function (float $degrees): float {
            return $degrees * M_PI / 180;
        };
        $lat1 = $toRadians(50.503);
        $lat2 = $toRadians($latitude);
        $deltaLat = $toRadians($latitude - 50.503);
        $deltaLon = $toRadians($longitude - 13.636);
        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
        return round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }
}
