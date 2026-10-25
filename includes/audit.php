<?php
declare(strict_types=1);

// Audit trail: who did what, when, from where, for adviser and officer actions.
// Logging must never break the action being logged, so every failure is swallowed and sent to error_log.

const AUDIT_CATEGORIES = [
    'auth' => 'Sign-in',
    'account' => 'Accounts',
    'profile' => 'Profile',
    'event' => 'Events',
    'registration' => 'Registrations',
    'attendance' => 'Attendance',
    'masterlist' => 'Masterlist',
    'officer' => 'Officers',
];

function audit_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        actor_name VARCHAR(100) NOT NULL,
        actor_role VARCHAR(30) NOT NULL DEFAULT '',
        action VARCHAR(50) NOT NULL,
        entity_type VARCHAR(30) NULL,
        entity_id VARCHAR(50) NULL,
        summary VARCHAR(255) NOT NULL,
        details TEXT NULL,
        ip_address VARCHAR(45) NOT NULL DEFAULT '',
        user_agent VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_audit_created (created_at),
        KEY idx_audit_user (user_id, created_at),
        KEY idx_audit_action (action, created_at),
        CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $done = true;
}

/**
 * Records one action. $action is "category.verb" (e.g. "event.update"); $details holds extra facts,
 * typically a field-by-field ['field' => [old, new]] map from audit_diff().
 * $actor overrides the signed-in user (used for sign-in, where the session is not set yet).
 */
function audit_log(mysqli $conn, string $action, string $summary, ?string $entityType = null, int|string|null $entityId = null, array $details = [], ?array $actor = null): void
{
    try {
        audit_ensure_schema($conn);
        if ($actor === null && !empty($_SESSION['user_id'])) {
            $stmt = $conn->prepare('SELECT id, name, role FROM users WHERE id=?');
            $sessionId = (int) $_SESSION['user_id'];
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $actor = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
        $userId = isset($actor['id']) ? (int) $actor['id'] : null;
        $name = mb_substr((string) ($actor['name'] ?? 'Unknown'), 0, 100);
        $role = (string) ($actor['role'] ?? '');
        $entity = $entityId === null ? null : (string) $entityId;
        $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $agent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $summary = mb_substr($summary, 0, 255);
        $stmt = $conn->prepare('INSERT INTO audit_log (user_id, actor_name, actor_role, action, entity_type, entity_id, summary, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssssssss', $userId, $name, $role, $action, $entityType, $entity, $summary, $json, $ip, $agent);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        error_log('JPCS audit: ' . $error->getMessage());
    }
}

/** ['field' => [old, new]] for the listed fields that changed. */
function audit_diff(array $before, array $after, array $fields): array
{
    $changes = [];
    foreach ($fields as $field => $label) {
        if (is_int($field)) $field = $label;
        $old = (string) ($before[$field] ?? '');
        $new = (string) ($after[$field] ?? '');
        if ($old !== $new) $changes[$label] = [$old, $new];
    }
    return $changes;
}

function audit_category(string $action): string
{
    return AUDIT_CATEGORIES[strstr($action, '.', true) ?: $action] ?? 'Other';
}
