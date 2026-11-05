<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Send readings using POST with a JSON body.']);
    exit;
}

require __DIR__ . '/config.php';

try {
    if (INGEST_API_KEY !== '' && !hash_equals(INGEST_API_KEY, (string)($_SERVER['HTTP_X_API_KEY'] ?? ''))) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('The request body must be a JSON object.');

    $required = ['water_level_cm'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || !is_numeric($input[$field])) {
            throw new InvalidArgumentException("{$field} is required and must be numeric.");
        }
    }
    $nullableNumber = static function (string $key) use ($input): ?float {
        if (!isset($input[$key])) return null;
        if (!is_numeric($input[$key])) throw new InvalidArgumentException("{$key} must be numeric.");
        return (float)$input[$key];
    };
    $water = (float)$input['water_level_cm'];
    if ($water < 0 || $water > 10000) throw new InvalidArgumentException('water_level_cm must be between 0 and 10000.');
    $alert = strtolower((string)($input['alert_level'] ?? 'normal'));
    if (!in_array($alert, ['normal', 'watch', 'warning', 'danger'], true)) {
        throw new InvalidArgumentException('alert_level must be normal, watch, warning or danger.');
    }
    $rain = filter_var($input['rain_detected'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

    $statement = database()->prepare(
        'INSERT INTO sensor_readings (water_level_cm, distance_cm, temperature_c, humidity_pct, rain_detected, rain_probability_pct, alert_level) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $water,
        $nullableNumber('distance_cm'),
        $nullableNumber('temperature_c'),
        $nullableNumber('humidity_pct'),
        $rain,
        $nullableNumber('rain_probability_pct'),
        $alert,
    ]);
    http_response_code(201);
    echo json_encode(['ok' => true, 'id' => (int)database()->lastInsertId(), 'recorded_at' => date(DATE_ATOM)]);
} catch (InvalidArgumentException|JsonException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Flood monitor ingest failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Database unavailable. Check config.php and import schema.sql.']);
}
