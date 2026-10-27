<?php
/**
 * Hlavní layout všech stránek (HTML kostra, hlavička, patička, knihovny).
 *
 * Proměnné:
 * @var string               $title    titulek stránky
 * @var string               $appName  název aplikace
 * @var string               $baseUrl  URL adresáře public/ (např. /Weather/public)
 * @var array<string, mixed> $cdn      URL + SRI knihoven z config/app.php
 * @var string               $content  HTML obsahu stránky (z pages/*)
 * @var string               $pageScript  JS modul stránky v public/assets (např. js/history.js)
 * @var string               $activePage  klíč aktivní položky menu (dashboard | history | stats)
 * @var string               $containerClass  třída hlavního kontejneru (výchozí container-xl)
 */
use OuBrix\Weather\Http\View;
?>
<!doctype html>
<html lang="cs" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title) ?> · <?= View::e($appName) ?></title>
    <meta name="description" content="Data z amatérské meteostanice OuBrix (Most) – aktuální počasí a historie měření.">
    <link rel="stylesheet" href="<?= View::e($cdn['bootstrap_css']['url']) ?>"
          integrity="<?= View::e($cdn['bootstrap_css']['sri']) ?>" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= View::e(View::asset('css/app.css')) ?>">
<?php if (isset($activePage) && $activePage === 'radar'): ?>
    <link rel="stylesheet" href="<?= View::e($cdn['leaflet_css']['url']) ?>"
          integrity="<?= View::e($cdn['leaflet_css']['sri']) ?>" crossorigin="anonymous">
    <script defer src="<?= View::e($cdn['leaflet_js']['url']) ?>"
            integrity="<?= View::e($cdn['leaflet_js']['sri']) ?>" crossorigin="anonymous"></script>
<?php endif; ?>
    <!-- Motiv se nastaví ještě před vykreslením, aby stránka „neblikla“. -->
    <script src="<?= View::e(View::asset('js/theme.js')) ?>"></script>
</head>
<body>
<?= View::render('partials/header', ['appName' => $appName, 'baseUrl' => $baseUrl, 'activePage' => isset($activePage) ? $activePage : 'dashboard']) ?>

<main id="obsah" class="<?= View::e(isset($containerClass) ? $containerClass : 'container-xl') ?> py-3 py-md-4">
<?= $content ?>
</main>

<?= View::render('partials/footer', ['appName' => $appName]) ?>

<script defer src="<?= View::e($cdn['echarts_js']['url']) ?>"
        integrity="<?= View::e($cdn['echarts_js']['sri']) ?>" crossorigin="anonymous"></script>
<?php
// Sdílené moduly importované přes „./api.js“ dostanou verzi podle data změny.
// Hosting posílá JS s cache na 30 dní; bez verze by prohlížeč mohl spojit
// nový skript stránky se starým api.js a stránka by spadla.
$modules = [];
foreach (['js/api.js', 'js/charts.js', 'js/gauges.js', 'js/week.js'] as $module) {
    $modules[$baseUrl . '/assets/' . $module] = View::asset($module);
}
?>
<script type="importmap"><?= json_encode(['imports' => $modules], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="module" src="<?= View::e(View::asset('js/navigation.js')) ?>"></script>
<script type="module" src="<?= View::e(View::asset(isset($pageScript) ? $pageScript : 'js/dashboard.js')) ?>"></script>
</body>
</html>
