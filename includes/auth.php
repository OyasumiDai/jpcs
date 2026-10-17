<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        redirect('login.php');
    }
}

function current_user(): array
{
    return [
        'id' => (int) ($_SESSION['user_id'] ?? 0),
        'username' => (string) ($_SESSION['username'] ?? ''),
        'name' => (string) ($_SESSION['name'] ?? ''),
        'role' => (string) ($_SESSION['role'] ?? ''),
        'position' => (string) ($_SESSION['position'] ?? ''),
    ];
}

function is_admin(): bool
{
    return in_array($_SESSION['role'] ?? '', ['admin_advisor', 'admin_adviser'], true);
}

function require_admin(): void
{
    require_login();

    if (!is_admin()) {
        http_response_code(403);
        exit('Access denied.');
    }
}
