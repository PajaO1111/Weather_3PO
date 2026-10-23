-- Additive aggregate tables for OuBrix Weather 2.0.
-- This migration intentionally does not alter pws_observations: its physical
-- schema, indexes, duplicate state, and MariaDB version must be audited first.
-- local_date is the station-local calendar date (configured as Europe/Prague).

CREATE TABLE IF NOT EXISTS weather_daily (
    station_id       VARCHAR(64) NOT NULL,
    local_date       DATE NOT NULL,
    tmin             DECIMAL(5,2) NULL,
    tmax             DECIMAL(5,2) NULL,
    tavg             DECIMAL(5,2) NULL,
    humidity_min     DECIMAL(5,2) NULL,
    humidity_max     DECIMAL(5,2) NULL,
    humidity_avg     DECIMAL(5,2) NULL,
    pressure_min     DECIMAL(7,2) NULL,
    pressure_max     DECIMAL(7,2) NULL,
    pressure_avg     DECIMAL(7,2) NULL,
    wind_avg         DECIMAL(7,2) NULL,
    wind_max         DECIMAL(7,2) NULL,
    gust_max         DECIMAL(7,2) NULL,
    rain_sum         DECIMAL(9,2) NULL,
    uv_max           DECIMAL(5,2) NULL,
    radiation_max    DECIMAL(9,2) NULL,
    sample_count     INT UNSIGNED NOT NULL DEFAULT 0,
    temp_sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    wind_sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    calculated_at    DATETIME NOT NULL,
    PRIMARY KEY (station_id, local_date),
    KEY ix_weather_daily_date (local_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weather_monthly (
    station_id       VARCHAR(64) NOT NULL,
    year_num         SMALLINT UNSIGNED NOT NULL,
    month_num        TINYINT UNSIGNED NOT NULL,
    tmin             DECIMAL(5,2) NULL,
    tmax             DECIMAL(5,2) NULL,
    tavg             DECIMAL(5,2) NULL,
    rain_sum         DECIMAL(10,2) NULL,
    wind_avg         DECIMAL(7,2) NULL,
    wind_max         DECIMAL(7,2) NULL,
    sample_count     INT UNSIGNED NOT NULL DEFAULT 0,
    temp_sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    wind_sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    calculated_at    DATETIME NOT NULL,
    PRIMARY KEY (station_id, year_num, month_num),
    KEY ix_weather_monthly_period (year_num, month_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weather_yearly (
    station_id       VARCHAR(64) NOT NULL,
    year_num         SMALLINT UNSIGNED NOT NULL,
    tmin             DECIMAL(5,2) NULL,
    tmax             DECIMAL(5,2) NULL,
    tavg             DECIMAL(5,2) NULL,
    rain_sum         DECIMAL(11,2) NULL,
    wind_max         DECIMAL(7,2) NULL,
    sample_count     INT UNSIGNED NOT NULL DEFAULT 0,
    temp_sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    wind_sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    calculated_at    DATETIME NOT NULL,
    PRIMARY KEY (station_id, year_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
