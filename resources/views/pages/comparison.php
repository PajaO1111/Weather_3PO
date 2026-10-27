<?php
use OuBrix\Weather\Http\View;
?>
<div id="comparison" data-api="<?= View::e($baseUrl) ?>/api/comparison.php" aria-busy="true">
    <h1 class="h4">Teplota OuBrix a ČHMÚ Kopisty</h1>
    <p class="text-body-secondary">
        Posledních 24 uzavřených hodin. Kopisty jsou jiná lokalita přibližně 5,2 km od OuBrix.
        Hodinové hodnoty jsou prosté průměry dostupných vzorků, nikoli oficiální hodinový produkt ČHMÚ.
        Neúplná hodina může mít málo vzorků; jejich počty jsou v tabulce.
    </p>
    <p id="comparison-station" class="small text-body-secondary"></p>
    <p class="small text-body-secondary">
        Kopisty: do průměru vstupuje jen kvalita 0 (Good) bez příznaku.
        Podezřelé, chybějící, odhadnuté i hodnoty neznámé kvality se vylučují.
        Výpadky zůstávají mezerami; poslední známá hodnota se do grafu nedoplňuje.
        Časy jsou zobrazeny v Europe/Prague včetně UTC posunu.
    </p>
    <div id="comparison-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="comparison-warning" class="alert alert-warning d-none" role="status"></div>
    <p id="comparison-loading" role="status">Načítám porovnání…</p>
    <p id="comparison-summary" aria-live="polite"></p>
    <p id="comparison-quality" class="small text-body-secondary"></p>
    <section class="card mb-3"><div class="card-body">
        <h2 class="h6">Hodinová průměrná teplota</h2>
        <div id="comparison-chart" class="chart" role="img" aria-label="Porovnání teploty OuBrix a Kopist"></div>
    </div></section>
    <div class="table-responsive">
        <table class="table table-sm">
            <caption>Vzorky a vyloučené hodnoty po hodinách; rozdíl = OuBrix − Kopisty.</caption>
            <thead><tr><th scope="col">Začátek hodiny</th><th scope="col">OuBrix °C</th><th scope="col">Vzorky</th>
                <th scope="col">Kopisty °C</th><th scope="col">Vzorky</th><th scope="col">Vyloučeno ČHMÚ</th><th scope="col">Rozdíl °C</th></tr></thead>
            <tbody id="comparison-rows"></tbody>
        </table>
    </div>
    <p class="small text-body-secondary"><a href="https://opendata.chmi.cz/meteorology/climate/now/">Zdroj: OpenData ČHMÚ</a></p>
</div>
