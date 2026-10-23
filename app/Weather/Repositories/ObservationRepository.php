<?php
/**
 * Čtení měření z původní tabulky pws_observations.
 *
 * Jediné místo v aplikaci, kde je SQL. Všechny dotazy jsou pouze SELECT
 * s parametry (prepared statements) a filtrují stanici podle WU_STATION_ID.
 *
 * Výkon: dotazy filtrují (station_id, obs_time_utc) – na produkci je index
 * idx_station_time (přidán 10/2026), takže se čte jen požadovaný rozsah.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Repositories;

use InvalidArgumentException;
use PDO;

final class ObservationRepository
{
    /** Sloupce s naměřenými hodnotami, které aplikace používá. */
    public const METRICS = [
        'temp_c', 'heat_index', 'wind_chill', 'dew_point', 'humidity', 'pressure',
        'winddir', 'wind_speed', 'wind_gust', 'uv', 'solar_radiation',
        'precip_rate', 'precip_total',
    ];

    /** Sloupce, pro které lze hledat denní extrém (whitelist proti SQL injection). */
    private const EXTREME_COLUMNS = ['temp_c', 'wind_gust', 'humidity', 'pressure', 'precip_rate', 'solar_radiation', 'uv'];

    /**
     * Hodinové agregace (hourly()): výstupní název => sloupec v DB.
     * Průměry se vrací jako součet + počet (<název>_sum, <název>_n), aby šlo
     * hodiny správně sloučit do dnů (vážený průměr podle počtu měření).
     */
    public const HOURLY_AVERAGES = [
        'temp_avg' => 'temp_c', 'dew_point_avg' => 'dew_point', 'humidity_avg' => 'humidity',
        'pressure_avg' => 'pressure', 'wind_avg' => 'wind_speed', 'solar_avg' => 'solar_radiation',
    ];
    public const HOURLY_MINIMA = ['temp_min' => 'temp_c', 'humidity_min' => 'humidity', 'pressure_min' => 'pressure'];
    public const HOURLY_MAXIMA = [
        'temp_max' => 'temp_c', 'humidity_max' => 'humidity', 'pressure_max' => 'pressure',
        'wind_max' => 'wind_speed', 'gust_max' => 'wind_gust', 'precip_rate_max' => 'precip_rate',
        'uv_max' => 'uv', 'solar_max' => 'solar_radiation',
        // Denní čítač srážek stanice – stav na konci hodiny (nuluje se o místní půlnoci).
        'precip_counter' => 'precip_total',
    ];

    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Poslední záznam stanice včetně popisných údajů (oblast, poloha, výška).
     *
     * @return array<string, mixed>|null null, pokud stanice nemá žádná data
     */
    public function latest(string $station): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT station_id, obs_time_utc, obs_time_local, neighborhood, lat, lon, elevation, '
            . implode(', ', self::METRICS) . ', qc_status
             FROM pws_observations
             WHERE station_id = :station
             ORDER BY obs_time_utc DESC, id DESC
             LIMIT 1'
        );
        $statement->bindValue(':station', $station, PDO::PARAM_STR);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Čas prvního a posledního měření stanice (UTC) – rozsah dostupných let.
     * Díky indexu (station_id, obs_time_utc) jde jen o dva skoky v indexu.
     *
     * @return array{first: string, last: string}|null null, pokud stanice nemá žádná data
     */
    public function bounds(string $station): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT MIN(obs_time_utc) AS first_time, MAX(obs_time_utc) AS last_time
             FROM pws_observations
             WHERE station_id = :station'
        );
        $statement->bindValue(':station', $station, PDO::PARAM_STR);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['first_time'] === null) {
            return null;
        }
        return ['first' => (string) $row['first_time'], 'last' => (string) $row['last_time']];
    }

    /**
     * Posledních N záznamů seřazených od nejstaršího (pro graf).
     *
     * @return list<array<string, mixed>>
     */
    public function recent(string $station, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Limit musi byt 1-1000.');
        }
        $statement = $this->pdo->prepare(
            'SELECT obs_time_utc, ' . implode(', ', self::METRICS) . ', qc_status
             FROM pws_observations
             WHERE station_id = :station
             ORDER BY obs_time_utc DESC, id DESC
             LIMIT :row_limit'
        );
        $statement->bindValue(':station', $station, PDO::PARAM_STR);
        $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_reverse($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Měření v časovém okně [od, do) v UTC seřazená od nejstaršího (pro graf).
     *
     * @param int $maxRows pojistka – vrátí nejvýš tolik NEJNOVĚJŠÍCH řádků
     * @return list<array<string, mixed>>
     */
    public function between(string $station, string $fromUtc, string $toUtc, int $maxRows): array
    {
        if ($maxRows < 1 || $maxRows > 10000) {
            throw new InvalidArgumentException('maxRows musi byt 1-10000.');
        }
        $statement = $this->pdo->prepare(
            'SELECT obs_time_utc, ' . implode(', ', self::METRICS) . ', qc_status
             FROM pws_observations
             WHERE station_id = :station AND obs_time_utc >= :from_utc AND obs_time_utc < :to_utc
             ORDER BY obs_time_utc DESC, id DESC
             LIMIT :row_limit'
        );
        $statement->bindValue(':station', $station, PDO::PARAM_STR);
        $statement->bindValue(':from_utc', $fromUtc, PDO::PARAM_STR);
        $statement->bindValue(':to_utc', $toUtc, PDO::PARAM_STR);
        $statement->bindValue(':row_limit', $maxRows, PDO::PARAM_INT);
        $statement->execute();
        return array_reverse($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Hodinové agregace v intervalu [od, do) v UTC, seřazené od nejstarší hodiny.
     *
     * Seskupuje se podle UTC hodiny („2026-10-23 15“) – místní půlnoc v Praze
     * je vždy na celé UTC hodině, takže z hodin lze přesně složit místní dny
     * i při přechodu na letní/zimní čas. Výpočet běží v DB přes index
     * (station_id, obs_time_utc); rok dat = ~8 800 řádků výsledku.
     *
     * @return list<array<string, mixed>> bucket, samples, <avg>_sum, <avg>_n, minima, maxima
     */
    public function hourly(string $station, string $fromUtc, string $toUtc): array
    {
        $columns = ['SUBSTR(obs_time_utc, 1, 13) AS bucket', 'COUNT(DISTINCT obs_time_utc) AS samples'];
        foreach (self::HOURLY_AVERAGES as $name => $column) {
            $columns[] = "SUM($column) AS {$name}_sum";
            $columns[] = "COUNT($column) AS {$name}_n";
        }
        foreach (self::HOURLY_MINIMA as $name => $column) {
            $columns[] = "MIN($column) AS $name";
        }
        foreach (self::HOURLY_MAXIMA as $name => $column) {
            $columns[] = "MAX($column) AS $name";
        }
        $statement = $this->pdo->prepare(
            'SELECT ' . implode(', ', $columns) . '
             FROM pws_observations
             WHERE station_id = :station AND obs_time_utc >= :from_utc AND obs_time_utc < :to_utc
             GROUP BY bucket
             ORDER BY bucket'
        );
        $statement->bindValue(':station', $station, PDO::PARAM_STR);
        $statement->bindValue(':from_utc', $fromUtc, PDO::PARAM_STR);
        $statement->bindValue(':to_utc', $toUtc, PDO::PARAM_STR);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Souhrnné hodnoty za časový interval [od, do) v UTC.
     *
     * @return array<string, mixed> počet měření a min/max vybraných veličin
     */
    public function summary(string $station, string $fromUtc, string $toUtc): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS samples,
                    MIN(obs_time_utc) AS first_time, MAX(obs_time_utc) AS last_time,
                    MIN(temp_c) AS temp_min, MAX(temp_c) AS temp_max, AVG(temp_c) AS temp_avg, COUNT(temp_c) AS temp_n,
                    MIN(humidity) AS humidity_min, MAX(humidity) AS humidity_max,
                    MIN(pressure) AS pressure_min, MAX(pressure) AS pressure_max,
                    MAX(wind_speed) AS wind_max, MAX(wind_gust) AS gust_max,
                    MAX(precip_rate) AS precip_rate_max, MAX(uv) AS uv_max,
                    MAX(solar_radiation) AS solar_max
             FROM pws_observations
             WHERE station_id = :station AND obs_time_utc >= :from_utc AND obs_time_utc < :to_utc'
        );
        $statement->bindValue(':station', $station, PDO::PARAM_STR);
        $statement->bindValue(':from_utc', $fromUtc, PDO::PARAM_STR);
        $statement->bindValue(':to_utc', $toUtc, PDO::PARAM_STR);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? ['samples' => 0] : $row;
    }

    /**
     * Čas a hodnota extrému veličiny v intervalu [od, do) v UTC.
     *
     * @param string $column    název sloupce z EXTREME_COLUMNS
     * @param bool   $maximum   true = maximum, false = minimum
     * @return array{value: mixed, time: string}|null
     */
    public function extreme(string $station, string $column, bool $maximum, string $fromUtc, string $toUtc): ?array
    {
        if (!in_array($column, self::EXTREME_COLUMNS, true)) {
            throw new InvalidArgumentException('Nepodporovany sloupec.');
        }
        $direction = $maximum ? 'DESC' : 'ASC';
        // Při shodě hodnot vracíme první výskyt v daném dni.
        $statement = $this->pdo->prepare(
            "SELECT $column AS value, obs_time_utc AS time
             FROM pws_observations
             WHERE station_id = :station AND obs_time_utc >= :from_utc AND obs_time_utc < :to_utc
               AND $column IS NOT NULL
             ORDER BY $column $direction, obs_time_utc ASC
             LIMIT 1"
        );
        $statement->bindValue(':station', $station, PDO::PARAM_STR);
        $statement->bindValue(':from_utc', $fromUtc, PDO::PARAM_STR);
        $statement->bindValue(':to_utc', $toUtc, PDO::PARAM_STR);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Poslední platný tlak naměřený nejpozději v daném čase (UTC).
     *
     * @return array{pressure: mixed, obs_time_utc: string}|null
     */
    public function pressureAtOrBefore(string $station, string $utc): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT pressure, obs_time_utc
             FROM pws_observations
             WHERE station_id = :station AND obs_time_utc <= :at_utc AND pressure IS NOT NULL
             ORDER BY obs_time_utc DESC
             LIMIT 1'
        );
        $statement->bindValue(':station', $station, PDO::PARAM_STR);
        $statement->bindValue(':at_utc', $utc, PDO::PARAM_STR);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
