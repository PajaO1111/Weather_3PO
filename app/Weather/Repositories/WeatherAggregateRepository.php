<?php
/**
 * Ukládání uzavřených místních dnů a odvozených měsíčních/ročních souhrnů.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Repositories;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class WeatherAggregateRepository
{
    private const DAILY_COLUMNS = [
        'tmin', 'tmax', 'tavg', 'dew_point_avg', 'humidity_min', 'humidity_max',
        'humidity_avg', 'pressure_min', 'pressure_max', 'pressure_avg', 'wind_avg',
        'wind_max', 'gust_max', 'rain_sum', 'precip_rate_max', 'uv_max', 'solar_avg',
        'solar_max', 'sample_count', 'hours_count', 'temp_sample_count',
        'dew_point_sample_count', 'humidity_sample_count', 'pressure_sample_count',
        'wind_sample_count', 'solar_sample_count', 'calculated_at',
    ];

    /** @var PDO */
    private $pdo;

    /** @var DateTimeZone */
    private $timezone;

    /** @var DateTimeZone */
    private $utc;

    /** @var DateTimeImmutable */
    private $now;

    public function __construct(PDO $pdo, DateTimeZone $timezone, ?DateTimeImmutable $now = null)
    {
        $this->pdo = $pdo;
        $this->timezone = $timezone;
        $this->utc = new DateTimeZone('UTC');
        $this->now = ($now ?? new DateTimeImmutable('now', $this->utc))->setTimezone($this->utc);
    }

    /**
     * Vrátí již uložené dny včetně prázdných značek.
     *
     * @return array<string, array<string, mixed>> datum => řádek agregace
     */
    public function days(string $station, string $fromDate, string $toDate): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM weather_daily
             WHERE station_id = :station AND local_date >= :from_date AND local_date <= :to_date
             ORDER BY local_date'
        );
        $statement->execute([':station' => $station, ':from_date' => $fromDate, ':to_date' => $toDate]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['local_date']] = $row;
        }
        return $rows;
    }

    /**
     * Převede uložené řádky do stejného interního tvaru jako HistoryService.
     *
     * @param array<string, array<string, mixed>> $rows datum => řádek agregace
     * @return array<string, array<string, mixed>> datum => denní kbelík
     */
    public function buckets(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $date => $row) {
            // Prázdný řádek je pouze značka, že den už byl zpracován.
            if ((int) $row['sample_count'] === 0) {
                continue;
            }
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->timezone);
            if ($start === false) {
                throw new RuntimeException('Neplatne datum ulozene denni agregace.');
            }
            $sums = [];
            foreach ([
                'temp_avg' => ['tavg', 'temp_sample_count'],
                'dew_point_avg' => ['dew_point_avg', 'dew_point_sample_count'],
                'humidity_avg' => ['humidity_avg', 'humidity_sample_count'],
                'pressure_avg' => ['pressure_avg', 'pressure_sample_count'],
                'wind_avg' => ['wind_avg', 'wind_sample_count'],
                'solar_avg' => ['solar_avg', 'solar_sample_count'],
            ] as $name => [$column, $countColumn]) {
                $count = (int) $row[$countColumn];
                $value = $this->number($row[$column]);
                if ($count > 0 && $value !== null) {
                    $sums[$name] = [$value * $count, $count];
                }
            }
            $buckets[$date] = [
                'kind' => 'day',
                'start' => $start,
                'date' => $date,
                'samples' => (int) $row['sample_count'],
                'hours' => (int) $row['hours_count'],
                'sums' => $sums,
                'min' => array_filter([
                    'temp_min' => $this->number($row['tmin']),
                    'humidity_min' => $this->number($row['humidity_min']),
                    'pressure_min' => $this->number($row['pressure_min']),
                ], static function ($value): bool {
                    return $value !== null;
                }),
                'max' => array_filter([
                    'temp_max' => $this->number($row['tmax']),
                    'humidity_max' => $this->number($row['humidity_max']),
                    'pressure_max' => $this->number($row['pressure_max']),
                    'wind_max' => $this->number($row['wind_max']),
                    'gust_max' => $this->number($row['gust_max']),
                    'precip_rate_max' => $this->number($row['precip_rate_max']),
                    'uv_max' => $this->number($row['uv_max']),
                    'solar_max' => $this->number($row['solar_max']),
                ], static function ($value): bool {
                    return $value !== null;
                }),
                'precip' => $this->number($row['rain_sum']),
            ];
        }
        return $buckets;
    }

    public function isSettled(string $date): bool
    {
        $nextDay = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->timezone);
        if ($nextDay === false) {
            throw new RuntimeException('Neplatne datum denni agregace.');
        }
        $endUtc = $nextDay->add(new DateInterval('P1D'))->setTimezone($this->utc);
        $settledBefore = $this->now->sub(new DateInterval('PT' . AggregateCache::SETTLE_SECONDS . 'S'));
        return $endUtc <= $settledBefore;
    }

    /**
     * Uloží i dny bez měření; nulový sample_count označuje zpracovaný prázdný den.
     *
     * @param list<array<string, mixed>> $buckets interní denní kbelíky z HistoryService
     */
    public function saveDays(string $station, array $buckets, array $datesToMark = []): void
    {
        $rows = [];
        foreach ($buckets as $bucket) {
            $date = (string) ($bucket['date'] ?? '');
            if ($date === '') {
                throw new RuntimeException('Denni agregace nema platne datum.');
            }
            $rows[$date] = $this->dailyRow($bucket);
        }
        foreach ($datesToMark as $date) {
            if (!isset($rows[$date])) {
                $rows[$date] = $this->dailyRow($this->emptyDay((string) $date));
            }
        }
        if ($rows === []) {
            return;
        }
        ksort($rows);

        $columns = array_merge(['station_id', 'local_date'], self::DAILY_COLUMNS);
        $placeholders = array_map(static function (string $column): string {
            return ':' . $column;
        }, $columns);
        $updates = array_map(static function (string $column): string {
            return $column . ' = VALUES(' . $column . ')';
        }, array_slice($columns, 2));
        $sql = 'INSERT INTO weather_daily (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $updates = array_map(static function (string $column): string {
                return $column . ' = excluded.' . $column;
            }, array_slice($columns, 2));
            $sql = 'INSERT INTO weather_daily (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', $placeholders) . ') ON CONFLICT(station_id, local_date) DO UPDATE SET '
                . implode(', ', $updates);
        }

        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $statement = $this->pdo->prepare($sql);
            foreach ($rows as $date => $values) {
                $statement->execute(array_merge(
                    [':station_id' => $station, ':local_date' => $date],
                    $values
                ));
            }
            $periods = [];
            foreach (array_keys($rows) as $date) {
                $periods[substr($date, 0, 7)] = $date;
            }
            foreach ($periods as $date) {
                $this->refreshPeriods($station, $date);
            }
            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $bucket
     * @return array<string, mixed>
     */
    private function dailyRow(array $bucket): array
    {
        $sum = static function (string $name) use ($bucket): ?float {
            return isset($bucket['sums'][$name]) && $bucket['sums'][$name][1] > 0
                ? round($bucket['sums'][$name][0] / $bucket['sums'][$name][1], 2)
                : null;
        };
        $count = static function (string $name) use ($bucket): int {
            return (int) ($bucket['sums'][$name][1] ?? 0);
        };
        $min = static function (string $name) use ($bucket) {
            return $bucket['min'][$name] ?? null;
        };
        $max = static function (string $name) use ($bucket) {
            return $bucket['max'][$name] ?? null;
        };

        return [
            'tmin' => $min('temp_min'), 'tmax' => $max('temp_max'), 'tavg' => $sum('temp_avg'),
            'dew_point_avg' => $sum('dew_point_avg'),
            'humidity_min' => $min('humidity_min'), 'humidity_max' => $max('humidity_max'),
            'humidity_avg' => $sum('humidity_avg'),
            'pressure_min' => $min('pressure_min'), 'pressure_max' => $max('pressure_max'),
            'pressure_avg' => $sum('pressure_avg'),
            'wind_avg' => $sum('wind_avg'), 'wind_max' => $max('wind_max'),
            'gust_max' => $max('gust_max'), 'rain_sum' => $bucket['precip'],
            'precip_rate_max' => $max('precip_rate_max'), 'uv_max' => $max('uv_max'),
            'solar_avg' => $sum('solar_avg'), 'solar_max' => $max('solar_max'),
            'sample_count' => (int) $bucket['samples'], 'hours_count' => (int) $bucket['hours'],
            'temp_sample_count' => $count('temp_avg'), 'dew_point_sample_count' => $count('dew_point_avg'),
            'humidity_sample_count' => $count('humidity_avg'), 'pressure_sample_count' => $count('pressure_avg'),
            'wind_sample_count' => $count('wind_avg'), 'solar_sample_count' => $count('solar_avg'),
            'calculated_at' => $this->now->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyDay(string $date): array
    {
        return [
            'date' => $date, 'samples' => 0, 'hours' => 0, 'sums' => [],
            'min' => [], 'max' => [], 'precip' => null,
        ];
    }

    private function refreshPeriods(string $station, string $date): void
    {
        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 5, 2);
        $rows = $this->periodRows($station, $year, $month);
        $monthly = $this->rollup($rows);
        $this->upsertPeriod('weather_monthly', ['station_id', 'year_num', 'month_num'], [
            'station_id' => $station, 'year_num' => $year, 'month_num' => $month,
        ] + $monthly);

        $yearRows = $this->periodRows($station, $year, null);
        $yearly = $this->rollup($yearRows);
        unset($yearly['wind_avg']);
        $this->upsertPeriod('weather_yearly', ['station_id', 'year_num'], [
            'station_id' => $station, 'year_num' => $year,
        ] + $yearly);
    }

    /** Číselné hodnoty z PDO se ukládají jako string; NULL ponecháváme jako NULL. */
    private function number($value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /** @return list<array<string, mixed>> */
    private function periodRows(string $station, int $year, ?int $month): array
    {
        $sql = 'SELECT tmin, tmax, tavg, rain_sum, wind_avg, wind_max, sample_count,
                       temp_sample_count, wind_sample_count
                FROM weather_daily WHERE station_id = :station AND local_date >= :from_date
                  AND local_date < :to_date';
        $from = sprintf('%04d-%02d-01', $year, $month ?? 1);
        $to = $month === null
            ? sprintf('%04d-01-01', $year + 1)
            : (new DateTimeImmutable($from, $this->utc))->modify('+1 month')->format('Y-m-d');
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':station' => $station, ':from_date' => $from, ':to_date' => $to]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function rollup(array $rows): array
    {
        $tempWeighted = 0.0;
        $tempCount = 0;
        $windWeighted = 0.0;
        $windCount = 0;
        $sampleCount = 0;
        $rain = null;
        $tmin = $tmax = $windMax = null;
        foreach ($rows as $row) {
            $samples = (int) $row['sample_count'];
            $sampleCount += $samples;
            $count = (int) $row['temp_sample_count'];
            if ($row['tavg'] !== null && $count > 0) {
                $tempWeighted += (float) $row['tavg'] * $count;
                $tempCount += $count;
            }
            $count = (int) $row['wind_sample_count'];
            if ($row['wind_avg'] !== null && $count > 0) {
                $windWeighted += (float) $row['wind_avg'] * $count;
                $windCount += $count;
            }
            $tmin = $row['tmin'] === null ? $tmin : ($tmin === null ? (float) $row['tmin'] : min($tmin, (float) $row['tmin']));
            $tmax = $row['tmax'] === null ? $tmax : ($tmax === null ? (float) $row['tmax'] : max($tmax, (float) $row['tmax']));
            $windMax = $row['wind_max'] === null ? $windMax : ($windMax === null ? (float) $row['wind_max'] : max($windMax, (float) $row['wind_max']));
            if ($row['rain_sum'] !== null) {
                $rain = ($rain ?? 0.0) + (float) $row['rain_sum'];
            }
        }

        return [
            'tmin' => $tmin, 'tmax' => $tmax,
            'tavg' => $tempCount > 0 ? $tempWeighted / $tempCount : null,
            'rain_sum' => $rain,
            'wind_avg' => $windCount > 0 ? $windWeighted / $windCount : null,
            'wind_max' => $windMax, 'sample_count' => $sampleCount,
            'temp_sample_count' => $tempCount, 'wind_sample_count' => $windCount,
            'calculated_at' => $this->now->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param list<string> $keys
     * @param array<string, mixed> $values
     */
    private function upsertPeriod(string $table, array $keys, array $values): void
    {
        $columns = array_keys($values);
        $placeholders = array_map(static function (string $column): string {
            return ':' . $column;
        }, $columns);
        $updates = array_values(array_diff($columns, $keys));
        $assignments = array_map(static function (string $column): string {
            return $column . ' = VALUES(' . $column . ')';
        }, $updates);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $assignments = array_map(static function (string $column): string {
                return $column . ' = excluded.' . $column;
            }, $updates);
            $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', $placeholders) . ') ON CONFLICT(' . implode(', ', $keys) . ') DO UPDATE SET '
                . implode(', ', $assignments);
        }
        $this->pdo->prepare($sql)->execute(array_combine($placeholders, array_values($values)));
    }
}
