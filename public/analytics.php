<?php
/**
 * Stránka analytiky vlastních měření; hodnoty načítá z api/analytics.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use OuBrix\Weather\Http\View;

$settings = weather_settings();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

echo View::page('pages/analytics', [
    'title' => 'Analytika',
    'appName' => (string) $settings['app_name'],
    'baseUrl' => View::baseUrl(),
    'cdn' => $settings['cdn'],
    'activePage' => 'analytics',
    'pageScript' => 'js/analytics.js',
], 'layouts/main');
