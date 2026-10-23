<!DOCTYPE html>
<html lang="cs">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Test: Kruhy + radiální tyčky</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            text-align: center;
            font-family: sans-serif;
            background: #f4f4f4
        }

        #chartTest {
            margin: 1rem auto;
            border: 1px solid #ccc;
            width: 800px;
            height: 800px;
        }
    </style>
</head>

<body>
    <h1>Test: Kruhy + tyčky</h1>
    <canvas id="chartTest" width="800" height="800"></canvas>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // 1) Statická data
            const windData = [
                { dir: 0, speed: 0 },
                { dir: 45, speed: 1 },
                { dir: 90, speed: 2 },
                { dir: 135, speed: 3 },
                { dir: 180, speed: 4 },
                { dir: 225, speed: 5 },
                { dir: 270, speed: 2.5 },
                { dir: 315, speed: 1.5 }
            ];

            // 2) Konstanty (px)
            const innerR = 100;  // vnitřní kruh (3 cm)
            const zeroR = 200;  // hladina pro speed=0 (4 cm)
            const outerR = 350;  // max průměr (10 cm)
            const maxSpeed = Math.max(...windData.map(d => d.speed), 1);
            const barWidth = 0.03; // tloušťka pruhu v radiánech (~1.7°)

            // 3) Plugin: vykreslí kruhy a úhlové osy
            const ringPlugin = {
                id: 'ringPlugin',
                beforeDraw(chart) {
                    const { ctx, chartArea: { left, right, top, bottom } } = chart;
                    const cx = (left + right) / 2, cy = (top + bottom) / 2;
                    ctx.save();
                    ctx.strokeStyle = '#888';
                    ctx.lineWidth = 2;
                    ctx.font = '14px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';

                    // tři kruhy
                    [innerR, zeroR, outerR].forEach(r => {
                        ctx.beginPath();
                        ctx.arc(cx, cy, r, 0, 2 * Math.PI);
                        ctx.stroke();
                    });

                    // osy 0°,45°…315° + popisek
                    [0, 45, 90, 135, 180, 225, 270, 315].forEach(deg => {
                        const rad = deg / 360 * 2 * Math.PI - Math.PI / 2;
                        const x1 = cx + innerR * Math.cos(rad);
                        const y1 = cy + innerR * Math.sin(rad);
                        const x2 = cx + outerR * Math.cos(rad);
                        const y2 = cy + outerR * Math.sin(rad);
                        ctx.beginPath();
                        ctx.moveTo(x1, y1);
                        ctx.lineTo(x2, y2);
                        ctx.stroke();
                        // label
                        const lx = cx + (outerR + 20) * Math.cos(rad);
                        const ly = cy + (outerR + 20) * Math.sin(rad);
                        ctx.fillText(deg + '°', lx, ly);
                    });

                    ctx.restore();
                }
            };

            // 4) Plugin: kreslí radiální „tyčky“
            const stripePlugin = {
                id: 'stripePlugin',
                afterDraw(chart) {
                    const { ctx, chartArea: { left, right, top, bottom } } = chart;
                    const cx = (left + right) / 2, cy = (top + bottom) / 2;

                    windData.forEach(d => {
                        const rad = d.dir / 360 * 2 * Math.PI - Math.PI / 2;
                        // délka pruhu
                        const r = d.speed <= 0
                            ? zeroR
                            : zeroR + (outerR - zeroR) * (d.speed / maxSpeed);

                        ctx.save();
                        ctx.beginPath();
                        // vnitřní bod
                        ctx.moveTo(
                            cx + innerR * Math.cos(rad - barWidth / 2),
                            cy + innerR * Math.sin(rad - barWidth / 2)
                        );
                        // vnější oblouk
                        ctx.arc(cx, cy, r, rad - barWidth / 2, rad + barWidth / 2);
                        // zpět na vnitřní kruh
                        ctx.lineTo(
                            cx + innerR * Math.cos(rad + barWidth / 2),
                            cy + innerR * Math.sin(rad + barWidth / 2)
                        );
                        // vnitřní oblouk (zpět)
                        ctx.arc(cx, cy, innerR, rad + barWidth / 2, rad - barWidth / 2, true);
                        ctx.closePath();
                        ctx.fillStyle = `hsl(${d.dir},70%,50%)`;
                        ctx.fill();
                        ctx.restore();
                    });
                }
            };

            // 5) Inicializace „prázdného“ doughnutu – pluginy okolo kreslí všechno
            new Chart(
                document.getElementById('chartTest'),
                {
                    type: 'doughnut',
                    data: { labels: [''], datasets: [{ data: [1], backgroundColor: ['transparent'] }] },
                    options: {
                        responsive: false,
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
</body>

</html>