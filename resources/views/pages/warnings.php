<?php
use OuBrix\Weather\Http\View;
?>
<div id="warnings" data-api="<?= View::e($baseUrl) ?>/api/warnings.php" aria-busy="true">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">Výstrahy ČHMÚ – ORP Most</h1>
        <button id="warnings-reload" type="button" class="btn btn-outline-secondary btn-sm">Znovu načíst</button>
    </div>
    <p class="text-body-secondary">
        Výběr podle geokódu CISORP 4209, nikoli podle textového názvu oblasti.
        Jde o výstražné události z aktuálního CAP souboru ČHMÚ, ne lokální předpověď
        ani hodinové koncentrace či index kvality ovzduší.
    </p>
    <div id="warnings-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="warnings-warning" class="alert alert-warning d-none" role="status"></div>
    <p id="warnings-loading" role="status">Načítám výstrahy ČHMÚ…</p>
    <div id="warnings-summary" class="alert alert-secondary d-none" role="status"></div>
    <div id="warnings-items"></div>
    <details id="warnings-other" class="mb-3 d-none">
        <summary>Hlášení bez výstrahy, neaktuální a testovací zprávy</summary>
        <div id="warnings-other-items" class="mt-3"></div>
    </details>
    <p id="warnings-source" class="small text-body-secondary forecast-text"></p>
    <p class="small text-body-secondary">
        Časy jsou v Europe/Prague. Konec platnosti zprávy není totéž co předpokládaný
        konec výskytu jevu; zobrazujeme je odděleně. Neuvedený konec platnosti znamená, že jej zdroj neudává
        (výstraha může platit do odvolání). Výškové hranice CAP jsou ve stopách, nikoli metrech.
        Cache se obnovuje po 5 minutách při načtení. Vydání starší než 24 hodin označujeme
        jako zastaralé z opatrnosti; nejde o dobu platnosti stanovenou ČHMÚ.
        Výpadek, neúplná zpráva ani prázdný výběr nepotvrzují stav bez výstrah.
    </p>
    <p class="small">
        Tento přehled nenahrazuje oficiální výstražnou službu.
        <a href="https://vystrahy-cr.chmi.cz/">Oficiální výstrahy ČHMÚ</a> ·
        <a href="https://vystrahy-cr.chmi.cz/data2/XOCZ50_OKPR.xml">Zdroj CAP XML</a>
    </p>
    <noscript><p class="alert alert-warning">Pro načtení přehledu zapněte JavaScript nebo otevřete oficiální výstrahy ČHMÚ.</p></noscript>
</div>
