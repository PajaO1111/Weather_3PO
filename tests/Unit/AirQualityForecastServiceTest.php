<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\AirQualityForecastService;

require_once dirname(__DIR__) . '/support/AirQualityForecastFixtures.php';

return static function (): int {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weather-air-forecast-' . bin2hex(random_bytes(6));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $rows = [
        ['component' => 'PM10', 'slot' => '23', 'modified' => $now],
        ['component' => 'PM10', 'slot' => '07', 'modified' => $now->modify('-5 minutes')],
        ['component' => 'PM10', 'slot' => '12', 'modified' => $now->modify('-10 minutes')],
    ];
    $created = [
        '23' => $now->modify('-30 minutes'),
        '07' => $now->modify('-10 minutes'),
        '12' => $now->modify('-20 minutes'),
    ];
    $sources = ['/air_quality/now/forecast/' => AirQualityForecastFixtures::index($rows)];
    foreach ($created as $slot => $createdAt) {
        $sources['/air_quality/now/forecast/airquality_FC-boxplots_ORP_PM10_h_' . $slot . '.zip']
            = AirQualityForecastFixtures::archive('PM10', $createdAt, $now);
    }
    $requested = [];
    $transport = static function (string $url, int $maxBytes) use (&$requested, $sources): string {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $requested[] = $path;
        if (!isset($sources[$path]) || strlen($sources[$path]) > $maxBytes) {
            throw new RuntimeException('Unexpected fixture request.');
        }
        return $sources[$path];
    };

    try {
        $gateway = new ChmiGateway($directory, $transport, $now);
        $service = new AirQualityForecastService($gateway, $now);
        $result = $service->build('PM10');
        check($result['area']['code'] === '892' && $result['area']['name'] === 'Most', 'select exact Most ORP metadata');
        check($result['pollutant']['unit'] === null, 'do not invent a forecast unit absent from source metadata');
        check($result['meta']['source']['archiveName'] === 'airquality_FC-boxplots_ORP_PM10_h_07.zip',
            'select newest JSON creation time, not the largest hour slot');
        check($result['meta']['createdAt'] === $created['07']->format('Y-m-d\TH:i:s\Z'),
            'preserve the chosen JSON creation timestamp');
        check(count($result['items']) === 72 && $result['currentMap']['type'] === 'mapa',
            'separate the spatial estimate from 72 forecast intervals');
        check($result['items'][0]['median'] === 12.0 && $result['items'][0]['p25'] === 11.0
            && $result['items'][0]['p75'] === 13.0, 'normalize percentile values');
        check(count($requested) === 4, 'inspect the three newest candidates and their index');

        $service->build('PM10');
        check(count($requested) === 4, 'cache the index and candidate archives within their TTL');

        $later = $now->modify('+4 hours');
        $offline = new ChmiGateway($directory, static function (): string {
            throw new RuntimeException('upstream offline');
        }, $later);
        $stale = (new AirQualityForecastService($offline, $later))->build('PM10');
        check($stale['meta']['stale'] === true && count($stale['meta']['warnings']) > 0,
            'mark older forecast and cached upstream data as stale');

        $rejected = false;
        try {
            $service->build('https://example.invalid');
        } catch (RuntimeException $exception) {
            $rejected = true;
        }
        check($rejected, 'reject arbitrary pollutants before requesting upstream data');

        return 10;
    } finally {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) unlink($file);
        }
        if (is_dir($directory)) rmdir($directory);
    }
};
