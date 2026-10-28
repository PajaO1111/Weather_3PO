<?php
/**
 * Spouštěč testů bez externích závislostí:
 *   php tests/run.php
 *
 * Každý testovací soubor vrací funkci, která vrátí počet ověřených podmínek.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/support/Fixtures.php';

/** Jednoduchá aserce: při nesplnění vyhodí výjimku s popisem. */
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$files = [
    'Unit/MeteoTest.php',
    'Unit/AstroTest.php',
    'Unit/WindRoseTest.php',
    'Unit/QueryTest.php',
    'Unit/ViewTest.php',
    'Unit/DatabaseTest.php',
    'Unit/ObservationServiceTest.php',
    'Unit/HistoryQueryTest.php',
    'Unit/HistoryServiceTest.php',
    'Unit/StatsServiceTest.php',
    'Unit/AggregateCacheTest.php',
    'Unit/WeatherAggregateRepositoryTest.php',
    'Unit/AnalyticsServiceTest.php',
    'Unit/AirQualityServiceTest.php',
    'Unit/AirQualityHistoryServiceTest.php',
    'Unit/AirQualityForecastServiceTest.php',
    'Unit/ComparisonServiceTest.php',
    'Unit/ClimateServiceTest.php',
    'Unit/ForecastServiceTest.php',
    'Unit/WarningServiceTest.php',
    'Unit/RadarServiceTest.php',
    'Unit/FetchPrecisionTest.php',
    'Integration/CurrentConditionsTest.php',
    'Integration/HttpTest.php',
];

$passed = 0;
$failed = 0;
foreach ($files as $file) {
    try {
        $test = require __DIR__ . '/' . $file;
        $count = $test();
        $passed += $count;
        echo "PASS $file ($count checks)\n";
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL $file: " . $exception->getMessage() . "\n");
    }
}
echo "Passed checks: $passed; failed files: $failed\n";
exit($failed === 0 ? 0 : 1);
