/**
 * Graf časové řady (Apache ECharts) pro dashboard.
 *
 * Umí přepínat veličiny (teplota, tlak, vítr, srážky), přerušuje čáru
 * při výpadku dat a při změně motivu se překreslí v tmavé/světlé variantě.
 */
import { formatNumber, formatTime } from './api.js';

/** Výchozí mezera mezi měřeními, od které se čára v grafu přeruší (ms). Přepisuje ji options.gapMs. */
const DEFAULT_GAP_MS = 90 * 60 * 1000;

/**
 * Definice zobrazitelných veličin.
 * series[].field = klíč v položkách z api/observations.php, axis = index osy Y.
 */
const METRICS = {
    temperature: {
        axes: [{ name: '°C' }],
        series: [
            { field: 'temp_c', name: 'Teplota', unit: '°C', color: '#d9480f' },
            { field: 'dew_point', name: 'Rosný bod', unit: '°C', color: '#1c7ed6' },
        ],
    },
    pressure: {
        axes: [{ name: 'hPa' }],
        series: [{ field: 'pressure', name: 'Tlak', unit: 'hPa', color: '#7048e8' }],
    },
    wind: {
        axes: [{ name: 'km/h', min: 0 }],
        series: [
            { field: 'wind_speed', name: 'Vítr', unit: 'km/h', color: '#0ca678' },
            { field: 'wind_gust', name: 'Nárazy', unit: 'km/h', color: '#f08c00', type: 'scatter' },
        ],
    },
    rain: {
        axes: [{ name: 'mm', min: 0 }, { name: 'mm/h', min: 0 }],
        series: [
            { field: 'precip_total', name: 'Denní úhrn (čítač)', unit: 'mm', color: '#1971c2', area: true },
            { field: 'precip_rate', name: 'Intenzita', unit: 'mm/h', color: '#66d9e8', type: 'bar', axis: 1 },
        ],
    },
};

/**
 * Převede body na dvojice [čas, hodnota] a vloží null do mezer v datech,
 * aby ECharts nespojil čarou dva vzdálené úseky.
 */
function toPoints(items, field, gapMs) {
    const points = [];
    let previous = null;
    for (const item of items) {
        const time = Date.parse(item.time);
        if (previous !== null && time - previous > gapMs) {
            points.push([previous + 1, null]);
        }
        points.push([time, item[field]]);
        previous = time;
    }
    return points;
}

/** Barvy textu a mřížky podle aktuálního motivu Bootstrapu (sdílí i history.js). */
export function themeColors() {
    const style = getComputedStyle(document.documentElement);
    return {
        text: style.getPropertyValue('--bs-body-color').trim() || '#212529',
        muted: style.getPropertyValue('--bs-secondary-color').trim() || '#6c757d',
        grid: style.getPropertyValue('--bs-border-color').trim() || '#dee2e6',
    };
}

/**
 * Vytvoří ovladač grafu nad elementem.
 *
 * @param {HTMLElement} element kontejner grafu
 * @param {{ gapMs?: number }} [options] gapMs = mezera, od které se čára přeruší
 * @returns {{ show(items: any[], metric: string, range?: {from: string, to: string}): void, resize(): void }}
 */
export function createSeriesChart(element, options = {}) {
    const gapMs = options.gapMs > 0 ? options.gapMs : DEFAULT_GAP_MS;
    let chart = null;
    let lastItems = [];
    let lastMetric = 'temperature';
    let lastRange = null;

    function ensureChart() {
        if (!window.echarts) {
            throw new Error('Knihovna grafů se nenačetla.');
        }
        if (!chart) {
            chart = window.echarts.init(element, null, { renderer: 'canvas' });
        }
        return chart;
    }

    function render() {
        const config = METRICS[lastMetric] || METRICS.temperature;
        const colors = themeColors();
        const instance = ensureChart();
        instance.setOption({
            animation: false,
            textStyle: { color: colors.text },
            grid: { left: 48, right: config.axes.length > 1 ? 48 : 16, top: 36, bottom: 56 },
            legend: { top: 0, textStyle: { color: colors.text } },
            tooltip: {
                trigger: 'axis',
                formatter(params) {
                    if (!params.length) {
                        return '';
                    }
                    const lines = [`<strong>${formatTime(new Date(params[0].value[0]).toISOString(), 'datetime')}</strong>`];
                    for (const p of params) {
                        const def = config.series[p.seriesIndex];
                        lines.push(`${p.marker} ${p.seriesName}: ${formatNumber(p.value[1], 1)} ${def.unit}`);
                    }
                    return lines.join('<br>');
                },
            },
            xAxis: {
                type: 'time',
                // Celé požadované okno (např. 24 h), i když data na začátku chybí.
                min: lastRange ? Date.parse(lastRange.from) : undefined,
                max: lastRange ? Date.parse(lastRange.to) : undefined,
                axisLabel: { color: colors.muted, formatter: (value) => formatTime(new Date(value).toISOString(), 'time') },
                axisLine: { lineStyle: { color: colors.grid } },
            },
            yAxis: config.axes.map((axis, index) => ({
                type: 'value',
                name: axis.name,
                min: axis.min,
                scale: axis.min === undefined,
                position: index === 0 ? 'left' : 'right',
                nameTextStyle: { color: colors.muted },
                axisLabel: { color: colors.muted },
                splitLine: { show: index === 0, lineStyle: { color: colors.grid } },
            })),
            dataZoom: [{ type: 'inside' }, { type: 'slider', height: 18, bottom: 8 }],
            series: config.series.map((def) => ({
                name: def.name,
                type: def.type || 'line',
                yAxisIndex: def.axis || 0,
                data: toPoints(lastItems, def.field, gapMs),
                // Při řídkých datech (CRON ~2× za hodinu) jsou body vidět; jinak jen čára.
                showSymbol: lastItems.length <= 120,
                symbolSize: 4,
                connectNulls: false,
                itemStyle: { color: def.color },
                lineStyle: { width: 2 },
                areaStyle: def.area ? { opacity: 0.15 } : undefined,
            })),
        }, { notMerge: true });
    }

    // Po přepnutí motivu graf překreslíme novými barvami.
    document.addEventListener('weather:themechange', () => {
        if (chart) {
            render();
        }
    });
    window.addEventListener('resize', () => chart && chart.resize());

    return {
        show(items, metric, range) {
            lastItems = items;
            lastMetric = metric;
            lastRange = range && range.from && range.to ? range : null;
            render();
        },
        resize() {
            if (chart) {
                chart.resize();
            }
        },
    };
}
