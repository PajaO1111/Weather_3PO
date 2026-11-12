<?php
declare(strict_types=1);

/**
 * Shared by the standalone collectors deployed beside this file.
 * No secrets or network calls on include.
 */
final class WeatherIngest
{
    public const FALLBACK_SECONDS = 600;
    public const SLOT_SECONDS = 300;
    private const FUTURE_SECONDS = 120;
    private const COLUMNS = [
        'station_id', 'obs_time_utc', 'obs_time_local', 'neighborhood',
        'software_type', 'country', 'solar_radiation', 'lon', 'lat',
        'realtime_frequency', 'epoch', 'uv', 'winddir', 'humidity', 'qc_status',
        'temp_c', 'heat_index', 'dew_point', 'wind_chill', 'wind_speed',
        'wind_gust', 'pressure', 'precip_rate', 'precip_total', 'elevation',
    ];

    public static function connect(): PDO
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $stmt = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('pws_observations', 'weather_ingest_state', 'weather_ingest_slots')");
        $engines = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($engines) !== 3 || count(array_filter($engines, static function (string $engine): bool {
            return strcasecmp($engine, 'InnoDB') !== 0;
        })) !== 0) {
            throw new RuntimeException('ingest_tables_require_InnoDB');
        }
        return $pdo;
    }

    public static function authorized(array $input, string $id, string $key): bool
    {
        return $id !== '' && strlen($key) >= 32
            && isset($input['ID'], $input['PASSWORD'])
            && is_string($input['ID']) && is_string($input['PASSWORD'])
            && hash_equals($id, $input['ID']) && hash_equals($key, $input['PASSWORD']);
    }

    private static function number(array $input, string $key, float $min, float $max, bool $required = false): ?float
    {
        if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            if ($required) {
                throw new InvalidArgumentException('missing_' . $key);
            }
            return null;
        }
        $value = $input[$key];
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value)) {
            throw new InvalidArgumentException('invalid_' . $key);
        }
        $value = (float) $value;
        if (!is_finite($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('range_' . $key);
        }
        return $value;
    }

    private static function converted(?float $value, float $factor, float $offset = 0): ?float
    {
        return $value === null ? null : ($value + $offset) * $factor;
    }

    private static function time(string $value, string $format, int $now): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($time === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $time->format($format) !== $value) {
            throw new InvalidArgumentException('invalid_observation_time');
        }
        $age = $now - $time->getTimestamp();
        if ($age >= self::FALLBACK_SECONDS || $age < -self::FUTURE_SECONDS) {
            throw new InvalidArgumentException('stale_or_future_observation');
        }
        return $time;
    }

    private static function base(string $station, DateTimeImmutable $time): array
    {
        if ($station === '' || strlen($station) > 16) {
            throw new InvalidArgumentException('invalid_station');
        }
        $row = array_fill_keys(self::COLUMNS, null);
        $row['station_id'] = $station;
        $row['obs_time_utc'] = $time->format('Y-m-d H:i:s');
        $row['obs_time_local'] = $time->setTimezone(new DateTimeZone('Europe/Prague'))->format('Y-m-d H:i:s');
        $row['epoch'] = $time->getTimestamp();
        return $row;
    }

    /** Firmware 1.x custom server: Weather Underground upload protocol, imperial units. */
    public static function station(array $input, string $station, int $now): array
    {
        $date = $input['dateutc'] ?? null;
        if (!is_string($date)) {
            throw new InvalidArgumentException('missing_dateutc');
        }
        $time = self::time($date === 'now' ? gmdate('Y-m-d H:i:s', $now) : $date, 'Y-m-d H:i:s', $now);
        $row = self::base($station, $time);
        $row['temp_c'] = self::converted(self::number($input, 'tempf', -148, 176, true), 5 / 9, -32);
        $row['humidity'] = self::number($input, 'humidity', 0, 100, true);
        $row['pressure'] = self::converted(self::number($input, 'baromin', 8, 35, true), 33.8638866667);
        $row['dew_point'] = self::converted(self::number($input, 'dewptf', -148, 176), 5 / 9, -32);
        $row['wind_chill'] = self::converted(self::number($input, 'windchillf', -148, 176), 5 / 9, -32);
        $row['heat_index'] = self::converted(self::number($input, 'heatindexf', -148, 248), 5 / 9, -32);
        $row['wind_speed'] = self::converted(self::number($input, 'windspeedmph', 0, 300), 1.609344);
        $row['wind_gust'] = self::converted(self::number($input, 'windgustmph', 0, 300), 1.609344);
        $row['winddir'] = self::number($input, 'winddir', 0, 360);
        $row['precip_rate'] = self::converted(self::number($input, 'rainin', 0, 100), 25.4);
        $row['precip_total'] = self::converted(self::number($input, 'dailyrainin', 0, 100), 25.4);
        $row['solar_radiation'] = self::number($input, 'solarradiation', 0, 3000);
        $row['uv'] = self::number($input, 'UV', 0, 99);
        $software = $input['softwaretype'] ?? 'Sencor direct';
        if (!is_string($software) || strlen($software) > 64 || preg_match('/[\x00-\x1f]/', $software)) {
            throw new InvalidArgumentException('invalid_softwaretype');
        }
        $row['software_type'] = $software;
        // A direct upload has no Weather Underground QC assessment.
        $row['qc_status'] = null;
        return $row;
    }

    public static function underground(array $input, string $station, int $now): array
    {
        if (($input['stationID'] ?? null) !== $station || !is_string($input['obsTimeUtc'] ?? null)
            || !is_array($input['metric'] ?? null)) {
            throw new InvalidArgumentException('invalid_WU_observation');
        }
        $row = self::base($station, self::time($input['obsTimeUtc'], 'Y-m-d\TH:i:s\Z', $now));
        $metric = $input['metric'];
        $fields = [
            'temp_c' => ['temp', -100, 80, true],
            'heat_index' => ['heatIndex', -100, 120],
            'dew_point' => ['dewpt', -100, 80],
            'wind_chill' => ['windChill', -100, 80],
            'wind_speed' => ['windSpeed', 0, 500],
            'wind_gust' => ['windGust', 0, 500],
            'pressure' => ['pressure', 270, 1200, true],
            'precip_rate' => ['precipRate', 0, 2540],
            'precip_total' => ['precipTotal', 0, 2540],
            'elevation' => ['elev', -500, 10000],
        ];
        foreach ($fields as $column => $args) {
            $row[$column] = self::number($metric, ...$args);
        }
        foreach ([
            'humidity' => ['humidity', 0, 100, true],
            'winddir' => ['winddir', 0, 360],
            'uv' => ['uv', 0, 99],
            'solar_radiation' => ['solarRadiation', 0, 3000],
            'lon' => ['lon', -180, 180],
            'lat' => ['lat', -90, 90],
            'qc_status' => ['qcStatus', -1, 1],
            'realtime_frequency' => ['realtimeFrequency', 0, 86400],
        ] as $column => $args) {
            $row[$column] = self::number($input, ...$args);
        }
        foreach (['neighborhood' => 64, 'softwareType' => 64, 'country' => 2] as $field => $limit) {
            $value = $input[$field] ?? null;
            if ($value !== null && (!is_string($value) || preg_match('//u', $value) !== 1
                || preg_match_all('/./us', $value) > $limit)) {
                throw new InvalidArgumentException('invalid_WU_' . $field);
            }
            $column = $field === 'softwareType' ? 'software_type' : $field;
            $row[$column] = $value;
        }
        return $row;
    }

    public static function directFresh(?array $state, int $now): bool
    {
        if ($state === null || empty($state['last_direct_received_utc']) || empty($state['last_direct_obs_utc'])) {
            return false;
        }
        $received = strtotime($state['last_direct_received_utc'] . ' UTC');
        $observed = strtotime($state['last_direct_obs_utc'] . ' UTC');
        return $received !== false && $observed !== false
            && $received <= $now && $now - $received < self::FALLBACK_SECONDS
            && $now - $observed < self::FALLBACK_SECONDS && $observed <= $now + self::FUTURE_SECONDS;
    }

    public static function hasFreshDirect(PDO $pdo, string $station, int $now): bool
    {
        $stmt = $pdo->prepare('SELECT last_direct_received_utc, last_direct_obs_utc FROM weather_ingest_state WHERE station_id = ?');
        $stmt->execute([$station]);
        $state = $stmt->fetch(PDO::FETCH_ASSOC);
        return self::directFresh($state === false ? null : $state, $now);
    }

    public static function slot(int $epoch): string
    {
        return gmdate('Y-m-d H:i:s', intdiv($epoch, self::SLOT_SECONDS) * self::SLOT_SECONDS);
    }

    /** State row lock serializes both sources, including the first upload, across PHP workers. */
    public static function store(PDO $pdo, array $row, string $source, ?int $now = null): string
    {
        if (!in_array($source, ['direct', 'wu'], true)) {
            throw new InvalidArgumentException('invalid_source');
        }
        self::time($row['obs_time_utc'], 'Y-m-d H:i:s', $now ?? time());
        $station = $row['station_id'];
        $slot = self::slot((int) $row['epoch']);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO weather_ingest_state (station_id) VALUES (?) ON DUPLICATE KEY UPDATE station_id = VALUES(station_id)');
            $stmt->execute([$station]);
            $stmt = $pdo->prepare('SELECT * FROM weather_ingest_state WHERE station_id = ? FOR UPDATE');
            $stmt->execute([$station]);
            $state = $stmt->fetch(PDO::FETCH_ASSOC);
            $now = $now ?? time();
            self::time($row['obs_time_utc'], 'Y-m-d H:i:s', $now);
            if ($source === 'wu' && self::directFresh($state, $now)) {
                $pdo->commit();
                return 'DIRECT_FRESH';
            }
            $stmt = $pdo->prepare('SELECT observation_id, source FROM weather_ingest_slots WHERE station_id = ? AND slot_utc = ?');
            $stmt->execute([$station, $slot]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            $old = false;
            if ($existing !== false) {
                $stmt = $pdo->prepare('SELECT * FROM pws_observations WHERE id = ? AND station_id = ?');
                $stmt->execute([$existing['observation_id'], $station]);
                $old = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($old === false) {
                    throw new RuntimeException('missing_linked_observation');
                }
            } else {
                // Adopt an existing legacy WU row without rewriting historical duplicates.
                $stmt = $pdo->prepare('SELECT * FROM pws_observations WHERE station_id = ? AND obs_time_utc >= ? AND obs_time_utc < ? ORDER BY obs_time_utc DESC, id DESC LIMIT 1');
                $stmt->execute([$station, $slot, gmdate('Y-m-d H:i:s', strtotime($slot . ' UTC') + self::SLOT_SECONDS)]);
                $old = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            $previousSource = $existing === false ? 'wu' : $existing['source'];
            $replace = $old === false
                || ($source === 'direct' && $previousSource !== 'direct')
                || ($source === $previousSource && $row['obs_time_utc'] > $old['obs_time_utc']);
            if ($source === 'direct') {
                $stmt = $pdo->prepare('SELECT neighborhood, country, lon, lat, elevation FROM pws_observations WHERE station_id = ? ORDER BY obs_time_utc DESC, id DESC LIMIT 1');
                $stmt->execute([$station]);
                $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($metadata !== false) {
                    foreach ($metadata as $column => $value) {
                        $row[$column] = $value;
                    }
                }
            }
            if ($replace) {
                if ($old === false) {
                    $sql = 'INSERT INTO pws_observations (' . implode(', ', self::COLUMNS) . ') VALUES ('
                        . implode(', ', array_fill(0, count(self::COLUMNS), '?')) . ')';
                    $values = array_map(static function (string $column) use ($row) { return $row[$column]; }, self::COLUMNS);
                } else {
                    $sql = 'UPDATE pws_observations SET '
                        . implode(', ', array_map(static function (string $column): string { return $column . ' = ?'; }, self::COLUMNS))
                        . ' WHERE id = ?';
                    $values = array_map(static function (string $column) use ($row) { return $row[$column]; }, self::COLUMNS);
                    $values[] = $old['id'];
                }
                $pdo->prepare($sql)->execute($values);
            }
            $id = $old === false ? $pdo->lastInsertId() : $old['id'];
            if ($existing === false) {
                $stmt = $pdo->prepare('INSERT INTO weather_ingest_slots (station_id, slot_utc, observation_id, source) VALUES (?, ?, ?, ?)');
                $stmt->execute([$station, $slot, $id, $replace ? $source : $previousSource]);
            } elseif ($replace) {
                $stmt = $pdo->prepare('UPDATE weather_ingest_slots SET source = ? WHERE station_id = ? AND slot_utc = ?');
                $stmt->execute([$source, $station, $slot]);
            }
            if ($source === 'direct' && ($state['last_direct_obs_utc'] === null || $row['obs_time_utc'] >= $state['last_direct_obs_utc'])) {
                $stmt = $pdo->prepare('UPDATE weather_ingest_state SET last_direct_received_utc = ?, last_direct_obs_utc = ? WHERE station_id = ?');
                $stmt->execute([gmdate('Y-m-d H:i:s', $now), $row['obs_time_utc'], $station]);
            }
            $pdo->commit();
            return !$replace ? 'EXISTS' : ($old === false ? 'OK' : 'UPDATED');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

function weather_ingest_log(string $file, string $result, array $info = [], bool $echo = true): void
{
    $line = gmdate('Y-m-d H:i:s') . 'Z ' . $result;
    foreach ($info as $key => $value) {
        $line .= ' ' . $key . '=' . str_replace(["\r", "\n"], '', (string) $value);
    }
    if (is_file($file) && filesize($file) > 512000 && !rename($file, $file . '.1')) {
        error_log('weather_ingest: log rotation failed');
    }
    if (file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
        error_log('weather_ingest: log write failed');
    }
    if ($echo) {
        echo $line . "\n";
    }
}
