CREATE DATABASE IF NOT EXISTS flood_monitor
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE flood_monitor;

CREATE TABLE IF NOT EXISTS sensor_readings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  water_level_cm DECIMAL(7,2) NOT NULL COMMENT 'Water depth in centimeters',
  distance_cm DECIMAL(7,2) NULL COMMENT 'HC-SR04 distance to water surface',
  temperature_c DECIMAL(5,2) NULL,
  humidity_pct DECIMAL(5,2) NULL,
  rain_detected TINYINT(1) NOT NULL DEFAULT 0,
  rain_probability_pct DECIMAL(5,2) NULL,
  alert_level ENUM('normal','watch','warning','danger') NOT NULL DEFAULT 'normal',
  recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sensor_recorded_at (recorded_at),
  INDEX idx_sensor_alert (alert_level, recorded_at)
) ENGINE=InnoDB;
