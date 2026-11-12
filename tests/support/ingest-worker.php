<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/weather-ingest.php';

try {
    $job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $pdo = new PDO($job['dsn'], (string) getenv('WEATHER_INGEST_MYSQL_USER'), (string) getenv('WEATHER_INGEST_MYSQL_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    echo WeatherIngest::store($pdo, $job['row'], $job['source'], $job['now']);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ' code=' . $e->getCode());
    exit(1);
}
