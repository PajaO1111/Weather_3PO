# OuBrix Weather – nový meteorologický portál

Nová webová aplikace pro amatérskou meteostanici **IMOST71** (Most).
Data **pouze čte** z původní tabulky `pws_observations`, kterou dál plní
beze změny původní CRON (`fetch_weather*.php`). Původní stránky
(`history.php`, `history5.php`) zůstávají funkční.

> Stav: **Datový základ, Analytika srážek a Ovzduší ČHMÚ jsou nasazené části; nově je připravena teplotní část fáze 4 – Kopisty.**
> Analytika nyní obsahuje srážkové úhrny 30/60/90 dní. ČHMÚ má samostatnou
> stránku aktuální kvality ovzduší pro Most (UMOM), serverovou validaci dat
> a cache s viditelným stavem zastaralosti. Další plán viz [Plán rozšíření](#plán-rozšíření).

## Požadavky

- PHP **7.4 nebo novější** (doporučeno 8.2+) s rozšířením `pdo_mysql`
- MariaDB/MySQL s původní tabulkou `pws_observations`
- Původní `config.php` s konstantami `DB_HOST`, `DB_PORT`, `DB_NAME`,
  `DB_USER`, `DB_PASS`, `WU_STATION_ID`
- Prohlížeč s podporou ES modulů (všechny současné prohlížeče)

Bez Composeru a bez build kroku – knihovny Bootstrap 5.3.3 a ECharts 5.6.0
se načítají z `cdn.jsdelivr.net` s pevnou verzí a SRI otiskem
(CSP domény oubrix.eu tento zdroj povoluje).

## Architektura v kostce

```
Prohlížeč ──GET──► public/index.php, history.php, stats.php, analytics.php, air-quality.php ──► resources/views (HTML kostra)
    │
    └─JS fetch──► public/api/current.php ──┐
                  public/api/observations.php ─► app/Http/Api (chyby, GET)
                  public/api/history.php ─────┤
                  public/api/stats.php ───────┤
                  public/api/analytics.php ───┤
                  public/api/air-quality.php ─┘ ─► app/Weather/Gateways/ChmiGateway (pevné HTTPS zdroje, cache)
                                               ─► app/Weather/Services (logika)
                                               ─► app/Weather/Repositories (SQL)
                                               ─► app/Infrastructure/Database (PDO) ─► původní config.php + DB
```

- **HTML** = PHP šablony (layout + partials + stránky), bez dat z DB.
  Stránka se zobrazí i při výpadku DB a ukáže srozumitelnou chybu.
- **JavaScript** = načtení JSON, vyplnění hodnot, graf, motiv, obnovování.
- **PHP backend** = JSON API; SQL je jen v repozitáři, výpočty ve službách.
- Žádné přepisovací pravidlo (mod_rewrite) není potřeba – každý endpoint
  je fyzický soubor v `public/api/`.

## Struktura souborů

```
Weather/                              (/www/domains/oubrix.eu/Weather)
├── bootstrap.php                     start: kontrola PHP, autoloader, nastavení
├── config/
│   └── app.php                       veřejné nastavení (časové pásmo, limity, CDN) – bez hesel
├── app/                              PHP třídy, namespace OuBrix\Weather\
│   ├── Infrastructure/Database.php   načtení config.php, PDO spojení, bezpečná diagnostika
│   ├── Http/
│   │   ├── Api.php                   společný obal API (GET, připojení, chyby 400/404/503)
│   │   ├── JsonResponse.php          odeslání JSON a jednotný tvar chyb
│   │   ├── CsvResponse.php           CSV soubor ke stažení (UTF-8 BOM, středník, ochrana proti vzorcům)
│   │   ├── View.php                  vykreslení šablon, escapování, URL assetů
│   │   └── Validation/
│   │       ├── ObservationQuery.php  validace ?hours= a ?limit=
│   │       ├── HistoryQuery.php      validace období historie (from, to, resolution, format)
│   │       └── StatsQuery.php        validace roku statistik (?year=RRRR | all)
│   └── Weather/
│       ├── Repositories/ObservationRepository.php   všechny SELECTy nad pws_observations
│       ├── Repositories/AggregateCache.php          souborová cache hodinových souhrnů uzavřených měsíců
│       ├── Services/CurrentConditionsService.php    aktuální stav, dnešní souhrn, tlaková tendence
│       ├── Services/ObservationService.php          časová řada pro graf (duplicity, konflikty)
│       ├── Services/HistoryService.php              historie: hodinové/denní agregace, souhrn období
│       ├── Services/HistoryCsv.php                  převod historie na CSV
│       ├── Services/StatsService.php                statistiky po měsících/letech, žebříčky rekordů
│       ├── Services/AnalyticsService.php            naměřené srážky za 30/60/90 dní a úplnost dat
│   ├── Services/AirQualityService.php           normalizace ovzduší, kvality hodnot a metadat ČHMÚ
│   ├── Gateways/ChmiGateway.php                 allowlist HTTPS zdrojů ČHMÚ, limity, timeout a cache
│       ├── Services/WindRoseService.php             větrná růžice (16 směrů × třídy rychlosti)
│       ├── Support/Meteo.php                        směr větru, pocitová teplota, UV, tendence, Beaufort, odhad stavu
│       └── Support/Astro.php                        východ/západ Slunce, výška Slunce, fáze Měsíce
├── public/                           JEDINÁ veřejná složka
│   ├── index.php                     stránka „Aktuálně“
│   ├── history.php                   stránka „Historie“
│   ├── stats.php                     stránka „Statistiky a rekordy“
│   ├── analytics.php                 stránka „Analytika“ (srážky za 30/60/90 dní)
│   ├── air-quality.php             stránka kvality ovzduší ČHMÚ (Most / UMOM)
│   ├── api/
│   │   ├── health.php                diagnostika nasazení (PHP, config, DB)
│   │   ├── current.php               aktuální stav stanice (JSON)
│   │   ├── observations.php          posledních N měření (JSON)
│   │   ├── history.php               měření za zvolené období (JSON / CSV)
│   │   ├── stats.php                 statistiky a rekordy za rok / celé období (JSON)
│   │   ├── analytics.php             naměřené srážky a úplnost dat (JSON)
│   │   ├── air-quality.php            aktuální kvalita ovzduší ČHMÚ pro UMOM (JSON)
│   │   └── windrose.php              větrná růžice za posledních N hodin (JSON)
│   └── assets/
│       ├── css/app.css               vlastní styly (světlý/tmavý motiv)
│       └── js/
│           ├── theme.js              přepínač motivu (auto/světlý/tmavý)
│           ├── api.js                fetch JSON, vyplnění data-field, české formátování čísel a časů
│           ├── charts.js             graf ECharts (teplota/tlak/vítr/srážky)
│           ├── gauges.js             SVG měřidla (oblouk, kompas, srážkoměr, UV, dráha Slunce, Měsíc, ikony, mini-grafy)
│           ├── history.js            stránka „Historie“: období, souhrn, 6 propojených grafů, tabulka
│           ├── stats.js              stránka „Statistiky“: výběr roku, graf, tabulka, žebříčky rekordů
│           ├── analytics.js          stránka „Analytika“: srážky, období a úplnost dat
│           ├── air-quality.js        stránka „Ovzduší ČHMÚ“: validované hodinové hodnoty UMOM
│           └── week.js               paprskový graf 7 dní (24 h × den) a větrná růžice
├── resources/views/
│   ├── layouts/main.php              HTML kostra, knihovny
│   ├── partials/header.php, footer.php   hlavička s menu (Aktuálně, Historie, Statistiky)
│   ├── pages/dashboard.php           obsah stránky „Aktuálně“
│   ├── pages/history.php             obsah stránky „Historie“
│   ├── pages/stats.php               obsah stránky „Statistiky a rekordy“
│   └── pages/analytics.php           obsah stránky „Analytika“
├── bin/check-connection.php          kontrola DB z příkazové řádky (ne z webu)
├── storage/cache/aggregates/         soubory cache (vytváří aplikace, lze kdykoli smazat)
├── tests/                            testy (spouští se lokálně, na hosting se nenahrávají)
└── docs/architecture/                analýza a návrh (na hosting se nenahrávají)
```

Složky `app/`, `config/`, `resources/`, `bin/`, `storage/` a `tests/` obsahují
`.htaccess` se zákazem přístupu z webu.

## Konfigurace

| Co | Kde | Poznámka |
|---|---|---|
| Přístup k DB, ID stanice | `/www/domains/config.php` | původní soubor, sdílí ho CRON |
| Token CRONu `FETCH_TOKEN` | `/www/domains/config.php` | `fetch_weather2.php` se z webu spustí jen s `?token=` shodným s touto konstantou (jinak 403); CRON volá `https://oubrix.eu/fetch_weather2.php?token=…` |
| Veřejné nastavení | [config/app.php](config/app.php) | časové pásmo, hranice „neaktuálních“ dat, limity, CDN |
| Jiná cesta ke config.php | proměnná prostředí `WEATHER_CONFIG` nebo `secret_config_path` v `config/app.php` | výchozí = dvě složky nad projektem |
| Cache souhrnů | `aggregate_cache`, `aggregate_cache_dir` v `config/app.php` | viz níže; proměnná prostředí `WEATHER_CACHE_DIR` (`off` = vypnuto) má přednost |

Původní `config.php` zapíná `display_errors`; aplikace ho po načtení vypíná,
aby se chyby nikdy nezobrazily návštěvníkům (jdou do error logu serveru).

### Cache souhrnů (výkon Historie a Statistik)

Hodinové souhrny, souhrn období a extrémy (teplota, náraz) každého **uzavřeného
měsíce** (místního, skončil aspoň před 24 h) se při prvním dotazu spočítají z DB
a uloží do `storage/cache/aggregates/{stanice}/RRRR-MM.json`. Další dotazy je
čtou ze souboru, z DB se živě čte jen rozpracovaný měsíc a okraje období.
Výsledky jsou totožné s výpočtem přímo z DB (ověřeno testem).

- DB ani CRON se nemění, žádná tabulka navíc.
- Smazání složky `storage/cache/aggregates/` (nebo jednoho souboru) = přepočet
  při dalším dotazu; to je potřeba jen při ruční opravě starých dat v DB.
- Při změně formátu v kódu se staré soubory poznají a přepočítají samy.
- Nelze-li do složky zapisovat, `api/health.php` ukáže `"cache": "not_writable"`
  a vše funguje dál, jen bez zrychlení.

## Nasazení na hosting

### Přesnost teploty z Weather Underground

Sběrné skripty `fetch_weather2.php` a původní `fetch_weather.php` požadují
`numericPrecision=decimal`. Bez tohoto parametru vrací Weather Underground
celočíselné hodnoty i při přesnějším původním měření. Předaná hodnota se
ukládá bez zaokrouhlení; například 7,9 °C zůstane 7,9 °C.
Hlavní stránka a porovnání s Kopisty zobrazují teploty na jednu desetinu
(český zápis `7,9`, nikoli `8`). Výpočty zachovávají svou dosavadní přesnost.

Aktualizovaný `fetch_weather2.php` je potřeba nahrát **na místo skutečně
spouštěné CRONem**, podle současné konfigurace do kořene webu
`/www/domains/oubrix.eu/fetch_weather2.php`, nejen do adresáře `Weather/`.
Změna se projeví u nového měření po dalším běhu CRONu; staré již zaokrouhlené
záznamy v DB se neopravují ani nevytvářejí chybějící desetiny.
DB schéma se nemění. Nepřenášet hesla ani měnit CRON token.

1. Nahrát do `/www/domains/oubrix.eu/Weather/` (zachovat strukturu):
   `bootstrap.php`, `config/`, `app/`, `public/`, `resources/`, `bin/`, `storage/`
   včetně souborů `.htaccess`.
   **Nenahrávat** `tests/`, `docs/`, `.env`, `config.php` ani SQL dumpy.
2. Smazat ze serveru soubory z předchozího prototypu, pokud tam zůstaly:
   `app/Http/Controllers/`, `public/assets/app.css`, `public/assets/dashboard.js`,
   `resources/views/dashboard.php`.
3. Ověřit v prohlížeči v tomto pořadí:

| Krok | URL | Očekávaný výsledek |
|---|---|---|
| 1 | `https://oubrix.eu/Weather/public/api/health.php` | `"status": "ok"`, `"database": "ok"`, `"cache": "ok"`, čas posledního měření |
| 2 | `https://oubrix.eu/Weather/public/api/current.php` | JSON s `station`, `observed`, `current`, `today` |
| 3 | `https://oubrix.eu/Weather/public/api/observations.php?hours=24` | měření za posledních 24 h (`meta.hours` = 24) |
| 4 | `https://oubrix.eu/Weather/public/index.php` | stránka „Aktuálně“ s hodnotami a grafem |
| 5 | `https://oubrix.eu/Weather/public/api/history.php?resolution=day` | JSON s `range`, `items` (dny), `summary` |
| 6 | `https://oubrix.eu/Weather/public/history.php` | stránka „Historie“ – posledních 7 dní po hodinách |
| 7 | `https://oubrix.eu/Weather/public/api/stats.php?year=all` | JSON s `years`, `rows` (roky), `total`, `records` |
| 8 | `https://oubrix.eu/Weather/public/stats.php` | stránka „Statistiky“ – letošní rok po měsících |
| 8a | `https://oubrix.eu/Weather/public/api/windrose.php` | JSON se 16 `sectors`, `classes`, `calm`, `prevailing` |
| 9 | `https://oubrix.eu/Weather/app/` | 403 Forbidden (ochrana interních složek) |

### Řešení potíží

Chybová odpověď API obsahuje jen bezpečné údaje v `diagnostics`
(nikdy heslo, text chyby DB ani cestu):

| `stage` / `reason` | Význam | Co dělat |
|---|---|---|
| prostý text „vyžaduje PHP 7.4“ | stará verze PHP | v administraci WEDOS přepnout PHP na 8.x |
| `configuration` + `CONFIG_NOT_FOUND` | config.php nenalezen | ověřit umístění `/www/domains/config.php` nebo nastavit `secret_config_path` |
| `configuration` + `CONFIG_INCOMPLETE` | chybí konstanta | doplnit konstanty uvedené v Požadavcích |
| `connection` + `sqlState`/`driverCode` | DB odmítla spojení | např. 1045 = špatné jméno/heslo, 2002 = nedostupný server |
| `query` + `sqlState` | chyba dotazu | např. 42S02 = tabulka neexistuje, 42000 = chybí práva SELECT |
| HTTP 500 bez JSON | fatální chyba PHP | otevřít `public/diag.php` (viz níže) |
| v prohlížeči stále stará chyba, `curl` už vrací 200 | CDN nebo prohlížeč drží starou odpověď | Ctrl+F5, případně počkat 10 min |
| soubory nalezeny, ale s malými písmeny (`app/weather/services/`) | FTP klient převádí názvy na malá písmena | aplikace to zvládne (autoloader zkouší i malá písmena), ale lepší je převod v FTP klientovi vypnout |

**`public/diag.php`** – dočasná diagnostika psaná syntaxí PHP 5, funguje i tam,
kde zbytek aplikace spadne. Vypíše verzi PHP, chybějící soubory, nalezení
`config.php` (jen názvy konstant), test DB stejně jako `history.php` a případnou
fatální chybu. **Po vyřešení smazat.**

## JSON API

Všechna API přijímají jen `GET` (jinak 405), vrací `application/json`
a chyby v jednotném tvaru `{"error": {"code", "message", "diagnostics?"}}`.
Kvůli cache se posílá `Cache-Control: no-store`. Hosting WEDOS ke každé jiné odpovědi
přidává `max-age=3600` a jeho CDN by pak až hodinu vracela stará data,
v nejhorším i starou chybovou odpověď.

### `api/current.php`

```json
{
  "station":  { "id": "IMOST71", "neighborhood": "Most", "lat": 50.5, "lon": 13.6, "elevation": 331.0 },
  "observed": { "utc": "2026-10-22T12:55:00Z", "local": "2026-10-22T14:55:00+02:00",
                "ageSeconds": 240, "stale": false, "staleAfterSeconds": 1800,
                "futureTimestamp": false, "qcStatus": 1 },
  "current":  { "temp_c": 16.0, "feels_like": 16.0, "dew_point": 9.0, "humidity": 62.0,
                "pressure": 1008.9, "winddir": 17.0, "wind_direction": "SSV",
                "wind_speed": 10.0, "wind_gust": 21.0, "uv": 0.7, "uv_category": "nízký",
                "solar_radiation": 201.3, "precip_rate": 0.0, "precip_total": 6.1, "…": "…" },
  "pressureTrend": { "hours": 3, "change": -0.4, "label": "ustálený", "comparedTo": "…" },
  "today": { "date": "2026-10-22", "samples": 28, "averageIntervalMinutes": 34,
             "temp_min": { "value": 4.0, "time": "2026-10-22T02:00:00+02:00" },
             "temp_max": { "value": 16.0, "time": "…" }, "gust_max": { "…": "…" },
             "temp_avg": 8.6, "humidity_min": 62.0, "pressure_min": 1008.9, "rainCounter": 6.1, "…": "…" },
  "condition": { "code": "partly", "label": "polojasno", "estimated": true },
  "astro": { "sun":  { "sunrise": "…T07:39:00+02:00", "transit": "…", "sunset": "…T17:59:00+02:00",
                      "dayLengthMinutes": 620, "polar": null, "elevation": 28.1, "isDay": true },
             "moon": { "phase": 0.43, "illumination": 94, "waxing": true, "name": "dorůstající Měsíc",
                       "ageDays": 12.6, "nextFull": "2026-10-26", "nextNew": "2026-11-09" } },
  "units": { "temp_c": "°C", "wind_speed": "km/h", "…": "…" },
  "timezone": "Europe/Prague"
}
```

- `null` = hodnota nebyla naměřena (nezaměňovat s nulou).
- `today` = místní den Europe/Prague (správně i při přechodu letního času).
- `rainCounter` = denní čítač stanice z posledního měření; `null`, pokud
  poslední měření není z dnešního dne. Nejde o ověřený srážkoměrný úhrn.
- `pressureTrend` = `null`, pokud chybí měření zhruba před 3 h (výpadek).
- `condition` = **odhad** stavu (jasno/polojasno/oblačno/déšť/sníh/mlha/noc) ze
  srážek, vlhkosti, rosného bodu a poměru slunečního záření k teoretickému
  záření při jasné obloze. Stanice oblačnost neměří, proto `estimated: true`.
- `astro.sun` = `null`, pokud stanice nemá zeměpisné souřadnice.
- `current.beaufort` = `{ "force": 2, "label": "větřík" }`,
  `current.precip_intensity` = slovní intenzita srážek.

### `api/observations.php?hours=N` (výchozí)

Měření za posledních `N` hodin, `N` = 1–168, výchozí 24. Okno se počítá **podle času**,
ne podle počtu řádků. CRON sice zapisuje po 5 min, ale starší data mají
nepravidelné mezery (do 23. 10. 2026 5–67 min) a běhy mohou vypadnout,
takže 288 řádků nemusí odpovídat 24 h.
Vrací `items` seřazené od nejstaršího (čas v UTC ISO 8601) a `meta`:
`hours`, `from`, `to`, `points`, `duplicates`, `conflicts`,
`medianIntervalMinutes` (typický odstup měření) a `truncated`
(true, pokud okno překročilo pojistku `series_max_rows`).

Starší režim `?limit=N` (posledních N řádků, 1–500) zůstává. Kombinace
`hours` + `limit` nebo neplatná hodnota → 400.

Graf přeruší čáru jen při mezeře delší než `chart_gap_minutes` (30 min)
a stanice je „neaktuální“ po `stale_after_seconds` (30 min) – obojí v
[config/app.php](config/app.php), nastaveno pro CRON s intervalem 5 min.

### `api/history.php?from=RRRR-MM-DD&to=RRRR-MM-DD&resolution=auto&format=json`

Měření za zvolené období – `from`/`to` jsou **místní** data (Europe/Prague)
včetně obou krajních dnů; bez parametrů posledních 7 dní včetně dneška.

| Parametr | Hodnoty | Poznámka |
|---|---|---|
| `resolution` | `auto` (výchozí), `raw`, `hour`, `day` | `auto`: do 3 dnů po 5 min, do 62 dnů po hodinách, jinak po dnech |
| `format` | `json` (výchozí), `csv` | CSV pro Excel: UTF-8, středník, desetinná čárka, místní čas |

Limity (v [config/app.php](config/app.php), `history_*`): období nejvýš 366 dní,
`raw` nejvýš 7 dní, `hour` nejvýš 186 dní. Budoucí datum, `from` po `to`
nebo překročený limit → 400 s českou zprávou.

Odpověď:
- `range` – `fromDate`, `toDate`, `days` a hranice `from`/`to` v UTC
  (správně i pro den přechodu na zimní čas, který má 25 h),
- `items` – u `raw` stejné položky jako `api/observations.php`; u `hour`/`day`
  agregace: `time` (začátek hodiny/dne v UTC), `samples`, `temp_avg/min/max`,
  `dew_point_avg`, `humidity_avg/min/max`, `pressure_avg/min/max`, `wind_avg`,
  `wind_max`, `gust_max`, `precip`, `precip_rate_max`, `uv_max`, `solar_avg`,
  `solar_max`; dny navíc `date` a `hours` (počet hodin s daty),
- `summary` – průměrná teplota, `temp_min`/`temp_max`/`gust_max` s časem,
  úhrn srážek `precip_sum` a nejdeštivější den, rozsahy tlaku a vlhkosti,
  max. UV a záření, `days` = klimatologické dny (mrazové Tmin < 0 °C,
  ledové Tmax < 0 °C, letní Tmax ≥ 25 °C, tropické Tmax ≥ 30 °C,
  tropické noci Tmin ≥ 20 °C, srážkové ≥ 1 mm).

Srážky: stanice posílá **denní čítač** (nuluje se o půlnoci), hodinový úhrn
je proto přírůstek čítače oproti předchozí hodině téhož dne; pokles čítače se
bere jako vynulování. Agregace se počítají z hodinových skupin v SQL
(`GROUP BY` hodina UTC) a do místních dnů se skládají v PHP – index
`idx_station_time (station_id, obs_time_utc)` dotaz výrazně zrychlí.

### `api/stats.php?year=RRRR|all`

Statistiky a rekordy: `year=RRRR` (výchozí letošní rok) → řádky po měsících
(do dneška), `year=all` → řádky po letech od prvního měření. Neplatný rok → 400.

Odpověď:
- `period` – `type` (year/all), `year`, `group` (month/year), `fromDate`, `toDate`,
- `years` – roky, za které jsou data (pro výběr), `dataFrom`/`dataTo`,
- `rows` – za měsíc/rok: `key`, `from`, `to`, `days`, `days_complete`,
  `temp_avg`, `temp_max_avg`, `temp_min_avg`, `temp_max`/`temp_min`/`gust_max`/`precip_max`
  jako `{value, date}`, `precip`, `rain_days`, `frost_days`, `ice_days`,
  `summer_days`, `tropical_days`, `tropical_nights`; `total` = totéž za celé období,
- `extremes` – absolutní `temp_max`, `temp_min`, `gust_max` s přesným časem,
- `records` – žebříčky top 5 dnů `{date, value}`: `temp_max_high`, `temp_min_low`,
  `temp_avg_high`, `temp_avg_low`, `temp_min_high`, `temp_max_low`,
  `precip_high`, `gust_high`, `range_high` (denní rozpětí).

Vychází z denních agregací `HistoryService`. Průměry, ledové dny, tropické noci,
„nejteplejší noci“, „nejchladnější dny“ a denní rozpětí se počítají jen z
**úplných dnů** (data alespoň z 18 hodin) – den s výpadkem stanice by je zkreslil.

Pod grafem „Teplota a srážky po měsících“ je **paprskový graf posledních 7 dní**
(`week.js`): 24 paprsků = hodiny místního času (0 h nahoře, po směru hodin),
jedna křivka = jeden den, dnešek oranžově. Přepíná se teplota, rosný bod, vlhkost,
tlak, nárazy, srážky, záření a UV (data z `api/history.php?resolution=hour`);
volba „Vítr (růžice)“ zobrazí větrnou růžici z `api/windrose.php`.

### `api/windrose.php?hours=N`

Větrná růžice za posledních `N` hodin (výchozí a maximum 168). Odpověď:
`sectors` (16 směrů od S), `classes` (`from`, `to`, `label`, `percent[16]`),
`calm` (% bezvětří < 1 km/h), `samples`, `prevailing`, `speedAvg`, `speedMax`,
`from`, `to`, `truncated`. Neplatné `hours` → 400.

### `api/health.php`

Diagnostika nasazení: verze PHP (major.minor), `pdoMysql`, `config`,
`database`, `latestObservationUtc`, `cache` (`ok` / `disabled` / `not_writable`).
Po odladění lze soubor smazat.

### `api/air-quality.php`

Aktuální hodinová data stanice ČHMÚ **Most (UMOM)** načítá serverově z pevně
povolených adres `opendata.chmi.cz`; prohlížeč nekomunikuje s ČHMÚ přímo.
API vrací PM10, PM2,5, NO2, SO2 a O3 pouze tehdy, pokud metadata potvrzují
aktivní měření, jednotku a platný typ hodnoty. Typy 5–7 (vyřazeno/chyba) se
nezobrazují jako koncentrace; typy 10–11 jsou označeny jako substituce.
Hodnoty `null`, chybějící řádky a neplatné kódy zůstávají odlišné od nuly.

Index kvality ovzduší se zobrazí pouze tehdy, pokud jej metadata UMOM označují
jako aktuální a záznam má úplný typ indexu. V ověřených metadatech UMOM tento
index není publikován jako real-time, proto stránka jeho nedostupnost vysvětluje
a nesnaží se jej odvozovat z koncentrací.

Metadata se ukládají na 24 hodin, hodinová data na 15 minut a číselníky na
30 dní do neveřejné `storage/cache/chmi/`. Při nedostupnosti upstreamu lze
zobrazit poslední cache s viditelným varováním a časem načtení; bez cache API
vrátí `503 CHMI_UNAVAILABLE`, nikoli prázdnou „úspěšnou“ odpověď. Současný
datový soubor je snímek, nikoli ověřená 24hodinová či sedmidenní časová řada;
graf historie proto zatím není součástí stránky.

Pro každou látku UMOM se navíc samostatně uchovává poslední platná koncentrace
v `storage/cache/chmi/air_last_valid_umom.json`. Chybový kód ani chybějící
řádek nového CSV tuto hodnotu nepřepíše. API v takovém případě vrací
`status: last_valid`, původní čas a typ hodnoty, `ageSeconds` a důvod
nedostupnosti nového měření. Stránka pod hodnotou uvádí, že není aktuální,
a její stáří v hodinách a minutách. Nová platná hodnota (včetně nuly) zobrazení
obnoví; starší snímek nesmí přepsat novější uložený údaj.

Úložiště vyžaduje právo zápisu pro PHP a nepodléhá 15minutové expiraci snímku.
Při chybě zápisu se zobrazí varování. Uchovávají se pouze hodnoty, které
aplikace skutečně načetla při požadavku na API; nejde o průběžný sběr CRONem
ani o automatické doplnění starší historie ČHMÚ. Pokud žádná platná hodnota
ještě nebyla zachycena, zůstává koncentrace nedostupná.

### `api/comparison.php`

Stránka [Srovnání s Kopisty](public/comparison.php) porovnává teplotu vlastní
stanice a ČHMÚ Kopisty (`0-20000-0-11433`) v °C za **24 uzavřených UTC hodin**.
Endpoint nemá volitelné období: další parametry vracejí 400. Surová vlastní
řada je omezena na 25 hodin a 3000 záznamů; z ČHMÚ se načtou nejvýše dva
denní desetiminutové JSON soubory a aktuální metadata stanice, prvků a kvality.
Veřejný klient nikdy nepředává URL upstreamu.

Obě řady se samostatně agregují prostým průměrem dostupných vzorků do hodin
`[začátek, konec)`. Nejde o oficiální hodinový produkt ČHMÚ ani důkaz úplnosti
hodiny. Tabulka uvádí počty přijatých vzorků i vyloučených hodnot Kopist.
ČHMÚ se přijímá jen s kvalitou **0 (Good)** a bez příznaku; neznámá kvalita,
odhad nebo chybějící hodnota nevstupují do průměru. API zveřejňuje počty
kódů kvality a příznaků. Vzorky stejného času s konfliktními hodnotami
se vyloučí. Mezery nejsou nuly a nedoplňuje se do nich poslední známá hodnota.

Rozdíl je `OuBrix − Kopisty` pouze pro hodiny, v nichž mají obě řady hodnotu.
Časy se zobrazují v Europe/Prague s UTC posunem, takže se rozliší obě
opakované hodiny při konci letního času. Kopisty jsou jiná lokalita asi
5,2 km od OuBrix; poloha a nadmořská výška se načítají z aktuálních metadat.
Jednotka ČHMÚ musí být potvrzena jako °C, nikoli odhadnuta podle hodnot.

Výpadek ČHMÚ nezablokuje vlastní řadu a výpadek DB nezablokuje Kopisty;
pokud selžou oba zdroje, API vrátí 503. Zastaralá gateway cache je označená.
Metadata se obnovují po UTC datech (TTL 24 h), měření po 15 minutách.
Hodnoty neznámé kvality mohou způsobit prázdnou řadu i při dostupném souboru;
stránka tento stav vysvětluje. Srážky a vítr nejsou do této první verze
srovnání zahrnuté.

## Lokální vývoj a testy

```powershell
# všechny testy (unit + integrační nad SQLite + HTTP test skutečných souborů public/)
php tests/run.php

# ověření desetinné teploty ve frontendových modulech (Node.js, bez balíčků)
node tests/Frontend/TemperaturePrecisionTest.mjs

# lokální náhled bez produkční DB (testovací data 26 h)
php -S 127.0.0.1:8765 -t public tests/support/router.php
# → http://127.0.0.1:8765/index.php, /history.php, /stats.php, /analytics.php, /air-quality.php

# kontrola spojení s produkční DB (na serveru přes SSH)
php bin/check-connection.php
```

Testy nepotřebují MariaDB ani síť – používají SQLite v paměti
([tests/support/Fixtures.php](tests/support/Fixtures.php)).

## Jak přidat další část

**Nový JSON endpoint** – vytvořit `public/api/nazev.php`:

```php
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\Api;

Api::run(static function (PDO $pdo, string $station) {
    // SQL patří do repozitáře, výpočty do služby
    return (new MojeSluzba(new ObservationRepository($pdo)))->data($station);
}, 60);
```

**Nová stránka** – šablona `resources/views/pages/nazev.php`, vstupní soubor
`public/nazev.php` volající `View::page('pages/nazev', [...], 'layouts/main')`,
odkaz v `resources/views/partials/header.php` a vlastní JS v `public/assets/js/`.

Pravidla:
- Pouze `SELECT` s parametry; do původní tabulky nikdy nezapisovat.
- Výstup v šablonách vždy přes `View::e()`.
- Kód v `app/` psát kompatibilně s PHP 7.4, dokud není ověřena verze na hostingu.
- `null` (chybějící měření) nikdy nenahrazovat nulou.

## Plán rozšíření

1. ✅ Aktuální stav stanice
2. ✅ Historie a grafy za zvolené období (5 min / hodiny / dny), souhrn, klimatologické dny, export CSV
3. ✅ Statistiky a rekordy (měsíce, roky, klimatologické dny, žebříčky nej… dnů)
4. ✅ Výkon: souborová cache souhrnů uzavřených měsíců (`storage/cache/`) a index
   `(station_id, obs_time_utc)`; starší návrh agregačních tabulek v DB
   ([database/migrations/001_weather_aggregates.sql](database/migrations/001_weather_aggregates.sql))
   není potřeba a není aplikován
5. Mapa (Leaflet), větrná růžice, porovnání s ČHMÚ (po ověření licence dat)

Podrobný návrh a analýza dat: [docs/architecture/README.md](docs/architecture/README.md),
datový model: [docs/architecture/data-model.md](docs/architecture/data-model.md).
