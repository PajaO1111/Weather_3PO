<?php
/**
 * Stránka „Historie“: https://oubrix.eu/Weather/public/history.php?from=…&to=…&resolution=…
 *
 * Stejně jako index.php vykreslí jen HTML kostru – měření za zvolené období
 * načte JavaScript (public/assets/js/history.js) z api/history.php.
 * Parametry v URL slouží k předvyplnění formuláře a ke sdílení odkazu;
 * neplatné hodnoty se nahradí výchozím obdobím (posledních 7 dní) s upozorněním.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use OuBrix\Weather\Http\Validation\HistoryQuery;
use OuBrix\Weather\Http\View;

$settings = weather_settings();
$now = new DateTimeImmutable('now', new DateTimeZone((string) $settings['timezone']));
$notice = null;
try {
    $query = HistoryQuery::parse($_GET, $now, $settings);
} catch (InvalidArgumentException $exception) {
    $notice = $exception->getMessage() . ' Zobrazuji výchozí období.';
    $query = HistoryQuery::parse([], $now, $settings);
}

header('Content-Type: text/html; charset=utf-8');
// Stránka obsahuje dnešní datum (předvolby období) – CDN WEDOS ji nesmí držet v cache.
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

echo View::page('pages/history', [
    'title' => 'Historie měření',
    'appName' => (string) $settings['app_name'],
    'baseUrl' => View::baseUrl(),
    'cdn' => $settings['cdn'],
    'activePage' => 'history',
    'pageScript' => 'js/history.js',
    'from' => $query['from']->format('Y-m-d'),
    'to' => $query['to']->format('Y-m-d'),
    'resolution' => $query['requested'],
    'today' => $now->format('Y-m-d'),
    'notice' => $notice,
    'limits' => [
        'maxDays' => (int) $settings['history_max_days'],
        'rawMaxDays' => (int) $settings['history_raw_max_days'],
        'hourMaxDays' => (int) $settings['history_hour_max_days'],
        'autoRawDays' => (int) $settings['history_auto_raw_days'],
        'autoHourDays' => (int) $settings['history_auto_hour_days'],
        'tableMaxRows' => (int) $settings['history_table_max_rows'],
    ],
    'chartGapMinutes' => (int) $settings['chart_gap_minutes'],
], 'layouts/main');
