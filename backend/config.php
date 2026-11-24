<?php
declare(strict_types=1);

// MySQL sensor timestamps are recorded in Singapore local time; parse/display them consistently.
date_default_timezone_set('Asia/Singapore');

/**
 * Local connection settings. Configure these as environment variables in your
 * PHP server, or edit the fallback values for your XAMPP installation.
 */
const DB_HOST = '127.0.0.1';
const DB_NAME = 'flood_monitor';
const DB_USER = 'root';
const DB_PASSWORD = '';
const DB_TABLE = 'sensor_readings';

// Optional shared secret for POST /backend/ingest.php. Leave empty on trusted LANs.
const INGEST_API_KEY = '';

/**
 * Blynk credentials belong in config.local.php (ignored by Git) or the
 * BLYNK_AUTH_TOKEN environment variable, never in browser-side code.
 */
function blynkAuthToken(): string
{
    $token = getenv('BLYNK_AUTH_TOKEN');
    if (is_string($token) && $token !== '') return $token;

    $localConfig = __DIR__ . '/config.local.php';
    if (!is_file($localConfig)) return '';
    $settings = require $localConfig;
    return is_array($settings) && is_string($settings['blynk_auth_token'] ?? null)
        ? trim($settings['blynk_auth_token'])
        : '';
}

function database(): PDO
{
    static $pdo;
    if (!$pdo instanceof PDO) {
        $host = getenv('FLOOD_DB_HOST') ?: DB_HOST;
        $name = getenv('FLOOD_DB_NAME') ?: DB_NAME;
        $user = getenv('FLOOD_DB_USER') ?: DB_USER;
        $password = getenv('FLOOD_DB_PASSWORD');
        if ($password === false) $password = DB_PASSWORD;
        $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Upgrade databases created before raw rain sensor values were stored.
        $hasRainValue = $pdo->query("SHOW COLUMNS FROM `" . DB_TABLE . "` LIKE 'rain_sensor_value'")->fetch();
        if (!$hasRainValue) {
            $pdo->exec('ALTER TABLE `' . DB_TABLE . '` ADD COLUMN rain_sensor_value SMALLINT UNSIGNED NULL AFTER rain_detected');
        }
    }
    return $pdo;
}
