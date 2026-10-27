<?php
/**
 * Společná UTC osa posledních 24 uzavřených hodin pro dvě nezávislé stanice.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class ComparisonService
{
    /**
     * @param array<string, mixed>|null $local
     * @param array<string, mixed>|null $kopisty
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    public function build(?array $local, ?array $kopisty, DateTimeImmutable $now, array $warnings = []): array
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $to = $now->setTime((int) $now->format('H'), 0);
        $from = $to->modify('-24 hours');
        $ours = $this->hours($local['items'] ?? [], $from, $to, false);
        $official = $this->hours($kopisty['items'] ?? [], $from, $to, true);
        $items = [];
        $qualityCounts = [];
        $flagCounts = [];
        foreach ($kopisty['items'] ?? [] as $point) {
            if ($point['time'] < $from->format('Y-m-d\TH:i:s\Z') || $point['time'] >= $to->format('Y-m-d\TH:i:s\Z')) {
                continue;
            }
            $qualityKey = (string) ($point['quality'] ?? 'unknown');
            if (!isset($qualityCounts[$qualityKey])) {
                $qualityCounts[$qualityKey] = ['count' => 0, 'label' => $point['qualityLabel'] ?? 'Neznámá kvalita'];
            }
            $qualityCounts[$qualityKey]['count']++;
            if (($point['flag'] ?? '') !== '') {
                $flagCounts[$point['flag']] = ($flagCounts[$point['flag']] ?? 0) + 1;
            }
        }
        $paired = 0;
        $delta = 0.0;
        for ($hour = $from; $hour < $to; $hour = $hour->modify('+1 hour')) {
            $key = $hour->format('Y-m-d\TH:00:00\Z');
            $a = $ours[$key] ?? ['value' => null, 'samples' => 0, 'excluded' => 0];
            $b = $official[$key] ?? ['value' => null, 'samples' => 0, 'excluded' => 0];
            $difference = $a['value'] !== null && $b['value'] !== null ? round($a['value'] - $b['value'], 2) : null;
            if ($difference !== null) {
                $paired++;
                $delta += $difference;
            }
            $items[] = [
                'time' => $key,
                'local' => $a['value'],
                'kopisty' => $b['value'],
                'difference' => $difference,
                'localSamples' => $a['samples'],
                'kopistySamples' => $b['samples'],
                'kopistyExcluded' => $b['excluded'],
            ];
        }
        return [
            'localStation' => $local['station'] ?? null,
            'kopistyStation' => $kopisty['station'] ?? null,
            'unit' => '°C',
            'range' => ['from' => $from->format('Y-m-d\TH:i:s\Z'), 'to' => $to->format('Y-m-d\TH:i:s\Z'), 'hours' => 24],
            'items' => $items,
            'summary' => ['pairedHours' => $paired, 'meanDifference' => $paired > 0 ? round($delta / $paired, 2) : null],
            'meta' => [
                'generatedAt' => $now->format('Y-m-d\TH:i:s\Z'),
                'localAvailable' => $local !== null,
                'kopistyAvailable' => $kopisty !== null,
                'localTruncated' => !empty($local['meta']['truncated']),
                'kopisty' => $kopisty['meta'] ?? null,
                'kopistyQualityCounts' => $qualityCounts,
                'kopistyFlagCounts' => $flagCounts,
                'warnings' => array_values(array_unique(array_merge($warnings, $kopisty['meta']['warnings'] ?? []))),
            ],
        ];
    }

    /**
     * Hodina je prostý průměr vzorků; neúplné pokrytí zůstává viditelné počtem vzorků.
     * Konfliktní duplicity se vyloučí, nikoli vyberou naslepo.
     *
     * @param list<array<string, mixed>> $points
     * @return array<string, array{value: float|null, samples: int, excluded: int}>
     */
    private function hours(array $points, DateTimeImmutable $from, DateTimeImmutable $to, bool $official): array
    {
        $unique = [];
        foreach ($points as $point) {
            if (!is_string($point['time'] ?? null)) {
                throw new RuntimeException('Srovnávací řada obsahuje neplatný čas.');
            }
            $time = $point['time'];
            if ($time < $from->format('Y-m-d\TH:i:s\Z') || $time >= $to->format('Y-m-d\TH:i:s\Z')) {
                continue;
            }
            $value = $point['temp_c'] ?? null;
            if ($official && (($point['quality'] ?? null) !== 0.0 || ($point['flag'] ?? '') !== '')) {
                $value = null;
            }
            if (array_key_exists($time, $unique) && $unique[$time] !== $value) {
                $unique[$time] = null;
            } elseif (!array_key_exists($time, $unique)) {
                $unique[$time] = $value;
            }
        }
        $buckets = [];
        foreach ($unique as $time => $value) {
            $key = substr($time, 0, 13) . ':00:00Z';
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['sum' => 0.0, 'samples' => 0, 'excluded' => 0];
            }
            if ($value === null) {
                $buckets[$key]['excluded']++;
            } else {
                $buckets[$key]['sum'] += $value;
                $buckets[$key]['samples']++;
            }
        }
        $result = [];
        foreach ($buckets as $key => $bucket) {
            $result[$key] = [
                'value' => $bucket['samples'] > 0 ? round($bucket['sum'] / $bucket['samples'], 2) : null,
                'samples' => $bucket['samples'],
                'excluded' => $bucket['excluded'],
            ];
        }
        return $result;
    }
}
