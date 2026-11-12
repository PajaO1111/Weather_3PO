<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;

final class MostForecastService
{
    private const SOURCE_URL = 'https://www.chmi.cz/meteogram/282-most';
    private const MODEL = 'ALADIN';
    private const POINT_ELEVATION = 280;

    /** @var ChmiGateway */
    private $gateway;

    public function __construct(ChmiGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function build(?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $response = $this->gateway->fetch('forecast_most');

        try {
            $document = json_decode($response['body'], true, 16);
            if (!is_array($document) || json_last_error() !== JSON_ERROR_NONE
                || !is_array($document['data'] ?? null) || $document['data'] === []
                || array_keys($document['data']) !== range(0, count($document['data']) - 1)
                || count($document['data']) > 100 || isset($document['code'])) {
                throw new RuntimeException('Neočekávaná struktura meteogramu ČHMÚ pro Most.');
            }

            $hours = [];
            $previousTimestamp = null;
            foreach ($document['data'] as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('Neplatná hodinová předpověď ČHMÚ pro Most.');
                }
                $time = $this->time($row['validityTime'] ?? null);
                $timestamp = strtotime($time);
                if ($previousTimestamp !== null && $timestamp <= $previousTimestamp) {
                    throw new RuntimeException('Časy hodinové předpovědi ČHMÚ nejsou vzestupné.');
                }
                $previousTimestamp = $timestamp;
                $hours[] = [
                    'time' => $time,
                    'temperatureC' => $this->number($row, 't2m', -100, 60),
                    'precipitationMm' => $this->number($row, 'prec', 0, 100),
                    'snowMm' => $this->number($row, 'snow', 0, 100),
                    'windSpeedMs' => $this->number($row, 'windSpeed', 0, 100),
                    'windGustMs' => $this->number($row, 'windGustSpeed', 0, 100),
                    'windDirectionDeg' => $this->number($row, 'windDirection', 0, 360),
                    'pressureHpa' => $this->number($row, 'mslp', 800, 1100),
                    'cloudCoverPct' => $this->number($row, 'cloudsTot', 0, 100),
                    'icon' => $this->icon($row['icon'] ?? null),
                ];
            }
        } catch (RuntimeException $exception) {
            $this->gateway->invalidate('forecast_most');
            throw $exception;
        }

        unset($response['body']);
        $warnings = [];
        if ($response['cacheStatus'] === 'stale') {
            $warnings[] = $response['upstreamError'];
        }

        return [
            'location' => [
                'name' => 'Most',
                'model' => self::MODEL,
                'pointElevationM' => self::POINT_ELEVATION,
                'pointId' => 282,
                'sourceUrl' => self::SOURCE_URL,
            ],
            'hours' => $hours,
            'meta' => [
                'generatedAt' => $now->format('Y-m-d\TH:i:s\Z'),
                'source' => $response,
                'warnings' => array_values(array_filter($warnings)),
            ],
        ];
    }

    private function time($value): string
    {
        if (!is_string($value)
            || preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?(Z|[+-]\d{2}:\d{2})$/D', $value, $match) !== 1) {
            throw new RuntimeException('Neplatný čas hodinové předpovědi ČHMÚ.');
        }
        $offset = $match[2] === 'Z' ? '+00:00' : $match[2];
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $match[1] . $offset);
        $errors = DateTimeImmutable::getLastErrors();
        if ($time === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException('Neplatný čas hodinové předpovědi ČHMÚ.');
        }
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function number(array $row, string $key, float $minimum, float $maximum): ?float
    {
        if (!array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }
        $value = $row[$key];
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
            || $value < $minimum || $value > $maximum) {
            throw new RuntimeException('Neplatná hodnota ' . $key . ' v předpovědi ČHMÚ.');
        }
        return (float) $value;
    }

    private function icon($value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0 || $value > 999) {
            throw new RuntimeException('Neplatný kód ikony v předpovědi ČHMÚ.');
        }
        return $value;
    }
}
