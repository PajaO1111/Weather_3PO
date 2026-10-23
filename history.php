<?php
/*
require_once: Načte soubor jen jednou – pokud už byl načten, znovu se nenačítá.
__DIR__: Vrací cestu k aktuálnímu adresáři souboru.
../config.php: Relativní cesta k souboru s konfigurací (např. přihlašovací údaje k databázi).
*/
require_once __DIR__ . '/../config.php';

// Připojení k DB
/*
$dsn: "Data Source Name" – řetězec s informacemi pro připojení k MySQL.
sprintf: Vloží hodnoty z konstant (např. DB_HOST) do formátovaného řetězce.
*/
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    DB_HOST,
    DB_PORT,
    DB_NAME
);

// Získání a validace uživatelského vstupu
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

// Omezení rozsahu, aby se předešlo příliš vysokým hodnotám
if ($limit > 8500) {
    $limit = 8500;
} elseif ($limit < 1) {
    $limit = 100;
}

/*
new PDO(...): Vytvoří nové připojení k databázi.
PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION: Zapne výjimky při chybách – místo tichého selhání se vyhodí chyba.
 */
// Načíst posledních 100 záznamů
// ... (váš stávající kód připojení k DB a zpracování uživatelského vstupu) ...

// Načíst záznamy s uživatelsky definovaným limitem, seřazené od nejstaršího
$sql = '
    SELECT
    station_id AS "ID stanice",
    obs_time_utc AS "Čas pozorování (UTC)",
    obs_time_local AS "Čas pozorování (místní)",
    neighborhood AS "Oblast",
    software_type AS "Typ softwaru",
    country AS "Země",
    solar_radiation AS "Solární záření",
    lon AS "Zeměpisná délka",
    lat AS "Zeměpisná šířka",
    realtime_frequency AS "Frekvence",
    epoch AS "Epoch (čas od 1. 1. 1970)",
    uv AS "UV index",
    winddir AS "Směr větru",
    humidity AS "Vlhkost (%)",
    qc_status AS "Stav",
    temp_c AS "Teplota (°C)",
    heat_index AS "Pocitová teplota (°C)",
    dew_point AS "Rosný bod (°C)",
    wind_chill AS "Pocitová teplota větru (°C)",
    wind_speed AS "Rychlost větru (km/h)",
    wind_gust AS "Náraz větru (km/h)",
    pressure AS "Tlak (hPa)",
    precip_rate AS "Rychlost srážek (mm/h)",
    precip_total AS "Celkové srážky (mm)",
    elevation AS "Nadmořská výška (m)"
FROM pws_observations
ORDER BY obs_time_utc DESC
LIMIT :limit;
';

$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ... (zbytek vašeho HTML a JavaScriptu) ...

//$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
/*
$pdo->query($sql): Spustí SQL dotaz.
fetchAll(PDO::FETCH_ASSOC): Vrátí všechny výsledky jako asociativní pole (klíče odpovídají názvům sloupců).
*/
?>
<!DOCTYPE html>
<html lang="cs">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="description" content="Historie meteorologických dat stanice OuBrix.eu včetně teploty, tlaku, srážek a dalších metrik.">
        <meta name="author" content="OuBrix.eu">
        <meta name="theme-color" content="#0d6efd">
        <meta name="robots" content="index,follow,max-image-preview:large">
    <title>Historie meteorologických dat</title>
        <link rel="canonical" href="https://oubrix.eu/history.php">
        <link rel="manifest" href="/manifest.json">

        <meta property="og:type" content="website">
        <meta property="og:locale" content="cs_CZ">
        <meta property="og:site_name" content="OuBrix.eu">
        <meta property="og:title" content="Historie meteorologických dat | OuBrix.eu">
        <meta property="og:description" content="Historie meteorologických dat stanice OuBrix.eu včetně teploty, tlaku, srážek a dalších metrik.">
        <meta property="og:url" content="https://oubrix.eu/history.php">
        <meta property="og:image" content="https://oubrix.eu/assets/img/optimized/0047991.jpg">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="Historie meteorologických dat | OuBrix.eu">
        <meta name="twitter:description" content="Historie meteorologických dat stanice OuBrix.eu včetně teploty, tlaku, srážek a dalších metrik.">
        <meta name="twitter:image" content="https://oubrix.eu/assets/img/optimized/0047991.jpg">

        <script type="application/ld+json">
            {
                "@context": "https://schema.org",
                "@type": "Dataset",
                "name": "Historie meteorologických dat",
                "description": "Historie meteorologických dat stanice OuBrix.eu včetně teploty, tlaku, srážek a dalších metrik.",
                "url": "https://oubrix.eu/history.php",
                "inLanguage": "cs-CZ",
                "creator": {
                    "@type": "Organization",
                    "name": "OuBrix.eu"
                },
                "isAccessibleForFree": true
            }
        </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/css/site-shell.css" rel="stylesheet">
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background-color: rgb(190, 187, 187);
        }
    </style>
</head>

<body class="p-4">
<div id="site-header" data-active="home"></div>
<div class="container mt-5 pt-5">
        <!-- vstup kolik záznamů -->
        <form method="get" action="">
            <label for="limit">Zobrazit záznamů:</label>
            <input type="number" id="limit" name="limit" value="100" min="1" max="500">
            <button type="submit">Aktualizovat</button>
        </form>
        <!-- Nadpis -->
        <h1 class="mb-4">Historie měření (posledních 100)</h1>

        <!-- Grafy (přizpůsobené podle potřeby) -->
        <div class="row mb-4">
            <div class="col"><canvas id="chartTemp"></canvas></div>
            <div class="col"><canvas id="chartPressure"></canvas></div>
            <div class="col"><canvas id="chartPrecip"></canvas></div>
        </div>

        <!-- Export CSV -->
        <button id="btn-export" class="btn btn-secondary mb-4">Export do CSV</button>

        <!-- Tabulka všech polí -->
        <?php if (!empty($rows)): ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <?php foreach (array_keys($rows[0]) as $col): ?>
                                <th><?= htmlspecialchars($col) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <?php foreach ($r as $v): ?>
                                    <td><?= htmlspecialchars((string) $v) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">Data nejsou momentálně dostupná. Header a footer zůstávají funkční.</div>
        <?php endif; ?>
    </div>

    <div id="site-footer"></div>

    <script>
    const data = <?= json_encode($rows, JSON_UNESCAPED_UNICODE) ?>;
    const labels = data.map(r => r['Čas pozorování (UTC)']);
    
    // Klíče musí odpovídat aliasům z SQL
    const temps = data.map(r => parseFloat(r['Teplota (°C)']));
    const pres = data.map(r => parseFloat(r['Tlak (hPa)']));
    const precs = data.map(r => parseFloat(r['Celkové srážky (mm)']));
        // Teplota
        new Chart(document.getElementById('chartTemp'), {
            type: 'line',
            data: { labels, datasets: [{ label: 'Teplota (°C)', data: temps, borderColor: '#f00', fill: false }] }
        });
        // Tlak
        new Chart(document.getElementById('chartPressure'), {
            type: 'line',
            data: { labels, datasets: [{ label: 'Tlak (hPa)', data: pres, borderColor: '#0a0', fill: false }] }
        });
        // Srážky
        // Vytvoříme pole barev na základě hodnot precs
        const backgroundColors = precs.map(value => {
            // Pokud je hodnota vyšší než 5, použijeme červenou, jinak zelenou
            return value > 5 ? '#ff0000' : '#00aa00'; // Můžete použít i #f00 a #0a0
        });

        new Chart(document.getElementById('chartPrecip'), {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Srážky (mm)',
                    data: precs,
                    backgroundColor: backgroundColors // Použijeme dynamicky vytvořené pole barev
                }]
            },
            options: {
                scales: {
                    y: {
                        min: 0,
                        max: 5
                    }
                }
            }
        });

        document.getElementById('btn-export').addEventListener('click', () => {
            const cols = Object.keys(data[0]);
            let csv = cols.join(',') + '\n';
            data.forEach(r => {
                csv += cols.map(c => r[c]).join(',') + '\n';
            });
            const blob = new Blob([csv], { type: 'text/csv' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'history.csv';
            a.click();
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/site-shell.js?v=20260723-1"></script>
</body>

</html>
