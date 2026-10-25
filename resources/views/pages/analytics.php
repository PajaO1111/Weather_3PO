<?php
/**
 * Analytika srážkových úhrnů z vlastních měření.
 *
 * @var string $baseUrl
 */
use OuBrix\Weather\Http\View;
?>
<div id="analytics" data-api-analytics="<?= View::e($baseUrl) ?>/api/analytics.php" aria-busy="true">
    <section class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0 me-2">Analytika vlastních měření</h1>
        <span class="badge text-bg-secondary" id="analytics-station">–</span>
        <span class="text-body-secondary small" id="analytics-range" aria-live="polite"></span>
    </section>

    <div class="alert alert-danger d-none" id="analytics-error" role="alert"></div>
    <p class="text-body-secondary small d-none" id="analytics-loading" role="status">Načítám data…</p>

    <section aria-labelledby="rainfall-title" class="mb-3">
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2">
            <h2 class="h5 mb-0" id="rainfall-title">Naměřené srážky</h2>
            <span class="text-body-secondary small" id="last-observation"></span>
        </div>
        <p class="text-body-secondary">
            Součet známých denních úhrnů v místních kalendářních dnech včetně dneška.
            Nejde o odchylku od normálu ani ukazatel sucha. Chybějící dny nejsou považovány za nulové srážky.
        </p>
        <div class="row g-3" id="rainfall-periods">
<?php foreach ([30, 60, 90] as $days): ?>
            <div class="col-12 col-md-4" data-rainfall-period="<?= $days ?>">
                <article class="card h-100 kpi">
                    <div class="card-body">
                        <h3 class="kpi-label">Posledních <?= $days ?> dní</h3>
                        <p class="kpi-value"><span data-rainfall-value>–</span><small> mm</small></p>
                        <p class="kpi-note" data-rainfall-dates>–</p>
                        <p class="kpi-note">Úhrn známý za <strong data-rainfall-coverage>–</strong> dní
                            (<span data-rainfall-percent>–</span> % pokrytí)</p>
                        <p class="kpi-note mb-0">Dní s měřením: <strong data-rainfall-weather-days>–</strong></p>
                    </div>
                </article>
            </div>
<?php endforeach; ?>
        </div>
    </section>

    <section class="card mb-3" aria-labelledby="rainfall-chart-title">
        <div class="card-body">
            <h2 class="h6" id="rainfall-chart-title">Souhrn srážek podle období</h2>
            <div class="chart chart-history" id="analytics-chart" role="img" aria-label="Naměřené srážky za 30, 60 a 90 dní"></div>
        </div>
    </section>
    <p class="small text-body-secondary" id="analytics-updated"></p>
</div>
