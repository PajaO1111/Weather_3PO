-- Snapshot of published ČHMÚ phenological observations.
-- The original meteorological tables are not modified.

CREATE TABLE IF NOT EXISTS phenology_stations (
    station_code VARCHAR(16) NOT NULL,
    station_name VARCHAR(160) NOT NULL,
    elevation_m SMALLINT NULL,
    latitude DECIMAL(8,5) NULL,
    longitude DECIMAL(8,5) NULL,
    PRIMARY KEY (station_code),
    KEY ix_phenology_station_name (station_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phenology_species (
    species_code VARCHAR(80) NOT NULL,
    species_name VARCHAR(160) NOT NULL,
    category VARCHAR(20) NOT NULL,
    PRIMARY KEY (species_code),
    KEY ix_phenology_species_category (category, species_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phenology_phases (
    phase_code VARCHAR(10) NOT NULL,
    phase_name VARCHAR(120) NOT NULL,
    PRIMARY KEY (phase_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phenology_station_species (
    station_code VARCHAR(16) NOT NULL,
    species_code VARCHAR(80) NOT NULL,
    declared_start_year SMALLINT UNSIGNED NOT NULL,
    declared_end_year SMALLINT UNSIGNED NOT NULL,
    declared_periods VARCHAR(100) NOT NULL,
    PRIMARY KEY (station_code, species_code),
    KEY ix_phenology_station_species_species (species_code, station_code),
    CONSTRAINT fk_pheno_station_species_station
        FOREIGN KEY (station_code) REFERENCES phenology_stations (station_code)
        ON DELETE CASCADE,
    CONSTRAINT fk_pheno_station_species_species
        FOREIGN KEY (species_code) REFERENCES phenology_species (species_code)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phenology_observations (
    station_code VARCHAR(16) NOT NULL,
    species_code VARCHAR(80) NOT NULL,
    phase_code VARCHAR(10) NOT NULL,
    year_num SMALLINT UNSIGNED NOT NULL,
    day_of_year SMALLINT UNSIGNED NULL,
    status ENUM('observed', 'not_occurred', 'conflict') NOT NULL,
    source_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    source_values VARCHAR(255) NOT NULL,
    PRIMARY KEY (station_code, species_code, phase_code, year_num),
    KEY ix_phenology_species_phase_year (species_code, phase_code, year_num, station_code),
    CONSTRAINT fk_pheno_observation_station_species
        FOREIGN KEY (station_code, species_code)
        REFERENCES phenology_station_species (station_code, species_code)
        ON DELETE CASCADE,
    CONSTRAINT fk_pheno_observation_phase
        FOREIGN KEY (phase_code) REFERENCES phenology_phases (phase_code)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phenology_import_files (
    source_path VARCHAR(255) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    row_count INT UNSIGNED NOT NULL,
    imported_at DATETIME NOT NULL,
    PRIMARY KEY (source_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
