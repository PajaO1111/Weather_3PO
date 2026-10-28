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

    <div class="alert alert-danger d-none" id="air-quality-error" role="alert"></div>
    <div class="alert alert-warning d-none" id="air-quality-warning" role="status"></div>
    <p class="text-body-secondary small d-none" id="air-quality-loading" role="status">Načítám data ČHMÚ…</p>

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
