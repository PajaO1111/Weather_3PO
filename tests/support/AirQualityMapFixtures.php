<?php
declare(strict_types=1);

final class AirQualityMapFixtures
{
    public static function index(): string
    {
        return '<a href="airquality_NRT-map_CR_PM10_h_08.zip">airquality_NRT-map_CR_PM10_h_08.zip</a>                 28-Oct-2026 09:33               18377' . "\n"
            . '<a href="airquality_NRT-map_CR_PM10_h_09.zip">airquality_NRT-map_CR_PM10_h_09.zip</a>                 28-Oct-2026 10:33               18377' . "\n"
            . '<a href="https://invalid.example/airquality_NRT-map_CR_PM10_h_23.zip">evil</a> 28-Oct-2026 11:33';
    }

    public static function staticMetadata(): string
    {
        $legend = [];
        foreach (['PM10' => ['hours', 'days'], 'PM2_5' => ['hours', 'days'],
            'NO2' => ['hours', 'max1h'], 'O3' => ['hours', 'max1h', 'max8h'], 'SO2' => ['hours', 'days']] as $component => $types) {
            foreach ($types as $type) {
                $legend[] = [
                    'componentCode' => $component,
                    'type' => $type,
                    'units' => 'µg∙m<sup>−3</sup>',
                    'desc' => $type === 'hours' ? 'hodinový průměr' : 'denní průměr',
                    'values' => [
                        ['color' => '#C7EAFB', 'label' => '<20'],
                        ['color' => '#FFFFFF', 'label' => 'Neúplná data'],
                    ],
                ];
            }
        }
        return json_encode([
            'data' => [
                'extent' => [
                    'EPSG' => 3857, 'xSW' => 1281000, 'ySW' => 6125000, 'xNE' => 2176500, 'yNE' => 6701000,
                    'dx' => 100, 'dy' => 100, 'lonSW' => 11.5074, 'latSW' => 48.109,
                    'lonNE' => 19.5518, 'latNE' => 51.4484,
                ],
                'legend' => $legend,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function archive(string $entryName = 'airquality_NRT-map_CR_2026102809_PM10_h.json'): string
    {
        require_once __DIR__ . '/RadarFixtures.php';
        $png = RadarFixtures::png(3000, 1929);
        $document = [
            'datovyZdrojID' => 'ovzdusi.mereni',
            'datumVytvoreni' => '2026-10-28T10:01:00.000Z',
            'data' => [
                'componentCode' => 'PM10',
                'type' => 'hours',
                'dt_beg_utc' => '2026-10-28T09:00:00.000Z',
                'dt_end_utc' => '2026-10-28T10:00:00.000Z',
                'mapFormat' => 'png',
                'mapEncoding' => 'base64',
                'map' => base64_encode($png),
            ],
        ];
        $path = tempnam(sys_get_temp_dir(), 'wx-map-fixture-');
        if ($path === false) {
            throw new RuntimeException('Cannot create air quality map archive fixture.');
        }
        $zip = new ZipArchive();
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true
                || !$zip->addFromString($entryName, json_encode($document, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Cannot write air quality map archive fixture.');
            }
            $zip->close();
            $archive = file_get_contents($path);
            if (!is_string($archive)) {
                throw new RuntimeException('Cannot read air quality map archive fixture.');
            }
            return $archive;
        } finally {
            @unlink($path);
        }
    }

    public static function cache(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
            throw new RuntimeException('Cannot create air quality map fixture cache.');
        }
        foreach ([
            'air_quality_map_index' => self::index(),
            'air_quality_map_static' => self::staticMetadata(),
            'air_map_archive_PM10_hours_09' => self::archive(),
        ] as $key => $body) {
            file_put_contents($directory . DIRECTORY_SEPARATOR . $key . '.json', json_encode([
                'body' => base64_encode($body),
                'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'sourceLastModified' => null,
            ], JSON_THROW_ON_ERROR));
        }
    }
}
