<?php
declare(strict_types=1);

/**
 * Local connection settings. Configure these as environment variables in your
 * PHP server, or edit the fallback values for your XAMPP installation.
 */
const DB_HOST = '127.0.0.1';
const DB_NAME = 'flood_monitor';
const DB_USER = 'root';
const DB_PASSWORD = '';
const DB_TABLE = 'sensor_readings';

// Optional shared secret for POST /ingest.php. Leave empty on trusted LANs.
const INGEST_API_KEY = '';

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
    }
    return $pdo;
}
