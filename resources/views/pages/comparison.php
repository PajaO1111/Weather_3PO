<?php
use OuBrix\Weather\Http\View;
?>
<div id="comparison" data-api="<?= View::e($baseUrl) ?>/api/comparison.php" aria-busy="true">
    <h1 class="h4">Porovnání OuBrix a ČHMÚ Kopisty</h1>
    <p class="text-body-secondary">
        Posledních 24 uzavřených hodin. Kopisty jsou jiná lokalita přibližně 5,2 km od OuBrix.
        Teplota a rychlost větru jsou hodinové průměry dostupných vzorků, srážky jsou hodinové úhrny.
        Nejde o oficiální hodinový produkt ČHMÚ.
        Neúplná hodina může mít málo vzorků; jejich počty jsou v tabulce.
    </p>
    <p id="comparison-station" class="small text-body-secondary"></p>
    <p class="small text-body-secondary">
        Kopisty: ověřená řada obsahuje jen kvalitu 0 (Good) bez příznaku.
        Hodiny obsahující kvalitu 5 (Unknown) jsou zobrazeny zvlášť přerušovanou čarou;
        jejich hodnoty a rozdíly jsou neověřené. Podezřelé, chybějící, odhadnuté a označené hodnoty se vylučují.
        Výpadky zůstávají mezerami; poslední známá hodnota se do grafu nedoplňuje.
        Časy jsou zobrazeny v Europe/Prague včetně UTC posunu.
    </p>
    <p class="small text-body-secondary">
        Vítr ČHMÚ se převádí z m/s na km/h. Srážky ČHMÚ jsou součtem šesti desetiminutových
        intervalů končících v dané hodině (včetně jejího konce); neúplné úhrny se nezobrazují.
        Srážky OuBrix jsou přibližné úhrny z přírůstků denního čítače, přiřazené konci intervalu měření.
        Mezera nad 10 minut, reset čítače nebo chybějící pokrytí hranic hodiny znamenají mezeru, nikoli nulu.
    </p>
    <div id="comparison-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="comparison-warning" class="alert alert-warning d-none" role="status"></div>
    <p id="comparison-loading" role="status">Načítám porovnání…</p>
    <p id="comparison-summary" aria-live="polite"></p>
    <p id="comparison-quality" class="small text-body-secondary"></p>
    <div class="mb-3">
        <label for="comparison-metric" class="form-label">Porovnávaná veličina</label>
        <select id="comparison-metric" class="form-select">
            <option value="temperature">Teplota (°C)</option>
            <option value="precipitation">Srážky (mm za hodinu)</option>
            <option value="wind">Rychlost větru (km/h)</option>
        </select>
    </div>
    <section class="card mb-3"><div class="card-body">
        <h2 id="comparison-chart-title" class="h6">Hodinová průměrná teplota</h2>
        <div id="comparison-chart" class="chart" role="img" aria-label="Porovnání teploty OuBrix a Kopist"></div>
    </div></section>
    <div class="table-responsive">
        <table class="table table-sm">
            <caption>Vzorky a vyloučené hodnoty po hodinách; rozdíl = OuBrix − Kopisty.</caption>
            <thead><tr><th scope="col">Začátek hodiny</th><th id="comparison-local-heading" scope="col">OuBrix °C</th><th scope="col">Vzorky / intervaly</th>
                <th id="comparison-kopisty-heading" scope="col">Kopisty °C</th><th scope="col">Vzorky / intervaly</th>
                <th scope="col">Vyloučeno ČHMÚ</th><th id="comparison-difference-heading" scope="col">Rozdíl °C</th><th scope="col">Kvalita ČHMÚ</th>
            </tr></thead>
            <tbody id="comparison-rows"></tbody>
        </table>
    </div>
    <p class="small text-body-secondary"><a href="https://opendata.chmi.cz/meteorology/climate/now/">Zdroj: OpenData ČHMÚ</a></p>
</div>
