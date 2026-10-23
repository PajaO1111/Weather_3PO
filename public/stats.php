<?php
/**
 * Stránka „Statistiky a rekordy“: https://oubrix.eu/Weather/public/stats.php?year=2026 | ?year=all
 *
 * Vykreslí jen HTML kostru – data načte JavaScript (public/assets/js/stats.js)
 * z api/stats.php. Neplatný rok se nahradí letošním s upozorněním.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use OuBrix\Weather\Http\Validation\StatsQuery;
use OuBrix\Weather\Http\View;

$settings = weather_settings();
$now = new DateTimeImmutable('now', new DateTimeZone((string) $settings['timezone']));
$notice = null;
try {
    $query = StatsQuery::parse($_GET, $now, $settings);
} catch (InvalidArgumentException $exception) {
    $notice = $exception->getMessage() . ' Zobrazuji letošní rok.';
    $query = StatsQuery::parse([], $now, $settings);
}

header('Content-Type: text/html; charset=utf-8');
// Stránka obsahuje letošní rok – CDN WEDOS ji nesmí držet v cache.
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

echo View::page('pages/stats', [
    'title' => 'Statistiky a rekordy',
    'appName' => (string) $settings['app_name'],
    'baseUrl' => View::baseUrl(),
    'cdn' => $settings['cdn'],
    'activePage' => 'stats',
    'pageScript' => 'js/stats.js',
    'period' => $query['period'],
    'currentYear' => (int) $now->format('Y'),
    'notice' => $notice,
], 'layouts/main');
