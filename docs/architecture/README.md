# OuBrix Weather — analýza implementace a návrh projektu

Stav analýzy: **22. 10. 2026**. Podkladem je celá dodaná kopie databáze a
stávající PHP soubory. Tento dokument navrhuje implementaci; nová aplikace
ani její API zatím nejsou vytvořené. Žádná operace nebyla provedena na serveru.

> **Aktualizace po doplnění konfigurace:** Vznikl oddělený read-only prototyp
> [dashboardu](../../public/index.php) a [první runtime test](runtime-validation-report.md).
> Zbytek tohoto dokumentu je nadále cílový návrh, nikoli seznam hotových modulů.
> Nová závazná hranice: původní CRON, oba původní ingest soubory i tabulka
> zůstávají beze změn. Unikátní klíč, deduplikace a přepis ingestu níže jsou
> pouze volitelné budoucí kroky — nejsou podmínkou čtení existující DB.
> Soubor `.env` nebyl automaticky zapojen; existující `config.php` používá
> konstanty. Obě konfigurace neslučovat bez výslovné volby zdroje.

## 1. Závěr

Doporučuji **modulární PHP monolit**: jeden projekt, jedna MariaDB, společná
aplikační vrstva pro HTML, JSON API a CLI úlohy. Není důvod zavádět
mikroslužby, samostatnou SPA, frontu zpráv nebo povinný Redis pro současný
objem 72 tisíc řádků.

Nejdříve opravit identitu pozorování a způsob jeho zpracování. Nové grafy mají
stát nad jednoznačnými časy, jednotkami, chybějícími hodnotami a známou
úplností měření — nikoli jen nad novou grafickou knihovnou.

Podrobné podklady:

- [Existující projekt a vstupní body](project-structure.md).
- [Existující technologie a dostupné runtime](tech-stack.md).
- [Ověřené schéma a profil dat](data-model.md).
- [Strojově vypočtený profil celého exportu](dump-profile.json).
- [Původní širší návrh portálu](../../navrh-weather-2.0.md); tento nový dokument
  má přednost tam, kde původní návrh pracoval s neověřenými předpoklady.

## 2. Co nově potvrzuje export

Zdroj: [pws_observations.sql](../../pws_observations.sql). Profil byl vypočten
lokálně parsováním všech INSERT záznamů, bez spuštění SQL. Nezávislé spočítání
řádků dat v PowerShellu potvrdilo stejný počet. Hash exportu:

```text
SHA-256 6cb26216fd1b8ece30fa78aaf4a1adbbdd31281c660897f42c4561835b89ca3e
```

| Zjištění | Skutečnost v exportu | Důsledek |
|---|---|---|
| Databáze | MariaDB 10.11.18, InnoDB, utf8mb4_general_ci | Není nutná změna databázové technologie. |
| Indexy | Pouze `PRIMARY KEY (id)` | Časové filtry, řazení a kontrola duplicity nemají odpovídající index. |
| Pozorování | 72 239 řádků, 72 095 různých dvojic stanice + UTC | Existuje 144 nadbytečných řádků. |
| Duplicity | 144 dvojic, žádný rozdíl v payloadu kromě `id`/`inserted_at` | Lze navrhnout deterministické sloučení, ne však bez zálohy a zastavení souběžných zápisů. |
| Stanice | Jedna, `IMOST71` | Multi-station infrastruktura není nutná, identifikátor však patří do klíčů. |
| Historie | 18. 9. 2025 15:09:52 až 22. 10. 2026 19:35:04 UTC | Nelze prezentovat skutečné dlouhodobé klimatické normály. |
| Pokrytí | Data v 334 ze 400 zahrnutých lokálních kalendářních dní | 66 dní nemá žádné pozorování; další dny mohou být neúplné. |
| Nejdelší mezera | 30. 10. až 17. 11. 2025, 1 532 904 s | Mezery nepřekreslovat spojenou čárou a neinterpolovat jako měření. |
| Interval | Medián 300 s; za září/říjen 2026 medián 2 089 s | Úplnost ani průměry nelze založit na jednom globálním počtu vzorků za den. |
| Chybějící senzory | 192 NULL v teplotě i řadě dalších metrik; všechny tyto řádky mají QC=1 | QC příznak sám nestačí k rozhodnutí o použitelnosti dat. |
| Časy | Všechny UTC časy odpovídají `epoch`; lokální časy odpovídají Europe/Prague | Neprovádět plošnou „opravu“ časů; sjednotit jejich používání. |
| Přesnost | Všech 72 047 nenulových teplot je celočíselných | Databázové `DECIMAL(...,2)` neznamená změřenou přesnost 0,01 °C. |
| Výška | Změna 77 → 331 m dne 20. 10. 2025 | Prověřit metadata/konfiguraci senzoru před interpretací tlakových trendů. |

Nejde o měření latence SQL ani o kontrolu živého serveru. Z exportu nelze
zjistit skutečný cron, nastavení DB uživatele, HTTP server, aktuální PHP na
webu nebo další tabulky, které nebyly exportovány.

## 3. Analýza implementace: konkrétní problémy

### 3.1 Ukládání a identita pozorování

[fetch_weather.php](../../fetch_weather.php) a
[fetch_weather2.php](../../fetch_weather2.php) mapují 25 polí WU API přímo do
stejné tabulky. Chybí společný bootstrap, klient API, mapování DTO i validační
služba. Druhý skript přidává `COUNT(*) WHERE obs_time_utc = ?`, ale následné
vložení není atomické. První skript nekontroluje duplicity vůbec.

Export potvrzuje reálné duplicity, nikoli pouze hypotetickou race condition.
Neprokazuje však, který skript je vytvořil ani zda oba skutečně běží v cronu.

**Návrh:** jeden CLI import, jedna identita `(station_id, obs_time_utc)`,
atomické rozhodnutí v DB a explicitní rozlišení výsledků `inserted`,
`duplicate`, `conflict`, `invalid`, `failed`. Odlišný payload stejného času
nepřepisovat tiše. Porovnat měřené a zdrojové hodnoty a konflikt zalogovat.
Nezahrnovat do porovnání DB metadata jako `inserted_at`.

Nepoužívat všeobecné `INSERT IGNORE`, protože může kromě duplicity potlačit
i jiné chyby. Pokud implementace zachytává duplicate-key výjimku, musí
ověřit konkrétní MySQL/MariaDB kód a dotčenou identitu; ostatní chyby propagovat.

### 3.2 Dotazy a výkon

[history5.php](../../history5.php) řadí podle `obs_time_local`; starší
[history.php](../../history.php) podle `obs_time_utc`. Oba dotazy jsou bez
filtru stanice a odpovídajících časových indexů. Připravené statementy i
integer bind limitu jsou vhodné a mají se zachovat.

Z DDL je zřejmé, že primární klíč `id` nekryje řazení podle času. Skutečný
execution plan a náklady však musí potvrdit `EXPLAIN` na odpovídající kopii
DB; v této analýze nebyly SQL dotazy benchmarkovány.

**Návrh:** dotazy vždy filtrovat stanicí a UTC rozsahem, řadit stabilně
`obs_time_utc, id`, použít složený index a pro dlouhé rozsahy agregované řady.
Samostatný index lokálního času nezavádět, pokud budou nové i kompatibilní
dotazy převedeny na UTC.

### 3.3 Null hodnoty jsou skutečná provozní situace

V PHP mapování větru se `NULL` přetypuje na `0.0`. V JavaScriptu se při
`parseFloat(null)` získá `NaN`. Export obsahuje 192 takových senzorových
záznamů, ačkoli mají QC=1.

**Návrh:** DTO uchovává `?float`/`?int`; JSON předává skutečný `null`.
Chybějící vítr není bezvětří a chybějící srážka není nula. Grafy mají mezery,
KPI zobrazí nedostupnou metriku a agregace započítají vlastní počet platných
vzorků pro každou veličinu.

### 3.4 Jednotky a srážky

Zdroj volá WU s `units=m`; [history.php](../../history.php) popisuje vítr v km/h,
zatímco [history5.php](../../history5.php) v m/s, bez převodu. Projekt musí mít
jednu mapu jednotek a převod provádět výslovně. Potvrzení poskytovatelského
kontraktu zůstává podmínkou nasazení; samotná číselná hodnota v SQL jednotku
nedokazuje. Doporučené UI může zobrazovat m/s, ale až po převodu z km/h.

Data silně podporují interpretaci `precip_total` jako denního čítače:
99 poklesů je mezi různými lokálními dny, žádný uvnitř jednoho dne.
Reset/nové měření nemusí být přesně o půlnoci.

**Návrh oproti původnímu dokumentu:** pro první verzi uchovat
`rain_observed_mm = MAX(precip_total)` za lokální den jako **pozorovaný stav
denního čítače**, nikoli automaticky úplný denní úhrn. Doplnit čas posledního
platného čítače, pokrytí a příznak úplnosti. V exportu není samostatná
garantovaná uzávěrka každého dne; chybějící konec dne může znamenat chybějící
srážku. Při součtu za měsíc označit neúplné dny a neprezentovat součet jako
bezchybný klimatologický úhrn.

`precip_rate` zobrazovat odděleně jako intenzitu. Nepoužít součet čítačů;
integrace intenzity přes několikahodinovou nebo vícedenní mezeru by rovněž
vytvářela nepozorované údaje.

### 3.5 Průměry, vítr a klimatologie

Globální vzorkový průměr bude při proměnlivé frekvenci sběru zvýhodňovat
hustěji měřené části historie. Pro dlouhodobé srovnání navrhuji časově
vážený průměr s definovaným maximálním překlenovaným intervalem, počítaný
z platných vzorků a doplněný pokrytou dobou. Přes velké mezery se váha
nepřenáší. Hodnotu maximálního intervalu nastavit podle epoch/fází sběru,
nikoli dodatečně zaměnit měření za trvalou konstantní hodnotu.

Větrná růžice musí mít výslovně definováno, zda uvádí četnost vzorků nebo
časovou četnost. Výchozí pro porovnání období má být časová s obdobným
omezením vah. Směr je odkud vítr fouká: 0° sever a úhly po směru hodinových
ručiček. Klid vyjmout ze směrových sektorů a jeho podíl uvést zvlášť.
Směry neprůměrovat aritmeticky; použít kruhovou statistiku.

Ze 72 095 unikátních pozorování má 43 690 rychlost větru 0. Nuly nelze bez
dalších důkazů označit za poruchu, ale jejich podíl má být v UI vidět.
Přibližně třináct měsíců neúplné historie nestačí pro klimatické normály;
zatím používat „srovnání dostupných období“ s uvedením délky a pokrytí.

### 3.6 Provozní soudržnost

- [index.php](../../index.php) odkazuje na chybějící
  `loader/router.php`; lokální kopie tedy není kompletní nasazení.
- DB/WU konfigurace mimo workspace není dostupná. Language server hlásí
  nedefinované konstanty, což zde prokazuje nedostupný kontext, ne automaticky
  chybu živého serveru.
- Verze PHP z hlavičky dumpu, lokálního CLI a language serveru se liší;
  viz [technologický inventář](tech-stack.md).
- Adaptér Chart.js je vložen před samotným Chart.js a jeho verze není
  svázána s hlavní knihovnou; v přechodné verzi ověřit závislosti a pořadí.
  Cílově zvolit jednu verzovanou instalaci ECharts, ne souběžné grafické stacky.
- CSV export předpokládá existenci `data[0]` a neřeší korektní quoting.
  Exportovat serverově s limitem a explicitním zpracováním prázdného výsledku.

## 4. Navržená struktura projektu

Následující strom je **cílový návrh**, nikoli seznam již vytvořených souborů.
Namespace např. `OuBrix\Weather`, PSR-4 autoload přes Composer. Framework není
pro tento rozsah podmínkou; jednoduché routování a explicitní sestavení
závislostí postačuje.

```text
weather/
├── composer.json
├── composer.lock
├── bootstrap.php
├── config/
│   ├── app.php                 # timezone, stanice, limity, epochy sběru
│   ├── database.php            # načítání přístupových údajů, ne jejich publikace
│   ├── services.php            # WU timeout a reference na tajný klíč
│   └── local.example.php       # pouze nefunkční příklad konfigurace
├── routes/
│   ├── web.php                 # HTML stránky
│   └── api.php                 # verzované JSON/CSV endpointy
├── app/
│   ├── Http/
│   │   ├── Router.php
│   │   ├── JsonResponse.php
│   │   ├── Controllers/
│   │   │   ├── DashboardController.php
│   │   │   ├── HistoryController.php
│   │   │   ├── AnalyticsController.php
│   │   │   ├── WindController.php
│   │   │   └── Api/
│   │   │       ├── ObservationController.php
│   │   │       ├── SummaryController.php
│   │   │       └── ExportController.php
│   │   └── Validation/
│   │       └── ObservationQuery.php
│   ├── Weather/
│   │   ├── Observation.php
│   │   ├── ObservationMapper.php
│   │   ├── MetricCatalog.php
│   │   ├── Services/
│   │   │   ├── IngestService.php
│   │   │   ├── AggregationService.php
│   │   │   ├── RainfallService.php
│   │   │   ├── WindRoseService.php
│   │   │   ├── StatisticsService.php
│   │   │   └── DataQualityService.php
│   │   └── Repositories/
│   │       ├── ObservationRepository.php
│   │       └── AggregateRepository.php
│   └── Infrastructure/
│       ├── Database.php
│       ├── WeatherUndergroundClient.php
│       └── FileCache.php        # až po stabilizaci dotazů a výpočtů
├── resources/
│   ├── views/
│   │   ├── layouts/main.php
│   │   ├── components/         # KPI, filtry, upozornění
│   │   ├── dashboard.php
│   │   ├── history.php
│   │   ├── analytics.php       # klima/heatmapy/extrémy jako oddělené sekce
│   │   ├── wind.php
│   │   └── errors/
│   └── assets/
│       ├── css/app.css
│       └── js/
│           ├── api.js
│           ├── theme.js
│           ├── charts.js
│           ├── dashboard.js
│           ├── history.js
│           ├── analytics.js
│           ├── wind.js
│           └── station-map.js
├── public/                     # jediný HTTP document root
│   ├── index.php
│   ├── history.php             # dočasný kompatibilní wrapper
│   ├── history5.php            # dočasný kompatibilní wrapper
│   └── assets/                 # nasazené vlastní a verzované knihovní assety
├── bin/
│   ├── fetch-weather.php       # cron/CLI, nikoli veřejný endpoint
│   └── aggregate-weather.php   # backfill i přepočet dotčených období
├── database/
│   ├── migrations/
│   └── queries/
│       ├── audit.sql
│       └── explain.sql
├── storage/
│   ├── cache/
│   ├── logs/
│   └── locks/
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── Fixtures/               # malé anonymizované/povolené testovací vzorky
└── docs/
    └── architecture/
```

Samostatné adresáře `api/`, `Models/` a druhá vrstva `DTO/` vedle
`Weather/Observation.php` nejsou nutné. API je způsob vstupu do stejných služeb,
ne samostatná aplikace. Nepřidávat generický `BaseRepository`, DI framework,
CQRS nebo event bus bez konkrétní potřeby.

### Odpovědnosti klíčových souborů

| Soubor/třída v návrhu | Odpovědnost | Co do něj nepatří |
|---|---|---|
| `bootstrap.php` | Autoload, konfigurace, vytvoření PDO, klienta, služeb a controllerů. | SQL dotazy ani HTML. |
| `Observation.php` | Typované hodnoty, UTC čas, skutečné null, původní QC. | PDO ani HTTP stahování. |
| `ObservationMapper.php` | Ověřené mapování WU/DB na DTO; číselné řetězce z PDO na typy. | UI formátování nebo tiché dosazování nul. |
| `MetricCatalog.php` | Allowlist metrik, DB pole, zdrojové/zobrazovací jednotky, limity. | Tajné klíče a SQL od klienta. |
| `WeatherUndergroundClient.php` | HTTP stav/timeout, JSON validace, bezpečné chybové hlášení. | INSERT do DB. |
| `IngestService.php` | Sjednocený ingest, validace, idempotence a konflikty. | Formátování grafů. |
| `ObservationRepository.php` | Prepared SQL, stabilní řazení, rozsahy, vložení. | Generování HTML a meteorologické výpočty. |
| `AggregationService.php` | Reprodukovatelný výpočet po stanicích a lokálních dnech. | Spouštění z HTTP bez limitu. |
| `RainfallService.php` | Interpretace čítače, pozorovaný úhrn a jeho úplnost. | Domýšlení deště během výpadku. |
| `DataQualityService.php` | Závažnost, mezery, null, fyzikální rozsahy a metadata. | Mazání či přepis surových měření. |
| `ObservationQuery.php` | Validace stanice, ISO-8601 času, metrik, limitu, intervalu. | Spoléhání na filtraci pouze v JavaScriptu. |
| Controllery | Vstup → validace → služba → HTTP odpověď. | Kopírování SQL mezi stránkami. |
| `charts.js` | Společná konfigurace ECharts, null/gap, jednotky, resize. | Výpočty „pravých“ agregací z oříznuté klientské řady. |

## 5. Databázové změny v pořadí závislostí

### A. Nejprve bez změny surového schématu

Ponechat všechny nynější sloupce a `id`. Nová read-only aplikace může být
nasazena vedle staré; dočasně číst kanonické pozorování podle nejnižšího `id`
pro `(station_id, obs_time_utc)` nebo počkat na schválenou deduplikaci.
Kanonizace pro čtení nesmí znamenat tichý výběr mezi konfliktními payloady.

### B. Identita a indexy

Po záloze, kontrole referencí na ID a zastavení obou potenciálních writerů:
ověřit aktuální duplicity, archivovat odstraněné kopie a teprve pak zavést:

```sql
ALTER TABLE pws_observations
    ADD UNIQUE KEY uq_pws_station_utc (station_id, obs_time_utc);
```

To je **návrh příkazu, ne zde provedená migrace**. Unikátní index už pokrývá
dotazy filtrované stanicí a UTC časem; nepřidávat k němu identický neunikátní
index. Staré dotazy bez `station_id` se musí upravit nebo pro ně samostatně
ověřit potřebu časového indexu.

Změnu `station_id` na jiný charset/kolaci neprovádět jen kvůli redesignu.
Nové agregační tabulky používat konzistentně s původním `varchar(16)` a
`utf8mb4_general_ci`, pokud není zdokumentován důvod pro odlišnost.

### C. Agregace a metadata výpočtu

Dosavadní [001_weather_aggregates.sql](../../database/migrations/001_weather_aggregates.sql)
je zatím **návrhový základ**, nikoli hotový ověřený backfill. Sama
nevytváří data ani neřeší všechny nově potvrzené situace.

Pro další verzi DDL navrhuji:

- `weather_daily`: klíč `(station_id, local_date)`, min/max a průměry;
  `tmedian`, pokud se má denní medián materializovat; samostatné validní
  počty/váhy metrik; `coverage_seconds`, časy prvního/posledního pozorování;
  srážkový pozorovaný stav + úplnost; `algorithm_version`, `calculated_at`.
- `weather_monthly`, `weather_yearly`: hlavně souhrny pro UI, vždy s pokrytím
  a verzí výpočtu; průměr vypočítat ze součtu vážených hodnot a jejich vah,
  nikoli průměrováním denních či měsíčních průměrů.
- `weather_quality_events`: volitelně až pro trvalá upozornění — stanice,
  časový rozsah, metrika, pravidlo, závažnost, stav potvrzení.

V první iteraci postačuje denní agregace; měsíční/roční dotazy přes přibližně
400 denních řádků budou malé. Měsíční a roční tabulky materializovat, až jejich
potřebu prokáže měření nebo složitost výpočtů. Nevytvářet šest vrstev cache
a agregací pro stejnou statistiku.

Přepočet dne běží pod zámkem a uloží všechny jeho hodnoty v jedné transakci.
Po opravě historického vzorku se znovu označí dotčené období; watermark podle
nejvyššího ID sám o sobě neodhalí aktualizaci starého řádku. Report má uvádět
verzi algoritmu, aby se nemíchaly různé metodiky.

Medián měsíce není medián denních mediánů a větrná růžice se nedá zpětně
odvodit z denního maxima/průměru větru. Tyto výpočty potřebují surové hodnoty
nebo účelové histogramy, ne jen základní denní tabulku.

## 6. Trasy a propojení UI s backendem

| Vstup | Controller/služba | Data |
|---|---|---|
| `/` | `DashboardController` | Poslední měření, stáří, dnešní souhrn, KPI. |
| `/historie` | `HistoryController` | Rozsah, metriky, časové grafy a tabulka. |
| `/analyzy` | `AnalyticsController` | Denní statistiky, klima, heatmapy a rekordy. |
| `/vitr` | `WindController` | Růžice, rychlostní třídy, nárazy a klid. |
| `GET /api/v1/current` | `ObservationController` | Skutečný poslední čas a validita každé metriky. |
| `GET /api/v1/series` | `ObservationController` | UTC interval `[from,to)`, stanice, metriky, granularita. |
| `GET /api/v1/summary` | `SummaryController` | Denní/měsíční/roční agregace s pokrytím. |
| `GET /api/v1/wind-rose` | `WindController` | Frekvence, definice tříd, platné váhy a klid. |
| `GET /api/v1/export` | `ExportController` | CSV/JSON, jednotné filtry a limity. |
| `php bin/fetch-weather.php` | `IngestService` | Stáhne a atomicky vloží jedno pozorování. |
| `php bin/aggregate-weather.php` | `AggregationService` | Backfill nebo přepočet lokálních dní. |

Odpověď řady má mít jasný kontrakt, například:

```json
{
  "station": "IMOST71",
  "timezone": "Europe/Prague",
  "units": {"temp_c": "°C", "wind_speed": "m/s"},
  "aggregation": "raw",
  "items": [
    {"time": "2026-10-22T19:35:04Z", "temp_c": null, "wind_speed": null}
  ],
  "truncated": false,
  "nextCursor": null
}
```

Jde o **ilustraci kontraktu**, nikoli skutečné hodnoty daného posledního
měření. Při převodu větru uvést výslednou jednotku i přepočet v katalogu.
Po překročení limitu vrátit stránkování nebo chybu; nevracet potichu prvních
5 000 bodů jako údajně kompletní rozsah. Kurzorem může být dvojice času a ID.
V dlouhých rozsazích použít agregace; `raw` export ponechat samostatně omezený.

Chyby mají HTTP stav a stabilní `error.code`/uživatelskou zprávu. Logovat
interní podrobnosti bez WU klíče a přihlašovacích údajů. Prázdná řada je
úspěšná odpověď s nulovým počtem bodů, nedostupná DB není prázdná úspěšná řada.

## 7. Nasazení na existující hosting a kompatibilita

- Nejdříve zjistit skutečný PHP runtime webu/CLI, podporu `pdo_mysql`,
  HTTP klienta a možnosti nastavení document root. Cíl z původního promptu
  je PHP 8.3; aktuální lokální CLI ani editor tento cíl společně nepotvrzují.
- Veřejně zpřístupnit pouze navržený adresář `public/`. Dump, zdrojové
  konfigurace, Composer/vendor, logy a CLI skripty nesmějí být běžně
  stahovatelné. Pokud hosting document root změnit neumí, umístit aplikační
  adresáře mimo jeho existující veřejný adresář a přizpůsobit bootstrap.
- Před přesunem ověřit, zda již nejsou další skutečné routy v nedodaném
  `loader/router.php`. Nový front controller je nesmí bez inventury přepsat.
- Zachovat URL starých historií pomocí tenkých wrapperů/route aliasů.
  `limit` má dnes rozdílné limity 500 a 8 500; při přechodu je buď zachovat
  v kompatibilní trase, nebo změnu výslovně oznámit.
- Ingesty přepnout kontrolovaně na jediný cron; nechat současně běžet staré
  i nové writery bez zámku/unikátní identity by znovu vytvářelo duplicity.
- Hlavní grafy nahradit ECharts, mapu Leafletem až po ověření publikované
  polohy a podmínek dlaždic; motivy realizovat nad jedním Bootstrap 5 stylem.

## 8. Doporučené etapy

1. **Potvrdit prostředí:** webové/CLI PHP, skutečné crony, chybějící router,
   konfiguraci zdroje, jednotky větru a tlaku, změnu nadmořské výšky.
2. **Sjednotit ingest a identitu:** záloha, audit aktuálních duplicit,
   archivace/deduplikace, unikátní klíč, jediný nový writer. Staré stránky
   stále fungují nad původní tabulkou.
3. **Zavést typované čtení a API:** null, UTC, jednotky, filtry, stabilní
   řazení, limity a explicitní chyby. Porovnat výstupy proti kopii dat.
4. **Zavést denní agregaci:** pokrytí, časové váhy, pozorovaný stav denního
   srážkového čítače, verze algoritmu; ověřit na dnech s mezerami i DST.
5. **Dashboard a historie:** KPI, ECharts, jednotná navigace, dark/light,
   CSV/JSON a responzivita; přesměrovat původní historii až po porovnání.
6. **Analytika:** růžice, heatmapy a rekordy s kvalitou/úplností. Teprve
   podle měření doplnit další materializace a cache.
7. **Další exporty a AI:** XLSX/PDF po volbě konkrétních knihoven a limitů;
   nejprve deterministické textové reporty z ověřených statistik, nikoli
   neověřená predikce.

Tato analýza nemění aplikační PHP ani původní data. Neověřuje živý dashboard,
výkon databázových dotazů nebo konečné DDL agregací; tyto kroky patří do
následné implementace na kopii a řízeného nasazení.
