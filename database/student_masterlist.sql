-- Student Masterlist feature (run once; masterlist.php also creates these tables on first use).
-- Requires the existing `users` table.

CREATE TABLE IF NOT EXISTS masterlist_uploads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uploaded_by INT NULL,
    uploader_name VARCHAR(100) NOT NULL,
    uploader_role VARCHAR(30) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_type VARCHAR(10) NOT NULL,
    batch VARCHAR(9) NOT NULL DEFAULT '',
    course_year_section VARCHAR(100) NOT NULL,
    rows_added INT UNSIGNED NOT NULL DEFAULT 0,
    rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
    rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_uploads_section (course_year_section),
    CONSTRAINT fk_uploads_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One row per student per batch (school year, e.g. 2026-2027).
-- student_id_no is stored as digits only (2024-00253 -> 202400253).
CREATE TABLE IF NOT EXISTS student_masterlist (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id_no VARCHAR(50) NOT NULL,
    student_name VARCHAR(150) NOT NULL,
    batch VARCHAR(9) NOT NULL DEFAULT '',
    course_year_section VARCHAR(100) NOT NULL,
    uploaded_by INT NULL,
    uploader_role VARCHAR(30) NOT NULL,
    upload_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_masterlist_student_batch (student_id_no, batch),
    KEY idx_masterlist_group (batch, course_year_section, student_name),
    KEY idx_masterlist_name (student_name),
    CONSTRAINT fk_masterlist_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_masterlist_upload FOREIGN KEY (upload_id) REFERENCES masterlist_uploads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Sections an officer may upload and manage. Advisers manage every section.
CREATE TABLE IF NOT EXISTS officer_sections (
    user_id INT NOT NULL,
    course_year_section VARCHAR(100) NOT NULL,
    assigned_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, course_year_section),
    CONSTRAINT fk_officer_sections_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
