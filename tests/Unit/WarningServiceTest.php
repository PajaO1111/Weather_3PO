<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\WarningService;
use OuBrix\Weather\Weather\Support\CapParser;

require_once dirname(__DIR__) . '/support/WarningFixtures.php';

return static function (): int {
    $now = new DateTimeImmutable('2026-10-27T22:00:00Z');
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weather-cap-' . bin2hex(random_bytes(6));
    $body = WarningFixtures::document();
    $calls = [];
    $gateway = new ChmiGateway($directory, static function (string $url, int $maxBytes) use (&$body, &$calls): string {
        $calls[] = [$url, $maxBytes];
        return $body;
    }, $now);
    $service = new WarningService($gateway);
    $parser = new CapParser();
    $count = 0;
    $assert = static function (bool $ok, string $label) use (&$count): void { check($ok, $label); $count++; };
    $load = static function (string $xml) use (&$body, $gateway, $service, $now): array {
        $body = $xml;
        $gateway->invalidate('warnings_current');
        return $service->build($now);
    };
    $select = static function (array $xmls) use ($service, $parser, $now): array {
        return $service->select(array_map([$parser, 'parse'], $xmls), $now);
    };
    try {
        $result = $service->build($now);
        $assert($calls[0] === ['https://vystrahy-cr.chmi.cz/data2/XOCZ50_OKPR.xml', 4000000], 'fixed official source, bounded XML');
        $assert($result['area']['code'] === '4209' && $result['summary']['active'] === 1, 'Most exact official code');
        $assert($result['items'][0]['info']['onset'] === '2026-10-27T19:00:00Z', 'offset normalized to UTC');
        $assert($result['items'][0]['info']['description'] === 'Vítr & déšť.'
            && $result['items'][0]['info']['instruction'] === 'Omezte pohyb venku.', 'retain source text and instruction');
        $service->build($now);
        $assert(count($calls) === 1, 'five minute cache avoids duplicate fetch');
        $info = WarningFixtures::info();
        $result = $load(WarningFixtures::document($info . WarningFixtures::info('4208')
            . str_replace('<language>cs</language>', '<language>en-GB</language>', $info)));
        $assert(count($result['items']) === 1, 'different ORP and English duplicates not included');
        $multi = str_replace('</info>', '<area><areaDesc>Most</areaDesc><geocode><valueName>CISORP</valueName>'
            . '<value>4209</value></geocode></area></info>', WarningFixtures::info('4208'));
        $result = $load(WarningFixtures::document($multi . str_replace('Silný vítr', 'Déšť', $info)));
        $assert(count($result['items']) === 2 && count($result['items'][0]['info']['areas']) === 2, 'all matching info and areas retained');
        $none = str_replace('<expires>2026-10-28T12:00:00+01:00</expires>', '', WarningFixtures::info('4209', 'Minor', 'Unlikely'));
        $none = str_replace('Silný vítr', 'Žádná výstraha před větrem', $none);
        $result = $load(WarningFixtures::document($none));
        $assert($result['summary']['state'] === 'no_warning_reported' && $result['items'][0]['info']['expires'] === null,
            'documented no-warning combination; absent expiry allowed without inventing time');
        $open = str_replace('<expires>2026-10-28T12:00:00+01:00</expires>', '', $info);
        $assert($load(WarningFixtures::document($open))['items'][0]['state'] === 'active', 'warning until revoked supports absent expiry');
        $future = str_replace('2026-10-27T20:00:00+01:00', '2026-10-28T09:00:00+01:00', $info);
        $assert($load(WarningFixtures::document($future))['summary']['upcoming'] === 1, 'upcoming warning');
        $expired = str_replace('2026-10-28T12:00:00+01:00', '2026-10-27T22:00:00Z', $info);
        $result = $load(WarningFixtures::document($expired));
        $assert($result['items'][0]['state'] === 'expired' && $result['summary']['state'] === 'unverified', 'expiry boundary not active or all-clear');
        $missing = str_replace('<onset>2026-10-27T20:00:00+01:00</onset>', '', $info);
        $assert($load(WarningFixtures::document($missing))['items'][0]['state'] === 'unknown_time', 'missing start stays explicitly unknown');
        $unknownArea = preg_replace('#<geocode>.*?</geocode>#', '', $info);
        $result = $load(WarningFixtures::document($unknownArea . $none));
        $assert($result['summary']['state'] === 'unverified' && $result['summary']['uncertainBlocks'] > 0, 'unknown geography prevents all-clear');
        $mixed = str_replace('</info>', '<area><areaDesc>Neznámá oblast</areaDesc></area></info>', WarningFixtures::info('4208'));
        $assert($load(WarningFixtures::document($mixed . $none))['summary']['state'] === 'unverified', 'unknown area beside other ORP cannot be silently ignored');
        $result = $load(WarningFixtures::document(str_replace('Likely', 'Unknown', $info) . $none));
        $assert($result['summary']['uncertainBlocks'] > 0 && $result['meta']['warnings'] !== [], 'unknown certainty visible');
        $result = $load(WarningFixtures::document($info, 'Alert', 'a', '2026-10-26T21:59:59Z'));
        $assert($result['items'][0]['state'] === 'stale' && $result['summary']['state'] === 'unverified', 'old issuance cannot confirm current status');
        $result = $load(WarningFixtures::document($info, 'Alert', 'a', '2026-10-27T21:00:00Z', '', 'Test'));
        $assert($result['items'][0]['state'] === 'non_actual' && $result['summary']['active'] === 0, 'test messages separate');
        $result = $load(WarningFixtures::document(str_replace('<category>Met</category>', '<category>Env</category>', $info)));
        $assert($result['items'][0]['info']['categories'] === ['Env'], 'environmental warning not a concentration or AQI');
        $environment = str_replace('<category>Met</category>', '<category>Env</category>', $info);
        $environment = str_replace('</info>', '<eventCode><valueName>SVRS</valueName><value>SMOGSIT.PM10</value></eventCode>'
            . '<parameter><valueName>situation</valueName><value></value></parameter>'
            . '<parameter><valueName>pollutant</valueName><value>PM10</value></parameter></info>', $environment);
        $result = $load(WarningFixtures::document($environment));
        $assert($result['items'][0]['info']['parameters'][0] === ['name' => 'situation', 'value' => '']
            && $result['items'][0]['info']['eventCodes'][0]['name'] === 'SVRS', 'real CAP empty situation and SVRS parameters retained');
        $effective = str_replace('</info>', '<effective>2026-10-28T09:00:00+01:00</effective></info>', $info);
        $assert($load(WarningFixtures::document($effective))['items'][0]['state'] === 'upcoming', 'future effective time gates current onset');
        $assert($load(WarningFixtures::document($none . $expired))['summary']['state'] === 'unverified', 'expired block beside no-warning not misreported as all-clear');
        $wrongCode = str_replace('<value>4209</value>', '<value>14209</value>', $info);
        $assert($load(WarningFixtures::document($wrongCode))['items'] === [], 'CISORP matching is exact not substring or area name');
        $noCzech = str_replace('<language>cs</language>', '<language>en-GB</language>', $none);
        $assert($load(WarningFixtures::document($noCzech))['summary']['state'] === 'unverified', 'unsupported language cannot confirm no warnings');
        $english = str_replace('<language>cs</language>', '<language>en-GB</language>', WarningFixtures::info('4209', 'Severe'));
        $result = $load(WarningFixtures::document($none . $english));
        $assert($result['summary']['active'] === 1 && count($result['items']) === 2
            && $result['items'][1]['info']['language'] === 'en-GB', 'unpaired foreign-language warning never hidden by other Czech blocks');
        $ended = str_replace('</info>', '<parameter><valueName>eventEndingTime</valueName>'
            . '<value>2026-10-27T21:59:59Z</value></parameter></info>', $info);
        $result = $load(WarningFixtures::document($ended));
        $assert($result['items'][0]['state'] === 'expired' && $result['items'][0]['info']['expires'] === '2026-10-28T11:00:00Z'
            && $result['items'][0]['info']['eventEndingTime'] === '2026-10-27T21:59:59Z',
            'event ending time distinct from message expiry and ends event display');
        $ref = 'chmi@chmi.cz,a,2026-10-27T20:00:00Z';
        $old = WarningFixtures::document($info, 'Alert', 'a', '2026-10-27T20:00:00Z');
        $update = WarningFixtures::document(WarningFixtures::info('4208'), 'Update', 'b', '2026-10-27T21:00:00Z', $ref);
        $result = $select([$update, $old]);
        $assert($result['items'] === [] && $result['meta']['supersededMessages'] === 1, 'update applied before geography independent of input order');
        $cancel = WarningFixtures::document('', 'Cancel', 'c', '2026-10-27T21:00:00Z', $ref);
        $result = $select([$old, $cancel]);
        $assert($result['items'] === [] && count($result['notices']) === 1 && $result['summary']['state'] === 'unverified',
            'cancel removes predecessor but alone never proves all-clear');
        $testCancel = WarningFixtures::document('', 'Cancel', 't', '2026-10-27T21:00:00Z', $ref, 'Test');
        $assert($select([$old, $testCancel])['summary']['active'] === 1, 'test cancellation cannot revoke actual message');
        $privateCancel = str_replace('<scope>Public</scope>', '<scope>Private</scope>', $cancel);
        $assert($select([$old, $privateCancel])['summary']['active'] === 1, 'private cancellation cannot revoke public warning');
        $wrongReference = str_replace('a,2026-10-27T20:00:00Z', 'a,2026-10-27T19:00:00Z', $cancel);
        $assert($select([$old, $wrongReference])['summary']['active'] === 1, 'reference must match sender, identifier and sent');
        $chain = WarningFixtures::document($info, 'Update', 'c', '2026-10-27T21:30:00Z', 'chmi@chmi.cz,b,2026-10-27T21:00:00Z');
        $assert($select([$chain, $update, $old])['summary']['active'] === 1, 'update chain returns only latest replacement');
        foreach ([
            'wrong namespace' => str_replace('urn:oasis:names:tc:emergency:cap:1.2', 'urn:other', $old),
            'DTD and external entity' => '<!DOCTYPE alert [<!ENTITY x SYSTEM "file:///not-readable">]>' . $old,
            'bad XML' => '<alert>',
            'duplicate sent' => str_replace('</sent>', '</sent><sent>2026-10-27T20:00:00Z</sent>', $old),
            'bad date' => str_replace('2026-10-27T20:00:00Z', '2026-02-31T20:00:00Z', $old),
            'no offset' => str_replace('2026-10-27T20:00:00Z', '2026-10-27T20:00:00', $old),
            'future sent' => str_replace('2026-10-27T20:00:00Z', '2026-10-28T20:00:00Z', $old),
            'bad severity' => str_replace('Moderate', 'Orange', $old),
            'inverted interval' => str_replace('2026-10-28T12:00:00+01:00', '2026-10-26T12:00:00+01:00', $old),
            'inverted event interval' => str_replace('</info>', '<parameter><valueName>eventEndingTime</valueName>'
                . '<value>2026-10-26T12:00:00Z</value></parameter></info>', $old),
            'empty reference' => WarningFixtures::document($info, 'Update'),
            'bad reference' => WarningFixtures::document($info, 'Update', 'b', '2026-10-27T21:00:00Z', 'broken'),
            'reference to future' => WarningFixtures::document($info, 'Update', 'b', '2026-10-27T21:00:00Z',
                'chmi@chmi.cz,a,2026-10-27T21:01:00Z'),
            'missing parameter value' => str_replace('</info>', '<parameter><valueName>situation</valueName></parameter></info>', $old),
            'UTF16' => "\0<\0a\0l\0e\0r\0t",
        ] as $case => $xml) {
            $failed = false;
            try { $load($xml); } catch (RuntimeException $exception) { $failed = true; }
            $assert($failed, 'reject ' . $case);
            $assert(!is_file($directory . DIRECTORY_SEPARATOR . 'warnings_current.json'), 'invalid cache evicted: ' . $case);
        }
        $assert(CapParser::time('2026-10-27T23:00:00.123+01:00') === '2026-10-27T22:00:00Z', 'fractional seconds supported');
        $load(WarningFixtures::document($none));
        $offline = new ChmiGateway($directory, static function (): string { throw new RuntimeException('offline'); }, $now->modify('+6 minutes'));
        $result = (new WarningService($offline))->build($now->modify('+6 minutes'));
        $assert($result['summary']['state'] === 'unverified' && $result['meta']['source']['cacheStatus'] === 'stale'
            && $result['meta']['warnings'] !== [] && $result['items'][0]['state'] === 'stale',
            'outage cache is never a current all-clear');
        $gateway->invalidate('warnings_current');
        $failed = false;
        try { (new WarningService($offline))->build($now); } catch (RuntimeException $exception) { $failed = true; }
        $assert($failed, 'outage without cache throws rather than returning empty warnings');
    } finally {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) unlink($file);
        if (is_dir($directory)) rmdir($directory);
    }
    return $count;
};
