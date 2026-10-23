<?php
/**
 * Minimalistický renderer PHP šablon z resources/views.
 *
 * Šablona = obyčejný .php soubor s HTML. Proměnné se předávají polem,
 * výstup se VŽDY escapuje funkcí View::e() (ochrana proti XSS).
 *
 * Použití:
 *   View::page('pages/dashboard', ['title' => 'Aktuálně'], 'layouts/main');
 */
declare(strict_types=1);

namespace OuBrix\Weather\Http;

use RuntimeException;

final class View
{
    /**
     * Vykreslí šablonu a vrátí HTML jako řetězec.
     *
     * @param string               $template cesta relativně k resources/views bez .php
     * @param array<string, mixed> $data     proměnné dostupné v šabloně
     */
    public static function render(string $template, array $data = []): string
    {
        if (preg_match('#^[a-z0-9_/-]+$#', $template) !== 1) {
            throw new RuntimeException('Neplatny nazev sablony.');
        }
        $file = WEATHER_ROOT . '/resources/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Sablona nenalezena: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
        return (string) ob_get_clean();
    }

    /**
     * Vykreslí obsah stránky a vloží ho do layoutu jako proměnnou $content.
     *
     * @param array<string, mixed> $data
     */
    public static function page(string $template, array $data, string $layout): string
    {
        $data['content'] = self::render($template, $data);
        return self::render($layout, $data);
    }

    /** Escapování textu pro HTML (obsah i atributy). */
    public static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Základní URL adresáře public/ (např. „/Weather/public“).
     *
     * Na WEDOS obsahuje SCRIPT_NAME vnitřní cestu („/domains/oubrix.eu/Weather/public/index.php“),
     * ne veřejnou URL. Proto se základ odvodí z REQUEST_URI a přijme se jen tehdy,
     * když je koncovou částí adresáře skriptu – podvržené REQUEST_URI tak nic nezmění.
     */
    public static function baseUrl(): string
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '/index.php';
        $scriptDir = rtrim(dirname($script), '/');
        $scriptDir = $scriptDir === '.' ? '' : $scriptDir;

        $path = parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $file = '/' . basename($script);
            if (substr($path, -strlen($file)) === $file) {
                $path = substr($path, 0, -strlen($file));
            }
            $candidate = rtrim($path, '/');
            if ($candidate === '' || substr($scriptDir, -strlen($candidate)) === $candidate) {
                return $candidate;
            }
        }
        return $scriptDir;
    }

    /**
     * URL statického souboru z public/assets s verzí podle data změny,
     * aby prohlížeč po nahrání nové verze nepoužil starou kopii z cache.
     */
    public static function asset(string $path): string
    {
        $file = WEATHER_ROOT . '/public/assets/' . ltrim($path, '/');
        $version = is_file($file) ? (string) filemtime($file) : '0';
        return self::baseUrl() . '/assets/' . ltrim($path, '/') . '?v=' . $version;
    }
}
