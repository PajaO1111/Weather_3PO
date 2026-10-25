<?php
/**
 * Analytika vlastních měření, která nevyžadují referenční klimatologický normál.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class AnalyticsService
{
    /** @var list<int> */
    private const RAINFALL_WINDOWS = [30, 60, 90];

    /** @var HistoryService */
    private $history;

    /** @var DateTimeZone */
    private $timezone;

    public function __construct(HistoryService $history, DateTimeZone $timezone)
    {
        $this->history = $history;
        $this->timezone = $timezone;
    }

    /**
     * Srážky se sčítají jen za dny, kdy je úhrn známý; chybějící den není nula.
     *
     * @return array<string, mixed>
     */
    public function build(string $station, DateTimeImmutable $now): array
    {
        $today = $now->setTimezone($this->timezone)->setTime(0, 0, 0);
        $from = $today->sub(new DateInterval('P89D'));
        $history = $this->history->build($station, $from, $today, 'day', 1);
        $daily = [];
        foreach ($history['items'] as $item) {
            $daily[$item['date']] = $item;
        }

        $periods = [];
        foreach (self::RAINFALL_WINDOWS as $days) {
            $start = $today->sub(new DateInterval('P' . ($days - 1) . 'D'));
            $rainfall = 0.0;
            $daysWithKnownPrecip = 0;
            $daysWithData = 0;
            foreach ($daily as $date => $item) {
                if ($date < $start->format('Y-m-d') || $date > $today->format('Y-m-d')) {
                    continue;
                }
                $daysWithData++;
                if ($item['precip'] !== null) {
                    $rainfall += (float) $item['precip'];
                    $daysWithKnownPrecip++;
                }
            }

            $periods[(string) $days] = [
                'days' => $days,
                'fromDate' => $start->format('Y-m-d'),
                'toDate' => $today->format('Y-m-d'),
                'rainfallMm' => $daysWithKnownPrecip > 0 ? round($rainfall, 1) : null,
                'daysWithData' => $daysWithData,
                'daysWithKnownPrecip' => $daysWithKnownPrecip,
                'daysMissing' => $days - $daysWithKnownPrecip,
                'coveragePercent' => round(($daysWithKnownPrecip / $days) * 100, 1),
            ];
        }

        return [
            'station' => $station,
            'periods' => $periods,
            'meta' => [
                'timezone' => $this->timezone->getName(),
                'generatedAt' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'lastObservation' => $history['summary']['last'],
            ],
        ];
    }
}
