<?php
/**
 * Sestavení „aktuálního stavu“ stanice pro hlavní stránku.
 *
 * Výstup = poslední měření + údaje o stanici + stáří dat + tlaková tendence
 * + souhrn dnešního dne (místní čas Europe/Prague, včetně letního času).
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Support\Astro;
use OuBrix\Weather\Weather\Support\Meteo;

final class CurrentConditionsService
{
    /** Formát časů uložených v DB (DATETIME). */
    private const DB_FORMAT = 'Y-m-d H:i:s';

    /** Jednotky veličin – WU data se stahují s units=m (vítr v km/h). */
    public const UNITS = [
        'temp_c' => '°C', 'heat_index' => '°C', 'wind_chill' => '°C', 'dew_point' => '°C', 'feels_like' => '°C',
        'humidity' => '%', 'pressure' => 'hPa', 'winddir' => '°',
        'wind_speed' => 'km/h', 'wind_gust' => 'km/h',
        'uv' => '', 'solar_radiation' => 'W/m²',
        'precip_rate' => 'mm/h', 'precip_total' => 'mm',
    ];

    /** @var ObservationRepository */
    private $repository;

    /** @var array<string, mixed> nastavení z config/app.php */
    private $settings;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(ObservationRepository $repository, array $settings)
    {
        $this->repository = $repository;
        $this->settings = $settings;
    }

    /**
     * @param string            $station ID stanice
     * @param DateTimeImmutable $now     aktuální čas (předává se kvůli testovatelnosti)
     * @return array<string, mixed>|null null, pokud stanice nemá žádná měření
     */
    public function build(string $station, DateTimeImmutable $now): ?array
    {
        $row = $this->repository->latest($station);
        if ($row === null) {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $local = new DateTimeZone((string) $this->settings['timezone']);
        $observed = $this->parseUtc((string) $row['obs_time_utc']);
        $age = $now->getTimestamp() - $observed->getTimestamp();

        // Aktuální hodnoty: z DB přichází řetězce/NULL, převádíme na float/null.
        $current = [];
        foreach (ObservationRepository::METRICS as $metric) {
            $current[$metric] = Meteo::toFloat($row[$metric]);
        }
        $current['feels_like'] = Meteo::feelsLike($current['temp_c'], $current['wind_speed'], $current['wind_chill'], $current['heat_index']);
        $current['wind_direction'] = Meteo::compass($current['winddir']);
        $current['uv_category'] = Meteo::uvCategory($current['uv']);
        $current['beaufort'] = Meteo::beaufort($current['wind_speed']);
        $current['precip_intensity'] = Meteo::precipIntensity($current['precip_rate']);

        // Hranice dnešního místního dne převedené do UTC (správně i při změně času).
        $dayStartLocal = $now->setTimezone($local)->setTime(0, 0, 0);
        $dayEndLocal = $dayStartLocal->add(new DateInterval('P1D'));
        $fromUtc = $dayStartLocal->setTimezone($utc)->format(self::DB_FORMAT);
        $toUtc = $dayEndLocal->setTimezone($utc)->format(self::DB_FORMAT);

        $isToday = $observed >= $dayStartLocal && $observed < $dayEndLocal;
        $staleAfter = (int) $this->settings['stale_after_seconds'];

        $lat = Meteo::toFloat($row['lat']);
        $lon = Meteo::toFloat($row['lon']);
        // Slunce: časy pro dnešní místní den, výška Slunce v okamžiku měření (pro odhad stavu).
        $sun = $lat === null || $lon === null ? null : Astro::sun($dayStartLocal, $observed, $lat, $lon, $local);
        $isDay = $sun !== null ? $sun['isDay'] : ($current['solar_radiation'] ?? 0.0) > 0.0;
        $condition = Meteo::condition(
            $current['precip_rate'],
            $current['temp_c'],
            $current['humidity'],
            $current['dew_point'],
            $isDay,
            $current['solar_radiation'],
            $sun === null ? null : Astro::clearSkyRadiation((float) $sun['elevation']),
            $sun === null ? null : (float) $sun['elevation']
        );

        return [
            'station' => [
                'id' => (string) $row['station_id'],
                'neighborhood' => $row['neighborhood'] === null ? null : (string) $row['neighborhood'],
                'lat' => $lat,
                'lon' => $lon,
                'elevation' => Meteo::toFloat($row['elevation']),
            ],
            'observed' => [
                'utc' => $observed->format('Y-m-d\TH:i:s\Z'),
                'local' => $observed->setTimezone($local)->format('Y-m-d\TH:i:sP'),
                'ageSeconds' => $age,
                'stale' => $age > $staleAfter,
                'staleAfterSeconds' => $staleAfter,
                'futureTimestamp' => $age < -60,
                'qcStatus' => $row['qc_status'] === null ? null : (int) $row['qc_status'],
            ],
            'current' => $current,
            // Odhad z měření (stanice nemá čidlo oblačnosti).
            'condition' => $condition + ['estimated' => true],
            'astro' => [
                'sun' => $sun,
                'moon' => Astro::moon($now, $local),
            ],
            'pressureTrend' => $this->pressureTrend($station, $observed, $current['pressure']),
            'today' => $this->today($station, $fromUtc, $toUtc, $dayStartLocal, $local, $isToday ? $current['precip_total'] : null),
            'units' => self::UNITS,
            'timezone' => (string) $this->settings['timezone'],
        ];
    }

    /**
     * Změna tlaku proti měření zhruba před N hodinami.
     *
     * Vrací null, pokud chybí aktuální tlak nebo starší měření v toleranci
     * (např. při výpadku stanice) – nevymýšlíme tendenci z neúplných dat.
     *
     * @return array<string, mixed>|null
     */
    private function pressureTrend(string $station, DateTimeImmutable $observed, ?float $pressure): ?array
    {
        if ($pressure === null) {
            return null;
        }
        $hours = (int) $this->settings['pressure_trend_hours'];
        $tolerance = (int) $this->settings['pressure_trend_tolerance_minutes'] * 60;
        $target = $observed->sub(new DateInterval('PT' . $hours . 'H'));
        $past = $this->repository->pressureAtOrBefore($station, $target->format(self::DB_FORMAT));
        if ($past === null) {
            return null;
        }
        $pastTime = $this->parseUtc((string) $past['obs_time_utc']);
        if ($target->getTimestamp() - $pastTime->getTimestamp() > $tolerance) {
            return null;
        }
        $change = round($pressure - (float) $past['pressure'], 1);
        return [
            'hours' => $hours,
            'change' => $change,
            'label' => Meteo::pressureTendency($change),
            'comparedTo' => $pastTime->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Souhrn dnešního místního dne.
     *
     * @param float|null $rainCounter denní čítač srážek z posledního měření
     *                                (null, pokud poslední měření není z dneška)
     * @return array<string, mixed>
     */
    private function today(string $station, string $fromUtc, string $toUtc, DateTimeImmutable $dayStartLocal, DateTimeZone $local, ?float $rainCounter): array
    {
        $summary = $this->repository->summary($station, $fromUtc, $toUtc);
        $samples = (int) ($summary['samples'] ?? 0);
        $result = [
            'date' => $dayStartLocal->format('Y-m-d'),
            'samples' => $samples,
            // Skutečný průměrný odstup měření dnes (CRON nezapisuje v pevném intervalu).
            'averageIntervalMinutes' => null,
            'rainCounter' => $rainCounter,
        ];
        if ($samples === 0) {
            return $result;
        }
        if ($samples > 1 && !empty($summary['first_time']) && !empty($summary['last_time'])) {
            $span = $this->parseUtc((string) $summary['last_time'])->getTimestamp()
                - $this->parseUtc((string) $summary['first_time'])->getTimestamp();
            $result['averageIntervalMinutes'] = round($span / 60 / ($samples - 1), 0);
        }
        foreach (['temp_avg', 'humidity_min', 'humidity_max', 'pressure_min', 'pressure_max', 'wind_max', 'precip_rate_max', 'uv_max', 'solar_max'] as $key) {
            $value = Meteo::toFloat($summary[$key] ?? null);
            $result[$key] = $value === null ? null : round($value, 1);
        }
        $result['temp_min'] = $this->extreme($station, 'temp_c', false, $fromUtc, $toUtc, $local);
        $result['temp_max'] = $this->extreme($station, 'temp_c', true, $fromUtc, $toUtc, $local);
        $result['gust_max'] = $this->extreme($station, 'wind_gust', true, $fromUtc, $toUtc, $local);
        return $result;
    }

    /**
     * Extrém s časem výskytu v místním čase.
     *
     * @return array{value: float|null, time: string}|null
     */
    private function extreme(string $station, string $column, bool $maximum, string $fromUtc, string $toUtc, DateTimeZone $local): ?array
    {
        $row = $this->repository->extreme($station, $column, $maximum, $fromUtc, $toUtc);
        if ($row === null) {
            return null;
        }
        return [
            'value' => Meteo::toFloat($row['value']),
            'time' => $this->parseUtc((string) $row['time'])->setTimezone($local)->format('Y-m-d\TH:i:sP'),
        ];
    }

    /** Převod času z DB (UTC DATETIME) na DateTimeImmutable v UTC. */
    private function parseUtc(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!' . self::DB_FORMAT, $value, new DateTimeZone('UTC'));
        if ($time === false) {
            throw new \UnexpectedValueException('INVALID_STORED_TIME');
        }
        return $time;
    }
}
