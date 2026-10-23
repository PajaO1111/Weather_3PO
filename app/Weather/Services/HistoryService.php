<?php
/**
 * Historie měření za zvolené období (stránka „Historie“, api/history.php).
 *
 * Podle podrobnosti vrací:
 *  - raw  … jednotlivá měření (po 5 min) – stejný tvar jako api/observations.php,
 *  - hour … hodinové agregace (průměr / minimum / maximum, úhrn srážek),
 *  - day  … denní agregace podle MÍSTNÍHO dne (složené z hodin).
 *
 * Vždy přidá souhrn období: extrémy s časem výskytu, úhrn srážek,
 * počty klimatologických dnů (mrazové, letní, tropické…).
 *
 * Srážky: stanice ukládá jen denní čítač precip_total (nuluje se o půlnoci).
 * Úhrn za hodinu = přírůstek čítače oproti předchozí hodině téhož dne;
 * pokles čítače se bere jako vynulování. Úhrn dne = součet hodin.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OuBrix\Weather\Weather\Repositories\AggregateCache;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Support\Meteo;
use UnexpectedValueException;

final class HistoryService
{
    /** Formát časů v DB (DATETIME, UTC). */
    private const DB_FORMAT = 'Y-m-d H:i:s';

    /** Formát časů v JSON (ISO 8601 v UTC). */
    private const ISO_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** Jednotky agregovaných veličin. */
    public const AGGREGATE_UNITS = [
        'temp_avg' => '°C', 'temp_min' => '°C', 'temp_max' => '°C', 'dew_point_avg' => '°C',
        'humidity_avg' => '%', 'humidity_min' => '%', 'humidity_max' => '%',
        'pressure_avg' => 'hPa', 'pressure_min' => 'hPa', 'pressure_max' => 'hPa',
        'wind_avg' => 'km/h', 'wind_max' => 'km/h', 'gust_max' => 'km/h',
        'precip' => 'mm', 'precip_rate_max' => 'mm/h',
        'uv_max' => '', 'solar_avg' => 'W/m²', 'solar_max' => 'W/m²',
    ];

    /** Hranice klimatologických dnů (°C, mm) – běžné definice ČHMÚ; používá i StatsService. */
    public const FROST_DAY_MIN = 0.0;       // mrazový den: Tmin < 0
    public const ICE_DAY_MAX = 0.0;         // ledový den: Tmax < 0
    public const SUMMER_DAY_MAX = 25.0;     // letní den: Tmax ≥ 25
    public const TROPICAL_DAY_MAX = 30.0;   // tropický den: Tmax ≥ 30
    public const TROPICAL_NIGHT_MIN = 20.0; // tropická noc: Tmin ≥ 20
    public const RAIN_DAY_MM = 1.0;         // srážkový den: úhrn ≥ 1 mm

    /** @var ObservationRepository */
    private $repository;

    /** @var DateTimeZone místní pásmo (Europe/Prague) */
    private $timezone;

    /** @var DateTimeZone */
    private $utc;

    /** @var AggregateCache zdroj hodinových agregací, souhrnu a extrémů (cache uzavřených měsíců) */
    private $aggregates;

    public function __construct(ObservationRepository $repository, DateTimeZone $timezone, ?AggregateCache $aggregates = null)
    {
        $this->repository = $repository;
        $this->timezone = $timezone;
        $this->utc = new DateTimeZone('UTC');
        // Bez cache = průchozí režim (vše živě z DB).
        $this->aggregates = $aggregates ?? new AggregateCache($repository, $timezone, null);
    }

    /**
     * @param DateTimeImmutable $fromDate   místní půlnoc prvního dne
     * @param DateTimeImmutable $toDate     místní půlnoc posledního dne (včetně)
     * @param string            $resolution raw | hour | day
     * @param int               $rawMaxRows pojistka počtu řádků pro raw
     * @return array<string, mixed>
     */
    public function build(string $station, DateTimeImmutable $fromDate, DateTimeImmutable $toDate, string $resolution, int $rawMaxRows): array
    {
        $fromLocal = $fromDate->setTimezone($this->timezone)->setTime(0, 0, 0);
        $toLocal = $toDate->setTimezone($this->timezone)->setTime(0, 0, 0)->add(new DateInterval('P1D'));
        $fromUtc = $fromLocal->setTimezone($this->utc);
        $toUtc = $toLocal->setTimezone($this->utc);
        $fromDb = $fromUtc->format(self::DB_FORMAT);
        $toDb = $toUtc->format(self::DB_FORMAT);

        // Hodiny jsou potřeba vždy – skládají se z nich dny a souhrn období.
        $hours = $this->hours($this->aggregates->hourly($station, $fromDb, $toDb));
        $days = $this->days($hours);

        $meta = [];
        if ($resolution === 'raw') {
            $rows = $this->repository->between($station, $fromDb, $toDb, $rawMaxRows);
            $series = (new ObservationService($this->repository))->present($rows, $station, [
                'truncated' => count($rows) >= $rawMaxRows,
            ]);
            $items = $series['items'];
            $units = $series['units'];
            $meta = $series['meta'];
        } elseif ($resolution === 'hour') {
            $items = array_map([$this, 'publicBucket'], $hours);
            $units = self::AGGREGATE_UNITS;
        } elseif ($resolution === 'day') {
            $items = array_map([$this, 'publicBucket'], $days);
            $units = self::AGGREGATE_UNITS;
        } else {
            throw new UnexpectedValueException('UNKNOWN_RESOLUTION');
        }

        return [
            'station' => $station,
            'resolution' => $resolution,
            'range' => [
                'fromDate' => $fromLocal->format('Y-m-d'),
                'toDate' => $toDate->format('Y-m-d'),
                'days' => count($this->calendarDays($fromLocal, $toLocal)),
                'from' => $fromUtc->format(self::ISO_FORMAT),
                'to' => $toUtc->format(self::ISO_FORMAT),
            ],
            'units' => $units,
            'items' => $items,
            'summary' => $this->summary($station, $fromDb, $toDb, $days),
            'meta' => $meta + ['points' => count($items)],
        ];
    }

    /**
     * Řádky z ObservationRepository::hourly() → interní hodinové „kbelíky“
     * se součty pro průměry a úhrnem srážek za hodinu.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function hours(array $rows): array
    {
        $buckets = [];
        $previousDate = null;
        $previousCounter = 0.0;
        foreach ($rows as $row) {
            $start = DateTimeImmutable::createFromFormat('!Y-m-d H', (string) $row['bucket'], $this->utc);
            if ($start === false) {
                throw new UnexpectedValueException('INVALID_STORED_TIME');
            }
            $localDate = $start->setTimezone($this->timezone)->format('Y-m-d');
            $bucket = [
                'kind' => 'hour',
                'start' => $start,
                'date' => $localDate,
                'samples' => (int) $row['samples'],
                'hours' => 1,
                'sums' => [],
                'min' => [],
                'max' => [],
            ];
            foreach (array_keys(ObservationRepository::HOURLY_AVERAGES) as $name) {
                $bucket['sums'][$name] = [Meteo::toFloat($row[$name . '_sum']) ?? 0.0, (int) $row[$name . '_n']];
            }
            foreach (array_keys(ObservationRepository::HOURLY_MINIMA) as $name) {
                $bucket['min'][$name] = Meteo::toFloat($row[$name]);
            }
            foreach (array_keys(ObservationRepository::HOURLY_MAXIMA) as $name) {
                if ($name !== 'precip_counter') {
                    $bucket['max'][$name] = Meteo::toFloat($row[$name]);
                }
            }

            // Úhrn srážek za hodinu z denního čítače.
            if ($localDate !== $previousDate) {
                $previousDate = $localDate;
                $previousCounter = 0.0;
            }
            $counter = Meteo::toFloat($row['precip_counter']);
            if ($counter === null) {
                $bucket['precip'] = null;
            } else {
                $bucket['precip'] = round($counter >= $previousCounter ? $counter - $previousCounter : $counter, 2);
                $previousCounter = $counter;
            }
            $buckets[] = $bucket;
        }
        return $buckets;
    }

    /**
     * Sloučí hodinové kbelíky do místních kalendářních dnů.
     *
     * @param list<array<string, mixed>> $hours
     * @return list<array<string, mixed>>
     */
    public function days(array $hours): array
    {
        $days = [];
        foreach ($hours as $hour) {
            $key = $hour['date'];
            if (!isset($days[$key])) {
                $start = DateTimeImmutable::createFromFormat('!Y-m-d', $key, $this->timezone);
                $days[$key] = ['kind' => 'day', 'start' => $start, 'date' => $key, 'samples' => 0, 'hours' => 0,
                    'sums' => [], 'min' => [], 'max' => [], 'precip' => null];
            }
            $day = &$days[$key];
            $day['samples'] += $hour['samples'];
            $day['hours']++;
            foreach ($hour['sums'] as $name => $pair) {
                $current = isset($day['sums'][$name]) ? $day['sums'][$name] : [0.0, 0];
                $day['sums'][$name] = [$current[0] + $pair[0], $current[1] + $pair[1]];
            }
            foreach ($hour['min'] as $name => $value) {
                $day['min'][$name] = self::lower(isset($day['min'][$name]) ? $day['min'][$name] : null, $value);
            }
            foreach ($hour['max'] as $name => $value) {
                $day['max'][$name] = self::higher(isset($day['max'][$name]) ? $day['max'][$name] : null, $value);
            }
            if ($hour['precip'] !== null) {
                $day['precip'] = round(($day['precip'] ?? 0.0) + $hour['precip'], 2);
            }
            unset($day);
        }
        return array_values($days);
    }

    /**
     * Interní kbelík → položka JSON (průměry zaokrouhlené, NULL = bez dat).
     *
     * @param array<string, mixed> $bucket
     * @return array<string, mixed>
     */
    public function publicBucket(array $bucket): array
    {
        $item = ['time' => $bucket['start']->setTimezone($this->utc)->format(self::ISO_FORMAT)];
        if ($bucket['kind'] === 'day') {
            $item['date'] = $bucket['date'];
            $item['hours'] = $bucket['hours'];
        }
        $item['samples'] = $bucket['samples'];
        foreach (self::AGGREGATE_UNITS as $name => $unit) {
            if (isset($bucket['sums'][$name])) {
                [$sum, $count] = $bucket['sums'][$name];
                $item[$name] = $count > 0 ? round($sum / $count, 2) : null;
            } elseif (array_key_exists($name, $bucket['min'])) {
                $item[$name] = $bucket['min'][$name];
            } elseif (array_key_exists($name, $bucket['max'])) {
                $item[$name] = $bucket['max'][$name];
            } elseif ($name === 'precip') {
                $item[$name] = $bucket['precip'];
            } else {
                $item[$name] = null;
            }
        }
        return $item;
    }

    /**
     * Souhrn období: extrémy s časem, úhrny, klimatologické dny.
     *
     * @param list<array<string, mixed>> $days
     * @return array<string, mixed>
     */
    private function summary(string $station, string $fromDb, string $toDb, array $days): array
    {
        $samples = 0;
        $precipSum = null;
        $wettest = null;
        $counts = ['withData' => count($days), 'frost' => 0, 'ice' => 0, 'summer' => 0,
            'tropical' => 0, 'tropicalNight' => 0, 'rain' => 0];
        foreach ($days as $day) {
            $samples += $day['samples'];
            $tmin = isset($day['min']['temp_min']) ? $day['min']['temp_min'] : null;
            $tmax = isset($day['max']['temp_max']) ? $day['max']['temp_max'] : null;
            if ($tmin !== null && $tmin < self::FROST_DAY_MIN) {
                $counts['frost']++;
            }
            if ($tmax !== null && $tmax < self::ICE_DAY_MAX) {
                $counts['ice']++;
            }
            if ($tmax !== null && $tmax >= self::SUMMER_DAY_MAX) {
                $counts['summer']++;
            }
            if ($tmax !== null && $tmax >= self::TROPICAL_DAY_MAX) {
                $counts['tropical']++;
            }
            if ($tmin !== null && $tmin >= self::TROPICAL_NIGHT_MIN) {
                $counts['tropicalNight']++;
            }
            if ($day['precip'] !== null) {
                $precipSum = ($precipSum ?? 0.0) + $day['precip'];
                if ($day['precip'] >= self::RAIN_DAY_MM) {
                    $counts['rain']++;
                }
                if ($wettest === null || $day['precip'] > $wettest['value']) {
                    $wettest = ['value' => $day['precip'], 'date' => $day['date']];
                }
            }
        }

        $totals = $samples > 0 ? $this->aggregates->summary($station, $fromDb, $toDb) : [];
        $value = static function (string $key) use ($totals): ?float {
            return isset($totals[$key]) ? Meteo::toFloat($totals[$key]) : null;
        };

        return [
            'samples' => $samples,
            'first' => isset($totals['first_time']) ? $this->iso((string) $totals['first_time']) : null,
            'last' => isset($totals['last_time']) ? $this->iso((string) $totals['last_time']) : null,
            'temp_avg' => $value('temp_avg') === null ? null : round($value('temp_avg'), 1),
            'temp_min' => $samples > 0 ? $this->extreme($station, 'temp_c', false, $fromDb, $toDb) : null,
            'temp_max' => $samples > 0 ? $this->extreme($station, 'temp_c', true, $fromDb, $toDb) : null,
            'gust_max' => $samples > 0 ? $this->extreme($station, 'wind_gust', true, $fromDb, $toDb) : null,
            'wind_max' => $value('wind_max'),
            'humidity_min' => $value('humidity_min'),
            'humidity_max' => $value('humidity_max'),
            'pressure_min' => $value('pressure_min'),
            'pressure_max' => $value('pressure_max'),
            'precip_sum' => $precipSum === null ? null : round($precipSum, 1),
            'precip_wettest_day' => $wettest,
            'precip_rate_max' => $value('precip_rate_max'),
            'uv_max' => $value('uv_max'),
            'solar_max' => $value('solar_max'),
            'days' => $counts,
        ];
    }

    /** @return array{value: float|null, time: string}|null */
    private function extreme(string $station, string $column, bool $maximum, string $fromDb, string $toDb): ?array
    {
        $row = $this->aggregates->extreme($station, $column, $maximum, $fromDb, $toDb);
        if ($row === null) {
            return null;
        }
        return ['value' => Meteo::toFloat($row['value']), 'time' => $this->iso((string) $row['time'])];
    }

    /** DATETIME z DB (UTC) → ISO 8601 „…Z“. */
    private function iso(string $dbTime): string
    {
        $time = DateTimeImmutable::createFromFormat('!' . self::DB_FORMAT, $dbTime, $this->utc);
        if ($time === false) {
            throw new UnexpectedValueException('INVALID_STORED_TIME');
        }
        return $time->format(self::ISO_FORMAT);
    }

    /**
     * Seznam místních dat v intervalu [od, do).
     *
     * @return list<string>
     */
    private function calendarDays(DateTimeImmutable $fromLocal, DateTimeImmutable $toLocal): array
    {
        $dates = [];
        for ($day = $fromLocal; $day < $toLocal; $day = $day->add(new DateInterval('P1D'))) {
            $dates[] = $day->format('Y-m-d');
        }
        return $dates;
    }

    private static function lower(?float $a, ?float $b): ?float
    {
        if ($a === null) {
            return $b;
        }
        return $b === null ? $a : min($a, $b);
    }

    private static function higher(?float $a, ?float $b): ?float
    {
        if ($a === null) {
            return $b;
        }
        return $b === null ? $a : max($a, $b);
    }
}
