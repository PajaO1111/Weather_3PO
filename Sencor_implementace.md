# Sencor SWS 12500: připojení k vlastnímu webu

Tento návod popisuje přímé ukládání měření ze stanice Sencor SWS 12500 do
databáze webu OuBrix Weather. Weather Underground (WU) zůstává záložním zdrojem.
Domácí počítač není pro běžný provoz potřeba.

Postup byl ověřen v listopadu 2026 na stanici, jejíž konfigurační stránka
uváděla **firmware 2.06**, a na hostingu WEDOS s PHP 8.5 a MariaDB.
Jiné verze firmwaru mohou mít jiné chování; zejména WSLink a firmware 3.x
nelze automaticky považovat za stejný protokol.

## 1. Jak přenos funguje

```text
Sencor v domácí síti
  → odchozí HTTP GET, port 80
  → station.oubrix.eu/weatherstation/updateweatherstation.php
  → předávací PHP soubor na subdoméně
  → station-upload.php
  → weather-ingest.php
  → MariaDB: pws_observations

CRON každých 5 minut
  → fetch_weather2.php
  → kontrola čerstvosti přímých dat
  → případně aktuální měření z Weather Underground
  → stejný ukládací mechanismus
```

Stanice **odesílá sama**. Web se nepřipojuje do domácí sítě a není potřeba
otevírat příchozí porty na domácím routeru.

### Ověřené chování stanice

- Pole URL obsahuje pouze hostitele, například `station.oubrix.eu`.
- Nezadává se `http://`, cesta `/station-upload.php` ani `:80`.
- Na ověřené stanici přicházel GET na
  `/weatherstation/updateweatherstation.php` přibližně každých 12 sekund.
- Celý 32znakový klíč dorazil správně; nebylo potřeba jej zkracovat.
- Stanice neposílá JSON. Posílá parametry v URL; JSON v diagnostice vytváří
  až přijímací program.

Na hlavní doméně za WEDOS Global/CDN se přímý příjem nepodařilo rozběhnout.
Po přechodu na subdoménu mířící přímo na hosting začala data přicházet.
**Přesná příčina původního selhání nebyla prokázána.** Není tedy správné
tvrdit, že WEDOS vždy blokuje tento model stanice.

## 2. Předpoklady a bezpečnost

Pro tento projekt potřebujete:

- funkční web a existující tabulku `pws_observations`;
- PHP s PDO MySQL;
- MariaDB/MySQL a tabulky používající InnoDB;
- přístup k DNS, FTP a databázi;
- pro zálohu přes WU platné `WU_STATION_ID`, `WU_API_KEY` a nastavený CRON.

Nejde o samostatný instalátor libovolného webu. Přijímač používá schéma a
konfiguraci tohoto projektu. Pro jiný web je nutné přizpůsobit cesty, schéma
a následné zobrazování dat.

**Důležité:** ověřená stanice posílá přes nešifrované HTTP. Klíč proto může
být viditelný na přenosové cestě a v přístupových logách obsahujících celé URL.
Použijte samostatný náhodný klíč výhradně pro příjem měření. Nikdy nepoužívejte
heslo do administrace, databáze, WU API klíč ani token CRONu.

- Klíč neukládejte do Git repozitáře, návodu ani veřejného diagnostického souboru.
- Pokud byl zveřejněn, vyměňte jej ve stanici i v neveřejné konfiguraci.
- Nesdílejte celé URL požadavků stanice s parametry.
- Samostatná subdoména mimo CDN znamená záměrné obejití této ochranné vrstvy.
  Nevypínejte kvůli tomu ochranu celého webu.

## 3. Záloha a příprava databáze

1. Zálohujte databázi, konfiguraci a původní sběrné skripty.
2. Ověřte engine tabulky:

   ```sql
   SHOW TABLE STATUS LIKE 'pws_observations';
   ```

   Sloupec `Engine` musí uvádět `InnoDB`. Pokud tomu tak není, naplánujte
   převod se zálohou a s ohledem na velikost tabulky; přijímač jiný engine odmítá.
3. V databázi webu spusťte jednou
   [004_weather_ingest.sql](database/migrations/004_weather_ingest.sql).
4. Ověřte existenci a InnoDB u všech tří tabulek:
   - `pws_observations`;
   - `weather_ingest_state`;
   - `weather_ingest_slots`.

Migrace přidává pomocné tabulky. Původní měření nemění ani nemaže.

## 4. Umístění souborů na WEDOS

Ověřené rozložení v FTP:

```text
/www/
  .htaccess                          původní směrování WEDOS
  domains/
    config.php                       neveřejná konfigurace
    station-upload.log               log přímého příjmu
    fetch_weather2.log               log záložního CRONu
    oubrix.eu/
      station-upload.php
      weather-ingest.php
      fetch_weather2.php
      .htaccess                      pravidla hlavního webu
      Weather/                       stávající webová aplikace
  subdom/
    station/
      weatherstation/
        updateweatherstation.php     předání do společného přijímače
```

Do kořene hlavního webu nahrajte společně:
[station-upload.php](station-upload.php),
[weather-ingest.php](weather-ingest.php) a
[fetch_weather2.php](fetch_weather2.php).

Nestačí je uložit pouze do složky `Weather/`. Sběrné skripty načítají
`../config.php` vzhledem ke svému umístění a do stejného nadřazeného adresáře
zapisují logy. PHP musí mít oprávnění logy vytvářet a aktualizovat;
nepovolujte zbytečně zápis všem pomocí oprávnění `777`.

## 5. Konfigurace ID a klíče

Vygenerujte klíč lokálně:

```powershell
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```

Výsledkem je 32 hexadecimálních znaků. Výsledek nikam veřejně neposílejte.
V neveřejném `/www/domains/config.php` přidejte:

```php
define('STATION_UPLOAD_ID', 'IMOST71');
define('STATION_UPLOAD_KEY', '<SEM_VLOZTE_VLASTNI_NAHODNY_KLIC>');
```

Zástupnou hodnotu nahraďte skutečným klíčem. Přijímač vyžaduje minimálně
32 znaků. Zachovejte existující databázové konstanty, `WU_STATION_ID`,
`WU_API_KEY` a `FETCH_TOKEN`.

`STATION_UPLOAD_ID` slouží k přihlášení. Data se ukládají pod `WU_STATION_ID`,
aby je našly stávající stránky webu. Pokud `STATION_UPLOAD_ID` není definováno,
použije se také `WU_STATION_ID`.

Pozor na chybějící středníky a neuzavřené uvozovky. Syntaktická chyba ve
sdílené konfiguraci může vyřadit přijímač, CRON i API webu. Soubor lze
lokálně zkontrolovat příkazem `php -l` s cestou ke konfiguraci.

## 6. DNS subdomény přímo na hosting

V detailu webhostingu zjistěte **WWW IPv4**, nikoli adresu FTP nebo databáze.
V ověřeném nasazení:

| Účel | IPv4 |
|---|---|
| Hlavní doména přes CDN | `185.8.237.11` |
| Vlastní WWW hosting | `46.28.106.206` |

Tyto adresy jsou příkladem konkrétního nasazení, ne univerzálními adresami WEDOS.
Pro jiný hosting použijte jeho skutečnou WWW IPv4.

V DNS vytvořte:

| Název | TTL | Typ | Data |
|---|---:|---|---|
| `station` | 300 | A | `46.28.106.206` |

Záznamy hlavní domény ani wildcard `*` neměňte. Explicitní jméno `station`
s vlastním A záznamem nezíská AAAA z wildcardu. Pokud však existuje
**explicitní AAAA pro `station`**, ověřte, kam směřuje, a nenechte jej vést
na jinou službu než zamýšlený přijímač.

Změna může být dočasně skrytá DNS cache. Ve Windows ověřte:

```powershell
Resolve-DnsName station.oubrix.eu -Type A
```

Samotný DNS záznam nestačí. Hosting musí subdoménu také obsluhovat.

## 7. Předávací soubor na subdoméně

Původní hlavní `.htaccess` WEDOS v `/www/` v tomto nasazení směruje
subdoménu `station.oubrix.eu` do `/www/subdom/station/`.
Toto pravidlo ponechte; nepřepisujte je pravidly hlavního webu.

Vytvořte přes FTP adresář:

```text
/www/subdom/station/weatherstation/
```

Do něj nahrajte soubor `updateweatherstation.php`:

```php
<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/domains/oubrix.eu/station-upload.php';
```

Cesta platí pro výše uvedené rozložení. Na jiném hostingu ji přizpůsobte.
Konfigurace a logy se nadále určují podle původního `station-upload.php`,
nikoli podle předávacího souboru. Klíč ani konfiguraci sem nekopírujte.

Tato cesta nesmí být přesměrována na HTTPS, kanonickou hlavní doménu ani
přihlašovací stránku. Příjem nesmí vyžadovat JavaScript nebo cookies a
nesmí se obsluhovat z cache. Stávající přijímač posílá hlavičky `no-store`.

## 8. Ověření endpointu před nastavením stanice

Použijte PowerShell:

```powershell
curl.exe -i --connect-timeout 10 --max-time 20 "http://station.oubrix.eu/weatherstation/updateweatherstation.php"
```

Bez ID a klíče je správný výsledek:

```text
HTTP/1.1 403 Forbidden
...
invalid credentials
```

To potvrzuje dostupnost skriptu, **nikoli příjem měření ani úspěšný zápis do DB**.
Nezadávejte skutečný klíč do testovacího URL, které by zůstalo v historii.

| Výsledek | Význam |
|---|---|
| 403 + `invalid credentials` | Skript očekávaně odmítl požadavek bez údajů |
| 404 | Nesprávná složka, cesta nebo obsluha subdomény |
| 301/302 | Nežádoucí přesměrování; nejdřív opravit pravidla či CDN |
| 503 + `temporarily unavailable` | Zkontrolovat konfiguraci a log přijímače |
| Timeout | Prověřit DNS, port 80, dostupnost hostingu a síťovou ochranu |

### Prohlížeč hlásí neplatný certifikát

Hlavní web může posílat HSTS s `includeSubDomains`. Prohlížeč pak sám převede
HTTP na HTTPS, i když server žádné přesměrování neposlal. Pokud subdoména
nemá odpovídající certifikát, zobrazí například `ERR_CERT_COMMON_NAME_INVALID`.

Varování neobcházejte a nevypínejte ochranu prohlížeče. Pro ověření skutečné
HTTP odpovědi použijte výše uvedený `curl.exe`. Prohlížečové HSTS není důkazem,
že stejný přechod na HTTPS provádí i stanice. Platný certifikát lze pro
subdoménu zajistit samostatně, ale nesmí tím vzniknout přesměrování jejího
upload endpointu z HTTP na HTTPS.

## 9. Nastavení Sencoru

1. Podržením tlačítka Wi-Fi podle návodu stanice zapněte režim AP.
   Komunitní návod uvádí přibližně 6 sekund; řiďte se označením svého modelu.
2. Připojte telefon nebo počítač k Wi-Fi stanice.
3. Otevřete konfigurační stránku `http://192.168.1.1`.
4. V části **WiFi Router setup** ponechte nastavení domácí Wi-Fi.
5. V části **Weather Server Setup** najděte třetí blok pod Wunderground
   a Weathercloud.
6. Vyplňte:

   | Pole | Hodnota pro ověřené nasazení |
   |---|---|
   | URL | `station.oubrix.eu` |
   | Station ID | `IMOST71`, shodné s `STATION_UPLOAD_ID` |
   | Station key | Vlastní klíč shodný s `STATION_UPLOAD_KEY` |

7. Nepřidávejte `http://`, `/weatherstation/...`, `/station-upload.php` ani port.
8. **Wunderground a Weathercloud ponechte nastavené**, aby dál dostávaly data.
9. Stiskněte **Apply**. Stanice musí opustit konfiguraci a připojit se zpět
   k domácí síti. Při kontrole znovu otevřeného nastavení má URL zůstat uložená.

U ověřeného přístroje byl klíč 32 znaků přenesen beze změny. Počet hvězdiček
v maskovaném poli není spolehlivým ukazatelem délky uloženého klíče.

## 10. Kontrola příjmu a databáze

V `/www/domains/station-upload.log` očekávejte:

```text
2026-11-09 22:57:05Z UPDATED source=direct obs=2026-11-09 22:57:05
2026-11-09 22:57:17Z UPDATED source=direct obs=2026-11-09 22:57:17
```

Časy se suffixem `Z` jsou UTC. V českém zimním čase je místní čas o hodinu vyšší.

- `OK`: vložen nový řádek.
- `UPDATED`: aktualizován existující řádek v daném slotu.
- `EXISTS`: příjem nevyžadoval přepsání řádku, například opakované měření.
- `DENIED`: přihlášení nebo metoda neprošly.
- `ERROR`: chyba měření, konfigurace nebo databáze.

V databázi:

```sql
SELECT id, station_id, obs_time_utc, obs_time_local, software_type,
       temp_c, humidity, pressure, wind_speed, precip_total, inserted_at
FROM pws_observations
WHERE station_id = 'IMOST71'
ORDER BY obs_time_utc DESC, id DESC
LIMIT 12;
```

Přímé zprávy bez vlastního `softwaretype` mají `software_type = 'Sencor direct'`.
Měření mají skutečný čas; nezaokrouhlují se na začátek slotu.

### Proč nevzniká řádek každých 12 sekund

Ukládá se poslední platné měření každého **5minutového slotu v UTC**:
například 22:55:00 až 22:59:59. Další novější zprávy ve slotu aktualizují
stejný řádek. `inserted_at` přitom zůstává časem prvního vložení, takže může
být starší než aktuální `obs_time_utc`.

Přímá data mají před WU přednost. Zámek stavu stanice, transakce a unikátní
klíč v `weather_ingest_slots` chrání souběžné zápisy. WU přímá data nepřepíše.
Historické duplicity vytvořené starými importéry se tímto automaticky nemažou.

### Jednotky a chybějící hodnoty

| Parametr stanice | Uložení |
|---|---|
| `tempf`, `dewptf` | °F → °C |
| `humidity` | % |
| `baromin` | inHg → hPa |
| `windspeedmph`, `windgustmph` | mph → km/h |
| `winddir` | stupně |
| `dailyrainin` | palce → mm, denní čítač |
| `solarradiation` | W/m² |
| `UV` | UV index |

`rainin` se v současném kódu převádí na `precip_rate` jako in/h → mm/h.
**Význam tohoto pole je nutné ověřit při dešti proti displeji konkrétní
stanice.** Některé varianty WU protokolu jím označují úhrn za poslední hodinu;
nelze jej bez ověření zaměňovat za okamžitou intenzitu.

Chybějící volitelné veličiny, například `heat_index` a `wind_chill`, zůstávají
`NULL`, nikoli nula. `qc_status` je u přímých dat `NULL`, protože neprošla
WU kontrolou kvality. Lokalita, souřadnice a výška se přebírají z posledního
uloženého měření stanice; bez předchozích metadat zůstanou `NULL`.
Pole `indoortempf` a `indoorhumidity` současný ukládací mechanismus neukládá.

Denní srážky se nesčítají přes jednotlivé zprávy. Po místní půlnoci může denní
čítač správně klesnout na nulu.

## 11. Záložní CRON přes Weather Underground

Ponechte původní plán **každých 5 minut** a stejný token. Musí však spouštět
novou verzi [fetch_weather2.php](fetch_weather2.php), která používá společný
ukládací mechanismus.

Webový CRON má tvar:

```text
https://oubrix.eu/fetch_weather2.php?token=<NEVEREJNY_FETCH_TOKEN>
```

Pokud čas posledního přímého přijetí **i čas měření** jsou mladší než
10 minut, log `/www/domains/fetch_weather2.log` obsahuje:

```text
SKIP reason=direct_fresh
```

Po 10 minutách bez čerstvých přímých dat se při následujícím běhu CRONu
zkusí aktuální WU měření. Při úspěchu log obsahuje například `OK source=wu`.
Po obnovení přímých dat se WU opět přeskočí.

- Vypněte staré importéry, které by zapisovaly mimo společný mechanismus.
- CRON není zpětné doplňování historie; stahuje aktuální měření.
- `NODATA http=204` znamená, že WU aktuální data nemá.
- Při výpadku celé DB nebo hostingu nemůže ukládat ani záložní zdroj.

Pro zkoušku výpadku vypněte pouze vlastní upload, pokud to nastavení dovoluje,
nebo dočasně změňte jen jeho cílovou URL. Nevypínejte Wi-Fi celé stanice:
tím byste současně odpojili i WU. Po testu obnovte správnou URL.

## 12. Diagnostika problémů

| Projev | Co ověřit |
|---|---|
| V logu jen `Mozilla`, `params=` prázdné | Jde o prohlížeč bez měření, nikoli důkaz přenosu stanice |
| Žádné nové řádky od stanice | Uložení Apply, domácí Wi-Fi, hostitel bez cesty, DNS, access/WAF logy |
| `id=mismatch` | Shoda Station ID s konfigurací |
| `key=mismatch` | Shoda klíče; nevyvozovat automaticky zkrácení |
| `key=truncated` | Dorazil kratší prefix očekávaného klíče; prověřit uloženou délku |
| `key=whitespace` | Klíč obsahuje mezery na okrajích |
| `id=missing`, `key=missing` | Požadavek neposlal očekávané parametry; prověřit `params` a metodu |
| `station_upload_key_missing_or_short` | Konfigurace nemá klíč alespoň 32 znaků |
| `ingest_tables_require_InnoDB` | Chybějící migrace nebo některá tabulka není InnoDB |
| HTTP 400, `invalid observation` | Log uvádí chybějící, neplatnou nebo časově nepřípustnou veličinu |
| HTTP 503 | Log přijímače a serverový PHP error log, konfigurace, DB |

Přijímač odmítá měření staré 10 nebo více minut a čas více než 2 minuty
v budoucnosti. `dateutc=now` používá čas přijetí serverem; v takové zprávě
nelze určit, zda konzole opakuje staré hodnoty ze senzoru.

API webu lze samostatně ověřit na
`https://oubrix.eu/Weather/public/api/health.php`.
`config: ok` a `database: ok` potvrzují konfiguraci a čtení DB, nikoli zdroj dat.
`latestObservationUtc` má postupovat. Při `stage: configuration` nejdříve
prověřte sdílený config a bezpečný diagnostický kód.

Rozšíření prohlížeče mohou vypisovat `runtime.lastError` nebo `chext_driver`;
tyto zprávy samy o sobě nejsou chybou přijímače.

## 13. Volitelný test na počítači v domácí síti

Tento test obejde internet, veřejné DNS, CDN a hosting.
Není součástí trvalého provozu.

1. Připojte počítač k domácímu routeru, nikoli k AP stanice.
2. Pomocí `ipconfig` zjistěte jeho skutečnou místní IPv4.
   V ověřeném testu to byla `192.168.50.162`; tuto hodnotu nekopírujte
   bez kontroly vlastní adresy.
3. Připravte dočasný přijímač mimo veřejný web:

   ```powershell
   New-Item -ItemType Directory -Force "$env:TEMP\sencor-test"
   notepad "$env:TEMP\sencor-test\receiver.php"
   ```

4. Do souboru vložte:

   ```php
   <?php
   $input = array_merge($_GET, $_POST);
   error_log(json_encode([
       'timeUtc' => gmdate('c'),
       'method' => $_SERVER['REQUEST_METHOD'] ?? '',
       'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
       'fields' => array_keys($input),
       'hasId' => isset($input['ID']),
       'hasPassword' => isset($input['PASSWORD']),
   ], JSON_UNESCAPED_SLASHES));
   header('Content-Type: text/plain');
   header('Cache-Control: no-store');
   echo "success\n";
   ```

5. Spusťte PHP na portu 80, například s XAMPP:

   ```powershell
   Set-Location "$env:TEMP\sencor-test"
   & "C:\xampp\php\php.exe" -S 0.0.0.0:80 receiver.php
   ```

6. Povolte případný firewall pouze pro soukromou domácí síť. Firewall
   nevypínejte a nenastavujte přesměrování portu z internetu.
   Pokud port 80 používá jiný program, řešte konflikt; jiný port není
   rovnocenným testem tohoto firmwaru.
7. Z telefonu na stejné domácí Wi-Fi ověřte
   `http://<IP_POCITACE>/weatherstation/updateweatherstation.php`.
   Očekávejte `success`; tento požadavek ještě není zpráva od stanice.
8. Do URL stanice vložte pouze IP počítače a potvrďte Apply.
9. Sledujte konzoli. Přítomnost polí jako `tempf`, `baromin`, `humidity`
   a `dateutc` potvrzuje přenos měření. `hasPassword: true` znamená jen
   přítomnost parametru, nikoli správnost jeho hodnoty.
10. Ukončete server pomocí Ctrl+C a vraťte URL na `station.oubrix.eu`.
    Odstraňte nepotřebnou výjimku firewallu pro testovací server.

Tento jednoduchý přijímač nic neukládá a neověřuje přihlášení. K ověření
správnosti klíče je potřeba porovnání pomocí `hash_equals` proti klíči
zadanému neveřejně, nikoli jeho výpis. Při reálném testu bylo ověřeno i
úspěšné přihlášení s 32 znaky a převod měření stejnou třídou `WeatherIngest`.

## 14. Kontrolní seznam dokončeného nasazení

- [ ] Existují všechny tři databázové tabulky a používají InnoDB.
- [ ] Sběrné skripty a neveřejná konfigurace jsou na správných cestách.
- [ ] Samostatný klíč má alespoň 32 znaků a není zveřejněný.
- [ ] DNS subdomény směřuje přímo na správný hosting.
- [ ] HTTP endpoint odpovídá bez přesměrování a bez cache.
- [ ] Stanice má uložený pouze název hostitele a správné ID/klíč.
- [ ] Log obsahuje pravidelná `source=direct`.
- [ ] DB obsahuje čerstvé řádky `Sencor direct` a nevznikají nové duplicity slotů.
- [ ] CRON při čerstvých přímých datech hlásí `SKIP reason=direct_fresh`.
- [ ] Záloha přes WU zůstává dostupná při výpadku vlastního uploadu.
- [ ] Denní srážky se nesčítají opakovaně a význam `rainin` je ověřen při dešti.
- [ ] Domácí testovací server je vypnutý; pro provoz není potřeba počítač.

## 15. Komunitní zdroje a jejich omezení

- [SWS-12500-custom-component](https://github.com/schizza/SWS-12500-custom-component):
  návod pro vlastní server a rozdíly vůči WSLink.
- [Původní WeatherStation-SWS12500](https://github.com/schizza/WeatherStation-SWS12500):
  historický návod upozorňující na problém URL s cestou.
- [Konstanty přijímacích cest](https://github.com/schizza/SWS-12500-custom-component/blob/main/custom_components/sws12500/const.py):
  `/weatherstation/updateweatherstation.php`.
- [Dokumentovaná chyba s portem](https://github.com/schizza/SWS-12500-custom-component/blob/main/firmware_bug.md):
  popsána pro firmware 1.0, s upozorněním na možný výskyt i jinde.
- [Česká diskuse](https://www.homeassistant-cz.cz/viewtopic.php?t=1349&start=50):
  obsahuje i hlášení problémů s firmwarem 2.06; samotná diskuse není důkaz
  konkrétní příčiny našeho původního selhání.

Rozhodující pro tento návod jsou skutečně přijaté požadavky, úspěšné ověření
klíče, logy `source=direct` a kontrola uložených řádků v databázi.
