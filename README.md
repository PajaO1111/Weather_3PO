# OuBrix Weather – nový meteorologický portál

Nová webová aplikace pro amatérskou meteostanici **IMOST71** (Most).
Data **pouze čte** z původní tabulky `pws_observations`. Nově je připraven přímý
příjem ze Sencoru přes `station-upload.php` se zálohou z WU přes
`fetch_weather2.php` (nasazení viz [Přímý příjem](#přímý-příjem-ze-sencoru-a-záloha-weather-underground)). Původní stránky
(`history.php`, `history5.php`) zůstávají funkční.

> Stav: **Datový základ, Analytika srážek a Ovzduší ČHMÚ jsou nasazené části; nově je připraveno porovnání teploty, srážek a větru s Kopisty (fáze 4).**
> Analytika nyní obsahuje srážkové úhrny 30/60/90 dní. ČHMÚ má samostatnou
> stránku kvality ovzduší s měřením Most (UMOM), srovnáním okolní stanice,
> předpovědí, historií SVRS Ústeckého kraje, operativní mapou a pětiletým
> gridem ČHMÚ 2020–2024 pro okres Most (fáze 9E). Stránka historického Klima Kopisty
> nyní odděluje oficiální normály ČHMÚ 1991–2020 od vlastního výpočtu z archivu.
> Fáze 6 přidala textovou předpověď a mobilní rozbalovací menu; fáze 7 přidává
> výstrahy ČHMÚ pro ORP Most.
> Fáze 8 přidává mapu radaru MAX_Z_MASK a hodinového odhadu MERGE.
> Fenologie (9H) je připravena jako samostatná stránka nad historickými daty ČHMÚ;
> nasazení vyžaduje novou migraci a jednorázový import CSV.
> Další plán viz [Plán rozšíření](#plán-rozšíření).

## Požadavky

- PHP **7.4 nebo novější** (doporučeno 8.2+) s rozšířením `pdo_mysql`
- Pro výstrahy CAP také rozšíření `dom` a `libxml`; odchozí HTTPS s ověřením certifikátu
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
                  public/api/air-quality.php ─┤
                  public/api/air-quality-history.php ─┤
                  public/api/air-quality-forecast.php ─┘ ─► app/Weather/Gateways/ChmiGateway (pevné HTTPS zdroje, cache)
                  public/api/air-quality-five-year.php ─► předzpracovaný data/air_quality/most-2020-2024.json
                  public/api/phenology.php ─► app/Weather/Services/PhenologyService ─► tabulky phenology_*
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
│   ├── Services/AirQualityHistoryService.php    validace a časová osa úředních událostí SVRS
│   ├── Services/AirQualityForecastService.php   boxplotová předpověď ovzduší pro ORP Most
│   ├── Services/AirQualityFiveYearMapService.php výběr hodnot z verzovaného GIS výřezu
│   ├── Gateways/ChmiGateway.php                 allowlist HTTPS zdrojů ČHMÚ, limity, timeout a cache
│       ├── Services/WindRoseService.php             větrná růžice (16 směrů × třídy rychlosti)
│       ├── Support/Meteo.php                        směr větru, pocitová teplota, UV, tendence, Beaufort, odhad stavu
│       └── Support/Astro.php                        východ/západ Slunce, výška Slunce, fáze Měsíce
├── public/                           JEDINÁ veřejná složka
│   ├── index.php                     stránka „Aktuálně“
│   ├── phenology.php                 historický fenologický kalendář ČHMÚ
│   ├── history.php                   stránka „Historie“
│   ├── stats.php                     stránka „Statistiky a rekordy“
│   ├── analytics.php                 stránka „Analytika“ (srážky za 30/60/90 dní)
│   ├── air-quality.php             kvalita ovzduší, srovnání stanic a historie SVRS
│   ├── api/
│   │   ├── health.php                diagnostika nasazení (PHP, config, DB)
│   │   ├── current.php               aktuální stav stanice (JSON)
│   │   ├── observations.php          posledních N měření (JSON)
│   │   ├── history.php               měření za zvolené období (JSON / CSV)
│   │   ├── stats.php                 statistiky a rekordy za rok / celé období (JSON)
│   │   ├── analytics.php             naměřené srážky a úplnost dat (JSON)
│   │   ├── air-quality.php            měření UMOM + srovnatelná blízká stanice (JSON)
│   │   ├── air-quality-history.php    historická SVRS data pro Ústecký kraj (JSON)
│   │   ├── air-quality-forecast.php   modelová hodinová předpověď pro ORP Most (JSON)
│   │   ├── air-quality-map.php        operativní mapy kvality ovzduší ČHMÚ (JSON)
│   │   ├── air-quality-five-year.php  pětiletá GIS mapa pro okres Most (JSON)
│   │   ├── phenology.php             stanice, druhy, fenofáze, roky a srovnání (JSON)
│   │   └── windrose.php              větrná růžice za posledních N hodin / zvolené dny (JSON)
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
│           ├── air-quality.js        měření, srovnání, předpověď a SVRS historie
│           ├── phenology.js          kalendář, meziroční řada a srovnání výšek
│           └── week.js               paprskový graf 7 dní (24 h × den) a větrná růžice
├── resources/views/
│   ├── layouts/main.php              HTML kostra, knihovny
│   ├── partials/header.php, footer.php   hlavička s menu (Aktuálně, Historie, Statistiky)
│   ├── pages/dashboard.php           obsah stránky „Aktuálně“
│   ├── pages/history.php             obsah stránky „Historie“
│   ├── pages/stats.php               obsah stránky „Statistiky a rekordy“
│   └── pages/analytics.php           obsah stránky „Analytika“
├── bin/check-connection.php          kontrola DB z příkazové řádky (ne z webu)
├── data/air_quality/                 verzovaný výřez ČHMÚ a hranice okresu Most
├── data/fenology/                    zdrojová CSV ČHMÚ pro roky 2014–2025 (~4,9 MB)
├── database/migrations/003_phenology.sql nové tabulky fenologie
├── bin/import-phenology.php           atomický opakovatelný import CSV do DB
├── tools/air_quality/                offline GIS výřez (Python; mimo webové požadavky)
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
| Přímý příjem `STATION_UPLOAD_ID`, `STATION_UPLOAD_KEY` | `/www/domains/config.php` | vlastní ID/klíč pouze pro Sencor; nejde o API klíč ani Station Key WU, viz níže |
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

### Fenologie (fáze 9H)

Veřejná stránka [public/phenology.php](public/phenology.php) zobrazuje
historická pozorování ČHMÚ pro dostupné stanice, dřeviny/byliny, fenofáze
a roky. Výchozí stanicí jsou Doksany. Stránka uvádí výšku, souřadnice,
vzdálenost od Mostu, dostupné roky a zdroj dat.

Před prvním použitím v databázi, která už má aplikované migrace 001 a 002,
spusťte z kořene projektu:

```powershell
# Zálohujte databázi; tato migrace přidává pouze nové tabulky phenology_*.
mysql --host=HOST --user=UZIVATEL --password NAZEV_DATABAZE < database/migrations/003_phenology.sql
php bin/import-phenology.php
```

Import načte 76 CSV souborů z `data/fenology/` (19 dřevin, 18 bylin,
stanice a číselníky). Výchozí snapshot obsahuje data 2014–2025. Aktualizaci
proveďte výměnou souborů za novější export ČHMÚ ve stejné struktuře
`wood_species/` a `herbs/`, následovanou opětovným spuštěním importéru.
Import probíhá v transakci, lze jej bezpečně opakovat a přestavuje pouze
fenologické tabulky; původní meteorologické tabulky nemění.

Zvláštní kód `444` se ukládá jako „fenofáze nenastala“; chybějící řádek
zůstává chybějícím záznamem. Rozporné duplicity zůstávají viditelné jako
samostatný stav a nezapočítají se do grafů ani mediánů. Tento snapshot má
dvě takové rozporné kombinace. Shodné duplicitní řádky se uchovají s počtem
zdrojových hodnot. Výstup importéru shrnuje soubory, kombinace a konflikty.

API [public/api/phenology.php](public/api/phenology.php) vrací dostupné
filtry i grafová data. Na webu je viditelně uveden zdroj
[ČHMÚ – fenologická pozorování](https://opendata.chmi.cz/meteorology/phenology/).
Roční plánovaný běh importu/cronu a provozní předání zůstávají součástí 9J.

#### Ruční kontrola nových dat na webu

Na stránce Fenologie rozbalte **Správce – kontrola nových dat ČHMÚ**.
Tlačítko po zadání hesla odešle POST na `api/phenology-update.php`.
Server zkontroluje seznamy souborů obou kategorií, stáhne celou sadu včetně
metadat a porovná SHA-256 s tabulkou `phenology_import_files`.
Při shodě zobrazí „Nová data nejsou“ a DB nepřepisuje. Změněnou sadu zpracuje
stávající transakční importér, včetně oprav starších let. Nové soubory/druhy
objeví ze seznamu ČHMÚ; chybějící dříve importovaný soubor znamená chybu,
nikoli pokyn k mazání historie. Výpadek nebo neplatná data nejsou hlášeny
jako „nová data nejsou“. Nejde o aktuální pozorování ani pylovou předpověď.

**Jednorázové nastavení na hostingu:**

1. Zvolte samostatné dlouhé správcovské heslo (nikoli heslo k DB).
2. Na důvěryhodném počítači vytvořte jeho hash funkcí
   `password_hash($heslo, PASSWORD_DEFAULT)`. Heslo neposílejte do chatu,
   neukládejte do JavaScriptu ani do veřejného nastavení.
3. Do stávajícího tajného `config.php` mimo webový projekt přidejte
   `define('PHENOLOGY_ADMIN_PASSWORD_HASH', 'SEM_PATRI_HASH');`.
   Bez této konstanty je aktualizace zakázaná.
4. PHP musí mít zápis do `storage/` a odchozí HTTPS na `opendata.chmi.cz`.
   Zachovejte ochranu `storage/.htaccess`. Používejte pouze HTTPS.

Endpoint přijímá heslo v hlavičce `X-Phenology-Password` (percent-encoded
kvůli podpoře diakritiky), nikoli v URL.
Nepovoluje CORS; cizí HTML formulář nemůže tuto hlavičku přidat.
Po pěti chybných heslech za pět minut blokuje další pokusy do vypršení okna.
Souborový zámek nepovolí souběžné webové kontroly. CLI import nespouštějte
současně s webovou aktualizací.

Kontrola může trvat až tři minuty (hosting musí tak dlouhý PHP požadavek
povolit). Pracovní CSV se mažou po dokončení; nemění dodaný lokální snapshot
v `data/fenology/`. Při tvrdém ukončení PHP mohou zbýt podsložky `run-*`
v `storage/phenology-update/`; odstraňte jen tyto pracovní kopie, až žádný
import neběží. Při přerušení spojení mohl import doběhnout: obnovte stránku
a ověřte datum importu před opakováním. Průběžný cron toto tlačítko nezavádí.

### Fáze 8 – Radar ČHMÚ

Nová stránka [public/radar.php](public/radar.php) je v menu **ČHMÚ → Radar ČHMÚ**.
Používá Leaflet 1.9.4 (pevná CDN verze se SRI) a mapový podklad OpenStreetMap
s povinným uvedením autorství. Podklad obsahuje hranice, města a silnice;
průhledné datové PNG se překrývá nad ním. Podklad jde přímo z
`https://tile.openstreetmap.org` (předává se IP adresa návštěvníka), bez stahování
do zásoby či serverového proxy. Radarová data a legendy načítá pouze PHP gateway
z pevně povolených cest ČHMÚ. Pouze stránka radaru načítá Leaflet.

API [public/api/radar.php](public/api/radar.php) vrací nejnovější snímek:
`?product=maxz` (výchozí) nebo `?product=merge`. Podporuje GET/HEAD, nepovolený
produkt/tvar/další parametr odmítne 400, jiné metody 405, nevalidní/nedostupný
zdroj bez použitelné odpovědi 503. Není to veřejná proxy: klient nemůže zadat
URL ani název souboru. API nezávisí na DB.

| Produkt | Pevný adresář | Význam času UTC v názvu | Obnova cache |
|---|---|---|---|
| MAX_Z_MASK | `composite/maxz/png_masked/` | konec 5minutového intervalu | 5 minut |
| MERGE | `composite/merge1h/png/` | konec předchozího hodinového intervalu | 10 minut |

Adresáře jsou pod `https://opendata.chmi.cz/meteorology/weather/radar/`.
Z indexu se vybírá nejnovější validní UTC název s přesným prefixem/suffixem,
nikoli čas modifikace souboru nebo pořadí HTML. Přenos má ověřené TLS, zakázaná
přesměrování, timeout 8 sekund na zdroj, limity 2 MB index / 1 MB snímek /
100 kB legenda. Legendy `scl-dbz-mmh.png` a `scl-mm.png` mají cache 30 dní.
Udržují se nejvýše tři snímkové cache každého produktu, bez zásahu do ostatní
cache (například posledních platných koncentrací). Revize téhož snímku se
znovu načte po intervalu produktu.

**Georeference ověřena podle dokumentace ČHMÚ, kapitoly 23 a 25:**
EPSG:3857, PNG 680 × 460 px. Rozsah **celého obrázku** je
`[[48.047, 11.267], [52.167, 20.770]]` v pořadí `[zeměpisná šířka, délka]`.
Datová oblast je menší: `[[48.047, 11.267], [51.458, 19.624]]`.
Pro `imageOverlay` je nutný celý rozsah PNG, jinak se radar posune/roztáhne.
Výchozí projekce Leaflet EPSG:3857 odpovídá projekci rastru; nepřevádíme PNG
na rovnoměrnou síť stupňů. Polohy OuBrix `50.51039, 13.64525` a Kopisty
`50.54417, 13.62278` jsou orientační; Kopisty dle současného záznamu metadat ČHMÚ.

- Přepínání produktů, posuvník krytí 0–100 %, přiblížení/posun, návrat na Most
  nebo celou datovou oblast, značky obou stanic. Nová vrstva se ověří načtením
  obrázku, stará se při obnově odstraní; nemícháme čas, produkt a jiné PNG.
- API vrací `product`, `crs`, `bounds`, `dataBounds`, `frame` s intervalem a stářím,
  `stations`, `meta.sources`/`warnings` a ověřené PNG `image`/`legend` jako datové URI.
  Obrázek a čas jsou tak ze stejné odpovědi; nevzniká závod dvou požadavků „latest“.
  PNG se kontroluje podle signatury, rozměrů, úplnosti chunků a CRC.
- MAX_Z_MASK je odrazivost v dBZ, **ne naměřený srážkový úhrn**. Světlejší barvy
  doplňují masku dopadu srážek na povrch podle PseudoCAPPI 2 km (práh 7 dBZ).
  Převod dBZ na mm/h v oficiální legendě je pouze radarový odhad.
- MERGE je kombinovaný plošný odhad mm za uvedenou hodinu, nikoli okamžitá
  intenzita. Každých deset minut se hodinové okno posouvá; **překrývající se
  úhrny se nesčítají**.
- Zdrojový čas je konec intervalu v UTC, UI zobrazuje začátek i konec v Europe/Prague.
  Snímky starší než 20 minut MAX_Z_MASK / 60 minut MERGE se označují jako zastaralé.
  Jsou to pravidla aplikace s tolerancí latence, nikoli záruka poskytovatele.
  Stará cache při výpadku je viditelně označena, chyba nikdy neznamená „bez srážek“.
  Průhledné/šedé/chybějící pixely nejsou garancí nulových srážek.
- První verze nemá animaci, HDF5, klikání na číselné hodnoty ani vlastní
  vektorové hranice nad radarem. Hranice jsou v podkladu; viditelnost lze
  zlepšit snížením krytí. Žádné automatické dlouhé smyčky ani plošné stahování.

**Nasazení pouze do `/www/domains/oubrix.eu/Weather/`:** nahrát nové i změněné
soubory v `app/`, `public/` (CSS a `assets/js/radar.js`), `resources/` a veřejný
`config/app.php` (nové CDN definice Leaflet). Neměnit tajný `config.php`, `.env`,
DB, CRON ani `.htaccess`; zachovat existující neveřejnou cache.
Pokud nadřazený web používá vlastní CSP, musí `img-src` povolovat
`https://tile.openstreetmap.org` a `data:` a knihovny z `https://cdn.jsdelivr.net`.
Při blokování podkladu nebo knihovny se zobrazí chyba; CSP automaticky neměníme.

Po nahrání ověřit `public/radar.php` a obě varianty `public/api/radar.php`
(200), mapový podklad, obě legendy, časové intervaly, přepnutí produktu a mobil.

```powershell
php tests\run.php
node tests\Frontend\RadarTest.mjs
node tests\Frontend\NavigationTest.mjs
```

### Fáze 7 – Výstrahy ČHMÚ pro ORP Most

Stránka [public/warnings.php](public/warnings.php) je v menu **ČHMÚ → Výstrahy – Most**.
API [public/api/warnings.php](public/api/warnings.php) podporuje GET/HEAD, nepřijímá
parametry (400), odmítá ostatní metody (405) a při nevalidním/nedostupném zdroji
bez použitelné odpovědi vrací 503. Nezávisí na DB ani tajných přístupových údajích.

**Ověřený zdroj (27. 10. 2026):** [konfigurace oficiálního prohlížeče](https://vystrahy-cr.chmi.cz/config/download-cap.json)
odkazuje pevný [aktuální CAP XML](https://vystrahy-cr.chmi.cz/data2/XOCZ50_OKPR.xml),
který vrátil HTTP 200. Jde o aktuální souhrnnou obálku, nikoli historii.
Archivní `alert_cap_50_DDHHMM.xml` soubory se do aktuálního stavu nemíchají.
[Metadata oficiálního prohlížeče](https://vystrahy-cr.chmi.cz/config/global.json)
potvrzují `cz.chmi.orp:4209 = Most`. Filtr používá přesnou hodnotu `CISORP = 4209`,
nikoli jméno kraje či hledání podřetězce.

- Pevný HTTPS zdroj, ověřené TLS, bez přesměrování, timeout 8 sekund, limit 4 MB,
  cache 5 minut při načtení. Nejde o push notifikace ani průběžný monitoring.
- CAP 1.2 XML odmítá DTD/entity, jiný namespace/kořen, neplatná data a číselníky.
  Zachovává identifikátor, odesílatele, reference, status, typ zprávy, info/oblasti,
  kódy jevů i parametry. České bloky mají přednost před odpovídajícími překlady;
  cizojazyčný blok bez českého protějšku zůstává viditelný s upozorněním.
- `Update` nahrazuje odkazovanou obálku, `Cancel` ji ruší ještě před územním
  filtrem. Aktuální snapshot nepřebírá odstraněné jevy z předchozí cache.
  Reference identifikují kombinaci `sender,identifier,sent`. Testovací nebo
  neveřejná zpráva nesmí odvolat skutečnou veřejnou výstrahu.
- Zobrazuje se jev, textová závažnost a barva, pravděpodobnost, naléhavost,
  vydání/stáří, výskyt jevu, platnost zprávy, oblast včetně výškových omezení,
  text a pokyny ČHMÚ. Časy s posunem se normalizují do UTC a zobrazují v Europe/Prague.
  `eventEndingTime` je konec **výskytu jevu**, nikoli konec platnosti zprávy `expires`.
  Oba časy jsou oddělené; chybějící konec nevymýšlíme (možná platnost do odvolání).
- Kombinace `Minor + Unlikely` znamená dle [dokumentace CAP ČHMÚ](https://opendata.chmi.cz/meteorology/weather/alerts/metadata/Dokumentace_CAP.pdf)
  minimální/žádné nebezpečí a žádnou/zrušenou výstrahu; není prezentována jako aktivní
  varování. Tyto bloky, ukončené a testovací zprávy jsou v rozbalovacím detailu.
  Neúplný čas/území, neznámé hodnoty, prázdný výběr nebo samotný Cancel nejsou „bez výstrah“.
- Vydání starší než 24 hodin je z opatrnosti označeno jako zastaralé, i když bylo
  právě staženo; jde o pravidlo aplikace, ne lhůtu ČHMÚ. Stará cache při výpadku
  nesmí potvrdit aktuální stav ani hlášení bez výstrah.
- Dokumentace i historická aktivní obálka potvrzují jevy SIVS/HPPS; aktuální
  soubor obsahuje i smogové skupiny O3, PM10, NO2 a SO2. Události `Env` a parametry
  SVRS zobrazujeme jako výstrahy, **ne koncentrace ani index INDX**. Nejde
  o kompletní stav jednotlivých vodoměrných profilů ani samostatný týdenní HAMR
  produkt `alert_cap_70_*`; tyto další zdroje nejsou integrovány.

API obsahuje `area`, `summary` (`warnings`, `no_warning_reported`, `unverified`),
`items` se zdrojovou obálkou a info blokem, odvolání/technické zprávy `notices`
a `meta` s časem vydání, načtení, cache a upozorněními. `no_warning_reported`
znamená jen výslovná hlášení zdroje, nikoli garanci nepřítomnosti nebezpečí.
Veškerý zdrojový text se vykresluje jako text, nikoli HTML; odkazy na oficiální
výstrahy a CAP jsou pevné.

**Nahrát pouze do `/www/domains/oubrix.eu/Weather/`:** nové i změněné soubory
v `app/`, `public/` (včetně CSS a `assets/js/warnings.js`) a `resources/`.
Zachovat existující neveřejnou cache; nepřepisovat hlavní web o úroveň výše.
DB, CRON, `.env`, tajný `config.php`, veřejný `config/app.php` ani `.htaccess`
se v této fázi nemění. Testy a fixture cache nepatří na hosting.

Po nahrání ověřit `public/warnings.php` a `public/api/warnings.php` (200),
odkaz v mobilním menu, vydání/stáří a detail hlášení. Při výpadku musí být jasné,
že aktuální stav nelze ověřit. Přehled nenahrazuje oficiální výstražnou službu.

```powershell
php tests\run.php
node tests\Frontend\WarningsTest.mjs
node tests\Frontend\NavigationTest.mjs
```

### Fáze 6 – Předpověď pro Most a kompaktní menu

Nová stránka [public/forecast.php](public/forecast.php) je v rozbalovacím menu
ve skupině **ČHMÚ → Předpověď ČHMÚ**. API
[public/api/forecast.php](public/api/forecast.php) načítá pevný zdroj
`https://data-provider.chmi.cz/api/graphs/graf.meteogram/282` serverově přes
`ChmiGateway`, s timeoutem 8 sekund, ověřením TLS, bez přesměrování, limitem
250 kB a cache 30 minut. Nezávisí na DB ani tajných přístupových údajích.
Parser ověřuje časovou posloupnost i rozsahy vstupních hodnot a odmítá
neplatnou strukturu. Při výpadku ČHMÚ lze vrátit jen označenou starou cache.
Stránka nyní zobrazuje hodinovou předpověď modelu ALADIN pro nejbližší výpočtový
bod ČHMÚ u Mostu (bod 282, 280 m n. m.) v denních kartách. API ČHMÚ poskytuje
hodinovou teplotu, srážky, sníh, vítr, tlak, oblačnost a kód ikony; denní minima,
maxima a součty počítá aplikace. Srážky a sníh se zobrazují v mm, sníh jako vodní
hodnota, nikoli výška pokrývky. Částečné dny jsou označeny. Jde o výstup jednoho
modelu v nejbližším bodě, nikoli o kombinaci modelů ani předpověď pro každou část
města. Odkaz na oficiální [meteogram ČHMÚ pro Most](https://www.chmi.cz/meteogram/282-most)
zůstává dostupný pro detailní graf.

API nepřijímá parametry oblasti/stanice: neplatný dotaz vrací 400, nepovolená
metoda 405 a nedostupná/nevalidní data bez použitelné odpovědi 503.
Tlačítko **Znovu načíst** opakuje načtení API, nikoli vynucení obnovy cache.

Navigace používá nativní `<details>/<summary>` **Menu** i na desktopu,
členěné na Vlastní stanici, Analytiku a ČHMÚ. Nevyžaduje Bootstrap JS ani
nové knihovny. Rozbalení funguje bez JavaScriptu a z klávesnice; malý modul
navíc zavírá menu po kliknutí mimo něj a klávesou Escape s návratem fokusu.
Aktivní stránka má `aria-current="page"`. Na mobilu se panel vejde do šířky
hlavičky, dlouhá nabídka má vlastní svislé posouvání a motiv zůstává dostupný.

**Nasazení pouze do `/www/domains/oubrix.eu/Weather/`:** nahrát nové i změněné
soubory v `app/`, `public/` (včetně CSS a obou nových JS modulů) a `resources/`.
Nenahrávat je do adresářů hlavního webu o úroveň výše. Ve fázi 6 se nemění
DB, CRON, tajný `config.php`, `.env` ani `.htaccess`. Existující neveřejnou
cache zachovat. Po nahrání ověřit `public/forecast.php`, `public/api/forecast.php`,
otevření menu na mobilu, odkazy a přepnutí motivu.

```powershell
php tests\run.php
node tests\Frontend\ForecastTest.mjs
node tests\Frontend\NavigationTest.mjs
node tests\Frontend\ClimateTest.mjs
node tests\Frontend\TemperaturePrecisionTest.mjs
```

### Fáze 5 – Klima Kopisty

Stránka [public/climate.php](public/climate.php) je dostupná z menu **Klima Kopisty**.
Nezávisí na databázi vlastní stanice. API [public/api/climate.php](public/api/climate.php)
vrací 12 měsíců vybraného roku, referenci a počty přijatých let; bez parametru
vybírá nejnovější rok publikovaného archivu. `?year=RRRR` vybírá jiný publikovaný
uzavřený rok. Neplatný, nepublikovaný či aktuální rok vrací 400, nepovolená metoda
405, nedostupná/nevalidní data bez použitelné cache 503.

- Pevný zdroj `Open.Data.MLY` pro stanici `0-20000-0-11433`, nikoli regionální CSV:
  [měsíční JSON Kopisty](https://opendata.chmi.cz/meteorology/climate/historical/data/monthly/mly-0-20000-0-11433.json).
- Výběr je přesný: teplota `T / AVG / AVG`, srážky `SRA / 06:00 / SUM`.
  Jednotky °C a mm se ověřují v historických metadatech denních prvků DLY,
  nikoli převzetím mapování z aktuálních desetiminutových dat.
- Referenční měsíční hodnota je průměr stejného měsíce v letech 1991–2020;
  zobrazí se jen při 30 přijatých hodnotách. Počet přijatých let je v tabulce.
  Chybějící údaje nejsou nuly. Příznaky `FLAG_REPEAT` a `FLAG_INTERRUPTED` i původní
  číselná hodnota zůstávají v API; označené hodnoty se nepoužívají ve výpočtech/grafech.
  Duplicity a neplatné hodnoty jsou explicitní chybou zdroje, nikoli náhradní nulou.
- Roční srážky vyžadují 12 přijatých měsíců. Referenční roční úhrn je průměr
  30 úplných ročních součtů. Roční průměr teploty se nepočítá.
- Oficiální normály teploty a srážek jsou samostatné produkty ČHMÚ 1991–2020
  pro stanici Kopisty. Měsíční odchylky se počítají jen z přijatých hodnot
  bez příznaků; srážkové procento je hodnota / normál × 100.
- Dříve počítaný **vlastní referenční průměr z archivu MLY** zůstává samostatně
  zobrazený a nesmí být zaměněn za oficiální normál. Normál Kopist není normálem
  lokality OuBrix. Archivní řada nepotvrzuje homogenitu ani úplnost denních podkladů.
- Archiv se znovu načítá po 24 hodinách, metadata a oficiální normály po 7 dnech, včetně jejich
  publikovaného data vytvoření a verze. Revize tak nezůstanou v cache neomezeně.
  Při výpadku se použije pouze viditelně označená stará cache.
  Probíhající rok/recent, API30 ani jevy/phenomena nejsou do této stránky přimíchány.

**Nasazení změn fáze 5:** nahrát nové i upravené soubory v `app/`, `public/`,
`resources/` a veřejný `config/app.php`. Neměnit tajný `config.php`, CRON ani DB.
`storage/cache/chmi/` musí být zapisovatelná pro PHP; její existující obsah
(zejména poslední platné údaje ovzduší) nemažte ani nepřepisujte lokální kopií.
Volitelná proměnná prostředí `WEATHER_CHMI_CACHE_DIR` určuje oddělenou neveřejnou
cache (používají ji i izolované HTTP testy). Testy a jejich fixture data na hosting
nepatří. První načtení stáhne přibližně 18 MB zdrojů; může trvat déle než další
načtení. Lokálně byl vrchol paměti PHP přibližně 92 MiB, doporučen je limit alespoň 128 MiB.

Ověření po nahrání:
`public/climate.php` a `public/api/climate.php` musí vracet 200, výběr jiného roku
musí změnit grafy a tabulku. Zkontrolovat označení reference, počty `/30`,
chybějící měsíce a informace o verzi zdrojů.

Lokální regresní testy bez tajných údajů:

```powershell
php tests\run.php
node tests\Frontend\ClimateTest.mjs
node tests\Frontend\TemperaturePrecisionTest.mjs
```

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
Samotná úprava přesnosti DB schéma nemění. Nový přímý příjem ale vyžaduje
migraci 004 popsanou níže. Nepřenášet hesla ani měnit CRON token.

### Přímý příjem ze Sencoru a záloha Weather Underground

Podrobný ověřený postup pro firmware 2.06, včetně přímé subdomény mimo CDN
a diagnostiky, je v [Sencor_implementace.md](Sencor_implementace.md).

Pro SWS 12500 s vlastním serverem je připraven příjem
protokolu Weather Underground (parametry `ID`, `PASSWORD`, `dateutc`,
`tempf`, `humidity`, `baromin` atd.). **Stanice sama odesílá na server**;
web ani CRON se nepřipojuje do domácí sítě.

- `station-upload.php` přijímá GET nebo formulářový POST, ověřuje samostatné
  přihlašovací údaje a odpovídá `success` až po potvrzeném zápisu do DB.
- `fetch_weather2.php` zůstává spouštěný CRONem každých 5 minut.
  Pokud poslední platný přímý příjem **i čas jeho měření** jsou mladší
  než 10 minut, loguje `SKIP reason=direct_fresh` a WU vůbec nevolá.
  Po 10 minutách může použít čerstvé aktuální měření z WU.
  Při pětiminutovém CRONu se záloha aktivuje při prvním běhu po uplynutí limitu.
- Oba zdroje zapisují přes `weather-ingest.php` do původní tabulky.
  Nové tabulky `weather_ingest_state` a `weather_ingest_slots` drží stav,
  zdroj (`direct`/`wu`) a unikátní interval stanice + 5 minut UTC.
  Databázový zámek stavu stanice a transakce chrání i souběžné požadavky.
- Interval například 12:00:00–12:04:59 UTC obsahuje jediný spravovaný záznam.
  Zůstává poslední přímé měření daného intervalu; starší a opakovaná přímá
  měření jej nepřepíšou. Přímý příjem nahradí záložní záznam ve stejném
  intervalu i při rozdílném čase. WU přímé měření nikdy nepřepíše.
  Původní skutečný čas měření se nezahlazuje na začátek intervalu.
- Chybné, neúplné a 10 nebo více minut staré zprávy neobnovují stav primárního
  zdroje. Čas více než 2 minuty v budoucnosti je odmítnut.
  `dateutc=now` znamená čas přijetí na serveru.
  V takové zprávě nelze poznat, zda konzole opakuje staré hodnoty ze senzoru.
  Minimem jsou venkovní teplota, vlhkost a tlak; chybějící volitelné veličiny
  zůstávají NULL, nikoli nula. Nejde o zpětné doplňování historie.
- Fahrenheit se převádí na °C, mph na km/h, inHg na hPa a srážky z palců na mm.
  `dailyrainin` je denní čítač, nikoli součet zpráv; nuluje se podle stanice.
  `rainin` se stejně jako v [komunitní integraci SWS 12500](https://github.com/schizza/SWS-12500-custom-component)
  vyhodnocuje jako intenzita v in/h; před ostrým provozem porovnat s displejem,
  protože obecný WU protokol
  používá toto pole i pro úhrn za poslední hodinu. Pokud konkrétní firmware
  posílá úhrn, nesmí se zaměnit za okamžitou intenzitu.
  Metadata (místo, souřadnice, výška) se pro přímý příjem zachovají z posledního
  záznamu stanice. Bez původních dat zůstanou NULL. Přímá měření nemají WU QC.
- Staré duplicity se hromadně nemažou. Při prvním novém zápisu do intervalu
  s existujícími daty se převezme poslední původní řádek; ostatní historické
  řádky zůstanou. Pravidlo jednoho záznamu platí pro nově spravované intervaly.

#### Nasazení

1. Zálohovat DB a původní sběrný skript. Ověřit, že `pws_observations`
   používá **InnoDB**; jiný engine přijímač odmítne. Spustit jednou
   [004_weather_ingest.sql](database/migrations/004_weather_ingest.sql).
   Migrace přidá dvě tabulky, ale nemění ani nemaže původní měření.
2. Do **stejného kořene webu** `/www/domains/oubrix.eu/` nahrát společně
   `station-upload.php`, `weather-ingest.php` a upravený `fetch_weather2.php`.
   Nestačí je nahrát pouze do `Weather/`. Skripty načítají původní neveřejný
   `/www/domains/config.php`; nemění se URL CRONu ani `FETCH_TOKEN`.
3. V neveřejném configu přidat:

   ```php
   define('STATION_UPLOAD_ID', 'IMOST71');
   define('STATION_UPLOAD_KEY', '<vlastni-nahodny-klic-32-znaku>');
   ```

   Klíč vygenerovat lokálně například `php -r "echo bin2hex(random_bytes(16));"`.
   Nahradit ukázkovou hodnotu; minimum je 32 znaků. ID pro přímý příjem může
   být jiné než `IMOST71`, ale všechny záznamy se ukládají pod `WU_STATION_ID`,
   aby stávající stránky viděly data. Bez `STATION_UPLOAD_ID` se použije
   `WU_STATION_ID`. Použít **jiný klíč** než WU Station Key, WU API key a CRON token.
4. V nastavení vlastního serveru Sencoru (sekce *Weather Server Setup*, třetí
   blok) zadat do pole **URL jen název přijímacího serveru** – bez `http://`
   a bez cesty –, v ověřeném nasazení `station.oubrix.eu` podle podrobného návodu,
   zvolené Station ID a nový Station Key.
   Odesílání na Weather Underground i Weathercloud ponechat zapnuté.
   Ověřený firmware 2.06 pole URL bere jako hostitele, sám posílá HTTP GET na
   `http://<URL>/weatherstation/updateweatherstation.php` (port 80, bez HTTPS,
   bez nutnosti přesměrování). Na hlavní doméně kořenový `.htaccess` tuto
   cestu směruje na `station-upload.php` a vyjímá ji z vynucení HTTPS.
   Na ověřené přímé subdoméně se používá předávací PHP soubor z podrobného návodu.
   Klíč tak putuje otevřeně – musí být jedinečný a nepoužitý jinde.
   Zamítnutý požadavek zapisuje do logu cestu, názvy parametrů a shodu
   ID/klíče (bez hodnot).
5. Zajistit, aby `fetch_weather.php`, staré kopie CRONu nebo jiné importéry
   nezapisovaly souběžně mimo tento společný mechanismus.
6. Sledovat neveřejné logy `/www/domains/station-upload.log` a
   `/www/domains/fetch_weather2.log` (nové řádky mají čas UTC se suffixem `Z`).
   Přímý příjem: `OK`, následně `UPDATED` či `EXISTS`; CRON: `SKIP`.
   Při výpadku pouze vlastního serveru na stanici po 10 minutách očekávat
   WU `OK`/`UPDATED`/`EXISTS`; po obnovení přímého příjmu opět `SKIP`.
   Nevypínat při této zkoušce Wi-Fi celé stanice, jinak vypadne i odesílání na WU.
   `NODATA http=204` znamená, že ani WU aktuální data nemá.
   HTTP 400 znamená vadná/starší data, 403 špatné přihlašovací údaje,
   503 chybu konfigurace, migrace či DB. `DENIED` po pouhém otevření URL
   bez údajů stanice je očekávané.

Oba sběrné endpointy musí být na hostingu/CDN vyřazené z cache.
Záloha řeší výpadek přímého doručování; při výpadku celé DB nebo hostingu
nemůže zapisovat ani jeden zdroj.
Vlastní logy klíče neobsahují, ale firmware je může posílat v query stringu:
access logy hostingu/CDN mohou `PASSWORD` zaznamenat. Přístup k nim chraňte,
pokud hosting dovolí, nastavte maskování, a logy nesdílejte bez odstranění
hesel a tokenů. Neupravujte ani neposílejte skutečný config do repozitáře.

Lokální kontrola: `php tests/run.php` zahrnuje jednotkové testy převodů,
času a desetiminutového limitu. Pro volitelný integrační test MySQL nastavit
`WEATHER_INGEST_MYSQL_DSN` (např. `mysql:host=127.0.0.1;port=33379;charset=utf8mb4`),
`WEATHER_INGEST_MYSQL_USER` a `WEATHER_INGEST_MYSQL_PASS` a spustit stejný příkaz.
Použít pouze oddělený testovací server: test potřebuje oprávnění vytvářet DB
a triggery; vytvoří a odstraní vlastní náhodně pojmenovanou databázi.
Ověřuje souběh procesů, návrat primárního zdroje, rollback a HTTP endpointy.
Nepřipojuje se na WU ani nečte produkční hesla.

### Nahrání webové aplikace

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

Pod grafem „Teplota a srážky po měsících“ je **paprskový graf 7 dní**
(`week.js`): 24 paprsků = hodiny místního času (0 h nahoře, po směru hodin),
jedna křivka = jeden den. Výchozí je posledních 7 dní (dnešek oranžově); výběrem
„Období“ (nebo šipkami ‹ ›) lze zobrazit kterýkoli **kalendářní týden ISO**
(po–ne, `?week=2026-W43` v adrese, funguje Zpět/Vpřed a nezávisí na `?year=`).
Dny bez dat se nekreslí a pod grafem je uvedeno, že období je neúplné.
Přepíná se teplota, rosný bod, vlhkost, tlak, nárazy, srážky, záření a UV
(data z `api/history.php?resolution=hour`); volba „Vítr (růžice)“ zobrazí větrnou
růžici z `api/windrose.php` za stejné období. Graf se na velkém monitoru zvětší
(Full HD ~ 650 px, QHD ~ 940 px) včetně písma a popisků všech hodin.

### `api/windrose.php?hours=N` / `?from=RRRR-MM-DD&to=RRRR-MM-DD`

Větrná růžice za posledních `N` hodin (výchozí a maximum 168), nebo za místní
dny `from`–`to` včetně obou krajních (nejvýš 7 dní, `to` ne v budoucnu; navíc
vrací `fromDate`, `toDate`). `hours` nelze kombinovat s `from`/`to`. Odpověď:
`sectors` (16 směrů od S), `classes` (`from`, `to`, `label`, `percent[16]`),
`calm` (% bezvětří < 1 km/h), `samples`, `prevailing`, `speedAvg`, `speedMax`,
`from`, `to`, `truncated`. Neplatné parametry → 400.

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
historie koncentrací proto není součástí stránky. Historie SVRS je oddělený
záznam úředních vyhlášení, nikoli měření koncentrací.

Pro každou lokalitu a látku se navíc samostatně uchovává poslední platná koncentrace
v `storage/cache/chmi/air_last_valid_<lokalita>.json` (například `air_last_valid_umom.json`).
Chybový kód ani chybějící
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

Srovnávací seznam tvoří nejvýše 12 aktivních stanic do 75 km od UMOM, které
podle aktuálních metadat sdílejí alespoň jednu real-time látku, jednotku
a interval. Výběr je validován proti tomuto seznamu; poslední platné hodnoty
se ukládají do odděleného souboru lokality a registrace. Rozdíl koncentrací
se zobrazí jen pro stejnou látku, jednotku, interval a přesně shodný UTC čas.
Rozdíl `UMOM − stanice` není korekcí místních vlivů ani důkazem reprezentativnosti.

### `api/air-quality-history.php`

Server načítá CSV [historického přehledu SVRS ČHMÚ](https://opendata.chmi.cz/air_quality/historical/smog/historicky_prehled_smogovych_situaci.csv)
a vrací časovou osu jen pro oblast **Ústecký kraj**. Zdroj obsahuje typ události,
látku, čas vyhlášení/odvolání, délku trvání a poznámku; explicitní časové offsety
se normalizují do UTC, v prohlížeči se zobrazují v místním čase.
Otevřená událost zůstává bez času odvolání. Překrývající se smogová situace,
regulace a varování jsou jednotlivé záznamy a nesčítají se do počtu nezávislých
událostí. Stránka nabízí filtry podle roku, látky a typu.

CSV má 24hodinovou cache a je připisováno ČHMÚ pod licencí [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
Při výpadku zdroje se případná stará cache viditelně označí. ČHMÚ upozorňuje,
že pravidla SVRS se měnila (mimo jiné v letech 2012, 2017 a 2025), proto historické
události napříč těmito obdobími nelze bez další metodiky považovat za přímo srovnatelné.
Historická vyhlášení nejsou aktuálními CAP výstrahami ani místními koncentracemi.

### `api/air-quality-forecast.php?pollutant=PM10`

Modelová hodinová předpověď ČHMÚ pro ORP Most, samostatná od měření UMOM.
`pollutant` přijímá pouze `PM10`, `PM2_5`, `NO2`, `O3` nebo `SO2` (výchozí
`PM10`). API načítá index publikovaných ZIPů a kontroluje tři nejnověji
publikované archivy; vybraný běh určuje nejvyšší `datumVytvoreni` uvnitř jejich
JSONů, nikoli číslo hodinového slotu v názvu ZIPu. V JSONu se ověřuje ORP kód
892 (`CZ-RUIAN-ORP`), hodinové intervaly, hodnoty a pořadí percentilů.

Odpověď obsahuje `area`, `pollutant`, samostatný volitelný `currentMap`
(záznam typu `mapa`), hodinové `items` typu `predpoved` a `meta` s časem
vydání, stářím, zdrojem, cache a upozorněními. Graf kreslí P50 a volitelné
pásmo P25–P75; minimum a maximum jsou dostupné v popisku. Časy jsou převedeny
z UTC do Europe/Prague. Oficiální schéma neuvádí jednotku ani podrobnosti
statistického vzorku, proto API vrací `pollutant.unit: null`; pásmo se
neprezentuje jako pravděpodobnostní interval.

Přístup k ZIPům je přes pevný allowlist gateway. Archiv se neextrahuje;
kontroluje se velikost ZIPu (2,5 MB), počet položek (250), celková
rozbalená velikost (8 MB) a cílový JSON (150 KB). Hosting musí mít povolené
PHP rozšíření `ZipArchive`, jinak endpoint vrací 503. Index i archivy mají
15minutovou cache; zastaralá data jsou označena, nedostupný nebo neplatný
zdroj nevrací prázdnou úspěšnou předpověď.

### `api/air-quality-map.php?component=PM10&type=hours`

Operativní mapa ČHMÚ se zobrazuje jako průhledná vrstva nad mapovým podkladem
OpenStreetMap. `component` podporuje `PM10`, `PM2_5`, `NO2`, `O3` a `SO2`;
parametr `type` nabízí pouze kombinace zveřejněné v legendě ČHMÚ:
hodinový průměr, denní průměr a u příslušných látek také denní maximum
hodinového nebo osmihodinového průměru. API volí nejnověji publikovaný soubor
podle času v katalogovém indexu, ověří obsah ZIPu, jeho časové období, PNG
a statická metadata. Zobrazuje oficiální legendu, interval dat, stáří a stav
cache. Při výpadku upstreamu se starší uložená mapa označí jako zastaralá.

Georeference vychází z rozsahu EPSG:3857 v oficiálních statických metadatech;
kontrolují se i souřadnice rohů a poměr stran PNG. U aktuálně ověřeného souboru
je rozsah gridu 895,5 × 576 km, PNG má 3000 × 1929 px a metadata uvádějí
100m krok zdrojového gridu. Zobrazený PNG pixel proto pokrývá přibližně
299 × 299 m. Barevná legenda vyjadřuje intervaly, nikoli přesnou koncentraci;
API ani mapa neumožňují bodový číselný odečet. Jde o plošný výstup vytvořený
z operativních měření a dalších podkladů, nikoli o měření v každém místě
nebo předpověď.

Katalog a archivy mají pětiminutovou cache, statická metadata týdenní.
ZIP se čte bez extrakce, s omezením velikosti; hosting potřebuje PHP
rozšíření `ZipArchive`. Mapa je připisována ČHMÚ pod licencí [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
Při použití podkladu OpenStreetMap se provozovateli dlaždic předává IP adresa.

### `api/air-quality-five-year.php?component=PM10`

Pětileté mapy používají oficiální archiv ČHMÚ za období **2020–2024**
(vydání katalogu 26. 11. 2025). Mimo webový požadavek je z něj vytvořen
verzovaný výřez **okresu Most**: 486 buněk gridu 1 × 1 km. Buňka se zařadí,
pokud její střed leží uvnitř okresu; hraniční buňky se neořezávají a mohou
částečně přesahovat správní hranici. Souřadnice jsou v EPSG:4326, výchozí
data jsou v EPSG:32633. Stránka zpřístupňuje všech devět ročních vrstev a
samostatnou 24hodinovou vrstvu SO₂ a PM10. Tyto dvě statistiky PM10 nejsou
zaměnitelné. Zobrazená desetinná přesnost odpovídá hodnotám zdroje: jedna
desetinná pro roční vrstvu, celá čísla pro 24hodinovou vrstvu.

Legenda používá pět stejně širokých intervalů z rozsahu právě zvolené látky
v tomto výřezu. Nejde o stupnici limitů ani srovnání mezi látkami. Kliknutí
na buňku ukáže hodnotu v µg/m³ a její ČHMÚ grid ID. Chybějící hodnoty se
zobrazují zvlášť. Pětiletá prostorová vrstva je plošný produkt ČHMÚ, nikoli
měření stanice UMOM ani časová řada; sousední pětiletá období se překrývají.

Výstup a zdrojové hashe jsou v
[`data/air_quality/most-2020-2024.json`](data/air_quality/most-2020-2024.json).
Přesný vstupní archiv se do repozitáře nekopíruje; výřez lze znovu vytvořit
offline pomocí Pythonu 3.10+ a knihoven v
[`tools/air_quality/requirements.txt`](tools/air_quality/requirements.txt):

```powershell
py -3.10 -m pip install -r tools/air_quality/requirements.txt
curl.exe --fail --location "https://opendata.chmi.cz/air_quality/products/5year_avg/sit1000_5lprum_20_24_CR_UTM.zip" --output "$env:TEMP\sit1000_5lprum_20_24_CR_UTM.zip"
py -3.10 tools/air_quality/build_five_year_extract.py `
  --archive "$env:TEMP\sit1000_5lprum_20_24_CR_UTM.zip" `
  --boundary data/air_quality/okres-most-ruian.geojson `
  --output data/air_quality/most-2020-2024.json
```

Generátor bez `--generated-at` zaznamená aktuální čas UTC; pro bitově
shodný výstup použít čas z `metadata.generatedAt` v existujícím souboru.
Hranice je z ČÚZK RÚIAN, okres Most (kód 3508), pod licencí
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). Při distribuci
uvést ČHMÚ jako zdroj imisních dat a ČÚZK/RÚIAN jako zdroj hranice.
Aktualizace produktu vyžaduje nový kontrolovaný výřez a změnu období či
metadat; těžké shapefile se nikdy neparsují při načítání stránky.
Při nasazení nahrát i `data/air_quality/most-2020-2024.json`; vstupní archiv,
hranice a offline nástroje nejsou potřeba pro běžný provoz API.

### `api/comparison.php`

Stránka [Srovnání s Kopisty](public/comparison.php) porovnává teplotu (°C),
rychlost větru (km/h) a hodinové srážky (mm) vlastní stanice a ČHMÚ Kopisty
(`0-20000-0-11433`) za **24 uzavřených UTC hodin**. Veličina se přepíná nad grafem;
tabulka, jednotky i souhrn se přizpůsobí výběru.
Endpoint nemá volitelné období: další parametry vracejí 400. Surová vlastní
řada je omezena na 26 hodin a 3000 záznamů (včetně předchozího stavu srážkového čítače);
z ČHMÚ se načtou nejvýše dva
denní desetiminutové JSON soubory a aktuální metadata stanice, prvků a kvality.
Veřejný klient nikdy nepředává URL upstreamu.

Teplota a vítr se samostatně agregují prostým průměrem dostupných vzorků do hodin
`[začátek, konec)`. Nejde o oficiální hodinový produkt ČHMÚ ani důkaz úplnosti
hodiny. Tabulka uvádí počty přijatých vzorků i vyloučených hodnot Kopist.
Ověřená řada ČHMÚ přijímá jen kvalitu **0 (Good)** bez příznaku. Kvalita
**5 (Unknown)** se zobrazuje zvlášť přerušovanou čarou: při jejím výskytu
je celý hodinový agregát z Good + Unknown označen jako neověřený, stejně jako
jeho rozdíl. Odhady, podezřelé, chybějící a označené hodnoty se nepoužijí.
API vrací `metrics.temperature`, `metrics.wind` a `metrics.precipitation`
s vlastními `items`, `unit` a `summary`; původní kořenové `items` a `summary`
nadále obsahují teplotní řadu Good-only. Neověřené hodnoty jsou v
`kopistyUnverified` a `unverifiedDifference`, počty v `kopistyUnknownSamples`.
Vzorky stejného času s konfliktními hodnotami
se vyloučí. Mezery nejsou nuly a nedoplňuje se do nich poslední známá hodnota.

Rozdíl je `OuBrix − Kopisty` pouze pro hodiny, v nichž mají obě řady hodnotu.
Časy se zobrazují v Europe/Prague s UTC posunem, takže se rozliší obě
opakované hodiny při konci letního času. Kopisty jsou jiná lokalita asi
5,2 km od OuBrix; poloha a nadmořská výška se načítají z aktuálních metadat.
Jednotky prvků ČHMÚ `T`, `F`, `SRA10M` se ověřují v metadatech (°C, m/s, mm).
Vítr ČHMÚ se násobí 3,6; vlastní vítr je již v km/h.

Srážky ČHMÚ jsou součtem šesti desetiminutových úhrnů za intervaly končící
v `(začátek, konec]`; chybějící interval znamená neúplnou hodinu a hodnotu `null`.
Srážky OuBrix jsou přibližným součtem přírůstků denního čítače `precip_total`,
nikoli průměrem intenzity `precip_rate`. Přírůstek se přiřadí konci intervalu
měření; hranice se mohou od celé hodiny lišit nejvýše o 10 minut.
Mezera přes 10 minut, chybějící čítač, jeho pokles nebo přechod místní půlnoci
zneplatní hodinu. Neznámý úhrn při resetu se nenahrazuje nulou.

Výpadek ČHMÚ nezablokuje vlastní řadu a výpadek DB nezablokuje Kopisty;
pokud selžou oba zdroje, API vrátí 503. Zastaralá gateway cache je označená.
Metadata se obnovují po UTC datech (TTL 24 h), měření po 15 minutách.
Hodnoty neznámé kvality již nezpůsobí prázdný graf při dostupných měřeních,
ale nejsou vydávány za ověřené. Neúplné srážkové hodiny zůstávají mezerami.
Frontend lze ověřit příkazy `node tests\Frontend\ComparisonTest.mjs`
a `node tests\Frontend\TemperaturePrecisionTest.mjs`; backend příkazem `php tests\run.php`.

### `api/phenology.php`

Volitelné parametry: `station`, `species`, `phase`, `year` a `compare`.
Bez parametrů API vrátí dostupné filtry a výchozí Doksany; stanice se řadí
podle vzdálenosti od Mostu. `compare` je seznam 2–4 kódů stanic oddělených
čárkou a musí obsahovat základní stanici. Srovnávací mediány se počítají jen
ze společných roků s pozorovaným nástupem. Nepodporované kombinace nebo
parametry vrací 400; chybějící tabulky/databáze 503.

V odpovědi jsou oddělené `observed`, `not_occurred` (kód 444), `missing`
a `conflict` (rozporné zdrojové duplicity). Poslední dva stavy nemají datum
a nevstupují do mediánů. Přestupné roky se na meziroční ose normalizují na
365 dní.

## Lokální vývoj a testy

```powershell
# všechny testy (unit + integrační nad SQLite + HTTP test skutečných souborů public/)
php tests/run.php

# ověření desetinné teploty ve frontendových modulech (Node.js, bez balíčků)
node tests/Frontend/TemperaturePrecisionTest.mjs

# kalendářní ISO týdny pro výběr týdne v paprskovém grafu (Statistiky)
node tests/Frontend/IsoWeekTest.mjs
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
