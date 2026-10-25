<?php
/**
 * Stránka „Statistiky a rekordy“ – souhrny po měsících (rok) nebo po letech (celé období).
 *
 * Kostra: výběr roku, souhrn, graf, tabulka, žebříčky rekordů. Hodnoty doplní
 * public/assets/js/stats.js z api/stats.php (prvky s data-field = cesta v JSON).
 * Výběr roku funguje i bez JavaScriptu (GET na stats.php).
 *
 * @var string      $baseUrl
 * @var string      $period       „RRRR“ nebo „all“
 * @var int         $currentYear
 * @var string|null $notice
 * @var string      $timezone     časové pásmo stanice (paprskový graf 7 dní)
 */
use OuBrix\Weather\Http\View;

// Žebříčky: klíč v records => [nadpis, jednotka, desetinná místa, popis].
$records = [
    'temp_max_high' => ['Nejteplejší dny', '°C', 1, 'nejvyšší denní maximum'],
    'temp_min_low' => ['Nejchladnější noci', '°C', 1, 'nejnižší denní minimum'],
    'temp_avg_high' => ['Nejteplejší průměr dne', '°C', 1, 'nejvyšší průměrná denní teplota'],
    'temp_avg_low' => ['Nejchladnější průměr dne', '°C', 1, 'nejnižší průměrná denní teplota'],
    'temp_min_high' => ['Nejteplejší noci', '°C', 1, 'nejvyšší denní minimum'],
    'temp_max_low' => ['Nejchladnější dny', '°C', 1, 'nejnižší denní maximum'],
    'precip_high' => ['Nejvíc srážek za den', 'mm', 1, 'denní úhrn'],
    'gust_high' => ['Nejsilnější nárazy větru', 'km/h', 1, 'nejsilnější náraz dne'],
    'range_high' => ['Největší denní rozdíl teplot', '°C', 1, 'maximum − minimum'],
];
$selected = $period === 'all' ? 'all' : (string) $period;
?>
<div id="stats"
     data-api-stats="<?= View::e($baseUrl) ?>/api/stats.php"
     data-page-url="<?= View::e($baseUrl) ?>/stats.php"
     data-history-url="<?= View::e($baseUrl) ?>/history.php">

    <section class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0 me-2">Statistiky a rekordy</h1>
        <span class="badge text-bg-secondary" data-field="station">–</span>
        <span class="text-body-secondary small" id="range-label" aria-live="polite"></span>
    </section>

<?php if ($notice !== null): ?>
    <div class="alert alert-warning" role="alert"><?= View::e($notice) ?></div>
<?php endif; ?>

    <!-- Výběr období (bez JS odešle GET; JS doplní roky s daty a načítá bez obnovení stránky) -->
    <form class="d-flex flex-wrap align-items-end gap-2 mb-3" id="stats-form" method="get" action="<?= View::e($baseUrl) ?>/stats.php">
        <div>
            <label class="form-label small mb-1" for="year">Období</label>
            <select class="form-select" id="year" name="year">
                <option value="all"<?= $selected === 'all' ? ' selected' : '' ?>>Celé období (po letech)</option>
<?php for ($year = $currentYear; $year >= $currentYear - 1; $year--): ?>
                <option value="<?= $year ?>"<?= $selected === (string) $year ? ' selected' : '' ?>>Rok <?= $year ?> (po měsících)</option>
<?php endfor; ?>
<?php if ($selected !== 'all' && (int) $selected < $currentYear - 1): ?>
                <option value="<?= View::e($selected) ?>" selected>Rok <?= View::e($selected) ?> (po měsících)</option>
<?php endif; ?>
            </select>
        </div>
        <noscript><button type="submit" class="btn btn-primary">Zobrazit</button></noscript>
    </form>

    <div class="alert alert-danger d-none" id="error" role="alert"></div>
    <p class="text-body-secondary small d-none" id="loading" role="status">Načítám data…</p>

    <!-- Souhrn období -->
    <section class="row g-3 mb-3" aria-label="Souhrn období">
        <div class="col-6 col-lg-3">
            <div class="card h-100 kpi"><div class="card-body">
                <h2 class="kpi-label">Průměrná teplota</h2>
                <p class="kpi-value"><span data-field="total.temp_avg" data-format="1">–</span><small> °C</small></p>
                <p class="kpi-note">prům. maximum <span data-field="total.temp_max_avg" data-format="1">–</span> °C</p>
                <p class="kpi-note">prům. minimum <span data-field="total.temp_min_avg" data-format="1">–</span> °C</p>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100 kpi"><div class="card-body">
                <h2 class="kpi-label">Absolutní extrémy</h2>
                <p class="kpi-note">max <strong class="text-danger-emphasis"><span data-field="extremes.temp_max.value" data-format="1">–</span> °C</strong>
                    <time data-field="extremes.temp_max.time" data-format="datetime"></time></p>
                <p class="kpi-note">min <strong class="text-info-emphasis"><span data-field="extremes.temp_min.value" data-format="1">–</span> °C</strong>
                    <time data-field="extremes.temp_min.time" data-format="datetime"></time></p>
                <p class="kpi-note">náraz <strong><span data-field="extremes.gust_max.value" data-format="1">–</span> km/h</strong>
                    <time data-field="extremes.gust_max.time" data-format="datetime"></time></p>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100 kpi"><div class="card-body">
                <h2 class="kpi-label">Srážky</h2>
                <p class="kpi-value"><span data-field="total.precip" data-format="1">–</span><small> mm</small></p>
                <p class="kpi-note">srážkových dnů <span data-field="total.rain_days" data-format="0">–</span></p>
                <p class="kpi-note">nejvíc <span data-field="total.precip_max.value" data-format="1">–</span> mm
                    <time data-field="total.precip_max.date" data-format="date"></time></p>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100 kpi"><div class="card-body">
                <h2 class="kpi-label">Data</h2>
                <p class="kpi-value"><span data-field="total.days" data-format="0">–</span><small> dnů s daty</small></p>
                <p class="kpi-note">z toho úplných <span data-field="total.days_complete" data-format="0">–</span></p>
                <p class="kpi-note"><span data-field="meta.samples" data-format="0">–</span> měření</p>
            </div></div>
        </div>
        <div class="col-12">
            <div class="card"><div class="card-body py-2">
                <h2 class="visually-hidden">Klimatologické dny</h2>
                <ul class="list-inline mb-0 small climate-days">
                    <li class="list-inline-item" title="Tmin pod 0 °C">Mrazové dny <strong data-field="total.frost_days" data-format="0">–</strong></li>
                    <li class="list-inline-item" title="Tmax pod 0 °C (jen úplné dny)">Ledové dny <strong data-field="total.ice_days" data-format="0">–</strong></li>
                    <li class="list-inline-item" title="Tmax alespoň 25 °C">Letní dny <strong data-field="total.summer_days" data-format="0">–</strong></li>
                    <li class="list-inline-item" title="Tmax alespoň 30 °C">Tropické dny <strong data-field="total.tropical_days" data-format="0">–</strong></li>
                    <li class="list-inline-item" title="Tmin alespoň 20 °C (jen úplné dny)">Tropické noci <strong data-field="total.tropical_nights" data-format="0">–</strong></li>
                    <li class="list-inline-item" title="Úhrn alespoň 1 mm">Srážkové dny <strong data-field="total.rain_days" data-format="0">–</strong></li>
                </ul>
            </div></div>
        </div>
    </section>

    <!-- Graf: teploty (čáry) a srážky (sloupce) po měsících / letech -->
    <section class="card mb-3" aria-label="Graf">
        <div class="card-body">
            <h2 class="h6" id="chart-title">Teplota a srážky</h2>
            <div class="chart chart-history" id="chart-stats" role="img" aria-label="Graf průměrných teplot a úhrnu srážek"></div>
        </div>
    </section>

    <!-- Paprskový graf: denní chod za posledních 7 dní (24 paprsků = hodiny) -->
    <section class="card mb-3" aria-labelledby="week-title" id="week"
             data-api-history="<?= View::e($baseUrl) ?>/api/history.php"
             data-api-windrose="<?= View::e($baseUrl) ?>/api/windrose.php?hours=168"
             data-timezone="<?= View::e($timezone) ?>">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <h2 class="h6 mb-0 me-auto" id="week-title">Posledních 7 dní – denní chod</h2>
                <div class="d-flex flex-wrap gap-1 wx-week-metrics" role="group" aria-label="Veličina v paprskovém grafu" id="week-metrics">
                    <button type="button" class="btn btn-outline-secondary active" data-metric="temp" aria-pressed="true">Teplota</button>
                    <button type="button" class="btn btn-outline-secondary" data-metric="dew" aria-pressed="false">Rosný bod</button>
                    <button type="button" class="btn btn-outline-secondary" data-metric="humidity" aria-pressed="false">Vlhkost</button>
                    <button type="button" class="btn btn-outline-secondary" data-metric="pressure" aria-pressed="false">Tlak</button>
                    <button type="button" class="btn btn-outline-secondary" data-metric="windrose" aria-pressed="false">Vítr (růžice)</button>
                    <button type="button" class="btn btn-outline-secondary" data-metric="gust" aria-pressed="false">Nárazy</button>
                    <button type="button" class="btn btn-outline-secondary" data-metric="precip" aria-pressed="false">Srážky</button>
                    <button type="button" class="btn btn-outline-secondary" data-metric="solar" aria-pressed="false">Záření</button>
                    <button type="button" class="btn btn-outline-secondary" data-metric="uv" aria-pressed="false">UV</button>
                </div>
            </div>
            <div id="week-chart" class="chart chart-radar" role="img" aria-label="Paprskový graf za posledních 7 dní"></div>
            <p class="small text-body-secondary mb-0" id="week-note"></p>
        </div>
    </section>

    <!-- Tabulka po měsících / letech -->
    <section class="card mb-3" aria-labelledby="table-title">
        <div class="card-body">
            <h2 class="h6" id="table-title">Přehled</h2>
            <div class="table-responsive history-table stats-table">
                <table class="table table-sm table-striped table-hover mb-0" id="stats-table"></table>
            </div>
            <p class="small text-body-secondary mb-0 mt-2">
                Průměry, ledové dny a tropické noci počítáme jen z úplných dnů (data alespoň z
                <span data-field="meta.minHoursComplete" data-format="0">18</span> hodin). Kliknutím na období
                nebo datum otevřete podrobnou historii.
            </p>
        </div>
    </section>

    <!-- Žebříčky rekordů -->
    <section aria-labelledby="records-title" class="mb-3">
        <h2 class="h5" id="records-title">Rekordy období</h2>
        <div class="row g-3" id="records">
<?php foreach ($records as $key => $record): ?>
            <div class="col-12 col-sm-6 col-xl-4">
                <div class="card h-100"><div class="card-body">
                    <h3 class="h6 mb-1"><?= View::e($record[0]) ?></h3>
                    <p class="small text-body-secondary mb-2"><?= View::e($record[3]) ?></p>
                    <ol class="records-list mb-0" data-record="<?= View::e($key) ?>"
                        data-unit="<?= View::e($record[1]) ?>" data-digits="<?= (int) $record[2] ?>">
                        <li class="text-body-secondary">–</li>
                    </ol>
                </div></div>
            </div>
<?php endforeach; ?>
        </div>
    </section>
</div>
