<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/audit.php';

if (!empty($_SESSION['user_id'])) {
    audit_log($conn, 'auth.logout', ($_SESSION['name'] ?? 'Someone') . ' signed out', 'user', (int) $_SESSION['user_id']);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}

session_destroy();
redirect('index.php');
