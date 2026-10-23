<?php
/**
 * Příprava časové řady měření pro graf.
 *
 * - převádí hodnoty z DB na čísla (NULL zůstává NULL = chybějící měření),
 * - odstraňuje přesné duplicity stejného času (v DB jich je ~144),
 * - pokud mají dva řádky stejný čas a RŮZNÉ hodnoty, hodnoty vynuluje
 *   a označí bod jako konflikt (nevybíráme „správnou“ hodnotu naslepo).
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Support\Meteo;
use UnexpectedValueException;

final class ObservationService
{
    /** @var ObservationRepository */
    private $repository;

    public function __construct(ObservationRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Posledních N měření ve tvaru pro JSON API (starší režim ?limit=).
     *
     * @return array<string, mixed>
     */
    public function series(string $station, int $limit): array
    {
        return $this->present($this->repository->recent($station, $limit), $station, ['requestedRows' => $limit]);
    }

    /**
     * Měření za posledních N hodin ke chvíli $now (režim ?hours=, výchozí pro graf).
     *
     * Okno se počítá podle času, ne podle počtu řádků – CRON zapisuje
     * nepravidelně, takže „288 řádků“ by nebylo 24 hodin.
     *
     * @return array<string, mixed>
     */
    public function window(string $station, int $hours, DateTimeImmutable $now, int $maxRows): array
    {
        $to = $now->setTimezone(new DateTimeZone('UTC'));
        $from = $to->sub(new DateInterval('PT' . $hours . 'H'));
        $rows = $this->repository->between($station, $from->format('Y-m-d H:i:s'), $to->modify('+1 second')->format('Y-m-d H:i:s'), $maxRows);
        return $this->present($rows, $station, [
            'hours' => $hours,
            'from' => $from->format('Y-m-d\TH:i:s\Z'),
            'to' => $to->format('Y-m-d\TH:i:s\Z'),
            'truncated' => count($rows) >= $maxRows,
        ]);
    }

    /**
     * Převede surové řádky (seřazené od nejstaršího) na body grafu.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $meta údaje o dotazu doplněné do „meta“
     * @return array<string, mixed>
     */
    public function present(array $rows, string $station, array $meta = []): array
    {
        $items = [];
        $duplicates = 0;
        $conflicts = [];
        foreach ($rows as $row) {
            $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $row['obs_time_utc'], new DateTimeZone('UTC'));
            if ($time === false) {
                throw new UnexpectedValueException('INVALID_STORED_TIME');
            }
            $point = ['time' => $time->format('Y-m-d\TH:i:s\Z')];
            foreach (ObservationRepository::METRICS as $metric) {
                $point[$metric] = Meteo::toFloat($row[$metric]);
            }
            $key = $point['time'];
            if (isset($items[$key])) {
                $duplicates++;
                if ($items[$key] !== $point) {
                    $conflicts[$key] = true;
                }
                continue;
            }
            $items[$key] = $point;
        }
        foreach (array_keys($conflicts) as $key) {
            foreach (ObservationRepository::METRICS as $metric) {
                $items[$key][$metric] = null;
            }
            $items[$key]['conflict'] = true;
        }

        $points = array_values($items);
        return [
            'station' => $station,
            'units' => CurrentConditionsService::UNITS,
            'items' => $points,
            'meta' => $meta + [
                'returnedRows' => count($rows),
                'points' => count($points),
                'duplicates' => $duplicates,
                'conflicts' => count($conflicts),
                'medianIntervalMinutes' => self::medianIntervalMinutes($points),
            ],
        ];
    }

    /**
     * Medián mezer mezi body v minutách (null při < 2 bodech).
     * Popisuje skutečnou frekvenci zápisu CRONem, která není pevná.
     *
     * @param list<array<string, mixed>> $points
     */
    public static function medianIntervalMinutes(array $points): ?float
    {
        $gaps = [];
        $previous = null;
        foreach ($points as $point) {
            $time = strtotime((string) $point['time']);
            if ($previous !== null) {
                $gaps[] = ($time - $previous) / 60;
            }
            $previous = $time;
        }
        if ($gaps === []) {
            return null;
        }
        sort($gaps);
        $middle = intdiv(count($gaps), 2);
        $median = count($gaps) % 2 === 1 ? $gaps[$middle] : ($gaps[$middle - 1] + $gaps[$middle]) / 2;
        return round($median, 1);
    }
}
