<?php
/**
 * Hlavička webu: název, navigace a přepínač motivu.
 *
 * @var string $appName
 * @var string $baseUrl
 * @var string $activePage klíč aktivní stránky
 */
use OuBrix\Weather\Http\View;

$groups = [
    'Vlastní stanice' => [
        'dashboard' => ['index.php', 'Aktuálně'],
        'history' => ['history.php', 'Historie'],
    ],
    'Analytika' => [
        'stats' => ['stats.php', 'Statistiky a rekordy'],
        'analytics' => ['analytics.php', 'Analytika srážek'],
    ],
    'ČHMÚ' => [
        'warnings' => ['warnings.php', 'Výstrahy – Most'],
        'forecast' => ['forecast.php', 'Předpověď ČHMÚ'],
        'air-quality' => ['air-quality.php', 'Ovzduší ČHMÚ'],
        'comparison' => ['comparison.php', 'Kopisty – porovnání'],
        'climate' => ['climate.php', 'Klima Kopisty'],
    ],
];
?>
<a class="visually-hidden-focusable" href="#obsah">Přeskočit na obsah</a>
<header class="app-header border-bottom">
    <nav class="weather-navigation container-xl" aria-label="Hlavní navigace">
        <a class="navbar-brand fw-semibold weather-brand" href="<?= View::e($baseUrl) ?>/index.php">
            <span aria-hidden="true">⛅</span> <?= View::e($appName) ?>
        </a>
        <details class="weather-menu">
            <summary>Menu</summary>
            <div class="weather-menu-panel">
<?php foreach ($groups as $label => $menu): ?>
                <section>
                    <h2 class="h6 text-body-secondary"><?= View::e($label) ?></h2>
                    <ul class="list-unstyled mb-0">
<?php foreach ($menu as $key => $entry): ?>
                        <li><a class="weather-menu-link<?= $key === $activePage ? ' active' : '' ?>"<?= $key === $activePage ? ' aria-current="page"' : '' ?>
                               href="<?= View::e($baseUrl) ?>/<?= View::e($entry[0]) ?>"><?= View::e($entry[1]) ?></a></li>
<?php endforeach; ?>
                </section>
<?php endforeach; ?>
            </div>
        </details>
        <div class="btn-group btn-group-sm weather-theme" role="group" aria-label="Barevný motiv">
            <button type="button" class="btn btn-outline-secondary" data-theme-value="auto" title="Podle systému">Auto</button>
            <button type="button" class="btn btn-outline-secondary" data-theme-value="light" title="Světlý motiv">☀</button>
            <button type="button" class="btn btn-outline-secondary" data-theme-value="dark" title="Tmavý motiv">☾</button>
        </div>
    </nav>
</header>
