<?php
/**
 * Hlavička webu: název, navigace a přepínač motivu.
 *
 * @var string $appName
 * @var string $baseUrl
 * @var string $activePage klíč aktivní stránky
 */
use OuBrix\Weather\Http\View;

// Položky menu: klíč => [soubor v public/, popisek].
$menu = [
    'dashboard' => ['index.php', 'Aktuálně'],
    'history' => ['history.php', 'Historie'],
    'stats' => ['stats.php', 'Statistiky'],
    'analytics' => ['analytics.php', 'Analytika'],
    'air-quality' => ['air-quality.php', 'Ovzduší ČHMÚ'],
    'comparison' => ['comparison.php', 'Kopisty'],
    'climate' => ['climate.php', 'Klima Kopisty'],
];
?>
<a class="visually-hidden-focusable" href="#obsah">Přeskočit na obsah</a>
<header class="app-header border-bottom">
    <nav class="navbar navbar-expand container-xl flex-wrap gap-2" aria-label="Hlavní navigace">
        <a class="navbar-brand fw-semibold" href="<?= View::e($baseUrl) ?>/index.php">
            <span aria-hidden="true">⛅</span> <?= View::e($appName) ?>
        </a>
        <ul class="navbar-nav me-auto flex-wrap">
<?php foreach ($menu as $key => $entry): ?>
<?php if ($key === $activePage): ?>
            <li class="nav-item"><a class="nav-link active" aria-current="page" href="<?= View::e($baseUrl) ?>/<?= View::e($entry[0]) ?>"><?= View::e($entry[1]) ?></a></li>
<?php else: ?>
            <li class="nav-item"><a class="nav-link" href="<?= View::e($baseUrl) ?>/<?= View::e($entry[0]) ?>"><?= View::e($entry[1]) ?></a></li>
<?php endif; ?>
<?php endforeach; ?>
        </ul>
        <div class="btn-group btn-group-sm" role="group" aria-label="Barevný motiv">
            <button type="button" class="btn btn-outline-secondary" data-theme-value="auto" title="Podle systému">Auto</button>
            <button type="button" class="btn btn-outline-secondary" data-theme-value="light" title="Světlý motiv">☀</button>
            <button type="button" class="btn btn-outline-secondary" data-theme-value="dark" title="Tmavý motiv">☾</button>
        </div>
    </nav>
</header>
