<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Services\AirQualityFiveYearMapService;

return static function (): int {
    $service = new AirQualityFiveYearMapService(
        dirname(__DIR__, 2) . '/data/air_quality/most-2020-2024.json'
    );
    $annual = $service->build('PM10');
    check($annual['period'] === '2020–2024'
        && $annual['component']['label'] === 'PM10 · roční průměr'
        && $annual['unit'] === 'µg/m³'
        && $annual['decimals'] === 1, 'identify the selected annual product, unit and precision');
    check($annual['statistics'] === ['gridCells' => 486, 'values' => 486, 'missing' => 0],
        'return the complete district grid coverage');
    check($annual['legend']['minimum'] === 9.0 && $annual['legend']['maximum'] === 23.5
        && count($annual['legend']['intervals']) === 5, 'derive local equal-interval legend bounds');
    check($annual['map']['type'] === 'FeatureCollection'
        && $annual['map']['features'][0]['properties']['value'] >= 0
        && $annual['boundary']['type'] === 'Polygon', 'return WGS84 grid cells and district boundary');
    check(strlen($annual['meta']['sourceArchiveSha256']) === 64
        && $annual['meta']['district']['ruianCode'] === 3508, 'retain source archive and district provenance');

    $daily = $service->build('PM10_24h');
    check($daily['layer']['code'] === '24h'
        && $daily['component']['label'] === 'PM10 · 24hodinová vrstva'
        && $daily['decimals'] === 0
        && $daily['legend']['minimum'] === 16.0
        && $daily['legend']['maximum'] === 43.0, 'keep the separate 24-hour product distinct from annual PM10');
    foreach (['NO2', 'BZN', 'BaP', 'PM10', 'PM2_5', 'As', 'Cd', 'Ni', 'Pb', 'SO2_24h', 'PM10_24h'] as $component) {
        check(AirQualityFiveYearMapService::supports($component), 'allow the published pollutant ' . $component);
        $selected = $service->build($component);
        check($selected['component']['code'] === $component && $selected['statistics']['values'] === 486,
            'build the complete map for ' . $component);
    }

    $failed = false;
    try {
        $service->build('../PM10');
    } catch (RuntimeException $exception) {
        $failed = true;
    }
    check($failed && !AirQualityFiveYearMapService::supports('../PM10'), 'reject unlisted pollutant values');

    $missing = new AirQualityFiveYearMapService(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-air-quality-grid.json');
    $failed = false;
    try {
        $missing->build('PM10');
    } catch (RuntimeException $exception) {
        $failed = true;
    }
    check($failed, 'report an unavailable preprocessed source instead of returning empty data');
    return 30;
};
