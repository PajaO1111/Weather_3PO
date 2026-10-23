Tohle už není jen "meteorologická stránka", ale malý meteorologický informační systém. Pokud to budeš předělávat, doporučil bych navrhnout architekturu tak, aby vydržela dalších 10 let a bylo možné postupně přidávat nové senzory, AI analýzu, předpovědi i klimatologické statistiky.

Cíle projektu OuBrix Weather 2.0

Hlavní myšlenky:

maximálně přehledné
mobile first
profesionální vzhled
rychlé načítání
analytika a historické trendy
možnost budoucí AI vrstvy
modularita

Chtěl bych dosáhnout dojmu:

Apple Weather + Grafana + Meteoblue + Windy

ne

tabulka s grafy

Technologie
Backend

PHP 8.4

MVC styl:

PHP
MariaDB
Redis (cache)


Redis časem velmi pomůže.

Například:

aktuální data
denní statistiky
měsíční statistiky
heatmapy


nemusí být znovu počítány.

Frontend

Doporučuji:

Tailwind CSS
AlpineJS
ECharts


Důvod:

Bootstrap dnes působí trochu zastarale.

Tailwind ti umožní vytvořit:

skleněné panely
moderní dashboard
dark mode
responzivní karty

bez boje s frameworkem.

Struktura adresářů
/app
    /Controllers
    /Services
    /Models
    /Repositories

/config

/public

/resources

    /views

        /dashboard
        /climate
        /statistics
        /wind

    /js
    /css

/storage

    /cache
    /logs

/database

    /migrations

/api

/tests

Datová vrstva

Dnes nejspíš ukládáš měření po jednotlivých záznamech.

Přidal bych agregace.

Tabulka
weather_data


obsahuje:

timestamp
temperature
humidity
pressure
wind_speed
wind_gust
wind_direction
uv
solar_radiation
rain

Denní agregace
weather_daily


obsahuje:

date

temp_min
temp_max
temp_avg

rain_sum

gust_max

pressure_min
pressure_max
pressure_avg

Měsíční agregace
weather_monthly

Roční agregace
weather_yearly


Tím zrychlíš statistiky o řád.

Menu
Dashboard
Přehled


Aktuální situace.

Historie
Historické grafy


To je dnešní stránka 5.

Klima
Dlouhodobý vývoj


roky

měsíce

sezóny

Vítr

Samostatný modul.

Srážky

Samostatný modul.

Extrémy

Rekordy.

Analýzy

Heatmapy.

Dashboard

První obrazovka.

Hero

Uprostřed:

18.4 °C

Polojasno


Pod:

Vlhkost
Tlak
Vítr
UV
Rosný bod


Styl:

█████████████████

18.4 °C

Polojasno

█████████████████


Apple Weather.

KPI karty

Řádek karet

┌────────┐
│ 18.4°C │
│ tepl.  │
└────────┘

┌────────┐
│ 72%    │
│ vlhk.  │
└────────┘


Každá obsahuje mini sparkline.

Například:

18.4°C

▁▁▂▂▃▄▅▆▇

Hlavní graf

Tohle bude největší změna.

Místo:

8 grafů pod sebou


bude:

1 graf


s přepínači.

Například:

☑ Teplota

☑ Vlhkost

☐ UV

☐ Radiace

☑ Tlak

☐ Déšť


ECharts umí:

zoom
drag
export PNG
tooltip
synchronizaci


Graf může mít:

levou osu

°C


pravou osu

hPa


druhou pravou

%


automaticky.

Heatmap centrum

Tohle podle mě bude unikátní část.

Heatmapa teplot

Rok.

Styl GitHub.

leden

🟦🟦🟩🟩🟨🟥


Každý den.

Na hover:

15.7.2026

min 16.2

max 32.4

avg 25.3

Heatmapa srážek

Barevná škála:

bílá

světle modrá

modrá

tmavě modrá

Heatmapa větru

Ukazuje:

průměrnou rychlost
poryvy
Heatmapa UV

Léto okamžitě vyskočí.

Heatmapa tlaku

Uvidíš přechody front.

Modul Vítr

Samostatná stránka.

Wind Rose

Nejdůležitější.

Ukáže:

N

NE

E

SE

S

SW

W

NW


a četnost.

Směr v čase

Další graf.

Největší poryvy

Tabulka.

Dny s nejsilnějším větrem

Top 100.

Modul Klima

Tohle bývá velmi populární.

Teplota po letech

Například:

2022

2023

2024

2025

2026


v jednom grafu.

Roční extrémy

Rekordy.

Klimatické normály

Průměr:

leden

únor

březen


atd.

Modul Extrémy

Samostatná stránka.

Nejvyšší teplota

39.8 °C

12.8.2025


Nejnižší teplota

-18.3 °C


Nejsilnější náraz

24.2 m/s


Největší denní déšť

67 mm

AI sekce

Budoucnost.

Každý den generovat text.

Například:

Dnešní den byl výrazně teplejší než dlouhodobý průměr.

Maximální teplota dosáhla 31.4°C.

Převládal severozápadní vítr.

Srážky nebyly zaznamenány.


Automaticky.

Dark Mode

Povinně.

Přepínač:

🌞 🌙

Design systém

Barvy:

modrá = voda

červená = teplota

zelená = vegetace

žlutá = UV

fialová = tlak


Konzistentně všude.

Moje doporučené finální rozložení
Dashboard
│
├── Aktuální stav
├── Hlavní graf
├── KPI karty
│

Historie
│
├── Interaktivní graf
├── Export CSV
├── Export PNG
│

Heatmapy
│
├── Teplota
├── Srážky
├── Tlak
├── UV
├── Vítr
│

Vítr
│
├── Wind Rose
├── Směry
├── Poryvy
│

Klima
│
├── Měsíce
├── Roční trendy
├── Normály
│

Extrémy
│
├── Rekordy
├── TOP100
│

O stanici


Tím bys měl systém, který bude vizuálně i funkčně na úrovni profesionálních řešení typu Meteoblue, WeatherLink nebo Grafana, ale zároveň přizpůsobený přesně datům ze stanice Sencor 12500 a specifikům Mostecka.