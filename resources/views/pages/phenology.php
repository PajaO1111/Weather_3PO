<?php
use OuBrix\Weather\Http\View;
?>
<div id="phenology" data-api="<?= View::e($baseUrl) ?>/api/phenology.php" aria-busy="true">
    <header class="mb-3">
        <h1 class="h4 mb-1">Fenologický kalendář ČHMÚ</h1>
        <p class="text-body-secondary mb-0">
            Historická pozorování vybrané stanice, dřeviny či byliny a fenofáze.
            Jde o kalendář fenologie, ne o aktuální pylovou předpověď pro Most.
        </p>
    </header>

    <form id="phenology-form" class="card mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label" for="phenology-station">Stanice</label>
                    <select class="form-select" id="phenology-station" name="station" disabled>
                        <option>Načítám stanice…</option>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label" for="phenology-species">Dřevina nebo bylina</label>
                    <select class="form-select" id="phenology-species" name="species" disabled></select>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label" for="phenology-phase">Fenofáze</label>
                    <select class="form-select" id="phenology-phase" name="phase" disabled></select>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label" for="phenology-year">Rok</label>
                    <select class="form-select" id="phenology-year" name="year" disabled></select>
                </div>
            </div>
            <fieldset class="mt-3">
                <legend class="form-label mb-1">Srovnání stanic podle nadmořské výšky</legend>
                <p class="small text-body-secondary mb-2">
                    Základní stanice bude vždy zahrnuta. Přidejte až tři další stanice
                    se společnými pozorovanými roky; srovnání nepředpokládá příčinný vliv výšky.
                </p>
                <div id="phenology-compare-options" class="phenology-station-options">
                    <span class="small text-body-secondary">Načítám dostupná srovnání…</span>
                </div>
            </fieldset>
            <button type="submit" class="btn btn-primary mt-3" id="phenology-submit">Zobrazit</button>
        </div>
    </form>

    <div id="phenology-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="phenology-warning" class="alert alert-warning d-none" role="status"></div>
    <p id="phenology-loading" class="text-body-secondary" role="status">Načítám fenologická pozorování…</p>

    <div id="phenology-results" class="d-none">
        <p id="phenology-station-summary" class="small text-body-secondary"></p>
        <div class="row g-3 mb-3">
            <section class="col-12 col-xl-6">
                <div class="card h-100"><div class="card-body">
                    <h2 class="h6">Kalendář fenofází ve zvoleném roce</h2>
                    <div id="phenology-calendar-chart" class="chart phenology-chart" role="img"
                         aria-label="Kalendář fenofází ve vybraném roce"></div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden">Fenofáze a jejich stav ve zvoleném roce</caption>
                            <thead><tr><th scope="col">Fenofáze</th><th scope="col">Datum / stav</th></tr></thead>
                            <tbody id="phenology-calendar-rows"></tbody>
                        </table>
                    </div>
                </div></div>
            </section>
            <section class="col-12 col-xl-6">
                <div class="card h-100"><div class="card-body">
                    <h2 class="h6">Nástup zvolené fenofáze v jednotlivých letech</h2>
                    <p id="phenology-median" class="small text-body-secondary"></p>
                    <div id="phenology-series-chart" class="chart phenology-chart" role="img"
                         aria-label="Meziroční průběh nástupu fenofáze"></div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden">Pozorované, nenastalé a chybějící roky zvolené fenofáze</caption>
                            <thead><tr><th scope="col">Rok</th><th scope="col">Datum / stav</th></tr></thead>
                            <tbody id="phenology-series-rows"></tbody>
                        </table>
                    </div>
                </div></div>
            </section>
        </div>

        <section class="card mb-3"><div class="card-body">
            <h2 class="h6">Srovnání stanic ve společných letech</h2>
            <p id="phenology-comparison-summary" class="small text-body-secondary"></p>
            <div id="phenology-comparison-chart" class="chart phenology-chart" role="img"
                 aria-label="Srovnání obvyklého nástupu fenofáze podle výšky stanice"></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <caption class="visually-hidden">Medián nástupu a nadmořská výška porovnávaných stanic</caption>
                    <thead><tr><th scope="col">Stanice</th><th scope="col">Nadmořská výška</th><th scope="col">Medián společných let</th><th scope="col">Počet let</th></tr></thead>
                    <tbody id="phenology-comparison-rows"></tbody>
                </table>
            </div>
        </div></section>
    </div>

    <section class="small text-body-secondary">
        <h2 class="h6">Jak data číst a co znamenají</h2>
        <ul>
            <li><strong>Fenofáze</strong> je opakující se vývojová událost rostliny, například začátek kvetení nebo rašení.</li>
            <li><strong>„Nenastala“ (ČHMÚ kód 444)</strong> znamená, že daná fenofáze v daném roce nebyla pozorována. Není to datum ani nulová hodnota. „Bez záznamu“ označuje chybějící řádek. Rozporné duplicitní řádky se zobrazí zvlášť a nezapočítají do grafů ani mediánů.</li>
            <li><strong>Reprezentativnost pro Most:</strong> stanice nejsou v Mostě. Vzdálenost, nadmořská výška a místní podmínky mohou posouvat termíny; graf srovnává stanice jen v letech, kdy mají pozorovaný nástup všechny vybrané stanice.</li>
            <li><strong>Délka řady:</strong> dostupné roky se liší podle druhu, stanice i fáze. Zhruba 12 let není klimatický normál a nestačí k tvrzení o dlouhodobém trendu.</li>
            <li>Meziroční graf používá společnou osu 365 dní; přestupný den je vložen mezi 28. únor a 1. březen.</li>
        </ul>
        <p id="phenology-source" class="mb-0">
            Zdroj: <a href="https://opendata.chmi.cz/meteorology/phenology/" rel="noopener noreferrer">ČHMÚ – fenologická pozorování</a>.
            Původní veřejná data; hodnoty „nenastala“ a chybějící záznamy se zobrazují odděleně.
        </p>
    </section>
</div>
