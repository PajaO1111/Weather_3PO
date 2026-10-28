<?php
use OuBrix\Weather\Http\View;
?>
<div id="climate" data-api="<?= View::e($baseUrl) ?>/api/climate.php" aria-busy="true">
    <h1 class="h4">Historické klima ČHMÚ Kopisty</h1>
    <p class="text-body-secondary">
        Stanice Kopisty (0-20000-0-11433), nikoli měření OuBrix ani regionální data Ústeckého kraje.
        Stránka porovnává archivované měsíční údaje s oficiálním staničním normálem ČHMÚ
        1991–2020. Dříve počítaný průměr ze stejného archivu zůstává uveden odděleně
        jako vlastní referenční výpočet; normál Kopist není normálem lokality OuBrix.
    </p>
    <form id="climate-form" class="d-flex flex-wrap align-items-end gap-2 mb-3">
        <div>
            <label for="climate-year" class="form-label">Rok publikovaného archivu</label>
            <select id="climate-year" name="year" class="form-select" disabled></select>
        </div>
        <button id="climate-submit" class="btn btn-primary" type="submit">Načíst</button>
    </form>
    <div id="climate-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="climate-warning" class="alert alert-warning d-none" role="status"></div>
    <p id="climate-loading" role="status">Načítám historické klima…</p>
    <div id="climate-results" class="d-none">
        <p id="climate-source" class="small text-body-secondary"></p>
        <p id="climate-summary" aria-live="polite"></p>
        <div class="row g-3 mb-3">
            <section class="col-lg-6"><div class="card h-100"><div class="card-body">
                <h2 class="h6">Průměrná měsíční teplota</h2>
                <div id="climate-temperature-chart" class="chart" role="img" aria-label="Měsíční teploty a reference Kopisty 1991 až 2020"></div>
                <p class="small text-body-secondary mb-0">
                    Zvolený rok a oficiální normál ČHMÚ; vlastní průměr z historického archivu je samostatná řada.
                    Teplotní odchylka je zobrazena pouze pro přijaté měsíční údaje.
                </p>
            </div></div></section>
            <section class="col-lg-6"><div class="card h-100"><div class="card-body">
                <h2 class="h6">Měsíční úhrny srážek</h2>
                <div id="climate-precipitation-chart" class="chart" role="img" aria-label="Měsíční srážky a reference Kopisty 1991 až 2020"></div>
                <p class="small text-body-secondary mb-0">
                    Srážkový rozdíl a procento normálu se počítají jen z přijatých měsíčních údajů.
                    100 % odpovídá měsíčnímu normálu ČHMÚ.
                </p>
            </div></div></section>
        </div>
        <div class="table-responsive">
            <table class="table table-sm">
                <caption>
                    Archivované hodnoty, oficiální staniční normál ČHMÚ a dřívější vlastní průměr
                    z archivu. Odchylky jsou zvolený rok − oficiální normál; srážky % jsou
                    hodnota / normál × 100. Počet = přijaté roky / 30 pro vlastní referenci.
                </caption>
                <thead><tr>
                    <th scope="col">Měsíc</th>
                    <th scope="col">Teplota °C</th><th scope="col">Normál °C</th><th scope="col">Odchylka °C</th>
                    <th scope="col">Vlastní ref. °C</th><th scope="col">Počet T</th>
                    <th scope="col">Srážky mm</th><th scope="col">Normál mm</th><th scope="col">Rozdíl mm</th>
                    <th scope="col">Normál %</th><th scope="col">Vlastní ref. mm</th>
                    <th scope="col">Počet SRA</th><th scope="col">Stav a příznaky</th>
                </tr></thead>
                <tbody id="climate-rows"></tbody>
            </table>
        </div>
    </div>
    <section class="small text-body-secondary">
        <h2 class="h6">Metodika a omezení</h2>
        <p>
            Zdroj Open.Data.MLY: teplota T / AVG / AVG, srážky SRA / 06:00 / SUM.
            Jednotky jsou ověřeny v historických metadatech denních prvků DLY, ze kterých
            měsíční statistiky vycházejí; intervaly metadat samy nedokládají úplnost denních měření.
            Oficiální normál ČHMÚ 1991–2020 je samostatný staniční produkt pro Kopisty.
            Dříve počítaná reference je aritmetický průměr stejného měsíce z archivu MLY
            a zobrazuje se pouze při 30 přijatých hodnotách bez FLAG_REPEAT a FLAG_INTERRUPTED.
        </p>
        <p>
            Chybějící údaje nejsou nuly. Hodnoty s příznakem se ukazují pouze jako původní údaj
            v popisu stavu, nevstupují do grafu ani do výpočtů. Duplicity nebo neplatný formát
            se odmítají. Ani hodnota bez příznaku není zárukou homogenizace či kvality všech
            denních podkladů; posouzení vychází pouze z publikovaných měsíčních údajů.
        </p>
        <p>
            Roční srážky se sčítají jen při 12 přijatých měsících a porovnávají s explicitní
            roční hodnotou normálu ČHMÚ (Month=13), nikoli se součtem zaokrouhlených měsíců.
            Roční vlastní referenční úhrn zůstává průměrem 30 úplných ročních součtů.
            Roční teplotní průměr z měsíčních údajů se zde nepočítá.
            Aktuální rok a průběžná data recent nejsou součástí tohoto archivu.
            ČHMÚ může historické řady revidovat; data kontrolujeme nejpozději po 24 hodinách,
            metadata po 7 dnech. Stará cache při výpadku zdroje je výslovně označena.
        </p>
        <p>
            <a href="https://opendata.chmi.cz/meteorology/climate/historical/data/monthly/mly-0-20000-0-11433.json">Zdroj měsíčních údajů ČHMÚ Kopisty (JSON)</a>
            · <a href="https://opendata.chmi.cz/meteorology/products/climate_normal_stations/period_1991_2020/temperature/0-20000-0-11433_T_1991_2020_normal.csv">Oficiální normál teploty</a>
            · <a href="https://opendata.chmi.cz/meteorology/products/climate_normal_stations/period_1991_2020/precipitation/0-20000-0-11433_SRA_1991_2020_normal.csv">Oficiální normál srážek</a>
            · <a href="https://opendata.chmi.cz/meteorology/climate/historical/metadata/">Historická metadata ČHMÚ</a>
        </p>
    </section>
</div>
