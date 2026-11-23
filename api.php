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
    if (isset($_GET['status'])) {
        $lastSeen = $pdo->query('SELECT recorded_at FROM sensor_readings ORDER BY id DESC LIMIT 1')->fetchColumn();
        $age = $lastSeen === false ? null : abs(time() - (new DateTimeImmutable((string)$lastSeen))->getTimestamp());
        // Sensor updates are expected every second; allow short Wi-Fi/database delays before marking it offline.
        echo json_encode(['ok' => true, 'database_online' => true, 'device_online' => $age !== null && $age <= 10, 'last_seen' => $lastSeen === false ? null : $lastSeen], JSON_THROW_ON_ERROR);
        exit;
    }
    $incremental = array_key_exists('since_id', $_GET);
    if ($incremental) {
        $sinceId = filter_var($_GET['since_id'], FILTER_VALIDATE_INT);
        if ($sinceId === false || $sinceId < 0) throw new InvalidArgumentException('since_id must be a non-negative integer.');
        $statement = $pdo->prepare('SELECT id, water_level_cm, distance_cm, temperature_c, humidity_pct, rain_detected, rain_sensor_value, rain_probability_pct, alert_level, recorded_at FROM sensor_readings WHERE id > ? ORDER BY id ASC LIMIT 200');
        $statement->execute([$sinceId]);
        $readings = $statement->fetchAll();
    } else {
        $readings = $pdo->query('SELECT id, water_level_cm, distance_cm, temperature_c, humidity_pct, rain_detected, rain_sensor_value, rain_probability_pct, alert_level, recorded_at FROM sensor_readings ORDER BY recorded_at DESC, id DESC LIMIT 1000')->fetchAll();
    }
    $total = $incremental ? null : (int)$pdo->query('SELECT COUNT(*) FROM sensor_readings')->fetchColumn();
    echo json_encode(['ok' => true, 'database_online' => true, 'total' => $total, 'readings' => $readings], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Flood monitor API request failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'database_online' => false, 'error' => 'MySQL is unavailable. Import schema.sql and check config.php.']);
}
