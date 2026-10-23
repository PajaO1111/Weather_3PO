<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/Fixtures.php';

use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\ObservationService;

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    fwrite(STDERR, "Usage: php tests\\check-dump.php selected-rows.json\n");
    exit(1);
}
try {
    $rows = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $pdo = Fixtures::database();
    $columns = array_keys(Fixtures::row(1, '2000-01-01 00:00:00'));
    foreach ($rows as $row) {
        Fixtures::insert($pdo, array_intersect_key($row, array_flip($columns)));
    }
    $station = (string) $rows[0]['station_id'];
    $repository = new ObservationRepository($pdo);
    $selected = $repository->recent($station, 500);
    $payload = (new ObservationService($repository))->present($selected, $station, ['requestedRows' => 500]);
    $nullInput = count(array_filter($rows, static fn (array $row): bool => $row['temp_c'] === null));
    $nullOutput = count(array_filter($payload['items'], static fn (array $row): bool => $row['temp_c'] === null));
    if (count($selected) !== count($rows) || $nullInput !== $nullOutput || $payload['meta']['duplicates'] < 1) {
        throw new RuntimeException('Dump round-trip assertion failed.');
    }
    foreach ($payload['items'] as $index => $item) {
        if ($index > 0 && $item['time'] <= $payload['items'][$index - 1]['time']) {
            throw new RuntimeException('Non-monotonic output time.');
        }
    }
    echo json_encode([
        'result' => 'PASS', 'scope' => 'real dump sample persisted into isolated SQLite, not live MariaDB',
        'sourceRows' => count($rows), 'outputPoints' => count($payload['items']),
        'preservedNullTemperature' => $nullOutput,
        'duplicatesInWindow' => $payload['meta']['duplicates'],
        'conflictingTimes' => $payload['meta']['conflicts'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Dump check failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
