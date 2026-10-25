<?php
declare(strict_types=1);

// Feature access for officer accounts. Advisers always have every feature.
// Officers only reach the features an adviser granted in Account Management.

const PANEL_FEATURES = [
    'officers' => ['label' => 'Officer Information', 'hint' => 'View and edit the officer directory', 'pages' => ['officers.php', 'officers_admin.php']],
    'registrations' => ['label' => 'Registrations & Attendance', 'hint' => 'Review, confirm and track registrations', 'pages' => ['registrations.php', 'pending.php', 'attendance.php', 'attendance_event.php', 'attendance_section.php', 'register_list.php']],
    'scanner' => ['label' => 'Event Attendance Scanner', 'hint' => 'Scan QR codes at the entrance', 'pages' => ['scanner.php']],
    'masterlist' => ['label' => 'Student Masterlist', 'hint' => 'Upload and manage section masterlists', 'pages' => ['masterlist.php']],
    'events' => ['label' => 'Event Management', 'hint' => 'View the event list and calendar', 'pages' => ['events.php']],
    'audit' => ['label' => 'Audit Trail', 'hint' => 'See their own activity history', 'pages' => ['audit.php']],
];

function permissions_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $exists = (int) $conn->query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'officer_permissions'")->fetch_assoc()['n'] > 0;
    if (!$exists) {
        $conn->query("CREATE TABLE officer_permissions (
            user_id INT NOT NULL,
            feature VARCHAR(30) NOT NULL,
            granted_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, feature),
            CONSTRAINT fk_officer_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        // Officers that existed before permissions keep the access they already had.
        $officers = $conn->query("SELECT id FROM users WHERE role = 'officer'")->fetch_all(MYSQLI_ASSOC);
        $stmt = $conn->prepare('INSERT INTO officer_permissions (user_id, feature) VALUES (?, ?)');
        foreach ($officers as $officer) {
            foreach (array_keys(PANEL_FEATURES) as $feature) {
                $id = (int) $officer['id'];
                $stmt->bind_param('is', $id, $feature);
                $stmt->execute();
            }
        }
        $stmt->close();
    }
    $done = true;
}

function permissions_is_adviser(array $user): bool
{
    return in_array($user['role'] ?? '', ['admin_advisor', 'admin_adviser'], true);
}

/** Features a panel user may use (all of them for advisers). */
function permissions_for(mysqli $conn, array $user): array
{
    static $cache = [];
    if (permissions_is_adviser($user)) return array_keys(PANEL_FEATURES);
    $id = (int) ($user['id'] ?? 0);
    if (!isset($cache[$id])) {
        permissions_ensure_schema($conn);
        $stmt = $conn->prepare('SELECT feature FROM officer_permissions WHERE user_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $cache[$id] = array_values(array_intersect(array_keys(PANEL_FEATURES), array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'feature')));
        $stmt->close();
    }
    return $cache[$id];
}

function permissions_can(mysqli $conn, array $user, string $feature): bool
{
    return in_array($feature, permissions_for($conn, $user), true);
}

/** The signed-in panel user, read from the database so role changes apply immediately. */
function permissions_current_user(mysqli $conn): array
{
    $id = (int) ($_SESSION['user_id'] ?? 0);
    $stmt = $conn->prepare('SELECT id, name, role FROM users WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $user;
}

/** Stops the request with a friendly page when the signed-in officer lacks the feature. */
function permissions_require(mysqli $conn, string $feature): void
{
    $user = permissions_current_user($conn);
    if (!$user || permissions_can($conn, $user, $feature)) return;
    http_response_code(403);
    $label = PANEL_FEATURES[$feature]['label'] ?? 'this feature';
    $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || isset($_GET['counts']) || isset($_GET['format']);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['error' => 'You do not have access to ' . $label . '.']));
    }
    $safe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    exit('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>No access | JPCS</title>'
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0e0b0a;color:#f6efe9;font:15px/1.5 Inter,"Segoe UI",Arial,sans-serif}main{max-width:420px;padding:32px;text-align:center;border:1px solid rgba(255,236,220,.12);border-radius:20px;background:rgba(38,30,25,.66)}h1{margin:0 0 8px;font-size:22px}p{margin:0 0 20px;color:#b9aea5}a{display:inline-block;padding:10px 18px;border-radius:12px;background:#ff6b2c;color:#1c0d04;font-weight:700;text-decoration:none}</style></head>'
        . '<body><main><h1>No access to ' . $safe . '</h1><p>Your officer account has not been given access to this feature. Ask an adviser to update your permissions in Account Management.</p><a href="dashboard.php">Back to dashboard</a></main></body></html>');
}

/** Saves an officer's granted features; returns [before, after] for the audit trail. */
function permissions_save(mysqli $conn, int $userId, array $features, int $grantedBy): array
{
    permissions_ensure_schema($conn);
    $features = array_values(array_intersect(array_keys(PANEL_FEATURES), $features));
    $stmt = $conn->prepare('SELECT feature FROM officer_permissions WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $before = array_values(array_intersect(array_keys(PANEL_FEATURES), array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'feature')));
    $stmt->close();
    $stmt = $conn->prepare('DELETE FROM officer_permissions WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('INSERT INTO officer_permissions (user_id, feature, granted_by) VALUES (?, ?, ?)');
    foreach ($features as $feature) {
        $stmt->bind_param('isi', $userId, $feature, $grantedBy);
        $stmt->execute();
    }
    $stmt->close();
    return [$before, $features];
}

function permissions_labels(array $features): string
{
    $labels = array_map(static fn($f) => PANEL_FEATURES[$f]['label'], array_values(array_intersect(array_keys(PANEL_FEATURES), $features)));
    return $labels ? implode(', ', $labels) : 'none';
}
