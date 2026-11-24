<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

try {
    $pdo = database();
    $statement = $pdo->query('SELECT id, recorded_at, water_level_cm, distance_cm, temperature_c, humidity_pct, rain_detected, rain_sensor_value, rain_probability_pct, alert_level FROM sensor_readings ORDER BY recorded_at DESC, id DESC');
} catch (Throwable $error) {
    error_log('Flood monitor CSV export failed: ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to export readings because MySQL is unavailable.';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="flood-readings-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');
$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, ['ID', 'Recorded at', 'Water level (cm)', 'Distance (cm)', 'Temperature (C)', 'Humidity (%)', 'Rain detected', 'Rain sensor value (0-4095)', 'Rain probability (%)', 'Alert']);
while ($row = $statement->fetch(PDO::FETCH_NUM)) {
    fputcsv($output, $row);
}
fclose($output);
