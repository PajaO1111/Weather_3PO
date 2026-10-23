<?php
/**
 * Odpověď ve formátu CSV ke stažení (export historie).
 *
 * Formát pro český Excel: UTF-8 s BOM, oddělovač středník, řádky CRLF.
 * Hodnoty se předávají už jako hotové řetězce (formátuje HistoryCsv).
 */
declare(strict_types=1);

namespace OuBrix\Weather\Http;

final class CsvResponse
{
    /** @var string */
    private $filename;

    /** @var list<string> */
    private $header;

    /** @var list<list<string>> */
    private $rows;

    /**
     * @param string             $filename název souboru (jen [A-Za-z0-9._-])
     * @param list<string>       $header   názvy sloupců
     * @param list<list<string>> $rows     řádky hodnot
     */
    public function __construct(string $filename, array $header, array $rows)
    {
        $this->filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'export.csv';
        $this->header = $header;
        $this->rows = $rows;
    }

    /** Text CSV včetně BOM (samostatně kvůli testům). */
    public function body(): string
    {
        $lines = [self::line($this->header)];
        foreach ($this->rows as $row) {
            $lines[] = self::line($row);
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    /** Odešle hlavičky a obsah souboru. */
    public function send(): void
    {
        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $this->filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $this->body();
    }

    /** @param list<string> $fields */
    private static function line(array $fields): string
    {
        $out = [];
        foreach ($fields as $field) {
            $field = (string) $field;
            // Ochrana proti „CSV injection“: text začínající =, +, -, @ by Excel spustil jako vzorec.
            // Záporná čísla („-3,5“) jsou v pořádku, proto se kontroluje jen nečíselný text.
            if ($field !== '' && strpos('=+-@', $field[0]) !== false && preg_match('/^-?\d+(,\d+)?$/', $field) !== 1) {
                $field = "'" . $field;
            }
            if (preg_match('/[;"\r\n]/', $field) === 1) {
                $field = '"' . str_replace('"', '""', $field) . '"';
            }
            $out[] = $field;
        }
        return implode(';', $out);
    }
}
