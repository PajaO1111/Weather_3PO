<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\MostForecastService;

require_once dirname(__DIR__) . '/support/MostForecastFixtures.php';

return static function (): int {
    $now = new DateTimeImmutable('2026-10-27T22:00:00Z');
    $document = MostForecastFixtures::document($now);
    $body = json_encode($document, JSON_THROW_ON_ERROR);
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weather-most-forecast-' . bin2hex(random_bytes(6));
    $calls = [];
    $gateway = new ChmiGateway($directory, static function (string $url, int $maxBytes) use (&$body, &$calls): string {
        $calls[] = [$url, $maxBytes];
        return $body;
    }, $now);
    $service = new MostForecastService($gateway);
    $count = 0;
    $assert = static function (bool $ok, string $message) use (&$count): void {
        check($ok, $message);
        $count++;
    };
    $load = static function (array $data) use (&$body, $gateway, $service, $now): array {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $gateway->invalidate('forecast_most');
        return $service->build($now);
    };
    try {
        $result = $service->build($now);
        $assert(count($result['hours']) === 4 && $result['hours'][0]['temperatureC'] === 6.5,
            'normalize ALADIN values and preserve hourly observations');
        $assert($calls[0] === ['https://data-provider.chmi.cz/api/graphs/graf.meteogram/282', 250000],
            'use the fixed, bounded official CHMI Most point endpoint');
        $assert($result['location']['name'] === 'Most' && $result['location']['pointElevationM'] === 280
            && $result['location']['model'] === 'ALADIN'
            && $result['location']['sourceUrl'] === 'https://www.chmi.cz/meteogram/282-most',
            'report source point and official meteogram');
        $assert($result['hours'][0]['time'] === '2026-10-27T23:00:00Z'
            && $result['hours'][0]['windDirectionDeg'] === 45.0
            && $result['hours'][0]['pressureHpa'] === 1020.5,
            'normalize source offsets and keep metrics');
        $service->build($now);
        $assert(count($calls) === 1, 'fresh cache avoids extra network request');

        foreach (['bad_time', 'unsorted', 'bad_value', 'bad_icon', 'empty', 'error_code'] as $case) {
            $data = $document;
            if ($case === 'bad_time') $data['data'][0]['validityTime'] = 'tomorrow';
            if ($case === 'unsorted') $data['data'][1]['validityTime'] = $data['data'][0]['validityTime'];
            if ($case === 'bad_value') $data['data'][0]['t2m'] = 999;
            if ($case === 'bad_icon') $data['data'][0]['icon'] = '70.svg';
            if ($case === 'empty') $data['data'] = [];
            if ($case === 'error_code') $data = ['code' => 404, 'data' => []];
            $failed = false;
            try {
                $load($data);
            } catch (RuntimeException $exception) {
                $failed = true;
            }
            $assert($failed, 'reject malformed point forecast: ' . $case);
        }
    } finally {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) unlink($file);
        if (is_dir($directory)) rmdir($directory);
    }
    return $count;
};
