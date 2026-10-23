-- phpMyAdmin SQL Dump
-- version 3.5.8.2
-- http://www.phpmyadmin.net
--
-- Počítač: md391.wedos.net:3306
-- Vygenerováno: Pát 23. říj 2026, 17:34
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
-- Struktura tabulky `pws_observations`
--

CREATE TABLE IF NOT EXISTS `pws_observations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `station_id` varchar(16) NOT NULL,
  `obs_time_utc` datetime NOT NULL,
  `obs_time_local` datetime NOT NULL,
  `neighborhood` varchar(64) DEFAULT NULL,
  `software_type` varchar(64) DEFAULT NULL,
  `country` char(2) DEFAULT NULL,
  `solar_radiation` decimal(7,2) DEFAULT NULL,
  `lon` decimal(9,6) DEFAULT NULL,
  `lat` decimal(9,6) DEFAULT NULL,
  `realtime_frequency` int(11) DEFAULT NULL,
  `epoch` int(11) DEFAULT NULL,
  `uv` decimal(4,2) DEFAULT NULL,
  `winddir` smallint(6) DEFAULT NULL,
  `humidity` tinyint(4) DEFAULT NULL,
  `qc_status` tinyint(4) DEFAULT NULL,
  `temp_c` decimal(5,2) DEFAULT NULL,
  `heat_index` decimal(5,2) DEFAULT NULL,
  `dew_point` decimal(5,2) DEFAULT NULL,
  `wind_chill` decimal(5,2) DEFAULT NULL,
  `wind_speed` decimal(5,2) DEFAULT NULL,
  `wind_gust` decimal(5,2) DEFAULT NULL,
  `pressure` decimal(7,2) DEFAULT NULL,
  `precip_rate` decimal(7,2) DEFAULT NULL,
  `precip_total` decimal(7,2) DEFAULT NULL,
  `elevation` smallint(6) DEFAULT NULL,
  `inserted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=72279 ;

--
-- Vypisuji data pro tabulku `pws_observations`
--

INSERT INTO `pws_observations` (`id`, `station_id`, `obs_time_utc`, `obs_time_local`, `neighborhood`, `software_type`, `country`, `solar_radiation`, `lon`, `lat`, `realtime_frequency`, `epoch`, `uv`, `winddir`, `humidity`, `qc_status`, `temp_c`, `heat_index`, `dew_point`, `wind_chill`, `wind_speed`, `wind_gust`, `pressure`, `precip_rate`, `precip_total`, `elevation`, `inserted_at`) VALUES
(72271, 'IMOST71', '2026-10-23 13:30:04', '2026-10-23 15:30:04', 'Most', NULL, 'CZ', 64.90, 13.643094, 50.506896, NULL, 1792762204, 0.50, 49, 78, 1, 10.00, 10.00, 6.00, 10.00, 0.00, 0.00, 997.29, 0.00, 0.00, 331, '2026-10-23 13:30:18'),
(72272, 'IMOST71', '2026-10-23 14:35:04', '2026-10-23 16:35:04', 'Most', NULL, 'CZ', 22.00, 13.643094, 50.506896, NULL, 1792766104, 0.00, 210, 79, 1, 10.00, 10.00, 6.00, 10.00, 0.00, 0.00, 996.95, 0.00, 0.00, 331, '2026-10-23 14:35:16'),
(72273, 'IMOST71', '2026-10-23 15:05:04', '2026-10-23 17:05:04', 'Most', NULL, 'CZ', 10.90, 13.643094, 50.506896, NULL, 1792767904, 0.00, 209, 80, 1, 10.00, 10.00, 6.00, 10.00, 0.00, 0.00, 996.61, 0.00, 0.00, 331, '2026-10-23 15:05:14'),
(72274, 'IMOST71', '2026-10-23 15:11:28', '2026-10-23 17:11:28', 'Most', NULL, 'CZ', 7.60, 13.643094, 50.506896, NULL, 1792768288, 0.00, 209, 81, 1, 10.00, 10.00, 7.00, 10.00, 0.00, 0.00, 996.61, 0.00, 0.00, 331, '2026-10-23 15:11:36'),
(72275, 'IMOST71', '2026-10-23 15:18:28', '2026-10-23 17:18:28', 'Most', NULL, 'CZ', 7.50, 13.643094, 50.506896, NULL, 1792768708, 0.00, 209, 81, 1, 10.00, 10.00, 7.00, 10.00, 0.00, 0.00, 996.61, 0.00, 0.00, 331, '2026-10-23 15:18:31'),
(72276, 'IMOST71', '2026-10-23 15:19:04', '2026-10-23 17:19:04', 'Most', NULL, 'CZ', 7.50, 13.643094, 50.506896, NULL, 1792768744, 0.00, 209, 81, 1, 10.00, 10.00, 7.00, 10.00, 0.00, 0.00, 996.61, 0.00, 0.00, 331, '2026-10-23 15:19:18'),
(72277, 'IMOST71', '2026-10-23 15:25:17', '2026-10-23 17:25:17', 'Most', NULL, 'CZ', 8.40, 13.643094, 50.506896, NULL, 1792769117, 0.00, 209, 83, 1, 10.00, 10.00, 7.00, 10.00, 0.00, 0.00, 996.28, 0.00, 0.00, 331, '2026-10-23 15:25:20'),
(72278, 'IMOST71', '2026-10-23 15:30:04', '2026-10-23 17:30:04', 'Most', NULL, 'CZ', 11.30, 13.643094, 50.506896, NULL, 1792769404, 0.00, 209, 83, 1, 9.00, 9.00, 7.00, 9.00, 0.00, 0.00, 996.28, 0.00, 0.00, 331, '2026-10-23 15:30:18');

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
