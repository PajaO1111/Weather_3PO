<?php
/**
 * Větrná růžice: četnost směrů větru (16 směrů) rozdělená podle rychlosti.
 *
 * Zdroj jsou surová měření za posledních N hodin. Duplicitní časy se počítají
 * jednou, měření bez směru nebo rychlosti se vynechají. Rychlost pod 1 km/h
 * je „bezvětří“ a nepatří do žádného směru.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Support\Meteo;

final class WindRoseService
{
    /** Hranice tříd rychlosti v km/h (zhruba po stupních Beaufortovy stupnice). */
    public const CLASS_LIMITS = [1.0, 6.0, 12.0, 20.0, 29.0, 39.0];

    /** @var ObservationRepository */
    private $repository;

    public function __construct(ObservationRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $station, int $hours, DateTimeImmutable $now, int $maxRows): array
    {
        $to = $now->setTimezone(new DateTimeZone('UTC'));
        $from = $to->sub(new DateInterval('PT' . $hours . 'H'));
        $rows = $this->repository->between(
            $station,
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
            $maxRows + 1
        );
        $truncated = count($rows) > $maxRows;
        if ($truncated) {
            $rows = array_slice($rows, -$maxRows);
        }
        $rose = self::aggregate($rows);
        $rose['station'] = $station;
        $rose['hours'] = $hours;
        $rose['from'] = $from->format('Y-m-d\TH:i:s\Z');
        $rose['to'] = $to->format('Y-m-d\TH:i:s\Z');
        $rose['truncated'] = $truncated;
        return $rose;
    }

    /**
     * Spočítá růžici z řádků (obs_time_utc, winddir, wind_speed).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function aggregate(array $rows): array
    {
        $classCount = count(self::CLASS_LIMITS);
        $counts = array_fill(0, $classCount, array_fill(0, 16, 0));
        $seen = [];
        $samples = 0;
        $calm = 0;
        $speedSum = 0.0;
        $speedMax = null;
        foreach ($rows as $row) {
            $time = (string) $row['obs_time_utc'];
            if (isset($seen[$time])) {
                continue;
            }
            $seen[$time] = true;
            $direction = Meteo::toFloat($row['winddir'] ?? null);
            $speed = Meteo::toFloat($row['wind_speed'] ?? null);
            if ($speed === null || $speed < 0) {
                continue;
            }
            if ($speed >= self::CLASS_LIMITS[0]
                && ($direction === null || $direction < 0.0 || $direction > 360.0)) {
                continue;
            }
            $samples++;
            $speedSum += $speed;
            $speedMax = $speedMax === null ? $speed : max($speedMax, $speed);
            if ($speed < self::CLASS_LIMITS[0]) {
                $calm++;
                continue;
            }
            $sector = ((int) floor((fmod($direction, 360.0) + 360.0 + 11.25) / 22.5)) % 16;
            $class = 0;
            for ($i = $classCount - 1; $i >= 0; $i--) {
                if ($speed >= self::CLASS_LIMITS[$i]) {
                    $class = $i;
                    break;
                }
            }
            $counts[$class][$sector]++;
        }

        $percent = static function (int $count) use ($samples): float {
            return $samples > 0 ? round($count * 100 / $samples, 1) : 0.0;
        };
        $classes = [];
        $sectorTotals = array_fill(0, 16, 0);
        foreach (self::CLASS_LIMITS as $i => $limit) {
            $next = self::CLASS_LIMITS[$i + 1] ?? null;
            foreach ($counts[$i] as $sector => $count) {
                $sectorTotals[$sector] += $count;
            }
            $classes[] = [
                'from' => $limit,
                'to' => $next,
                'label' => $next === null
                    ? '≥ ' . (int) $limit . ' km/h'
                    : (int) $limit . ' až méně než ' . (int) $next . ' km/h',
                'percent' => array_map($percent, $counts[$i]),
            ];
        }
        $sectors = [];
        for ($i = 0; $i < 16; $i++) {
            $sectors[] = Meteo::compass($i * 22.5);
        }
        $prevailing = null;
        if (max($sectorTotals) > 0) {
            $prevailing = $sectors[array_search(max($sectorTotals), $sectorTotals, true)];
        }

        return [
            'sectors' => $sectors,
            'classes' => $classes,
            'calm' => $percent($calm),
            'samples' => $samples,
            'prevailing' => $prevailing,
            'speedAvg' => $samples > 0 ? round($speedSum / $samples, 1) : null,
            'speedMax' => $speedMax,
        ];
    }
}
