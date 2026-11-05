<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$config = [
    'site' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\'),
    'pollMs' => 15000,
    'maxDepthCm' => 25,
    'warningDepthCm' => 18,
    'dangerDepthCm' => 22,
];
$rows = [];
$dbOnline = false;
$databaseError = null;
$serverOnline = true;
$now = new DateTimeImmutable();

try {
    $pdo = database();
    $rows = $pdo->query('SELECT id, water_level_cm, distance_cm, temperature_c, humidity_pct, rain_detected, rain_probability_pct, alert_level, recorded_at FROM sensor_readings ORDER BY recorded_at DESC, id DESC LIMIT 40')->fetchAll();
    $dbOnline = true;
    if ($rows) {
        $last = new DateTimeImmutable((string)$rows[0]['recorded_at']);
        $serverOnline = $now->getTimestamp() - $last->getTimestamp() < 180;
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
            'rain_probability_pct' => 34, 'alert_level' => 'warning',
            'recorded_at' => $now->modify("-{$minutesAgo} minutes")->format('Y-m-d H:i:s'),
        ];
    }
    // Keep the same newest-first order used by the MySQL query above.
    $rows = array_reverse($rows);
}

$chronological = array_reverse($rows);
$latest = $rows[0];
$previous = $rows[1] ?? $latest;
$waterLevel = (float)$latest['water_level_cm'];
$previousLevel = (float)$previous['water_level_cm'];
$latestTime = new DateTimeImmutable((string)$latest['recorded_at']);
$previousTime = new DateTimeImmutable((string)$previous['recorded_at']);
$minutes = max(1 / 60, ($latestTime->getTimestamp() - $previousTime->getTimestamp()) / 60);
$riseRate = ($waterLevel - $previousLevel) / $minutes;
$timeToDanger = $riseRate > 0.001 ? max(0, ($config['dangerDepthCm'] - $waterLevel) / $riseRate) : null;
$percentFull = min(100, max(0, $waterLevel / $config['maxDepthCm'] * 100));
$rainChance = isset($latest['rain_probability_pct']) ? (float)$latest['rain_probability_pct'] : null;
$alertLevel = strtolower((string)$latest['alert_level']);
if (!$demo && $riseRate > 0.12 && $waterLevel >= $config['warningDepthCm']) $alertLevel = 'danger';
elseif (!$demo && $waterLevel >= $config['warningDepthCm'] && !in_array($alertLevel, ['danger','warning'], true)) $alertLevel = 'warning';
$alertLabels = ['danger' => 'Critical flood risk', 'warning' => 'Rising water · Warning', 'watch' => 'Conditions to monitor', 'normal' => 'All systems normal'];
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
  <title>FloodWatch · Monitoring dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;450;500;550;600;650;700;750;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/dashboard.css">
  <script>window.FLOOD_CONFIG = <?= json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <a href="#overview" class="brand"><span class="brand-mark"><svg viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M14 3C11 8 6 13.4 6 18a8 8 0 0 0 16 0c0-4.6-5-10-8-15Z" fill="currentColor" opacity=".2"/><path d="M14 3C11 8 6 13.4 6 18a8 8 0 0 0 16 0c0-4.6-5-10-8-15Z" stroke="currentColor" stroke-width="1.8"/><path d="M10 19c.4 2 1.6 3 3.5 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span><span>Flood<span class="brand-light">Watch</span><small>LOCAL MONITORING</small></span></a>
    <div class="nav-label">MONITORING</div>
    <nav class="navigation" aria-label="Main navigation"><a class="nav-item active" href="#overview"><span class="nav-icon">▦</span> Overview <span class="nav-dot"></span></a><a class="nav-item" href="#readings"><span class="nav-icon">⟱</span> Water levels</a><a class="nav-item" href="#climate"><span class="nav-icon">☁</span> Rain & climate</a><a class="nav-item" href="#readings"><span class="nav-icon">▤</span> Reading history</a></nav>
    <div class="nav-label nav-label-bottom">SYSTEM</div>
    <div class="sidebar-status"><span class="status-led <?= $serverOnline ? 'is-online' : 'is-offline' ?>"></span><div><strong>ESP32 sensor</strong><small><?= $serverOnline ? 'Transmitting data' : 'Awaiting sensor data' ?></small></div><span class="sidebar-chevron">›</span></div>
    <div class="sidebar-foot"><div class="avatar">FM</div><div class="profile-copy"><strong>Flood Monitor</strong><small>Local administrator</small></div><button class="icon-button profile-more" aria-label="Account options">···</button></div>
  </aside>

  <main class="main-content" id="overview">
    <header class="topbar"><div class="breadcrumbs"><button class="menu-button" id="menuButton" aria-label="Toggle navigation">☰</button><span>Monitoring</span><span class="crumb-slash">/</span><strong>Overview</strong></div><div class="topbar-right"><span class="local-pill"><span class="status-led <?= $serverOnline ? 'is-online' : 'is-offline' ?>"></span> <?= $dbOnline ? 'Local server' : 'Server disconnected' ?></span><button class="top-clock" title="Browser local time"><span>◷</span><span id="clock">--:--:--</span></button><span class="avatar top-avatar">FM</span></div></header>
    <div class="page-body">
      <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> FLOOD EARLY WARNING SYSTEM</div><h1>Monitoring overview<span class="heading-period">.</span></h1><p class="page-subtitle">Real-time water levels, local weather and flood risk.</p></div><div class="heading-actions"><button class="button button-quiet" id="downloadButton"><span>↓</span> Export CSV</button><button class="button button-primary" id="refreshButton"><span class="refresh-icon">↻</span> Refresh data</button></div></section>

      <?php if ($demo): ?><div class="notice demo-notice" role="status"><span>ⓘ</span><span><strong>Preview data</strong> — connect your MySQL database to start displaying real sensor readings.</span><a href="setup.php">Database setup <span>↗</span></a></div><?php endif; ?>
      <?php if ($databaseError): ?><div class="notice database-notice" role="status"><span>⌁</span><span><?= htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8') ?></span><a href="setup.php">Setup guide <span>↗</span></a></div><?php endif; ?>

      <section class="alert-banner alert-<?= htmlspecialchars($alertLevel, ENT_QUOTES, 'UTF-8') ?>" id="alertBanner" aria-live="polite"><span class="alert-icon-wrap"><span class="alert-icon"><?= $alertLevel === 'normal' ? '✓' : '!' ?></span></span><span class="alert-copy"><strong id="alertTitle"><?= htmlspecialchars($alertLabel, ENT_QUOTES, 'UTF-8') ?></strong><small id="alertCaption"><?= $alertLevel === 'normal' ? 'Sensor readings are within normal operating ranges.' : 'Water level ' . ($riseRate >= 0 ? 'increasing' : 'decreasing') . ' · Last reading ' . htmlspecialchars($latestTime->format('H:i'), ENT_QUOTES, 'UTF-8') ?></small></span><span class="alert-level-pill" id="alertPill"><?= htmlspecialchars(strtoupper($alertLevel), ENT_QUOTES, 'UTF-8') ?></span><span class="alert-chevron">›</span></section>

      <section class="metrics-grid" aria-label="Current sensor readings">
        <article class="metric-card water-card"><div class="card-topline"><span class="metric-label">WATER LEVEL</span><span class="metric-icon water-icon">↟</span></div><div class="water-metric"><div class="water-value"><strong id="waterLevel"><?= $fmt($waterLevel) ?></strong><span>cm</span></div><span class="trend-badge <?= $riseRate > 0.01 ? 'trend-up' : ($riseRate < -0.01 ? 'trend-down' : 'trend-steady') ?>" id="levelTrend"><?= $riseRate >= 0 ? '↗' : '↘' ?> <?= abs($riseRate) < 0.001 ? 'Steady' : $fmt($riseRate) . ' cm / min' ?></span></div><div class="level-track"><span class="level-fill" id="levelFill" style="width:<?= round($percentFull) ?>%"></span><span class="track-marker warning-marker" style="left:<?= round($config['warningDepthCm'] / $config['maxDepthCm'] * 100) ?>%"></span><span class="track-marker danger-marker" style="left:<?= round($config['dangerDepthCm'] / $config['maxDepthCm'] * 100) ?>%"></span></div><div class="track-labels"><span>0 cm</span><span>Warning <strong><?= $config['warningDepthCm'] ?> cm</strong></span><span>Danger <strong><?= $config['dangerDepthCm'] ?> cm</strong></span></div><div class="metric-foot"><span>Capacity</span><strong id="levelPercent"><?= $fmt($percentFull, 0) ?>%</strong></div></article>

        <article class="metric-card prediction-card"><div class="card-topline"><span class="metric-label">FLOOD PREDICTION</span><span class="metric-icon prediction-icon">↗</span></div><div class="prediction-main"><div class="prediction-value"><strong id="riseRate"><?= $fmt($riseRate, 2) ?></strong><span>cm/min</span></div><span class="prediction-caption">Rate of rise</span></div><div class="prediction-divider"></div><div class="danger-prediction"><span>Estimated time to danger</span><strong id="timeToDanger"><?= $timeToDanger === null ? '—' : ($timeToDanger < 60 ? round($timeToDanger) . ' min' : floor($timeToDanger / 60) . 'h ' . round($timeToDanger % 60) . 'm') ?></strong></div><div class="prediction-foot">Based on the last two sensor readings</div></article>

        <article class="metric-card climate-card" id="climate"><div class="card-topline"><span class="metric-label">WEATHER CONDITIONS</span><span class="metric-icon climate-icon">☼</span></div><div class="climate-stats"><div class="climate-stat"><span class="climate-symbol temperature-symbol">°</span><div><strong id="temperature"><?= $fmt($latest['temperature_c']) ?>°<span>C</span></strong><small>Temperature</small></div></div><div class="climate-stat"><span class="climate-symbol humidity-symbol">◌</span><div><strong id="humidity"><?= $fmt($latest['humidity_pct'], 0) ?><span>%</span></strong><small>Humidity</small></div></div></div><div class="rain-row"><span class="rain-status <?= !empty($latest['rain_detected']) ? 'rain-active' : '' ?>"><span class="rain-led"></span> Rain <?= !empty($latest['rain_detected']) ? 'detected' : 'not detected' ?></span><span class="rain-probability"><?= $rainChance !== null ? $fmt($rainChance, 0) . '% chance' : 'No forecast' ?></span></div></article>
      </section>

      <section class="chart-card" aria-label="Historical water level chart"><div class="section-card-heading"><div><div class="section-title-row"><span class="chart-title-icon">⌁</span><h2>Water level history</h2><span class="live-tag"><span class="live-dot"></span> LIVE</span></div><p class="section-description">Sensor readings over the last 36 minutes</p></div><div class="chart-heading-right"><div class="chart-legend"><span></span> Water depth</div><select id="chartRange" class="range-select" aria-label="Chart time range"><option value="18">Last 36 min</option><option value="9">Last 18 min</option><option value="6">Last 12 min</option></select><button class="icon-button chart-options" aria-label="Chart options">···</button></div></div><div class="chart-wrap"><div class="chart-y-axis"><span><?= $config['maxDepthCm'] ?></span><span>20</span><span><?= $config['warningDepthCm'] ?></span><span>10</span><span>0</span></div><div class="chart-plot"><div class="chart-gridlines"><span></span><span></span><span></span><span></span><span></span></div><div class="threshold-line warning-line" style="bottom:<?= $config['warningDepthCm'] / $config['maxDepthCm'] * 100 ?>%"><span>WARNING · <?= $config['warningDepthCm'] ?> CM</span></div><div class="threshold-line danger-line" style="bottom:<?= $config['dangerDepthCm'] / $config['maxDepthCm'] * 100 ?>%"><span>DANGER · <?= $config['dangerDepthCm'] ?> CM</span></div><svg id="waterChart" class="water-chart" viewBox="0 0 900 220" preserveAspectRatio="none" role="img" aria-label="Water depth history line chart"><defs><linearGradient id="areaGradient" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#35c5a0" stop-opacity=".24"/><stop offset="100%" stop-color="#35c5a0" stop-opacity="0"/></linearGradient></defs><path id="chartArea" class="chart-area"/><path id="chartPath" class="chart-path"/><g id="chartPoints"></g></svg><div id="chartTooltip" class="chart-tooltip"></div></div></div><div class="chart-x-axis" id="chartTimes"></div></section>

      <section class="readings-card" id="readings"><div class="section-card-heading readings-heading"><div><div class="section-title-row"><span class="table-title-icon">▤</span><h2>Recent readings</h2><span class="count-pill" id="readingCount"><?= count($rows) ?></span></div><p class="section-description">Permanently stored sensor measurements</p></div><span class="data-source"><span class="database-icon">◈</span> <?= $dbOnline ? 'MySQL · Local database' : 'Demo data · MySQL offline' ?></span></div><div class="table-scroll"><table><thead><tr><th>RECORDED AT</th><th>WATER LEVEL</th><th>RISE RATE</th><th>RAINFALL</th><th>TEMPERATURE</th><th>HUMIDITY</th><th>STATUS</th></tr></thead><tbody id="readingsBody">
      <?php foreach (array_slice($rows, 0, 8) as $index => $reading): $readingDate = new DateTimeImmutable((string)$reading['recorded_at']); $rowPrevious = $rows[$index + 1] ?? $reading; $rowPreviousDate = new DateTimeImmutable((string)$rowPrevious['recorded_at']); $rowMinutes = max(1 / 60, ($readingDate->getTimestamp() - $rowPreviousDate->getTimestamp()) / 60); $rowRate = ((float)$reading['water_level_cm'] - (float)$rowPrevious['water_level_cm']) / $rowMinutes; $rowAlert = (string)$reading['alert_level']; ?>
        <tr><td class="timestamp-cell"><span class="row-led <?= $rowAlert === 'danger' ? 'row-led-danger' : ($rowAlert === 'warning' ? 'row-led-warning' : '') ?>"></span><span><?= htmlspecialchars($readingDate->format('M j, Y'), ENT_QUOTES, 'UTF-8') ?></span><span class="timestamp-time"><?= htmlspecialchars($readingDate->format('H:i:s'), ENT_QUOTES, 'UTF-8') ?></span></td><td><strong class="table-water-value"><?= $fmt($reading['water_level_cm']) ?><span> cm</span></strong></td><td class="rate-cell <?= $rowRate > 0.01 ? 'rate-positive' : ($rowRate < -0.01 ? 'rate-negative' : '') ?>"><?= abs($rowRate) > 0.001 ? (($rowRate > 0) ? '↗ ' : '↘ ') . $fmt(abs($rowRate), 2) . ' <span>cm/m</span>' : '—' ?></td><td><span class="table-rain <?= !empty($reading['rain_detected']) ? 'rain-active' : '' ?>"><span class="rain-led"></span><?= !empty($reading['rain_detected']) ? 'Detected' : 'None' ?></span></td><td><?= $fmt($reading['temperature_c']) ?><span class="unit-label">°C</span></td><td><?= $fmt($reading['humidity_pct'], 0) ?><span class="unit-label">%</span></td><td><span class="status-pill status-<?= htmlspecialchars($rowAlert, ENT_QUOTES, 'UTF-8') ?>"><span></span><?= htmlspecialchars(ucfirst($rowAlert), ENT_QUOTES, 'UTF-8') ?></span></td></tr>
      <?php endforeach; ?>
      </tbody></table></div><div class="table-footer"><span>Showing <strong><?= min(8, count($rows)) ?></strong> of <strong id="totalReadings"><?= $demo ? '—' : count($rows) ?></strong> recent readings</span><button class="view-all-button" id="viewAllButton">View all readings <span>→</span></button></div></section>
      <footer class="page-footer"><span><span class="footer-led <?= $serverOnline ? 'is-online' : 'is-offline' ?>"></span> <?= $serverOnline ? 'Receiving sensor data' : 'Waiting for sensor connection' ?></span><span class="footer-separator">·</span><span>Last updated <strong id="lastUpdated"><?= htmlspecialchars($latestTime->format('H:i:s'), ENT_QUOTES, 'UTF-8') ?></strong></span><span class="footer-right">Local deployment <span>·</span> No cloud required</span></footer>
    </div>
  </main>
</div>
<script id="dashboard-data" type="application/json"><?= json_encode(['readings' => $chartRows, 'labels' => $chartTimes, 'demo' => $demo, 'dbOnline' => $dbOnline, 'maxDepth' => $config['maxDepthCm'], 'warningDepth' => $config['warningDepthCm'], 'dangerDepth' => $config['dangerDepthCm']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="assets/dashboard.js" defer></script>
</body></html>
