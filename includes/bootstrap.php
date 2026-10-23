<?php
declare(strict_types=1);

// Philippine time, matching the MySQL server, so "today" and timestamps agree across PHP and the database.
date_default_timezone_set(getenv('JPCS_TIMEZONE') ?: 'Asia/Manila');

// Start one consistent, safer session for every protected page.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionPath = dirname(__DIR__) . '/storage/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0775, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/helpers.php';
