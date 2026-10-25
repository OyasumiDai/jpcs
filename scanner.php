<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_login();
require_once __DIR__ . '/includes/permissions.php';
permissions_require($conn, 'scanner');

if (empty($_SESSION['scanner_csrf'])) {
    $_SESSION['scanner_csrf'] = bin2hex(random_bytes(32));
}

$eventID = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$hasEventID = is_int($eventID);
$event = null;
if ($hasEventID) {
    $stmt = $conn->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->bind_param('i', $eventID);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

function scanner_ago(int $timestamp): string
{
    $seconds = max(0, time() - $timestamp);
    if ($seconds < 60) return 'just now';
    if ($seconds < 3600) return intdiv($seconds, 60) . ' min ago';
    if ($seconds < 86400) return intdiv($seconds, 3600) . ' hr ago';
    return date('M j', $timestamp);
}

function scanner_stats(mysqli $conn, int $eventId): array
{
    $stmt = $conn->prepare("SELECT
        (SELECT COUNT(*) FROM registrations WHERE event_id = ? AND status = 'confirmed') AS registered,
        (SELECT COUNT(*) FROM attendance WHERE event_id = ?) AS attended");
    $stmt->bind_param('ii', $eventId, $eventId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['registered' => (int) $row['registered'], 'attended' => (int) $row['attended']];
}

function scanner_person(array $registration, ?int $timestamp = null): array
{
    return [
        'name' => trim((string) ($registration['student_name'] ?? '')),
        'student_id' => (string) ($registration['student_id'] ?? ''),
        'course' => (string) ($registration['course_year_section'] ?? ''),
        'control' => (string) ($registration['control_number'] ?? ''),
        'time' => $timestamp ? date('g:i A', $timestamp) : '',
    ];
}

/** One row of the live feed; shared by the page and the scan response. */
function scanner_feed_item(array $row): string
{
    $name = trim((string) ($row['student_name'] ?? '')) ?: 'Unknown registration';
    $parts = preg_split('/[\s,]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $initials = strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : ''));
    $meta = array_filter([(string) ($row['student_id'] ?? ''), (string) ($row['course_year_section'] ?? '')]);
    $ts = (int) $row['ts'];
    ob_start(); ?>
<li class="sc-feed__item" data-sc-search="<?= e(strtolower($name . ' ' . implode(' ', $meta) . ' ' . $row['participant_id'])) ?>">
    <span class="sc-avatar" aria-hidden="true"><?= e($initials) ?></span>
    <span class="sc-feed__who"><strong><?= e($name) ?></strong><small><?= e(implode(' · ', $meta) ?: (string) $row['participant_id']) ?></small></span>
    <time datetime="<?= e(date('c', $ts)) ?>" title="<?= e(date('M j, Y g:i:s A', $ts)) ?>"><?= e(date('g:i A', $ts)) ?></time>
</li>
<?php return (string) ob_get_clean();
}

/** Records one scan and describes the outcome for the popup. */
function scanner_process(mysqli $conn, array $event, string $code): array
{
    $eventId = (int) $event['id'];
    if ($event['status'] === 'done') {
        return ['status' => 'closed', 'title' => 'Scanning is closed', 'message' => 'This event is already marked as done.'];
    }
    if ($code === '') {
        return ['status' => 'invalid', 'title' => 'Nothing was read', 'message' => 'The QR code was empty. Try scanning again.'];
    }

    $stmt = $conn->prepare('SELECT r.*, e.title AS event_title FROM registrations r LEFT JOIN events e ON e.id = r.event_id WHERE r.control_number = ? ORDER BY (r.event_id = ?) DESC LIMIT 1');
    $stmt->bind_param('si', $code, $eventId);
    $stmt->execute();
    $registration = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$registration) {
        return ['status' => 'invalid', 'title' => 'QR code not recognised', 'message' => 'No registration matches this code. Make sure it is a JPCS event pass.', 'student' => ['control' => $code]];
    }
    $person = scanner_person($registration);
    if ((int) $registration['event_id'] !== $eventId) {
        return ['status' => 'invalid', 'title' => 'Pass is for another event', 'message' => 'This pass was issued for ' . ($registration['event_title'] ?: 'a different event') . '.', 'student' => $person];
    }
    if ($registration['status'] !== 'confirmed') {
        return ['status' => 'invalid', 'title' => 'Registration not confirmed', 'message' => 'Confirm this registration in Registrations & Attendance before checking the student in.', 'student' => $person];
    }

    // The unique key (event_id, participant_id) makes the duplicate check safe across devices.
    $stmt = $conn->prepare('INSERT IGNORE INTO attendance (event_id, participant_id, scanned_at) VALUES (?, ?, NOW())');
    $stmt->bind_param('is', $eventId, $code);
    $stmt->execute();
    $inserted = $stmt->affected_rows === 1;
    $attendanceId = (int) $stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare('SELECT id, participant_id, UNIX_TIMESTAMP(scanned_at) AS ts FROM attendance WHERE event_id = ? AND participant_id = ?');
    $stmt->bind_param('is', $eventId, $code);
    $stmt->execute();
    $attendance = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $ts = (int) ($attendance['ts'] ?? time());

    if (!$inserted) {
        return ['status' => 'duplicate', 'title' => 'Already scanned', 'message' => 'This QR code was already used to check in ' . scanner_ago($ts) . '. Attendance was not recorded again.', 'student' => scanner_person($registration, $ts) + ['time_label' => 'First scanned']];
    }

    audit_log($conn, 'attendance.scan', 'Recorded attendance for ' . $registration['student_name'] . ' at ' . ($event['title'] ?? 'event'), 'attendance', $attendanceId, ['Control no.' => ['', $code]]);
    return [
        'status' => 'ok',
        'title' => 'Attendance recorded',
        'message' => 'Welcome to ' . $event['title'] . '!',
        'student' => scanner_person($registration, $ts) + ['time_label' => 'Time in'],
        'row' => scanner_feed_item($registration + $attendance),
    ];
}

if (request_method_is('POST') && $event) {
    $wantsJson = ($_POST['ajax'] ?? '') === '1';
    $code = trim((string) ($_POST['qrdata'] ?? ''));
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['scanner_csrf'], $_POST['csrf'])) {
        $result = ['status' => 'error', 'title' => 'Session expired', 'message' => 'Refresh the page and try again.'];
    } else {
        try {
            $result = scanner_process($conn, $event, mb_substr($code, 0, 50));
        } catch (Throwable $error) {
            error_log('Scanner: ' . $error->getMessage());
            $result = ['status' => 'error', 'title' => 'Could not save', 'message' => 'Attendance could not be saved. Please try again.'];
        }
    }
    $result['stats'] = scanner_stats($conn, (int) $eventID);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    unset($result['row']);
    $_SESSION['scanner_popup'] = $result;
    redirect('scanner.php?event_id=' . (int) $eventID);
}

$popup = $_SESSION['scanner_popup'] ?? null;
unset($_SESSION['scanner_popup']);

$feed = [];
$stats = ['registered' => 0, 'attended' => 0];
if ($event) {
    $stats = scanner_stats($conn, (int) $eventID);
    $stmt = $conn->prepare('SELECT a.id, a.participant_id, UNIX_TIMESTAMP(a.scanned_at) AS ts, r.student_name, r.student_id, r.course_year_section
        FROM attendance a
        LEFT JOIN registrations r ON TRIM(r.control_number) = TRIM(a.participant_id) AND r.event_id = a.event_id
        WHERE a.event_id = ?
        ORDER BY a.scanned_at DESC, a.id DESC');
    $stmt->bind_param('i', $eventID);
    $stmt->execute();
    $feed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$upcoming = [];
if (!$hasEventID) {
    $upcoming = $conn->query("SELECT e.*,
            (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = 'confirmed') AS registered,
            (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id) AS attended
        FROM events e WHERE e.status = 'upcoming' ORDER BY e.event_date ASC, e.event_time ASC")->fetch_all(MYSQLI_ASSOC);
}

$percent = static fn (int $attended, int $registered): int => $registered > 0 ? (int) min(100, round($attended / $registered * 100)) : 0;
$today = date('Y-m-d');
$icon = static fn (string $path): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
$icons = [
    'calendar' => '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'pin' => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    'scan' => '<path d="M4 8V5.5A1.5 1.5 0 0 1 5.5 4H8M16 4h2.5A1.5 1.5 0 0 1 20 5.5V8M20 16v2.5a1.5 1.5 0 0 1-1.5 1.5H16M8 20H5.5A1.5 1.5 0 0 1 4 18.5V16M4 12h16"/>',
    'camera' => '<path d="M4 8.5A2.5 2.5 0 0 1 6.5 6h1.6l1.4-2h5l1.4 2h1.6A2.5 2.5 0 0 1 20 8.5v8a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 16.5z"/><circle cx="12" cy="12.5" r="3.5"/>',
    'back' => '<path d="M15 18l-6-6 6-6"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
    'sound' => '<path d="M11 5L6 9H3v6h3l5 4zM15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/>',
    'list' => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event Attendance Scanner | JPCS</title>
    <link rel="icon" type="image/png" href="assets/img/jpcs-logo.png?v=3">
    <?php require __DIR__ . '/includes/admin_head.php'; ?>
    <link rel="stylesheet" href="assets/css/scanner.css?v=<?= filemtime(__DIR__ . '/assets/css/scanner.css') ?>">
</head>
<body class="app-page admin-page jpcs-admin feature-scanner">
<?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

<main class="content">
<?php if (!$hasEventID) { ?>
    <header class="sc-intro">
        <h1>Event Attendance Scanner</h1>
        <p>Choose an event, then scan each student's event pass to check them in.</p>
    </header>

    <?php if (!$upcoming) { ?>
        <div class="empty-state sc-empty">
            <?= $icon($icons['calendar']) ?>
            <h2>No upcoming events</h2>
            <p>Events marked as upcoming in Event Management will appear here.</p>
        </div>
    <?php } else { ?>
        <div class="sc-events">
        <?php foreach ($upcoming as $index => $row) {
            $registered = (int) $row['registered']; $attended = (int) $row['attended']; $pct = $percent($attended, $registered);
            $isToday = $row['event_date'] === $today; ?>
            <a class="sc-event" href="scanner.php?event_id=<?= (int) $row['id'] ?>" style="--i: <?= (int) $index ?>">
                <span class="sc-event__poster">
                    <img src="<?= e(image_url($row['poster'])) ?>" alt="" loading="lazy">
                    <?php if ($isToday) { ?><span class="sc-chip is-live">Today</span><?php } ?>
                </span>
                <span class="sc-event__body">
                    <strong><?= e($row['title']) ?></strong>
                    <span class="sc-event__meta">
                        <span><?= $icon($icons['calendar']) ?><?= e(date('D, M j, Y', strtotime((string) $row['event_date']))) ?></span>
                        <?php if (!empty($row['event_time'])) { ?><span><?= $icon($icons['clock']) ?><?= e(date('g:i A', strtotime((string) $row['event_time']))) ?></span><?php } ?>
                        <?php if (!empty($row['location'])) { ?><span><?= $icon($icons['pin']) ?><?= e($row['location']) ?></span><?php } ?>
                    </span>
                    <span class="sc-progress" role="img" aria-label="<?= $attended ?> of <?= $registered ?> checked in"><i style="width: <?= $pct ?>%"></i></span>
                    <span class="sc-event__count"><b><?= $attended ?></b> of <?= $registered ?> checked in</span>
                    <span class="sc-event__open"><?= $icon($icons['scan']) ?>Open scanner</span>
                </span>
            </a>
        <?php } ?>
        </div>
    <?php } ?>

<?php } elseif (!$event) { ?>
    <a href="scanner.php" class="sc-back"><?= $icon($icons['back']) ?>All events</a>
    <div class="empty-state sc-empty">
        <?= $icon($icons['calendar']) ?>
        <h2>Event not found</h2>
        <p>It may have been deleted. Choose another event.</p>
    </div>

<?php } else {
    $isDone = $event['status'] === 'done';
    $pct = $percent($stats['attended'], $stats['registered']); ?>
    <div class="sc-app" data-scanner data-event="<?= (int) $eventID ?>" data-closed="<?= $isDone ? '1' : '0' ?>">
        <header class="sc-head">
            <a href="scanner.php" class="sc-back"><?= $icon($icons['back']) ?>All events</a>
            <div class="sc-head__title">
                <h1><?= e($event['title']) ?></h1>
                <p class="sc-event__meta">
                    <span><?= $icon($icons['calendar']) ?><?= e(date('D, M j, Y', strtotime((string) $event['event_date']))) ?></span>
                    <?php if (!empty($event['event_time'])) { ?><span><?= $icon($icons['clock']) ?><?= e(date('g:i A', strtotime((string) $event['event_time']))) ?></span><?php } ?>
                    <?php if (!empty($event['location'])) { ?><span><?= $icon($icons['pin']) ?><?= e($event['location']) ?></span><?php } ?>
                </p>
            </div>
            <a class="btn" href="registrations.php?event_id=<?= (int) $eventID ?>&amp;tab=attendance"><?= $icon($icons['list']) ?>Attendance report</a>
        </header>

        <section class="sc-stats" aria-label="Attendance summary">
            <div class="sc-stat"><span>Registered</span><strong data-sc-stat="registered"><?= $stats['registered'] ?></strong></div>
            <div class="sc-stat is-good"><span>Checked in</span><strong data-sc-stat="attended"><?= $stats['attended'] ?></strong></div>
            <div class="sc-stat is-muted"><span>Not yet scanned</span><strong data-sc-stat="remaining"><?= max(0, $stats['registered'] - $stats['attended']) ?></strong></div>
            <div class="sc-stat sc-stat--progress">
                <span>Turnout</span><strong data-sc-stat="percent"><?= $pct ?>%</strong>
                <span class="sc-progress"><i data-sc-bar style="width: <?= $pct ?>%"></i></span>
            </div>
        </section>

        <div class="sc-grid">
            <section class="sc-panel sc-camera" aria-labelledby="sc-camera-title">
                <div class="sc-panel__head">
                    <h2 id="sc-camera-title">Scan event pass</h2>
                    <span class="sc-chip" data-sc-status><?= $isDone ? 'Closed' : 'Camera off' ?></span>
                </div>

                <?php if ($isDone) { ?>
                    <div class="sc-closed">
                        <?= $icon($icons['scan']) ?>
                        <p><strong>Scanning is closed.</strong> This event is marked as done, so no more attendance can be recorded.</p>
                    </div>
                <?php } else { ?>
                    <div class="sc-viewport" data-sc-viewport>
                        <div id="reader"></div>
                        <div class="sc-frame" aria-hidden="true"><i></i><i></i><i></i><i></i><b></b></div>
                        <div class="sc-idle" data-sc-idle>
                            <?= $icon($icons['camera']) ?>
                            <p data-sc-idle-text>Point the camera at the QR code on the student's event pass.</p>
                            <button type="button" class="btn-primary" data-sc-start>Start camera</button>
                        </div>
                    </div>
                    <div class="sc-controls">
                        <button type="button" class="btn" data-sc-stop hidden>Stop camera</button>
                        <label class="sc-select" data-sc-camera-wrap hidden>
                            <span class="visually-hidden">Camera</span>
                            <select data-sc-camera aria-label="Camera"></select>
                        </label>
                        <button type="button" class="btn sc-sound" data-sc-sound aria-pressed="true" title="Scan sounds"><?= $icon($icons['sound']) ?><span>Sound on</span></button>
                    </div>

                    <form method="POST" class="sc-manual" data-sc-form>
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['scanner_csrf']) ?>">
                        <label for="qrdata">Manual entry or USB scanner</label>
                        <div class="sc-manual__row">
                            <input type="text" name="qrdata" id="qrdata" required maxlength="50" autocomplete="off" spellcheck="false" placeholder="Control number on the pass">
                            <button type="submit">Check in</button>
                        </div>
                        <p>Use this if the camera is unavailable. Only confirmed registrations for this event are accepted.</p>
                    </form>
                <?php } ?>
            </section>

            <section class="sc-panel sc-feed" aria-labelledby="sc-feed-title">
                <div class="sc-panel__head">
                    <h2 id="sc-feed-title">Checked in <span class="sc-count" data-sc-stat="attended"><?= $stats['attended'] ?></span></h2>
                    <a class="sc-link" href="scanner.php?event_id=<?= (int) $eventID ?>" title="Reload to include scans from other devices">Refresh</a>
                </div>
                <label class="sc-search">
                    <?= $icon($icons['search']) ?>
                    <span class="visually-hidden">Search checked-in students</span>
                    <input type="search" data-sc-filter placeholder="Search name, ID or section" autocomplete="off">
                </label>
                <ol class="sc-feed__list" data-sc-feed aria-live="polite">
                    <?php foreach ($feed as $row) echo scanner_feed_item($row); ?>
                </ol>
                <p class="sc-feed__empty" data-sc-empty <?= $feed ? 'hidden' : '' ?>>No one has checked in yet. Scanned students appear here, newest first.</p>
                <p class="sc-feed__empty" data-sc-nomatch hidden>No checked-in student matches your search.</p>
            </section>
        </div>
    </div>
<?php } ?>
</main>

<dialog class="sc-popup" id="scan-popup" aria-labelledby="sc-popup-title" aria-describedby="sc-popup-message"<?= $popup ? ' data-initial="' . e(json_encode($popup, JSON_UNESCAPED_UNICODE)) . '"' : '' ?>>
    <div class="sc-popup__card">
        <span class="sc-popup__icon" aria-hidden="true">
            <svg class="is-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
            <svg class="is-duplicate" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5h.01"/><path d="M10.3 3.9L2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
            <svg class="is-invalid" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M7 7l10 10M17 7L7 17"/></svg>
        </span>
        <p class="sc-popup__eyebrow" data-sc-p="eyebrow"></p>
        <h2 id="sc-popup-title" data-sc-p="title"></h2>
        <p class="sc-popup__message" id="sc-popup-message" data-sc-p="message"></p>
        <div class="sc-popup__person" data-sc-p="person">
            <span class="sc-avatar sc-avatar--lg" data-sc-p="initials"></span>
            <span><strong data-sc-p="name"></strong><small data-sc-p="course"></small></span>
        </div>
        <dl class="sc-popup__details">
            <div data-sc-row="student_id"><dt>Student ID</dt><dd data-sc-p="student_id"></dd></div>
            <div data-sc-row="control"><dt>Control number</dt><dd data-sc-p="control"></dd></div>
            <div data-sc-row="time"><dt data-sc-p="time_label">Time in</dt><dd data-sc-p="time"></dd></div>
        </dl>
        <button type="button" class="btn-primary sc-popup__close" data-sc-close>Scan next</button>
        <p class="sc-popup__hint">Closes automatically · <kbd>Enter</kbd> to continue</p>
        <span class="sc-popup__timer" aria-hidden="true"><i data-sc-timer></i></span>
    </div>
</dialog>

<script src="js/html5-qrcode.min.js"></script>
<script src="js/scanner.js?v=<?= filemtime(__DIR__ . '/js/scanner.js') ?>"></script>
</body>
</html>
