<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\RadarService;
use OuBrix\Weather\Weather\Support\RadarProduct;

require_once dirname(__DIR__) . '/support/RadarFixtures.php';

return static function (): int {
    $now = new DateTimeImmutable('2026-10-27T22:40:00Z');
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weather-radar-' . bin2hex(random_bytes(6));
    $index = RadarFixtures::index('maxz', '202610272235') . RadarFixtures::index('maxz', '202610272240');
    $image = RadarFixtures::png();
    $calls = [];
    $offline = false;
    $transport = static function (string $url, int $limit) use (&$index, &$image, &$calls, &$offline): string {
        $calls[] = [$url, $limit];
        if ($offline) throw new RuntimeException('offline');
        if (substr($url, -1) === '/') return $index;
        if (strpos($url, '/scl/') !== false) return RadarFixtures::png(56, 236);
        return $image;
    };
    $gateway = new ChmiGateway($directory, $transport, $now);
    $service = new RadarService($gateway);
    $count = 0;
    $assert = static function (bool $ok, string $label) use (&$count): void { check($ok, $label); $count++; };
    try {
        $result = $service->build('maxz', $now);
        $assert($result['frame']['end'] === '2026-10-27T22:40:00Z'
            && $result['frame']['start'] === '2026-10-27T22:35:00Z', 'latest exact filename, UTC end and five-minute interval');
        $assert($result['bounds'] === [[48.047, 11.267], [52.167, 20.770]]
            && $result['dataBounds'] === [[48.047, 11.267], [51.458, 19.624]], 'whole PNG extent distinct from data bounds');
        $assert($result['crs'] === 'EPSG:3857' && $result['frame']['width'] === 680
            && $result['frame']['height'] === 460, 'georeferenced raster dimensions');
        $assert($result['product']['name'] === 'MAX_Z_MASK' && $result['product']['unit'] === 'dBZ'
            && $result['product']['refreshSeconds'] === 300, 'reflectivity not hourly rain');
        $assert(base64_decode(substr($result['image'], strlen('data:image/png;base64,'))) === $image, 'image payload tied to selected metadata');
        $assert($calls[0] === ['https://opendata.chmi.cz/meteorology/weather/radar/composite/maxz/png_masked/', 2000000]
            && strpos($calls[1][0], 'pacz2gmaps3.z_max3d.20261027.2240.0.png') !== false, 'fixed index and derived allowlisted image');
        $service->build('maxz', $now);
        $assert(count($calls) === 3, 'all three source resources cached');
        $index = RadarFixtures::index('merge', '202610272220');
        $result = $service->build('merge', $now);
        $assert($result['frame']['start'] === '2026-10-27T21:20:00Z'
            && $result['frame']['end'] === '2026-10-27T22:20:00Z', 'MERGE timestamp is end of trailing hour');
        $assert($result['product']['unit'] === 'mm / 1 h' && $result['product']['refreshSeconds'] === 600,
            'MERGE ten-minute refresh, hourly amount');
        $assert($result['meta']['stale'] === false, 'normal MERGE latency not falsely stale');
        $offline = true;
        $later = new ChmiGateway($directory, $transport, $now->modify('+6 minutes'));
        $result = (new RadarService($later))->build('maxz', $now->modify('+6 minutes'));
        $assert($result['meta']['stale'] && $result['meta']['warnings'] !== [], 'outage cache visibly stale');
        $offline = false;
        $result = $service->build('merge', $now->modify('+3 hours'));
        $assert($result['meta']['stale'], 'old measurement stale even if cache fetch is fresh');
        foreach (['other', '../maxz', 'maxz?url=x'] as $product) {
            $failed = false;
            try { $service->build($product, $now); } catch (RuntimeException $exception) { $failed = true; }
            $assert($failed, 'reject product ' . $product);
        }
        foreach (['<a href="https://evil/pacz2gmaps3.z_max3d.20261027.2240.0.png">x</a>',
            '<a href="../pacz2gmaps3.z_max3d.20261027.2240.0.png">x</a>', '<html>empty</html>',
            '<a href="pacz2gmaps3.z_max3d.20260231.2240.0.png">x</a>',
            RadarFixtures::index('maxz', '202610282240'), RadarFixtures::index('maxz', '202610172240')] as $html) {
            $failed = false;
            try { RadarProduct::latest($html, 'maxz', $now); } catch (RuntimeException $exception) { $failed = true; }
            $assert($failed, 'reject invalid, untrusted or unusable listing');
        }
        foreach (['not png', substr($image, 0, -4), RadarFixtures::png(1, 1),
            substr_replace($image, 'x', 45, 1)] as $body) {
            $failed = false;
            try { RadarProduct::png($body, [680, 460]); } catch (RuntimeException $exception) { $failed = true; }
            $assert($failed, 'reject invalid, truncated, wrong size or bad CRC PNG');
        }
        $image = '<html>bad upstream</html>';
        $gateway->invalidate('radar_frame_maxz_202610272240');
        $failed = false;
        try { $service->build('maxz', $now); } catch (RuntimeException $exception) { $failed = true; }
        $assert($failed && !is_file($directory . DIRECTORY_SEPARATOR . 'radar_frame_maxz_202610272240.json'),
            'invalid image evicted not returned as no rain');
        $failed = false;
        try { $gateway->fetch('radar_frame_maxz_202610272240/evil'); } catch (RuntimeException $exception) { $failed = true; }
        $assert($failed, 'gateway never accepts caller URL or traversal');
        $image = RadarFixtures::png();
        foreach (['202610272215', '202610272220', '202610272225', '202610272230'] as $stamp) {
            $gateway->fetch('radar_frame_maxz_' . $stamp);
        }
        $assert(count(glob($directory . DIRECTORY_SEPARATOR . 'radar_frame_maxz_*.json')) === 3,
            'only three latest frame caches retained per product');
        $assert(is_file($directory . DIRECTORY_SEPARATOR . 'radar_index_merge.json')
            && is_file($directory . DIRECTORY_SEPARATOR . 'radar_legend_maxz.json'), 'pruning preserves other products and shared resources');
        $shuffled = RadarFixtures::index('maxz', '202610272240') . RadarFixtures::index('maxz', '202610272235');
        $assert(RadarProduct::latest($shuffled, 'maxz', $now) === '202610272240', 'newest selected by UTC name not directory order');
        $radius = 6378137;
        $north = log(tan(pi() / 4 + deg2rad(52.167) / 2)) * $radius;
        $south = log(tan(pi() / 4 + deg2rad(48.047) / 2)) * $radius;
        $width = deg2rad(20.770 - 11.267) * $radius;
        $assert(abs($width / ($north - $south) - 680 / 460) < 0.001,
            'full image projected aspect ratio matches 680x460 raster');
        foreach (['202610272241', '202602302240'] as $stamp) {
            $failed = false;
            try { $gateway->fetch('radar_frame_maxz_' . $stamp); } catch (RuntimeException $exception) { $failed = true; }
            $assert($failed, 'gateway rejects invalid timestamp or non-five-minute slot');
        }
    } finally {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) unlink($file);
        if (is_dir($directory)) rmdir($directory);
    }
    return $count;
};
