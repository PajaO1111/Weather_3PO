<?php
use OuBrix\Weather\Http\View;
?>
<div id="radar" data-api="<?= View::e($baseUrl) ?>/api/radar.php" aria-busy="true">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">Radar ČHMÚ</h1>
        <button id="radar-reload" type="button" class="btn btn-outline-secondary btn-sm">Znovu načíst</button>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-sm-6">
            <label for="radar-product" class="form-label">Produkt</label>
            <select id="radar-product" class="form-select">
                <option value="maxz">MAX_Z_MASK – radarová odrazivost</option>
                <option value="merge">MERGE – hodinový odhad srážek</option>
            </select>
        </div>
        <div class="col-sm-6">
            <label for="radar-opacity" class="form-label">Krytí radarové vrstvy: <output id="radar-opacity-value">70 %</output></label>
            <input id="radar-opacity" class="form-range" type="range" min="0" max="100" step="5" value="70">
            <button id="radar-home" type="button" class="btn btn-outline-secondary btn-sm">Most a okolí</button>
            <button id="radar-country" type="button" class="btn btn-outline-secondary btn-sm">Celá datová oblast</button>
        </div>
    </div>
    <div id="radar-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="radar-warning" class="alert alert-warning d-none" role="status"></div>
    <div id="radar-map-error" class="alert alert-warning d-none" role="status"></div>
    <p id="radar-loading" role="status">Načítám radar ČHMÚ…</p>
    <p id="radar-time" class="fw-semibold" role="status"></p>
    <p id="radar-description" class="text-body-secondary"></p>
    <div id="radar-map" class="radar-map border rounded mb-3" role="region" aria-label="Mapa radaru ČHMÚ s podkladem OpenStreetMap"></div>
    <div id="radar-legend-panel" class="d-none mb-3">
        <h2 class="h6">Oficiální legenda ČHMÚ</h2>
        <img id="radar-legend" width="56" height="236" alt="">
        <p id="radar-legend-note" class="small text-body-secondary"></p>
    </div>
    <p id="radar-source" class="small text-body-secondary forecast-text"></p>
    <p class="small text-body-secondary">
        Barevné PNG je vrstva nad mapovým podkladem. Průhledné místo není zárukou nulových srážek;
        šedá či chybějící data nelze vykládat jako sucho. Značky OuBrix a Kopisty jsou orientační polohy stanic.
        Časy jsou v Europe/Prague. První verze zobrazuje pouze nejnovější dostupný snímek, bez animace
        a bez číselných hodnot v bodě. Tlačítko načítá stav aplikace, neobchází cache.
        Mapový podklad se načítá z OpenStreetMap a při jeho použití se provozovateli map předává IP adresa.
    </p>
    <p class="small">
        Zdroj dat: <a href="https://opendata.chmi.cz/meteorology/weather/radar/">ČHMÚ OpenData</a> ·
        <a href="https://opendata.chmi.cz/meteorology/weather/radar/radar_popis_cz.pdf">Dokumentace produktů a georeference</a>.
        Radar nenahrazuje <a href="<?= View::e($baseUrl) ?>/warnings.php">oficiální výstrahy</a>.
    </p>
    <noscript><p class="alert alert-warning">Interaktivní mapa vyžaduje JavaScript.</p></noscript>
</div>
