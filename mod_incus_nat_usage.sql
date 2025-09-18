-- phpMyAdmin SQL Dump
-- version 5.0.4
-- https://www.phpmyadmin.net/
--
-- 主机： localhost
-- 生成日期： 2025-09-18 22:41:08
-- 服务器版本： 5.7.44-log
-- PHP 版本： 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `whmcs`
--

-- --------------------------------------------------------

--
-- 表的结构 `mod_incus_nat_usage`
--

CREATE TABLE `mod_incus_nat_usage` (
  `id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `container_name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `last_update` int(11) NOT NULL DEFAULT '0',
  `last_bytes_in` bigint(20) NOT NULL DEFAULT '0',
  `last_bytes_out` bigint(20) NOT NULL DEFAULT '0',
  `usage_bytes_in` bigint(20) NOT NULL DEFAULT '0',
  `usage_bytes_out` bigint(20) NOT NULL DEFAULT '0',
  `usage_reset_date` date DEFAULT NULL,
  `is_limited` tinyint(1) NOT NULL DEFAULT '0',
  `history` text COLLATE utf8_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


--
-- 转储表的索引
--

--
-- 表的索引 `mod_incus_nat_usage`
--
ALTER TABLE `mod_incus_nat_usage`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `service_id` (`service_id`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `mod_incus_nat_usage`
--
ALTER TABLE `mod_incus_nat_usage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=120;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
