<?php
/**
 * Hlavička webu: název, navigace a přepínač motivu.
 *
 * Na širokém displeji (≥ 992 px) je navigace řada ikon vedle loga s popiskem
 * při najetí/fokusu; na užším displeji rozbalovací „Menu“. Vždy je viditelná
 * jen jedna z nich (druhá má display:none, takže ji nečte ani čtečka).
 *
 * @var string $appName
 * @var string $baseUrl
 * @var string $activePage klíč aktivní stránky
 */
use OuBrix\Weather\Http\View;

// klíč => [soubor, název, ikona ze sprite partials/icons.php]
$groups = [
    'Vlastní stanice' => [
        'dashboard' => ['index.php', 'Aktuálně', 'i-dashboard'],
        'history' => ['history.php', 'Historie', 'i-history'],
    ],
    'Analytika' => [
        'stats' => ['stats.php', 'Statistiky a rekordy', 'i-stats'],
        'analytics' => ['analytics.php', 'Analytika srážek', 'i-analytics'],
    ],
    'Příroda' => [
        'phenology' => ['phenology.php', 'Fenologie', 'i-phenology'],
    ],
    'ČHMÚ' => [
        'radar' => ['radar.php', 'Radar ČHMÚ', 'i-radar'],
        'warnings' => ['warnings.php', 'Výstrahy – Most', 'i-warnings'],
        'forecast' => ['forecast.php', 'Předpověď ČHMÚ', 'i-forecast'],
        'air-quality' => ['air-quality.php', 'Ovzduší ČHMÚ', 'i-air-quality'],
        'comparison' => ['comparison.php', 'Kopisty – porovnání', 'i-comparison'],
        'climate' => ['climate.php', 'Klima Kopisty', 'i-climate'],
    ],
];
$icon = static function (string $id): string {
    return '<svg class="wx-icon" aria-hidden="true" focusable="false"><use href="#' . View::e($id) . '"></use></svg>';
};
?>
<?= View::render('partials/icons') ?>
<a class="visually-hidden-focusable" href="#obsah">Přeskočit na obsah</a>
<header class="app-header border-bottom">
    <nav class="weather-navigation container-fluid wx-wide" aria-label="Hlavní navigace">
        <a class="navbar-brand fw-semibold weather-brand" href="<?= View::e($baseUrl) ?>/index.php">
            <span aria-hidden="true">⛅</span> <?= View::e($appName) ?>
        </a>
        <ul class="weather-iconbar list-unstyled mb-0">
<?php foreach ($groups as $label => $menu): ?>
            <li class="weather-iconbar-group">
                <ul class="list-unstyled mb-0" aria-label="<?= View::e($label) ?>">
<?php foreach ($menu as $key => $entry): ?>
                    <li><a class="weather-iconbar-link<?= $key === $activePage ? ' active' : '' ?>"<?= $key === $activePage ? ' aria-current="page"' : '' ?>
                           href="<?= View::e($baseUrl) ?>/<?= View::e($entry[0]) ?>"><?= $icon($entry[2]) ?><span class="weather-iconbar-label"><?= View::e($entry[1]) ?></span></a></li>
<?php endforeach; ?>
                </ul>
            </li>
<?php endforeach; ?>
        </ul>
        <details class="weather-menu">
            <summary><?= $icon('i-menu') ?> Menu</summary>
            <div class="weather-menu-panel">
<?php foreach ($groups as $label => $menu): ?>
                <section>
                    <h2 class="h6 text-body-secondary"><?= View::e($label) ?></h2>
                    <ul class="list-unstyled mb-0">
<?php foreach ($menu as $key => $entry): ?>
                        <li><a class="weather-menu-link<?= $key === $activePage ? ' active' : '' ?>"<?= $key === $activePage ? ' aria-current="page"' : '' ?>
                               href="<?= View::e($baseUrl) ?>/<?= View::e($entry[0]) ?>"><?= $icon($entry[2]) ?> <?= View::e($entry[1]) ?></a></li>
<?php endforeach; ?>
                    </ul>
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
