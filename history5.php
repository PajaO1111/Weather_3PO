<?php
require_once __DIR__ . '/../config.php';

// --- Přidáno: Získání a validace uživatelského vstupu (limit) ---
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
if ($limit > 500) {
    $limit = 500;
} elseif ($limit < 1) {
    $limit = 100;
}

/// Připojení k DB
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    DB_HOST,
    DB_PORT,
    DB_NAME
);

// --- Úprava SQL: použití limitu od uživatele ---
$sql = "
  SELECT
    obs_time_local,
    solar_radiation,
    uv,
    winddir,
    wind_speed,
    wind_gust,
    pressure,
    temp_c,
    dew_point,
    humidity,
    precip_rate,
    precip_total
  FROM pws_observations
  ORDER BY obs_time_local DESC
  LIMIT :limit
";
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
// $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

$windData = array_map(function ($r) {
    return [
        'time' => $r['obs_time_local'],
        'dir' => (float) $r['winddir'],
        'speed' => (float) $r['wind_speed']
    ];
}, $rows);

?>


<!DOCTYPE html>
<html lang="cs">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="description" content="Rozšířená historie meteorologických dat OuBrix.eu: grafy větru, UV, tlaku, teploty a srážek.">
        <meta name="author" content="OuBrix.eu">
        <meta name="theme-color" content="#0d6efd">
        <meta name="robots" content="index,follow,max-image-preview:large">
    <title>Historie meteorologických dat</title>
        <link rel="canonical" href="https://oubrix.eu/history5.php">
        <link rel="manifest" href="/manifest.json">

        <meta property="og:type" content="website">
        <meta property="og:locale" content="cs_CZ">
        <meta property="og:site_name" content="OuBrix.eu">
        <meta property="og:title" content="Historie meteorologických dat (rozšířená) | OuBrix.eu">
        <meta property="og:description" content="Rozšířená historie meteorologických dat OuBrix.eu: grafy větru, UV, tlaku, teploty a srážek.">
        <meta property="og:url" content="https://oubrix.eu/history5.php">
        <meta property="og:image" content="https://oubrix.eu/assets/img/optimized/0047991.jpg">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="Historie meteorologických dat (rozšířená) | OuBrix.eu">
        <meta name="twitter:description" content="Rozšířená historie meteorologických dat OuBrix.eu: grafy větru, UV, tlaku, teploty a srážek.">
        <meta name="twitter:image" content="https://oubrix.eu/assets/img/optimized/0047991.jpg">

        <script type="application/ld+json">
            {
                "@context": "https://schema.org",
                "@type": "Dataset",
                "name": "Historie meteorologických dat (rozšířená)",
                "description": "Rozšířená historie meteorologických dat OuBrix.eu: grafy větru, UV, tlaku, teploty a srážek.",
                "url": "https://oubrix.eu/history5.php",
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
    <!-- vklad luxon, musí být před chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/luxon@3.4.4"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.3.1"></script>
    <!-- chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="p-4">
    <style>
        body {
            background-color: rgb(190, 187, 187);
        }

        .chart-container {
            position: relative;
            width: 100%;
            padding-bottom: 100%;
        }

        .chart-container canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
    </style>

<div id="site-header" data-active="home"></div>
<div class="container mt-5 pt-5">

        <!-- Přidáno: vstup pro počet záznamů -->
        <form method="get" action="">
            <label for="limit">Zobrazit záznamů:</label>
            <input type="number" id="limit" name="limit" value="<?= htmlspecialchars($limit) ?>" min="1" max="500">
            <button type="submit">Aktualizovat</button>
        </form>

        <h1 class="mb-4">Historie měření (posledních <?= htmlspecialchars($limit) ?>)</h1>


        <!-- Grafy (přizpůsobené podle potřeby) -->
        <div class="row mb-4">
            <div class="col"><canvas id="chartTemp"></canvas></div>
            <div class="col"><canvas id="chartPressure"></canvas></div>
            <div class="col"><canvas id="chartPrecip"></canvas></div>
        </div>

        <h2 class="mb-3">Další metriky</h2>

        <!-- Řádek 1: sluneční záření a UV -->
        <div class="row mb-4">
            <div class="col-md-6">
                <canvas id="chartSolar"></canvas>
            </div>
            <div class="col-md-6">
                <canvas id="chartUV"></canvas>
            </div>
        </div>

        <!-- Řádek 2: vítr – rychlost a poryv -->
        <div class="row mb-4">
            <div class="col-md-6">
                <canvas id="chartWindSpeed"></canvas>
            </div>
            <div class="col-md-6">
                <canvas id="chartWindGust"></canvas>
            </div>
        </div>

        <!-- Řádek 3: vítr – směr -->
        <div class="row mb-4">
            <div class="col">
                <canvas id="chartWindDir"></canvas>
            </div>
        </div>

        <!-- GRAF: Směr a rychlost větru (history4) -->
        <h2 class="mb-3">Směr a rychlost větru (polar)</h2>
        <div class="row justify-content-center py-4">
            <div class="col-12 col-lg-8">
                <div class="chart-container">
                    <canvas id="chartWindDirSpeed"></canvas>
                </div>
            </div>
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
        const labels = data.map(r => r.obs_time_local);
        // tři metriky
        const temps = data.map(r => parseFloat(r.temp_c));
        const pres = data.map(r => parseFloat(r.pressure));
        const precs = data.map(r => parseFloat(r.precip_rate));

        // Další datové sady
        const solar = data.map(r => parseFloat(r.solar_radiation));
        const uv = data.map(r => parseFloat(r.uv));
        const windSpeed = data.map(r => parseFloat(r.wind_speed));
        const windGust = data.map(r => parseFloat(r.wind_gust));
        const windDir = data.map(r => parseFloat(r.winddir));

        // Nastavení globálních vlastností pro Chart.js
        Chart.defaults.color = '#fff'; // Barva písma pro všechny popisky a osy
        // ... (váš stávající kód pro načítání dat a barvy) ...

        // ... (ostatní grafy) ...

        new Chart(document.getElementById('chartTemp'), {
            type: 'line',
            data: { labels, datasets: [{ label: 'Teplota (°C)', data: temps, borderColor: '#f00', fill: false }] }
        });
        
        new Chart(document.getElementById('chartPressure'), {
            type: 'line',
            data: { labels, datasets: [{ label: 'Tlak (hPa)', data: pres, borderColor: '#00f', fill: false }] }
        });

        // --- Úprava srážek podle history.php ---


        // Najdeme maximální hodnotu srážek v celém datovém souboru
        const maxPrecipValue = Math.max(...precs);

        // Nastavíme dynamickou maximální hodnotu pro graf
        let maxGraf = 5; // Výchozí hodnota
        if (maxPrecipValue > 5) {
            maxGraf = maxPrecipValue;
        }

        // Vytvoříme pole barev na základě hodnot precs
        const backgroundColors = precs.map(value => {
            // Pokud je hodnota vyšší než 5, použijeme červenou, jinak zelenou
            return value > 5 ? '#00ccffff' : '#0003aaff';
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
                        max: maxGraf // dynamická hodnota velikosti grafu
                    }
                }
            }
        });

        // 4) Sluneční záření
        new Chart(document.getElementById('chartSolar'), {
            type: 'line',
            data: {
                labels, datasets: [{
                    label: 'Sluneční záření (W/m²)',
                    data: solar,
                    borderColor: 'rgba(255,159,64,1)',
                    backgroundColor: 'rgba(255,159,64,0.2)',
                    tension: 0.3
                }]
            },
            options: { scales: { x: { display: false } } }
        });

        // 5) UV index
        new Chart(document.getElementById('chartUV'), {
            type: 'line',
            data: {
                labels, datasets: [{
                    label: 'UV index',
                    data: uv,
                    borderColor: 'rgba(75,192,192,1)',
                    backgroundColor: 'rgba(75,192,192,0.2)',
                    tension: 0.3
                }]
            },
            options: { scales: { x: { display: false } } }
        });

        // 6) Vítr – rychlost
        new Chart(document.getElementById('chartWindSpeed'), {
            type: 'line',
            data: {
                labels, datasets: [{
                    label: 'Rychlost větru (m/s)',
                    data: windSpeed,
                    borderColor: 'rgba(54,162,235,1)',
                    backgroundColor: 'rgba(54,162,235,0.2)',
                    tension: 0.3
                }]
            },
            options: { scales: { x: { display: false } } }
        });

        // 7) Vítr – poryv
        new Chart(document.getElementById('chartWindGust'), {
            type: 'line',
            data: {
                labels, datasets: [{
                    label: 'Poryv větru (m/s)',
                    data: windGust,
                    borderColor: 'rgba(153,102,255,1)',
                    backgroundColor: 'rgba(153,102,255,0.2)',
                    tension: 0.3
                }]
            },
            options: { scales: { x: { display: false } } }
        });

        // 8) Vítr – směr (line chart pro přehled)
        new Chart(document.getElementById('chartWindDir'), {
            type: 'line',
            data: {
                labels, datasets: [{
                    label: 'Směr větru (°)',
                    data: windDir,
                    borderColor: 'rgba(255,206,86,1)',
                    backgroundColor: 'rgba(255,206,86,0.2)',
                    tension: 0.3
                }]
            },
            options: {
                scales: {
                    x: { display: false },
                    y: {
                        suggestedMin: 0,
                        suggestedMax: 360,
                        ticks: { stepSize: 90 }
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

        // --- GRAF: Směr a rychlost větru (polar) ---
        document.addEventListener('DOMContentLoaded', () => {
            const windData = <?= json_encode($windData, JSON_UNESCAPED_UNICODE) ?>;
            const ratioInner = 0.30;
            const ratioZero = 0.40;
            const ratioOuter = 1.00;
            const maxSpeed = windData.length
                ? Math.max(...windData.map(d => d.speed))
                : 1;
            const barWidth = 0.03; // ~1.7°

            const ringPlugin = {
                id: 'ringPlugin',
                beforeDraw(chart) {
                    const { ctx, chartArea: { left, right, top, bottom } } = chart;
                    const cx = (left + right) / 2;
                    const cy = (top + bottom) / 2;
                    const maxR = Math.min(right - left, bottom - top) / 2;

                    ctx.save();
                    ctx.strokeStyle = '#888';
                    ctx.lineWidth = 2;
                    ctx.font = '14px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';

                    [ratioInner, ratioZero, ratioOuter].forEach(ratio => {
                        ctx.beginPath();
                        ctx.arc(cx, cy, maxR * ratio, 0, 2 * Math.PI);
                        ctx.stroke();
                    });

                    [0, 45, 90, 135, 180, 225, 270, 315].forEach(deg => {
                        const rad = deg / 360 * 2 * Math.PI - Math.PI / 2;
                        const r1 = maxR * ratioInner;
                        const r2 = maxR * ratioOuter;
                        ctx.beginPath();
                        ctx.moveTo(cx + r1 * Math.cos(rad), cy + r1 * Math.sin(rad));
                        ctx.lineTo(cx + r2 * Math.cos(rad), cy + r2 * Math.sin(rad));
                        ctx.stroke();
                        ctx.fillText(deg + '°',
                            cx + (r2 + 20) * Math.cos(rad),
                            cy + (r2 + 20) * Math.sin(rad)
                        );
                    });
                    ctx.restore();
                }
            };

            const stripePlugin = {
                id: 'stripePlugin',
                afterDraw(chart) {
                    const { ctx, chartArea: { left, right, top, bottom } } = chart;
                    const cx = (left + right) / 2;
                    const cy = (top + bottom) / 2;
                    const maxR = Math.min(right - left, bottom - top) / 2;

                    windData.forEach(d => {
                        const rad = d.dir / 360 * 2 * Math.PI - Math.PI / 2;
                        const r = d.speed <= 0
                            ? maxR * ratioZero
                            : maxR * ratioZero + (maxR * ratioOuter - maxR * ratioZero) * (d.speed / maxSpeed);

                        ctx.save();
                        ctx.beginPath();
                        ctx.moveTo(
                            cx + maxR * ratioInner * Math.cos(rad - barWidth / 2),
                            cy + maxR * ratioInner * Math.sin(rad - barWidth / 2)
                        );
                        ctx.arc(cx, cy, r, rad - barWidth / 2, rad + barWidth / 2);
                        ctx.lineTo(
                            cx + maxR * ratioInner * Math.cos(rad + barWidth / 2),
                            cy + maxR * ratioInner * Math.sin(rad + barWidth / 2)
                        );
                        ctx.arc(cx, cy, maxR * ratioInner, rad + barWidth / 2, rad - barWidth / 2, true);
                        ctx.closePath();
                        ctx.fillStyle = `hsl(${d.dir},70%,50%)`;
                        ctx.fill();
                        ctx.restore();
                    });
                }
            };

            new Chart(
                document.getElementById('chartWindDirSpeed'),
                {
                    type: 'doughnut',
                    data: {
                        labels: windData.map(d => d.time),
                        datasets: [{ data: [1], backgroundColor: ['transparent'] }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        plugins: {
                            legend: { display: false },
                            ringPlugin: {},
                            stripePlugin: {}
                        }
                    },
                    plugins: [ringPlugin, stripePlugin]
                }
            );
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/site-shell.js?v=20260723-1"></script>
</body>

</html>
