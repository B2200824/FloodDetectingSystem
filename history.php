<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$pageSize = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$status = strtolower(trim((string)($_GET['status'] ?? '')));
$search = trim((string)($_GET['q'] ?? ''));
if (!in_array($status, ['', 'normal', 'watch', 'warning', 'danger'], true)) $status = '';
$rows = [];
$total = 0;
$deviceOnline = false;
$error = null;
try {
    $pdo = database();
    $lastSeen = $pdo->query('SELECT recorded_at FROM sensor_readings ORDER BY id DESC LIMIT 1')->fetchColumn();
    $deviceOnline = $lastSeen !== false && abs(time() - (new DateTimeImmutable((string)$lastSeen))->getTimestamp()) <= 10;
    $where = [];
    $params = [];
    if ($status !== '') { $where[] = 'alert_level = :status'; $params['status'] = $status; }
    if ($search !== '') { $where[] = '(CAST(id AS CHAR) LIKE :searchId OR CAST(water_level_cm AS CHAR) LIKE :searchLevel)'; $params['searchId'] = '%' . $search . '%'; $params['searchLevel'] = '%' . $search . '%'; }
    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM sensor_readings' . $clause);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = min($page, $pages);
    $query = $pdo->prepare('SELECT id, water_level_cm, distance_cm, temperature_c, humidity_pct, rain_detected, rain_probability_pct, alert_level, recorded_at FROM sensor_readings' . $clause . ' ORDER BY recorded_at DESC, id DESC LIMIT :limit OFFSET :offset');
    foreach ($params as $key => $value) $query->bindValue(':' . $key, $value);
    $query->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $query->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
    $query->execute();
    $rows = $query->fetchAll();
} catch (Throwable $exception) {
    error_log('Flood monitor history unavailable: ' . $exception->getMessage());
    $error = 'MySQL is unavailable. Start MySQL and import schema.sql to view saved readings.';
    $pages = 1;
}
$from = $total ? ($page - 1) * $pageSize + 1 : 0;
$to = min($page * $pageSize, $total);
$params = array_filter(['status' => $status, 'q' => $search], static fn ($v) => $v !== '');
$pageUrl = static fn (int $number): string => 'history.php?' . http_build_query($params + ['page' => $number]);
$h = static fn (mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$fmt = static fn (mixed $value, int $digits = 1): string => is_numeric($value) ? number_format((float)$value, $digits) : '&mdash;';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#0b1119"><title>Reading history | FloodWatch</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/scenario.css"><link rel="stylesheet" href="assets/history.css"></head>
<body><div class="layout">
  <aside class="sidebar"><a href="index.php#overview" class="brand"><span class="brand-mark"><svg viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M14 3C11 8 6 13.4 6 18a8 8 0 0 0 16 0c0-4.6-5-10-8-15Z" stroke="currentColor" stroke-width="1.8"/><path d="M10 19c.4 2 1.6 3 3.5 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span><span>Flood<span class="brand-light">Watch</span><small>LOCAL MONITORING</small></span></a>
    <div class="nav-label">MONITORING</div><nav class="navigation" aria-label="Main navigation"><a class="nav-item" href="index.php#overview"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 20 20"><rect x="2.5" y="2.5" width="6" height="6" rx="1"/><rect x="11.5" y="2.5" width="6" height="6" rx="1"/><rect x="2.5" y="11.5" width="6" height="6" rx="1"/><rect x="11.5" y="11.5" width="6" height="6" rx="1"/></svg></span> Overview</a><a class="nav-item" href="water-levels.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M3 12c1.4 0 1.4-4 2.8-4s1.4 6 2.8 6 1.4-9 2.8-9 1.4 11 2.8 11 1.4-4 2.8-4"/></svg></span> Water levels</a><a class="nav-item" href="climate.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M5 13.5a3 3 0 0 1 .3-6 4.7 4.7 0 0 1 9 1.1 2.5 2.5 0 0 1 .2 5H5Z"/><path d="m7 16-.7 1.5m4-1.5-.7 1.5m4-1.5-.7 1.5"/></svg></span> Rain &amp; climate</a><a class="nav-item active" href="history.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M7 4h10M7 10h10M7 16h10"/><circle cx="3.5" cy="4" r=".7"/><circle cx="3.5" cy="10" r=".7"/><circle cx="3.5" cy="16" r=".7"/></svg></span> Reading history <span class="nav-dot"></span></a></nav>
    <div class="nav-label nav-label-bottom">SYSTEM</div><div class="sidebar-status"><span class="status-led <?= $deviceOnline ? 'is-online' : 'is-offline' ?>" data-device-indicator></span><div><strong>ESP32 sensor</strong><small data-device-label><?= $deviceOnline ? 'Connected' : 'Disconnected' ?></small></div></div><div class="sidebar-foot"><div class="avatar">FM</div><div class="profile-copy"><strong>Flood Monitor</strong><small>Local administrator</small></div></div>
  </aside>
  <main class="main-content"><header class="topbar"><div class="breadcrumbs"><a href="index.php#overview">Monitoring</a><span class="crumb-slash">/</span><strong>Reading history</strong></div><div class="topbar-right"><a class="history-back" href="index.php">&larr; Dashboard</a></div></header>
    <div class="page-body history-body"><section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> SENSOR ARCHIVE</div><h1>Reading history<span class="heading-period">.</span></h1><p class="page-subtitle">Browse, search, and filter your saved sensor measurements.</p></div><div class="heading-actions history-actions"><a class="button button-quiet history-export" href="export.php"><span aria-hidden="true">&#8595;</span> Export CSV</a><button class="button button-primary" id="historyRefreshButton" type="button"><span class="refresh-icon" aria-hidden="true">&#8635;</span> Refresh data</button></div></section>
      <?php if ($error): ?><div class="notice database-notice" role="status"><span>!</span><span><?= $h($error) ?></span><a href="setup.php">Setup guide &#8599;</a></div><?php endif; ?>
      <section class="history-card"><div class="history-toolbar"><div><strong><?= number_format($total) ?></strong><span> saved readings</span><small> &middot; Showing <?= number_format($from) ?>&ndash;<?= number_format($to) ?></small></div><form class="history-filters" method="get" action="history.php"><label class="sr-only" for="historySearch">Search reading ID or water level</label><input id="historySearch" name="q" type="search" value="<?= $h($search) ?>" placeholder="Search ID or level&hellip;"><label class="sr-only" for="historyStatus">Filter by alert</label><select id="historyStatus" name="status"><option value="">All alerts</option><?php foreach (['normal','watch','warning','danger'] as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= ucfirst($option) ?></option><?php endforeach; ?></select><button class="button button-primary" type="submit">Apply filters</button><?php if ($search !== '' || $status !== ''): ?><a class="clear-filters" href="history.php">Clear</a><?php endif; ?></form></div>
        <div class="table-scroll"><table><thead><tr><th>RECORDED AT</th><th>WATER LEVEL</th><th>DISTANCE</th><th>TEMPERATURE</th><th>HUMIDITY</th><th>RAIN</th><th>ALERT</th></tr></thead><tbody>
        <?php foreach ($rows as $row): $alert = in_array($row['alert_level'], ['normal','watch','warning','danger'], true) ? $row['alert_level'] : 'normal'; ?><tr><td class="timestamp-cell"><span class="row-led <?= $alert === 'danger' ? 'row-led-danger' : ($alert === 'warning' ? 'row-led-warning' : '') ?>"></span><span><?= $h((new DateTimeImmutable((string)$row['recorded_at']))->format('M j, Y')) ?></span><span class="timestamp-time"><?= $h((new DateTimeImmutable((string)$row['recorded_at']))->format('H:i:s')) ?></span></td><td><strong class="table-water-value"><?= $fmt($row['water_level_cm']) ?><span> cm</span></strong></td><td><?= $fmt($row['distance_cm']) ?><span class="unit-label"> cm</span></td><td><?= $fmt($row['temperature_c']) ?><span class="unit-label"> &deg;C</span></td><td><?= $fmt($row['humidity_pct'], 0) ?><span class="unit-label">%</span></td><td><span class="table-rain <?= !empty($row['rain_detected']) ? 'rain-active' : '' ?>"><span class="rain-led"></span><?= !empty($row['rain_detected']) ? 'Rain' : 'No rain' ?></span></td><td><span class="status-pill status-<?= $alert ?>"><span></span><?= ucfirst($alert) ?></span></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td class="history-empty" colspan="7"><?= $error ? 'History is unavailable until MySQL reconnects.' : 'No readings match these filters yet.' ?></td></tr><?php endif; ?></tbody></table></div>
        <div class="history-pagination"><span>Page <strong><?= $page ?></strong> of <strong><?= $pages ?></strong></span><div><?php if ($page > 1): ?><a href="<?= $h($pageUrl($page - 1)) ?>">&larr; Previous</a><?php else: ?><span class="disabled">&larr; Previous</span><?php endif; ?><?php if ($page < $pages): ?><a href="<?= $h($pageUrl($page + 1)) ?>">Next &rarr;</a><?php else: ?><span class="disabled">Next &rarr;</span><?php endif; ?></div><form class="history-page-jump" method="get" action="history.php"><label for="historyPage">Jump to page</label><?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= $h($status) ?>"><?php endif; ?><?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= $h($search) ?>"><?php endif; ?><input id="historyPage" name="page" type="number" min="1" max="<?= $pages ?>" value="<?= $page ?>" aria-label="Page number"><button type="submit">Go</button></form></div>
      </section><footer class="page-footer"><span><span class="footer-led <?= $rows ? 'is-online' : 'is-offline' ?>"></span><?= $rows ? 'History loaded from MySQL' : 'No saved readings available' ?></span><span class="footer-right">FloodWatch &middot; Local monitoring</span></footer>
    </div>
  </main>
</div><script src="assets/device-status.js" defer></script><script src="assets/history.js" defer></script></body></html>
