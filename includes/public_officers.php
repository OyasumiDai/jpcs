<?php
declare(strict_types=1);

function ensure_public_officers_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS officer_profiles (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        position VARCHAR(100) DEFAULT NULL,
        photo VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
