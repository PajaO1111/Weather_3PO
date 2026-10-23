# Existující struktura projektu

Projekt je malá **fullstack PHP aplikace bez viditelného frameworku**.
HTML, SQL i JavaScript jsou převážně v jednom souboru. Nejsou zde aplikační
třídy, servisní/repository vrstvy, Composer manifest ani frontendový build.

## Pozorované vstupní body

| Soubor | Vstup a odpovědnost |
|---|---|
| [index.php](../../index.php) | Přes `require_once` načítá nedodaný `loader/router.php`. |
| [history5.php](../../history5.php) | Parametr `limit` 1–500; 12 polí z DB, osm kartézských grafů a jeden polární, HTML tabulka a CSV. |
| [history.php](../../history.php) | Starší historie, až 8 500 řádků, 25 polí pod českými aliasy, tři grafy a CSV. |
| [fetch_weather.php](../../fetch_weather.php) | Stažení aktuálního WU pozorování a INSERT bez kontroly duplicity. Způsob skutečného spouštění není dodán. |
| [fetch_weather2.php](../../fetch_weather2.php) | Stejný ingest s `COUNT` před INSERTem. Způsob skutečného spouštění není dodán. |
| [test_rings.php](../../test_rings.php) | Samostatný grafický prototyp s pevnými umělými daty. |
| [.php-preview-router.php](../../.php-preview-router.php) | Pomocný preview router: OPTIONS, echo POST, statické soubory a include PHP. Doklad lokálního náhledu, ne doložený produkční router. |

Celkem sedm PHP souborů v této kopii: jeden bootstrap, dvě historie, dva
ingesty, jeden prototyp a jeden preview helper. Z toho nelze odvodit celkový
rozsah nasazeného webu, protože index odkazuje na další nedodaný soubor.

## Sdílené závislosti a stav

- Obě historie a oba ingest skripty načítají `../config.php`; jeho obsah
  není v pracovním prostoru.
- Sdílený perzistentní stav je
  [pws_observations](../../pws_observations.sql), nikoli session nebo cache.
- Historie odkazují na nedodané CSS/JS site-shell, manifest a obrázky.
- Nejsou zde definice cron úloh, explicitní transakce, správce migrací,
  aplikační logovací vrstva ani testovací runner.
- Existující [test_rings.php](../../test_rings.php) není automatický test.

## Funkční oblasti

Příjem pozorování z Weather Underground → ukládání surových dat → omezený
výběr posledních řádků → grafy/tabulka/CSV. Analytické výpočty, agregace,
wind rose, API, alarmy a uživatelská témata z požadovaného portálu zatím v
analyzované kopii nejsou implementovány.

Navrhované oddělení vrstev a souborů je v [hlavním návrhu](README.md);
tento inventář popisuje pouze existující implementaci.
