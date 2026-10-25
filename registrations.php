<?php
declare(strict_types=1);
// Registrations & Attendance: one place for pending review, the registered list and attendance per section.
// Replaces pending.php, register_list.php, attendance.php, attendance_event.php and attendance_section.php.
require_once __DIR__ . '/admin_features.php';
require_once __DIR__ . '/includes/permissions.php';
permissions_require($conn, 'registrations');
require_once __DIR__ . '/includes/registration_confirm.php';
require_once __DIR__ . '/includes/masterlist.php';
require_once __DIR__ . '/includes/audit.php';

$regUser = af_user($conn, (int) $_SESSION['user_id']);
if (!in_array($regUser['role'] ?? '', ['admin_advisor', 'admin_adviser', 'officer'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$regTabs = ['pending' => 'Pending review', 'registered' => 'Registered', 'attendance' => 'Attendance'];
$regEventId = filter_var($_GET['event_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
$regTab = is_string($_GET['tab'] ?? null) && isset($regTabs[$_GET['tab']]) ? $_GET['tab'] : null;

/* ---------- Actions: confirm / resend / remove (POST, then redirect) ---------- */
if (request_method_is('POST')) {
    af_csrf();
    $id = filter_var($_POST['registration_id'] ?? '', FILTER_VALIDATE_INT);
    $eventId = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT);
    $action = (string) ($_POST['action'] ?? '');
    $back = 'registrations.php?event_id=' . (int) $eventId . '&tab=' . ($action === 'resend' ? 'registered' : 'pending');
    try {
        if (!$id || !$eventId || !in_array($action, ['confirm', 'resend', 'remove'], true)) throw new RuntimeException('Invalid registration action.');
        $lookup = $conn->prepare('SELECT r.student_name, r.student_id, e.title FROM registrations r JOIN events e ON e.id = r.event_id WHERE r.id = ? AND r.event_id = ?');
        $lookup->bind_param('ii', $id, $eventId);
        $lookup->execute();
        $target = $lookup->get_result()->fetch_assoc() ?: [];
        $lookup->close();
        if ($action === 'remove') {
            $stmt = $conn->prepare("DELETE FROM registrations WHERE id=? AND event_id=? AND status='pending'");
            $stmt->bind_param('ii', $id, $eventId);
            $stmt->execute();
            $removed = $stmt->affected_rows;
            $stmt->close();
            if ($removed !== 1) throw new RuntimeException('Only pending registrations can be removed.');
            $flash = ['success', 'Registration removed.'];
            audit_log($conn, 'registration.remove', 'Removed ' . ($target['student_name'] ?? 'a') . ' registration for ' . ($target['title'] ?? 'an event'), 'registration', (int) $id, ['Student ID' => [(string) ($target['student_id'] ?? ''), '']]);
        } else {
            $result = jpcs_confirm_registration($conn, (int) $id, (int) $eventId, $action, (string) $regUser['name']);
            $flash = [$result['type'], $result['message']];
            audit_log($conn, 'registration.' . $action, ($action === 'confirm' ? 'Confirmed ' : 'Re-sent confirmation to ') . ($target['student_name'] ?? 'a student') . ' for ' . ($target['title'] ?? 'an event'), 'registration', (int) $id, ['Control no.' => ['', $result['control_number']], 'Email' => ['', $result['type'] === 'success' ? 'sent' : 'failed']]);
        }
    } catch (Throwable $error) {
        error_log('JPCS registrations: ' . $error->getMessage());
        $flash = ['error', $error instanceof mysqli_sql_exception ? 'The database rejected this change. Please try again.' : $error->getMessage()];
    }
    $_SESSION['reg_flash'] = $flash;
    redirect($back);
}
$regFlash = $_SESSION['reg_flash'] ?? null;
unset($_SESSION['reg_flash']);

$attendanceJoin = 'LEFT JOIN (SELECT event_id, participant_id, MIN(scanned_at) AS first_scan FROM attendance GROUP BY event_id, participant_id) a
    ON a.event_id = r.event_id AND a.participant_id = r.control_number';

/* ---------- Event picker data ---------- */
$regEvents = $conn->query("SELECT e.id, e.title, e.event_date, e.event_time, e.location, e.poster, e.status,
        COALESCE(SUM(r.status = 'pending'), 0) AS pending,
        COALESCE(SUM(r.status = 'confirmed'), 0) AS confirmed,
        COALESCE(SUM(r.status = 'confirmed' AND a.participant_id IS NOT NULL), 0) AS attended
    FROM events e
    LEFT JOIN registrations r ON r.event_id = e.id
    $attendanceJoin
    GROUP BY e.id
    ORDER BY e.status = 'done', CASE WHEN e.status = 'upcoming' THEN e.event_date END ASC, e.event_date DESC")->fetch_all(MYSQLI_ASSOC);

$regEvent = null;
foreach ($regEvents as $candidate) if ((int) $candidate['id'] === $regEventId) $regEvent = $candidate;

/* ---------- Selected event data ---------- */
$pendingRows = $confirmedRows = $sectionStats = [];
$listed = [];
if ($regEvent) {
    $stmt = $conn->prepare("SELECT r.*, a.first_scan FROM registrations r $attendanceJoin WHERE r.event_id = ? ORDER BY r.student_name ASC, r.id ASC");
    $stmt->bind_param('i', $regEventId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if ($row['status'] === 'pending') { $pendingRows[] = $row; continue; }
        if ($row['status'] !== 'confirmed') continue;
        $confirmedRows[] = $row;
        $section = trim((string) $row['course_year_section']) !== '' ? (string) $row['course_year_section'] : 'No section';
        $sectionStats[$section] ??= ['registered' => 0, 'attended' => 0];
        $sectionStats[$section]['registered']++;
        if ($row['first_scan'] !== null) $sectionStats[$section]['attended']++;
    }
    $stmt->close();
    usort($pendingRows, static fn($a, $b) => strcmp((string) $a['registered_at'], (string) $b['registered_at']));
    ksort($sectionStats, SORT_NATURAL);
    $listed = masterlist_lookup_many($conn, array_column($pendingRows, 'student_id'));
    $regTab ??= $pendingRows ? 'pending' : 'registered';
}
$regAttended = count(array_filter($confirmedRows, static fn($r) => $r['first_scan'] !== null));
$regRate = $confirmedRows ? (int) round($regAttended / count($confirmedRows) * 100) : 0;
$regSectionParam = is_string($_GET['section'] ?? null) ? trim($_GET['section']) : '';
$regYear = static fn(string $section): string => preg_match('/(\d\w{2} Year)/', $section, $m) ? $m[1] : 'Other';

af_head('Registrations & Attendance');
?>
<?php if ($regFlash) { ?><p class="<?= $regFlash[0] === 'success' ? 'success' : 'error' ?>" role="<?= $regFlash[0] === 'success' ? 'status' : 'alert' ?>"><?= e($regFlash[1]) ?></p><?php } ?>

<?php if (!$regEvent) { ?>
  <p class="rx-intro">Review new registrations, see who registered and track attendance — all in one place. Pick an event to start.</p>
  <?php if ($regEventId) { ?><p class="error" role="alert">That event was not found. Choose one below.</p><?php } ?>
  <div class="rx-picker-tools">
    <label class="rx-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg><span class="visually-hidden">Search events</span><input type="search" placeholder="Search events" data-rx-event-search></label>
    <div class="rx-segment" role="group" aria-label="Event status">
      <button type="button" data-rx-event-filter="all" aria-pressed="true">All</button>
      <button type="button" data-rx-event-filter="upcoming" aria-pressed="false">Upcoming</button>
      <button type="button" data-rx-event-filter="done" aria-pressed="false">Done</button>
    </div>
  </div>
  <?php if (!$regEvents) { ?><div class="empty-state">No events yet. Create one in Event Management.</div><?php } ?>
  <div class="rx-events">
    <?php foreach ($regEvents as $i => $event) { $rate = (int) $event['confirmed'] ? (int) round($event['attended'] / $event['confirmed'] * 100) : 0; ?>
      <a class="rx-event" href="registrations.php?event_id=<?= (int) $event['id'] ?>" data-rx-event data-status="<?= e($event['status']) ?>" data-search="<?= e(mb_strtolower($event['title'] . ' ' . $event['location'])) ?>" style="--rx-delay: <?= min($i, 10) * 50 ?>ms">
        <span class="rx-event__poster"><img src="<?= e(image_url($event['poster'])) ?>" alt="" loading="lazy">
          <span class="status-badge is-<?= e($event['status']) ?>"><?= e(ucfirst((string) $event['status'])) ?></span>
          <?php if ((int) $event['pending'] > 0) { ?><span class="rx-event__pending"><?= (int) $event['pending'] ?> pending</span><?php } ?>
        </span>
        <span class="rx-event__body">
          <strong><?= e($event['title']) ?></strong>
          <small><?= e(date('D, M j, Y', strtotime((string) $event['event_date']))) ?><?= trim((string) $event['location']) !== '' ? ' · ' . e($event['location']) : '' ?></small>
          <span class="rx-event__stats"><span><b><?= (int) $event['confirmed'] ?></b> registered</span><span><b><?= (int) $event['attended'] ?></b> attended</span></span>
          <span class="rx-bar" aria-label="<?= $rate ?>% attended"><span style="width: <?= $rate ?>%"></span></span>
        </span>
      </a>
    <?php } ?>
  </div>
  <div class="empty-state" data-rx-events-empty hidden>No events match your search.</div>

<?php } else { $time = substr((string) ($regEvent['event_time'] ?? ''), 0, 5); ?>
  <a class="btn btn-ghost rx-back" href="registrations.php">&larr; All events</a>
  <section class="rx-event-head">
    <img src="<?= e(image_url($regEvent['poster'])) ?>" alt="">
    <div>
      <span class="status-badge is-<?= e($regEvent['status']) ?>"><?= e(ucfirst((string) $regEvent['status'])) ?></span>
      <h2><?= e($regEvent['title']) ?></h2>
      <p><?= e(date('l, F j, Y', strtotime((string) $regEvent['event_date']))) ?><?= $time !== '' ? ' · ' . e(date('g:i A', strtotime($time))) : '' ?><?= trim((string) $regEvent['location']) !== '' ? ' · ' . e($regEvent['location']) : '' ?></p>
    </div>
    <div class="rx-event-head__actions">
      <?php if ($regEvent['status'] === 'upcoming') { ?><a class="btn" href="scanner.php?event_id=<?= (int) $regEventId ?>">Open scanner</a><?php } ?>
      <button type="button" class="btn" data-rx-print>Print list</button>
    </div>
  </section>

  <div class="rx-stats">
    <div class="stat-tile is-warn"><span>Pending review</span><strong><?= count($pendingRows) ?></strong></div>
    <div class="stat-tile"><span>Registered</span><strong><?= count($confirmedRows) ?></strong></div>
    <div class="stat-tile is-good"><span>Attended</span><strong><?= $regAttended ?></strong></div>
    <div class="stat-tile is-muted"><span><?= $regEvent['status'] === 'done' ? 'Did not attend' : 'Not yet scanned' ?></span><strong><?= count($confirmedRows) - $regAttended ?></strong></div>
    <div class="stat-tile rx-rate" style="--rx-rate: <?= $regRate ?>"><span>Attendance rate</span><strong><?= $regRate ?>%</strong><i class="rx-ring" aria-hidden="true"></i></div>
  </div>

  <nav class="rx-tabs" role="tablist" aria-label="Event views">
    <?php foreach ($regTabs as $key => $label) { $count = ['pending' => count($pendingRows), 'registered' => count($confirmedRows), 'attendance' => count($sectionStats)][$key]; ?>
      <a role="tab" id="rx-tab-<?= $key ?>" href="registrations.php?event_id=<?= (int) $regEventId ?>&amp;tab=<?= $key ?>" data-rx-tab="<?= $key ?>" aria-controls="rx-panel-<?= $key ?>" aria-selected="<?= $regTab === $key ? 'true' : 'false' ?>"><?= e($label) ?> <span><?= $count ?></span></a>
    <?php } ?>
  </nav>

  <?php /* Pending review */ ?>
  <section class="rx-panel" id="rx-panel-pending" role="tabpanel" aria-labelledby="rx-tab-pending" data-rx-panel="pending"<?= $regTab === 'pending' ? '' : ' hidden' ?>>
    <?php if (!$pendingRows) { ?>
      <div class="rx-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"></path></svg></span><strong>All caught up</strong><p>No registrations are waiting for review.</p></div>
    <?php } else { ?>
      <?php if ($regEvent['status'] !== 'upcoming') { ?><p class="warning rx-note">This event is done, so pending registrations can no longer be confirmed — only removed.</p><?php } ?>
      <p class="rx-help">Confirming sends the student their QR code and event pass by email. Students on the masterlist are marked so you can confirm them quickly.</p>
      <div class="table-wrapper">
        <table data-no-enhance class="rx-table">
          <thead><tr><th scope="col">Student</th><th scope="col">Student ID</th><th scope="col">Section</th><th scope="col">Contact</th><th scope="col">Registered</th><th scope="col">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($pendingRows as $row) { $on = $listed[masterlist_normalize_id((string) $row['student_id'])] ?? null; ?>
            <tr>
              <td><strong><?= e($row['student_name']) ?></strong><?php if ($on) { ?><span class="ml-badge is-listed">On masterlist</span><?php } else { ?><span class="ml-badge is-unlisted">Not on masterlist</span><?php } ?></td>
              <td><?= e($row['student_id']) ?></td>
              <td><?= e($row['course_year_section']) ?></td>
              <td><span class="rx-contact"><?= e($row['email']) ?><small><?= e($row['contact']) ?></small></span></td>
              <td><time datetime="<?= e(date('c', strtotime((string) $row['registered_at']))) ?>"><?= e(date('M j, g:i A', strtotime((string) $row['registered_at']))) ?></time></td>
              <td><div class="row-actions">
                <?php if ($regEvent['status'] === 'upcoming') { ?>
                <form method="post" class="inline-action"><?php af_field(); ?><input type="hidden" name="event_id" value="<?= (int) $regEventId ?>"><input type="hidden" name="registration_id" value="<?= (int) $row['id'] ?>"><button type="submit" name="action" value="confirm">Confirm</button></form>
                <?php } ?>
                <form method="post" class="inline-action" onsubmit="return confirm('Remove <?= e(addslashes((string) $row['student_name'])) ?>\'s registration?');"><?php af_field(); ?><input type="hidden" name="event_id" value="<?= (int) $regEventId ?>"><input type="hidden" name="registration_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="action" value="remove"><button type="submit" class="btn-danger">Remove</button></form>
              </div></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </section>

  <?php /* Registered list with filters */ ?>
  <section class="rx-panel" id="rx-panel-registered" role="tabpanel" aria-labelledby="rx-tab-registered" data-rx-panel="registered" data-rx-section="<?= e($regSectionParam) ?>"<?= $regTab === 'registered' ? '' : ' hidden' ?>>
    <?php if (!$confirmedRows) { ?>
      <div class="rx-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M15 19c-.6-2.8-2.6-4.3-6-4.3S3.6 16.2 3 19"></path><circle cx="9" cy="8" r="3.3"></circle></svg></span><strong>No confirmed registrations yet</strong><p>Students appear here once their registration is confirmed.</p></div>
    <?php } else { $years = array_values(array_unique(array_map(static fn($r) => $regYear((string) $r['course_year_section']), $confirmedRows))); sort($years, SORT_NATURAL); ?>
      <div class="rx-filters">
        <label class="rx-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg><span class="visually-hidden">Search students</span><input type="search" placeholder="Search name, ID, email or control no." data-rx-search></label>
        <div class="rx-chips" role="group" aria-label="Year level">
          <button type="button" class="rx-chip" data-rx-year="" aria-pressed="true">All years</button>
          <?php foreach ($years as $year) { ?><button type="button" class="rx-chip" data-rx-year="<?= e($year) ?>" aria-pressed="false"><?= e($year) ?></button><?php } ?>
        </div>
        <label class="rx-select"><span class="visually-hidden">Section</span><select data-rx-section-select><option value="">All sections</option><?php foreach (array_keys($sectionStats) as $section) { ?><option value="<?= e($section) ?>"><?= e($section) ?></option><?php } ?></select></label>
        <div class="rx-segment" role="group" aria-label="Attendance">
          <button type="button" data-rx-att="" aria-pressed="true">All</button>
          <button type="button" data-rx-att="1" aria-pressed="false">Attended</button>
          <button type="button" data-rx-att="0" aria-pressed="false"><?= $regEvent['status'] === 'done' ? 'Absent' : 'Not yet' ?></button>
        </div>
      </div>
      <div class="rx-list-meta"><span data-rx-count></span><button type="button" class="btn btn-ghost" data-rx-csv>Export CSV</button></div>
      <div class="table-wrapper rx-print-area">
        <h2 class="rx-print-title"><?= e($regEvent['title']) ?> — Registered students <span data-rx-print-filter></span></h2>
        <table data-no-enhance class="rx-table" data-rx-table>
          <thead><tr><th scope="col">#</th><th scope="col">Student</th><th scope="col">Student ID</th><th scope="col">Section</th><th scope="col">Email</th><th scope="col">Control no.</th><th scope="col">Attendance</th><th scope="col" class="rx-no-print">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($confirmedRows as $row) { $section = trim((string) $row['course_year_section']) !== '' ? (string) $row['course_year_section'] : 'No section'; $att = $row['first_scan'] !== null; ?>
            <tr data-rx-row data-year="<?= e($regYear($section)) ?>" data-section="<?= e($section) ?>" data-att="<?= $att ? '1' : '0' ?>" data-search="<?= e(mb_strtolower($row['student_name'] . ' ' . $row['student_id'] . ' ' . $row['email'] . ' ' . $row['control_number'])) ?>">
              <td class="rx-num"></td>
              <td><strong><?= e($row['student_name']) ?></strong><?php if ($row['confirmed_by'] === 'Masterlist (auto)') { ?><small class="rx-auto">Auto-confirmed</small><?php } ?></td>
              <td><?= e($row['student_id']) ?></td>
              <td><?= e($section) ?></td>
              <td><?= e($row['email']) ?></td>
              <td><code><?= e($row['control_number']) ?></code></td>
              <td><?php if ($att) { ?><span class="status-badge attended">Attended<small><?= e(date('g:i A', strtotime((string) $row['first_scan']))) ?></small></span><?php } else { ?><span class="status-badge notyet"><?= $regEvent['status'] === 'done' ? 'Absent' : 'Not yet' ?></span><?php } ?></td>
              <td class="rx-no-print"><form method="post" class="inline-action"><?php af_field(); ?><input type="hidden" name="event_id" value="<?= (int) $regEventId ?>"><input type="hidden" name="registration_id" value="<?= (int) $row['id'] ?>"><button type="submit" name="action" value="resend" class="btn-secondary rx-small">Resend email</button></form></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        <div class="rx-empty rx-empty--inline" data-rx-none hidden><strong>No students match these filters.</strong></div>
      </div>
    <?php } ?>
  </section>

  <?php /* Attendance per section */ ?>
  <section class="rx-panel" id="rx-panel-attendance" role="tabpanel" aria-labelledby="rx-tab-attendance" data-rx-panel="attendance"<?= $regTab === 'attendance' ? '' : ' hidden' ?>>
    <?php if (!$sectionStats) { ?>
      <div class="rx-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 8V5h3M17 5h3v3M20 16v3h-3M7 19H4v-3"></path></svg></span><strong>No attendance to show yet</strong><p>Attendance appears per section once students are confirmed and scanned.</p></div>
    <?php } else { ?>
      <p class="rx-help">Select a section to see its students.</p>
      <div class="rx-sections">
        <?php foreach ($sectionStats as $section => $stat) { $pct = (int) round($stat['attended'] / max(1, $stat['registered']) * 100); ?>
          <button type="button" class="rx-section" data-rx-open-section="<?= e($section) ?>">
            <span class="rx-section__name"><?= e($section) ?></span>
            <span class="rx-section__nums"><b><?= (int) $stat['attended'] ?></b> / <?= (int) $stat['registered'] ?> attended</span>
            <span class="rx-bar"><span style="width: <?= $pct ?>%"></span></span>
            <span class="rx-section__pct"><?= $pct ?>%</span>
          </button>
        <?php } ?>
      </div>
    <?php } ?>
  </section>
<?php } ?>
<script src="js/registrations.js?v=<?= filemtime(__DIR__ . '/js/registrations.js') ?>" defer></script>
<?php af_end();
