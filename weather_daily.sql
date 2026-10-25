-- phpMyAdmin SQL Dump
-- version 3.5.8.2
-- http://www.phpmyadmin.net
--
-- Počítač: md391.wedos.net:3306
-- Vygenerováno: Ned 25. říj 2026, 11:19
-- Verze serveru: 10.11.18-MariaDB-log
-- Verze PHP: 5.4.23

SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

--
-- Databáze: `d376849_weather`
--

-- --------------------------------------------------------

--
-- Struktura tabulky `weather_daily`
--

CREATE TABLE IF NOT EXISTS `weather_daily` (
  `station_id` varchar(64) NOT NULL,
  `local_date` date NOT NULL,
  `tmin` decimal(5,2) DEFAULT NULL,
  `tmax` decimal(5,2) DEFAULT NULL,
  `tavg` decimal(5,2) DEFAULT NULL,
  `dew_point_avg` decimal(5,2) DEFAULT NULL,
  `humidity_min` decimal(5,2) DEFAULT NULL,
  `humidity_max` decimal(5,2) DEFAULT NULL,
  `humidity_avg` decimal(5,2) DEFAULT NULL,
  `pressure_min` decimal(7,2) DEFAULT NULL,
  `pressure_max` decimal(7,2) DEFAULT NULL,
  `pressure_avg` decimal(7,2) DEFAULT NULL,
  `wind_avg` decimal(7,2) DEFAULT NULL,
  `wind_max` decimal(7,2) DEFAULT NULL,
  `gust_max` decimal(7,2) DEFAULT NULL,
  `rain_sum` decimal(9,2) DEFAULT NULL,
  `precip_rate_max` decimal(7,2) DEFAULT NULL,
  `uv_max` decimal(5,2) DEFAULT NULL,
  `solar_avg` decimal(9,2) DEFAULT NULL,
  `solar_max` decimal(9,2) DEFAULT NULL,
  `radiation_max` decimal(9,2) DEFAULT NULL,
  `sample_count` int(10) unsigned NOT NULL DEFAULT 0,
  `hours_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `temp_sample_count` int(10) unsigned NOT NULL DEFAULT 0,
  `dew_point_sample_count` int(10) unsigned NOT NULL DEFAULT 0,
  `humidity_sample_count` int(10) unsigned NOT NULL DEFAULT 0,
  `pressure_sample_count` int(10) unsigned NOT NULL DEFAULT 0,
  `wind_sample_count` int(10) unsigned NOT NULL DEFAULT 0,
  `solar_sample_count` int(10) unsigned NOT NULL DEFAULT 0,
  `calculated_at` datetime NOT NULL,
  PRIMARY KEY (`station_id`,`local_date`),
  KEY `ix_weather_daily_date` (`local_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
