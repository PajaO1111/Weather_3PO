<?php
use OuBrix\Weather\Http\View;
?>
<div id="forecast" data-api="<?= View::e($baseUrl) ?>/api/forecast.php" aria-busy="true">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">Textová předpověď ČHMÚ</h1>
        <button id="forecast-reload" type="button" class="btn btn-outline-secondary btn-sm">Znovu načíst</button>
    </div>
    <p id="forecast-scope" class="text-body-secondary">
        Obecná textová předpověď ČHMÚ; není lokální předpovědí pro Most.
        Konkrétní oblasti platí pouze tam, kde je zdrojový text výslovně jmenuje.
    </p>
    <div id="forecast-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="forecast-warning" class="alert alert-warning d-none" role="status"></div>
    <p id="forecast-loading" role="status">Načítám předpověď ČHMÚ…</p>
    <div id="forecast-items"></div>
    <p id="forecast-source" class="small text-body-secondary"></p>
    <p class="small text-body-secondary">
        Časy jsou zobrazeny v Europe/Prague. Pokud zdroj neuvádí konec platnosti,
        nedopočítáváme jej z názvu nebo textu. Vydání starší než 24 hodin označujeme
        jako zastaralé; nejde o poskytovatelem určenou dobu platnosti.
        Cache se obnovuje po 30 minutách. Tlačítko načte stav aplikace, neobchází cache ČHMÚ.
    </p>
    <p class="small"><a href="https://data-provider.chmi.cz/api/data/txtf/meteo.today">Zdroj: textová předpověď ČHMÚ</a></p>
</div>
