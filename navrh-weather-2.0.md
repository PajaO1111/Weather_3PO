# OuBrix Weather 2.0 — analýza a návrh

> **Aktualizace po dodání databáze (22. 10. 2026):** Původní text níže vznikl
> před zpřístupněním SQL exportu. Ověřené schéma, profil všech 72 239 řádků
> a zpřesněná struktura projektu jsou nyní v
> [analýze implementace](docs/architecture/README.md). Ta má přednost zejména
> v otázkách indexů, duplicit, srážkového čítače, pokrytí a časového vážení.

## Shrnutí a rozsah jistoty

Tento návrh vychází z PHP souborů dostupných v pracovním prostoru, zejména z
`history5.php`, dále z `history.php`, `fetch_weather.php` a `fetch_weather2.php`.
Jejich společným zdrojem dat je tabulka `pws_observations` a zdrojem měření
Weather Underground PWS API. V prostoru není definice tabulky, databázová
migrace, `config.php` ani dump dat. Nelze proto potvrdit fyzické typy sloupců,
primární klíč, existující indexy, engine, počet řádků, duplicity, skutečné
intervaly měření ani obsah `qc_status`. Návrhy, které na těchto faktech závisejí,
jsou níže označeny jako předpoklady a před nasazením vyžadují kontrolu schématu.

**Doporučený směr:** zachovat `pws_observations` jako zdroj pravdy, současný
web provozovat beze změny během první fáze a postupně přidat API, agregace,
dashboard a analytické stránky. Nepřejmenovávat ani hromadně nepřepisovat
existující data, dokud neproběhne audit schématu a kontrolní backfill.

## 1. Analýza stávající implementace

### Vstupní stránka `history5.php`

1. Načítá `../config.php`, vytváří PDO připojení a provádí jeden parametrizovaný
   dotaz nad `pws_observations`.
2. Dotaz čte 12 polí: `obs_time_local`, `solar_radiation`, `uv`, `winddir`,
   `wind_speed`, `wind_gust`, `pressure`, `temp_c`, `dew_point`, `humidity`,
   `precip_rate`, `precip_total`. Řadí sestupně podle `obs_time_local`, vrátí
   limit 1–500 (výchozí 100) a výsledek v PHP obrátí do vzestupného pořadí.
3. Celý výsledek předává do HTML tabulky, JavaScriptu i CSV exportu. Počet
   datových bodů je tedy omezen limitem stránky, ne stránkováním/API.
4. Zobrazuje osm kartézských grafů (teplota, tlak, srážková intenzita,
   radiace, UV, rychlost a náraz větru, směr větru) a vlastní polární graf.
   Používá Bootstrap 5 a Chart.js z CDN.
5. V grafu srážek se používá `precip_rate`, ale popisek říká „Srážky (mm)“;
   jde o intenzitu (typicky mm/h), nikoli úhrn. `precip_total` je v dotazu také
   načteno, avšak do grafu se nepoužívá. Je nutné potvrdit jednotky i sémantiku
   hodnot přímo proti API a existujícím záznamům.
6. Polární graf kreslí jednu radiální značku pro každý záznam; jeho délka je
   normalizována rychlostí větru a směr určuje úhel. Nejde o meteorologickou
   větrnou růžici: nepočítá četnost směrů, rychlostní třídy ani podíl klidu.
7. CSS nastavuje šedé pozadí a globální bílou barvu textu Chart.js; chybí
   ucelený dark/light režim a není zaručena čitelnost všech popisků.

### Datové toky a sousední soubory

```text
Weather Underground current-observation API
      │
      ├── fetch_weather.php  ── INSERT ──┐
      └── fetch_weather2.php ── COUNT + INSERT (není atomické)
                                         ▼
                                pws_observations
                                  │         │
                                  ▼         ▼
                              history5.php history.php
```

- Oba ingest skripty vkládají do stejné tabulky téměř totožnou sadu 25 polí
  včetně UTC a lokálního času, identifikátoru stanice, souřadnic, metrik
  a `epoch`.
- `fetch_weather.php` před vložením vůbec nekontroluje duplicity.
  `fetch_weather2.php` dělá `SELECT COUNT(*)` podle `obs_time_utc`, následovaný
  samostatným `INSERT`. Při souběžném běhu oba procesy mohou projít kontrolou
  a vložit stejný záznam; atomickou ochranu zajistí až unikátní index a
  odpovídající `INSERT ... ON DUPLICATE KEY UPDATE` nebo bezpečné ignorování
  duplicit.
- Skripty stahují pouze aktuální pozorování; nejsou zde vidět archivní import,
  plánovač, validační vrstva ani mechanismus oprav agregací. Dashboard proto
  může zobrazit naměřenou historii, nikoli předpověď počasí bez dalšího zdroje.
- `history.php` vybírá širší sadu polí (včetně ID, QC, lokality a výšky),
  řadí podle `obs_time_utc` a připouští limit až 8500. Zároveň má HTML vstup
  nastavený natvrdo na `value=100`, text nadpisu je také natvrdo „100“ a graf
  srážek má pevné maximum 5.
- `index.php` pouze zahrnuje `loader/router.php`, který v seznamu souborů
  pracovního prostoru není. Stejně tak odkazované `site-shell.css/js` nejsou
  v dostupném seznamu. Jejich chování a strukturu navigace nelze ověřit.
- `test_rings.php` je izolovaný prototyp Chart.js s umělými daty; není produkční
  zdroj dat ani větrná růžice.

### Konkrétní problémy a příležitosti

| Oblast | Dnešní stav | Návrh |
|---|---|---|
| Časová osa | `history5.php` řadí podle místního času, `history.php` podle UTC. | Používat UTC `obs_time_utc` pro řazení, filtry a API; lokální čas odvozovat jednotně v `Europe/Prague`. |
| Indexy | Definice indexů nejsou k dispozici. | Ověřit `SHOW CREATE TABLE` a `SHOW INDEX`; podle plánu dotazů indexovat čas, případně `(station_id, obs_time_utc)`. |
| Výkon | Výběr je omezený limitem, ale bez stránkování a serverového filtrování. | API s intervalem, limitem, povolenými metrikami a agregovanou granularitou. |
| Srážky | Intenzita a úhrn nejsou v UI rozlišené. | Zobrazovat `precip_rate` jako mm/h a denní/měsíční součty počítat z rozdílů kumulativního čítače nebo integrace intenzity. |
| Export CSV | Ruční spojování neescapuje čárky, uvozovky, nové řádky ani vzorce v tabulkovém procesoru; při prázdných datech `data[0]` selže. | Serverový streamovaný CSV export se správným quotingem, limitem rozsahu a ochranou před CSV formula injection. |
| JSON do `<script>` | `json_encode` používá pouze `JSON_UNESCAPED_UNICODE`. | Použít `JSON_THROW_ON_ERROR` a `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`, nebo data načítat přes API. |
| Vítr | Polární graf kreslí jednotlivá pozorování jako stejné úhlové značky. | Počítat četnosti v sektorech a rychlostních třídách; samostatně zobrazit klid, nárazy a kruhový průměr směru. |
| Grafická škála | `Math.max(...precs)` může být zavádějící při `NaN`; měření směrů je spojeno čárou jako běžná číselná osa. | Normalizovat nečíselné/null hodnoty a směr zobrazovat jako kruhovou veličinu nebo body. |
| Příjem dat | Neověřuje se HTTP timeout, stav odpovědi, JSON struktura ani povinná pole; obě implementace se duplikují. | Jedna služba ingestu s timeoutem, validací, logováním, idempotencí a metrikami běhu. |
| Chyby | Ingest výjimku zaloguje, ale vypíše její text do výstupu. | Nevracet klientovi interní chyby/konfiguraci; logovat bezpečně a vracet stavový kód. |
| Přístupnost/responzivita | `history5.php` má velké grafy a tabulku; polární graf používá pevný poměr, prototyp testu má pevně 800×800 px. | Testovat 320/768/1024/1920/3440 px, přístupné popisky, ovládání klávesnicí a správnou výšku grafů. |

## 2. Datový model a migrační strategie

### Zachování zdroje pravdy

`pws_observations` ponechat jako surová pozorování. V navržených agregačních
tabulkách je `station_id` součástí klíče, aby agregace zůstaly správné i při
pozdějším přidání dalších stanic. `local_date` znamená kalendářní den stanice
v časové zóně `Europe/Prague`; surové časy a API filtry zůstávají v UTC.

Připravená aditivní DDL migrace je v
`database/migrations/001_weather_aggregates.sql`. Vytvoří tři nové tabulky,
ale **nečte ani nepřepisuje** neověřené schéma `pws_observations` a neprovádí
backfill. Spustit ji až po záloze a potvrzení MariaDB verze/kolace a provozního
postupu. `CREATE TABLE IF NOT EXISTS` není kontrola kompatibility již
existující tabulky se stejným jménem.

### Výpočet agregací

1. Nejdříve inventarizovat datové typy, duplicity, rozsah UTC časů, intervaly,
   QC stav a null hodnoty. Ověřit, zda `obs_time_utc` je `DATETIME` nebo
   řetězec a jaký formát má `obs_time_local`.
2. Převést každé pozorování k lokálnímu dni stanice deterministicky z UTC.
   Nespoléhat na implicitní časovou zónu PHP, databázového spojení ani serveru.
3. Pro teplotu, vlhkost, tlak, vítr, UV a radiaci počítat min/max/avg z
   platných vzorků a zachovat počet vzorků. Sledovat pokrytí dne, aby se
   průměr z krátkého výpadku nevydával za plnohodnotný den.
4. **Nesčítat `precip_total`**: pravděpodobně jde o kumulativní stav měřidla.
   Denní déšť počítat z validovaných rozdílů sousedních čítačů (včetně resetu
   a výpadku) nebo integrací `precip_rate` přes skutečné časové rozestupy.
   Před nasazením ověřit konkrétní chování senzoru/API. Rozdíl přes dlouhý
   výpadek nesmí být bez kontroly přiřazen jedinému dni.
5. Backfill spouštět po časových dávkách do dočasných/nových agregačních
   tabulek, porovnat vzorky proti ručnímu výpočtu a až pak zapnout inkrementální
   přepočet. Opakované spuštění musí být idempotentní.
6. Denní agregace jsou primární výpočet; měsíční a roční agregace odvozovat z
   validních denních agregací, nikoli z průměrů měsíčních průměrů. Průměry
   vážit počtem vzorků nebo odpovídající dobou pokrytí.

### Indexy surové tabulky

Po ověření typu, existujících indexů a `EXPLAIN` pro reálné dotazy:

```sql
-- Kandidát pro aktuální history dotazy řazené/omezené podle času:
CREATE INDEX ix_pws_observations_utc
    ON pws_observations (obs_time_utc);

-- Preferovaná varianta, pokud API vždy filtruje podle stanice:
CREATE INDEX ix_pws_observations_station_utc
    ON pws_observations (station_id, obs_time_utc);
```

Neinstalovat oba indexy bez měření: druhý může pokrýt dotazy, které filtrují
stanici a rozsah UTC, zatímco zápisová zátěž a velikost tabulky rostou s každým
indexem. Nepřidávat unikátní index, dokud se neověří význam identity záznamu,
případné duplicitní časy a chování více stanic. Pokud je identita jednoho
pozorování `(station_id, obs_time_utc)`, nejdříve deduplikovat zálohovaná data
a potom nastavit unikátní klíč; duplicity nikdy neodstraňovat automaticky bez
kontroly.

### Ukázkové SQL dotazy

Po ověření fyzického typu `obs_time_utc` jako časového sloupce:

```sql
-- Nedávná pozorování pro časovou řadu; časové hodnoty předává API jako bindy.
SELECT obs_time_utc, temp_c, dew_point, pressure, humidity,
       wind_speed, wind_gust, winddir, uv, solar_radiation,
       precip_rate, precip_total
FROM pws_observations
WHERE station_id = :station_id
  AND obs_time_utc >= :from_utc
  AND obs_time_utc < :to_utc
ORDER BY obs_time_utc ASC
LIMIT :row_limit;
```

Názvy sloupců jsou pevně dané; pokud klient vybírá metriky, sestavit projekci
jen z allowlistu, protože SQL placeholder nemůže zastoupit identifikátor.
Pro výpočet měsíční průměrné teploty/větru z denních tabulek nepoužívat
nevážený průměr denních průměrů:

```sql
-- Vstupem jsou validně spočtené denní agregace; stanice a období jsou bindy.
SELECT station_id,
       YEAR(local_date) AS year_num,
       MONTH(local_date) AS month_num,
       MIN(tmin) AS tmin,
       MAX(tmax) AS tmax,
       SUM(rain_sum) AS rain_sum,
       SUM(tavg * temp_sample_count)
           / NULLIF(SUM(temp_sample_count), 0) AS tavg,
       SUM(wind_avg * wind_sample_count)
           / NULLIF(SUM(wind_sample_count), 0) AS wind_avg,
       MAX(wind_max) AS wind_max,
       SUM(sample_count) AS sample_count,
       SUM(temp_sample_count) AS temp_sample_count,
       SUM(wind_sample_count) AS wind_sample_count
FROM weather_daily
WHERE station_id = :station_id
  AND local_date >= :from_date
  AND local_date < :to_date
GROUP BY station_id, YEAR(local_date), MONTH(local_date)
ORDER BY year_num, month_num;
```

Váhou pro teplotu a vítr jsou samostatné počty platných vzorků; celkový počet
vzorků zůstává ukazatelem objemu dat. Pokud se intervaly měření nepravidelně
mění, průměry je třeba vážit dobou pokrytí nebo validní délkou měření a
agregovat konzistentní metodikou. `rain_sum` zde předpokládá již správně
vypočtený denní úhrn, ne součet `precip_total`.

## 3. Cílová architektura

Postupně oddělit vykreslování, API, dotazy a výpočty. Zachovat současný hosting
PHP/MariaDB; Tailwind není nutnou podmínkou přechodu a zaváděl by druhý design
systém vedle již použitého Bootstrapu. Doporučená první verze: PHP 8.3,
MariaDB, Bootstrap 5, Apache ECharts a Leaflet. Redis přidat až při naměřené
potřebě; pro první nasazení postačí cache s explicitní TTL mimo veřejný webroot.

```text
prohlížeč (dashboard, historie, klima, vítr)
      │ JSON + CSV API
      ▼
PHP front controller / API controllers
      ├── validace parametrů + DTO
      ├── repositories (PDO, prepared statements)
      ├── services (agregace, rekordy, wind rose, kvalita)
      └── cache (nejprve souborová; později Redis)
             │
             ├── pws_observations (zdroj pravdy)
             └── weather_daily/monthly/yearly

cron/CLI ingest ──> validační služba ──> pws_observations
cron/CLI aggregate ────────────────────> agregační tabulky
```

Cache klíč musí zahrnovat stanici, rozsah, granularitu, metriky a verzi
agregačního výpočtu. Cache invalidovat po úspěšném ingestu/přepočtu příslušného
dne; historické agregace lze držet déle než aktuální podmínky. Při chybě cache
nevracet prázdný úspěšný výsledek — zalogovat chybu a načíst z databáze, nebo
vrátit explicitní API chybu.

### Navržená adresářová struktura

```text
app/
  Controllers/              DashboardController.php, ObservationController.php
  DTO/                      ObservationQuery.php
  Repositories/             ObservationRepository.php, WeatherAggregateRepository.php
  Services/                 AggregationService.php, WindRoseService.php
                            ExtremesService.php, DataQualityService.php
  Support/                  Database.php, JsonResponse.php, Validator.php
config/                     app.php (bez tajemství), database.example.php
database/
  migrations/               001_weather_aggregates.sql
  queries/                  backfill_daily.sql (až po auditu schématu)
public/
  index.php                 front controller
  api/v1/                   směrování API
  assets/css/               design tokens + Bootstrap overrides
  assets/js/                dashboard.js, history.js, climate.js
resources/views/
  layout.php
  dashboard/index.php
  history/index.php
  climate/index.php
  wind/index.php
  extremes/index.php
bin/                        ingest-weather.php, aggregate-weather.php
storage/                    cache/ a logs/ mimo veřejný webroot
tests/
  Unit/                     wind rose, rainfall, timezone, validation
  Integration/              repository a API kontrakty
```

Přesun `history5.php` do této struktury provést až po zpětně kompatibilním
nasazení. Stávající URL může dočasně zůstat jako tenký wrapper/přesměrování.

## 4. REST API

JSON odpovědi verzovat pod `/api/v1`; datum/čas vstupu API je ISO-8601 UTC,
výstup obsahuje UTC i lokální zobrazení. Validovat datum, pořadí intervalu,
maximální rozsah, stanici, počet bodů a seznam metrik. Názvy sloupců nejsou
parametrizovatelné hodnoty SQL: metriky se mapují přes pevný allowlist.

| Metoda a cesta | Účel | Odpověď / omezení |
|---|---|---|
| `GET /api/v1/current?station=...` | Poslední validní stav a čas posledního měření. | Hodnoty + `observedAtUtc`, `ageSeconds`, QC. |
| `GET /api/v1/observations?from=...&to=...&metrics=temp_c,pressure` | Časová řada s limitem/stránkováním. | `items[]`, UTC `time`, pouze povolená měření. |
| `GET /api/v1/series?from=...&to=...&interval=hour` | Agregovaná řada pro hlavní graf/meteogram. | Granularita `raw|hour|day`; server hlídá maximální body. |
| `GET /api/v1/summary/daily?date=YYYY-MM-DD` | KPI a denní min/max/avg/součty. | Data z `weather_daily` + úplnost měření. |
| `GET /api/v1/climate/monthly?fromYear=...&toYear=...` | Měsíční normály, srovnání a anomálie. | Agregované roky, datum začátku normálu uvedeno v metadatech. |
| `GET /api/v1/wind-rose?from=...&to=...&sectors=16` | Směr × rychlostní třída a klid. | Četnosti/percenta, definice tříd a QC pravidel. |
| `GET /api/v1/extremes?period=all` | Rekordy. | Hodnota, jednotka, čas UTC/lokální, zdroj a QC. |
| `GET /api/v1/quality?from=...&to=...` | Pokrytí a anomálie. | Upozornění, nikoli automatická oprava surových dat. |
| `GET /api/v1/export.csv?...` | CSV vybraného rozsahu. | Streamované UTF-8 CSV, maximální rozsah/řádkový limit. |
| `GET /api/v1/export.json?...` | JSON export. | Stejné filtry a limity jako série. |

XLSX/PDF export vyžaduje zvolenou a auditovanou knihovnu; nezavádět jej, dokud
není jasný požadovaný formát a limity. PNG/SVG export hlavního grafu řešit na
klientu přes ECharts. Každý export respektuje stejnou validaci a autorizaci
jako příslušný API endpoint.

## 5. Návrh UI a wireframe

```text
┌ OuBrix Weather   [Přehled] [Historie] [Klima] [Vítr] [Extrémy]   [☼/◐] ┐
│ STANICE · poslední měření 14:32 · data stará 1 min · kvalita ●         │
│                                                                        │
│  Teplota       Pocitová       Vlhkost       Tlak        Vítr / poryv   │
│  18,4 °C       18,0 °C        62 %          1014 hPa    3 / 7 km/h    │
│  sparkline     trend          sparkline     trend       směr SV        │
│  Rosný bod     UV             Radiace       Déšť dnes   Stav přenosu  │
│                                                                        │
│  Historie / meteogram         [6 h] [24 h] [7 d] [30 d] [Vlastní]      │
│  [teplota] [rosný bod] [tlak] [vlhkost] [vítr] [UV] [radiace] [déšť]  │
│  ┌──────────────── interaktivní ECharts graf ───────────────────────┐ │
│  │  zoom · pan · tooltip · legenda · export PNG/SVG                  │ │
│  └───────────────────────────────────────────────────────────────────┘ │
│  [Mapa stanice / aktuální podmínky]          [Dnešní souhrn]           │
└────────────────────────────────────────────────────────────────────────┘
```

- **Dashboard:** aktuální podmínky a stáří dat, KPI s jednotkami/QC, hlavní
  přepínatelný graf, denní souhrn a mapa stanice. V mobilu KPI do dvou sloupců,
  ovládání grafu do horizontálně posuvné lišty bez přetečení stránky.
- **Historie:** intervaly, vlastní rozsah, granularita, výběr metrik, tabulkový
  přístup k datům a serverový export; výchozí pohled nesmí načítat tisíce
  neagregovaných vzorků.
- **Meteogram:** jednotná osa času pro historická měření; srážky jako sloupce
  s jasným rozlišením úhrnu a intenzity, vítr jako rychlost/směr, ostatní
  veličiny samostatnými osami s popsanými jednotkami. Prognózu přidat až po
  napojení předpovědního zdroje.
- **Klima/analytika:** denní statistiky, měsíční/roční souhrny, anomálie vůči
  explicitně zvolenému referenčnímu období, kalendářní heatmapy a boxploty.
  Violin plot přidat pouze pokud je pro data a velikost vzorku smysluplný.
- **Vítr:** skutečná větrná růžice s volitelnými 8/16 sektory, rychlostními
  pásmy, procenty četnosti, podílem klidného větru, dominantním (největší
  četnost) a průměrným kruhovým směrem. Nárazy vykazovat samostatně.
- **Extrémy:** rekordy s časem, jednotkou, QC a zdrojem; porovnávat pouze
  validní data a u srážek rekord definovat jako denní úhrn.
- **Mapa:** Leaflet + OpenStreetMap, souřadnice z ověřeného nastavení stanice
  nebo pozorování. Nezobrazovat každou soukromou souřadnici z tabulky bez
  potvrzení; respektovat podmínky veřejné OSM dlaždicové služby.

Dark mode respektuje `prefers-color-scheme` při prvním načtení a přepínač
uživatele ukládá preferenci; uživatelská volba má přednost. Testovací šířky:
320, 768, 1024, 1920 a 3440 px. Na nejširších monitorech omezit maximální šířku
obsahu, na mobilu ověřit zejména grafy, navigaci, tabulky a exportní ovladače.

## 6. Ukázkové PHP: repository pro časovou řadu

Jde o návrhový vzor pro cílové `app/Repositories/ObservationRepository.php`;
sloupce a datové typy je před implementací nutné ověřit podle skutečného DDL.
Hodnoty časů se validují na hranici API, názvy metrik se mapují přes allowlist.

```php
<?php
declare(strict_types=1);

final class ObservationRepository
{
    private const METRICS = [
        'temp_c', 'dew_point', 'pressure', 'humidity',
        'wind_speed', 'wind_gust', 'winddir', 'uv',
        'solar_radiation', 'precip_rate', 'precip_total',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<string> $metrics
     *  @return list<array<string, mixed>>
     */
    public function between(
        string $stationId,
        DateTimeImmutable $fromUtc,
        DateTimeImmutable $toUtc,
        array $metrics,
        int $limit = 5000,
    ): array {
        if ($fromUtc >= $toUtc || $limit < 1 || $limit > 5000) {
            throw new InvalidArgumentException('Neplatný rozsah nebo limit.');
        }

        $selected = array_values(array_unique($metrics));
        if ($selected === [] || array_diff($selected, self::METRICS) !== []) {
            throw new InvalidArgumentException('Neplatný seznam metrik.');
        }

        $columns = implode(', ', array_map(
            static fn (string $metric): string => '`' . $metric . '`',
            $selected,
        ));
        $sql = "SELECT obs_time_utc, {$columns}
                FROM pws_observations
                WHERE station_id = :station
                  AND obs_time_utc >= :from_utc
                  AND obs_time_utc < :to_utc
                ORDER BY obs_time_utc ASC
                LIMIT :row_limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':station', $stationId, PDO::PARAM_STR);
        $stmt->bindValue(':from_utc', $fromUtc->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s'));
        $stmt->bindValue(':to_utc', $toUtc->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s'));
        $stmt->bindValue(':row_limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
```

V produkčním API mají být `stationId`, časové limity a přístup k exportu
ověřeny společnou validační vrstvou; `PDO::ATTR_EMULATE_PREPARES` vypnout,
pokud to ověřená verze ovladače podporuje. Připojení nastavit s
`PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` a explicitním charsetem.

## 7. Ukázkový Apache ECharts

Ukázka demonstruje časovou osu, zoom, legendu a dvě osy. `series` sestavit
z validované odpovědi API; data musí být seřazena podle UTC a chybějící hodnoty
posílat jako `null`, nikoli jako řetězec `"null"`.

```js
const chart = echarts.init(document.getElementById('weather-chart'));
chart.setOption({
  tooltip: { trigger: 'axis', axisPointer: { type: 'cross' } },
  legend: { type: 'scroll' },
  toolbox: {
    feature: {
      dataZoom: { yAxisIndex: 'none' },
      restore: {},
      saveAsImage: { type: 'png', pixelRatio: 2 }
    }
  },
  dataZoom: [{ type: 'inside', xAxisIndex: 0 }, { type: 'slider', xAxisIndex: 0 }],
  xAxis: { type: 'time' },
  yAxis: [
    { type: 'value', name: 'Teplota (°C)' },
    { type: 'value', name: 'Tlak (hPa)', position: 'right' }
  ],
  series: [
    {
      name: 'Teplota (°C)',
      type: 'line',
      yAxisIndex: 0,
      showSymbol: false,
      connectNulls: false,
      data: temperaturePoints
    },
    {
      name: 'Tlak (hPa)',
      type: 'line',
      yAxisIndex: 1,
      showSymbol: false,
      connectNulls: false,
      data: pressurePoints
    }
  ]
});
window.addEventListener('resize', () => chart.resize());
```

Export SVG ověřit s konkrétní verzí a rendererem ECharts; PNG z canvas rendereru
je výchozí cesta. Pro CSV/JSON velkých rozsahů nepoužívat klientský export
omezeného počtu bodů, ale serverový endpoint.

## 8. Výkon, cache a kvalita dat

### Výkonové pořadí

1. Změřit současné `EXPLAIN`, velikost tabulky a indexy. Pro aktuální
   `history5.php` je nejdůležitější `ORDER BY obs_time_local DESC LIMIT n`;
   po sjednocení na UTC odpovídá index časové ose. Neprovádět `EXPLAIN ANALYZE`
   na produkci bez posouzení dopadu podle verze MariaDB.
2. Přenést řazení na UTC a stránkování/rozsahy do API; nepředávat celé tabulky
   ani celé historické rozsahy do PHP/JavaScriptu.
3. Pro dlouhé rozsahy použít hodinové/denní agregace, pro krátké rozsahy
   surová data; u extrémně dlouhých řad případně server-side downsampling.
4. Agregace přepočítávat inkrementálně po dávkách a znovu zpracovat pouze
   dotčené dny; měsíční/roční statistiky odvozovat z denních dat se správným
   vážením.
5. Cache nejprve souborová mimo webroot: aktuální stav TTL 30–60 s; denní
   souhrny krátké TTL a invalidace při ingestu; historické agregace delší TTL.
   Cache není náhradou za korektní SQL/index.
6. Redis nasadit, až se prokáže potřeba sdílené cache, více PHP workerů nebo
   vyšší souběh; řešit výpadek Redis explicitním fallbackem a metrikou.
7. Načítat knihovny lokálně/verzovaně nebo s integritou CDN, minimalizovat
   počet externích assetů a neposílat nepotřebná metadata do každého grafu.

### Data-quality pravidla

- **Výpadek přenosu:** odhad očekávaného intervalu z reálné frekvence stanice;
  označit dobu bez měření a aktuální stav jako stale, ne jako aktuální.
- **QC:** uchovat `qc_status`; pravidla pro zahrnutí do statistik nastavit po
  zjištění jeho skutečných hodnot.
- **Rozsahy a skoky:** konfigurovatelné limity teploty, tlaku a změny mezi
  vzorky odvodit z možností senzoru a lokálního klimatu; podezřelé vzorky
  označit, nemazat.
- **Senzorová porucha:** samostatně detekovat opakovanou konstantní hodnotu,
  chybějící metriku, chybný čas, neplatný směr a nerealistickou rychlost změny.
- **Upozornění:** ukládat typ, začátek/konec, závažnost a potvrzení; zamezit
  opakovaným notifikacím jedné události.
- **Rekordy:** počítat z validních pozorování s uvedením pokrytí dat a
  jednoznačným pravidlem pro denní srážkový úhrn.

AI analytiku ponechat jako pozdější služební vrstvu: exportovat validované
agregace a vysvětlitelné příznaky, ne posílat tajemství ani nekontrolovaná
surová data externím službám. První „textový report“ lze generovat
deterministicky ze statistik; predikci netvrdit bez trénovacích dat a validace.

## 9. Implementační plán a akceptační kritéria

1. **Audit a bezpečná záloha:** získat DDL, `SHOW INDEX`, DB verzi, počty/
   duplicity, časové pokrytí, jednotky, QC a intervaly; ověřit zálohu/obnovu.
   Výstupem je potvrzené schéma a rozhodnutí o časovém klíči a srážkách.
2. **Opravit zdroj příjmu:** sjednotit oba ingest skripty do jedné služby,
   přidat timeout, validaci odpovědi a atomickou idempotenci až po ověření
   unikátní identity; zachovat současné datové pole.
3. **Migrace agregací:** nasadit aditivní DDL, testovat na kopii, backfill
   v dávkách, porovnat náhodně vybrané dny a ověřit opakovatelnost.
4. **API a indexy:** přidat read-only endpointy s parametry, limity,
   stránkováním a testy; indexy vybrat z `EXPLAIN` a ověřit bez regressí ingestu.
5. **Nový dashboard:** ECharts, KPI, dark/light, prázdný/loading/error/stale
   stav; starou historii ponechat jako fallback při rollout.
6. **Analytika:** vítr, klima, extrémy, heatmapy, denní souhrny a quality
   dashboard po ověření výpočtů a pokrytí.
7. **Exporty, mapa a cache:** přidat CSV/JSON, PNG/SVG, Leaflet a cache po
   ověření UX, licencí/limitů dlaždic a bezpečnostních limitů.
8. **Nasazení a přechod:** porovnat starou a novou stránku, sledovat DB
   latenci, chybovost, čerstvost měření a přesnost agregací; až potom vypnout
   starou implementaci.

Minimální testy před přepnutím:

- bez horizontálního scrollování na šířkách 320/768/1024/1920/3440 px;
- API odmítá obrácený/nesprávný interval, nedovolené metriky a nadlimitní
  rozsah; SQL názvy metrik nelze injektovat;
- časové řady jsou striktně seřazeny podle UTC a správně zobrazí přechod
  letního/zimního času v `Europe/Prague`;
- prázdný výsledek, `null`, chyba DB, stale data i chyba exportu mají
  explicitní stav;
- denní déšť zvládá nulový déšť, reset čítače, chybějící vzorky a půlnoc;
- opakovaný ingest nevytváří duplicity a opakovaný backfill nemění správný
  výsledek;
- export zachová čárky/uvozovky/diakritiku a neaktivuje tabulkový vzorec;
- větrná růžice testuje severní sektor přes 0°/360°, klid a nulový počet dat.

## 10. Rizika migrace a rozhodnutí před implementací

| Riziko | Dopad | Mitigace |
|---|---|---|
| Neznámý DDL a indexy | DDL/index může selhat nebo zhoršit zápis. | Před migrací získat `SHOW CREATE TABLE`, `SHOW INDEX`, verzi MariaDB a plán dotazů. |
| Časová zóna / DST | Chybné denní hranice a statistiky v přechodových dnech. | UTC jako klíč, stanici explicitně `Europe/Prague`, testovat oba DST přechody. |
| Kumulativní `precip_total` | Nadhodnocené/nesprávné součty srážek. | Ověřit reset a sampling; počítat rozdíly/integrál, zachovat auditní testy. |
| Duplicitní data | Nesprávné statistiky a rekordy. | Inventarizovat, definovat identitu, deduplikovat pouze po záloze a schválení. |
| Jednotky větru | Násobná chyba při zobrazení nebo agregaci. | Potvrdit sémantiku API; všude ukládat a zobrazovat explicitní jednotky. |
| Příliš rychlý kompletní rewrite | Výpadek webu nebo ztráta kompatibility. | Strangler přístup: API/agregace nové, stávající URL a tabulka zůstávají. |
| Velké backfilly | Zámky, vysoké IO a pomalý ingest. | Časové dávky, staging, měření, možnost pozastavit a obnovit. |
| CDN/externí mapy | Výpadky, soukromí, limity použití. | Verzování assetů, lokální fallback, dodržet pravidla OSM; neposílat tajemství. |
| Cache zastará | Dashboard ukazuje neaktuální „live“ stav. | Zobrazit stáří, TTL, invalidace a monitorovat cache hit/miss/stáří. |
| Přehnané závěry z řídkých dat | Klimatické normály/rekordy zavádějí. | Uvádět dobu pokrytí, úplnost a metodiku; nepředstírat klimatologickou normálu bez rozsahu. |

### Konkrétní soubory další implementace

- `database/migrations/001_weather_aggregates.sql` — aditivní tabulky agregací
  (přiložená migrace).
- `app/Repositories/ObservationRepository.php` — výběr surových dat dle
  stanice a UTC intervalu (ukázka výše).
- `app/Repositories/WeatherAggregateRepository.php` — denní/měsíční/roční
  dotazy.
- `app/Services/AggregationService.php` — validace a idempotentní přepočet.
- `app/Services/WindRoseService.php` — sektory, rychlostní pásma a klid.
- `app/Controllers/ObservationController.php` — `/api/v1/observations` a
  `/api/v1/series`.
- `app/Controllers/DashboardController.php` + `resources/views/dashboard/`
  — nové KPI a přehled.
- `public/assets/js/dashboard.js` — ECharts; `public/assets/css/` — motivy.
- `bin/ingest-weather.php` a `bin/aggregate-weather.php` — CLI/cron, nikoli
  veřejné webové endpointy.
- `tests/Unit/` a `tests/Integration/` — srážky, časová zóna, wind rose,
  API a porovnání backfillu.

## Závěr

Současný kód je jednoduchá server-rendered historie nad tabulkou pozorování,
nikoli zatím analytický systém. Největší přínos a současně nejnižší migrační
riziko přinese sjednocení UTC, robustní ingest, korektní výpočet srážek,
agregační tabulky a read-only API. Teprve na této ověřené vrstvě stavět
dashboard, klimatologii, rekordy, větrnou růžici a AI rozšíření.
