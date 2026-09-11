-- =====================================================================
-- Enterprise Database Schema for AIR IAQ Smart Dashboard
-- Target Database: air_quality_db
-- Compatibility: MariaDB 10.4+ / MySQL 5.7+ / MySQL 8.0+
-- Charset: utf8mb4 / utf8mb4_unicode_ci
-- Created for: ICT BRB - Site 4 ICT 401 & Enterprise IAQ Monitoring
-- =====================================================================

-- 1. Create Database if not exists
CREATE DATABASE IF NOT EXISTS `air_quality_db`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `air_quality_db`;

-- Safety Environment Settings
SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';

-- ---------------------------------------------------------------------
-- 2. Drop Views & Tables in REVERSE Dependency Order
-- (Drop child referencing tables first, then parent tables to prevent #3730)
-- ---------------------------------------------------------------------
DROP VIEW IF EXISTS `v_latest_telemetry`;
DROP VIEW IF EXISTS `v_daily_iaq_summary`;

DROP TABLE IF EXISTS `iaq_alerts`;
DROP TABLE IF EXISTS `telemetry_45m_snapshots`;
DROP TABLE IF EXISTS `telemetry_records`;
DROP TABLE IF EXISTS `system_sync_status`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `sites`;

-- ---------------------------------------------------------------------
-- Table 1: sites (Monitoring Locations / Rooms / Sensors)
-- ---------------------------------------------------------------------
CREATE TABLE `sites` (
  `site_id` VARCHAR(32) NOT NULL,
  `site_code` VARCHAR(50) NOT NULL,
  `site_name` VARCHAR(100) NOT NULL,
  `building` VARCHAR(100) NOT NULL DEFAULT 'ICT BRB',
  `floor` VARCHAR(20) NOT NULL DEFAULT '4',
  `room` VARCHAR(50) NOT NULL DEFAULT '401',
  `description` VARCHAR(255) DEFAULT 'ห้องตรวจวัดคุณภาพอากาศชั้น 4 อาคาร ICT BRB',
  `sensor_model` VARCHAR(100) DEFAULT 'Emtrontech AIIR v2',
  `mac_address` VARCHAR(32) DEFAULT NULL,
  `status` ENUM('ACTIVE', 'MAINTENANCE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`site_id`),
  UNIQUE KEY `idx_site_code` (`site_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ข้อมูลห้องและจุดติดตั้งเซ็นเซอร์ตรวจวัด';

-- ---------------------------------------------------------------------
-- Table 2: telemetry_records (High-Frequency Real-time Telemetry Data)
-- ---------------------------------------------------------------------
CREATE TABLE `telemetry_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` VARCHAR(32) NOT NULL DEFAULT '4',
  `site_name` VARCHAR(100) NOT NULL DEFAULT 'Site 4 - ICT401',
  `pm25` DECIMAL(6,2) NOT NULL DEFAULT 0.00 COMMENT 'PM2.5 (µg/m³)',
  `pm10` DECIMAL(6,2) NOT NULL DEFAULT 0.00 COMMENT 'PM10 (µg/m³)',
  `co2` DECIMAL(7,2) NOT NULL DEFAULT 0.00 COMMENT 'CO2 (ppm)',
  `temp` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Temperature (°C)',
  `humid` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Relative Humidity (%RH)',
  `evoc` DECIMAL(7,2) NOT NULL DEFAULT 0.00 COMMENT 'VOC / EVOC (ppb)',
  `rssi` VARCHAR(16) NOT NULL DEFAULT '0' COMMENT 'RSSI Signal (dBm)',
  `iaq_score` SMALLINT NOT NULL DEFAULT 0 COMMENT 'AI IAQ Score (0-100%)',
  `sensor_time` VARCHAR(50) DEFAULT NULL COMMENT 'Sensor Timestamp String',
  `server_timestamp` BIGINT NOT NULL COMMENT 'Unix Timestamp in Seconds',
  `server_time` DATETIME NOT NULL COMMENT 'Bangkok Server Time',
  `route_used` VARCHAR(64) DEFAULT 'UNKNOWN' COMMENT 'DIRECT / PROXY route used',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_telem_site_ts` (`site_id`, `server_timestamp` DESC),
  KEY `idx_telem_server_time` (`server_time`),
  KEY `idx_telem_score` (`iaq_score`),
  CONSTRAINT `fk_telem_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`site_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ข้อมูลตรวจวัดคุณภาพอากาศแบบละเอียด Real-time';

-- ---------------------------------------------------------------------
-- Table 3: telemetry_45m_snapshots (Aggregated 45-Minute Long-Term Data)
-- ---------------------------------------------------------------------
CREATE TABLE `telemetry_45m_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` VARCHAR(32) NOT NULL DEFAULT '4',
  `site_name` VARCHAR(100) NOT NULL DEFAULT 'Site 4 - ICT401',
  `pm25` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `pm10` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `co2` DECIMAL(7,2) NOT NULL DEFAULT 0.00,
  `temp` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `humid` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `evoc` DECIMAL(7,2) NOT NULL DEFAULT 0.00,
  `rssi` VARCHAR(16) NOT NULL DEFAULT '0',
  `iaq_score` SMALLINT NOT NULL DEFAULT 0,
  `sensor_time` VARCHAR(50) DEFAULT NULL,
  `server_timestamp` BIGINT NOT NULL,
  `server_time` DATETIME NOT NULL,
  `label` VARCHAR(32) DEFAULT NULL COMMENT 'e.g. 11/09 08:45',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_snap_site_ts` (`site_id`, `server_timestamp` DESC),
  KEY `idx_snap_server_time` (`server_time`),
  CONSTRAINT `fk_snap_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`site_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ข้อมูลสแนปช็อตสรุปทุก 45 นาที สำหรับกราฟย้อนหลังระยะยาว';

-- ---------------------------------------------------------------------
-- Table 4: iaq_alerts (Incident & Exceedance Alert Logs)
-- ---------------------------------------------------------------------
CREATE TABLE `iaq_alerts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` VARCHAR(32) NOT NULL DEFAULT '4',
  `metric_name` ENUM('PM25', 'PM10', 'CO2', 'TEMP', 'HUMID', 'EVOC', 'OFFLINE') NOT NULL,
  `metric_value` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `threshold_value` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `severity` ENUM('INFO', 'WARNING', 'DANGER', 'CRITICAL') NOT NULL DEFAULT 'WARNING',
  `status` ENUM('ACTIVE', 'ACKNOWLEDGED', 'RESOLVED') NOT NULL DEFAULT 'ACTIVE',
  `message` VARCHAR(255) NOT NULL,
  `triggered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_alert_status` (`status`, `triggered_at` DESC),
  KEY `idx_alert_site` (`site_id`, `metric_name`),
  CONSTRAINT `fk_alert_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`site_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บันทึกประวัติการแจ้งเตือนเมื่อค่าเซ็นเซอร์เกินเกณฑ์ความปลอดภัย';

-- ---------------------------------------------------------------------
-- Table 5: system_sync_status (API Proxy Sync Health & Route Tracking)
-- ---------------------------------------------------------------------
CREATE TABLE `system_sync_status` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sync_key` VARCHAR(64) NOT NULL,
  `last_sync_timestamp` BIGINT NOT NULL,
  `last_sync_time` DATETIME NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'OK',
  `route_used` VARCHAR(64) DEFAULT 'UNKNOWN',
  `response_ms` DECIMAL(8,2) DEFAULT 0.00,
  `message` TEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_sync_key` (`sync_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='สถานะการเชื่อมต่อ API Proxy และ Latency ของระบบ';

-- ---------------------------------------------------------------------
-- Table 6: system_settings (Dynamic System Parameters & Safety Thresholds)
-- ---------------------------------------------------------------------
CREATE TABLE `system_settings` (
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `setting_group` VARCHAR(32) NOT NULL DEFAULT 'GENERAL',
  `description` VARCHAR(255) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='การตั้งค่าพารามิเตอร์ของระบบและเกณฑ์ความปลอดภัย';

-- ---------------------------------------------------------------------
-- Table 7: users (Administrative & Dashboard Operator Accounts)
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `role` ENUM('ADMIN', 'OPERATOR', 'VIEWER') NOT NULL DEFAULT 'OPERATOR',
  `status` ENUM('ACTIVE', 'SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
  `last_login` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บัญชีผู้ใช้งานระบบและสิทธิ์การจัดการ';

-- =====================================================================
-- Initial Seed Data
-- =====================================================================

-- 1. Seed Sites (Site 4 ICT 401)
INSERT INTO `sites` (`site_id`, `site_code`, `site_name`, `building`, `floor`, `room`, `description`, `sensor_model`, `status`)
VALUES ('4', 'ICT401', 'Site 4 - ICT401', 'ICT BRB', '4', '401', 'ห้องควบคุมและติดตามคุณภาพอากาศ ICT BRB ชั้น 4', 'Emtrontech AIIR v2', 'ACTIVE')
ON DUPLICATE KEY UPDATE `site_name` = VALUES(`site_name`);

-- 2. Seed System Settings & Safety Thresholds (WHO / US-EPA / PCD Thailand Standards)
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('PM25_WARN_THRESHOLD', '37.5', 'THRESHOLDS', 'เกณฑ์เตือนค่า PM2.5 เริ่มมีผลกระทบต่อสุขภาพ (µg/m³)'),
('PM25_DANGER_THRESHOLD', '75.0', 'THRESHOLDS', 'เกณฑ์อันตรายค่า PM2.5 มีผลกระทบต่อสุขภาพมาก (µg/m³)'),
('PM10_WARN_THRESHOLD', '50.0', 'THRESHOLDS', 'เกณฑ์เตือนค่า PM10 (µg/m³)'),
('CO2_WARN_THRESHOLD', '1000.0', 'THRESHOLDS', 'เกณฑ์เตือนก๊าซคาร์บอนไดออกไซด์เกินมาตรฐาน ASHRAE (ppm)'),
('CO2_DANGER_THRESHOLD', '1500.0', 'THRESHOLDS', 'เกณฑ์อันตรายก๊าซคาร์บอนไดออกไซด์ (ppm)'),
('TEMP_MIN_COMFORT', '22.0', 'COMFORT', 'อุณหภูมิต่ำสุดที่รู้สึกสบาย (°C)'),
('TEMP_MAX_COMFORT', '26.0', 'COMFORT', 'อุณหภูมิสูงสุดที่รู้สึกสบาย (°C)'),
('HUMID_MIN_COMFORT', '40.0', 'COMFORT', 'ความชื้นสัมพัทธ์ต่ำสุด (%RH)'),
('HUMID_MAX_COMFORT', '65.0', 'COMFORT', 'ความชื้นสัมพัทธ์สูงสุด (%RH)'),
('EVOC_WARN_THRESHOLD', '50.0', 'THRESHOLDS', 'เกณฑ์เตือนสารระเหยง่าย (ppb)'),
('AUTO_REFRESH_INTERVAL_SEC', '30', 'SYSTEM', 'รอบเวลาดึงข้อมูลอัตโนมัติของ Dashboard (วินาที)'),
('SNAPSHOT_INTERVAL_SEC', '2700', 'SYSTEM', 'รอบบันทึกสแนปช็อตระยะยาว (45 นาที = 2700 วินาที)')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- 3. Seed Default Admin User
-- Password is 'admin123'
INSERT INTO `users` (`username`, `password_hash`, `full_name`, `role`, `status`) VALUES
('admin', '$2y$10$e8w6p7nFzX.rT51o4n2bkuKqQW4aB5b0.R6vU4f6A9gM5v5G9W6Xe', 'System Administrator', 'ADMIN', 'ACTIVE'),
('operator', '$2y$10$e8w6p7nFzX.rT51o4n2bkuKqQW4aB5b0.R6vU4f6A9gM5v5G9W6Xe', 'ICT BRB Operator', 'OPERATOR', 'ACTIVE')
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`);

-- =====================================================================
-- Database Views for Instant Querying in phpMyAdmin & Dashboards
-- =====================================================================

-- View 1: v_latest_telemetry (Latest sensor reading per site)
CREATE VIEW `v_latest_telemetry` AS
SELECT 
    t.id,
    t.site_id,
    s.site_code,
    s.site_name,
    s.building,
    s.floor,
    s.room,
    t.pm25,
    t.pm10,
    t.co2,
    t.temp,
    t.humid,
    t.evoc,
    t.rssi,
    t.iaq_score,
    t.server_timestamp,
    t.server_time,
    t.route_used,
    CASE 
        WHEN t.iaq_score >= 80 THEN 'EXCELLENT'
        WHEN t.iaq_score >= 60 THEN 'MODERATE'
        WHEN t.iaq_score >= 40 THEN 'UNHEALTHY_SENSITIVE'
        ELSE 'POOR'
    END AS air_quality_status
FROM `telemetry_records` t
INNER JOIN `sites` s ON t.site_id = s.site_id
INNER JOIN (
    SELECT site_id, MAX(id) AS max_id 
    FROM `telemetry_records` 
    GROUP BY site_id
) latest ON t.id = latest.max_id;

-- View 2: v_daily_iaq_summary (Daily aggregation for reports)
CREATE VIEW `v_daily_iaq_summary` AS
SELECT 
    DATE(server_time) AS record_date,
    site_id,
    COUNT(*) AS total_samples,
    ROUND(MIN(pm25), 2) AS min_pm25,
    ROUND(AVG(pm25), 2) AS avg_pm25,
    ROUND(MAX(pm25), 2) AS max_pm25,
    ROUND(MIN(co2), 2) AS min_co2,
    ROUND(AVG(co2), 2) AS avg_co2,
    ROUND(MAX(co2), 2) AS max_co2,
    ROUND(AVG(temp), 2) AS avg_temp,
    ROUND(AVG(humid), 2) AS avg_humid,
    ROUND(AVG(iaq_score), 0) AS avg_score
FROM `telemetry_records`
GROUP BY DATE(server_time), site_id
ORDER BY record_date DESC;

-- Restore Environment Settings
SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
