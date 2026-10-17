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
