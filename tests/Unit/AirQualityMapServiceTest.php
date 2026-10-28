<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\AirQualityMapService;
use OuBrix\Weather\Weather\Support\AirQualityMapProduct;

require_once dirname(__DIR__) . '/support/AirQualityMapFixtures.php';

return static function (): int {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weather-air-map-' . bin2hex(random_bytes(6));
    $archive = AirQualityMapFixtures::archive();
    $calls = [];
    $now = new DateTimeImmutable('2026-10-28T10:35:00Z');
    $transport = static function (string $url, int $maxBytes) use (&$calls, $archive): string {
        $calls[] = [$url, $maxBytes];
        if (strpos($url, '/air_quality/now/maps/airquality_NRT-map_CR_PM10_h_09.zip') !== false) {
            return $archive;
        }
        if (strpos($url, 'airquality_NRT-map_static.json') !== false) {
            return AirQualityMapFixtures::staticMetadata();
        }
        if (substr($url, -strlen('/air_quality/now/maps/')) === '/air_quality/now/maps/') {
            return AirQualityMapFixtures::index();
        }
        throw new RuntimeException('Unexpected test URL.');
    };
    try {
        $result = (new AirQualityMapService(new ChmiGateway($directory, $transport, $now), $now))->build('PM10', 'hours');
        check($result['component']['code'] === 'PM10' && $result['type']['code'] === 'hours',
            'select requested component and aggregation');
        check($result['period']['start'] === '2026-10-28T09:00:00Z'
            && $result['period']['end'] === '2026-10-28T10:00:00Z', 'return interval from map metadata');
        check($result['bounds'] === [[48.109, 11.5074], [51.4484, 19.5518]], 'use official EPSG:3857 extent coordinates');
        check($result['raster']['width'] === 3000 && $result['raster']['height'] === 1929
            && $result['raster']['gridStepMeters'] === 100
            && abs($result['raster']['pixelWidthMeters'] - 298.5) < 0.1
            && abs($result['raster']['pixelHeightMeters'] - 298.6) < 0.1,
            'distinguish 100m source grid from PNG pixel resolution');
        check($result['legend']['values'][0]['label'] === '<20'
            && $result['legend']['units'] === 'µg∙m−3', 'return sanitized official legend labels and units');
        check(strpos($result['map'], 'data:image/png;base64,') === 0
            && count($calls) === 3 && $result['meta']['stale'] === false, 'return image and source provenance');
        check(strpos($calls[1][0], 'airquality_NRT-map_CR_PM10_h_09.zip') !== false,
            'choose the most recently published matching archive');

        foreach ([['NO2', 'days'], ['PM10', 'max1h'], ['../PM10', 'hours']] as $invalid) {
            $failed = false;
            try {
                (new AirQualityMapService(new ChmiGateway($directory, $transport, $now), $now))->build($invalid[0], $invalid[1]);
            } catch (RuntimeException $exception) {
                $failed = true;
            }
            check($failed, 'reject unsupported component/type combination');
        }
        $failed = false;
        try {
            AirQualityMapProduct::filename('PM10', 'hours', '../');
        } catch (RuntimeException $exception) {
            $failed = true;
        }
        check($failed, 'reject archive slot traversal');

        $badTransport = static function (string $url, int $maxBytes): string {
            if (strpos($url, 'airquality_NRT-map_CR_PM10_h_09.zip') !== false) {
                return AirQualityMapFixtures::archive('../outside.json');
            }
            if (strpos($url, 'airquality_NRT-map_static.json') !== false) {
                return AirQualityMapFixtures::staticMetadata();
            }
            return AirQualityMapFixtures::index();
        };
        $failed = false;
        try {
            (new AirQualityMapService(new ChmiGateway($directory . '-bad', $badTransport, $now), $now))->build('PM10', 'hours');
        } catch (RuntimeException $exception) {
            $failed = true;
        }
        check($failed, 'reject archive paths and invalidate malformed map');
        return 12;
    } finally {
        foreach ([$directory, $directory . '-bad'] as $path) {
            if (is_dir($path)) {
                foreach (scandir($path) ?: [] as $file) {
                    if ($file !== '.' && $file !== '..') {
                        unlink($path . DIRECTORY_SEPARATOR . $file);
                    }
                }
                rmdir($path);
            }
        }
    }
};
