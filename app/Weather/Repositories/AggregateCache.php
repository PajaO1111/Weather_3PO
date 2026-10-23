<?php
/**
 * Souborová cache hodinových agregací uzavřených měsíců.
 *
 * Proč: Historie a Statistiky skládají dny z hodinových skupin (GROUP BY nad
 * celým obdobím) a hledají extrémy (ORDER BY nad celým obdobím). S přibývajícími
 * roky to zpomaluje. Data uzavřeného měsíce se už nemění, proto se pro každou
 * stanici a měsíc spočítají JEDNOU a uloží do souboru:
 *   storage/cache/aggregates/<stanice>/<RRRR-MM>.json
 *   = hodinové řádky (stejné jako ObservationRepository::hourly), souhrn měsíce
 *     (jako ::summary) a extrémy s časem (teplota max/min, náraz max).
 *
 * Dotaz na období se rozdělí podle MÍSTNÍCH měsíců (Europe/Prague):
 *  - celý měsíc, který skončil před víc než SETTLE_SECONDS → ze souboru,
 *  - zbytek (neúplné měsíce na okrajích, probíhající měsíc) → živý dotaz do DB.
 * Výsledky obou částí se sloučí tak, aby byly totožné s jedním živým dotazem.
 *
 * Bez zapisovatelné složky (nebo s $directory = null) se třída chová přesně
 * jako repozitář – vše jde živě do DB. Do DB nic nezapisuje.
 *
 * Smazání cache: stačí smazat složku storage/cache/aggregates (vytvoří se znovu).
 * Při změně SQL agregací se cache zneplatní sama (otisk FORMAT v souboru).
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Repositories;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OuBrix\Weather\Weather\Support\Meteo;

final class AggregateCache
{
    /** Měsíc se ukládá, až od jeho konce uplyne tolik sekund (opožděná data). */
    public const SETTLE_SECONDS = 86400;

    /** Extrémy ukládané do souboru měsíce: klíč => [sloupec, maximum?]. */
    private const CACHED_EXTREMES = [
        'temp_c:max' => ['temp_c', true],
        'temp_c:min' => ['temp_c', false],
        'wind_gust:max' => ['wind_gust', true],
    ];

    /** Verze formátu souboru – zvýšit při změně struktury. */
    private const FORMAT_VERSION = 1;

    private const DB_FORMAT = 'Y-m-d H:i:s';

    /** @var ObservationRepository */
    private $repository;

    /** @var DateTimeZone */
    private $timezone;

    /** @var DateTimeZone */
    private $utc;

    /** @var string|null složka cache; null = vypnuto */
    private $directory;

    /** @var DateTimeImmutable */
    private $now;

    /** @var array<string, array<string, mixed>> načtené měsíce v rámci požadavku */
    private $loaded = [];

    /** @var array{hits: int, built: int, live: int} statistika pro diagnostiku/testy */
    private $stats = ['hits' => 0, 'built' => 0, 'live' => 0];

    public function __construct(ObservationRepository $repository, DateTimeZone $timezone, ?string $directory, ?DateTimeImmutable $now = null)
    {
        $this->repository = $repository;
        $this->timezone = $timezone;
        $this->utc = new DateTimeZone('UTC');
        $this->now = $now ?? new DateTimeImmutable('now', $this->utc);
        $this->directory = $directory !== null && self::prepareDirectory($directory) ? $directory : null;
    }

    /**
     * Cache podle config/app.php (aggregate_cache, aggregate_cache_dir).
     *
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(ObservationRepository $repository, DateTimeZone $timezone, array $settings): self
    {
        return new self($repository, $timezone, self::directoryFromSettings($settings));
    }

    /** @param array<string, mixed> $settings */
    public static function directoryFromSettings(array $settings): ?string
    {
        // Proměnná prostředí má přednost (testy): „off“ = vypnuto, jinak cesta.
        $env = getenv('WEATHER_CACHE_DIR');
        if (is_string($env) && $env !== '') {
            return $env === 'off' ? null : $env;
        }
        if (empty($settings['aggregate_cache'])) {
            return null;
        }
        $dir = $settings['aggregate_cache_dir'] ?? null;
        return is_string($dir) && $dir !== '' ? $dir : WEATHER_ROOT . '/storage/cache/aggregates';
    }

    /**
     * Stav cache pro api/health.php: „ok“, „disabled“ nebo „not_writable“.
     *
     * @param array<string, mixed> $settings
     */
    public static function status(array $settings): string
    {
        $dir = self::directoryFromSettings($settings);
        if ($dir === null) {
            return 'disabled';
        }
        return self::prepareDirectory($dir) ? 'ok' : 'not_writable';
    }

    public function enabled(): bool
    {
        return $this->directory !== null;
    }

    /** @return array{hits: int, built: int, live: int} */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Stejný výsledek jako ObservationRepository::hourly().
     *
     * @return list<array<string, mixed>>
     */
    public function hourly(string $station, string $fromUtc, string $toUtc): array
    {
        $rows = [];
        foreach ($this->parts($fromUtc, $toUtc) as $part) {
            if ($part['month'] === null) {
                $this->stats['live']++;
                $chunk = $this->repository->hourly($station, $part['from'], $part['to']);
            } else {
                $chunk = $this->month($station, $part)['hourly'];
            }
            foreach ($chunk as $row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Stejný výsledek jako ObservationRepository::summary() (čísla jako float).
     *
     * @return array<string, mixed>
     */
    public function summary(string $station, string $fromUtc, string $toUtc): array
    {
        $parts = $this->parts($fromUtc, $toUtc);
        if (count($parts) === 1 && $parts[0]['month'] === null) {
            $this->stats['live']++;
            return $this->repository->summary($station, $fromUtc, $toUtc);
        }
        $summaries = [];
        foreach ($parts as $part) {
            if ($part['month'] === null) {
                $this->stats['live']++;
                $summaries[] = $this->repository->summary($station, $part['from'], $part['to']);
            } else {
                $summaries[] = $this->month($station, $part)['summary'];
            }
        }
        return self::mergeSummaries($summaries);
    }

    /**
     * Stejný výsledek jako ObservationRepository::extreme(); při shodě hodnot
     * vyhrává dřívější výskyt.
     *
     * @return array{value: mixed, time: string}|null
     */
    public function extreme(string $station, string $column, bool $maximum, string $fromUtc, string $toUtc): ?array
    {
        $key = $column . ':' . ($maximum ? 'max' : 'min');
        $parts = $this->parts($fromUtc, $toUtc);
        if (!isset(self::CACHED_EXTREMES[$key]) || (count($parts) === 1 && $parts[0]['month'] === null)) {
            $this->stats['live']++;
            return $this->repository->extreme($station, $column, $maximum, $fromUtc, $toUtc);
        }
        $best = null;
        foreach ($parts as $part) {
            if ($part['month'] === null) {
                $this->stats['live']++;
                $candidate = $this->repository->extreme($station, $column, $maximum, $part['from'], $part['to']);
            } else {
                $candidate = $this->month($station, $part)['extremes'][$key];
            }
            if ($candidate === null || $candidate['value'] === null) {
                continue;
            }
            $value = (float) $candidate['value'];
            // Části jdou chronologicky, při shodě tedy zůstává dřívější výskyt.
            if ($best === null || ($maximum ? $value > (float) $best['value'] : $value < (float) $best['value'])) {
                $best = $candidate;
            }
        }
        return $best;
    }

    /**
     * Rozdělí [od, do) na uložitelné celé měsíce a živé úseky (sousední živé úseky sloučí).
     *
     * @return list<array{month: string|null, from: string, to: string}>
     */
    private function parts(string $fromUtc, string $toUtc): array
    {
        $from = $this->utcTime($fromUtc);
        $to = $this->utcTime($toUtc);
        if ($this->directory === null || $from === null || $to === null || $from >= $to) {
            return [['month' => null, 'from' => $fromUtc, 'to' => $toUtc]];
        }
        $settled = $this->now->sub(new DateInterval('PT' . self::SETTLE_SECONDS . 'S'));
        $parts = [];
        $cursor = $from;
        $monthStart = $from->setTimezone($this->timezone)->modify('first day of this month')->setTime(0, 0, 0);
        while ($cursor < $to) {
            $monthEnd = $monthStart->add(new DateInterval('P1M'));
            $startUtc = $monthStart->setTimezone($this->utc);
            $endUtc = $monthEnd->setTimezone($this->utc);
            $partEnd = $endUtc < $to ? $endUtc : $to;
            $cacheable = $startUtc >= $from && $endUtc <= $to && $endUtc <= $settled;
            $part = [
                'month' => $cacheable ? $monthStart->format('Y-m') : null,
                'from' => $cursor->format(self::DB_FORMAT),
                'to' => $partEnd->format(self::DB_FORMAT),
            ];
            $last = count($parts) - 1;
            if ($part['month'] === null && $last >= 0 && $parts[$last]['month'] === null) {
                $parts[$last]['to'] = $part['to'];
            } else {
                $parts[] = $part;
            }
            $cursor = $partEnd;
            $monthStart = $monthEnd;
        }
        return $parts;
    }

    /**
     * Data uzavřeného měsíce: ze souboru, jinak spočítá z DB a uloží.
     *
     * @param array{month: string, from: string, to: string} $part
     * @return array<string, mixed>
     */
    private function month(string $station, array $part): array
    {
        $key = $station . '|' . $part['month'];
        if (isset($this->loaded[$key])) {
            return $this->loaded[$key];
        }
        $file = $this->file($station, $part['month']);
        $data = $this->read($file, $station, $part);
        if ($data === null) {
            $this->stats['built']++;
            $data = $this->build($station, $part);
            $this->write($file, $data);
        } else {
            $this->stats['hits']++;
        }
        $hourly = [];
        foreach ($data['rows'] as $values) {
            $hourly[] = array_combine($data['columns'], $values);
        }
        return $this->loaded[$key] = [
            'hourly' => $hourly,
            'summary' => $data['summary'],
            'extremes' => $data['extremes'],
        ];
    }

    /**
     * @param array{month: string, from: string, to: string} $part
     * @return array<string, mixed>
     */
    private function build(string $station, array $part): array
    {
        $hourly = $this->repository->hourly($station, $part['from'], $part['to']);
        $extremes = [];
        foreach (self::CACHED_EXTREMES as $key => [$column, $maximum]) {
            $extremes[$key] = $this->repository->extreme($station, $column, $maximum, $part['from'], $part['to']);
        }
        // Sloupcový zápis (bez opakování klíčů) – soubor je asi 3× menší.
        $columns = $hourly === [] ? [] : array_keys($hourly[0]);
        return [
            'format' => self::format(),
            'station' => $station,
            'month' => $part['month'],
            'from' => $part['from'],
            'to' => $part['to'],
            'created' => $this->now->format('Y-m-d\TH:i:s\Z'),
            'columns' => $columns,
            'rows' => array_map('array_values', $hourly),
            'summary' => $this->repository->summary($station, $part['from'], $part['to']),
            'extremes' => $extremes,
        ];
    }

    /**
     * @param array{month: string, from: string, to: string} $part
     * @return array<string, mixed>|null null = soubor chybí, je poškozený nebo z jiné verze
     */
    private function read(string $file, string $station, array $part): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $json = @file_get_contents($file);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)
            || ($data['format'] ?? null) !== self::format()
            || ($data['station'] ?? null) !== $station
            || ($data['from'] ?? null) !== $part['from']
            || ($data['to'] ?? null) !== $part['to']
            || !is_array($data['columns'] ?? null) || !is_array($data['rows'] ?? null)
            || !is_array($data['summary'] ?? null) || !is_array($data['extremes'] ?? null)) {
            return null;
        }
        foreach ($data['rows'] as $row) {
            if (!is_array($row) || count($row) !== count($data['columns'])) {
                return null;
            }
        }
        foreach (array_keys(self::CACHED_EXTREMES) as $key) {
            if (!array_key_exists($key, $data['extremes'])) {
                return null;
            }
        }
        return $data;
    }

    /** Zápis přes dočasný soubor + rename, aby jiný požadavek nikdy nečetl polovinu souboru. */
    private function write(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('OuBrix Weather cache: nelze vytvořit složku cache.');
            return;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if ($json === false || @file_put_contents($temp, $json, LOCK_EX) === false || !@rename($temp, $file)) {
            @unlink($temp);
            error_log('OuBrix Weather cache: zápis souboru cache selhal.');
        }
    }

    private function file(string $station, string $month): string
    {
        $safeStation = preg_replace('/[^A-Za-z0-9_-]/', '_', $station);
        return $this->directory . '/' . $safeStation . '/' . $month . '.json';
    }

    /**
     * Sloučí souhrny částí do jednoho (jako jeden dotaz přes celé období).
     *
     * @param list<array<string, mixed>> $summaries
     * @return array<string, mixed>
     */
    public static function mergeSummaries(array $summaries): array
    {
        $merged = ['samples' => 0];
        $tempSum = 0.0;
        $tempCount = 0;
        foreach ($summaries as $summary) {
            $samples = (int) ($summary['samples'] ?? 0);
            if ($samples === 0) {
                continue;
            }
            $merged['samples'] += $samples;
            $count = (int) ($summary['temp_n'] ?? 0);
            $avg = Meteo::toFloat($summary['temp_avg'] ?? null);
            if ($avg !== null && $count > 0) {
                $tempSum += $avg * $count;
                $tempCount += $count;
            }
            foreach ($summary as $name => $raw) {
                if ($raw === null || in_array($name, ['samples', 'temp_avg', 'temp_n'], true)) {
                    continue;
                }
                if ($name === 'first_time' || $name === 'last_time') {
                    $current = $merged[$name] ?? null;
                    $better = $current === null || ($name === 'first_time' ? strcmp((string) $raw, $current) < 0 : strcmp((string) $raw, $current) > 0);
                    $merged[$name] = $better ? (string) $raw : $current;
                    continue;
                }
                $value = Meteo::toFloat($raw);
                if ($value === null) {
                    continue;
                }
                $current = $merged[$name] ?? null;
                $isMin = substr($name, -4) === '_min';
                $merged[$name] = $current === null ? $value : ($isMin ? min($current, $value) : max($current, $value));
            }
        }
        $merged['temp_avg'] = $tempCount > 0 ? $tempSum / $tempCount : null;
        $merged['temp_n'] = $tempCount;
        return $merged;
    }

    /** Otisk formátu: verze + definice agregací (změna SQL = nová cache). */
    private static function format(): string
    {
        return self::FORMAT_VERSION . '-' . substr(md5((string) json_encode([
            ObservationRepository::HOURLY_AVERAGES,
            ObservationRepository::HOURLY_MINIMA,
            ObservationRepository::HOURLY_MAXIMA,
            array_keys(self::CACHED_EXTREMES),
        ])), 0, 10);
    }

    private static function prepareDirectory(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }
        return is_writable($directory);
    }

    private function utcTime(string $value): ?DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!' . self::DB_FORMAT, $value, $this->utc);
        return $time === false ? null : $time;
    }
}
