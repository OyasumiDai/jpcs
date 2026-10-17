<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function ensure_student_accounts_table(): void
{
    global $conn;
    $conn->query("CREATE TABLE IF NOT EXISTS student_accounts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(150) NOT NULL,
        student_id VARCHAR(50) NOT NULL UNIQUE,
        email VARCHAR(150) NOT NULL UNIQUE,
        course_year VARCHAR(100) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function current_student(): ?array
{
    if (empty($_SESSION['student_id'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['student_id'],
        'full_name' => (string) ($_SESSION['student_name'] ?? ''),
        'student_number' => (string) ($_SESSION['student_number'] ?? ''),
        'email' => (string) ($_SESSION['student_email'] ?? ''),
    ];
}

function require_student(): void
{
    if (current_student() === null) {
        redirect('student_login.php');
    }
}

ensure_student_accounts_table();
