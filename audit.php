<?php
declare(strict_types=1);
// Audit trail viewer. Advisers see everyone's actions; officers see only their own.
require_once __DIR__ . '/admin_features.php';
require_once __DIR__ . '/includes/permissions.php';
permissions_require($conn, 'audit');
require_once __DIR__ . '/includes/audit.php';

audit_ensure_schema($conn);
$auditUser = af_user($conn, (int) $_SESSION['user_id']);
if (!in_array($auditUser['role'] ?? '', ['admin_advisor', 'admin_adviser', 'officer'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$auditAdmin = in_array($auditUser['role'], ['admin_advisor', 'admin_adviser'], true);

/* ---------- Filters ---------- */
$q = trim((string) ($_GET['q'] ?? ''));
$category = is_string($_GET['category'] ?? null) && isset(AUDIT_CATEGORIES[$_GET['category']]) ? $_GET['category'] : '';
$person = $auditAdmin ? (int) ($_GET['user'] ?? 0) : (int) $auditUser['id'];
$validDate = static fn($v) => is_string($v) && ($d = DateTimeImmutable::createFromFormat('!Y-m-d', $v)) && $d->format('Y-m-d') === $v ? $v : '';
$from = $validDate($_GET['from'] ?? '');
$to = $validDate($_GET['to'] ?? '');

$where = ['1=1'];
$params = [];
$types = '';
if ($person > 0) { $where[] = 'user_id = ?'; $params[] = $person; $types .= 'i'; }
if (!$auditAdmin) $where[] = "action <> 'auth.login_failed'";
if ($category !== '') { $where[] = 'action LIKE ?'; $params[] = $category . '.%'; $types .= 's'; }
if ($from !== '') { $where[] = 'created_at >= ?'; $params[] = $from . ' 00:00:00'; $types .= 's'; }
if ($to !== '') { $where[] = 'created_at < ? + INTERVAL 1 DAY'; $params[] = $to; $types .= 's'; }
if ($q !== '') { $where[] = '(summary LIKE ? OR actor_name LIKE ? OR details LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); $types .= 'sss'; }
$sqlWhere = implode(' AND ', $where);

$run = static function (string $sql) use ($conn, $types, $params): mysqli_result {
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
};

$changesText = static function (?string $json): string {
    $details = $json ? json_decode($json, true) : null;
    if (!is_array($details)) return '';
    $parts = [];
    foreach ($details as $field => $pair) {
        if (is_array($pair) && count($pair) === 2) $parts[] = $pair[0] === '' ? "$field: {$pair[1]}" : "$field: {$pair[0]} → {$pair[1]}";
    }
    return implode('; ', $parts);
};

/* ---------- CSV export of the filtered log ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    $rows = $run("SELECT * FROM audit_log WHERE $sqlWhere ORDER BY created_at DESC, id DESC LIMIT 10000");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="jpcs-audit-trail-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date & time', 'Person', 'Role', 'Category', 'Action', 'Summary', 'Changes', 'IP address']);
    while ($row = $rows->fetch_assoc()) {
        fputcsv($out, [$row['created_at'], $row['actor_name'], role_label($row['actor_role']), audit_category($row['action']), $row['action'], $row['summary'], $changesText($row['details']), $row['ip_address']]);
    }
    exit;
}

/* ---------- Page data ---------- */
$total = (int) $run("SELECT COUNT(*) AS n FROM audit_log WHERE $sqlWhere")->fetch_assoc()['n'];
$perPage = 40;
$pages = max(1, (int) ceil($total / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;
$entries = $run("SELECT * FROM audit_log WHERE $sqlWhere ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset")->fetch_all(MYSQLI_ASSOC);
$today = (int) $conn->query("SELECT COUNT(*) AS n FROM audit_log WHERE created_at >= CURDATE()" . ($auditAdmin ? '' : ' AND user_id = ' . (int) $auditUser['id']))->fetch_assoc()['n'];
$failed = $auditAdmin ? (int) $conn->query("SELECT COUNT(*) AS n FROM audit_log WHERE action = 'auth.login_failed' AND created_at >= NOW() - INTERVAL 1 DAY")->fetch_assoc()['n'] : 0;
$people = $auditAdmin ? $conn->query("SELECT id, name FROM users ORDER BY name")->fetch_all(MYSQLI_ASSOC) : [];

$filters = array_filter(['q' => $q, 'category' => $category, 'user' => $auditAdmin && $person ? $person : '', 'from' => $from, 'to' => $to], static fn($v) => $v !== '' && $v !== 0);
$link = static fn(array $extra = []) => 'audit.php?' . http_build_query($filters + $extra);
$dayLabel = static function (string $date): string {
    $day = date('Y-m-d', strtotime($date));
    return $day === date('Y-m-d') ? 'Today' : ($day === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : date('l, F j, Y', strtotime($date)));
};
$icons = [
    'auth' => '<path d="M10 5H5v14h5M14 8l4 4-4 4M9 12h9"></path>',
    'account' => '<circle cx="12" cy="8" r="3"></circle><path d="M5 20c.8-3.3 3.2-5 7-5s6.2 1.7 7 5"></path>',
    'profile' => '<circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c.8-3.6 3.3-5.5 7-5.5s6.2 1.9 7 5.5"></path>',
    'event' => '<path d="M4 6h16v14H4z"></path><path d="M8 4v4M16 4v4M4 10h16"></path>',
    'registration' => '<path d="M15 19c-.6-2.8-2.6-4.3-6-4.3S3.6 16.2 3 19"></path><circle cx="9" cy="8" r="3.3"></circle><path d="M18 8v6M15 11h6"></path>',
    'attendance' => '<path d="M4 8V5h3M17 5h3v3M20 16v3h-3M7 19H4v-3"></path><path d="m8.5 12 2.5 2.5 4.5-5"></path>',
    'masterlist' => '<path d="M6 3h9l4 4v14H6z"></path><path d="M14 3v5h5M9 12h7M9 16h7"></path>',
    'officer' => '<circle cx="9" cy="8" r="3"></circle><path d="M3 20c.6-3.3 2.6-5 6-5s5.4 1.7 6 5"></path><path d="M16 5.5a3 3 0 0 1 0 5.8M17 15c2.1.5 3.4 1.9 4 5"></path>',
];

af_head('Audit Trail');
?>
<p class="ml-intro"><?= $auditAdmin ? 'Every change made in the adviser and officer panels: who did it, when, and what changed.' : 'Your own activity in the officer panel: what you changed and when.' ?></p>

<div class="au-stats">
  <div class="stat-tile"><span>Matching entries</span><strong><?= number_format($total) ?></strong></div>
  <div class="stat-tile is-good"><span>Today</span><strong><?= $today ?></strong></div>
  <?php if ($auditAdmin) { ?><a class="stat-tile is-warn au-stat-link" href="audit.php?category=auth&amp;q=Failed"><span>Failed sign-ins (24 h)</span><strong><?= $failed ?></strong></a><?php } ?>
</div>

<form method="get" class="au-filters" role="search">
  <label class="rx-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg><span class="visually-hidden">Search</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search actions, names or changes"></label>
  <label>Category<select name="category"><option value="">All categories</option><?php foreach (AUDIT_CATEGORIES as $key => $label) { ?><option value="<?= e($key) ?>"<?= $key === $category ? ' selected' : '' ?>><?= e($label) ?></option><?php } ?></select></label>
  <?php if ($auditAdmin) { ?><label>Person<select name="user"><option value="">Everyone</option><?php foreach ($people as $p) { ?><option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === $person ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php } ?></select></label><?php } ?>
  <label>From<input type="date" name="from" value="<?= e($from) ?>" max="<?= date('Y-m-d') ?>"></label>
  <label>To<input type="date" name="to" value="<?= e($to) ?>" max="<?= date('Y-m-d') ?>"></label>
  <div class="au-filter-actions">
    <button type="submit">Apply</button>
    <?php if ($filters) { ?><a class="btn btn-ghost" href="audit.php">Clear</a><?php } ?>
    <a class="btn" href="<?= e($link(['export' => 'csv'])) ?>">Export CSV</a>
  </div>
</form>

<?php if (!$entries) { ?>
  <div class="rx-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 8v4l2.5 2"></path><circle cx="12" cy="12" r="8"></circle></svg></span><strong><?= $filters ? 'No activity matches these filters' : 'No activity recorded yet' ?></strong><p>Actions are recorded from now on: sign-ins, accounts, events, registrations, attendance, masterlist and officer changes.</p></div>
<?php } else { ?>
  <div class="au-timeline">
    <?php $shownDay = null; foreach ($entries as $entry) {
      $cat = strstr($entry['action'], '.', true) ?: $entry['action'];
      $day = $dayLabel($entry['created_at']);
      $details = $entry['details'] ? json_decode($entry['details'], true) : [];
      $failedLogin = $entry['action'] === 'auth.login_failed';
      if ($day !== $shownDay) { $shownDay = $day; ?><h2 class="au-day"><?= e($day) ?></h2><?php } ?>
      <article class="au-entry is-<?= e($cat) ?><?= $failedLogin ? ' is-alert' : '' ?>">
        <span class="au-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><?= $icons[$cat] ?? $icons['event'] ?></svg></span>
        <div class="au-main">
          <p class="au-summary"><?= e($entry['summary']) ?></p>
          <p class="au-meta">
            <span class="au-who"><?= e($entry['actor_name']) ?><?php if ($entry['actor_role'] !== '') { ?> <small>(<?= e(role_label($entry['actor_role'])) ?>)</small><?php } ?></span>
            <span class="au-tag"><?= e(audit_category($entry['action'])) ?></span>
            <?php if ($auditAdmin && $entry['ip_address'] !== '') { ?><span title="<?= e($entry['user_agent']) ?>">IP <?= e($entry['ip_address']) ?></span><?php } ?>
          </p>
          <?php if (is_array($details) && $details) { ?>
            <details class="au-details">
              <summary><?= count($details) ?> detail<?= count($details) === 1 ? '' : 's' ?></summary>
              <dl>
                <?php foreach ($details as $field => $pair) { if (!is_array($pair) || count($pair) !== 2) continue; ?>
                  <div><dt><?= e((string) $field) ?></dt><dd><?php if ((string) $pair[0] !== '') { ?><del><?= e(mb_strimwidth((string) $pair[0], 0, 140, '…')) ?></del> <span aria-hidden="true">→</span> <?php } ?><ins><?= e(mb_strimwidth((string) $pair[1], 0, 140, '…')) ?></ins></dd></div>
                <?php } ?>
              </dl>
            </details>
          <?php } ?>
        </div>
        <time class="au-time" datetime="<?= e(date('c', strtotime($entry['created_at']))) ?>" title="<?= e(date('M j, Y g:i:s A', strtotime($entry['created_at']))) ?>"><?= e(date('g:i A', strtotime($entry['created_at']))) ?></time>
      </article>
    <?php } ?>
  </div>
  <?php if ($pages > 1) { ?>
    <nav class="admin-table-pagination ml-pagination" aria-label="Audit pages">
      <span>Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> entries</span>
      <?php if ($page > 1) { ?><a class="btn" href="<?= e($link(['page' => $page - 1])) ?>">Newer</a><?php } ?>
      <?php if ($page < $pages) { ?><a class="btn" href="<?= e($link(['page' => $page + 1])) ?>">Older</a><?php } ?>
    </nav>
  <?php } ?>
<?php } ?>
<?php af_end();
