<?php
use OuBrix\Weather\Http\View;
?>
<div id="forecast" data-api="<?= View::e($baseUrl) ?>/api/forecast.php" aria-busy="true">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">Předpověď počasí pro Most</h1>
        <button id="forecast-reload" type="button" class="btn btn-outline-secondary btn-sm">Znovu načíst</button>
    </div>
    <p id="forecast-scope" class="text-body-secondary mb-3">
        Most leží v Ústeckém kraji přibližně 284 m n. m. Zobrazená hodinová předpověď
        ALADIN pochází z nejbližšího výpočtového bodu ČHMÚ (280 m n. m.); nejde o
        záruku počasí v každé části města.
    </p>
    <div id="forecast-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="forecast-warning" class="alert alert-warning d-none" role="status"></div>
    <p id="forecast-loading" role="status">Načítám předpověď ČHMÚ…</p>
    <div id="forecast-items" class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-3"></div>
    <p id="forecast-source" class="small text-body-secondary"></p>
    <p class="small text-body-secondary">
        Denní teploty jsou minimum a maximum hodinových hodnot. Srážky a sníh jsou
        součty modelových hodinových hodnot v mm; sníh je vyjádřen jako vodní hodnota,
        nikoli výška nové sněhové pokrývky. Vítr ukazuje maximum a jeho směr,
        tlak rozsah hodnot během dne. Neúplný první a poslední den jsou označeny.
        Časy jsou v Europe/Prague. Data se v aplikaci obnovují po 30 minutách;
        tlačítko neobchází cache ČHMÚ.
    </p>
    <p class="small">
        <a id="forecast-source-link" href="https://www.chmi.cz/meteogram/282-most" target="_blank" rel="noopener noreferrer">
            Otevřít meteogram ČHMÚ pro Most
        </a>
        · <a href="https://data-provider.chmi.cz/api/graphs/graf.meteogram/282" target="_blank" rel="noopener noreferrer">
            zdrojová data ALADIN
        </a>
    </p>
</div>
