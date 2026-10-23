-- phpMyAdmin SQL Dump
-- version 3.5.8.2
-- http://www.phpmyadmin.net
--
-- Počítač: md391.wedos.net:3306
-- Vygenerováno: Pát 23. říj 2026, 16:36
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
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=72273 ;

--
-- Vypisuji data pro tabulku `pws_observations`
--

INSERT INTO `pws_observations` (`id`, `station_id`, `obs_time_utc`, `obs_time_local`, `neighborhood`, `software_type`, `country`, `solar_radiation`, `lon`, `lat`, `realtime_frequency`, `epoch`, `uv`, `winddir`, `humidity`, `qc_status`, `temp_c`, `heat_index`, `dew_point`, `wind_chill`, `wind_speed`, `wind_gust`, `pressure`, `precip_rate`, `precip_total`, `elevation`, `inserted_at`) VALUES
(72260, 'IMOST71', '2026-10-23 07:45:15', '2026-10-23 09:45:15', 'Most', NULL, 'CZ', 116.20, 13.643094, 50.506896, NULL, 1792741515, 0.80, 291, 81, 1, 9.00, 9.00, 6.00, 9.00, 4.00, 4.00, 1001.02, 0.00, 0.00, 331, '2026-10-23 07:45:19'),
(72261, 'IMOST71', '2026-10-23 08:01:51', '2026-10-23 10:01:51', 'Most', NULL, 'CZ', 132.70, 13.643094, 50.506896, NULL, 1792742511, 1.00, 294, 82, 1, 9.00, 9.00, 6.00, 9.00, 0.00, 0.00, 1001.02, 0.00, 0.00, 331, '2026-10-23 08:02:02'),
(72262, 'IMOST71', '2026-10-23 08:50:03', '2026-10-23 10:50:03', 'Most', NULL, 'CZ', 160.50, 13.643094, 50.506896, NULL, 1792745403, 1.00, 301, 76, 1, 10.00, 10.00, 6.00, 10.00, 0.00, 0.00, 1000.68, 0.00, 0.00, 331, '2026-10-23 08:50:13'),
(72263, 'IMOST71', '2026-10-23 09:05:15', '2026-10-23 11:05:15', 'Most', NULL, 'CZ', 153.40, 13.643094, 50.506896, NULL, 1792746315, 1.00, 299, 79, 1, 10.00, 10.00, 6.00, 10.00, 0.00, 0.00, 1000.68, 0.00, 0.00, 331, '2026-10-23 09:05:20'),
(72264, 'IMOST71', '2026-10-23 09:55:15', '2026-10-23 11:55:15', 'Most', NULL, 'CZ', 117.70, 13.643094, 50.506896, NULL, 1792749315, 1.00, 299, 76, 1, 10.00, 10.00, 6.00, 10.00, 0.00, 0.00, 1000.00, 0.00, 0.00, 331, '2026-10-23 09:55:22'),
(72265, 'IMOST71', '2026-10-23 10:20:04', '2026-10-23 12:20:04', 'Most', NULL, 'CZ', 91.70, 13.643094, 50.506896, NULL, 1792750804, 0.90, 296, 76, 1, 10.00, 10.00, 6.00, 10.00, 3.00, 3.00, 999.66, 0.00, 0.00, 331, '2026-10-23 10:20:17'),
(72266, 'IMOST71', '2026-10-23 11:10:16', '2026-10-23 13:10:16', 'Most', NULL, 'CZ', 148.30, 13.643094, 50.506896, NULL, 1792753816, 0.90, 297, 77, 1, 10.00, 10.00, 6.00, 10.00, 0.00, 0.00, 998.98, 0.00, 0.00, 331, '2026-10-23 11:10:20'),
(72267, 'IMOST71', '2026-10-23 11:20:04', '2026-10-23 13:20:04', 'Most', NULL, 'CZ', 147.00, 13.643094, 50.506896, NULL, 1792754404, 1.00, 296, 76, 1, 11.00, 11.00, 7.00, 11.00, 0.00, 0.00, 998.98, 0.00, 0.00, 331, '2026-10-23 11:20:19'),
(72268, 'IMOST71', '2026-10-23 12:15:04', '2026-10-23 14:15:04', 'Most', NULL, 'CZ', 112.90, 13.643094, 50.506896, NULL, 1792757704, 1.00, 296, 77, 1, 10.00, 10.00, 7.00, 10.00, 0.00, 0.00, 998.65, 0.00, 0.00, 331, '2026-10-23 12:15:18'),
(72269, 'IMOST71', '2026-10-23 12:20:16', '2026-10-23 14:20:16', 'Most', NULL, 'CZ', 104.00, 13.643094, 50.506896, NULL, 1792758016, 0.70, 296, 77, 1, 10.00, 10.00, 7.00, 10.00, 0.00, 0.00, 997.97, 0.00, 0.00, 331, '2026-10-23 12:20:20'),
(72270, 'IMOST71', '2026-10-23 13:25:04', '2026-10-23 15:25:04', 'Most', NULL, 'CZ', 57.00, 13.643094, 50.506896, NULL, 1792761904, 0.00, 302, 80, 1, 10.00, 10.00, 7.00, 10.00, 0.00, 0.00, 997.29, 0.00, 0.00, 331, '2026-10-23 13:25:12'),
(72271, 'IMOST71', '2026-10-23 13:30:04', '2026-10-23 15:30:04', 'Most', NULL, 'CZ', 64.90, 13.643094, 50.506896, NULL, 1792762204, 0.50, 49, 78, 1, 10.00, 10.00, 6.00, 10.00, 0.00, 0.00, 997.29, 0.00, 0.00, 331, '2026-10-23 13:30:18'),
(72272, 'IMOST71', '2026-10-23 14:35:04', '2026-10-23 16:35:04', 'Most', NULL, 'CZ', 22.00, 13.643094, 50.506896, NULL, 1792766104, 0.00, 210, 79, 1, 10.00, 10.00, 6.00, 10.00, 0.00, 0.00, 996.95, 0.00, 0.00, 331, '2026-10-23 14:35:16');

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
