<?php
/**
 * Veřejné nastavení aplikace (BEZ hesel a klíčů).
 *
 * Přístupové údaje k DB zůstávají v původním config.php mimo web
 * (/www/domains/config.php), který používá i CRON a původní stránky.
 */
declare(strict_types=1);

return [
    // Název zobrazovaný v záhlaví stránky.
    'app_name' => 'OuBrix Weather',

    // Časové pásmo pro zobrazení a pro „dnešní“ souhrn (DB ukládá UTC i místní čas).
    'timezone' => 'Europe/Prague',

    // Cesta k původnímu config.php. Null = dvě úrovně nad projektem
    // (/www/domains/oubrix.eu/Weather -> /www/domains/config.php).
    // Lze přepsat proměnnou prostředí WEATHER_CONFIG.
    'secret_config_path' => null,

    // CRON (URL https://oubrix.eu/fetch_weather2.php?token=…) zapisuje každých 5 min
    // (od 23. 10. 2026; dříve kvůli cache CDN nepravidelně 5–67 min).
    // Prahy níže tolerují několik vynechaných běhů.

    // Po kolika sekundách bez nového měření je stanice označena jako neaktuální (30 min).
    'stale_after_seconds' => 1800,

    // Graf: časové okno v hodinách (výchozí a maximum přes ?hours=).
    'series_default_hours' => 24,
    'series_max_hours' => 168,
    // Pojistka proti přetížení – nejvýš tolik řádků v jedné odpovědi.
    'series_max_rows' => 2000,
    // Starší režim ?limit= (počet posledních řádků) – zachován kvůli kompatibilitě.
    'series_default_limit' => 288,
    'series_max_limit' => 500,
    // Mezera mezi měřeními, od které graf přeruší čáru (výpadek stanice/CRONu).
    'chart_gap_minutes' => 30,

    // Tlaková tendence se počítá proti měření zhruba před 3 hodinami;
    // tolerance pokrývá nejdelší běžnou mezeru mezi zápisy.
    'pressure_trend_hours' => 3,
    'pressure_trend_tolerance_minutes' => 30,

    // Stránka Historie (public/history.php, api/history.php). Délky období ve dnech.
    'history_max_days' => 366,          // nejdelší volitelné období
    'history_raw_max_days' => 7,        // data po 5 min (~2 000 řádků)
    'history_hour_max_days' => 186,     // hodinové agregace (~4 500 řádků)
    'history_auto_raw_days' => 3,       // „Automaticky“: do 3 dnů po 5 min,
    'history_auto_hour_days' => 62,     //   do 62 dnů po hodinách, jinak po dnech
    'history_raw_max_rows' => 3000,     // pojistka počtu surových řádků
    'history_table_max_rows' => 2000,   // víc řádků tabulka nezobrazí (zbytek v CSV)

    // Souborová cache hodinových souhrnů uzavřených měsíců (Historie, Statistiky).
    // Jeden soubor na stanici a měsíc, DB se nemění. Smazání složky = přepočet.
    // Null = Weather/storage/cache/aggregates (složka musí být zapisovatelná pro PHP).
    'aggregate_cache' => true,
    'aggregate_cache_dir' => null,

    // Databázové denní agregace jsou aktivní po ověření tabulek weather_daily,
    // weather_monthly a weather_yearly v produkční databázi.
    'weather_daily_aggregate_cache' => true,

    // Veřejná data ČHMÚ (složka zůstává mimo public/ a je v .gitignore).
    // Volitelná samostatná cache také pro izolované HTTP testy.
    'chmi_cache_dir' => getenv('WEATHER_CHMI_CACHE_DIR') ?: null,

    // Pevné verze knihoven z CDN včetně SRI otisků (CSP domény povoluje cdn.jsdelivr.net).
    'cdn' => [
        'bootstrap_css' => [
            'url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
            'sri' => 'sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH',
        ],
        'echarts_js' => [
            'url' => 'https://cdn.jsdelivr.net/npm/echarts@5.6.0/dist/echarts.min.js',
            'sri' => 'sha384-pPi0zxBAoDu6+JXW/C68UZLvBUUtU+7zonhif43rqj7pxsGyqyqzcian2Rj37Rss',
        ],
    ],
];
