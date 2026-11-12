<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use PDO;
use RuntimeException;

final class PhenologyUpdateService
{
    /** @var PDO */
    private $pdo;
    /** @var ChmiGateway */
    private $gateway;

    public function __construct(PDO $pdo, ChmiGateway $gateway)
    {
        $this->pdo = $pdo;
        $this->gateway = $gateway;
    }

    /** @return array<string,mixed> */
    public function update(string $directory): array
    {
        $started = microtime(true);
        $files = [];
        foreach (['wood_species', 'herbs'] as $category) {
            $data = self::csvNames($this->gateway->fetchPhenology($category . '/data/'));
            $metadata = self::csvNames($this->gateway->fetchPhenology($category . '/metadata/'));
            if ($data === [] || !in_array('Seznam_fenofaze.csv', $metadata, true)) {
                throw new RuntimeException('ČHMÚ neposkytuje úplný seznam fenologických souborů.');
            }
            foreach ($data as $name) {
                $stationName = substr($name, 0, -4) . '_stanice.csv';
                if (!in_array($stationName, $metadata, true)) {
                    throw new RuntimeException('V nabídce ČHMÚ chybí metadata druhu.');
                }
                $files[] = $category . '/data/' . $name;
                $files[] = $category . '/metadata/' . $stationName;
            }
            $files[] = $category . '/metadata/Seznam_fenofaze.csv';
        }
        if (count($files) > 200) {
            throw new RuntimeException('Sada ČHMÚ překračuje podporovaný počet souborů.');
        }
        $previous = $this->pdo->query('SELECT source_path, sha256 FROM phenology_import_files')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        // A disappearing source file must not silently erase previously imported species.
        if (array_diff(array_keys($previous), $files) !== []) {
            throw new RuntimeException('Na ČHMÚ chybí dříve importované soubory. Aktualizaci musí posoudit správce.');
        }
        $hashes = [];
        $bytes = 0;
        try {
            foreach ($files as $relative) {
                if (microtime(true) - $started > 100) {
                    throw new RuntimeException('Kontrola ČHMÚ překročila časový limit. Zkuste ji později.');
                }
                $body = $this->gateway->fetchPhenology($relative);
                $bytes += strlen($body);
                if ($bytes > 30000000) {
                    throw new RuntimeException('Sada ČHMÚ překračuje limit 30 MB.');
                }
                $path = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $parent = dirname($path);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    throw new RuntimeException('Nelze vytvořit pracovní složku fenologie.');
                }
                if (file_put_contents($path, $body) !== strlen($body)) {
                    throw new RuntimeException('Nelze uložit pracovní kopii dat ČHMÚ.');
                }
                $hashes[$relative] = hash('sha256', $body);
            }
            ksort($previous);
            ksort($hashes);
            if ($previous === $hashes) {
                return ['status' => 'unchanged', 'message' => 'Nová data nejsou. Data v DB odpovídají souborům ČHMÚ.'];
            }
            $result = (new PhenologyImportService($this->pdo))->importDirectory($directory);
            return [
                'status' => 'updated',
                'message' => 'Data byla aktualizována z ČHMÚ.',
                'import' => $result,
            ];
        } finally {
            foreach ($files as $relative) {
                $path = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (is_file($path) && !unlink($path)) {
                    error_log('OuBrix Weather fenologie: nelze odstranit pracovní CSV.');
                }
            }
            foreach (['wood_species', 'herbs'] as $category) {
                foreach (['data', 'metadata'] as $type) {
                    $path = $directory . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . $type;
                    if (is_dir($path) && !rmdir($path)) {
                        error_log('OuBrix Weather fenologie: nelze odstranit pracovní složku.');
                    }
                }
                $path = $directory . DIRECTORY_SEPARATOR . $category;
                if (is_dir($path) && !rmdir($path)) {
                    error_log('OuBrix Weather fenologie: nelze odstranit pracovní složku kategorie.');
                }
            }
            if (is_dir($directory) && !rmdir($directory)) {
                error_log('OuBrix Weather fenologie: nelze odstranit pracovní složku importu.');
            }
        }
    }

    /** @return string[] */
    public static function csvNames(string $html): array
    {
        preg_match_all('~href=["\']([A-Za-z0-9_]{1,80}\.csv)["\']~i', $html, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names, SORT_STRING);
        return $names;
    }
}
