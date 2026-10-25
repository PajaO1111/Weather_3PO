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
</div>
