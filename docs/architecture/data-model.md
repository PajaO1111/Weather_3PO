# Ověřený existující datový model

Zdroj: [DDL a obsah SQL exportu](../../pws_observations.sql).
Export obsahuje jednu tabulku, nikoli nutně všechny tabulky serverové DB.
Strojový profil: [dump-profile.json](dump-profile.json).

## Tabulka `pws_observations`

InnoDB, `utf8mb4_general_ci`, jediný index `PRIMARY KEY (id)`.
Nejsou deklarovány cizí klíče, unikátní identita stanice/času ani vedlejší
indexy. `AUTO_INCREMENT=72240` odpovídá maximu exportovaného ID 72 239.

| Pole | Fyzický typ | NULL / výchozí |
|---|---|---|
| id | int unsigned, AUTO_INCREMENT | NOT NULL, PK |
| station_id | varchar(16) | NOT NULL |
| obs_time_utc | datetime | NOT NULL |
| obs_time_local | datetime | NOT NULL |
| neighborhood | varchar(64) | NULL |
| software_type | varchar(64) | NULL |
| country | char(2) | NULL |
| solar_radiation | decimal(7,2) | NULL |
| lon, lat | decimal(9,6) | NULL |
| realtime_frequency, epoch | int | NULL |
| uv | decimal(4,2) | NULL |
| winddir | smallint | NULL |
| humidity, qc_status | tinyint | NULL |
| temp_c, heat_index, dew_point, wind_chill | decimal(5,2) | NULL |
| wind_speed, wind_gust | decimal(5,2) | NULL |
| pressure, precip_rate, precip_total | decimal(7,2) | NULL |
| elevation | smallint | NULL |
| inserted_at | timestamp | NOT NULL, current_timestamp() |

Celkem 27 sloupců. Neexistuje ORM ani entity class; ingest zapisuje 25 sloupců
a automaticky vznikají `id` a `inserted_at`. Žádná explicitní transakční
hranice není v PHP uvedena. U druhého ingestu jsou kontrolní SELECT a INSERT
dvě samostatná volání.

## Entity a vztahy

Jediná fyzická entita je pozorování. `station_id` je atribut, nikoli doložený
cizí klíč na tabulku stanic. Metadata stanice se opakují v každém pozorování.
Žádné další vztahy ani agregační tabulky nejsou v tomto exportu definovány.

## Statistický profil exportu

- 72 239 řádků, všechna ID unikátní a od 1 do 72 239.
- 72 095 časově unikátních pozorování jedné stanice `IMOST71`.
- 144 dvojic duplicit: všechny sloupce kromě ID a času vložení jsou stejné.
- Časové pokrytí UTC: 2025-09-18 15:09:52 → 2026-10-22 19:35:04.
- 334 dní s alespoň jedním pozorováním z 400 zahrnutých lokálních dní.
- UTC vs epoch: 0 rozdílů; Europe/Prague vs uložený lokální čas: 0 rozdílů.
  Uloženy jsou skutečné offsety +01:00 i +02:00, ale DATETIME sám zónu nenese.
- Medián rozestupu unikátních pozorování je 300 s, 95. percentil 468 s;
  2 605 intervalů je delších než 900 s. Jde o popis exportu, ne o dokázaný
  počet incidentů nebo konfiguraci cronu.
- QC: `1` v 71 849 řádcích, `0` v 381, `-1` v 9. Význam těchto kódů není
  ve zdroji vysvětlen.
- Ve 192 řádcích chybí teplota a další senzorové metriky. Všechny mají QC=1.
  Tlak je vyplněn i v těchto řádcích.
- `software_type` a `realtime_frequency` jsou NULL ve všech řádcích.
- Výška má dvě hodnoty: 77 m v 5 528 řádcích a 331 m v 66 711; změna je
  mezi ID 5528 a 5529, ostatní lokální/souřadnicová metadata zůstávají stejná.
- Žádný platný teplotní vzorek nemá nenulovou desetinnou část.
- `precip_total` má 99 poklesů mezi různými lokálními dny a žádný uvnitř
  téhož dne (po odstranění časových duplicit pro účel profilování).

### Rozsahy uložených hodnot, nikoli kvalifikované klimatické rekordy

| Veličina | Minimum | Maximum |
|---|---:|---:|
| Teplota | −13 | 39 |
| Vlhkost | 16 | 99 |
| Tlak | 964,44 | 1 016,59 |
| Rychlost větru | 0 | 32 |
| Náraz větru | 0 | 38 |
| UV | 0 | 10,2 |
| Radiace | 0 | 1 081,9 |
| Srážková intenzita | 0 | 24 |
| Srážkový čítač | 0 | 31,22 |

Jednotky a fyzikální interpretaci zejména větru, srážek a tlaku je nutné
spojit s poskytovatelským kontraktem. Samotný DECIMAL v DB jednotku neurčuje.
Hodnoty jsou prostý profil všech nenulových dat, bez filtrování QC a bez
automatického označení rekordů.

Tento dokument zachycuje existující model beze změn. Navrhované změny a
metodika agregací jsou odděleny v [hlavním návrhu](README.md).
