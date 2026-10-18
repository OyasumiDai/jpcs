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
<?php require __DIR__ . '/includes/admin_head.php'; ?><script src="js/admin-dashboard.js" defer></script></head>
<body class="app-page admin-page">
<?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
<main class="content dash-page">
  <header class="dash-header"><div><p class="dash-eyebrow">JPCS / OVERVIEW</p><h1>Dashboard</h1><p>Organization activity, registrations and event attendance.</p></div><div class="dash-actions"><a class="dash-button" href="?<?= e($dashQuery) ?>&amp;export=csv">Export CSV</a><button type="button" data-dash-print>Print / Save PDF</button></div></header>
  <form class="panel-card dash-filters" method="get"><label>Reporting period<select name="period" id="dash-period"><?php foreach (['today'=>'Today','7'=>'Last 7 days','30'=>'Last 30 days','semester'=>'Semester (Jan–Jun / Jul–Dec to date)','custom'=>'Custom range'] as $value=>$label) { ?><option value="<?= e($value) ?>" <?= $dashPeriod===(string)$value?'selected':'' ?>><?= e($label) ?></option><?php } ?></select></label><label>From<input type="date" name="start" value="<?= e($dashStart->format('Y-m-d')) ?>"></label><label>Through<input type="date" name="end" value="<?= e($dashEnd->format('Y-m-d')) ?>"></label><button type="submit">Apply filter</button></form>
  <?php if ($dashError || $dashDataError) { ?><p class="dash-alert" role="alert"><?= e($dashError ?: $dashDataError) ?></p><?php } ?>
  <p class="dash-context"><?= e($dashStart->format('M j, Y')) ?> – <?= e($dashEnd->format('M j, Y')) ?> · Prior: <?= e($dashPrior[0]) ?> – <?= e($dashStart->modify('-1 day')->format('Y-m-d')) ?> · <?= $dashAdmin?'Advisor':'Officer' ?> view · Organization-wide event data</p>
  <section class="dash-kpis" aria-label="Key performance indicators"><?php foreach ($dashKpis as $key=>$meta) { ?><article class="panel-card dash-kpi"><h2><?= e($meta[0]) ?></h2><strong><?= $dashAvailable?number_format($dashMetrics[$key]):'—' ?></strong><p><?= e(dash_trend($key)) ?></p><small><?= e($meta[1]) ?></small></article><?php } ?></section>
  <div class="dashboard-grid dash-grid">
  <?php foreach (['daily'=>['Registrations over time','Daily registration totals; dates with no registrations have zero entries.'], 'attendance'=>['Attendance by event','Top 12 events by distinct check-ins during the selected period.'], 'status'=>['Event status','Current status of events scheduled within the selected period.'], 'sections'=>['Course, year & section','Registration counts grouped by the stored course / year / section value.']] as $id=>$meta) { ?>
    <section class="panel-card dash-card"><h2><?= e($meta[0]) ?></h2><p class="dash-muted"><?= e($meta[1]) ?></p><div class="dash-chart" data-dash-chart="<?= e($id) ?>"></div><details><summary>View data table / legend</summary><div class="dash-table-wrap"><table><thead><tr><th>Category</th><th>Count</th></tr></thead><tbody><?php foreach ($dashCharts[$id] as $row) { ?><tr><td><?= e($row['label']) ?></td><td><?= (int)$row['value'] ?></td></tr><?php } ?><?php if (!$dashCharts[$id]) { ?><tr><td colspan="2"><?= $dashAvailable?'No records in this period.':'Data unavailable.' ?></td></tr><?php } ?></tbody></table></div></details></section>
  <?php } ?>
  <section class="panel-card dash-card"><div class="dash-section-head"><h2>Upcoming events</h2><a href="events.php">Manage events</a></div><p class="dash-muted">Next six events from today, across all reporting periods.</p><?php foreach ($dashUpcoming as $event) { ?><article class="dash-row"><div><a href="register_list.php?event_id=<?= (int)$event['id'] ?>"><strong><?= e($event['title']) ?></strong></a><p><?= e($event['event_date']) ?> <?= e($event['event_time']) ?> · <?= e($event['location']) ?></p></div><span class="dash-badge"><?= (int)$event['registrations'] ?> registrations / <?= (int)$event['pending'] ?> pending</span></article><?php } ?><?php if (!$dashUpcoming) { ?><p class="dash-empty"><?= $dashAvailable?'No future events marked upcoming.':'Data unavailable.' ?></p><?php } ?></section>
  <section class="panel-card dash-card"><div class="dash-section-head"><h2>Pending review <span class="dash-badge"><?= $dashPendingTotal===null?'—':$dashPendingTotal ?></span></h2><a href="pending.php">View all</a></div><p class="dash-muted">Oldest six requests, across all dates. Reject removes the pending registration.</p><?php foreach ($dashPending as $row) { ?><article class="dash-row"><div><strong><?= e($row['student_name']) ?></strong><p><?= e($row['title']) ?> · <?= e($row['registered_at']) ?></p></div><form action="pending.php" method="post" class="dash-actions" data-dash-review><input type="hidden" name="csrf" value="<?= e($_SESSION['pending_csrf']) ?>"><input type="hidden" name="registration_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="event_id" value="<?= (int)$row['event_id'] ?>"><?php if ($row['event_status']==='upcoming') { ?><button name="action" value="confirm">Approve</button><?php } ?><button name="action" value="remove" class="dash-danger">Reject</button></form></article><?php } ?><?php if (!$dashPending) { ?><p class="dash-empty"><?= $dashAvailable?'No pending requests.':'Data unavailable.' ?></p><?php } ?></section>
  <section class="panel-card dash-card"><h2>Recent activity</h2><p class="dash-muted">Latest 10 recorded actions within your filter.</p><ol class="dash-feed"><?php foreach ($dashActivity as $row) { ?><li><strong><?= e($row['kind']) ?></strong><span><?= e($row['label']) ?></span><time><?= e($row['happened']) ?></time></li><?php } ?></ol><?php if (!$dashActivity) { ?><p class="dash-empty"><?= $dashAvailable?'No timestamped activity in this period.':'Data unavailable.' ?></p><?php } ?></section>
  <section class="panel-card dash-card"><h2>Quick actions</h2><div class="dash-quick"><a href="events.php">Manage events <span>→</span></a><a href="pending.php">Review registrations <span>→</span></a><a href="scanner.php">Open attendance scanner <span>→</span></a><a href="attendance.php">Attendance reports <span>→</span></a><a href="register_list.php">Registration lists <span>→</span></a><?php if ($dashAdmin) { ?><a href="accounts.php">Manage accounts <span>→</span></a><?php } ?></div></section>
  </div>
  <footer class="panel-card dash-notes"><strong>About these numbers</strong><p>Registration status is its current value, not status at the period end. Approval / rejection timestamps and historical status changes are not stored; no approval trend is inferred. Attendance counts distinct event / participant pairs by scan time, not unique students or an attendance rate. Semester uses calendar half-years because no academic calendar is stored. Times use the application/database timezone. Upcoming and pending lists ignore the reporting filter.</p></footer>
  <?php if ($dashAdmin) { ?><details class="panel-card dash-notes dash-gallery"><summary>Legacy highlight gallery</summary><p>Upload images for an existing event (JPEG, PNG, WebP or GIF; up to 8 MB each). Existing gallery POST names remain supported and require this session’s CSRF token.</p><form method="post" enctype="multipart/form-data" class="dash-filters"><input type="hidden" name="csrf" value="<?= e($_SESSION['dashboard_csrf']) ?>"><label>Event ID<input type="number" name="event_id" required></label><label>Images<input type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required></label><button name="upload_images" value="1">Upload images</button></form><form method="post" class="dash-filters" data-dash-delete-image><input type="hidden" name="csrf" value="<?= e($_SESSION['dashboard_csrf']) ?>"><label>Image ID<input type="number" name="remove_id" required></label><button>Remove image</button></form></details><?php } ?>
</main>
<script type="application/json" id="dash-data"><?= json_encode(['charts'=>$dashCharts,'available'=>$dashAvailable,'start'=>$dashBounds[0],'end'=>$dashBounds[1]], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
</body></html>
