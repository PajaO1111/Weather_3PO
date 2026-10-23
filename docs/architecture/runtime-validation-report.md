# Runtime test read-only prototypu

> **Historický záznam (22. 10. 2026).** Prototyp popsaný níže byl nahrazen
> 1. částí aplikace (stránka „Aktuálně“, API v `public/api/`, kompatibilita
> s PHP 7.4). Soubory `ObservationController.php`, `resources/views/dashboard.php`,
> `public/assets/dashboard.js` a URL `?route=…` již neexistují.
> Aktuální stav, nasazení a testy: [README.md](../../README.md).

Datum: 22. 10. 2026. Cíl: ověřit navržené rozdělení projektu nad původním
datovým modelem, bez přepisování CRONu a bez změny produkční tabulky.

## Rozhodnutí: CRON zůstává

Nový web pouze čte `pws_observations`. Původní
[fetch_weather.php](../../fetch_weather.php),
[fetch_weather2.php](../../fetch_weather2.php),
[history5.php](../../history5.php) i CRON se nemění.
Nespustila se migrace, ALTER, UPDATE, INSERT ani deduplikace na serveru.
Unikátní index není podmínkou běhu tohoto prototypu.

Duplikáty se řeší jen v načteném okně pro zobrazení. Stejný čas s rozdílnými
vybranými metrikami je označen jako konflikt, hodnoty se nepředstírají.
Kontrola se týká projekce metrik pro dashboard, ne všech metadat původního
payloadu. Nejde o vyčištění databáze.

## Co bylo skutečně implementováno

- [bootstrap.php](../../bootstrap.php): malý namespace autoloader bez nového
  package manageru.
- [Database.php](../../app/Infrastructure/Database.php): externí konfigurace,
  PDO a `SET SESSION TRANSACTION READ ONLY`; nastaví také UTC DB session.
- [ObservationQuery.php](../../app/Http/Validation/ObservationQuery.php):
  výchozích 288 posledních surových řádků, explicitní limit 1–500.
- [ObservationRepository.php](../../app/Weather/Repositories/ObservationRepository.php):
  původní tabulka, stanice z `WU_STATION_ID`, bindované hodnoty, pořadí UTC/ID.
- [ObservationService.php](../../app/Weather/Services/ObservationService.php):
  numerické typy, zachování null, duplicity/konflikty, stáří a budoucí čas.
- [ObservationController.php](../../app/Http/Controllers/ObservationController.php):
  JSON a CSV ze stejného výběru.
- [public/index.php](../../public/index.php):
  oddělený front controller; původní kořenový index není nahrazen.
- [dashboard.php](../../resources/views/dashboard.php) a
  [dashboard.js](../../public/assets/dashboard.js):
  devět KPI, jednoduché sparklines, ECharts, legenda, zoom, PNG,
  automatický/světlý/tmavý motiv, stav načítání, prázdná data a chyba.

Prototyp záměrně rozlišuje „teplotní index“ od obecné pocitové teploty a
„pozorovaný denní čítač“ od ověřeného denního srážkového úhrnu. Neprovádí
automatický výpočet nové veličiny ani dlouhodobých agregací.

## Konfigurace bez změny stávajících souborů

Diagnostická aktualizace: chybová odpověď API nyní obsahuje bezpečné
`diagnostics.stage`, případně povolený konfigurační kód, SQLSTATE a číselný
kód DB ovladače. Neobsahuje text výjimky, heslo, SQL ani cestu konfigurace.
Rozlišuje konfiguraci, spojení, nastavení časové zóny, read-only session a
čtení pozorování. Pro tuto diagnostiku nahrát současně
[Database.php](../../app/Infrastructure/Database.php) a
[public/index.php](../../public/index.php); samotná změna loaderu obecnou
odpověď staršího vstupního souboru nezmění.

Po upřesnění serverového umístění loader ve výchozím stavu používá
`config.php` **o dva adresáře výše než kořen projektu**:
projekt je v `/www/domains/oubrix.eu/Weather`, konfigurace v
`/www/domains/config.php`, mimo veřejný adresář domény.
Tato oprava se týká pouze nového loaderu; původní PHP a CRON se nemění.
Vyžaduje DB konstanty
a `WU_STATION_ID`; WU API klíč se nikam neodesílá a nový web WU API nevolá.
Dodaná konfigurace sama `.env` nenačítá. Prototyp proto za jejími zády
nepřepisuje konstanty podle `.env`.

V lokální kopii leží konfigurace přímo v kořeni projektu. Pro test se její
umístění zadalo explicitně:

```powershell
$env:WEATHER_CONFIG = 'X:\Weather_po\config.php'
php .\bin\check-connection.php
```

Přístupové údaje ani obsah `.env`/konfigurace nejsou součástí tohoto reportu.
Nový [.gitignore](../../.gitignore) tyto lokální soubory ignoruje; neřeší
jejich případné již existující publikování na webserveru nebo v jiné kopii Git.

### Nasazení vedle původního webu

Při potvrzeném umístění projektu v podadresáři `Weather` je testovací vstup
`/Weather/public/index.php`; API je
`/Weather/public/index.php?route=observations&limit=1`.
Assety jsou relativní k tomuto vstupu. Před uploadem
ale zajistit, že PHP zdrojové adresáře nelze stahovat jako soubory.

Preferované konečné řešení je samostatný document root na `public/`.
To nevyžaduje přesun ani změnu současného CRONu, který může dál používat
svou původní absolutní cestu. Neuploadovat do veřejného adresáře `.env`,
dump, logy, testy a konfigurační soubory. Bez znalosti skutečného serverového
layoutu zde nebylo provedeno přesměrování hlavní domény.

## Testing Strategy

- appType: server-rendered HTML + JavaScript grafy + PHP JSON/CSV.
- Legacy test assets: žádná automatická sada; `test_rings.php` je demo.
- Nativní spustitelná sada:
  `php tests\run.php` (tři soubory, bez nových závislostí).
- DB: živé připojení se zkouší pouze pro čtení; izolované integrační testy
  používají skutečnou lokální SQLite v paměti, nikoli mock PDO.
- Browser: integrovaný prohlížeč a Playwright; Docker není potřebný pro UI.
- Docker CLI není dostupné. MariaDB container proto není použit; SQLite
  neověřuje MariaDB DDL, execution plan, oprávnění ani READ ONLY session.
- PHP CLI je 8.2.12, Node 24.22.0. Běh pod požadovaným PHP 8.3 neověřen.
- Izolovaná fixture data nejsou tajně použita jako náhrada živé DB:
  testovací router zobrazuje výraznou informaci „syntetická data“ a produkční
  front controller při chybě vrací 503.

Ověřené cesty: validní/nevalidní dotaz → výběr jen dané stanice → prezentace
null a duplicit → JSON/CSV; selhání DB → 503 a viditelná chyba; graf → legenda
a zoom → export PNG; motiv → reload → zachování volby; pět šířek viewportu.
Tento veřejný read-only prototyp nemá login ani zápisové API; test autentizace
a produkční write/read-back by zde nebyl odpovídající a nebyl proveden.
Persistence byla ověřena pouze nad izolovanou SQLite a následně uklizena
zánikem spojení v paměti.

## Výsledky

| Test | Výsledek | Exit code / důkaz |
|---|---|---|
| Syntaxe nových PHP souborů a JavaScriptu | PASS | 0, `php -l`, `node --check` |
| Jednotkové a repository testy | PASS | 0; 25 kontrol, 0 selhání ve 3 souborech |
| Skutečné vzorky dumpu přes repository/SQLite | PASS | 0; 312 vstupů → 307 bodů, zachováno 8 null teplot, 5 duplicit |
| Fixture HTTP JSON/CSV | PASS | 0; pořadí času, null, export a 4 varianty neplatných limitů |
| Lokální produkční API při nedostupné DB | PASS pro chybovou cestu | HTTP 503, `DATA_UNAVAILABLE`, bez zveřejnění driver zprávy |
| Neplatný limit / trasa / metoda | PASS | HTTP 400 / 404 / 405 |
| Browser UI nad izolovanou SQLite | PASS | Playwright bez výjimky; 9 KPI, 9 řad, canvas vytvořen |
| Motiv a legenda | PASS | Tmavý motiv zachován po reloadu; výběr tlaku i osa zachovány při změně motivu |
| Zoom a PNG | PASS | Zoom 20–80 %, `data:image/png` |
| Připojení k původní DB z lokálního počítače | NEOVĚŘENO / připojení selhalo | CLI 1; `PDOException code=2002` |
| Skutečná MariaDB read-only session | NEOVĚŘENO | Spojení nevzniklo; nastavení session nebylo provedeno |

Kód 2002 je chyba spojení; bez další diagnostiky jej nelze označit za špatné
heslo, firewall nebo konkrétní DNS problém. Žádná taková příčina se zde
nepředstírá. Test je nutné zopakovat z hostingu s dostupnou DB.

### Přesný test responzivity

Playwright porovnal `document.documentElement.scrollWidth` s `innerWidth`.

| Viewport | Původní stránka: šířka obsahu | Prototyp: šířka obsahu | Prototyp přetéká |
|---:|---:|---:|---|
| 320 | 972 | 305 | ne |
| 768 | 972 | 753 | ne |
| 1024 | 1009 | 1009 | ne |
| 1920 | 1905 | 1905 | ne |
| 3440 | 3425 | 3425 | ne |

Rozdíl 15 px odpovídá místu pro scrollbar ve zkoušeném prohlížeči. Nejde
o přepočet návrhu, ale o skutečně naměřené rozměry.

### Veřejná produkční stránka

`https://oubrix.eu/history5.php?limit=288`: HTTP 200, 288 tabulkových řádků,
orientační jeden HTTP požadavek 1,383 s (není to benchmark).
Prohlížeč potvrdil JavaScript chybu čtení `_adapters` při načtení
chartjs-adapter-luxon před Chart.js a přetékání při 320/768 px.
Původní zdroj nebyl opraven, aby se nezměnil současný provoz.

## Opakování testů

```powershell
php .\tests\run.php
php -S 127.0.0.1:8137 -t .\public .\tests\support\router.php
```

V druhém terminálu:

```powershell
node .\tests\http.cjs http://127.0.0.1:8137/
```

Router v [tests/support/router.php](../../tests/support/router.php) je výhradně
pro izolované testy a spouští se pouze pod vestavěným PHP serverem; nesmí být
produkčním routerem. Reálný vzorek dumpu není přidán do veřejných assetů ani
nahrazen API fixture fallbackem.

Pro skutečné připojení:

```powershell
$env:WEATHER_CONFIG = 'X:\Weather_po\config.php'
php .\bin\check-connection.php
php -S 127.0.0.1:8138 -t .\public
```

Na hostingu použít odpovídající PHP CLI a serverové cesty. Ověřit především
konfiguraci dvě úrovně nad projektem a aktuální měření. Skript vypíše jen stav a
metadata, nikoli hesla.

## Pokrytí původního promptu

**V tomto kroku funguje:** základ KPI/sparkline, ECharts legenda a zoom, PNG,
JSON/CSV, dark/light, explicitní chyba DB, null, stáří dat a responzivita.

**Zbývá implementovat/ověřit:** vlastní časové rozsahy a agregované série,
meteogram, denní/měsíční/roční analytika, klimatologie/heatmapy/boxploty,
automatické rekordy, meteorologická wind rose, mapa Leaflet, SVG/XLSX/PDF,
cache, trvalé quality alarmy a budoucí AI služby. UI texty prototypu jsou
zatím zjednodušené; nejde o finální lokalizaci ani úplný redesign.

288 znamená počet surových řádků, nikoli garantovaných 24 hodin. Po spojení
duplicit může být bodů méně; API počty uvádí výslovně. Hranice stale/gap je
pro prototyp explicitně 2 hodiny, nikoli automaticky odvozená frekvence cronu.
Sparklines mají posledních 24 bodů; nejde o časově vážené statistiky.

```text
environment:
  docker: UNAVAILABLE — Docker CLI nenalezeno
  node: AVAILABLE — v24.22.0
  playwright: AVAILABLE — integrovany browser, vykonane Playwright kroky
  infra-tier: FALLBACK(SQLite) — realna MariaDB z tohoto pocitace nedostupna
  browser-tier: PRIMARY(Playwright)
startup: PASS — oba docasne PHP servery odpovedely; po testu zastaveny
integration: PASS — exit_code: 0; 25 kontrol + dump round-trip; MariaDB gap
e2e: PARTIAL — exit_code: 0 pro HTTP runner; Playwright bez vyjimky;
     fixture UI overeno, zive DB UI neovereno
overall: NEEDS_SIGNOFF — lokalni read-only prototyp overen, produkcni DB spojeni
         selhalo exit_code: 1; nelze prohlasit zive napojeni za uspesne
```
