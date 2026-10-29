<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Services\PhenologyImportService;

require_once dirname(__DIR__) . '/support/PhenologyFixtures.php';

return static function (): int {
    $data = "\"Stanice\",\"Rok\",\"Plodina\",\"Fenofáze\",\"Julian\"\n"
        . "\"U1DOKS31\",\"2024\",\"Bříza, bělokorá\",\"PK50\",\"60\"\n"
        . "\"U1DOKS31\",\"2023\",\"Bříza, bělokorá\",\"PK50\",\"444\"\n"
        . "\"U1DOKS31\",\"2024\",\"Bříza, bělokorá\",\"BT\",\"366\"\n";
    $parsed = PhenologyImportService::parseObservationCsv($data);
    check($parsed['speciesName'] === 'Bříza, bělokorá', 'quoted Czech species name');
    check($parsed['rows'][1]['status'] === 'not_occurred' && $parsed['rows'][1]['dayOfYear'] === null,
        '444 is a state, never a day-of-year');
    check($parsed['rows'][2]['dayOfYear'] === 366, 'leap-year day 366 is valid');
    $variantHeader = "\"Stanice\",\"Rok\",\"Svída krvavá\",\"Fenofáze\",\"Julian\"\n"
        . "\"U1DOKS31\",\"2024\",\"Svída krvavá\",\"PK50\",\"60\"\n";
    check(PhenologyImportService::parseObservationCsv($variantHeader)['speciesName'] === 'Svída krvavá',
        'accept published species-name header variant');

    $phases = PhenologyImportService::parsePhaseCsv(
        "\"Zkratka fenofáze\",\"Název fenofáze\"\n\"PK50\",\"Počátek kvetení, polovina\"\n\"BT\",\"Butonizace\"\n"
    );
    check($phases['PK50'] === 'Počátek kvetení, polovina', 'quoted phase label');
    $stationCsv = "\"Název stanice\",\"Indikativ stanice\",\"Nadmořská výška (m)\",\"z.š.\",\"z.d.\",\"Období\",\"Fenofáze (bříza, bělokorá)\"\n"
        . "\"Doksany, stanice\",\"U1DOKS31\",\"155\",\"50.46\",\"14.17\",\"2014-2016, 2019-2025\",\"PK50, BT\"\n";
    $parsedStations = PhenologyImportService::parseStationCsv($stationCsv, $phases);
    check($parsedStations['speciesName'] === 'bříza, bělokorá'
        && $parsedStations['stations']['U1DOKS31']['name'] === 'Doksany, stanice'
        && $parsedStations['stations']['U1DOKS31']['declaredPeriods'] === '2014-2016, 2019-2025',
        'quoted station metadata and disjoint periods');

    $badYear = "\"Stanice\",\"Rok\",\"Plodina\",\"Fenofáze\",\"Julian\"\n\"U1DOKS31\",\"2023\",\"Bříza\",\"PK50\",\"366\"\n";
    $rejectedDay = false;
    try {
        PhenologyImportService::parseObservationCsv($badYear);
    } catch (RuntimeException $exception) {
        $rejectedDay = true;
    }
    check($rejectedDay, 'reject day 366 in non-leap year');

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phenology-import-' . bin2hex(random_bytes(6));
    foreach (['wood_species', 'herbs'] as $category) {
        mkdir($root . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . 'data', 0777, true);
        mkdir($root . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . 'metadata', 0777, true);
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . 'metadata' . DIRECTORY_SEPARATOR . 'Seznam_fenofaze.csv',
            "\"Zkratka fenofáze\",\"Název fenofáze\"\n\"PK50\",\"Počátek kvetení 50%\"\n\"BT\",\"Butonizace\"\n"
        );
    }
    $files = [
        'wood_species' => ['Test_species', 'Bříza testovací', 'Bříza testovací', 'PK50', '100'],
        'herbs' => ['Test_herb', 'Bylina testovací', 'Bylina testovací', 'PK50', '444'],
    ];
    foreach ($files as $category => $definition) {
        [$code, $name, $crop, $phase, $day] = $definition;
        $dataPath = $root . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $code . '.csv';
        $duplicate = $category === 'wood_species'
            ? "\"U1DOKS31\",\"2024\",\"$crop\",\"$phase\",\"444\"\n"
            : '';
        file_put_contents($dataPath, "\"Stanice\",\"Rok\",\"Plodina\",\"Fenofáze\",\"Julian\"\n\"U1DOKS31\",\"2024\",\"$crop\",\"$phase\",\"$day\"\n$duplicate");
        $stationPath = $root . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . 'metadata' . DIRECTORY_SEPARATOR . $code . '_stanice.csv';
        file_put_contents(
            $stationPath,
            "\"Název stanice\",\"Indikativ stanice\",\"Nadmořská výška (m)\",\"z.š.\",\"z.d.\",\"Období\",\"Fenofáze ($name)\"\n"
            . "\"Doksany\",\"U1DOKS31\",\"155\",\"50.46\",\"14.17\",\"2014-2025\",\"PK50\"\n"
        );
    }

    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    PhenologyFixtures::createSchema($pdo);
    $service = new PhenologyImportService($pdo);
    try {
        $first = $service->importDirectory($root);
        $second = $service->importDirectory($root);
        check($first['species'] === 2 && $first['observations'] === 2 && $first['conflicts'] === 1 && $first['files'] === 6,
            'import source data, both categories and provenance');
        check($second['observations'] === 2
            && (int) $pdo->query('SELECT COUNT(*) FROM phenology_observations')->fetchColumn() === 2,
            'repeat import is idempotent');
        check((int) $pdo->query("SELECT COUNT(*) FROM phenology_observations WHERE status = 'not_occurred' AND day_of_year IS NULL")->fetchColumn() === 1,
            'persist 444 separately from observed date');
        check((int) $pdo->query("SELECT COUNT(*) FROM phenology_observations WHERE status = 'conflict' AND source_values = '100,444'")->fetchColumn() === 1,
            'preserve conflicting duplicate source rows without choosing one');
    } finally {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($root);
    }

    return 11;
};
