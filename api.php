<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false, 'error' => 'Use GET to retrieve readings.']);
    exit;
}

require __DIR__ . '/config.php';

try {
    $pdo = database();
    $readings = $pdo->query('SELECT id, water_level_cm, distance_cm, temperature_c, humidity_pct, rain_detected, rain_probability_pct, alert_level, recorded_at FROM sensor_readings ORDER BY recorded_at DESC, id DESC LIMIT 1000')->fetchAll();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM sensor_readings')->fetchColumn();
    echo json_encode(['ok' => true, 'database_online' => true, 'total' => $total, 'readings' => $readings], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Flood monitor API request failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'database_online' => false, 'error' => 'MySQL is unavailable. Import schema.sql and check config.php.']);
}
