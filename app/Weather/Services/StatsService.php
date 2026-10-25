<?php
/**
 * Statistiky a rekordy (stránka „Statistiky“, api/stats.php).
 *
 * - rok:          řádky = měsíce zvoleného roku,
 * - celé období:  řádky = roky od prvního měření.
 *
 * Vychází z denních agregací HistoryService (místní dny, srážky z denního
 * čítače). Hodnoty, které by neúplný den zkreslil (průměry, „nejchladnější
 * maximum“, „nejteplejší minimum“, ledové dny, tropické noci, denní rozpětí),
 * se počítají jen ze dnů s alespoň MIN_HOURS_COMPLETE hodinami dat.
 * Např. den s daty jen z odpoledne by jinak vytvořil falešnou „tropickou noc“.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OuBrix\Weather\Http\Validation\StatsQuery;
use OuBrix\Weather\Weather\Repositories\AggregateCache;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Repositories\WeatherAggregateRepository;

final class StatsService
{
    /** Kolik položek má každý žebříček rekordů. */
    public const RECORDS_TOP = 5;

    /** Den je „úplný“, má-li data alespoň z tolika hodin. */
    public const MIN_HOURS_COMPLETE = 18;

    /**
     * Žebříčky rekordů: klíč => [veličina dne, true = nejvyšší / false = nejnižší, jen úplné dny].
     * Veličina „range“ = denní rozpětí teplot (max − min).
     */
    private const RECORDS = [
        'temp_max_high' => ['temp_max', true, false],
        'temp_min_low' => ['temp_min', false, false],
        'temp_avg_high' => ['temp_avg', true, true],
        'temp_avg_low' => ['temp_avg', false, true],
        'temp_min_high' => ['temp_min', true, true],
        'temp_max_low' => ['temp_max', false, true],
        'precip_high' => ['precip', true, false],
        'gust_high' => ['gust_max', true, false],
        'range_high' => ['range', true, true],
    ];

    /** Jednotky hodnot v řádcích a rekordech. */
    public const UNITS = [
        'temp_avg' => '°C', 'temp_max_avg' => '°C', 'temp_min_avg' => '°C', 'temp_max' => '°C', 'temp_min' => '°C',
        'precip' => 'mm', 'precip_max' => 'mm', 'gust_max' => 'km/h', 'range' => '°C',
    ];

    /** @var ObservationRepository */
    private $repository;

    /** @var HistoryService */
    private $history;

    /** @var DateTimeZone */
    private $timezone;

    public function __construct(
        ObservationRepository $repository,
        DateTimeZone $timezone,
        ?AggregateCache $aggregates = null,
        ?WeatherAggregateRepository $dailyAggregates = null
    )
    {
        $this->repository = $repository;
        $this->history = new HistoryService($repository, $timezone, $aggregates, $dailyAggregates);
        $this->timezone = $timezone;
    }

    /**
     * @param string $period rok „RRRR“ nebo StatsQuery::ALL
     * @return array<string, mixed>
     */
    public function build(string $station, string $period, DateTimeImmutable $now): array
    {
        $today = $now->setTimezone($this->timezone)->setTime(0, 0, 0);
        $bounds = $this->repository->bounds($station);
        $firstDate = $bounds === null ? null : $this->localDate($bounds['first']);
        $lastDate = $bounds === null ? null : $this->localDate($bounds['last']);

        if ($period === StatsQuery::ALL) {
            $group = 'year';
            $to = $lastDate !== null && $lastDate < $today ? $lastDate : $today;
            $from = $firstDate !== null && $firstDate <= $to ? $firstDate : $to;
        } else {
            $group = 'month';
            $from = $this->date($period . '-01-01');
            $endOfYear = $this->date($period . '-12-31');
            $to = $endOfYear < $today ? $endOfYear : $today;
        }

        $history = $this->history->build($station, $from, $to, 'day', 1);
        $days = $history['items'];

        $rows = [];
        foreach ($this->periods($from, $to, $group) as $key => [$rowFrom, $rowTo]) {
            $key = (string) $key; // klíč „2026“ PHP v poli převede na int
            $selected = array_values(array_filter($days, static function (array $day) use ($key): bool {
                return strpos($day['date'], $key) === 0;
            }));
            $rows[] = ['key' => $key, 'from' => $rowFrom->format('Y-m-d'), 'to' => $rowTo->format('Y-m-d')]
                + $this->aggregate($selected);
        }

        $years = [];
        if ($firstDate !== null) {
            for ($year = (int) $firstDate->format('Y'); $year <= (int) $today->format('Y'); $year++) {
                $years[] = $year;
            }
        }

        $summary = $history['summary'];
        return [
            'station' => $station,
            'period' => [
                'type' => $period === StatsQuery::ALL ? 'all' : 'year',
                'year' => $period === StatsQuery::ALL ? null : (int) $period,
                'group' => $group,
                'fromDate' => $from->format('Y-m-d'),
                'toDate' => $to->format('Y-m-d'),
            ],
            'years' => $years,
            'dataFrom' => $firstDate === null ? null : $firstDate->format('Y-m-d'),
            'dataTo' => $lastDate === null ? null : $lastDate->format('Y-m-d'),
            'units' => self::UNITS,
            'rows' => $rows,
            'total' => $this->aggregate($days),
            // Absolutní extrémy s přesným časem (z jednotlivých měření).
            'extremes' => [
                'temp_max' => $summary['temp_max'],
                'temp_min' => $summary['temp_min'],
                'gust_max' => $summary['gust_max'],
            ],
            'records' => $this->records($days),
            'meta' => [
                'recordsTop' => self::RECORDS_TOP,
                'minHoursComplete' => self::MIN_HOURS_COMPLETE,
                'samples' => $summary['samples'],
            ],
        ];
    }

    /**
     * Souhrn skupiny dnů (měsíc, rok, celé období).
     *
     * @param list<array<string, mixed>> $days denní položky z HistoryService
     * @return array<string, mixed>
     */
    public function aggregate(array $days): array
    {
        $row = [
            'days' => count($days), 'days_complete' => 0,
            'temp_avg' => null, 'temp_max_avg' => null, 'temp_min_avg' => null,
            'temp_max' => null, 'temp_min' => null,
            'precip' => null, 'precip_max' => null, 'rain_days' => 0, 'gust_max' => null,
            'frost_days' => 0, 'ice_days' => 0, 'summer_days' => 0, 'tropical_days' => 0, 'tropical_nights' => 0,
        ];
        $means = ['temp_avg' => [], 'temp_max_avg' => [], 'temp_min_avg' => []];
        foreach ($days as $day) {
            $complete = self::complete($day);
            $tmax = $day['temp_max'];
            $tmin = $day['temp_min'];
            if ($complete) {
                $row['days_complete']++;
                foreach (['temp_avg' => 'temp_avg', 'temp_max_avg' => 'temp_max', 'temp_min_avg' => 'temp_min'] as $target => $source) {
                    if ($day[$source] !== null) {
                        $means[$target][] = $day[$source];
                    }
                }
            }
            if ($tmax !== null) {
                $row['temp_max'] = self::better($row['temp_max'], $tmax, $day['date'], true);
                $row['summer_days'] += $tmax >= HistoryService::SUMMER_DAY_MAX ? 1 : 0;
                $row['tropical_days'] += $tmax >= HistoryService::TROPICAL_DAY_MAX ? 1 : 0;
                $row['ice_days'] += $complete && $tmax < HistoryService::ICE_DAY_MAX ? 1 : 0;
            }
            if ($tmin !== null) {
                $row['temp_min'] = self::better($row['temp_min'], $tmin, $day['date'], false);
                $row['frost_days'] += $tmin < HistoryService::FROST_DAY_MIN ? 1 : 0;
                $row['tropical_nights'] += $complete && $tmin >= HistoryService::TROPICAL_NIGHT_MIN ? 1 : 0;
            }
            if ($day['precip'] !== null) {
                $row['precip'] = round(($row['precip'] ?? 0.0) + $day['precip'], 2);
                $row['precip_max'] = self::better($row['precip_max'], round($day['precip'], 1), $day['date'], true);
                $row['rain_days'] += $day['precip'] >= HistoryService::RAIN_DAY_MM ? 1 : 0;
            }
            if ($day['gust_max'] !== null) {
                $row['gust_max'] = self::better($row['gust_max'], $day['gust_max'], $day['date'], true);
            }
        }
        foreach ($means as $name => $values) {
            $row[$name] = $values === [] ? null : round(array_sum($values) / count($values), 1);
        }
        if ($row['precip'] !== null) {
            $row['precip'] = round($row['precip'], 1);
        }
        return $row;
    }

    /**
     * Žebříčky nej… dnů (top N), při shodě dřívější datum první.
     *
     * @param list<array<string, mixed>> $days
     * @return array<string, list<array{date: string, value: float}>>
     */
    public function records(array $days): array
    {
        $result = [];
        foreach (self::RECORDS as $key => [$field, $highest, $completeOnly]) {
            $list = [];
            foreach ($days as $day) {
                if ($completeOnly && !self::complete($day)) {
                    continue;
                }
                if ($field === 'range') {
                    $value = $day['temp_max'] !== null && $day['temp_min'] !== null
                        ? round($day['temp_max'] - $day['temp_min'], 1) : null;
                } else {
                    $value = $day[$field];
                }
                // Nulové srážky a bezvětří nejsou „rekord“.
                if ($value === null || (in_array($field, ['precip', 'gust_max'], true) && $value <= 0)) {
                    continue;
                }
                $list[] = ['date' => $day['date'], 'value' => round((float) $value, 1)];
            }
            usort($list, static function (array $a, array $b) use ($highest): int {
                $order = $highest ? $b['value'] <=> $a['value'] : $a['value'] <=> $b['value'];
                return $order !== 0 ? $order : strcmp($a['date'], $b['date']);
            });
            $result[$key] = array_slice($list, 0, self::RECORDS_TOP);
        }
        return $result;
    }

    /**
     * Měsíce nebo roky v rozsahu: klíč („2026-03“ / „2026“) => [od, do] (místní data).
     *
     * @return array<string, array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     */
    private function periods(DateTimeImmutable $from, DateTimeImmutable $to, string $group): array
    {
        $periods = [];
        $cursor = $group === 'month' ? $from->modify('first day of this month') : $from->setDate((int) $from->format('Y'), 1, 1);
        $step = new DateInterval($group === 'month' ? 'P1M' : 'P1Y');
        while ($cursor <= $to) {
            $next = $cursor->add($step);
            $end = $next->sub(new DateInterval('P1D'));
            $periods[$cursor->format($group === 'month' ? 'Y-m' : 'Y')] = [
                $cursor < $from ? $from : $cursor,
                $end > $to ? $to : $end,
            ];
            $cursor = $next;
        }
        return $periods;
    }

    /** @param array<string, mixed> $day */
    private static function complete(array $day): bool
    {
        return (int) $day['hours'] >= self::MIN_HOURS_COMPLETE;
    }

    /**
     * Ponechá extrém (vyšší/nižší hodnotu); při shodě zůstává dřívější den.
     *
     * @param array{value: float, date: string}|null $current
     * @return array{value: float, date: string}
     */
    private static function better(?array $current, float $value, string $date, bool $higher): array
    {
        if ($current === null || ($higher ? $value > $current['value'] : $value < $current['value'])) {
            return ['value' => $value, 'date' => $date];
        }
        return $current;
    }

    /** UTC čas z DB → místní půlnoc daného dne. */
    private function localDate(string $utc): DateTimeImmutable
    {
        $time = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $time->setTimezone($this->timezone)->setTime(0, 0, 0);
    }

    private function date(string $date): DateTimeImmutable
    {
        $result = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->timezone);
        if ($result === false) {
            throw new \UnexpectedValueException('INVALID_DATE');
        }
        return $result;
    }
}
