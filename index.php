<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
$blynkControlReady = blynkAuthToken() !== '';

$config = [
    'site' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\'),
    'pollMs' => 1000,
    'maxDepthCm' => 20,
    'alertDepthCm' => 9,
    'warningDepthCm' => 13,
    'dangerDepthCm' => 17,
    'rainShiftCm' => 2,
];
$rows = [];
$dbOnline = false;
$databaseError = null;
$serverOnline = true;
$now = new DateTimeImmutable();

try {
    $pdo = database();
    $rows = $pdo->query('SELECT id, water_level_cm, distance_cm, temperature_c, humidity_pct, rain_detected, rain_sensor_value, rain_probability_pct, alert_level, recorded_at FROM sensor_readings ORDER BY recorded_at DESC, id DESC LIMIT 40')->fetchAll();
    $dbOnline = true;
    if ($rows) {
        $last = new DateTimeImmutable((string)$rows[0]['recorded_at']);
        $serverOnline = abs($now->getTimestamp() - $last->getTimestamp()) <= 10;
    } else {
        $serverOnline = false;
    }
} catch (Throwable $error) {
    error_log('Flood monitor dashboard database unavailable: ' . $error->getMessage());
    $databaseError = 'MySQL is not connected. Start MySQL and import schema.sql to view your readings.';
    $serverOnline = false;
}

$demo = false;
if (!$rows) {
    $demo = true;
    $level = 16.4;
    for ($i = 17; $i >= 0; $i--) {
        $minutesAgo = $i * 2;
        $water = round($level - $i * 0.054 + sin($i * 0.82) * 0.12, 1);
        $rows[] = [
            'id' => 1842 - (17 - $i), 'water_level_cm' => max(0, $water),
            'distance_cm' => round(max(0, $config['maxDepthCm'] - $water), 1),
            'temperature_c' => 29.4, 'humidity_pct' => 82,
            'rain_detected' => $i > 3 ? 1 : 0,
            'rain_sensor_value' => $i > 3 ? 1800 : 4095,
            'rain_probability_pct' => 34, 'alert_level' => 'warning',
            'recorded_at' => $now->modify("-{$minutesAgo} minutes")->format('Y-m-d H:i:s'),
        ];
    }
    // Keep the same newest-first order used by the MySQL query above.
    $rows = array_reverse($rows);
}

$chronological = array_reverse($rows);
$latest = $rows[0];
$waterLevel = (float)$latest['water_level_cm'];
$rainShift = !empty($latest['rain_detected']) ? $config['rainShiftCm'] : 0;
$currentAlertDepth = $config['alertDepthCm'] - $rainShift;
$currentWarningDepth = $config['warningDepthCm'] - $rainShift;
$currentDangerDepth = $config['dangerDepthCm'] - $rainShift;
$latestTime = new DateTimeImmutable((string)$latest['recorded_at']);
$trendStart = $latestTime->modify('-30 seconds')->getTimestamp();
$trendRows = array_values(array_filter($chronological, static fn ($reading) => (new DateTimeImmutable((string)$reading['recorded_at']))->getTimestamp() >= $trendStart));
$trendRate = 0.0;
if (count($trendRows) > 1) {
    $firstTrend = $trendRows[0];
    $lastTrend = $trendRows[array_key_last($trendRows)];
    $trendSeconds = (new DateTimeImmutable((string)$lastTrend['recorded_at']))->getTimestamp() - (new DateTimeImmutable((string)$firstTrend['recorded_at']))->getTimestamp();
    if ($trendSeconds > 0) $trendRate = ((float)$lastTrend['water_level_cm'] - (float)$firstTrend['water_level_cm']) / ($trendSeconds / 60);
}
$percentFull = min(100, max(0, $waterLevel / $config['maxDepthCm'] * 100));
$rainChance = isset($latest['rain_probability_pct']) ? (float)$latest['rain_probability_pct'] : null;
$alertLevel = strtolower((string)$latest['alert_level']);
$alertLabels = ['danger' => 'Critical flood risk', 'warning' => 'Rising water &middot; Warning', 'watch' => 'Conditions to monitor', 'normal' => 'All systems normal'];
$alertLabel = $alertLabels[$alertLevel] ?? $alertLabels['normal'];
$fmt = static fn (mixed $value, int $decimals = 1): string => is_numeric($value) ? number_format((float)$value, $decimals) : '—';
$chartRows = array_slice($chronological, -18);
$chartValues = array_map(static fn ($r) => (float)$r['water_level_cm'], $chartRows);
$chartTimes = array_map(static fn ($r) => (new DateTimeImmutable((string)$r['recorded_at']))->format('H:i'), $chartRows);
$initials = 'FM';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0b1119"><meta name="description" content="Live flood and weather monitoring dashboard with permanently stored local sensor readings.">
  <title>FloodWatch &middot; Monitoring dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;450;500;550;600;650;700;750;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/dashboard.css">
  <link rel="stylesheet" href="assets/scenario.css">
  <link rel="stylesheet" href="assets/history.css">
  <link rel="stylesheet" href="assets/overview.css?v=<?= (int)filemtime(__DIR__ . '/assets/overview.css') ?>">
  <script>window.FLOOD_CONFIG = <?= json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <a href="#overview" class="brand"><span class="brand-mark"><svg viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M14 3C11 8 6 13.4 6 18a8 8 0 0 0 16 0c0-4.6-5-10-8-15Z" fill="currentColor" opacity=".2"/><path d="M14 3C11 8 6 13.4 6 18a8 8 0 0 0 16 0c0-4.6-5-10-8-15Z" stroke="currentColor" stroke-width="1.8"/><path d="M10 19c.4 2 1.6 3 3.5 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span><span>Flood<span class="brand-light">Watch</span><small>LOCAL MONITORING</small></span></a>
    <div class="nav-label">MONITORING</div>
    <nav class="navigation" aria-label="Main navigation"><a class="nav-item active" href="#overview"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 20 20"><rect x="2.5" y="2.5" width="6" height="6" rx="1"/><rect x="11.5" y="2.5" width="6" height="6" rx="1"/><rect x="2.5" y="11.5" width="6" height="6" rx="1"/><rect x="11.5" y="11.5" width="6" height="6" rx="1"/></svg></span> Overview <span class="nav-dot"></span></a><a class="nav-item" href="water-levels.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M3 12c1.4 0 1.4-4 2.8-4s1.4 6 2.8 6 1.4-9 2.8-9 1.4 11 2.8 11 1.4-4 2.8-4"/></svg></span> Water levels</a><a class="nav-item" href="climate.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M5 13.5a3 3 0 0 1 .3-6 4.7 4.7 0 0 1 9 1.1 2.5 2.5 0 0 1 .2 5H5Z"/><path d="m7 16-.7 1.5m4-1.5-.7 1.5m4-1.5-.7 1.5"/></svg></span> Rain &amp; climate</a><a class="nav-item" href="history.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M7 4h10M7 10h10M7 16h10"/><circle cx="3.5" cy="4" r=".7"/><circle cx="3.5" cy="10" r=".7"/><circle cx="3.5" cy="16" r=".7"/></svg></span> Reading history</a></nav>
    <div class="nav-label nav-label-bottom">SYSTEM</div>
    <div class="sidebar-status"><span class="status-led <?= $serverOnline ? 'is-online' : 'is-offline' ?>" data-device-indicator></span><div><strong>ESP32 sensor</strong><small data-device-label><?= $serverOnline ? 'Connected' : 'Disconnected' ?></small></div><span class="sidebar-chevron">›</span></div>
    <div class="sidebar-foot"><div class="avatar">FM</div><div class="profile-copy"><strong>Flood Monitor</strong><small>Local administrator</small></div><button class="icon-button profile-more" aria-label="Account options">&middot;&middot;&middot;</button></div>
  </aside>

  <main class="main-content" id="overview">
    <header class="topbar"><div class="breadcrumbs"><button class="menu-button" id="menuButton" aria-label="Toggle navigation">&#9776;</button><span>Monitoring</span><span class="crumb-slash">/</span><strong>Overview</strong></div><div class="topbar-right"><span class="local-pill"><span class="status-led <?= $serverOnline ? 'is-online' : 'is-offline' ?>"></span> <?= $dbOnline ? 'Local server' : 'Server disconnected' ?></span><button class="top-clock" title="Browser local time"><span>&#9719;</span><span id="clock">--:--:--</span></button><span class="avatar top-avatar">FM</span></div></header>
    <div class="page-body">
      <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> FLOOD EARLY WARNING SYSTEM</div><h1>Monitoring overview<span class="heading-period">.</span></h1><p class="page-subtitle">Real-time water levels, local weather and flood risk.</p></div></section>

      <?php if ($demo): ?><div class="notice demo-notice" role="status"><span>ⓘ</span><span><strong>Preview data</strong> — connect your MySQL database to start displaying real sensor readings.</span><a href="setup.php">Database setup <span>&#8599;</span></a></div><?php endif; ?>
      <?php if ($databaseError): ?><div class="notice database-notice" role="status"><span>⌁</span><span><?= htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8') ?></span><a href="setup.php">Setup guide <span>&#8599;</span></a></div><?php endif; ?>

      <section class="alert-banner alert-<?= htmlspecialchars($alertLevel, ENT_QUOTES, 'UTF-8') ?>" id="alertBanner" aria-live="polite"><span class="alert-icon-wrap"><span class="alert-icon"><?= $alertLevel === 'normal' ? '✓' : '!' ?></span></span><span class="alert-copy"><strong id="alertTitle"><?= htmlspecialchars($alertLabel, ENT_QUOTES, 'UTF-8') ?></strong><small id="alertCaption"><?= $alertLevel === 'normal' ? 'Sensor readings are within normal operating ranges.' : 'Water level ' . (abs($trendRate) < 0.5 ? 'stable' : ($trendRate > 0 ? 'increasing' : 'decreasing')) . ' &middot; Last reading ' . htmlspecialchars($latestTime->format('H:i'), ENT_QUOTES, 'UTF-8') ?></small></span><span class="alert-level-pill" id="alertPill"><?= htmlspecialchars(strtoupper($alertLevel), ENT_QUOTES, 'UTF-8') ?></span><span class="alert-chevron">›</span></section>

      <section class="scenario-lab" aria-labelledby="scenarioTitle">
        <div class="scenario-copy"><span class="scenario-kicker">INTERACTIVE SCENARIO LAB</span><h2 id="scenarioTitle">Pressure-test the forecast</h2><p>Explore water levels and rainfall without changing stored sensor readings.</p></div>
        <div class="scenario-controls">
          <label class="scenario-slider-label" for="scenarioLevel"><span>Water depth</span><strong><output id="scenarioLevelValue"><?= $fmt($waterLevel) ?></output> cm</strong></label><input id="scenarioLevel" class="scenario-slider" type="range" min="0" max="<?= (int)$config['maxDepthCm'] ?>" step="0.5" value="<?= htmlspecialchars((string)$waterLevel, ENT_QUOTES, 'UTF-8') ?>" aria-label="Simulated water depth">
          <div class="scenario-result" id="scenarioResult" aria-live="polite"><span class="scenario-result-orb"></span><span><strong id="scenarioRisk">Scenario ready</strong><small id="scenarioAdvice">Move the slider to explore risk.</small></span><span class="scenario-rain" id="scenarioRain">☀ Dry</span></div>
        </div>
      </section>

      <section class="metrics-grid overview-metrics" aria-label="Current sensor readings">
        <article class="metric-card water-card"><div class="card-topline"><span class="metric-label">WATER LEVEL</span><span class="metric-icon water-icon">↟</span></div><div class="water-metric"><div class="water-value"><strong id="waterLevel"><?= $fmt($waterLevel) ?></strong><span>cm</span></div></div><div class="level-track"><span class="level-fill" id="levelFill" style="width:<?= round($percentFull) ?>%"></span><span class="track-marker alert-marker" id="alertMarker" style="left:<?= round($currentAlertDepth / $config['maxDepthCm'] * 100) ?>%"></span><span class="track-marker warning-marker" id="warningMarker" style="left:<?= round($currentWarningDepth / $config['maxDepthCm'] * 100) ?>%"></span><span class="track-marker danger-marker" id="dangerMarker" style="left:<?= round($currentDangerDepth / $config['maxDepthCm'] * 100) ?>%"></span></div><div class="track-labels"><span>0 cm</span><span id="alertThreshold">Alert <strong><?= $currentAlertDepth ?> cm</strong></span><span id="warningThreshold">Warning <strong><?= $currentWarningDepth ?> cm</strong></span><span id="dangerThreshold">Danger <strong><?= $currentDangerDepth ?> cm</strong></span></div><div class="metric-foot"><span>Capacity</span><strong id="levelPercent"><?= $fmt($percentFull, 0) ?>%</strong></div></article>

        <article class="metric-card alarm-control-card" aria-labelledby="alarmControlTitle"><div class="card-topline"><span class="alarm-control-title" id="alarmControlTitle">Alarm Control</span><span class="metric-icon alarm-icon" aria-hidden="true">&#9671;</span></div><div class="alarm-mode-buttons" role="group" aria-label="Buzzer mode"><button type="button" class="alarm-mode is-selected" data-alarm-mode="auto" aria-pressed="true"><span aria-hidden="true">◇</span> Auto</button><button type="button" class="alarm-mode" data-alarm-mode="on" aria-pressed="false"><span aria-hidden="true">◇</span> Siren ON</button><button type="button" class="alarm-mode" data-alarm-mode="silence" aria-pressed="false"><span aria-hidden="true">◇</span> Silence</button></div><p class="alarm-control-note" id="alarmControlStatus" role="status"><?= $blynkControlReady ? 'Checking the current ESP32 alarm mode…' : 'Add your Blynk device token to config.local.php to enable control.' ?></p></article>

        
      </section>
      <footer class="page-footer"><span><span class="footer-led <?= $serverOnline ? 'is-online' : 'is-offline' ?>"></span> <?= $serverOnline ? 'Receiving sensor data' : 'Waiting for sensor connection' ?></span><span class="footer-separator">&middot;</span><span>Last updated <strong id="lastUpdated"><?= htmlspecialchars($latestTime->format('H:i:s'), ENT_QUOTES, 'UTF-8') ?></strong></span><span class="footer-right">Local deployment <span>&middot;</span> No cloud required</span></footer>
    </div>
  </main>
</div>
<script id="dashboard-data" type="application/json"><?= json_encode(['readings' => $chartRows, 'labels' => $chartTimes, 'demo' => $demo, 'dbOnline' => $dbOnline, 'maxDepth' => $config['maxDepthCm'], 'alertDepth' => $config['alertDepthCm'], 'warningDepth' => $config['warningDepthCm'], 'dangerDepth' => $config['dangerDepthCm'], 'rainShift' => $config['rainShiftCm']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="assets/dashboard.js?v=<?= (int)filemtime(__DIR__ . '/assets/dashboard.js') ?>" defer></script><script src="assets/alarm-control.js?v=<?= (int)filemtime(__DIR__ . '/assets/alarm-control.js') ?>" defer></script><script src="assets/device-status.js" defer></script>
</body></html>
