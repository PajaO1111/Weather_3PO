<?php
declare(strict_types=1);

use OuBrix\Weather\Infrastructure\Database;
use OuBrix\Weather\Weather\Services\PhenologyImportService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

$source = WEATHER_ROOT . '/data/fenology';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        echo "Použití: php bin/import-phenology.php [--source=C:\\cesta\\k\\fenologii]\n";
        echo "Výchozí zdroj: data/fenology (adresáře wood_species a herbs).\n";
        exit(0);
    }
    if (strncmp($argument, '--source=', 9) === 0 && substr($argument, 9) !== '') {
        $source = substr($argument, 9);
        continue;
    }
    fwrite(STDERR, "Neznámý argument. Použijte --help.\n");
    exit(2);
}

try {
    $stage = 'configuration';
    $pdo = Database::connection(WEATHER_ROOT, $stage);
    $result = (new PhenologyImportService($pdo))->importDirectory($source);
    echo sprintf(
        "Import fenologie dokončen: %d souborů, %d druhů, %d dvojic stanice–druh, %d kombinací pozorování, %d rozporných duplicit. UTC: %s\n",
        $result['files'],
        $result['species'],
        $result['stations'],
        $result['observations'],
        $result['conflicts'],
        $result['importedAt']
    );
} catch (\PDOException $exception) {
    $details = Database::failureDetails($exception, isset($stage) ? $stage : 'connection');
    fwrite(STDERR, 'Import fenologie selhal při práci s databází (' . json_encode($details) . ").\n");
    exit(1);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Import fenologie selhal: ' . $exception->getMessage() . "\n");
    exit(1);
}
