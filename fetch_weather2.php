<?php
/**
 * fetch_weather2.php – CRON: stáhne aktuální měření stanice z Weather Underground
 * a uloží ho do tabulky pws_observations (jen pokud tam ještě není).
 *
 * Oproti původní verzi:
 *  - každé spuštění zapíše řádek do logu MIMO web (/www/domains/fetch_weather2.log):
 *    čas spuštění, HTTP kód WU, čas měření z WU a výsledek → je vidět, zda CRON
 *    opravdu běží každých 5 min a zda WU vrací nová data,
 *  - časový limit stahování (dříve mohl skript viset), kontrola HTTP kódu
 *    (WU vrací 204 bez dat, když stanice delší dobu nic neposlala),
 *  - zámek proti souběžnému spuštění (dva běhy naráz vytvářely duplicitní řádky),
 *  - kontrola duplicity i podle stanice a s časem převedeným na formát DATETIME,
 *  - hlavičky „no-store“, aby CDN WEDOS volání CRONu necachovala,
 *  - ochrana tajným tokenem: přes web se skript spustí jen s parametrem
 *    ?token=… shodným s konstantou FETCH_TOKEN v config.php (mimo web).
 *    Bez tokenu nebo se špatným tokenem vrátí 403 a nic nestahuje ani nezapisuje.
 *    Z příkazové řádky (CLI) token není potřeba.
 * Struktura tabulky ani vkládaná data se NEMĚNÍ.
 *
 * URL pro CRON: https://oubrix.eu/fetch_weather2.php?token=<FETCH_TOKEN>
 */
// CRON na WEDOS volá skript přes URL, tedy přes CDN WEDOS. Bez těchto hlaviček
// CDN odpověď hodinu cachuje a skript se při dalších voláních vůbec nespustí
// (proto se ukládalo jen ~1–2 měření za hodinu místo každých 5 min).
if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../config.php';

// Chyby jen do logu, ne na výstup (původní config.php zapíná display_errors).
ini_set('display_errors', '0');

const FETCH_LOG = __DIR__ . '/../fetch_weather2.log';
const FETCH_LOG_MAX_BYTES = 512000;   // pak se starý log přejmenuje na .1
const FETCH_TIMEOUT_SECONDS = 20;

/** Zapíše jeden řádek do logu a vypíše ho (výstup CRONu). */
function fetch_log($result, array $info = [])
{
    $line = date('Y-m-d H:i:s') . ' ' . $result;
    foreach ($info as $key => $value) {
        $line .= ' ' . $key . '=' . ($value === null ? '-' : $value);
    }
    if (is_file(FETCH_LOG) && filesize(FETCH_LOG) > FETCH_LOG_MAX_BYTES) {
        @rename(FETCH_LOG, FETCH_LOG . '.1');
    }
    @file_put_contents(FETCH_LOG, $line . "\n", FILE_APPEND | LOCK_EX);
    echo $line . "\n";
}

// Ochrana tokenem – jen pro volání přes web. Chybí-li FETCH_TOKEN v config.php,
// skript se z webu nespustí vůbec (bezpečnější než běžet bez ochrany).
if (PHP_SAPI !== 'cli') {
    $expected = defined('FETCH_TOKEN') ? (string) FETCH_TOKEN : '';
    $given = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
    if (strlen($expected) < 32 || !hash_equals($expected, $given)) {
        http_response_code(403);
        // Token se do logu nikdy nezapisuje, jen důvod odmítnutí.
        $reason = strlen($expected) < 32 ? 'FETCH_TOKEN_chybi_v_configu' : ($given === '' ? 'bez_tokenu' : 'spatny_token');
        fetch_log('DENIED', ['reason' => $reason]);
        exit;
    }
}

$started = microtime(true);

// Zámek: pokud předchozí běh ještě neskončil, tento se ukončí.
$lock = fopen(sys_get_temp_dir() . '/fetch_weather2_' . md5(__FILE__) . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fetch_log('SKIP', ['reason' => 'predchozi_beh_stale_bezi']);
    exit;
}

try {
    // Bez numericPrecision=decimal vrací WU teploty zaokrouhlené na celá čísla.
    // 1) Stažení aktuálního měření z WU (s časovým limitem)
    $url = sprintf(
        'https://api.weather.com/v2/pws/observations/current?stationId=%s&format=json&units=m&numericPrecision=decimal&apiKey=%s',
        rawurlencode(WU_STATION_ID),
        rawurlencode(WU_API_KEY)
    );
    $context = stream_context_create(['http' => [
        'timeout' => FETCH_TIMEOUT_SECONDS,
        'ignore_errors' => true,          // chceme přečíst i kód 204/4xx
        'header' => "Accept: application/json\r\nAccept-Encoding: identity\r\n",
    ]]);
    $json = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s?/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    if ($json === false) {
        fetch_log('ERROR', ['reason' => 'stazeni_selhalo', 'http' => $status]);
        exit;
    }
    if ($status === 204 || trim($json) === '') {
        fetch_log('NODATA', ['reason' => 'WU_nema_aktualni_mereni', 'http' => $status]);
        exit;
    }
    if ($status !== 200) {
        fetch_log('ERROR', ['reason' => 'WU_http', 'http' => $status]);
        exit;
    }
    $payload = json_decode($json, true);
    $d = isset($payload['observations'][0]) ? $payload['observations'][0] : null;
    if (!is_array($d) || empty($d['obsTimeUtc']) || !isset($d['metric']) || !is_array($d['metric'])) {
        fetch_log('ERROR', ['reason' => 'neplatna_odpoved_WU', 'http' => $status]);
        exit;
    }

    // WU vrací „2026-10-23T14:35:04Z“ → DATETIME „2026-10-23 14:35:04“ (UTC).
    $obsTime = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $d['obsTimeUtc'], new DateTimeZone('UTC'));
    if ($obsTime === false) {
        fetch_log('ERROR', ['reason' => 'neplatny_cas', 'obs' => $d['obsTimeUtc']]);
        exit;
    }
    $obsUtc = $obsTime->format('Y-m-d H:i:s');
    $ageMinutes = (int) round((time() - $obsTime->getTimestamp()) / 60);

    // 2) Připojení k DB (stejně jako dříve)
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 3) Už uloženo? (stanice + čas)
    $check = $pdo->prepare('SELECT COUNT(*) FROM pws_observations WHERE station_id = ? AND obs_time_utc = ?');
    $check->execute([$d['stationID'], $obsUtc]);
    if ((int) $check->fetchColumn() > 0) {
        fetch_log('EXISTS', ['obs' => $obsUtc, 'age_min' => $ageMinutes]);
        exit;
    }

    // 4) Vložení – stejné sloupce a hodnoty jako původní skript
    $metric = $d['metric'];
    $get = function (array $source, $key) {
        return array_key_exists($key, $source) ? $source[$key] : null;
    };
    $stmt = $pdo->prepare('
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
    ');
    $stmt->execute([
        ':station' => $get($d, 'stationID'),
        ':utc' => $obsUtc,
        ':local' => $get($d, 'obsTimeLocal'),
        ':neigh' => $get($d, 'neighborhood'),
        ':soft' => $get($d, 'softwareType'),
        ':country' => $get($d, 'country'),
        ':solar' => $get($d, 'solarRadiation'),
        ':lon' => $get($d, 'lon'),
        ':lat' => $get($d, 'lat'),
        ':freq' => $get($d, 'realtimeFrequency'),
        ':epoch' => $get($d, 'epoch'),
        ':uv' => $get($d, 'uv'),
        ':winddir' => $get($d, 'winddir'),
        ':hum' => $get($d, 'humidity'),
        ':qc' => $get($d, 'qcStatus'),
        ':temp' => $get($metric, 'temp'),
        ':heatIndex' => $get($metric, 'heatIndex'),
        ':dewpt' => $get($metric, 'dewpt'),
        ':windChill' => $get($metric, 'windChill'),
        ':windSpeed' => $get($metric, 'windSpeed'),
        ':windGust' => $get($metric, 'windGust'),
        ':pressure' => $get($metric, 'pressure'),
        ':precipRate' => $get($metric, 'precipRate'),
        ':precipTotal' => $get($metric, 'precipTotal'),
        ':elev' => $get($metric, 'elev'),
    ]);

    fetch_log('OK', ['obs' => $obsUtc, 'age_min' => $ageMinutes, 'ms' => (int) ((microtime(true) - $started) * 1000)]);
} catch (Throwable $e) {
    // Text výjimky jde jen do error logu serveru (může obsahovat údaje o DB).
    error_log('fetch_weather2: ' . $e->getMessage());
    $code = $e instanceof PDOException ? $e->getCode() : get_class($e);
    fetch_log('ERROR', ['reason' => 'vyjimka', 'code' => $code]);
}
