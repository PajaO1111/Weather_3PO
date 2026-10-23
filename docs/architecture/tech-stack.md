# Existující technologický inventář

| Vrstva | Nalezená technologie/verze | Zdroj a omezení |
|---|---|---|
| Aplikace | PHP, PDO MySQL, `file_get_contents`, `json_decode` | PHP zdroje; bez Composer manifestu a bez deklarované minimální verze. |
| DB export | MariaDB 10.11.18-MariaDB-log | Hlavička dodaného SQL; není to živá kontrola serveru. |
| Tabulka | InnoDB, utf8mb4_general_ci | DDL v dodaném exportu. |
| Exportní nástroj | phpMyAdmin 3.5.8.2 | Hlavička exportu. |
| PHP u exportu | 5.4.23 | Hlavička exportu; neprokazuje runtime cílové aplikace. |
| Lokální CLI | PHP 8.2.12, `C:\xampp\php\php.exe` | Ověřeno `php --version` v tomto prostředí. |
| Editor | Language server vrací PHP 8.5 | Nastavení editoru/analýzy, nikoli důkaz verze webového serveru. |
| CSS | Bootstrap 5.3.0, externí site-shell | CDN odkazy v obou historiích. |
| Grafy | Chart.js bez verze | CDN v historiích a prototypu; přesná nasazená verze není z kódu fixována. |
| Datumy grafů | Luxon 3.4.4 a chartjs-adapter-luxon 1.3.1 | V rozšířené historii. Adaptér je vložen před Chart.js. |
| Ikony | Font Awesome 6.3.0 | Externí skript v historiích. |
| Zdroj dat | WU `v2/pws/observations/current`, `units=m` | Oba ingest skripty. |

## Zjištěné hranice

- [index.php](../../index.php) odkazuje na nedostupný router. Nelze potvrdit
  framework, routy ani celkový layout produkční aplikace mimo dodané soubory.
- Konfigurační soubor není součástí kopie. Language server hlásí u
  [history5.php](../../history5.php) pět nedefinovaných DB konstant a u
  [fetch_weather2.php](../../fetch_weather2.php) sedm DB/WU konstant.
- Lokální CLI, editor a export nesdělují stejnou verzi PHP. PHP 8.3 je
  požadovaný cíl původního zadání, ne zde ověřený produkční stav.
- Zdroje neobsahují deklaraci Apache, Nginx, Redis, ECharts, Leaflet,
  automatických testů nebo CI. Nezaměňovat požadovaný stack za již nasazený.
- Žádný upgrade závislostí ani kontrola živých CDN knihoven zde neproběhly.
