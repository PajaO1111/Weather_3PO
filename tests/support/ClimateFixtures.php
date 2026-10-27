<?php
declare(strict_types=1);

final class ClimateFixtures
{
    public static function document(string $stream, string $header, array $rows): string
    {
        return json_encode([
            'datovyTokID' => $stream, 'datumVytvoreni' => '2026-05-05T10:32:27.614Z', 'verzeDat' => '1.0',
            'data' => ['data' => ['header' => $header, 'values' => $rows]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function sources(): array
    {
        $wsi = '0-20000-0-11433';
        $rows = [];
        for ($year = 1991; $year <= 2020; $year++) {
            for ($month = 1; $month <= 12; $month++) {
                $rows[] = [$wsi, 'T', (string) $year, sprintf('%02d', $month), 'AVG', 'AVG',
                    $month + ($year - 1991) / 10, '', ''];
                $rows[] = [$wsi, 'SRA', (string) $year, sprintf('%02d', $month), '06:00', 'SUM',
                    $month * 10 + $year - 1991, '', ''];
            }
        }
        return [
            'climate_history_station' => self::document('Open.Data.Metadata',
                'WSI,FULL_NAME,BEGIN_DATE,END_DATE,GEOGR1,GEOGR2,ELEVATION',
                [[$wsi, 'Kopisty', '1969-01-01T00:00:00Z', '3999-12-31T23:59:00Z', 13.62278, 50.54417, 240]]),
            'climate_history_elements' => self::document('Open.Data.Metadata',
                'OBS_TYPE,WSI,BEGIN_DATE,END_DATE,EG_EL_ABBREVIATION,NAME,UN_DESCRIPTION,SCHEDULE',
                [['DLY', $wsi, '1969-01-01T00:00:00Z', '3999-12-31T23:59:00Z', 'T', 'Teplota', '°C', 'AVG,06:00,13:00,20:00'],
                 ['DLY', $wsi, '1969-01-01T00:00:00Z', '3999-12-31T23:59:00Z', 'SRA', 'Srážka', 'mm', '06:00']]),
            'kopisty_monthly' => self::document('Open.Data.MLY',
                'STATION,ELEMENT,YEAR,MONTH,TIMEFUNCTION,MDFUNCTION,VALUE,FLAG_REPEAT,FLAG_INTERRUPTED', $rows),
        ];
    }

    public static function cache(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
            throw new RuntimeException('Cannot create climate fixture cache.');
        }
        foreach (self::sources() as $key => $body) {
            file_put_contents($directory . DIRECTORY_SEPARATOR . $key . '.json', json_encode([
                'body' => base64_encode($body), 'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'sourceLastModified' => null,
            ], JSON_THROW_ON_ERROR));
        }
    }
}
