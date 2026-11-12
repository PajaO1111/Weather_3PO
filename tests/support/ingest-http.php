<?php
declare(strict_types=1);

return static function (PDO $pdo, string $dsn, string $user, string $password): int {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oubrix-ingest-http-' . bin2hex(random_bytes(6));
    $web = $root . DIRECTORY_SEPARATOR . 'web';
    if (!mkdir($web, 0700, true)) {
        throw new RuntimeException('Cannot create HTTP fixture');
    }
    $process = null;
    $checks = 0;
    $assert = static function (bool $ok, string $message) use (&$checks): void {
        check($ok, $message);
        $checks++;
    };
    try {
        foreach (['station-upload.php', 'fetch_weather2.php', 'weather-ingest.php'] as $file) {
            if (!copy(dirname(__DIR__, 2) . '/' . $file, $web . DIRECTORY_SEPARATOR . $file)) {
                throw new RuntimeException('Cannot copy HTTP fixture');
            }
        }
        $parts = [];
        foreach (explode(';', substr($dsn, strlen('mysql:'))) as $item) {
            $pair = explode('=', $item, 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }
        $key = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(16));
        $config = "<?php\n";
        foreach ([
            'DB_HOST' => $parts['host'], 'DB_PORT' => (int) ($parts['port'] ?? 3306),
            'DB_NAME' => $parts['dbname'], 'DB_USER' => $user, 'DB_PASS' => $password,
            'WU_STATION_ID' => 'HTTPTEST', 'STATION_UPLOAD_ID' => 'UPLOADTEST',
            'STATION_UPLOAD_KEY' => $key, 'FETCH_TOKEN' => $token,
            'WU_API_KEY' => 'unused-no-outbound-request-when-direct-is-fresh',
        ] as $constant => $value) {
            $config .= 'define(' . var_export($constant, true) . ', ' . var_export($value, true) . ");\n";
        }
        file_put_contents($root . DIRECTORY_SEPARATOR . 'config.php', $config);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException('Cannot allocate HTTP test port');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $process = proc_open([PHP_BINARY, '-S', $address, '-t', $web], [
            0 => ['pipe', 'r'],
            1 => ['file', $root . DIRECTORY_SEPARATOR . 'server.log', 'a'],
            2 => ['file', $root . DIRECTORY_SEPARATOR . 'server.log', 'a'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start HTTP test server');
        }
        fclose($pipes[0]);
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(100000);
        }
        $assert($ready, 'HTTP fixture responsive');
        $request = static function (string $path, array $data = [], string $method = 'GET') use ($address): array {
            $url = 'http://' . $address . '/' . $path;
            $query = http_build_query($data);
            if ($method === 'GET') {
                $url .= '?' . $query;
            }
            $context = stream_context_create(['http' => [
                'method' => $method, 'ignore_errors' => true, 'timeout' => 5,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $method === 'POST' ? $query : '',
            ]]);
            $body = file_get_contents($url, false, $context);
            $headers = $http_response_header ?? [];
            preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $match);
            return [(int) ($match[1] ?? 0), $body, implode("\n", $headers)];
        };
        [$status, $body] = $request('station-upload.php');
        $assert($status === 403 && $body === "invalid credentials\n", 'unauthenticated station upload denied');
        [$status] = $request('station-upload.php', [], 'DELETE');
        $assert($status === 405, 'unsupported upload method rejected');
        $input = ['ID' => 'UPLOADTEST', 'PASSWORD' => $key, 'dateutc' => 'now',
            'tempf' => '46.22', 'humidity' => '85', 'baromin' => '29.92', 'dailyrainin' => '0.5'];
        [$status, $body] = $request('station-upload.php', array_merge($input, ['tempf' => 'bad']));
        $assert($status === 400 && $body === "invalid observation\n", 'invalid numeric upload explicitly rejected');
        [$status, $body] = $request('station-upload.php', array_merge($input, ['dateutc' => gmdate('Y-m-d H:i:s', time() - 700)]));
        $assert($status === 400, 'stale uploads cannot refresh heartbeat');
        $assert(!WeatherIngest::hasFreshDirect($pdo, 'HTTPTEST', time()), 'invalid requests never create primary heartbeat');
        $input['dateutc'] = gmdate('Y-m-d H:i:s');
        [$status, $body, $headers] = $request('station-upload.php', $input);
        $assert($status === 200 && $body === "success\n", 'GET WU protocol upload acknowledged');
        $assert(strpos($headers, 'no-store') !== false, 'uploads cannot be cached');
        $assert(WeatherIngest::hasFreshDirect($pdo, 'HTTPTEST', time()), 'real upload publishes heartbeat');
        [$status, $body] = $request('station-upload.php', $input, 'POST');
        $assert($status === 200 && $body === "success\n", 'form POST supported');
        $assert((int) $pdo->query("SELECT COUNT(*) FROM pws_observations WHERE station_id = 'HTTPTEST'")->fetchColumn() === 1, 'HTTP retries do not duplicate');
        $row = $pdo->query("SELECT * FROM pws_observations WHERE station_id = 'HTTPTEST'")->fetch(PDO::FETCH_ASSOC);
        $assert(abs((float) $row['temp_c'] - 7.9) < 0.001 && $row['station_id'] === 'HTTPTEST', 'wire format converted under canonical DB station');
        [$status, $body] = $request('fetch_weather2.php', ['token' => $token]);
        $assert($status === 200 && strpos($body, 'SKIP reason=direct_fresh') !== false, 'real CRON skips WU network request when direct is fresh');
        [$status, $body] = $request('fetch_weather2.php', ['token' => 'wrong']);
        $assert($status === 403 && strpos($body, 'DENIED') !== false, 'CRON token protection preserved');
        $logs = file_get_contents($root . DIRECTORY_SEPARATOR . 'station-upload.log')
            . file_get_contents($root . DIRECTORY_SEPARATOR . 'fetch_weather2.log');
        $assert(strpos($logs, $key) === false && strpos($logs, $token) === false, 'collector logs contain no keys or tokens');
        $pdo->exec('DROP TABLE weather_ingest_slots');
        [$status, $body] = $request('station-upload.php', $input);
        $assert($status === 503 && $body === "temporarily unavailable\n", 'DB failure never acknowledged as success');
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach (['station-upload.php', 'fetch_weather2.php', 'weather-ingest.php'] as $file) {
            $path = $web . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        foreach (['config.php', 'server.log', 'station-upload.log', 'fetch_weather2.log'] as $file) {
            $path = $root . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($web);
        rmdir($root);
    }
    return $checks;
};
