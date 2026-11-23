<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

$modeValues = ['auto' => 0, 'on' => 1, 'silence' => 2];
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    respond(405, ['ok' => false, 'error' => 'Use GET to read or POST to change the alarm mode.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Accept browser state changes only from the same origin as the dashboard.
    $origin = parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $expectedScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $requestHost = parse_url($expectedScheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!is_array($origin) || !is_array($requestHost)
        || strtolower((string)($origin['scheme'] ?? '')) !== $expectedScheme
        || strtolower((string)($origin['host'] ?? '')) !== strtolower((string)($requestHost['host'] ?? ''))
        || ($origin['port'] ?? null) !== ($requestHost['port'] ?? null)) {
        respond(403, ['ok' => false, 'error' => 'This control request did not come from the dashboard.']);
    }
}

require __DIR__ . '/config.php';
$token = blynkAuthToken();
if ($token === '') {
    respond(503, ['ok' => false, 'error' => 'Blynk is not configured. Add the device token to config.local.php.']);
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? json_decode(file_get_contents('php://input') ?: '', true) : null;
$mode = is_array($input) ? ($input['mode'] ?? null) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!is_string($mode) || !array_key_exists($mode, $modeValues))) {
    respond(400, ['ok' => false, 'error' => 'Choose Auto, Siren ON, or Silence.']);
}

// v2 is the alarm mode datastream in the ESP32 sketch (0=Auto, 1=ON, 2=Silence).
$query = [
    'token' => $token,
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') $query['v2'] = $modeValues[$mode];
$url = 'https://blynk.cloud/external/api/' . ($_SERVER['REQUEST_METHOD'] === 'GET' ? 'get?' : 'update?')
    . http_build_query($query, '', '&', PHP_QUERY_RFC3986)
    . ($_SERVER['REQUEST_METHOD'] === 'GET' ? '&v2' : '');
$context = stream_context_create(['http' => [
    'method' => 'GET',
    'timeout' => 8,
    'ignore_errors' => true,
    'header' => "Accept: application/json\r\n",
]]);
$response = @file_get_contents($url, false, $context);
$statusLine = $http_response_header[0] ?? '';
preg_match('/\s(\d{3})\s/', $statusLine, $matches);
$httpStatus = isset($matches[1]) ? (int)$matches[1] : 0;
if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
    error_log('FloodWatch Blynk alarm command failed with HTTP status ' . ($httpStatus ?: 'unavailable'));
    if ($httpStatus === 0) {
        $message = 'The PHP server could not reach Blynk Cloud. Check its internet connection and PHP HTTPS access.';
    } elseif (stripos((string)$response, 'Invalid token') !== false) {
        $message = 'Blynk rejected the token. Update config.local.php with the current device token.';
    } elseif (stripos((string)$response, 'datastream') !== false || stripos((string)$response, 'pin format') !== false) {
        $message = 'Blynk could not find the V2 datastream. Check that V2 is set up for this device.';
    } else {
        $message = 'Blynk rejected the alarm request (HTTP ' . ($httpStatus ?: 'unavailable') . ').';
    }
    respond(502, ['ok' => false, 'error' => $message]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $values = json_decode($response, true);
    $value = is_array($values) ? ($values[0] ?? $values['v2'] ?? $values['V2'] ?? null) : $values;
    $currentMode = filter_var($value, FILTER_VALIDATE_INT);
    $mode = $currentMode === false ? null : array_search($currentMode, $modeValues, true);
    if (!is_string($mode)) respond(502, ['ok' => false, 'error' => 'Blynk returned an unknown alarm mode.']);
}

respond(200, ['ok' => true, 'mode' => $mode]);
