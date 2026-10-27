<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Support\ChmiJson;

final class ForecastService
{
    /** @var ChmiGateway */
    private $gateway;

    public function __construct(ChmiGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function build(?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $response = $this->gateway->fetch('forecast_today');
        try {
            $data = json_decode($response['body'], true, 32);
            if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE || $data === []
                || array_keys($data) !== range(0, count($data) - 1) || count($data) > 50) {
                throw new RuntimeException('Neočekávaná struktura textové předpovědi ČHMÚ.');
            }
            $items = [];
            $warnings = [];
            foreach ($data as $row) {
                if (!is_array($row)) throw new RuntimeException('Neplatná položka předpovědi ČHMÚ.');
                $sent = ChmiJson::time($row['sent'] ?? null);
                $start = ChmiJson::time($row['startTime'] ?? null);
                $end = !isset($row['endTime']) || $row['endTime'] === '' ? null : ChmiJson::time($row['endTime']);
                $age = $now->getTimestamp() - strtotime($sent);
                if ($age < -300 || ($end !== null && strtotime($end) <= strtotime($start))) {
                    throw new RuntimeException('Nesouhlasí čas vydání nebo interval předpovědi ČHMÚ.');
                }
                $sections = $row['data'] ?? null;
                if (!is_array($sections) || $sections === [] || count($sections) > 100
                    || array_keys($sections) !== range(0, count($sections) - 1)) {
                    throw new RuntimeException('Předpověď ČHMÚ nemá platné textové sekce.');
                }
                $texts = [];
                foreach ($sections as $section) {
                    if (!is_array($section)) throw new RuntimeException('Neplatná textová sekce ČHMÚ.');
                    $texts[] = [
                        'headline' => $this->optionalText($section['headline'] ?? null),
                        'text' => $this->text($section['displayText'] ?? null),
                    ];
                }
                $stale = $age > 86400;
                $status = $stale ? 'stale' : ($end !== null && strtotime($end) <= $now->getTimestamp()
                    ? 'expired' : (strtotime($start) > $now->getTimestamp() ? 'upcoming' : ($end === null ? 'published' : 'active')));
                if ($stale) $warnings[] = 'Vydání předpovědi je starší než 24 hodin; nemusí již odpovídat současné situaci.';
                if ($status === 'expired') $warnings[] = 'Čas platnosti zveřejněné předpovědi již skončil.';
                $items[] = [
                    'mainHeadline' => $this->optionalText($row['mainHeadline'] ?? null),
                    'sent' => $sent, 'startTime' => $start, 'endTime' => $end,
                    'ageSeconds' => max(0, $age), 'stale' => $stale, 'status' => $status, 'sections' => $texts,
                ];
            }
        } catch (RuntimeException $exception) {
            $this->gateway->invalidate('forecast_today');
            throw $exception;
        }
        unset($response['body']);
        if ($response['cacheStatus'] === 'stale') $warnings[] = $response['upstreamError'];
        return [
            'scope' => 'Obecná textová předpověď ČHMÚ; není lokální předpovědí pro Most.',
            'items' => $items,
            'meta' => ['generatedAt' => $now->format('Y-m-d\TH:i:s\Z'), 'source' => $response,
                'warnings' => array_values(array_unique($warnings))],
        ];
    }

    private function optionalText($value): ?string
    {
        if ($value === null || $value === '') return null;
        return $this->text($value);
    }

    private function text($value): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 50000) {
            throw new RuntimeException('Chybějící nebo neplatný text předpovědi ČHMÚ.');
        }
        return trim($value);
    }
}
