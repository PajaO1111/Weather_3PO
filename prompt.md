Níže je prompt navržený pro GitHub Copilot Agent / Copilot Chat ve VS Code. Je napsaný tak, aby Copilot neudělal jen facelift stránek, ale navrhl kompletní profesionální meteorologickou platformu včetně návrhu databáze, migrace dat, analytiky a moderního UX.

PROMPT PRO COPILOT VS CODE

Analyzuj existující projekt meteorologické stanice postavený v PHP.

Vstupním bodem je soubor:

history5.php


Nejprve proveď důkladnou analýzu:

struktury aplikace
databázového schématu
SQL dotazů
použitých tabulek
datových toků
způsobu ukládání měření
způsobu vykreslování grafů
výkonových problémů
možností optimalizace

Nevytvářej pouze kosmetické úpravy.

Navrhni kompletní profesionální redesign na úrovni moderních meteorologických a analytických systémů jako:

Grafana
Meteoblue
Windy
Home Assistant Dashboard
Apple Weather
Ambient Weather
Davis WeatherLink
Ecowitt

Přitom zachovej plnou kompatibilitu s existující databází a daty.

CÍLE PROJEKTU

Vytvoř moderní meteorologický portál.

Priorita:

rychlost
přehlednost
analytika
responzivita
dlouhodobé statistiky

Design:

moderní
minimalistický
profesionální
dark/light mode
mobilní zařízení
desktop
tablet

Použij:

PHP 8.3
MariaDB
Bootstrap 5 nebo Tailwind
Apache ECharts
Leaflet
Chart.js pouze pokud je nutný


Preferuj:

Apache ECharts


kvůli:

zoomu
heatmapám
wind rose
exportům
velkým datasets
NOVÁ STRUKTURA WEBU

Navrhni následující moduly.

LIVE DASHBOARD

Aktuální podmínky.

Horní panel:

Teplota
Pocitová teplota
Vlhkost
Tlak
Vítr
Poryvy
UV
Sluneční radiace
Srážky dnes


Ve formě KPI karet.

Každá karta obsahuje:

aktuální hodnotu
trend
mini sparkline graf
barevné indikátory
INTERAKTIVNÍ GRAFY

Jeden hlavní graf.

Uživatel může zapínat:

Teplota
Rosný bod
Tlak
Vlhkost
Vítr
Nárazy větru
UV
Radiace
Srážky


Požadavky:

zoom
pan
výběr období
legenda
export PNG
export SVG
METEOGRAM

Navrhni profesionální meteogram.

Na jednotné časové ose zobraz:

teplota
srážky
vítr
tlak
vlhkost
UV
radiace


Podobně jako Meteoblue.

ANALYTIKA

Samostatná stránka.

Denní:

min
max
průměr
medián


Měsíční:

extrémy
součty srážek
průměrné teploty
větrnost


Roční:

rekordy
odchylky
trendy

KLIMATOLOGIE

Grafy:

roční průměry
měsíční průměry
extrémy
teplotní anomálie


Použij:

heatmapy
boxploty
violin ploty

pokud je podporuje knihovna.

EXTRÉMY

Databáze rekordů:

nejvyšší teplota
nejnižší teplota
nejvyšší tlak
nejnižší tlak
nejvyšší vítr
nejvyšší náraz
nejvyšší UV
nejvyšší radiace
nejvyšší denní úhrn srážek


Automatické generování.

VÍTR

Vytvoř sekci:

Wind Rose

Zobraz:

četnost směrů
rychlosti
nárazy


Meteorologicky správné.

Doplň:

převládající směr
nejčastější směr
nejvyšší rychlost dle směru

MAPA

Leaflet + OpenStreetMap.

Zobraz:

pozici stanice
základní data
aktuální podmínky
DATOVÝ MODEL

Analyzuj stávající tabulky.

Navrhni:

agregační tabulky

Denní agregace:

weather_daily


Obsah:

datum
tmin
tmax
tavg
humidity_min
humidity_max
humidity_avg
pressure_min
pressure_max
pressure_avg
wind_max
gust_max
rain_sum
uv_max
radiation_max


Měsíční agregace:

weather_monthly


Obsah:

rok
měsíc
tmin
tmax
tavg
rain_sum
wind_avg
wind_max


Roční agregace:

weather_yearly


Obsah:

rok
tmin
tmax
tavg
rain_sum
wind_max

VÝKON

Analyzuj indexy.

Navrhni:

INDEX(timestamp)
INDEX(measurement_time)
INDEX(date)
INDEX(year,month)


Podle skutečné struktury databáze.

Vyhledej:

pomalé SQL dotazy
zbytečné full table scan
opakované agregace

Nahraď je:

materialized aggregations
cache
prepared statements
CACHE

Navrhni:

Redis
nebo
souborovou cache


Pro:

denní statistiky
měsíční statistiky
rekordy
wind rose
RESPONSIVE UI

Dashboard musí fungovat:

320 px
768 px
1024 px
1920 px
3440 px


bez horizontálního scrollování.

DARK MODE

Automatický přepínač.

Použij:

prefers-color-scheme


a uživatelskou volbu.

EXPORTY

Přidej export:

CSV
JSON
XLSX
PDF
PNG graf
SVG graf

MONITORING KVALITY DAT

Detekuj anomálie:

výpadek senzorů
nereálné teploty
nereálný tlak
skokové změny
výpadek přenosu


Vytvoř systém upozornění.

AI ANALYTIKA

Připrav architekturu pro budoucí AI funkce:

predikce trendů
detekce anomálií
vyhledávání rekordů
porovnání období
generování textového reportu

VÝSTUP

Vygeneruj:

Analýzu stávající aplikace
Návrh nové architektury
Návrh databázových změn
SQL migrace
Nové REST endpointy
Návrh UI
Wireframe dashboardu
Kompletní adresářovou strukturu projektu
Ukázkové PHP třídy
Ukázkové SQL dotazy
Ukázkový kód Apache ECharts
Implementační plán po krocích
Seznam výkonových optimalizací
Rizika migrace

Nevytvářej obecný popis. Analyzuj skutečný soubor history5.php a existující databázové dotazy a navrhuj řešení přímo podle nalezené implementace. Navrhni konkrétní zdrojové soubory, konkrétní SQL migrace a konkrétní úpravy kódu.