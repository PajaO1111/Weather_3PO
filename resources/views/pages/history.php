<?php
/**
 * Stránka „Historie“ – měření za zvolené období.
 *
 * Kostra: formulář období, souhrn, grafy, tabulka. Hodnoty doplní
 * public/assets/js/history.js z api/history.php (prvky s data-field
 * = cesta v JSON, např. „summary.temp_max.value“). Formulář funguje
 * i bez JavaScriptu (GET na history.php), JS jen načítá data bez obnovení stránky.
 *
 * @var string               $baseUrl
 * @var string               $from        RRRR-MM-DD
 * @var string               $to          RRRR-MM-DD
 * @var string               $resolution  auto | raw | hour | day
 * @var string               $today       dnešní místní datum RRRR-MM-DD
 * @var string|null          $notice      upozornění na neplatné parametry v URL
 * @var array<string, int>   $limits      limity období z config/app.php
 * @var int                  $chartGapMinutes
 */
use OuBrix\Weather\Http\View;

$resolutions = [
    'auto' => 'Automaticky',
    'raw' => 'Po 5 minutách',
    'hour' => 'Po hodinách',
    'day' => 'Po dnech',
];
$presets = [
    'today' => 'Dnes',
    'yesterday' => 'Včera',
    '7d' => '7 dní',
    '30d' => '30 dní',
    'month' => 'Tento měsíc',
    'prev-month' => 'Minulý měsíc',
    'year' => 'Tento rok',
    '365d' => '365 dní',
];
// Grafy: id => [nadpis, popis pro čtečky].
$charts = [
    'temperature' => ['Teplota a rosný bod', 'Graf teploty'],
    'humidity' => ['Relativní vlhkost', 'Graf vlhkosti'],
    'pressure' => ['Tlak vzduchu', 'Graf tlaku'],
    'wind' => ['Vítr', 'Graf rychlosti větru a nárazů'],
    'rain' => ['Srážky', 'Graf srážek'],
    'sun' => ['Sluneční záření a UV index', 'Graf slunečního záření'],
];
?>
<div id="history"
     data-api-history="<?= View::e($baseUrl) ?>/api/history.php"
     data-page-url="<?= View::e($baseUrl) ?>/history.php"
     data-today="<?= View::e($today) ?>"
     data-limits="<?= View::e(json_encode($limits)) ?>"
     data-chart-gap-minutes="<?= (int) $chartGapMinutes ?>">

    <section class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0 me-2">Historie měření</h1>
        <span class="badge text-bg-secondary" data-field="station">–</span>
        <span class="text-body-secondary small" id="range-label" aria-live="polite"></span>
    </section>

<?php if ($notice !== null): ?>
    <div class="alert alert-warning" role="alert"><?= View::e($notice) ?></div>
<?php endif; ?>

    <!-- Výběr období (bez JS odešle GET a stránka se načte znovu) -->
    <form class="card mb-3" id="history-form" method="get" action="<?= View::e($baseUrl) ?>/history.php">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-1 mb-3" role="group" aria-label="Rychlá volba období" id="presets">
<?php foreach ($presets as $key => $label): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="<?= View::e($key) ?>"><?= View::e($label) ?></button>
<?php endforeach; ?>
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="from">Od</label>
                    <input class="form-control" type="date" id="from" name="from" required
                           value="<?= View::e($from) ?>" max="<?= View::e($today) ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="to">Do (včetně)</label>
                    <input class="form-control" type="date" id="to" name="to" required
                           value="<?= View::e($to) ?>" max="<?= View::e($today) ?>">
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label small mb-1" for="resolution">Podrobnost</label>
                    <select class="form-select" id="resolution" name="resolution">
<?php foreach ($resolutions as $key => $label): ?>
                        <option value="<?= View::e($key) ?>"<?= $key === $resolution ? ' selected' : '' ?>><?= View::e($label) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill" id="submit">Zobrazit</button>
                    <a class="btn btn-outline-secondary" id="csv" download
                       href="<?= View::e($baseUrl) ?>/api/history.php?<?= View::e(http_build_query(['from' => $from, 'to' => $to, 'resolution' => $resolution, 'format' => 'csv'])) ?>"
                       title="Stáhnout data zvoleného období pro Excel">CSV</a>
                </div>
            </div>
            <p class="form-text mb-0">
                Po 5 minutách nejvýš <?= (int) $limits['rawMaxDays'] ?> dní, po hodinách <?= (int) $limits['hourMaxDays'] ?> dní,
                po dnech <?= (int) $limits['maxDays'] ?> dní. „Automaticky“ volí podle délky období.
            </p>
        </div>
    </form>

    <div class="alert alert-danger d-none" id="error" role="alert"></div>
    <p class="text-body-secondary small d-none" id="loading" role="status">Načítám data…</p>

    <!-- Souhrn období -->
    <section class="row g-3 mb-3" aria-label="Souhrn období" id="summary">
        <div class="col-6 col-lg-3">
            <div class="card h-100 kpi"><div class="card-body">
                <h2 class="kpi-label">Teplota</h2>
                <p class="kpi-value"><span data-field="summary.temp_avg" data-format="1">–</span><small> °C průměr</small></p>
                <p class="kpi-note">max <strong class="text-danger-emphasis"><span data-field="summary.temp_max.value" data-format="1">–</span> °C</strong>
                    <time data-field="summary.temp_max.time" data-format="datetime"></time></p>
                <p class="kpi-note">min <strong class="text-info-emphasis"><span data-field="summary.temp_min.value" data-format="1">–</span> °C</strong>
                    <time data-field="summary.temp_min.time" data-format="datetime"></time></p>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100 kpi"><div class="card-body">
                <h2 class="kpi-label">Srážky</h2>
                <p class="kpi-value"><span data-field="summary.precip_sum" data-format="1">–</span><small> mm celkem</small></p>
                <p class="kpi-note">nejvíc <span data-field="summary.precip_wettest_day.value" data-format="1">–</span> mm
                    <time data-field="summary.precip_wettest_day.date" data-format="date"></time></p>
                <p class="kpi-note">max. intenzita <span data-field="summary.precip_rate_max" data-format="1">–</span> mm/h</p>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100 kpi"><div class="card-body">
                <h2 class="kpi-label">Vítr</h2>
                <p class="kpi-value"><span data-field="summary.gust_max.value" data-format="1">–</span><small> km/h náraz</small></p>
                <p class="kpi-note"><time data-field="summary.gust_max.time" data-format="datetime"></time></p>
                <p class="kpi-note">max. průměrný vítr <span data-field="summary.wind_max" data-format="1">–</span> km/h</p>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100 kpi"><div class="card-body">
                <h2 class="kpi-label">Tlak · vlhkost · slunce</h2>
                <p class="kpi-note">tlak <span data-field="summary.pressure_min" data-format="1">–</span> – <span data-field="summary.pressure_max" data-format="1">–</span> hPa</p>
                <p class="kpi-note">vlhkost <span data-field="summary.humidity_min" data-format="0">–</span> – <span data-field="summary.humidity_max" data-format="0">–</span> %</p>
                <p class="kpi-note">UV max <span data-field="summary.uv_max" data-format="1">–</span>, záření max <span data-field="summary.solar_max" data-format="0">–</span> W/m²</p>
                <p class="kpi-note"><span data-field="summary.samples" data-format="0">–</span> měření, dnů s daty <span data-field="summary.days.withData" data-format="0">–</span></p>
            </div></div>
        </div>
        <div class="col-12">
            <div class="card"><div class="card-body py-2">
                <h2 class="visually-hidden">Klimatologické dny</h2>
                <ul class="list-inline mb-0 small climate-days">
                    <li class="list-inline-item" title="Tmin pod 0 °C">Mrazové dny <strong data-field="summary.days.frost" data-format="0">–</strong></li>
                    <li class="list-inline-item" title="Tmax pod 0 °C">Ledové dny <strong data-field="summary.days.ice" data-format="0">–</strong></li>
                    <li class="list-inline-item" title="Tmax alespoň 25 °C">Letní dny <strong data-field="summary.days.summer" data-format="0">–</strong></li>
                    <li class="list-inline-item" title="Tmax alespoň 30 °C">Tropické dny <strong data-field="summary.days.tropical" data-format="0">–</strong></li>
                    <li class="list-inline-item" title="Tmin alespoň 20 °C">Tropické noci <strong data-field="summary.days.tropicalNight" data-format="0">–</strong></li>
                    <li class="list-inline-item" title="Úhrn alespoň 1 mm">Srážkové dny <strong data-field="summary.days.rain" data-format="0">–</strong></li>
                </ul>
            </div></div>
        </div>
    </section>

    <!-- Grafy (přiblížení kolečkem/tažením se přenáší na všechny) -->
    <section class="row g-3 mb-3" aria-label="Grafy">
<?php foreach ($charts as $id => $chart): ?>
        <div class="col-12 col-xxl-6">
            <div class="card h-100"><div class="card-body">
                <h2 class="h6"><?= View::e($chart[0]) ?></h2>
                <div class="chart chart-history" id="chart-<?= View::e($id) ?>" data-chart="<?= View::e($id) ?>" role="img" aria-label="<?= View::e($chart[1]) ?>"></div>
            </div></div>
        </div>
<?php endforeach; ?>
        <p class="small text-body-secondary mb-0" id="chart-note"></p>
    </section>

    <!-- Tabulka hodnot se vykreslí až po rozbalení (velký počet řádků) -->
    <details class="card mb-3" id="table-details">
        <summary class="card-header">Tabulka hodnot</summary>
        <div class="card-body">
            <p class="small text-body-secondary" id="table-note"></p>
            <div class="table-responsive history-table">
                <table class="table table-sm table-striped table-hover mb-0" id="data-table"></table>
            </div>
        </div>
    </details>
</div>
