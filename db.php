<?php
declare(strict_types=1);

// Keep local XAMPP defaults working while allowing deployment configuration
// through environment variables instead of hard-coding secrets in the app.
$servername = getenv('JPCS_DB_HOST') ?: 'localhost';
$username = getenv('JPCS_DB_USER') ?: 'root';
$password = getenv('JPCS_DB_PASSWORD') ?: '';
$dbname = getenv('JPCS_DB_NAME') ?: 'jpcs_event_system';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    error_log('JPCS database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The database is temporarily unavailable.');
}
