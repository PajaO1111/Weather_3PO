<?php
declare(strict_types=1);

final class ComparisonFixtures
{
    public static function cache(string $directory): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $date = $now->format('Ymd');
        $wsi = '0-20000-0-11433';
        $table = static function (string $header, array $rows) use ($now): string {
            return json_encode([
                'datumVytvoreni' => $now->format('Y-m-d\TH:i:s\Z'), 'verzeDat' => '1.0',
                'data' => ['data' => ['header' => $header, 'values' => $rows]],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        };
        $sources = [
            'climate_meta1_' . $date => $table('WSI,FULL_NAME,GEOGR1,GEOGR2,ELEVATION',
                [[$wsi, 'Kopisty', 13.62278, 50.54417, 240]]),
            'climate_meta2_' . $date => $table('OBS_TYPE,WSI,EG_EL_ABBREVIATION,UN_DESCRIPTION',
                [['10M', $wsi, 'T', '°C'], ['10M', $wsi, 'F', 'm/s'], ['10M', $wsi, 'SRA10M', 'mm']]),
            'climate_meta4_' . $date => $table('FLAG2,DESCRIPTION',
                [[0, 'Good/Kvalitní hodnota'], [5, 'Unknown/Kvalita neznámá']]),
        ];
        foreach ([$now->modify('-1 day'), $now] as $day) {
            $rows = [];
            for ($time = $day->setTime(0, 0); $time < $day->setTime(0, 0)->modify('+1 day'); $time = $time->modify('+10 minutes')) {
                foreach (['T' => 8.0, 'F' => 4.0, 'SRA10M' => 0.1] as $element => $value) {
                    $rows[] = [$wsi, $element, $time->format('Y-m-d\TH:i:s\Z'), $value, '', 5];
                }
            }
            $sources['kopisty_' . $day->format('Ymd')] = $table('STATION,ELEMENT,DT,VAL,FLAG,QUALITY', $rows);
        }
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
            throw new RuntimeException('Cannot create comparison fixture cache.');
        }
        foreach ($sources as $key => $body) {
            file_put_contents($directory . DIRECTORY_SEPARATOR . $key . '.json', json_encode([
                'body' => base64_encode($body), 'fetchedAt' => $now->format('Y-m-d\TH:i:s\Z'), 'sourceLastModified' => null,
            ], JSON_THROW_ON_ERROR));
        }
    }
}
