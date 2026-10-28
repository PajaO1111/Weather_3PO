<?php
declare(strict_types=1);

final class AirQualityHistoryFixtures
{
    public static function csv(): string
    {
        return "\xEF\xBB\xBFlatka,typ,oblast,vyhlaseni,odvolani,delka_trvani,poznamka\n"
            . "PM10,\"smogová situace\",\"Ústecký kraj\",2010-12-03T16:20:00+01:00,2010-12-06T00:08:00+01:00,55.8,\n"
            . "PM10,regulace,\"Ústecký kraj\",2010-12-03T19:10:00+01:00,2010-12-06T00:08:00+01:00,53,\n"
            . "O3,varování,\"Ústecký kraj\",2025-07-14T12:00:00+02:00,,,\"test poznámky\"\n"
            . "PM10,\"smogová situace\",\"Středočeský kraj\",2025-01-01T10:00:00+01:00,2025-01-02T10:00:00+01:00,24,\n";
    }

    public static function cache(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
            throw new RuntimeException('Cannot create air quality history fixture cache.');
        }
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'air_svrs_history.json', json_encode([
            'body' => base64_encode(self::csv()),
            'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'sourceLastModified' => null,
        ], JSON_THROW_ON_ERROR));
    }
}
