<?php
declare(strict_types=1);

final class WarningFixtures
{
    public static function info(string $code = '4209', string $severity = 'Moderate', string $certainty = 'Likely'): string
    {
        return '<info><language>cs</language><category>Met</category><event>Silný vítr</event>'
            . '<urgency>Expected</urgency><severity>' . $severity . '</severity><certainty>' . $certainty . '</certainty>'
            . '<onset>2026-10-27T20:00:00+01:00</onset><expires>2026-10-28T12:00:00+01:00</expires>'
            . '<headline>Výstraha pro Most</headline><description>Vítr &amp; déšť.</description>'
            . '<instruction>Omezte pohyb venku.</instruction><area><areaDesc>Ústecký kraj</areaDesc>'
            . '<geocode><valueName>CISORP</valueName><value>' . $code . '</value></geocode></area></info>';
    }

    public static function document(?string $infos = null, string $type = 'Alert', string $id = 'a',
        string $sent = '2026-10-27T21:00:00Z', string $references = '', string $status = 'Actual'): string
    {
        return '<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2"><identifier>' . $id . '</identifier>'
            . '<sender>chmi@chmi.cz</sender><sent>' . $sent . '</sent><status>' . $status . '</status>'
            . '<msgType>' . $type . '</msgType><scope>Public</scope><references>' . $references . '</references>'
            . ($infos ?? self::info()) . '</alert>';
    }

    public static function cache(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) throw new RuntimeException('Cannot create warning cache.');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $info = self::info();
        $info = str_replace(['2026-10-27T20:00:00+01:00', '2026-10-28T12:00:00+01:00'],
            [$now->modify('-1 hour')->format('Y-m-d\TH:i:s\Z'), $now->modify('+12 hours')->format('Y-m-d\TH:i:s\Z')], $info);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'warnings_current.json', json_encode([
            'body' => base64_encode(self::document($info, 'Alert', 'fixture', $now->modify('-1 hour')->format('Y-m-d\TH:i:s\Z'))),
            'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'sourceLastModified' => null,
        ], JSON_THROW_ON_ERROR));
    }
}
