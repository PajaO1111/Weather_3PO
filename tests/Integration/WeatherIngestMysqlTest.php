<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/weather-ingest.php';

return static function (): int {
    $dsn = getenv('WEATHER_INGEST_MYSQL_DSN');
    if (!$dsn) {
        echo "SKIP WeatherIngestMysqlTest: set WEATHER_INGEST_MYSQL_DSN for a disposable MySQL server\n";
        return 0;
    }
    // Only the randomly named database created here is ever dropped.
    $database = 'weather_ingest_test_' . bin2hex(random_bytes(6));
    $user = (string) getenv('WEATHER_INGEST_MYSQL_USER');
    $password = (string) getenv('WEATHER_INGEST_MYSQL_PASS');
    $admin = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
    $testDsn = $dsn . ';dbname=' . $database;
    $pdo = new PDO($testDsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $checks = 0;
    $assert = static function (bool $ok, string $message) use (&$checks): void {
        check($ok, $message);
        $checks++;
    };
    try {
        $dump = file_get_contents(dirname(__DIR__, 2) . '/pws_observations.sql');
        if (!preg_match('/CREATE TABLE IF NOT EXISTS `pws_observations`.*?;/s', $dump, $schema)) {
            throw new RuntimeException('Observation schema missing');
        }
        $pdo->exec($schema[0]);
        $pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/004_weather_ingest.sql'));
        // Applying the migration again must be harmless.
        $pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/004_weather_ingest.sql'));
        $now = strtotime('2026-11-09 12:00:00 UTC');
        $make = static function (string $station, int $epoch) use ($now): array {
            return WeatherIngest::station([
                'dateutc' => gmdate('Y-m-d H:i:s', $epoch), 'tempf' => '46.22',
                'humidity' => '85', 'baromin' => '29.92', 'dailyrainin' => '0.5',
            ], $station, max($epoch, $now));
        };
        $wu = $make('TEST', $now);
        $wu['neighborhood'] = 'Most';
        $wu['lat'] = 50.5;
        $wu['lon'] = 13.6;
        $wu['elevation'] = 254;
        $assert(WeatherIngest::store($pdo, $wu, 'wu', $now) === 'OK', 'WU used before first direct upload');
        $id = $pdo->query("SELECT id FROM pws_observations WHERE station_id = 'TEST'")->fetchColumn();
        $direct = $make('TEST', $now + 10);
        $direct['temp_c'] = 8.5;
        $assert(WeatherIngest::store($pdo, $direct, 'direct', $now + 10) === 'UPDATED', 'direct replaces WU with shifted timestamp');
        $stored = $pdo->query("SELECT * FROM pws_observations WHERE station_id = 'TEST'")->fetch(PDO::FETCH_ASSOC);
        $assert($stored['id'] == $id && abs((float) $stored['temp_c'] - 8.5) < 0.001, 'replacement preserves row ID and changes values');
        $assert($stored['neighborhood'] === 'Most' && (float) $stored['elevation'] === 254.0, 'direct inherits station metadata');
        $assert(WeatherIngest::store($pdo, $direct, 'direct', $now + 11) === 'EXISTS', 'exact retries do not duplicate');
        $later = $make('TEST', $now + 40);
        $assert(WeatherIngest::store($pdo, $later, 'direct', $now + 40) === 'UPDATED', 'latest direct sample retained in slot');
        $assert(WeatherIngest::store($pdo, $direct, 'direct', $now + 41) === 'EXISTS', 'out of order direct ignored');
        $assert(WeatherIngest::hasFreshDirect($pdo, 'TEST', $now + 639), '599 seconds is fresh');
        $assert(!WeatherIngest::hasFreshDirect($pdo, 'TEST', $now + 640), '600 seconds enables fallback');
        $assert(WeatherIngest::store($pdo, $make('TEST', $now + 500), 'wu', $now + 500) === 'DIRECT_FRESH', 'fresh primary blocks WU in another slot');
        $assert(WeatherIngest::store($pdo, $make('TEST', $now + 640), 'wu', $now + 640) === 'OK', 'fallback allowed at exact threshold');
        $assert(WeatherIngest::store($pdo, $make('TEST', $now + 650), 'direct', $now + 650) === 'UPDATED', 'recovery replaces backup slot');
        $assert(WeatherIngest::store($pdo, $make('TEST', $now + 660), 'wu', $now + 660) === 'DIRECT_FRESH', 'WU loses again after recovery');
        $assert((int) $pdo->query("SELECT COUNT(*) FROM pws_observations WHERE station_id = 'TEST'")->fetchColumn() === 2, 'two five minute slots, not two sources');
        $assert($pdo->query("SELECT DISTINCT source FROM weather_ingest_slots WHERE station_id = 'TEST'")->fetchColumn() === 'direct', 'source trace retained');
        $service = new \OuBrix\Weather\Weather\Services\CurrentConditionsService(
            new \OuBrix\Weather\Weather\Repositories\ObservationRepository($pdo), weather_settings()
        );
        $current = $service->build('TEST', new DateTimeImmutable('@' . ($now + 700)));
        $assert($current['station']['id'] === 'TEST' && $current['current']['temp_c'] === 7.9, 'existing current API reads direct observations');
        $assert($current['today']['rainCounter'] === 12.7 && $current['observed']['qcStatus'] === null, 'existing API preserves rain counter and null QC');
        $midnight = strtotime('2026-11-09 23:00:00 UTC');
        $rain = $make('RAIN', $midnight - 1);
        $rain['precip_total'] = 12.7;
        WeatherIngest::store($pdo, $rain, 'direct', $midnight - 1);
        $rain = $make('RAIN', $midnight);
        $rain['precip_total'] = 0;
        WeatherIngest::store($pdo, $rain, 'direct', $midnight);
        $rainRows = $pdo->query("SELECT obs_time_local, precip_total FROM pws_observations WHERE station_id = 'RAIN' ORDER BY obs_time_utc")->fetchAll(PDO::FETCH_ASSOC);
        $assert(count($rainRows) === 2 && $rainRows[1]['obs_time_local'] === '2026-11-10 00:00:00'
            && (float) $rainRows[1]['precip_total'] === 0.0, 'local midnight reset kept in separate slots');

        $legacy = $make('LEGACY', $now);
        $columns = array_keys($legacy);
        $stmt = $pdo->prepare('INSERT INTO pws_observations (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $stmt->execute(array_values($legacy));
        $assert(WeatherIngest::store($pdo, $make('LEGACY', $now + 20), 'direct', $now + 20) === 'UPDATED', 'adopts legacy row');
        $assert((int) $pdo->query("SELECT COUNT(*) FROM pws_observations WHERE station_id = 'LEGACY'")->fetchColumn() === 1, 'adoption does not duplicate');

        $pdo->exec("CREATE TRIGGER ingest_test_fail BEFORE INSERT ON pws_observations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test rollback'");
        try {
            WeatherIngest::store($pdo, $make('FAIL', $now), 'direct', $now);
            $assert(false, 'DB failure must propagate');
        } catch (PDOException $e) {
            $assert(!$pdo->inTransaction(), 'failed transaction rolled back');
        } finally {
            $pdo->exec('DROP TRIGGER ingest_test_fail');
        }
        $assert((int) $pdo->query("SELECT COUNT(*) FROM weather_ingest_state WHERE station_id = 'FAIL'")->fetchColumn() === 0, 'failed insert cannot publish fresh heartbeat');
        $assert((int) $pdo->query("SELECT COUNT(*) FROM weather_ingest_slots WHERE station_id = 'FAIL'")->fetchColumn() === 0, 'failed insert cannot reserve slot');

        $workers = [];
        try {
            for ($i = 0; $i < 8; $i++) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/support/ingest-worker.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException('Cannot start ingestion worker');
                }
                fwrite($pipes[0], json_encode([
                    'dsn' => $testDsn, 'row' => $make('RACE', $now + $i),
                    'source' => $i % 2 === 0 ? 'direct' : 'wu', 'now' => $now + 10,
                ], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                $workers[] = [$process, $pipes[1], $pipes[2]];
            }
            foreach ($workers as [$process, $stdout, $stderr]) {
                $output = stream_get_contents($stdout);
                $error = stream_get_contents($stderr);
                fclose($stdout);
                fclose($stderr);
                $code = proc_close($process);
                $assert($code === 0 && $error === '' && in_array($output, ['OK', 'UPDATED', 'EXISTS', 'DIRECT_FRESH'], true), 'concurrent worker succeeds');
            }
        } finally {
            foreach ($workers as [$process, $stdout, $stderr]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
                foreach ([$stdout, $stderr] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
        }
        $assert((int) $pdo->query("SELECT COUNT(*) FROM pws_observations WHERE station_id = 'RACE'")->fetchColumn() === 1, 'parallel workers create one row');
        $assert((int) $pdo->query("SELECT COUNT(*) FROM weather_ingest_slots WHERE station_id = 'RACE'")->fetchColumn() === 1, 'unique slot during simultaneous first writes');
        $assert($pdo->query("SELECT source FROM weather_ingest_slots WHERE station_id = 'RACE'")->fetchColumn() === 'direct', 'direct wins regardless of arrival order');
        $assert($pdo->query("SELECT obs_time_utc FROM pws_observations WHERE station_id = 'RACE'")->fetchColumn() === gmdate('Y-m-d H:i:s', $now + 6), 'newest direct sample wins race');
        $httpTest = require dirname(__DIR__) . '/support/ingest-http.php';
        $checks += $httpTest($pdo, $testDsn, $user, $password);
    } finally {
        $pdo = null;
        $admin->exec('DROP DATABASE `' . $database . '`');
    }
    return $checks;
};
