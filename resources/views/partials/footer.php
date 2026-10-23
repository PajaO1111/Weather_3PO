<?php
/**
 * Patička webu: zdroj dat a upozornění k přesnosti.
 *
 * @var string $appName
 */
use OuBrix\Weather\Http\View;
?>
<footer class="app-footer border-top py-3 mt-4">
    <div class="container-xl small text-body-secondary d-flex flex-wrap gap-2 justify-content-between">
        <span>© <?= date('Y') ?> <?= View::e($appName) ?> · data z vlastní stanice přes Weather Underground</span>
        <span>Amatérská stanice – údaje jsou orientační, nejde o oficiální měření ČHMÚ.</span>
    </div>
</footer>
