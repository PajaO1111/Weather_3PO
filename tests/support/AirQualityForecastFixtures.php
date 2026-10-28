<?php
declare(strict_types=1);

final class AirQualityForecastFixtures
{
    private const COMPONENTS = ['PM10', 'PM2_5', 'NO2', 'O3', 'SO2'];

    /**
     * @param array<int, array{component: string, slot: string, modified: DateTimeImmutable}> $entries
     */
    public static function index(array $entries): string
    {
        $rows = [];
        foreach ($entries as $entry) {
            $filename = 'airquality_FC-boxplots_ORP_' . $entry['component'] . '_h_' . $entry['slot'] . '.zip';
            $rows[] = '<a href="' . $filename . '">' . $filename . '</a>            '
                . $entry['modified']->format('d-M-Y H:i') . '              1000';
        }
        return "<html><body><pre>\n" . implode("\n", $rows) . "\n</pre></body></html>";
    }

    public static function archive(string $component, DateTimeImmutable $createdAt, ?DateTimeImmutable $firstForecast = null): string
    {
        $reference = ($firstForecast ?? $createdAt)->setTimezone(new DateTimeZone('UTC'));
        $start = $reference->setTime((int) $reference->format('H'), 0, 0)->modify('+1 hour');
        $values = [];
        $mapStart = $start->modify('-1 hour');
        $values[] = self::value($mapStart, 'mapa', 1);
        for ($hour = 0; $hour < 72; $hour++) {
            $values[] = self::value($start->modify('+' . $hour . ' hours'), 'predpoved', 10 + $hour);
        }
        $document = [
            'zaznamID' => 'fixture-' . $component . '-' . $createdAt->format('YmdHi'),
            'datovyZdrojID' => 'fixture',
            'datovyTokID' => 'fixture',
            'datumVytvoreni' => $createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'verzeDat' => '1.0',
            'data' => [
                'metadata' => [
                    'DataSourceID' => 'fixture',
                    'Dataset' => 'fixture',
                    'OriginFile' => $createdAt->format('Y-m-d_H') . '_' . $component . '_892_percentiles.csv',
                    'ComponentCode' => $component,
                    'AreaType' => 'ORP',
                    'AreaName' => 'Most',
                    'AreaCode' => '892',
                    'AreaCodeSystem' => 'CZ-RUIAN-ORP',
                    'Identifier' => $component . '_892',
                ],
                'values' => $values,
            ],
        ];
        $stamp = $createdAt->setTimezone(new DateTimeZone('UTC'))->format('YmdH');
        $filename = 'airquality_FC-boxplots_ORP-892_' . $stamp . '_' . $component . '_h.json';
        $temporary = tempnam(sys_get_temp_dir(), 'air-forecast-fixture-');
        if (!is_string($temporary)) {
            throw new RuntimeException('Cannot create forecast fixture.');
        }
        $zip = new ZipArchive();
        try {
            if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true
                || !$zip->addFromString($filename, json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                || !$zip->close()) {
                throw new RuntimeException('Cannot build forecast ZIP fixture.');
            }
            $body = file_get_contents($temporary);
            if (!is_string($body)) {
                throw new RuntimeException('Cannot read forecast ZIP fixture.');
            }
            return $body;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function cache(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create forecast fixture cache.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $slot = $now->format('H');
        $entries = [];
        $files = [];
        foreach (self::COMPONENTS as $component) {
            $entries[] = ['component' => $component, 'slot' => $slot, 'modified' => $now];
            $files['air_forecast_archive_' . $component . '_h_' . $slot] = self::archive($component, $now);
        }
        $files['air_forecast_index'] = self::index($entries);
        foreach ($files as $key => $body) {
            file_put_contents($directory . DIRECTORY_SEPARATOR . $key . '.json', json_encode([
                'body' => base64_encode($body),
                'fetchedAt' => $now->format('Y-m-d\TH:i:s\Z'),
                'sourceLastModified' => null,
            ], JSON_THROW_ON_ERROR));
        }
    }

    private static function value(DateTimeImmutable $start, string $type, int $base): array
    {
        return [
            'dt_beg_utc' => $start->format('Y-m-d\TH:i:s\Z'),
            'dt_end_utc' => $start->modify('+1 hour')->format('Y-m-d\TH:i:s\Z'),
            'type' => $type,
            'min' => (string) $base,
            'p_25' => (string) ($base + 1),
            'p_50' => (string) ($base + 2),
            'p_75' => (string) ($base + 3),
            'max' => (string) ($base + 4),
        ];
    }
}
