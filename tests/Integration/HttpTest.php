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
        check($request('/api/air-quality.php', 'POST')[0] === 405, 'air quality api rejects non-GET');
        [$status, $type, $body] = $request('/comparison.php');
        check($status === 200 && strpos($body, 'data-api="/api/comparison.php"') !== false, 'comparison page and api wired');
        check(strpos($body, 'js/comparison.js') !== false && strpos($body, 'Kopisty') !== false, 'comparison module and navigation');
        check($request('/api/comparison.php', 'POST')[0] === 405, 'comparison rejects POST');
        check($request('/api/comparison.php?hours=999')[0] === 400, 'comparison has bounded fixed window');
        [$status, $type, $body] = $request('/climate.php');
        check($status === 200 && strpos($type, 'text/html') === 0
            && strpos($body, 'data-api="/api/climate.php"') !== false, 'climate page wired');
        check(strpos($body, 'js/climate.js') !== false && strpos($body, 'Klima Kopisty') !== false
            && strpos($body, 'FLAG_REPEAT') !== false && strpos($body, 'navbar-nav me-auto flex-wrap') !== false,
            'climate module, wrapping menu and methodology');
        [$status, $type, $body] = $request('/api/climate.php');
        $climate = json_decode($body, true);
        check($status === 200 && strpos($type, 'application/json') === 0
            && $climate['year'] === 2020 && count($climate['items']) === 12, 'actual climate API default year');
        check($climate['reference']['months'][0]['temperature']['count'] === 30
            && $climate['reference']['rainfallAnnual'] === 954.0, 'actual climate API reference calculations');
        [$status, , $body] = $request('/api/climate.php?year=1991');
        check($status === 200 && json_decode($body, true)['year'] === 1991, 'climate year selection');
        check($request('/api/climate.php', 'POST')[0] === 405, 'climate rejects POST');
        check($request('/api/climate.php?year[]=1991')[0] === 400
            && $request('/api/climate.php?year=oops')[0] === 400
            && $request('/api/climate.php?station=other')[0] === 400, 'climate rejects invalid query shapes');
        check($request('/api/climate.php?year=1800')[0] === 400
            && $request('/api/climate.php?year=' . gmdate('Y'))[0] === 400, 'climate rejects unpublished and current year');
        check($request('/assets/js/climate.js')[0] === 200, 'climate module served');
        return 52;
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
