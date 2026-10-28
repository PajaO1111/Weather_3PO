<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\AirQualityHistoryService;

require_once dirname(__DIR__) . '/support/AirQualityHistoryFixtures.php';

return static function (): int {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weather-svrs-test-' . bin2hex(random_bytes(6));
    $calls = 0;
    $now = new DateTimeImmutable('2026-10-28T10:00:00Z');
    $gateway = new ChmiGateway($directory, static function (string $url, int $maxBytes) use (&$calls): string {
        $calls++;
        check(strpos($url, 'historicky_prehled_smogovych_situaci.csv') !== false
            && strlen(AirQualityHistoryFixtures::csv()) <= $maxBytes, 'request fixed SVRS source within size bound');
        return AirQualityHistoryFixtures::csv();
    }, $now);
    try {
        $result = (new AirQualityHistoryService($gateway))->build();
        check($result['area'] === 'Ústecký kraj' && count($result['events']) === 3,
            'select only SVRS events for Ústecký kraj');
        check($result['events'][0]['pollutant'] === 'O3'
            && $result['events'][0]['endedAt'] === null
            && $result['events'][0]['note'] === 'test poznámky',
            'sort newest event first and preserve open event metadata');
        check($result['events'][1]['issuedAt'] === '2010-12-03T18:10:00Z'
            && $result['events'][2]['issuedAt'] === '2010-12-03T15:20:00Z',
            'normalize explicit local offsets to UTC and preserve overlapping event types');
        check($result['events'][1]['durationHours'] === 53.0
            && $result['events'][1]['type'] !== $result['events'][2]['type'],
            'retain duration without merging smog situation and regulation');
        check($result['meta']['source']['license'] === 'CC BY 4.0'
            && $result['meta']['source']['cacheStatus'] === 'fresh'
            && $calls === 1, 'provide attribution and source cache provenance');
        $cached = (new AirQualityHistoryService(new ChmiGateway($directory, static function (): string {
            throw new RuntimeException('upstream offline');
        }, $now->modify('+2 days'))))->build();
        check($cached['meta']['source']['cacheStatus'] === 'stale'
            && count($cached['meta']['warnings']) === 1, 'use clearly marked stale history cache on outage');

        $invalid = new ChmiGateway($directory . '-invalid', static function (): string {
            return "latka,typ,oblast\nPM10,unknown,Ústecký kraj\n";
        }, $now);
        $failed = false;
        try {
            (new AirQualityHistoryService($invalid))->build();
        } catch (RuntimeException $exception) {
            $failed = true;
        }
        check($failed, 'reject malformed SVRS source instead of returning empty success');
        return 6;
    } finally {
        foreach ([$directory, $directory . '-invalid'] as $path) {
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
