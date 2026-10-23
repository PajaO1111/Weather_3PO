<?php
/**
 * Hlavní stránka: https://oubrix.eu/Weather/public/index.php
 *
 * Vykreslí jen HTML kostru (layout + dashboard). Žádná měření se zde
 * z DB nečtou – stránka se tak zobrazí i při výpadku DB a data dotáhne
 * JavaScript z public/api/current.php a public/api/observations.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use OuBrix\Weather\Http\View;

$settings = weather_settings();

header('Content-Type: text/html; charset=utf-8');
// Bez cache v CDN WEDOS – jinak se změny stránky (a verze skriptů) projeví až po hodině.
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

echo View::page('pages/dashboard', [
    'title' => 'Aktuální počasí',
    'appName' => (string) $settings['app_name'],
    'baseUrl' => View::baseUrl(),
    'cdn' => $settings['cdn'],
    'seriesHours' => (int) $settings['series_default_hours'],
    'chartGapMinutes' => (int) $settings['chart_gap_minutes'],
    'activePage' => 'dashboard',
    // Widgety využijí celou šíři velkých monitorů (max. 1920 px, viz app.css).
    'containerClass' => 'container-fluid wx-wide',
    'pageScript' => 'js/dashboard.js',
], 'layouts/main');
