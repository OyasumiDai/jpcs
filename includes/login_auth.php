<?php
declare(strict_types=1);

/** Match credentials without changing sessions or database records.
 * Ambiguous credentials fail closed rather than choosing a privileged account.
 */
function find_login_account(mysqli $conn, string $identifier, string $password): ?array
{
    if ($identifier === '' || $password === '') return null;
    $matches = [];
    $stmt = $conn->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->bind_param('s', $identifier);
    $stmt->execute();
    foreach ($stmt->get_result() as $row) {
        $stored = (string) $row['password'];
        $legacy = !password_get_info($stored)['algo'];
        if (password_verify($password, $stored) || ($legacy && hash_equals($stored, $password))) {
            $matches[] = ['type' => 'staff', 'record' => $row, 'legacy' => $legacy];
        }
    }
    $stmt->close();

    $stmt = $conn->prepare('SELECT * FROM student_accounts WHERE email = ? OR student_id = ?');
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    foreach ($stmt->get_result() as $row) {
        if (password_verify($password, (string) $row['password_hash'])) {
            $matches[] = ['type' => 'student', 'record' => $row, 'legacy' => false];
        }
    }
    $stmt->close();
    return count($matches) === 1 ? $matches[0] : null;
}

/** Clear the other account type to prevent stale staff privileges after switching. */
function set_login_session(array $account): string
{
    session_regenerate_id(true);
    foreach (['user_id', 'username', 'name', 'role', 'position', 'student_id', 'student_name', 'student_number', 'student_email'] as $key) {
        unset($_SESSION[$key]);
    }
    $row = $account['record'];
    if ($account['type'] === 'student') {
        $_SESSION['student_id'] = $row['id'];
        $_SESSION['student_name'] = $row['full_name'];
        $_SESSION['student_number'] = $row['student_id'];
        $_SESSION['student_email'] = $row['email'];
        return 'index.php';
    }
    $_SESSION['user_id'] = $row['id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['name'] = $row['name'];
    $_SESSION['role'] = $row['role'];
    $_SESSION['position'] = $row['position'];
    return 'dashboard.php';
}

// Brute-force protection: failed sign-ins are counted per identifier and per IP address.
const LOGIN_MAX_FAILURES = 5;        // wrong passwords per identifier before it locks
const LOGIN_MAX_IP_FAILURES = 20;    // failures from one IP before it is blocked
const LOGIN_LOCK_MINUTES = 15;       // window and lock length
const LOGIN_WARN_AFTER = 3;          // start showing "attempts left" after this many failures

function login_throttle_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(150) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_login_attempts_identifier (identifier, created_at),
        KEY idx_login_attempts_ip (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    // Old rows are only needed for the current window.
    $conn->query('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
    $done = true;
}

function login_throttle_key(string $identifier): string
{
    return mb_substr(mb_strtolower(trim($identifier)), 0, 150);
}

function login_throttle_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * Current state for an identifier and the visitor's IP.
 * @return array{locked:int,failures:int,remaining:int} locked = seconds until sign-in is allowed again
 */
function login_throttle_status(mysqli $conn, string $identifier): array
{
    login_throttle_ensure_schema($conn);
    $minutes = LOGIN_LOCK_MINUTES;
    $check = static function (string $column, string $value, int $limit) use ($conn, $minutes): array {
        $stmt = $conn->prepare("SELECT COUNT(*) AS n, TIMESTAMPDIFF(SECOND, NOW(), MAX(created_at) + INTERVAL ? MINUTE) AS wait
            FROM login_attempts WHERE $column = ? AND created_at > NOW() - INTERVAL ? MINUTE");
        $stmt->bind_param('isi', $minutes, $value, $minutes);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $count = (int) $row['n'];
        return [$count, $count >= $limit ? max(0, (int) $row['wait']) : 0];
    };
    [$failures, $lockedName] = $identifier === '' ? [0, 0] : $check('identifier', login_throttle_key($identifier), LOGIN_MAX_FAILURES);
    [, $lockedIp] = $check('ip', login_throttle_ip(), LOGIN_MAX_IP_FAILURES);
    return ['locked' => max($lockedName, $lockedIp), 'failures' => $failures, 'remaining' => max(0, LOGIN_MAX_FAILURES - $failures)];
}

function login_throttle_fail(mysqli $conn, string $identifier): void
{
    login_throttle_ensure_schema($conn);
    $key = login_throttle_key($identifier);
    $ip = login_throttle_ip();
    $stmt = $conn->prepare('INSERT INTO login_attempts (identifier, ip) VALUES (?, ?)');
    $stmt->bind_param('ss', $key, $ip);
    $stmt->execute();
    $stmt->close();
}

/** Forget failures after a successful sign-in or password reset. */
function login_throttle_clear(mysqli $conn, array $identifiers): void
{
    login_throttle_ensure_schema($conn);
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE identifier = ?');
    foreach (array_unique(array_filter(array_map('login_throttle_key', $identifiers))) as $key) {
        $stmt->bind_param('s', $key);
        $stmt->execute();
    }
    $stmt->close();
}

/** "14 minutes" / "45 seconds" */
function login_throttle_wait_text(int $seconds): string
{
    if ($seconds >= 60) {
        $minutes = (int) ceil($seconds / 60);
        return $minutes . ($minutes === 1 ? ' minute' : ' minutes');
    }
    return $seconds . ($seconds === 1 ? ' second' : ' seconds');
}
