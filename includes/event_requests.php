<?php
declare(strict_types=1);

// Event approval: officers propose new events or changes; an adviser approves (publishes) or rejects them.

const EVENT_REQUEST_FIELDS = ['title' => 'Title', 'description' => 'Description', 'event_date' => 'Date', 'event_time' => 'Time', 'location' => 'Location', 'status' => 'Status', 'poster' => 'Poster', 'pass_image' => 'Event pass'];

function event_requests_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS event_requests (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_id INT NULL,
        request_type ENUM('create', 'update') NOT NULL,
        payload TEXT NOT NULL,
        new_files TEXT NULL,
        requested_by INT NULL,
        requester_name VARCHAR(100) NOT NULL,
        status ENUM('pending', 'approved', 'rejected', 'withdrawn') NOT NULL DEFAULT 'pending',
        reviewed_by INT NULL,
        reviewer_name VARCHAR(100) NULL,
        review_note VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP NULL DEFAULT NULL,
        KEY idx_event_requests_status (status, created_at),
        KEY idx_event_requests_event (event_id, status),
        CONSTRAINT fk_event_requests_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_event_requests_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $done = true;
}

function event_requests_pending_count(mysqli $conn): int
{
    event_requests_ensure_schema($conn);
    return (int) $conn->query("SELECT COUNT(*) AS n FROM event_requests WHERE status = 'pending'")->fetch_assoc()['n'];
}

function event_requests_find(mysqli $conn, int $id): ?array
{
    event_requests_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT * FROM event_requests WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($row) {
        $row['data'] = json_decode((string) $row['payload'], true) ?: [];
        $row['files'] = json_decode((string) ($row['new_files'] ?? '[]'), true) ?: [];
    }
    return $row;
}

/** Pending update request for an event, if any (one at a time keeps reviews unambiguous). */
function event_requests_pending_for_event(mysqli $conn, int $eventId): ?array
{
    event_requests_ensure_schema($conn);
    $stmt = $conn->prepare("SELECT id, requester_name, created_at FROM event_requests WHERE event_id = ? AND status = 'pending' LIMIT 1");
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function event_requests_submit(mysqli $conn, array $user, ?int $eventId, array $data, array $newFiles): int
{
    event_requests_ensure_schema($conn);
    $type = $eventId ? 'update' : 'create';
    $payload = json_encode(array_intersect_key($data, EVENT_REQUEST_FIELDS), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $files = json_encode(array_values($newFiles), JSON_UNESCAPED_SLASHES);
    $userId = (int) $user['id'];
    $name = (string) $user['name'];
    $stmt = $conn->prepare('INSERT INTO event_requests (event_id, request_type, payload, new_files, requested_by, requester_name) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssis', $eventId, $type, $payload, $files, $userId, $name);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

/** Approves a pending request: writes the event and returns its id. */
function event_requests_approve(mysqli $conn, array $request, array $reviewer, string $note): int
{
    $d = $request['data'];
    $conn->begin_transaction();
    try {
        if ($request['request_type'] === 'create') {
            $stmt = $conn->prepare('INSERT INTO events (title, description, event_date, event_time, location, poster, pass_image, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssssss', $d['title'], $d['description'], $d['event_date'], $d['event_time'], $d['location'], $d['poster'], $d['pass_image'], $d['status']);
            $stmt->execute();
            $eventId = (int) $stmt->insert_id;
        } else {
            $eventId = (int) $request['event_id'];
            $stmt = $conn->prepare('UPDATE events SET title=?, description=?, event_date=?, event_time=?, location=?, poster=?, pass_image=?, status=? WHERE id=?');
            $stmt->bind_param('ssssssssi', $d['title'], $d['description'], $d['event_date'], $d['event_time'], $d['location'], $d['poster'], $d['pass_image'], $d['status'], $eventId);
            $stmt->execute();
        }
        $stmt->close();
        event_requests_close($conn, (int) $request['id'], 'approved', $reviewer, $note, $eventId);
        $conn->commit();
        return $eventId;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

/** Rejects or withdraws a request and deletes images that were uploaded only for it. */
function event_requests_close(mysqli $conn, int $requestId, string $status, array $reviewer, string $note = '', ?int $eventId = null): void
{
    $reviewerId = (int) $reviewer['id'];
    $reviewerName = (string) $reviewer['name'];
    $note = mb_substr(trim($note), 0, 500);
    $stmt = $conn->prepare("UPDATE event_requests SET status = ?, reviewed_by = ?, reviewer_name = ?, review_note = ?, reviewed_at = NOW(), event_id = COALESCE(?, event_id) WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('sissii', $status, $reviewerId, $reviewerName, $note, $eventId, $requestId);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    if ($changed !== 1) throw new RuntimeException('This request was already handled. Refresh to see its status.');
}

function event_requests_discard_files(array $request): void
{
    foreach ($request['files'] as $path) {
        if (is_string($path) && str_starts_with($path, 'uploads/admin/')) @unlink(dirname(__DIR__) . '/' . $path);
    }
}

/** ['Field' => [current, proposed]] for the fields a request changes. */
function event_requests_changes(array $request, ?array $current): array
{
    $changes = [];
    foreach (EVENT_REQUEST_FIELDS as $field => $label) {
        $old = (string) ($current[$field] ?? '');
        $new = (string) ($request['data'][$field] ?? '');
        if ($field === 'event_time') { $old = substr($old, 0, 5); $new = substr($new, 0, 5); }
        if ($old !== $new) $changes[$label] = [$old, $new];
    }
    return $changes;
}
