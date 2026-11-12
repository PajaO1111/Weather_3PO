-- Run before deploying station-upload.php and the new fetch_weather2.php.
-- All three tables, including the existing pws_observations, must use InnoDB.
-- Original observations and their schema are left untouched.

CREATE TABLE IF NOT EXISTS weather_ingest_state (
    station_id VARCHAR(16) NOT NULL,
    last_direct_received_utc DATETIME NULL,
    last_direct_obs_utc DATETIME NULL,
    PRIMARY KEY (station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS weather_ingest_slots (
    station_id VARCHAR(16) NOT NULL,
    slot_utc DATETIME NOT NULL,
    observation_id INT UNSIGNED NOT NULL,
    source VARCHAR(8) NOT NULL,
    PRIMARY KEY (station_id, slot_utc),
    UNIQUE KEY uq_weather_ingest_observation (observation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
