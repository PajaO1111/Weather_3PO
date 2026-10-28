<?php
/**
 * Aktuální hodinové hodnoty kvality ovzduší ČHMÚ pro Most (UMOM).
 *
 * @var string $baseUrl
 */
use OuBrix\Weather\Http\View;
?>
<div id="air-quality" data-api-air-quality="<?= View::e($baseUrl) ?>/api/air-quality.php" aria-busy="true">
    <section class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0 me-2">Kvalita ovzduší ČHMÚ</h1>
        <span class="badge text-bg-secondary" id="air-quality-station">Most · UMOM</span>
        <span class="text-body-secondary small" id="air-quality-location"></span>
    </section>
    <div class="nav nav-tabs mb-3" role="tablist" aria-label="Sekce kvality ovzduší">
        <button class="nav-link active" id="air-quality-live-tab" type="button" role="tab"
                aria-selected="true" aria-controls="air-quality-live-panel" tabindex="0">
            Měření a předpověď
        </button>
        <button class="nav-link" id="air-quality-history-tab" type="button" role="tab"
                aria-selected="false" aria-controls="air-quality-history-panel" tabindex="-1">
            Historie SVRS
        </button>
        <button class="nav-link" id="air-quality-map-tab" type="button" role="tab"
                aria-selected="false" aria-controls="air-quality-map-panel" tabindex="-1">
            Mapa ovzduší
        </button>
    </div>
    <div id="air-quality-live-panel" role="tabpanel" aria-labelledby="air-quality-live-tab" tabindex="0">

    <div class="alert alert-danger d-none" id="air-quality-error" role="alert"></div>
    <div class="alert alert-warning d-none" id="air-quality-warning" role="status"></div>
    <p class="text-body-secondary small d-none" id="air-quality-loading" role="status">Načítám data ČHMÚ…    </p>

    <section class="card mb-3" aria-labelledby="air-quality-comparison-title">
        <div class="card-body">
            <h2 class="h5" id="air-quality-comparison-title">Srovnání s blízkou stanicí ČHMÚ</h2>
            <p class="small text-body-secondary">
                Zobrazí se jen společně měřené látky se stejnou jednotkou a intervalem. Rozdíl
                se počítá pouze při shodném čase měření; nejde o korekci polohy ani hodnocení
                reprezentativnosti stanic.
            </p>
            <label class="form-label" for="air-quality-comparison-station">Srovnávací stanice</label>
            <select class="form-select mb-3" id="air-quality-comparison-station" disabled>
                <option value="">Načítám blízké stanice…</option>
            </select>
            <p class="small text-body-secondary" id="air-quality-comparison-status" aria-live="polite"></p>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <caption>Hodnoty jsou v uvedených časech; rozdíl je UMOM minus zvolená stanice.</caption>
                    <thead><tr>
                        <th scope="col">Látka</th>
                        <th scope="col">UMOM</th>
                        <th scope="col">Srovnávací stanice</th>
                        <th scope="col">Rozdíl</th>
                    </tr></thead>
                    <tbody id="air-quality-comparison-rows"></tbody>
                </table>
            </div>
        </div>
    </section>

    <p class="text-body-secondary">
        Hodinová měření stanice ČHMÚ Most (UMOM). Zobrazují se pouze hodnoty s ověřeným
        nebo operativním typem; chybové kódy nejsou vykresleny jako koncentrace.
        Při chybě nebo chybějícím měření se zobrazí poslední uložená platná hodnota
        s původním časem a upozorněním na její stáří, pokud je dostupná.
    </p>

    <section class="row g-3 mb-3" id="air-quality-measurements" aria-label="Měřené znečišťující látky">
<?php foreach ([
    'PM10' => 'PM10',
    'PM2_5' => 'PM2,5',
    'NO2' => 'NO₂',
    'SO2' => 'SO₂',
    'O3' => 'O₃',
] as $component => $label): ?>
        <div class="col-12 col-sm-6 col-lg">
            <article class="card h-100 kpi" data-measurement="<?= View::e($component) ?>">
                <div class="card-body">
                    <h2 class="kpi-label"><?= View::e($label) ?></h2>
                    <p class="kpi-value"><span data-value>–</span><small data-unit> µg/m³</small></p>
                    <p class="kpi-note" data-status>Čeká na načtení dat.</p>
                    <p class="kpi-note mb-0" data-observed></p>
                </div>
            </article>
        </div>
<?php endforeach; ?>
    </section>

    <section class="card mb-3" id="air-quality-forecast"
             data-api="<?= View::e($baseUrl) ?>/api/air-quality-forecast.php" aria-busy="true">
        <div class="card-body">
            <h2 class="h5">Modelová předpověď pro ORP Most</h2>
            <p class="text-body-secondary">
                Jde o plošný modelový odhad pro správní obvod, nikoli o měření stanice UMOM
                ani předpověď přesně pro její polohu.
            </p>
            <div class="row g-3 align-items-end mb-3">
                <div class="col-12 col-sm-6 col-lg-4">
                    <label class="form-label" for="air-quality-forecast-pollutant">Sledovaná látka</label>
                    <select class="form-select" id="air-quality-forecast-pollutant">
                        <option value="PM10">PM10</option>
                        <option value="PM2_5">PM2,5</option>
                        <option value="NO2">NO₂</option>
                        <option value="O3">O₃</option>
                        <option value="SO2">SO₂</option>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-4">
                    <label class="form-label" for="air-quality-forecast-horizon">Zobrazený výhled</label>
                    <select class="form-select" id="air-quality-forecast-horizon">
                        <option value="24">Následujících 24 hodin</option>
                        <option value="48">Následujících 48 hodin</option>
                        <option value="72" selected>Celý výhled (až 72 hodin)</option>
                    </select>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="" id="air-quality-forecast-range" checked>
                        <label class="form-check-label" for="air-quality-forecast-range">
                            Zobrazit pásmo 25.–75. percentilu
                        </label>
                    </div>
                </div>
            </div>
            <div class="alert alert-danger d-none" id="air-quality-forecast-error" role="alert"></div>
            <div class="alert alert-warning d-none" id="air-quality-forecast-warning" role="status"></div>
            <p class="text-body-secondary small d-none" id="air-quality-forecast-loading" role="status">Načítám předpověď ČHMÚ…</p>
            <p class="small text-body-secondary" id="air-quality-forecast-meta"></p>
            <div class="air-quality-forecast-chart" id="air-quality-forecast-chart"
                 role="img" aria-label="Hodinová modelová předpověď koncentrace pro ORP Most"></div>
            <p class="small mb-0" id="air-quality-forecast-map"></p>
            <p class="small text-body-secondary mt-2 mb-0">
                P50 je medián; pásmo ukazuje P25–P75 a minimum s maximem najdete v popisku bodu.
                ČHMÚ schéma uvádí percentily, ale nepopisuje jejich statistický základ, proto pásmo
                není označeno jako pravděpodobnost ani jako interval nejistoty. Časy jsou převedeny
                z UTC na místní čas. Jednotka v metadatech předpovědi není uvedena.
            </p>
        </div>
    </section>

    <section class="card mb-3" aria-labelledby="air-quality-index-title">
        <div class="card-body">
            <h2 class="h6 mb-1" id="air-quality-index-title">Index kvality ovzduší</h2>
            <p class="mb-0" id="air-quality-index">Ověřuji dostupnost indexu pro stanici UMOM…</p>
            <p class="small text-body-secondary mb-0" id="air-quality-index-time"></p>
        </div>
    </section>

    <p class="small text-body-secondary mb-1" id="air-quality-updated"></p>
    <p class="small text-body-secondary mb-0">
        Zdroj:
        <a href="https://opendata.chmi.cz/air_quality/now/" target="_blank" rel="noopener noreferrer">OpenData ČHMÚ – kvalita ovzduší</a>.
        Hodinové hodnoty představují měření na stanici Most, nikoli plošný odhad pro celé město.
    </p>

    <section class="mt-4" aria-labelledby="air-quality-explanation-title">
        <h2 class="h5" id="air-quality-explanation-title">Jak číst zobrazená data</h2>
        <p>
            Horní karty jsou hodinová měření ze stanice ČHMÚ Most (UMOM). Graf níže čerpá z jiného
            datasetu: jde o modelovou předpověď a prostorový odhad pro celé ORP Most. Tyto dva druhy
            dat se proto nesmějí zaměňovat ani přímo porovnávat jako rovnocenná měření.
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th scope="col">Látka</th>
                        <th scope="col">Co představuje</th>
                        <th scope="col">Obvyklé zdroje</th>
                        <th scope="col">Možné zdravotní souvislosti</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row">PM10</th>
                        <td>Částice s aerodynamickým průměrem do 10 µm.</td>
                        <td>Prach, doprava a otěr, průmysl, spalování a stavební činnost.</td>
                        <td>Mohou dráždit dýchací cesty a zhoršovat respirační obtíže.</td>
                    </tr>
                    <tr>
                        <th scope="row">PM2,5</th>
                        <td>Jemné částice s aerodynamickým průměrem do 2,5 µm.</td>
                        <td>Spalování, vytápění, doprava a průmyslové procesy.</td>
                        <td>Mohou pronikat hlouběji do plic; dlouhodobá expozice souvisí s respiračními a srdečně-cévními dopady.</td>
                    </tr>
                    <tr>
                        <th scope="row">NO₂</th>
                        <td>Oxid dusičitý, plynná znečišťující látka.</td>
                        <td>Spalování paliv, zejména silniční doprava a energetika.</td>
                        <td>Dráždí dýchací cesty a může zhoršovat astma.</td>
                    </tr>
                    <tr>
                        <th scope="row">O₃</th>
                        <td>Přízemní ozon, který vzniká druhotně chemickými reakcemi v ovzduší.</td>
                        <td>Vzniká působením slunečního záření na oxidy dusíku a těkavé organické látky.</td>
                        <td>Může zhoršovat dýchací obtíže a ovlivnit plicní funkce.</td>
                    </tr>
                    <tr>
                        <th scope="row">SO₂</th>
                        <td>Oxid siřičitý, plynná znečišťující látka.</td>
                        <td>Spalování paliv obsahujících síru a některé průmyslové procesy.</td>
                        <td>Dráždí dýchací cesty a může vyvolat jejich stažení, zvláště u citlivých osob.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="small text-body-secondary mb-0">
            Zdroje v tabulce jsou obecné, neurčují původ znečištění v ORP Most.
            Zdravotní účinky jsou obecné informace, nikoli zdravotní doporučení ani hodnocení aktuálního rizika.
            Další informace:
            <a href="https://iris.who.int/handle/10665/345334" target="_blank" rel="noopener noreferrer">
                doporučení WHO pro kvalitu ovzduší
            </a>.
            Obrazové srovnání velikosti částic nebylo převzato, protože nebyla ověřena licence.
        </p>
    </section>
    </div>
    <section id="air-quality-history-panel" role="tabpanel" aria-labelledby="air-quality-history-tab"
             tabindex="0" hidden data-api="<?= View::e($baseUrl) ?>/api/air-quality-history.php">
        <h2 class="h5">Historická vyhlášení SVRS – Ústecký kraj</h2>
        <p class="text-body-secondary">
            Jde o úřední vyhlášení pro kraj, nikoli o záznam lokální koncentrace nebo současnou
            výstrahu CAP. Smogová situace, regulace a varování jsou samostatné události; jejich
            časové překryvy se nesčítají jako nezávislé případy.
        </p>
        <div class="row g-2 mb-3">
            <div class="col-12 col-sm-4">
                <label class="form-label" for="air-quality-history-year">Rok</label>
                <select class="form-select" id="air-quality-history-year"><option value="">Všechny roky</option></select>
            </div>
            <div class="col-12 col-sm-4">
                <label class="form-label" for="air-quality-history-pollutant">Látka</label>
                <select class="form-select" id="air-quality-history-pollutant">
                    <option value="">Všechny látky</option>
                    <option value="PM10">PM10</option><option value="O3">O₃</option>
                    <option value="SO2">SO₂</option><option value="NO2">NO₂</option>
                </select>
            </div>
            <div class="col-12 col-sm-4">
                <label class="form-label" for="air-quality-history-type">Typ události</label>
                <select class="form-select" id="air-quality-history-type">
                    <option value="">Všechny typy</option>
                    <option value="smogová situace">Smogová situace</option>
                    <option value="regulace">Regulace</option>
                    <option value="varování">Varování</option>
                </select>
            </div>
        </div>
        <div class="alert alert-danger d-none" id="air-quality-history-error" role="alert"></div>
        <div class="alert alert-warning d-none" id="air-quality-history-warning" role="status"></div>
        <p class="small text-body-secondary" id="air-quality-history-source"></p>
        <p class="small" id="air-quality-history-count" aria-live="polite"></p>
        <p class="small text-body-secondary">
            Délka vyhlášení v hodinách. Každý pruh představuje samostatnou událost ČHMÚ;
            překrývající se události se nesčítají.
        </p>
        <div class="svrs-duration-chart" id="air-quality-history-chart" role="img"
             aria-label="Délka historických vyhlášení SVRS v hodinách"></div>
        <details class="mb-3">
            <summary>Podrobnosti událostí</summary>
            <ol class="svrs-timeline mt-3" id="air-quality-history-events"></ol>
        </details>
        <p class="small text-body-secondary">
            Zdroj: <a href="https://opendata.chmi.cz/air_quality/historical/smog/historicky_prehled_smogovych_situaci.csv"
                      target="_blank" rel="noopener noreferrer">historický přehled ČHMÚ (CSV)</a>,
            licence <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank"
                       rel="noopener noreferrer">CC BY 4.0</a>;
            <a href="https://ovzdusi.chmi.cz/SVRS/historie.php" target="_blank" rel="noopener noreferrer">
                metodika a právní změny SVRS
            </a>.
            Podle ČHMÚ se pravidla měnila v letech 2012, 2017 a 2025; události z různých období
            proto nelze bez další metodiky považovat za přímo srovnatelné.
        </p>
    </section>
    <section id="air-quality-map-panel" role="tabpanel" aria-labelledby="air-quality-map-tab"
             tabindex="0" hidden data-api="<?= View::e($baseUrl) ?>/api/air-quality-map.php">
        <h2 class="h5">Operativní mapa kvality ovzduší ČHMÚ</h2>
        <p class="text-body-secondary">
            Plošná mapa vytvořená z operativních měření stanic a dalších podkladů; nejde o přímé měření
            v každém místě ani o předpověď. Barvy označují intervaly z oficiální legendy ČHMÚ.
            Mapa nepodporuje přesné číselné odečítání v bodě.
        </p>
        <div class="row g-2 align-items-end mb-3">
            <div class="col-12 col-sm-6">
                <label class="form-label" for="air-quality-map-component">Látka</label>
                <select class="form-select" id="air-quality-map-component">
                    <option value="PM10">PM10</option>
                    <option value="PM2_5">PM2,5</option>
                    <option value="NO2">NO₂</option>
                    <option value="O3">O₃</option>
                    <option value="SO2">SO₂</option>
                </select>
            </div>
            <div class="col-12 col-sm-6">
                <label class="form-label" for="air-quality-map-type">Období / agregace</label>
                <select class="form-select" id="air-quality-map-type">
                    <option value="hours">Hodinový průměr</option>
                </select>
            </div>
        </div>
        <div class="alert alert-danger d-none" id="air-quality-map-error" role="alert"></div>
        <div class="alert alert-warning d-none" id="air-quality-map-warning" role="status"></div>
        <p class="small text-body-secondary" id="air-quality-map-status" role="status">Mapa se načte při otevření této záložky.</p>
        <label class="form-label" for="air-quality-map-opacity">
            Krytí vrstvy: <output id="air-quality-map-opacity-value">75 %</output>
        </label>
        <input class="form-range" id="air-quality-map-opacity" type="range" min="0" max="100" step="5" value="75">
        <div id="air-quality-map" class="air-quality-map border rounded mb-3"
             role="region" aria-label="Operativní mapa kvality ovzduší ČHMÚ pro Českou republiku"></div>
        <section class="mb-3" aria-labelledby="air-quality-map-legend-title">
            <h3 class="h6" id="air-quality-map-legend-title">Legenda ČHMÚ</h3>
            <p class="small text-body-secondary" id="air-quality-map-legend-description"></p>
            <ul class="air-quality-map-legend list-unstyled d-flex flex-wrap gap-3 mb-0"
                id="air-quality-map-legend" aria-label="Barevná legenda mapy"></ul>
        </section>
        <p class="small text-body-secondary" id="air-quality-map-source"></p>
        <p class="small text-body-secondary">
            Statická metadata uvádějí krok vstupního gridu 100 m, samotné PNG má nižší rozlišení.
            Rozlišení zobrazeného rastru a jeho rozsah jsou uvedeny u zdroje; nejde o přesnost měření.
            Zdroj: <a href="https://opendata.chmi.cz/air_quality/now/maps/" target="_blank" rel="noopener noreferrer">
                operativní mapy kvality ovzduší ČHMÚ
            </a>, licence <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank"
                            rel="noopener noreferrer">CC BY 4.0</a>.
            Podklad OpenStreetMap předává při načítání mapy IP adresu provozovateli dlaždic.
        </p>
    </section>
</div>
