<?php
/**
 * HTTP test skutečných souborů public/ přes vestavěný PHP server
 * a tests/support/router.php (SQLite data místo produkční DB).
 */
declare(strict_types=1);

return static function (): int {
    $port = 8790 + random_int(0, 50);
    $root = dirname(__DIR__, 2);
    $command = escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port -t " . escapeshellarg($root . '/public')
        . ' ' . escapeshellarg($root . '/tests/support/router.php');
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    require_once dirname(__DIR__) . '/support/ClimateFixtures.php';
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weather-http-climate-' . bin2hex(random_bytes(6));
    ClimateFixtures::cache($cache);
    require_once dirname(__DIR__) . '/support/ForecastFixtures.php';
    ForecastFixtures::cache($cache);
    require_once dirname(__DIR__) . '/support/WarningFixtures.php';
    WarningFixtures::cache($cache);
    require_once dirname(__DIR__) . '/support/RadarFixtures.php';
    RadarFixtures::cache($cache);
    require_once dirname(__DIR__) . '/support/AirQualityForecastFixtures.php';
    AirQualityForecastFixtures::cache($cache);
    $environment = array_merge(getenv(), ['WEATHER_CHMI_CACHE_DIR' => $cache]);
    $process = proc_open($command, [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $root, $environment);
    check(is_resource($process), 'server start');

    /** @return array{0:int,1:string,2:string} [status, content-type, body] */
    $request = static function (string $path, string $method = 'GET') use ($port): array {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 10]]);
        $body = @file_get_contents("http://127.0.0.1:$port$path", false, $context);
        $headers = isset($http_response_header) ? $http_response_header : [];
        $status = 0;
        $type = '';
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            } elseif (stripos($header, 'Content-Type:') === 0) {
                $type = trim(substr($header, 13));
            }
        }
        return [$status, $type, (string) $body];
    };

    try {
        // Počkat, až server začne odpovídat.
        for ($i = 0; $i < 50; $i++) {
            if ($request('/api/health.php')[0] !== 0) {
                break;
            }
            usleep(100000);
        }

        [$status, $type, $body] = $request('/index.php');
        check($status === 200 && strpos($type, 'text/html') === 0, 'page 200 html');
        check(strpos($body, 'data-api-current="/api/current.php"') !== false, 'page api url');
        check(strpos($body, 'integrity="sha384-') !== false, 'SRI on CDN assets');
        check(strpos($body, 'Fatal') === false && strpos($body, 'Warning') === false, 'no PHP errors in page');

        [$status, $type, $body] = $request('/api/health.php');
        $health = json_decode($body, true);
        check($status === 200 && $health['status'] === 'ok' && $health['database'] === 'ok', 'health ok');

        [$status, $type, $body] = $request('/api/current.php');
        $current = json_decode($body, true);
        check($status === 200 && strpos($type, 'application/json') === 0, 'current 200 json');
        check($current['station']['id'] === 'TEST' && is_float($current['current']['pressure']), 'current payload');
        check($current['observed']['stale'] === false && $current['today']['samples'] > 0, 'current fresh with today');

        [$status, , $body] = $request('/api/observations.php');
        $series = json_decode($body, true);
        check($status === 200 && $series['meta']['hours'] === 24 && count($series['items']) > 200, 'series default 24 h');
        [$status, , $body] = $request('/api/observations.php?limit=12');
        check($status === 200 && count(json_decode($body, true)['items']) === 12, 'legacy limit');
        check($request('/api/observations.php?hours=1&limit=5')[0] === 400, 'hours+limit 400');

        check($request('/api/observations.php?limit=0')[0] === 400, 'invalid limit 400');
        check($request('/api/current.php', 'POST')[0] === 405, 'POST 405');
        check($request('/assets/js/dashboard.js')[0] === 200, 'asset served');

        // Stránka a API historie.
        [$status, $type, $body] = $request('/history.php');
        check($status === 200 && strpos($type, 'text/html') === 0 && strpos($body, 'data-api-history="/api/history.php"') !== false, 'history page');
        check(strpos($body, 'js/history.js') !== false && strpos($body, 'aria-current="page"') !== false && strpos($body, 'Fatal') === false, 'history page script and nav');
        [$status, , $body] = $request('/history.php?from=bad');
        check($status === 200 && strpos($body, 'alert-warning') !== false, 'history page invalid query falls back');
        [$status, $type, $body] = $request('/api/history.php?resolution=hour&from=' . date('Y-m-d', time() - 2 * 86400));
        $history = json_decode($body, true);
        check($status === 200 && strpos($type, 'application/json') === 0 && $history['resolution'] === 'hour' && count($history['items']) > 20, 'history api json');
        check($history['summary']['samples'] > 200 && is_array($history['summary']['temp_max']), 'history api summary');
        [$status, $type, $body] = $request('/api/history.php?format=csv&resolution=day');
        check($status === 200 && strpos($type, 'text/csv') === 0 && strpos($body, "\xEF\xBB\xBFDatum;") === 0, 'history csv');
        check($request('/api/history.php?from=2026-13-01')[0] === 400 && $request('/api/history.php?resolution=week')[0] === 400, 'history invalid 400');

        [$status, $type, $body] = $request('/stats.php');
        check($status === 200 && strpos($type, 'text/html') === 0 && strpos($body, 'data-api-stats="/api/stats.php"') !== false && strpos($body, 'js/stats.js') !== false, 'stats page');
        check(strpos($body, 'href="/stats.php"') !== false && strpos($body, 'Fatal') === false, 'stats nav link');
        check(strpos($request('/stats.php?year=abc')[2], 'alert-warning') !== false, 'stats page invalid year falls back');
        [$status, $type, $body] = $request('/api/stats.php');
        $stats = json_decode($body, true);
        check($status === 200 && strpos($type, 'application/json') === 0 && $stats['period']['group'] === 'month' && $stats['total']['days'] >= 1, 'stats api json');
        check(count($stats['years']) >= 1 && is_array($stats['records']['temp_max_high']) && is_array($stats['extremes']['temp_max']), 'stats api records');
        [$status, , $body] = $request('/api/stats.php?year=all');
        check($status === 200 && json_decode($body, true)['period']['group'] === 'year', 'stats api all');
        check($request('/api/stats.php?year=1999')[0] === 400, 'stats invalid 400');
        check(strpos($request('/stats.php')[2], 'data-api-windrose="/api/windrose.php?hours=168"') !== false, 'stats page has radar section');
        [$status, $type, $body] = $request('/analytics.php');
        check($status === 200 && strpos($type, 'text/html') === 0 && strpos($body, 'data-api-analytics="/api/analytics.php"') !== false, 'analytics page');
        check(strpos($body, 'href="/analytics.php"') !== false && strpos($body, 'js/analytics.js') !== false, 'analytics page navigation and module');
        [$status, $type, $body] = $request('/api/analytics.php');
        $analytics = json_decode($body, true);
        check($status === 200 && strpos($type, 'application/json') === 0, 'analytics api json');
        check(isset($analytics['periods']['30'], $analytics['periods']['60'], $analytics['periods']['90'])
            && isset($analytics['meta']['timezone'], $analytics['meta']['generatedAt']), 'analytics api includes bounded rainfall windows and metadata');
        [$status, $type, $body] = $request('/api/windrose.php');
        $rose = json_decode($body, true);
        check($status === 200 && strpos($type, 'application/json') === 0 && count($rose['sectors']) === 16 && $rose['hours'] === 168, 'windrose api json');
        check($request('/api/windrose.php?hours=0')[0] === 400, 'windrose invalid 400');

        [$status, $type, $body] = $request('/air-quality.php');
        check($status === 200 && strpos($type, 'text/html') === 0, 'air quality page 200 html');
        check(strpos($body, 'data-api-air-quality="/api/air-quality.php"') !== false
            && strpos($body, 'Ovzduší ČHMÚ') !== false
            && strpos($body, 'PM2,5') !== false, 'air quality page has API, navigation and pollutants');
        check(strpos($body, 'data-api="/api/air-quality-forecast.php"') !== false
            && strpos($body, 'Jak číst zobrazená data') !== false
            && stripos($body, 'jednotka v metadatech předpovědi není uvedena') !== false,
            'air quality page explains forecast and source limitations');
        check($request('/api/air-quality.php', 'POST')[0] === 405, 'air quality api rejects non-GET');
        [$status, $type, $body] = $request('/api/air-quality-forecast.php?pollutant=PM10');
        $airForecast = json_decode($body, true);
        check($status === 200 && strpos($type, 'application/json') === 0
            && $airForecast['area']['code'] === '892'
            && $airForecast['pollutant']['code'] === 'PM10'
            && $airForecast['pollutant']['unit'] === null, 'air forecast API uses Most and leaves unspecified unit null');
        check(count($airForecast['items']) === 72 && $airForecast['currentMap']['type'] === 'mapa'
            && $airForecast['meta']['source']['archiveCacheStatus'] === 'fresh',
            'air forecast API returns typed hourly forecasts, separate map and provenance');
        check($request('/api/air-quality-forecast.php?pollutant=other')[0] === 400
            && $request('/api/air-quality-forecast.php?pollutant[]=PM10')[0] === 400
            && $request('/api/air-quality-forecast.php?url=other')[0] === 400,
            'air forecast API rejects invalid pollutant and arbitrary query parameters');
        check($request('/api/air-quality-forecast.php', 'POST')[0] === 405
            && $request('/assets/js/air-quality.js')[0] === 200, 'air forecast API method and frontend module');
        [$status, $type, $body] = $request('/comparison.php');
        check($status === 200 && strpos($body, 'data-api="/api/comparison.php"') !== false, 'comparison page and api wired');
        check(strpos($body, 'js/comparison.js') !== false && strpos($body, 'Kopisty') !== false, 'comparison module and navigation');
        check($request('/api/comparison.php', 'POST')[0] === 405, 'comparison rejects POST');
        check($request('/api/comparison.php?hours=999')[0] === 400, 'comparison has bounded fixed window');
        [$status, $type, $body] = $request('/climate.php');
        check($status === 200 && strpos($type, 'text/html') === 0
            && strpos($body, 'data-api="/api/climate.php"') !== false, 'climate page wired');
        check(strpos($body, 'js/climate.js') !== false && strpos($body, 'Klima Kopisty') !== false
            && strpos($body, 'FLAG_REPEAT') !== false && strpos($body, '<details class="weather-menu">') !== false,
            'climate module, dropdown menu and methodology');
        check(strpos($body, 'Oficiální normál ČHMÚ 1991–2020') !== false
            && strpos($body, 'Normál %') !== false, 'climate page labels official station normal distinctly');
        [$status, $type, $body] = $request('/api/climate.php');
        $climate = json_decode($body, true);
        check($status === 200 && strpos($type, 'application/json') === 0
            && $climate['year'] === 2020 && count($climate['items']) === 12, 'actual climate API default year');
        check($climate['reference']['months'][0]['temperature']['count'] === 30
            && $climate['reference']['rainfallAnnual'] === 954.0, 'actual climate API reference calculations');
        check($climate['officialNormal']['annual']['temperature'] === 9.4
            && $climate['officialNormal']['annual']['precipitation'] === 493.5
            && $climate['officialNormal']['months'][0]['precipitation'] === 29.8,
            'climate API exposes official monthly and independent annual normals');
        check($climate['officialComparison']['annualRainfallPercent'] !== null
            && count($climate['meta']['sources']) === 5, 'climate API reports annual comparison and all source provenance');
        [$status, , $body] = $request('/api/climate.php?year=1991');
        check($status === 200 && json_decode($body, true)['year'] === 1991, 'climate year selection');
        check($request('/api/climate.php', 'POST')[0] === 405, 'climate rejects POST');
        check($request('/api/climate.php?year[]=1991')[0] === 400
            && $request('/api/climate.php?year=oops')[0] === 400
            && $request('/api/climate.php?station=other')[0] === 400, 'climate rejects invalid query shapes');
        check($request('/api/climate.php?year=1800')[0] === 400
            && $request('/api/climate.php?year=' . gmdate('Y'))[0] === 400, 'climate rejects unpublished and current year');
        check($request('/assets/js/climate.js')[0] === 200, 'climate module served');
        [$status, $type, $body] = $request('/forecast.php');
        check($status === 200 && strpos($type, 'text/html') === 0
            && strpos($body, 'data-api="/api/forecast.php"') !== false, 'forecast page wired');
        check(strpos($body, 'js/forecast.js') !== false
            && strpos($body, 'není lokální předpovědí pro Most') !== false, 'forecast module and geographical disclaimer');
        check(strpos($body, 'aria-current="page"') !== false
            && strpos($body, '<summary>Menu</summary>') !== false
            && strpos($body, 'js/navigation.js') !== false, 'native accessible navigation, enhancement script and active page');
        [$status, $type, $body] = $request('/api/forecast.php');
        $forecast = json_decode($body, true);
        check($status === 200 && strpos($type, 'application/json') === 0
            && count($forecast['items'][0]['sections']) === 3, 'actual forecast API with fixture');
        check($forecast['items'][0]['status'] === 'published'
            && $forecast['items'][0]['endTime'] === null && $forecast['meta']['source']['cacheStatus'] === 'fresh',
            'forecast API preserves absent expiry and source provenance');
        check($request('/api/forecast.php', 'POST')[0] === 405
            && $request('/api/forecast.php?station=Most')[0] === 400, 'forecast rejects method and arbitrary parameters');
        check($request('/assets/js/forecast.js')[0] === 200 && $request('/assets/js/navigation.js')[0] === 200,
            'forecast and navigation modules served');
        [$status, $type, $body] = $request('/warnings.php');
        check($status === 200 && strpos($type, 'text/html') === 0
            && strpos($body, 'data-api="/api/warnings.php"') !== false, 'warning page wired');
        check(strpos($body, 'js/warnings.js') !== false && strpos($body, 'CISORP 4209') !== false
            && strpos($body, 'aria-current="page"') !== false, 'warnings module, geography and active menu');
        [$status, $type, $body] = $request('/api/warnings.php');
        $alerts = json_decode($body, true);
        check($status === 200 && strpos($type, 'application/json') === 0
            && $alerts['area']['code'] === '4209' && $alerts['summary']['active'] === 1, 'real warning API uses isolated fixture');
        check($alerts['items'][0]['info']['severity'] === 'Moderate'
            && $alerts['meta']['source']['cacheStatus'] === 'fresh', 'warning API retains severity and provenance');
        check($request('/api/warnings.php', 'POST')[0] === 405
            && $request('/api/warnings.php?url=other')[0] === 400, 'warnings reject arbitrary query and method');
        check($request('/api/warnings.php', 'HEAD')[0] === 200, 'warnings HEAD works');
        check($request('/assets/js/warnings.js')[0] === 200, 'warnings module served');
        file_put_contents($cache . DIRECTORY_SEPARATOR . 'warnings_current.json', json_encode([
            'body' => base64_encode('<broken>'), 'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'sourceLastModified' => null,
        ], JSON_THROW_ON_ERROR));
        [$status, , $body] = $request('/api/warnings.php');
        check($status === 503 && json_decode($body, true)['error']['code'] === 'WARNINGS_UNAVAILABLE'
            && strpos($body, 'Neznamená to stav bez výstrah') !== false, 'invalid CAP is explicit 503 not empty success');
        [$status, $type, $body] = $request('/radar.php');
        check($status === 200 && strpos($type, 'text/html') === 0
            && strpos($body, 'data-api="/api/radar.php"') !== false && strpos($body, 'js/radar.js') !== false, 'radar page and module wired');
        check(strpos($body, 'leaflet@1.9.4') !== false && strpos($body, 'aria-current="page"') !== false, 'radar pinned map library and active link');
        [$status, $type, $body] = $request('/api/radar.php');
        $radar = json_decode($body, true);
        check($status === 200 && strpos($type, 'application/json') === 0
            && $radar['product']['id'] === 'maxz' && $radar['crs'] === 'EPSG:3857', 'radar API default product');
        check($radar['bounds'][1] === [52.167, 20.77] && strpos($radar['image'], 'data:image/png;base64,') === 0,
            'radar JSON includes validated PNG paired with full bounds');
        [$status, , $body] = $request('/api/radar.php?product=merge');
        $radar = json_decode($body, true);
        check($status === 200 && $radar['product']['unit'] === 'mm / 1 h'
            && strtotime($radar['frame']['end']) - strtotime($radar['frame']['start']) === 3600, 'MERGE hourly API');
        check($request('/api/radar.php?product[]=maxz')[0] === 400
            && $request('/api/radar.php?product=other')[0] === 400
            && $request('/api/radar.php?url=https://other')[0] === 400, 'radar rejects malformed and arbitrary parameters');
        check($request('/api/radar.php', 'POST')[0] === 405
            && $request('/api/radar.php', 'HEAD')[0] === 200, 'radar method contract');
        check($request('/assets/js/radar.js')[0] === 200, 'radar module served');
        file_put_contents($cache . DIRECTORY_SEPARATOR . 'radar_index_maxz.json', json_encode([
            'body' => base64_encode('<html>bad index</html>'), 'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'sourceLastModified' => null,
        ], JSON_THROW_ON_ERROR));
        [$status, , $body] = $request('/api/radar.php');
        check($status === 503 && json_decode($body, true)['error']['code'] === 'RADAR_UNAVAILABLE', 'invalid radar source not empty success');
        return 76;
    } finally {
        $status = proc_get_status($process);
        if ($status['running']) {
            // Windows: ukončit celý strom procesů vestavěného serveru.
            if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
                exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' >NUL 2>&1');
            } else {
                proc_terminate($process);
            }
        }
        proc_close($process);
        foreach (glob($cache . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) unlink($file);
        rmdir($cache);
    }
};
