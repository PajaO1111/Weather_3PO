<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use OuBrix\Weather\Http\View;

$settings = weather_settings();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
echo View::page('pages/comparison', [
    'title' => 'Srovnání s Kopisty',
    'appName' => $settings['app_name'],
    'baseUrl' => View::baseUrl(),
    'cdn' => $settings['cdn'],
    'activePage' => 'comparison',
    'pageScript' => 'js/comparison.js',
], 'layouts/main');
