Nejdříve k ČHMÚ: pro tvůj projekt je velmi zajímavé, že od roku 2025 provozuje Národní databázi hydrometeorologických údajů a produktů na OpenData ČHMÚ a zveřejňuje otevřená data s možností automatizovaného stahování přes API/datové služby. Součástí jsou meteorologická data, hydrologická data, výstrahy, produkty a metadata datových sad.

Z dostupných informací lze pro tvou stanici využít zejména:

meteorologická pozorování
klimatologické normály
výstrahy ČHMÚ
radarové produkty
hydrologická data
srážkové produkty
archivní data
metadata stanic
geoprostorová data INSPIRE

ČHMÚ uvádí, že otevřená data a metadata jsou dostupná přes OpenData ČHMÚ a související API rozhraní pro automatické stahování dat.

Co bych z ČHMÚ integroval
1. Výstrahy

Vlastní stránka:

Výstrahy ČHMÚ


Porovnání:

Oficiální výstraha:
Silný vítr

Moje stanice:
Max náraz 23,8 m/s

2. Klimatologické normály

To je obrovská přidaná hodnota.

Místo:

průměrná teplota září = 17.1°C


zobrazit:

Průměr září 2026:
17.1°C

Normál:
14.8°C

Odchylka:
+2.3°C

3. Sucho

Spojit:

Tvoje srážky
+
Normál ČHMÚ


a počítat:

Deficit srážek

4. Radar

Mapa:

Pozice stanice

+
Radar ČHMÚ

5. Historická porovnání

Například:

Most stanice OuBrix

vs

Most ČHMÚ


To je analyticky velmi zajímavé.

Prompt pro novou stránku analytics.php

Tento prompt bych použil přímo v Copilot Agentu.

Vytvoř novou stránku analytics.php.

Analyzuj existující databázi meteorologické stanice.

Použij stejné připojení k databázi jako v history5.php.

Použij Bootstrap 5 a Apache ECharts.

Cílem je vytvořit profesionální meteorologický analytický portál.

Nevytvářej pouze grafy.

Vytvoř datovou analytiku podobnou:

- Grafana
- Meteoblue
- Home Assistant
- WeatherLink

------------------------------------------------
HLAVNÍ DASHBOARD
------------------------------------------------

Zobraz KPI karty:

- počet měření
- počet dnů v databázi
- nejvyšší teplota historie
- nejnižší teplota historie
- nejvyšší náraz větru
- nejvyšší denní srážky
- nejvyšší UV
- nejvyšší radiace

Každá karta:

- hodnota
- datum rekordu
- ikona
- trend

------------------------------------------------
EXTRÉMY POČASÍ
------------------------------------------------

Vytvoř tabulku rekordů:

Teplota:
- absolutní maximum
- absolutní minimum

Tlak:
- maximum
- minimum

Vlhkost:
- maximum
- minimum

Vítr:
- maximum
- náraz

UV:
- maximum

Radiace:
- maximum

Srážky:
- nejvyšší hodinový úhrn
- nejvyšší denní úhrn
- nejvyšší měsíční úhrn

------------------------------------------------
KLIMATOLOGIE
------------------------------------------------

Počítej:

- průměrné teploty po měsících
- průměrné srážky po měsících
- průměrný vítr po měsících

Graf:

12 měsíců

Použij ECharts.

------------------------------------------------
TEPELNÉ REKORDY
------------------------------------------------

Počítej:

Letní den:
Tmax >= 25

Tropický den:
Tmax >= 30

Supertropický den:
Tmax >= 35

Mrazový den:
Tmin < 0

Ledový den:
Tmax < 0

Arktický den:
Tmin <= -10

Zobraz:

- tento rok
- minulý rok
- celá historie

------------------------------------------------
SUCHO
------------------------------------------------

Počítej:

srážky za:

7 dní
30 dní
60 dní
90 dní

Počet dní bez srážek.

Vypočítej:

dry_streak

Pokud:

30denní srážky < 20 mm

zobraz:

Mírné sucho

Pokud:

30denní srážky < 10 mm

zobraz:

Významné sucho

Pokud:

30denní srážky < 5 mm

zobraz:

Extrémní sucho

------------------------------------------------
HEATMAPA
------------------------------------------------

Vytvoř heatmapu:

každý den

barva dle:

- teploty
- srážek
- UV

Podobně jako GitHub Contributions.

------------------------------------------------
VÍTR
------------------------------------------------

Vytvoř Wind Rose.

Rozdělení:

N
NE
E
SE
S
SW
W
NW

Počítej:

- četnost
- průměrnou rychlost
- max náraz

Urči:

převládající směr větru.

------------------------------------------------
POROVNÁNÍ LET
------------------------------------------------

Graf:

2024
2025
2026

pro:

- teplotu
- srážky

na jednom grafu.

------------------------------------------------
INTEGRACE ČHMÚ
------------------------------------------------

Připrav samostatnou službu:

services/chmiService.php

která bude umět:

- načíst otevřená data ČHMÚ
- načíst výstrahy
- načíst klimatologické normály
- načíst radarová metadata

Implementaci odděl od hlavní aplikace.

------------------------------------------------
DB AGREGACE
------------------------------------------------

Navrhni SQL migrace:

weather_daily
weather_monthly
weather_yearly
weather_records
weather_drought

Vytvoř SQL CREATE TABLE.

Vytvoř indexy.

Optimalizuj všechny dotazy.

------------------------------------------------
AI REPORT
------------------------------------------------

Automaticky generuj měsíční report:

Například:

'Září 2026 bylo o 2.1°C teplejší
než dlouhodobý průměr.
Spadlo 61 mm srážek,
což odpovídá 82 % normálu.
Nejsilnější náraz dosáhl 19.2 m/s.'

------------------------------------------------
VÝSTUP
------------------------------------------------

Vygeneruj:

1. analytics.php
2. SQL migrace
3. weather_daily agregace
4. weather_monthly agregace
5. weather_yearly agregace
6. weather_records agregace
7. weather_drought agregace
8. služby pro ČHMÚ
9. ECharts konfigurace
10. doporučení optimalizací


Můj návrh je jít ještě dál a vytvořit kolem stanice samostatný modul„Klima Most 2024–2030“, který bude kombinovat vlastní měření, normály ČHMÚ, výstrahy ČHMÚ, radar a dlouhodobé trendy. Tím se z obyčejného dashboardu stane skutečná lokální klimatologická databáze.