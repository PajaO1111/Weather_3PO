<?php
/**
 * Stránka „Aktuálně“ – přehled posledního měření stanice.
 *
 * Obsahuje jen kostru; hodnoty doplní public/assets/js/dashboard.js podle
 * atributů data-field (cesta v JSON z api/current.php, např. „current.temp_c“)
 * a měřidla vykreslí public/assets/js/gauges.js (SVG).
 * Do doby načtení se zobrazuje „–“.
 *
 * Rozvržení: hlavička se stavem počasí, 8 widgetů (na mobilu pod sebou,
 * od 576 px po dvou, od 992 px po čtyřech), graf 24 h, dnešní souhrn a stanice.
 *
 * @var string $baseUrl
 * @var int    $seriesHours  délka okna grafu v hodinách
 * @var int    $chartGapMinutes  mezera, od které graf přeruší čáru
 */
use OuBrix\Weather\Http\View;
?>
<div id="dashboard"
     data-api-current="<?= View::e($baseUrl) ?>/api/current.php"
     data-api-series="<?= View::e($baseUrl) ?>/api/observations.php?hours=<?= (int) $seriesHours ?>"
     data-chart-gap-minutes="<?= (int) $chartGapMinutes ?>">

    <h1 class="visually-hidden">Aktuální počasí</h1>
    <div class="alert alert-danger d-none" id="error" role="alert"></div>

    <!-- Hlavička: stav počasí, teplota, stanice a aktuálnost dat -->
    <section class="wx-hero mb-3" id="hero" aria-label="Aktuální stav">
        <div class="wx-hero-main">
            <div class="wx-hero-icon" id="condition-icon" role="img" aria-label="Stav počasí"></div>
            <div>
                <p class="wx-hero-temp mb-0"><span data-field="current.temp_c" data-format="1">–</span><span class="wx-hero-unit">°C</span></p>
                <p class="wx-hero-condition mb-1"><span id="condition-label">–</span> <span class="wx-hero-hint" title="Stanice nemá čidlo oblačnosti – stav se odhaduje ze srážek, vlhkosti a slunečního záření.">(odhad)</span></p>
                <p class="mb-0">Pocitově <strong><span data-field="current.feels_like" data-format="1">–</span>&nbsp;°C</strong>
                    <span class="wx-hero-sep">·</span>
                    <span class="wx-min">↓ <span data-field="today.temp_min.value" data-format="1">–</span>&nbsp;°C</span>
                    <span class="wx-max">↑ <span data-field="today.temp_max.value" data-format="1">–</span>&nbsp;°C</span>
                </p>
            </div>
        </div>
        <div class="wx-hero-side" aria-live="polite">
            <p class="wx-hero-place mb-1"><span data-field="station.neighborhood">–</span> · <span data-field="station.id">–</span></p>
            <p class="mb-1 small">Měření <time data-field="observed.local" data-format="datetime">–</time></p>
            <p class="mb-2"><span class="wx-live" id="freshness">načítám…</span></p>
            <button type="button" class="btn btn-sm wx-hero-btn" id="refresh">↻ Obnovit</button>
        </div>
    </section>

    <!-- Widgety veličin -->
    <section class="wx-grid mb-3" aria-label="Veličiny">
        <article class="card wx-widget">
            <div class="card-body">
                <h2 class="wx-title">Teplota</h2>
                <div class="wx-gauge" id="gauge-temp" role="img">
                    <div class="wx-center">
                        <span class="wx-value" data-field="current.temp_c" data-format="1">–</span><span class="wx-unit">°C</span>
                        <span class="wx-sub">rosný bod <span data-field="current.dew_point" data-format="1">–</span>&nbsp;°C</span>
                    </div>
                </div>
                <dl class="wx-facts">
                    <div><dt>Dnes min</dt><dd><span data-field="today.temp_min.value" data-format="1">–</span>&nbsp;°C <time data-field="today.temp_min.time" data-format="time"></time></dd></div>
                    <div><dt>Dnes max</dt><dd><span data-field="today.temp_max.value" data-format="1">–</span>&nbsp;°C <time data-field="today.temp_max.time" data-format="time"></time></dd></div>
                </dl>
                <div class="wx-spark" id="spark-temp" role="img"></div>
            </div>
        </article>

        <article class="card wx-widget">
            <div class="card-body">
                <h2 class="wx-title">Vlhkost</h2>
                <div class="wx-gauge wx-gauge-ring" id="gauge-humidity" role="img">
                    <div class="wx-center">
                        <span class="wx-value" data-field="current.humidity" data-format="0">–</span><span class="wx-unit">%</span>
                        <span class="wx-sub">relativní</span>
                    </div>
                </div>
                <dl class="wx-facts">
                    <div><dt>Dnes min</dt><dd><span data-field="today.humidity_min" data-format="0">–</span>&nbsp;%</dd></div>
                    <div><dt>Dnes max</dt><dd><span data-field="today.humidity_max" data-format="0">–</span>&nbsp;%</dd></div>
                </dl>
                <div class="wx-spark" id="spark-humidity" role="img"></div>
            </div>
        </article>

        <article class="card wx-widget">
            <div class="card-body">
                <h2 class="wx-title">Vítr</h2>
                <div class="wx-gauge wx-gauge-ring" id="gauge-wind" role="img">
                    <div class="wx-center">
                        <span class="wx-value" data-field="current.wind_speed" data-format="1">–</span><span class="wx-unit">km/h</span>
                        <span class="wx-sub">z <span data-field="current.wind_direction">–</span> (<span data-field="current.winddir" data-format="0">–</span>°)</span>
                    </div>
                </div>
                <dl class="wx-facts">
                    <div><dt>Náraz</dt><dd><span data-field="current.wind_gust" data-format="1">–</span>&nbsp;km/h</dd></div>
                    <div><dt>Beaufort</dt><dd><span data-field="current.beaufort.force">–</span> · <span data-field="current.beaufort.label">–</span></dd></div>
                    <div><dt>Dnes náraz max</dt><dd><span data-field="today.gust_max.value" data-format="1">–</span>&nbsp;km/h <time data-field="today.gust_max.time" data-format="time"></time></dd></div>
                </dl>
                <div class="wx-spark" id="spark-wind" role="img"></div>
            </div>
        </article>

        <article class="card wx-widget">
            <div class="card-body">
                <h2 class="wx-title">Tlak</h2>
                <div class="wx-gauge" id="gauge-pressure" role="img">
                    <div class="wx-center wx-center-low">
                        <span class="wx-value" data-field="current.pressure" data-format="1">–</span><span class="wx-unit">hPa</span>
                        <span class="wx-sub" id="pressure-arrow">–</span>
                    </div>
                </div>
                <dl class="wx-facts">
                    <div><dt>Tendence</dt><dd id="pressure-trend">–</dd></div>
                    <div><dt>Dnes min / max</dt><dd><span data-field="today.pressure_min" data-format="1">–</span> / <span data-field="today.pressure_max" data-format="1">–</span></dd></div>
                </dl>
                <div class="wx-spark" id="spark-pressure" role="img"></div>
            </div>
        </article>

        <article class="card wx-widget">
            <div class="card-body">
                <h2 class="wx-title">Srážky dnes</h2>
                <div class="wx-rain">
                    <div class="wx-rain-tube" id="gauge-rain" role="img"></div>
                    <div>
                        <p class="mb-0"><span class="wx-value" data-field="today.rainCounter" data-format="1">–</span><span class="wx-unit">mm</span></p>
                        <p class="wx-sub mb-2">úhrn od půlnoci</p>
                        <p class="mb-0"><strong><span data-field="current.precip_rate" data-format="1">–</span></strong>&nbsp;mm/h</p>
                        <p class="wx-sub mb-0" data-field="current.precip_intensity">–</p>
                    </div>
                </div>
                <dl class="wx-facts">
                    <div><dt>Dnes max intenzita</dt><dd><span data-field="today.precip_rate_max" data-format="1">–</span>&nbsp;mm/h</dd></div>
                </dl>
                <div class="wx-spark" id="spark-rain" role="img"></div>
            </div>
        </article>

        <article class="card wx-widget">
            <div class="card-body">
                <h2 class="wx-title">UV index</h2>
                <p class="wx-uv-value mb-1"><span class="wx-value" data-field="current.uv" data-format="1">–</span>
                    <span class="wx-badge" id="uv-badge" data-field="current.uv_category">–</span></p>
                <div class="wx-uv-bar" id="gauge-uv" role="img"></div>
                <dl class="wx-facts">
                    <div><dt>Dnes max</dt><dd data-field="today.uv_max" data-format="1">–</dd></div>
                </dl>
                <div class="wx-spark" id="spark-uv" role="img"></div>
            </div>
        </article>

        <article class="card wx-widget">
            <div class="card-body">
                <h2 class="wx-title">Sluneční záření</h2>
                <div class="wx-gauge" id="gauge-solar" role="img">
                    <div class="wx-center">
                        <span class="wx-value" data-field="current.solar_radiation" data-format="0">–</span><span class="wx-unit">W/m²</span>
                        <span class="wx-sub">výška Slunce <span data-field="astro.sun.elevation" data-format="0">–</span>°</span>
                    </div>
                </div>
                <dl class="wx-facts">
                    <div><dt>Dnes max</dt><dd><span data-field="today.solar_max" data-format="0">–</span>&nbsp;W/m²</dd></div>
                </dl>
                <div class="wx-spark" id="spark-solar" role="img"></div>
            </div>
        </article>

        <article class="card wx-widget">
            <div class="card-body">
                <h2 class="wx-title">Slunce a Měsíc</h2>
                <div class="wx-sunpath" id="sun-path" role="img"></div>
                <dl class="wx-facts wx-facts-row">
                    <div><dt>Východ</dt><dd data-field="astro.sun.sunrise" data-format="time">–</dd></div>
                    <div><dt>Délka dne</dt><dd id="day-length">–</dd></div>
                    <div><dt>Západ</dt><dd data-field="astro.sun.sunset" data-format="time">–</dd></div>
                </dl>
                <div class="wx-moon">
                    <div class="wx-moon-icon" id="moon-icon" role="img"></div>
                    <div>
                        <p class="mb-0 fw-semibold" data-field="astro.moon.name">–</p>
                        <p class="mb-0 small">osvětleno <strong><span data-field="astro.moon.illumination" data-format="0">–</span>&nbsp;%</strong>,
                            <span id="moon-trend">–</span></p>
                        <p class="mb-0 small text-body-secondary">úplněk <span data-field="astro.moon.nextFull" data-format="date">–</span> · nov <span data-field="astro.moon.nextNew" data-format="date">–</span></p>
                    </div>
                </div>
            </div>
        </article>
    </section>

    <!-- Graf posledních 24 h -->
    <section class="card mb-3" aria-labelledby="chart-title">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <h2 class="h6 mb-0 me-auto" id="chart-title">Posledních 24 hodin</h2>
                <div class="btn-group btn-group-sm" role="group" aria-label="Veličina v grafu" id="chart-metrics">
                    <button type="button" class="btn btn-outline-secondary active" data-metric="temperature" aria-pressed="true">Teplota</button>
                    <button type="button" class="btn btn-outline-secondary" data-metric="pressure" aria-pressed="false">Tlak</button>
                    <button type="button" class="btn btn-outline-secondary" data-metric="wind" aria-pressed="false">Vítr</button>
                    <button type="button" class="btn btn-outline-secondary" data-metric="rain" aria-pressed="false">Srážky</button>
                </div>
            </div>
            <div id="chart" class="chart" role="img" aria-label="Graf měření za posledních 24 hodin"></div>
            <p class="small text-body-secondary mb-0" id="chart-note"></p>
        </div>
    </section>

    <!-- Dnešní souhrn a informace o stanici -->
    <div class="row g-3">
        <section class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6">Dnešní souhrn <span class="text-body-secondary fw-normal" data-field="today.date" data-format="date"></span></h2>
                    <table class="table table-sm mb-0">
                        <tbody>
                        <tr><th scope="row" class="fw-normal">Průměrná teplota</th><td class="text-end"><span data-field="today.temp_avg" data-format="1">–</span> °C</td></tr>
                        <tr><th scope="row" class="fw-normal">Tlak min / max</th><td class="text-end"><span data-field="today.pressure_min" data-format="1">–</span> / <span data-field="today.pressure_max" data-format="1">–</span> hPa</td></tr>
                        <tr><th scope="row" class="fw-normal">Nejvyšší průměrný vítr</th><td class="text-end"><span data-field="today.wind_max" data-format="1">–</span> km/h</td></tr>
                        <tr><th scope="row" class="fw-normal">Nejsilnější náraz</th><td class="text-end"><span data-field="today.gust_max.value" data-format="1">–</span> km/h <time class="text-body-secondary" data-field="today.gust_max.time" data-format="time"></time></td></tr>
                        <tr><th scope="row" class="fw-normal">Max. intenzita srážek</th><td class="text-end"><span data-field="today.precip_rate_max" data-format="1">–</span> mm/h</td></tr>
                        <tr><th scope="row" class="fw-normal">Počet měření</th><td class="text-end"><span data-field="today.samples" data-format="0">–</span> <span class="text-body-secondary">(průměrně po <span data-field="today.averageIntervalMinutes" data-format="0">–</span> min)</span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6">Stanice</h2>
                    <table class="table table-sm mb-0">
                        <tbody>
                        <tr><th scope="row" class="fw-normal">ID (Weather Underground)</th><td class="text-end" data-field="station.id">–</td></tr>
                        <tr><th scope="row" class="fw-normal">Lokalita</th><td class="text-end" data-field="station.neighborhood">–</td></tr>
                        <tr><th scope="row" class="fw-normal">Souřadnice</th><td class="text-end"><span data-field="station.lat" data-format="4">–</span>° N, <span data-field="station.lon" data-format="4">–</span>° E</td></tr>
                        <tr><th scope="row" class="fw-normal">Nadmořská výška</th><td class="text-end"><span data-field="station.elevation" data-format="0">–</span> m</td></tr>
                        <tr><th scope="row" class="fw-normal">Zápis dat</th><td class="text-end">CRON, každých 5 min</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>
