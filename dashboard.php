<?php
require_once __DIR__ . '/includes/admin_dashboard_data.php';
if (empty($_SESSION['pending_csrf'])) $_SESSION['pending_csrf'] = bin2hex(random_bytes(32));
if (empty($_SESSION['dashboard_csrf'])) $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));

// Retain the historical gallery POST field names, with authorization and CSRF protection.
if (request_method_is('POST')) {
    if (!$dashAdmin || !hash_equals($_SESSION['dashboard_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit('Gallery action denied. Refresh the dashboard and use its gallery form.');
    }
    if (isset($_POST['remove_id'])) {
        $image = dash_query('SELECT image_path FROM highlight_images WHERE id=?', [(string) (int) $_POST['remove_id']])[0] ?? null;
        if ($image) {
            $base = realpath(__DIR__ . '/uploads/highlights/gallery');
            $path = realpath(__DIR__ . '/' . $image['image_path']);
            if ($base && $path && str_starts_with($path, $base . DIRECTORY_SEPARATOR) && is_file($path)) unlink($path);
            $stmt = $conn->prepare('DELETE FROM highlight_images WHERE id=?');
            $id = (int) $_POST['remove_id']; $stmt->bind_param('i', $id); $stmt->execute();
        }
        exit('deleted');
    }
    if (isset($_POST['upload_images'])) {
        $eventId = (int) ($_POST['event_id'] ?? -1);
        if (!dash_query('SELECT id FROM events WHERE id=?', [(string) $eventId])) { http_response_code(400); exit('Unknown event.'); }
        $dir = __DIR__ . '/uploads/highlights/gallery/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        foreach (($_FILES['images']['tmp_name'] ?? []) as $key => $tmp) {
            if ($_FILES['images']['error'][$key] !== UPLOAD_ERR_OK || $_FILES['images']['size'][$key] > 8 * 1024 * 1024) continue;
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime] ?? null;
            if (!$ext || !getimagesize($tmp)) continue;
            $relative = 'uploads/highlights/gallery/' . bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($tmp, __DIR__ . '/' . $relative)) {
                $stmt = $conn->prepare('INSERT INTO highlight_images (event_id,image_path,uploaded_at) VALUES (?,?,NOW())');
                $stmt->bind_param('is', $eventId, $relative); $stmt->execute(); $stmt->close();
            }
        }
        redirect('dashboard.php');
    }
    http_response_code(400); exit('Unknown action.');
}
if (($_GET['export'] ?? '') === 'csv') {
    if (!$dashAvailable) { http_response_code(503); exit($dashDataError); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="jpcs-dashboard-' . $dashStart->format('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    $csv = static function (array $row) use ($out): void {
        fputcsv($out, array_map(static function ($v) { $v = (string) $v; return preg_match('/^[=+@\-\t\r\n]/', $v) ? "'" . $v : $v; }, $row));
    };
    $csv(['JPCS dashboard summary', $dashStart->format('Y-m-d'), $dashEnd->format('Y-m-d')]);
    $csv(['Prior period', $dashPrior[0], $dashStart->modify('-1 day')->format('Y-m-d')]);
    $csv(['Metric','Value','Prior value','Definition / limitation']);
    foreach ($dashKpis as $key=>$meta) $csv([$meta[0],$dashMetrics[$key],$meta[2] ? $dashPrevious[$key] : 'Unavailable',$meta[1]]);
    foreach (['Registrations by date'=>$dashDaily,'Attendance by event (top 12)'=>$dashEventAttendance,'Event status (current)'=>$dashStatuses,'Course / year / section'=>$dashSections] as $title=>$rows) {
        $csv([]); $csv([$title,'Count']); foreach ($rows as $row) $csv([$row['label'],$row['value']]);
    }
    fclose($out); exit;
}
$dashCharts = ['daily'=>$dashDaily,'attendance'=>$dashEventAttendance,'status'=>$dashStatuses,'sections'=>$dashSections];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard · JPCS</title><link rel="icon" href="assets/img/jpcs-logo.png">
<?php require __DIR__ . '/includes/admin_head.php'; ?><script src="js/admin-dashboard.js?v=<?= filemtime(__DIR__ . '/js/admin-dashboard.js') ?>" defer></script></head>
<body class="app-page admin-page">
<?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
<?php
require_once __DIR__ . '/includes/permissions.php';
$dashCan = static fn(string $feature): bool => permissions_can($conn, $dashUser, $feature);
$dashHour = (int) date('G');
$dashGreeting = $dashHour < 12 ? 'Good morning' : ($dashHour < 18 ? 'Good afternoon' : 'Good evening');
$dashNameParts = preg_split('/\s+/', trim((string) $dashUser['name'])) ?: [];
// Keep a title with the name: "Prof Dave", not "Prof".
$dashFirstName = implode(' ', array_slice($dashNameParts, 0, preg_match('/^(prof|dr|engr|mr|mrs|ms|sir|maam)\.?$/i', $dashNameParts[0] ?? '') ? 2 : 1));
$dashPeriods = ['today' => 'Today', '7' => '7 days', '30' => '30 days', 'semester' => 'Semester'];
$dashTrendBadge = static function (string $key) use ($dashMetrics, $dashPrevious, $dashAvailable): string {
    if (!$dashAvailable) return '';
    $delta = $dashMetrics[$key] - $dashPrevious[$key];
    if ($delta === 0) return '<span class="db-trend is-flat">No change</span>';
    $pct = $dashPrevious[$key] > 0 ? ' · ' . abs(round($delta / $dashPrevious[$key] * 100)) . '%' : '';
    return '<span class="db-trend ' . ($delta > 0 ? 'is-up' : 'is-down') . '">' . ($delta > 0 ? '▲ ' : '▼ ') . abs($delta) . $pct . '</span>';
};
$dashActivityIcon = ['Registration received' => '<path d="M15 19c-.6-2.8-2.6-4.3-6-4.3S3.6 16.2 3 19"></path><circle cx="9" cy="8" r="3.3"></circle><path d="M18 8v6M15 11h6"></path>', 'Attendance scanned' => '<path d="M4 8V5h3M17 5h3v3M20 16v3h-3M7 19H4v-3"></path><path d="m8.5 12 2.5 2.5 4.5-5"></path>', 'Event created' => '<path d="M4 6h16v14H4z"></path><path d="M8 4v4M16 4v4M4 10h16"></path>'];
$dashQuick = array_filter([
    ['registrations', 'registrations.php', 'Review registrations', '<path d="M4 5h16v14H4z"></path><path d="M8 9h8M8 13h5"></path>'],
    ['scanner', 'scanner.php', 'Open scanner', '<path d="M4 8V5h3M17 5h3v3M20 16v3h-3M7 19H4v-3"></path><path d="M8 12h8"></path>'],
    ['events', 'events.php' . ($dashAdmin ? '?createform=1' : ''), $dashAdmin ? 'Create event' : 'View events', '<path d="M4 6h16v14H4z"></path><path d="M8 4v4M16 4v4M4 10h16"></path>'],
    ['masterlist', 'masterlist.php', 'Upload masterlist', '<path d="M6 3h9l4 4v14H6z"></path><path d="M14 3v5h5M9 13h6M12 10v6"></path>'],
    [$dashAdmin ? '' : '__never', 'accounts.php', 'Manage accounts', '<circle cx="12" cy="8" r="3"></circle><path d="M5 20c.8-3.3 3.2-5 7-5s6.2 1.7 7 5"></path>'],
    ['audit', 'audit.php', 'Audit trail', '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"></path><path d="M9 12l2 2 4-4"></path>'],
], static fn($action) => $action[0] === '' || ($action[0] !== '__never' && $dashCan($action[0])));
?>
<main class="content dash-page db-page">
  <header class="db-head">
    <div>
      <p class="db-date"><?= e(date('l, F j, Y')) ?></p>
      <h1><?= e($dashGreeting) ?><?= $dashFirstName !== '' ? ', ' . e($dashFirstName) : '' ?></h1>
      <p class="db-sub">Here's what's happening in JPCS — <?= e($dashStart->format('M j')) ?> to <?= e($dashEnd->format('M j, Y')) ?>.</p>
    </div>
    <div class="db-head__tools">
      <nav class="rx-segment db-periods" aria-label="Reporting period">
        <?php foreach ($dashPeriods as $value => $label) { ?><a href="?period=<?= e($value) ?>"<?= $dashPeriod === (string) $value ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php } ?>
        <details class="db-custom"<?= $dashPeriod === 'custom' ? ' open' : '' ?>>
          <summary<?= $dashPeriod === 'custom' ? ' aria-current="page"' : '' ?>>Custom</summary>
          <form method="get" class="db-custom__form">
            <input type="hidden" name="period" value="custom">
            <label>From<input type="date" name="start" value="<?= e($dashStart->format('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>"></label>
            <label>To<input type="date" name="end" value="<?= e($dashEnd->format('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>"></label>
            <button type="submit">Apply</button>
          </form>
        </details>
      </nav>
      <details class="db-more">
        <summary class="btn btn-ghost" aria-label="Export options"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 20h14"></path></svg>Export</summary>
        <div class="db-more__menu"><a href="?<?= e($dashQuery) ?>&amp;export=csv">Download CSV</a><button type="button" data-dash-print>Print / save as PDF</button></div>
      </details>
    </div>
  </header>
  <?php if ($dashError || $dashDataError) { ?><p class="dash-alert" role="alert"><?= e($dashError ?: $dashDataError) ?></p><?php } ?>

  <section class="db-kpis" aria-label="Key numbers">
    <article class="db-kpi"><span class="db-kpi__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M15 19c-.6-2.8-2.6-4.3-6-4.3S3.6 16.2 3 19"></path><circle cx="9" cy="8" r="3.3"></circle><path d="M18 8v6M15 11h6"></path></svg></span><div><span>Registrations</span><strong><?= $dashAvailable ? number_format($dashMetrics['registrations']) : '—' ?></strong><?= $dashTrendBadge('registrations') ?></div></article>
    <<?= $dashCan('registrations') ? 'a href="registrations.php"' : 'article' ?> class="db-kpi is-warn"><span class="db-kpi__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><path d="M12 8v4l2.5 2"></path></svg></span><div><span>Pending review</span><strong><?= $dashPendingTotal === null ? '—' : number_format($dashPendingTotal) ?></strong><small><?= $dashPendingTotal ? 'Waiting for an officer' : 'All caught up' ?></small></div></<?= $dashCan('registrations') ? 'a' : 'article' ?>>
    <article class="db-kpi is-good"><span class="db-kpi__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 8V5h3M17 5h3v3M20 16v3h-3M7 19H4v-3"></path><path d="m8.5 12 2.5 2.5 4.5-5"></path></svg></span><div><span>Check-ins</span><strong><?= $dashAvailable ? number_format($dashMetrics['scans']) : '—' ?></strong><?= $dashTrendBadge('scans') ?></div></article>
    <<?= $dashCan('events') ? 'a href="events.php"' : 'article' ?> class="db-kpi is-muted"><span class="db-kpi__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h16v14H4z"></path><path d="M8 4v4M16 4v4M4 10h16"></path></svg></span><div><span>Upcoming events</span><strong><?= count($dashUpcoming) ?></strong><small><?= $dashUpcoming ? 'Next: ' . e(date('M j', strtotime((string) $dashUpcoming[0]['event_date']))) : 'None scheduled' ?></small></div></<?= $dashCan('events') ? 'a' : 'article' ?>>
  </section>

  <div class="db-layout">
    <div class="db-main">
      <section class="panel-card db-card">
        <div class="db-card__head"><div><h2>Registrations over time</h2><p>Daily registrations in the selected period.</p></div><span class="db-total"><?= $dashAvailable ? number_format($dashMetrics['registrations']) : '—' ?> total</span></div>
        <div class="dash-chart" data-dash-chart="daily"></div>
      </section>

      <?php if ($dashCan('registrations')) { ?>
      <section class="panel-card db-card">
        <div class="db-card__head"><div><h2>Needs your attention <?php if ($dashPendingTotal) { ?><span class="dash-badge"><?= (int) $dashPendingTotal ?></span><?php } ?></h2><p>Oldest pending registrations first.</p></div><a class="db-link" href="registrations.php">View all</a></div>
        <?php if (!$dashPending) { ?>
          <div class="db-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"></path></svg></span>No registrations are waiting. Nice work!</div>
        <?php } else { ?>
          <ul class="db-list">
            <?php foreach (array_slice($dashPending, 0, 5) as $row) { ?>
              <li class="db-pending">
                <span class="db-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim((string) $row['student_name']), 0, 1))) ?></span>
                <div class="db-pending__who"><strong><?= e($row['student_name']) ?></strong><small><?= e($row['title']) ?> · <?= e(date('M j, g:i A', strtotime((string) $row['registered_at']))) ?></small></div>
                <form action="pending.php" method="post" class="db-pending__actions" data-dash-review><input type="hidden" name="csrf" value="<?= e($_SESSION['pending_csrf']) ?>"><input type="hidden" name="registration_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="event_id" value="<?= (int) $row['event_id'] ?>">
                  <?php if ($row['event_status'] === 'upcoming') { ?><button name="action" value="confirm" class="db-approve" title="Approve and email the QR code">Approve</button><?php } ?>
                  <button name="action" value="remove" class="dash-danger db-reject" title="Reject and remove">Reject</button>
                </form>
              </li>
            <?php } ?>
          </ul>
        <?php } ?>
      </section>
      <?php } ?>
    </div>

    <aside class="db-side">
      <section class="panel-card db-card">
        <div class="db-card__head"><h2>Upcoming events</h2><?php if ($dashCan('events')) { ?><a class="db-link" href="events.php">All events</a><?php } ?></div>
        <?php if (!$dashUpcoming) { ?><div class="db-empty">No upcoming events yet.</div><?php } else { ?>
          <ul class="db-list">
            <?php foreach (array_slice($dashUpcoming, 0, 4) as $event) { $ts = strtotime((string) $event['event_date']); ?>
              <li><a class="db-event" href="<?= $dashCan('registrations') ? 'registrations.php?event_id=' . (int) $event['id'] : '#' ?>">
                <span class="db-event__date"><b><?= e(date('j', $ts)) ?></b><?= e(date('M', $ts)) ?></span>
                <span class="db-event__body"><strong><?= e($event['title']) ?></strong><small><?= $event['event_time'] ? e(date('g:i A', strtotime((string) $event['event_time']))) . ' · ' : '' ?><?= e($event['location']) ?></small><small class="db-event__stats"><?= (int) $event['registrations'] ?> registered<?= (int) $event['pending'] ? ' · <b>' . (int) $event['pending'] . ' pending</b>' : '' ?></small></span>
              </a></li>
            <?php } ?>
          </ul>
        <?php } ?>
      </section>

      <?php if ($dashQuick) { ?>
      <section class="panel-card db-card">
        <div class="db-card__head"><h2>Quick actions</h2></div>
        <div class="db-quick">
          <?php foreach ($dashQuick as [, $href, $label, $icon]) { ?><a href="<?= e($href) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg><?= e($label) ?></a><?php } ?>
        </div>
      </section>
      <?php } ?>

      <section class="panel-card db-card">
        <div class="db-card__head"><h2>Recent activity</h2></div>
        <?php if (!$dashActivity) { ?><div class="db-empty">No activity in this period.</div><?php } else { ?>
          <ol class="db-feed">
            <?php foreach (array_slice($dashActivity, 0, 6) as $row) { ?>
              <li><span class="db-feed__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><?= $dashActivityIcon[$row['kind']] ?? $dashActivityIcon['Event created'] ?></svg></span><span><strong><?= e($row['kind']) ?></strong><small><?= e($row['label']) ?></small></span><time datetime="<?= e(date('c', strtotime((string) $row['happened']))) ?>"><?= e(date('M j, g:i A', strtotime((string) $row['happened']))) ?></time></li>
            <?php } ?>
          </ol>
        <?php } ?>
      </section>
    </aside>
  </div>

  <details class="panel-card db-insights">
    <summary><span><strong>More insights</strong><small>Attendance by event, event status, sections and all figures</small></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg></summary>
    <div class="db-figures">
      <?php foreach ($dashKpis as $key => $meta) { ?><div><span><?= e($meta[0]) ?></span><strong><?= $dashAvailable ? number_format($dashMetrics[$key]) : '—' ?></strong><small><?= e($meta[1]) ?></small></div><?php } ?>
    </div>
    <div class="db-charts">
      <?php foreach (['attendance' => ['Attendance by event', 'Top events by check-ins.'], 'status' => ['Event status', 'Events dated in this period.'], 'sections' => ['Course, year & section', 'Registrations per section.']] as $id => $meta) { ?>
        <section class="db-chart-card"><h3><?= e($meta[0]) ?></h3><p><?= e($meta[1]) ?></p><div class="dash-chart" data-dash-chart="<?= e($id) ?>"></div></section>
      <?php } ?>
    </div>
    <p class="db-notes"><strong>About these numbers:</strong> registration status is its current value; attendance counts distinct event/participant pairs by scan time; "Semester" uses calendar half-years. Upcoming events and pending review ignore the period filter.</p>
  </details>

  <?php if ($dashAdmin) { ?><details class="panel-card dash-notes dash-gallery db-gallery"><summary>Highlight gallery (images per event)</summary><p>Upload images for an existing event (JPEG, PNG, WebP or GIF; up to 8 MB each).</p><form method="post" enctype="multipart/form-data" class="dash-filters"><input type="hidden" name="csrf" value="<?= e($_SESSION['dashboard_csrf']) ?>"><label>Event ID<input type="number" name="event_id" required></label><label>Images<input type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required></label><button name="upload_images" value="1">Upload images</button></form><form method="post" class="dash-filters" data-dash-delete-image><input type="hidden" name="csrf" value="<?= e($_SESSION['dashboard_csrf']) ?>"><label>Image ID<input type="number" name="remove_id" required></label><button>Remove image</button></form></details><?php } ?>
</main>
<script type="application/json" id="dash-data"><?= json_encode(['charts'=>$dashCharts,'available'=>$dashAvailable,'start'=>$dashBounds[0],'end'=>$dashBounds[1]], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
</body></html>
