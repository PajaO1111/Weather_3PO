<?php
// fetch_weather.php
require_once __DIR__ . '/../config.php';

try {
    // Připojení k DB
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Stáhnout JSON
    $url = sprintf(
        'https://api.weather.com/v2/pws/observations/current' .
        '?stationId=%s&format=json&units=m&apiKey=%s',
        WU_STATION_ID,
        WU_API_KEY
    );
    $json = file_get_contents($url);
    if ($json === false) {
        throw new Exception('Chyba při stahování dat.');
    }
    $d = json_decode($json, true)['observations'][0];

    // Vložíme všechna data
    $stmt = $pdo->prepare("
      INSERT INTO pws_observations (
        station_id, obs_time_utc, obs_time_local, neighborhood,
        software_type, country, solar_radiation, lon, lat,
        realtime_frequency, epoch, uv, winddir, humidity, qc_status,
        temp_c, heat_index, dew_point, wind_chill,
        wind_speed, wind_gust, pressure, precip_rate, precip_total, elevation
      ) VALUES (
        :station, :utc, :local, :neigh,
        :soft, :country, :solar, :lon, :lat,
        :freq, :epoch, :uv, :winddir, :hum, :qc,
        :temp, :heatIndex, :dewpt, :windChill,
        :windSpeed, :windGust, :pressure, :precipRate, :precipTotal, :elev
      )
    ");

    $stmt->execute([
        ':station' => $d['stationID'],
        ':utc' => $d['obsTimeUtc'],
        ':local' => $d['obsTimeLocal'],
        ':neigh' => $d['neighborhood'],
        ':soft' => $d['softwareType'],
        ':country' => $d['country'],
        ':solar' => $d['solarRadiation'],
        ':lon' => $d['lon'],
        ':lat' => $d['lat'],
        ':freq' => $d['realtimeFrequency'],
        ':epoch' => $d['epoch'],
        ':uv' => $d['uv'],
        ':winddir' => $d['winddir'],
        ':hum' => $d['humidity'],
        ':qc' => $d['qcStatus'],
        ':temp' => $d['metric']['temp'],
        ':heatIndex' => $d['metric']['heatIndex'],
        ':dewpt' => $d['metric']['dewpt'],
        ':windChill' => $d['metric']['windChill'],
        ':windSpeed' => $d['metric']['windSpeed'],
        ':windGust' => $d['metric']['windGust'],
        ':pressure' => $d['metric']['pressure'],
        ':precipRate' => $d['metric']['precipRate'],
        ':precipTotal' => $d['metric']['precipTotal'],
        ':elev' => $d['metric']['elev']
    ]);

    echo "OK: Záznam přidán.\n";
} catch (Exception $e) {
    error_log($e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
}