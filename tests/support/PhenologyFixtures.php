<?php
declare(strict_types=1);

final class PhenologyFixtures
{
    public static function createSchema(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE phenology_stations (
            station_code TEXT PRIMARY KEY, station_name TEXT NOT NULL, elevation_m INTEGER NULL,
            latitude NUMERIC NULL, longitude NUMERIC NULL)');
        $pdo->exec('CREATE TABLE phenology_species (
            species_code TEXT PRIMARY KEY, species_name TEXT NOT NULL, category TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE phenology_phases (
            phase_code TEXT PRIMARY KEY, phase_name TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE phenology_station_species (
            station_code TEXT NOT NULL, species_code TEXT NOT NULL, declared_start_year INTEGER NOT NULL,
            declared_end_year INTEGER NOT NULL, declared_periods TEXT NOT NULL, PRIMARY KEY (station_code, species_code),
            FOREIGN KEY (station_code) REFERENCES phenology_stations (station_code) ON DELETE CASCADE,
            FOREIGN KEY (species_code) REFERENCES phenology_species (species_code) ON DELETE CASCADE)');
        $pdo->exec('CREATE TABLE phenology_observations (
            station_code TEXT NOT NULL, species_code TEXT NOT NULL, phase_code TEXT NOT NULL,
            year_num INTEGER NOT NULL, day_of_year INTEGER NULL, status TEXT NOT NULL,
            source_count INTEGER NOT NULL DEFAULT 1, source_values TEXT NOT NULL DEFAULT \'\',
            PRIMARY KEY (station_code, species_code, phase_code, year_num),
            FOREIGN KEY (station_code, species_code) REFERENCES phenology_station_species (station_code, species_code) ON DELETE CASCADE,
            FOREIGN KEY (phase_code) REFERENCES phenology_phases (phase_code) ON DELETE RESTRICT)');
        $pdo->exec('CREATE TABLE phenology_import_files (
            source_path TEXT PRIMARY KEY, sha256 TEXT NOT NULL, row_count INTEGER NOT NULL, imported_at TEXT NOT NULL)');
    }

    public static function seed(PDO $pdo): void
    {
        self::createSchema($pdo);
        $stations = [
            ['U1DOKS31', 'Doksany', 155, 50.46, 14.17],
            ['U1BRAN31', 'Brandov', 630, 50.63, 13.38],
            ['U1MEDE31', 'Měděnec', 830, 50.43, 13.11],
        ];
        $insertStation = $pdo->prepare('INSERT INTO phenology_stations VALUES (?, ?, ?, ?, ?)');
        foreach ($stations as $station) $insertStation->execute($station);
        $pdo->exec("INSERT INTO phenology_species VALUES ('Briza_belokora', 'Bříza bělokorá', 'wood_species')");
        $pdo->exec("INSERT INTO phenology_phases VALUES ('PK50', 'Počátek kvetení 50%')");
        $pdo->exec("INSERT INTO phenology_phases VALUES ('BT', 'Butonizace')");
        $insertStationSpecies = $pdo->prepare(
            "INSERT INTO phenology_station_species VALUES (?, ?, 2014, 2017, '2014-2017')"
        );
        foreach (array_column($stations, 0) as $code) {
            $insertStationSpecies->execute([$code, 'Briza_belokora']);
        }
        $rows = [
            ['U1DOKS31', 'Briza_belokora', 'PK50', 2014, 100, 'observed'],
            ['U1DOKS31', 'Briza_belokora', 'PK50', 2015, null, 'not_occurred'],
            ['U1DOKS31', 'Briza_belokora', 'PK50', 2016, 101, 'observed'],
            ['U1DOKS31', 'Briza_belokora', 'PK50', 2017, 102, 'observed'],
            ['U1DOKS31', 'Briza_belokora', 'BT', 2016, 90, 'observed'],
            ['U1BRAN31', 'Briza_belokora', 'PK50', 2014, 104, 'observed'],
            ['U1BRAN31', 'Briza_belokora', 'PK50', 2015, null, 'not_occurred'],
            ['U1BRAN31', 'Briza_belokora', 'PK50', 2016, 106, 'observed'],
            ['U1MEDE31', 'Briza_belokora', 'PK50', 2014, 110, 'observed'],
            ['U1MEDE31', 'Briza_belokora', 'PK50', 2016, 115, 'observed'],
        ];
        $insert = $pdo->prepare('INSERT INTO phenology_observations
            (station_code, species_code, phase_code, year_num, day_of_year, status) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($rows as $row) $insert->execute($row);
        $pdo->exec("INSERT INTO phenology_import_files VALUES ('wood_species/data/Briza_belokora.csv', 'hash', 10, '2026-10-29 12:00:00')");
    }
}
