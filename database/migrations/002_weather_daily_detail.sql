-- Rozsireni dennich agregaci potrebne pro presne zpetne sestaveni denniho API.
-- Spustit jednou po 001_weather_aggregates.sql; tabulky pws_observations se nemeni.

ALTER TABLE weather_daily
    ADD COLUMN dew_point_avg DECIMAL(5,2) NULL AFTER tavg,
    ADD COLUMN precip_rate_max DECIMAL(7,2) NULL AFTER rain_sum,
    ADD COLUMN solar_avg DECIMAL(9,2) NULL AFTER uv_max,
    ADD COLUMN solar_max DECIMAL(9,2) NULL AFTER solar_avg,
    ADD COLUMN hours_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER sample_count,
    ADD COLUMN dew_point_sample_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER temp_sample_count,
    ADD COLUMN humidity_sample_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER dew_point_sample_count,
    ADD COLUMN pressure_sample_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER humidity_sample_count,
    ADD COLUMN solar_sample_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER wind_sample_count;
