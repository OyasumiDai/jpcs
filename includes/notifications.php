<?php
declare(strict_types=1);

// Panel notifications for advisers and officers. Most items are read from data the system
// already records (registrations, attendance, masterlist uploads, events); event edits and
// "mark done" are written to panel_activity because nothing else timestamps them.

require_once __DIR__ . '/masterlist.php';

const NOTIFICATIONS_DAYS = 30;
const NOTIFICATIONS_LIMIT = 40;

function notifications_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS panel_activity (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(30) NOT NULL,
        event_id INT NULL,
        user_id INT NULL,
        actor_name VARCHAR(100) NOT NULL,
        title VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_activity_created (created_at),
        CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS notification_reads (
        user_id INT NOT NULL PRIMARY KEY,
        last_seen_at TIMESTAMP NULL DEFAULT NULL,
        CONSTRAINT fk_notification_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $done = true;
}

/** Records an event change ('event_updated' or 'event_done') for other panel users. */
function notifications_log_event(mysqli $conn, array $user, string $kind, int $eventId, string $title): void
{
    try {
        notifications_ensure_schema($conn);
        $userId = (int) ($user['id'] ?? 0);
        $actor = (string) ($user['name'] ?? $user['username'] ?? 'Someone');
        $stmt = $conn->prepare('INSERT INTO panel_activity (kind, event_id, user_id, actor_name, title) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('siiss', $kind, $eventId, $userId, $actor, $title);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        // A missing notification must never block saving the event itself.
        error_log('JPCS notifications log: ' . $error->getMessage());
    }
}

/** Inline SVG icon for a notification kind (static markup, safe to print). */
function notifications_icon(string $kind): string
{
    $paths = [
        'registration' => '<path d="M15 19c-.6-2.8-2.6-4.3-6-4.3S3.6 16.2 3 19"></path><circle cx="9" cy="8" r="3.3"></circle><path d="M18 8v6M15 11h6"></path>',
        'attendance' => '<path d="M4 8V5h3M17 5h3v3M20 16v3h-3M7 19H4v-3"></path><path d="m8.5 12 2.5 2.5 4.5-5"></path>',
        'masterlist' => '<path d="M6 3h9l4 4v14H6z"></path><path d="M14 3v5h5M9 12h7M9 16h7"></path>',
        'event' => '<path d="M4 6h16v14H4z"></path><path d="M8 4v4M16 4v4M4 10h16"></path>',
        'reminder' => '<circle cx="12" cy="13" r="7"></circle><path d="M12 9v4l2.5 2M5 4 3 6M19 4l2 2"></path>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$kind] ?? $paths['event']) . '</svg>';
}

function notifications_last_seen(mysqli $conn, int $userId): ?int
{
    notifications_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT UNIX_TIMESTAMP(last_seen_at) AS seen FROM notification_reads WHERE user_id=?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $seen = $stmt->get_result()->fetch_assoc()['seen'] ?? null;
    $stmt->close();
    return $seen !== null ? (int) $seen : null;
}

function notifications_mark_read(mysqli $conn, int $userId): void
{
    notifications_ensure_schema($conn);
    $stmt = $conn->prepare('INSERT INTO notification_reads (user_id, last_seen_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function notifications_time_ago(int $time): string
{
    $diff = time() - $time;
    if ($diff < 0) return 'upcoming';
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    if ($diff < 172800) return 'yesterday';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M j', $time);
}

/**
 * Newest-first notifications for a panel user.
 * @return list<array{key:string,kind:string,title:string,detail:string,url:string,at:int,unread:bool,ago:string}>
 */
function notifications_feed(mysqli $conn, array $user, int $limit = NOTIFICATIONS_LIMIT): array
{
    notifications_ensure_schema($conn);
    masterlist_ensure_schema($conn);
    $userId = (int) ($user['id'] ?? 0);
    // All times come from MySQL (UNIX_TIMESTAMP), so they stay correct whatever PHP's timezone is.
    $since = 'NOW() - INTERVAL ' . NOTIFICATIONS_DAYS . ' DAY';
    $items = [];
    $add = static function (array $item) use (&$items): void { $items[] = $item; };

    // New registrations, grouped per event per day.
    $stmt = $conn->prepare("SELECT e.id, e.title, DATE(r.registered_at) AS day, COUNT(*) AS n,
            SUM(r.status = 'pending') AS pending, SUM(r.confirmed_by = 'Masterlist (auto)') AS auto,
            UNIX_TIMESTAMP(MAX(r.registered_at)) AS at,
            SUBSTRING_INDEX(GROUP_CONCAT(r.student_name ORDER BY r.registered_at DESC SEPARATOR '\n'), '\n', 1) AS latest
        FROM registrations r JOIN events e ON e.id = r.event_id
        WHERE r.registered_at >= $since GROUP BY e.id, e.title, DATE(r.registered_at)");
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $n = (int) $row['n'];
        $detail = [];
        if ((int) $row['pending'] > 0) $detail[] = (int) $row['pending'] . ' waiting for review';
        if ((int) $row['auto'] > 0) $detail[] = (int) $row['auto'] . ' confirmed from the masterlist';
        $add(['key' => 'reg-' . $row['id'] . '-' . $row['day'], 'kind' => 'registration',
            'title' => $n === 1 ? $row['latest'] . ' registered for ' . $row['title'] : $n . ' new registrations for ' . $row['title'],
            'detail' => implode(' · ', $detail), 'url' => 'registrations.php?tab=pending&event_id=' . (int) $row['id'], 'at' => (int) $row['at']]);
    }
    $stmt->close();

    // Attendance scans, grouped per event per day.
    $stmt = $conn->prepare("SELECT e.id, e.title, DATE(a.scanned_at) AS day, COUNT(*) AS n, UNIX_TIMESTAMP(MAX(a.scanned_at)) AS at,
            SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(r.student_name, a.participant_id) ORDER BY a.scanned_at DESC SEPARATOR '\n'), '\n', 1) AS latest
        FROM attendance a JOIN events e ON e.id = a.event_id
        LEFT JOIN registrations r ON r.control_number = a.participant_id AND r.event_id = a.event_id
        WHERE a.scanned_at >= $since GROUP BY e.id, e.title, DATE(a.scanned_at)");
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $n = (int) $row['n'];
        $add(['key' => 'scan-' . $row['id'] . '-' . $row['day'], 'kind' => 'attendance',
            'title' => $n === 1 ? $row['latest'] . ' checked in at ' . $row['title'] : $n . ' check-ins at ' . $row['title'],
            'detail' => $n === 1 ? 'QR code scanned' : 'Latest: ' . $row['latest'], 'url' => 'registrations.php?tab=attendance&event_id=' . (int) $row['id'], 'at' => (int) $row['at']]);
    }
    $stmt->close();

    // Masterlist uploads for sections this user manages (not their own uploads).
    $sections = masterlist_user_sections($conn, $user);
    if ($sections) {
        $stmt = $conn->prepare('SELECT *, UNIX_TIMESTAMP(created_at) AS at FROM masterlist_uploads WHERE created_at >= ' . $since . ' AND (uploaded_by IS NULL OR uploaded_by <> ?) AND course_year_section IN (' . implode(',', array_fill(0, count($sections), '?')) . ')');
        $stmt->bind_param('i' . str_repeat('s', count($sections)), $userId, ...$sections);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $add(['key' => 'ml-' . $row['id'], 'kind' => 'masterlist',
                'title' => $row['uploader_name'] . ' uploaded the ' . $row['course_year_section'] . ' masterlist',
                'detail' => masterlist_batch_label((string) $row['batch']) . ' · ' . (int) $row['rows_added'] . ' added, ' . (int) $row['rows_updated'] . ' updated',
                'url' => 'masterlist.php?tab=history', 'at' => (int) $row['at']]);
        }
        $stmt->close();
    }

    // New events.
    $stmt = $conn->prepare('SELECT id, title, event_date, UNIX_TIMESTAMP(created_at) AS at FROM events WHERE created_at >= ' . $since);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $add(['key' => 'event-new-' . $row['id'], 'kind' => 'event', 'title' => 'New event: ' . $row['title'],
            'detail' => 'Scheduled for ' . date('M j, Y', strtotime((string) $row['event_date'])), 'url' => 'events.php#event-' . (int) $row['id'], 'at' => (int) $row['at']]);
    }
    $stmt->close();

    // Event edits and "mark done" by other users.
    $stmt = $conn->prepare('SELECT *, UNIX_TIMESTAMP(created_at) AS at FROM panel_activity WHERE created_at >= ' . $since . ' AND (user_id IS NULL OR user_id <> ?)');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $done = $row['kind'] === 'event_done';
        $add(['key' => 'activity-' . $row['id'], 'kind' => 'event',
            'title' => $row['actor_name'] . ($done ? ' marked ' : ' updated ') . $row['title'] . ($done ? ' as done' : ''),
            'detail' => $done ? 'Event finished' : 'Event details changed', 'url' => 'events.php#event-' . (int) $row['event_id'], 'at' => (int) $row['at']]);
    }
    $stmt->close();

    // Event approval: advisers see officers' requests; officers see decisions on their own requests.
    require_once __DIR__ . '/event_requests.php';
    event_requests_ensure_schema($conn);
    if (in_array($user['role'] ?? '', ['admin_advisor', 'admin_adviser'], true)) {
        $stmt = $conn->prepare("SELECT id, request_type, payload, requester_name, UNIX_TIMESTAMP(created_at) AS at FROM event_requests WHERE status = 'pending' AND created_at >= $since AND (requested_by IS NULL OR requested_by <> ?)");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $title = (string) ((json_decode((string) $row['payload'], true) ?: [])['title'] ?? 'an event');
            $add(['key' => 'evreq-' . $row['id'], 'kind' => 'event',
                'title' => $row['requester_name'] . ($row['request_type'] === 'create' ? ' proposed a new event: ' : ' requested changes to ') . $title,
                'detail' => 'Waiting for your approval', 'url' => 'events.php?view=approvals', 'at' => (int) $row['at']]);
        }
        $stmt->close();
    } else {
        $stmt = $conn->prepare("SELECT id, request_type, payload, status, reviewer_name, review_note, UNIX_TIMESTAMP(reviewed_at) AS at FROM event_requests WHERE requested_by = ? AND status IN ('approved', 'rejected') AND reviewed_at >= $since");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $title = (string) ((json_decode((string) $row['payload'], true) ?: [])['title'] ?? 'your event');
            $approved = $row['status'] === 'approved';
            $add(['key' => 'evdec-' . $row['id'], 'kind' => 'event',
                'title' => ($row['request_type'] === 'create' ? 'Your new event ' . $title . ($approved ? ' was approved' : ' was not approved') : 'Your changes to ' . $title . ($approved ? ' were approved' : ' were not approved')),
                'detail' => trim(($approved ? 'Published' : 'Rejected') . ' by ' . $row['reviewer_name'] . ($row['review_note'] ? ': ' . $row['review_note'] : '')),
                'url' => 'events.php?view=requests', 'at' => (int) $row['at']]);
        }
        $stmt->close();
    }

    // Reminders: upcoming events in the next 3 days (the reminder appears when the event enters that window).
    $stmt = $conn->query("SELECT id, title, event_time, location, DATEDIFF(event_date, CURDATE()) AS days,
            UNIX_TIMESTAMP(GREATEST(TIMESTAMP(event_date) - INTERVAL 3 DAY, created_at)) AS at
        FROM events WHERE status = 'upcoming' AND event_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 3 DAY");
    foreach ($stmt->fetch_all(MYSQLI_ASSOC) as $row) {
        $days = (int) $row['days'];
        $when = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : 'in ' . $days . ' days');
        $add(['key' => 'soon-' . $row['id'], 'kind' => 'reminder', 'title' => $row['title'] . ' is ' . $when,
            'detail' => trim(substr((string) $row['event_time'], 0, 5) . ' · ' . $row['location'], ' ·'), 'url' => 'events.php#event-' . (int) $row['id'], 'at' => (int) $row['at']]);
    }

    usort($items, static fn($a, $b) => $b['at'] <=> $a['at']);
    $items = array_slice($items, 0, $limit);
    $seen = notifications_last_seen($conn, $userId);
    foreach ($items as &$item) {
        $item['unread'] = $seen === null || $item['at'] > $seen;
        $item['ago'] = notifications_time_ago($item['at']);
        $item['icon'] = notifications_icon($item['kind']);
    }
    unset($item);
    return $items;
}

function notifications_unread_count(mysqli $conn, array $user): int
{
    return count(array_filter(notifications_feed($conn, $user), static fn($item) => $item['unread']));
}
