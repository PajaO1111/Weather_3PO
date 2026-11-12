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
    private const METRICS = [
        'temperature' => ['field' => 'temp_c', 'element' => 'T', 'unit' => '°C'],
        'wind' => ['field' => 'wind_speed', 'element' => 'F', 'unit' => 'km/h'],
        'precipitation' => ['field' => 'precip_mm', 'element' => 'SRA10M', 'unit' => 'mm'],
    ];

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
        $metrics = [];
        foreach (self::METRICS as $name => $definition) {
            $ours = $name === 'precipitation'
                ? $this->rainHours($local['items'] ?? [], $from, $to)
                : $this->hours($local['items'] ?? [], $from, $to, false, $name);
            $official = $this->hours($kopisty['items'] ?? [], $from, $to, true, $name);
            $provisional = $this->hours($kopisty['items'] ?? [], $from, $to, true, $name, true);
            $metrics[$name] = $this->compare($ours, $official, $provisional, $from, $to) + ['unit' => $definition['unit']];
        }
        $temperature = $metrics['temperature'];
        $items = $temperature['items'];
        $qualityCounts = [];
        $flagCounts = [];
        foreach ($kopisty['items'] ?? [] as $point) {
            if (($point['element'] ?? 'T') !== 'T') {
                continue;
            }
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
        return [
            'localStation' => $local['station'] ?? null,
            'kopistyStation' => $kopisty['station'] ?? null,
            'unit' => '°C',
            'range' => ['from' => $from->format('Y-m-d\TH:i:s\Z'), 'to' => $to->format('Y-m-d\TH:i:s\Z'), 'hours' => 24],
            'items' => $items,
            'summary' => $temperature['summary'],
            'metrics' => $metrics,
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

    private function compare(array $ours, array $official, array $provisional, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $items = [];
        $paired = 0;
        $delta = 0.0;
        $provisionalPaired = 0;
        for ($hour = $from; $hour < $to; $hour = $hour->modify('+1 hour')) {
            $key = $hour->format('Y-m-d\TH:00:00\Z');
            $a = $ours[$key] ?? ['value' => null, 'samples' => 0, 'excluded' => 0];
            $b = $official[$key] ?? ['value' => null, 'samples' => 0, 'excluded' => 0];
            $c = $provisional[$key] ?? ['value' => null, 'samples' => 0, 'excluded' => 0, 'unknown' => 0];
            $unverified = $c['unknown'] > 0 ? $c['value'] : null;
            $difference = $a['value'] !== null && $b['value'] !== null ? round($a['value'] - $b['value'], 2) : null;
            if ($difference !== null) {
                $paired++;
                $delta += $difference;
            }
            $unverifiedDifference = $a['value'] !== null && $unverified !== null ? round($a['value'] - $unverified, 2) : null;
            if ($unverifiedDifference !== null) {
                $provisionalPaired++;
            }
            $items[] = [
                'time' => $key,
                'local' => $a['value'],
                'kopisty' => $b['value'],
                'difference' => $difference,
                'localSamples' => $a['samples'],
                'kopistySamples' => $b['samples'],
                'kopistyExcluded' => $b['excluded'],
                'kopistyUnverified' => $unverified,
                'kopistyUnknownSamples' => $c['unknown'],
                'kopistyUnverifiedSamples' => $c['samples'],
                'kopistyUnverifiedExcluded' => $c['excluded'],
                'unverifiedDifference' => $unverifiedDifference,
            ];
        }
        return [
            'items' => $items,
            'summary' => ['pairedHours' => $paired, 'meanDifference' => $paired > 0 ? round($delta / $paired, 2) : null,
                'unverifiedPairedHours' => $provisionalPaired],
        ];
    }

    /**
     * Hodina je prostý průměr vzorků; neúplné pokrytí zůstává viditelné počtem vzorků.
     * Konfliktní duplicity se vyloučí, nikoli vyberou naslepo.
     *
     * @param list<array<string, mixed>> $points
     * @return array<string, array{value: float|null, samples: int, excluded: int, unknown: int}>
     */
    private function hours(array $points, DateTimeImmutable $from, DateTimeImmutable $to, bool $official, string $metric, bool $allowUnknown = false): array
    {
        $definition = self::METRICS[$metric];
        $rain = $metric === 'precipitation';
        $unique = [];
        foreach ($points as $point) {
            if ($official && ($point['element'] ?? 'T') !== $definition['element']) {
                continue;
            }
            if (!is_string($point['time'] ?? null)) {
                throw new RuntimeException('Srovnávací řada obsahuje neplatný čas.');
            }
            $time = $rain ? (new DateTimeImmutable($point['time']))->modify('-1 second')->format('Y-m-d\TH:i:s\Z') : $point['time'];
            if ($time < $from->format('Y-m-d\TH:i:s\Z') || $time >= $to->format('Y-m-d\TH:i:s\Z')) {
                continue;
            }
            $value = $point[$definition['field']] ?? null;
            if ($rain && !preg_match('/T\d{2}:[0-5]0:00Z$/', $point['time'])) {
                $value = null;
            }
            $quality = $point['quality'] ?? null;
            $unknown = $quality === 5.0 || $quality === 5;
            if ($official && (!in_array($quality, $allowUnknown ? [0, 0.0, 5, 5.0] : [0, 0.0], true) || ($point['flag'] ?? '') !== '')) {
                $value = null;
            }
            if ($value !== null && (!is_numeric($value) || !is_finite((float) $value) || ($metric !== 'temperature' && $value < 0))) {
                $value = null;
            }
            if ($official && $metric === 'wind' && $value !== null) {
                $value *= 3.6;
            }
            $sample = ['value' => $value, 'unknown' => $unknown && $value !== null ? 1 : 0];
            if (array_key_exists($time, $unique) && $unique[$time] !== $sample) {
                $unique[$time] = ['value' => null, 'unknown' => 0];
            } elseif (!array_key_exists($time, $unique)) {
                $unique[$time] = $sample;
            }
        }
        $buckets = [];
        foreach ($unique as $time => $sample) {
            $value = $sample['value'];
            $key = substr($time, 0, 13) . ':00:00Z';
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['sum' => 0.0, 'samples' => 0, 'excluded' => 0, 'unknown' => 0];
            }
            if ($value === null) {
                $buckets[$key]['excluded']++;
            } else {
                $buckets[$key]['sum'] += $value;
                $buckets[$key]['samples']++;
                $buckets[$key]['unknown'] += $sample['unknown'];
            }
        }
        $result = [];
        foreach ($buckets as $key => $bucket) {
            $result[$key] = [
                'value' => $bucket['samples'] > 0 && (!$rain || $bucket['samples'] === 6)
                    ? round($rain ? $bucket['sum'] : $bucket['sum'] / $bucket['samples'], 2) : null,
                'samples' => $bucket['samples'],
                'excluded' => $bucket['excluded'],
                'unknown' => $bucket['unknown'],
            ];
        }
        return $result;
    }

    /**
     * Denní čítač není intenzita srážek. Přírůstky přiřadíme konci intervalu;
     * mezery nad 10 minut, chybějící hodnoty a reset čítače zneplatní hodinu.
     */
    private function rainHours(array $points, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $unique = [];
        foreach ($points as $point) {
            if (!is_string($point['time'] ?? null)) {
                throw new RuntimeException('Srovnávací řada obsahuje neplatný čas.');
            }
            $time = $point['time'];
            $value = $point['precip_total'] ?? null;
            if ($value !== null && (!is_numeric($value) || !is_finite((float) $value) || $value < 0)) {
                $value = null;
            }
            $value = $value === null ? null : (float) $value;
            if (array_key_exists($time, $unique) && $unique[$time] !== $value) {
                $unique[$time] = null;
            } elseif (!array_key_exists($time, $unique)) {
                $unique[$time] = $value;
            }
        }
        ksort($unique);
        $timezone = new DateTimeZone('Europe/Prague');
        $result = [];
        for ($hour = $from; $hour < $to; $hour = $hour->modify('+1 hour')) {
            $start = $hour->getTimestamp();
            $end = $start + 3600;
            $previous = null;
            $previousValue = null;
            $sum = 0.0;
            $samples = 0;
            $excluded = 0;
            $coveredStart = false;
            $last = null;
            foreach ($unique as $time => $value) {
                $instant = new DateTimeImmutable($time);
                $timestamp = $instant->getTimestamp();
                if ($timestamp <= $start) {
                    $previous = $instant;
                    $previousValue = $value;
                    continue;
                }
                if ($timestamp > $end) {
                    break;
                }
                $valid = $previous !== null && $previousValue !== null && $value !== null
                    && $timestamp - $previous->getTimestamp() <= 600
                    && $instant->setTimezone($timezone)->format('Y-m-d') === $previous->setTimezone($timezone)->format('Y-m-d')
                    && $value >= $previousValue;
                if ($valid) {
                    $coveredStart = $coveredStart || $previous->getTimestamp() <= $start;
                    $sum += $value - $previousValue;
                    $samples++;
                } else {
                    $excluded++;
                }
                $last = $timestamp;
                $previous = $instant;
                $previousValue = $value;
            }
            $result[$hour->format('Y-m-d\TH:00:00\Z')] = [
                'value' => $coveredStart && $last !== null && $end - $last <= 600 && $excluded === 0 ? round($sum, 2) : null,
                'samples' => $samples,
                'excluded' => $excluded,
            ];
        }
        return $result;
    }
}
