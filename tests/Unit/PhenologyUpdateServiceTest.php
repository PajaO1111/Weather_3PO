<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\PhenologyUpdateService;

require_once dirname(__DIR__) . '/support/PhenologyFixtures.php';

return static function (): int {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    PhenologyFixtures::createSchema($pdo);
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pheno-update-' . bin2hex(random_bytes(6));
    mkdir($root);
    $sources = [];
    foreach (['wood_species', 'herbs'] as $category) {
        $sources[$category . '/data/'] = '<a href="Test.csv">Test</a>';
        $sources[$category . '/metadata/'] = '<a href="Test_stanice.csv">Stations</a>'
            . '<a href="Seznam_fenofaze.csv">Phases</a>';
        $sources[$category . '/data/Test.csv'] = "\"Stanice\",\"Rok\",\"Plodina\",\"Fenofáze\",\"Julian\"\n"
            . "\"U1DOKS31\",\"2025\",\"Test\",\"PK50\",\"100\"\n";
        $sources[$category . '/metadata/Test_stanice.csv'] =
            "\"Název stanice\",\"Indikativ stanice\",\"Nadmořská výška (m)\",\"z.š.\",\"z.d.\",\"Období\",\"Fenofáze (Test)\"\n"
            . "\"Doksany\",\"U1DOKS31\",\"155\",\"50.45\",\"14.17\",\"2014-2025\",\"PK50\"\n";
        $sources[$category . '/metadata/Seznam_fenofaze.csv'] =
            "\"Zkratka fenofáze\",\"Název fenofáze\"\n\"PK50\",\"Kvetení\"\n";
    }
    // Species codes must differ between the two source categories.
    foreach (array_keys($sources) as $path) {
        if (strpos($path, 'herbs/') === 0) {
            $body = $sources[$path];
            unset($sources[$path]);
            $sources[str_replace('Test', 'Herb', $path)] = str_replace('Test', 'Herb', $body);
        }
    }
    $fail = false;
    $gateway = new ChmiGateway($root, static function (string $url, int $maxBytes) use (&$sources, &$fail): string {
        check(strpos($url, 'https://opendata.chmi.cz/meteorology/phenology/') === 0, 'fixed upstream origin');
        if ($fail) throw new RuntimeException('test upstream offline');
        $path = substr($url, strlen('https://opendata.chmi.cz/meteorology/phenology/'));
        if (!isset($sources[$path])) throw new RuntimeException('missing fixture path');
        return $sources[$path];
    });
    $service = new PhenologyUpdateService($pdo, $gateway);
    $checks = 0;
    try {
        check(PhenologyUpdateService::csvNames('<a href="../Bad.csv">x</a><a href="Good.csv">y</a>'
            . '<a href="https://evil.test/A.csv">z</a>') === ['Good.csv'], 'reject unsafe source filenames');
        $checks++;
        $denied = false;
        try { $gateway->fetchPhenology('herbs/data/../../config.php'); }
        catch (RuntimeException $exception) { $denied = true; }
        check($denied, 'gateway rejects path traversal before transport');
        $checks++;
        $first = $service->update($root . DIRECTORY_SEPARATOR . 'first');
        check($first['status'] === 'updated' && $first['import']['files'] === 6
            && $first['import']['observations'] === 2, 'initial full import');
        $checks++;
        $pdo->exec("UPDATE phenology_import_files SET imported_at = '2020-01-01 00:00:00'");
        $same = $service->update($root . DIRECTORY_SEPARATOR . 'same');
        check($same['status'] === 'unchanged'
            && $pdo->query('SELECT MIN(imported_at) FROM phenology_import_files')->fetchColumn() === '2020-01-01 00:00:00',
            'unchanged sources do not rewrite DB or import timestamp');
        $checks++;
        $sources['herbs/data/Herb.csv'] .= "\"U1DOKS31\",\"2026\",\"Herb\",\"PK50\",\"110\"\n";
        $updated = $service->update($root . DIRECTORY_SEPARATOR . 'changed');
        check($updated['status'] === 'updated' && $updated['import']['observations'] === 3,
            'new year triggers import');
        $checks++;
        $snapshot = $pdo->query('SELECT * FROM phenology_observations ORDER BY 1,2,3,4')->fetchAll();
        $sources['herbs/data/Herb.csv'] = 'invalid CSV';
        $denied = false;
        try { $service->update($root . DIRECTORY_SEPARATOR . 'invalid'); }
        catch (RuntimeException $exception) { $denied = true; }
        check($denied && $snapshot === $pdo->query('SELECT * FROM phenology_observations ORDER BY 1,2,3,4')->fetchAll(),
            'invalid source preserves original DB');
        $checks++;
        check(!is_dir($root . DIRECTORY_SEPARATOR . 'invalid'), 'remove staged files after failure');
        $checks++;
        $sources['herbs/data/'] = '<html>empty</html>';
        $denied = false;
        try { $service->update($root . DIRECTORY_SEPARATOR . 'empty'); }
        catch (RuntimeException $exception) { $denied = true; }
        check($denied, 'empty index is not reported as unchanged');
        $checks++;
        $fail = true;
        $denied = false;
        try { $service->update($root . DIRECTORY_SEPARATOR . 'offline'); }
        catch (RuntimeException $exception) { $denied = true; }
        check($denied && (int) $pdo->query('SELECT COUNT(*) FROM phenology_observations')->fetchColumn() === 3,
            'upstream failure preserves DB and surfaces error');
        $checks++;
    } finally {
        rmdir($root);
    }
    return $checks;
};
