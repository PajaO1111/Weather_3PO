<?php
/**
 * Testovací data: SQLite databáze v paměti se stejnými sloupci,
 * jaké aplikace čte z původní tabulky pws_observations.
 */
declare(strict_types=1);

final class Fixtures
{
    /**
     * Jeden řádek měření (hodnoty odpovídají reálným datům stanice).
     *
     * @param string $time UTC čas ve formátu Y-m-d H:i:s
     * @return array<string, mixed>
     */
    public static function row(int $id, string $time, ?float $temperature = 18.0, string $station = 'TEST'): array
    {
        $local = (new DateTimeImmutable($time, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Prague'));
        return [
            'id' => $id, 'station_id' => $station, 'obs_time_utc' => $time,
            'obs_time_local' => $local->format('Y-m-d H:i:s'),
            'neighborhood' => 'Most', 'lat' => 50.503, 'lon' => 13.636, 'elevation' => 331,
            'temp_c' => $temperature, 'heat_index' => $temperature, 'wind_chill' => $temperature,
            'dew_point' => 9.0, 'humidity' => 62, 'pressure' => 1005.08,
            'winddir' => 270, 'wind_speed' => 7.0, 'wind_gust' => 12.0,
            'uv' => 0.7, 'solar_radiation' => 201.3,
            'precip_rate' => 0.0, 'precip_total' => 1.2, 'qc_status' => 1,
        ];
    }

    /** Prázdná tabulka pws_observations v SQLite. */
    public static function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE pws_observations (
            id INTEGER PRIMARY KEY, station_id TEXT NOT NULL, obs_time_utc TEXT NOT NULL,
            obs_time_local TEXT NOT NULL, neighborhood TEXT, lat NUMERIC, lon NUMERIC, elevation INTEGER,
            temp_c NUMERIC, heat_index NUMERIC, wind_chill NUMERIC, dew_point NUMERIC,
            humidity NUMERIC, pressure NUMERIC, winddir NUMERIC, wind_speed NUMERIC,
            wind_gust NUMERIC, uv NUMERIC, solar_radiation NUMERIC,
            precip_rate NUMERIC, precip_total NUMERIC, qc_status INTEGER)');
        return $pdo;
    }

    /** @param array<string, mixed> $row */
    public static function insert(PDO $pdo, array $row): void
    {
        $columns = array_keys($row);
        $statement = $pdo->prepare('INSERT INTO pws_observations (' . implode(',', $columns) . ') VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')');
        $statement->execute(array_values($row));
    }

    /**
     * Data pro prohlížečový test: 26 hodin měření po 5 minutách končících „teď“,
     * s denním průběhem teploty, výpadkem 1 h a jedním řádkem bez hodnot.
     */
    public static function browserDatabase(string $station = 'TEST'): PDO
    {
        $pdo = self::database();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = $now->setTime((int) $now->format('H'), intdiv((int) $now->format('i'), 5) * 5);
        $count = 26 * 12;
        for ($i = 0; $i < $count; $i++) {
            $time = $now->modify('-' . (($count - 1 - $i) * 5) . ' minutes');
            if ($i > 100 && $i <= 112) {
                continue; // výpadek stanice
            }
            $hour = (int) $time->setTimezone(new DateTimeZone('Europe/Prague'))->format('G');
            $temperature = round(10 + 6 * sin(($hour - 9) / 24 * 2 * M_PI));
            $row = self::row($i + 1, $time->format('Y-m-d H:i:s'), $i === 200 ? null : $temperature, $station);
            $row['pressure'] = round(1012 - $i * 0.01, 2);
            $row['winddir'] = ($i * 7) % 360;
            $row['wind_speed'] = 5 + ($i % 9);
            $row['wind_gust'] = 9 + ($i % 13);
            $row['precip_total'] = $i > 250 ? round(($i - 250) * 0.1, 1) : 0.0;
            $row['precip_rate'] = $i > 250 ? 1.2 : 0.0;
            self::insert($pdo, $row);
        }
        return $pdo;
    }
}
