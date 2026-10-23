<?php
declare(strict_types=1);

// Student masterlist: schema, section access, file parsing and lookups used by
// masterlist.php, student sign-up, event registration and pending review.

const MASTERLIST_MAX_BYTES = 10 * 1024 * 1024;
const MASTERLIST_MAX_ROWS = 5000;
// Student ID numbers are stored as digits only (2024-00253 and 202400253 are the same ID).
const MASTERLIST_ID_PATTERN = '/^\d{7,12}$/';

function masterlist_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS masterlist_uploads (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS student_masterlist (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS officer_sections (
        user_id INT NOT NULL,
        course_year_section VARCHAR(100) NOT NULL,
        assigned_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, course_year_section),
        CONSTRAINT fk_officer_sections_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    // Upgrade tables created before batches existed: one row per student per batch.
    $hasBatch = static fn(string $table): bool => (int) $conn->query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = 'batch'")->fetch_assoc()['n'] > 0;
    if (!$hasBatch('masterlist_uploads')) {
        $conn->query("ALTER TABLE masterlist_uploads ADD COLUMN batch VARCHAR(9) NOT NULL DEFAULT '' AFTER file_type");
    }
    if (!$hasBatch('student_masterlist')) {
        $conn->query("ALTER TABLE student_masterlist ADD COLUMN batch VARCHAR(9) NOT NULL DEFAULT '' AFTER student_name,
            DROP INDEX uq_masterlist_student_id, ADD UNIQUE KEY uq_masterlist_student_batch (student_id_no, batch),
            DROP INDEX idx_masterlist_section, ADD KEY idx_masterlist_group (batch, course_year_section, student_name)");
    }
    $done = true;
}

/* ---------- Courses, year levels, sections and batches ---------- */

const MASTERLIST_COURSES = ['BSIT'];
const MASTERLIST_YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

function masterlist_section_letters(): array
{
    return range('A', 'Z');
}

// Course + year level => section letters, e.g. 'BSIT 1st Year' => ['A'..'Z'].
// Shared by the masterlist and the event registration form.
function masterlist_section_groups(): array
{
    $groups = [];
    foreach (MASTERLIST_COURSES as $course) {
        foreach (MASTERLIST_YEAR_LEVELS as $year) $groups[$course . ' ' . $year] = masterlist_section_letters();
    }
    return $groups;
}

function masterlist_sections(): array
{
    $sections = [];
    foreach (masterlist_section_groups() as $year => $letters) {
        foreach ($letters as $letter) $sections[] = $year . ' - ' . $letter;
    }
    return $sections;
}

function masterlist_make_section(string $course, string $yearLevel, string $letter): string
{
    return $course . ' ' . $yearLevel . ' - ' . $letter;
}

/** "BSIT 1st Year - D" -> ['course' => 'BSIT', 'year' => '1st Year', 'letter' => 'D'] */
function masterlist_split_section(string $section): array
{
    return preg_match('/^(\S+) (\d\w{2} Year) - ([A-Z])$/', $section, $m)
        ? ['course' => $m[1], 'year' => $m[2], 'letter' => $m[3]]
        : ['course' => '', 'year' => '', 'letter' => ''];
}

// "BSIT 1st Year - D" -> "BSIT 1st Year" (the student account's course/year).
function masterlist_course_year(string $section): string
{
    return trim((string) preg_replace('/\s*-\s*[A-Z]$/', '', $section));
}

// School-year batches run June to May: October 2026 is in the 2026-2027 batch.
function masterlist_current_batch(?DateTimeInterface $today = null): string
{
    $today ??= new DateTimeImmutable('today');
    $start = (int) $today->format('Y') - ((int) $today->format('n') < 6 ? 1 : 0);
    return $start . '-' . ($start + 1);
}

/** Batches offered in the forms: two school years back to three ahead, newest first. */
function masterlist_batches(): array
{
    $start = (int) substr(masterlist_current_batch(), 0, 4);
    $batches = [];
    for ($year = $start + 3; $year >= $start - 2; $year--) $batches[] = $year . '-' . ($year + 1);
    return $batches;
}

function masterlist_valid_batch(string $batch): bool
{
    return (bool) preg_match('/^(\d{4})-(\d{4})$/', $batch, $m) && (int) $m[2] === (int) $m[1] + 1 && (int) $m[1] >= 2000 && (int) $m[1] <= 2100;
}

function masterlist_batch_label(string $batch): string
{
    return $batch === '' ? 'No batch' : $batch . ' batch';
}

function masterlist_is_adviser(array $user): bool
{
    return in_array($user['role'] ?? '', ['admin_advisor', 'admin_adviser'], true);
}

// Sections a panel user may manage: advisers manage all, officers only their assigned ones.
function masterlist_user_sections(mysqli $conn, array $user): array
{
    if (masterlist_is_adviser($user)) return masterlist_sections();
    $stmt = $conn->prepare('SELECT course_year_section FROM officer_sections WHERE user_id=?');
    $userId = (int) ($user['id'] ?? 0);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $assigned = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'course_year_section');
    $stmt->close();
    return array_values(array_intersect(masterlist_sections(), $assigned));
}

function masterlist_normalize_id(string $raw): string
{
    return (string) preg_replace('/[\s\-]+/', '', trim($raw));
}

function masterlist_valid_id(string $id): bool
{
    return (bool) preg_match(MASTERLIST_ID_PATTERN, $id);
}

// "DELA CRUZ,  juan m" -> "Dela Cruz, Juan M." ; names without a comma keep their order.
function masterlist_normalize_name(string $raw): string
{
    $name = trim((string) preg_replace('/\s+/u', ' ', $raw), " \t\n\r\0\x0B,;");
    if ($name === '') return '';
    $name = (string) preg_replace('/\s*,\s*/u', ', ', $name);
    $name = mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    // A lone trailing initial gets its period: "Juan M" -> "Juan M."
    $name = (string) preg_replace('/(\s\p{Lu})$/u', '$1.', $name);
    return $name;
}

function masterlist_valid_name(string $name): bool
{
    return mb_strlen($name) >= 3 && mb_strlen($name) <= 150
        && preg_match('/\p{L}.*\p{L}/u', $name) && !preg_match('/\d/', $name);
}

/**
 * Masterlist rows keyed by normalized student ID. With $batch, only that batch;
 * otherwise each student's newest batch (used by sign-up, registration and pending review).
 * @return array<string,array>
 */
function masterlist_lookup_many(mysqli $conn, array $ids, ?string $batch = null): array
{
    masterlist_ensure_schema($conn);
    $ids = array_values(array_unique(array_filter(array_map('masterlist_normalize_id', $ids), 'masterlist_valid_id')));
    $found = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $sql = 'SELECT * FROM student_masterlist WHERE student_id_no IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
        $params = $chunk;
        if ($batch !== null) { $sql .= ' AND batch = ?'; $params[] = $batch; }
        $stmt = $conn->prepare($sql . ' ORDER BY batch ASC');
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        // Ascending order: the newest batch overwrites older ones.
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $found[$row['student_id_no']] = $row;
        $stmt->close();
    }
    return $found;
}

function masterlist_find(mysqli $conn, string $studentId): ?array
{
    $id = masterlist_normalize_id($studentId);
    return masterlist_lookup_many($conn, [$id])[$id] ?? null;
}

function masterlist_count(mysqli $conn): int
{
    masterlist_ensure_schema($conn);
    return (int) ($conn->query('SELECT COUNT(*) AS n FROM student_masterlist')->fetch_assoc()['n'] ?? 0);
}

// "Dela Cruz, Juan M." -> surname / given name / middle initial for the registration form.
function masterlist_split_name(string $name): array
{
    $parts = ['surname' => '', 'given_name' => '', 'middle_initial' => ''];
    if (!str_contains($name, ',')) { $parts['given_name'] = $name; return $parts; }
    [$surname, $rest] = array_map('trim', explode(',', $name, 2));
    $parts['surname'] = $surname;
    if (preg_match('/^(.*?)\s+(\p{L})\.?$/u', $rest, $m)) {
        $parts['given_name'] = $m[1];
        $parts['middle_initial'] = mb_strtoupper($m[2]);
    } else {
        $parts['given_name'] = $rest;
    }
    return $parts;
}

/* ---------- Upload validation and parsing ---------- */

/** @return array{path:string,name:string,type:string} */
function masterlist_validate_upload(array $file): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Choose a PDF, Excel (.xlsx) or CSV file to upload.');
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > MASTERLIST_MAX_BYTES) {
        throw new RuntimeException('The file is larger than 10 MB. Split the masterlist into smaller files.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('The upload did not finish. Please try again.');
    $name = basename((string) ($file['name'] ?? 'masterlist'));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = [
        'pdf' => ['application/pdf'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'text/x-csv'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];
    if (!isset($allowed[$extension])) {
        throw new RuntimeException($extension === 'xls'
            ? 'Old Excel files (.xls) are not supported. In Excel, use File > Save As > Excel Workbook (.xlsx).'
            : 'Only PDF, Excel (.xlsx) and CSV files are accepted.');
    }
    if (!in_array($mime, $allowed[$extension], true)) {
        throw new RuntimeException('The file content does not match its .' . $extension . ' extension. Re-export the file and try again.');
    }
    return ['path' => $file['tmp_name'], 'name' => $name, 'type' => $extension];
}

/** @return list<array{line:int,id:string,name:string}> raw rows, before cleaning */
function masterlist_parse(string $path, string $type): array
{
    $rows = match ($type) {
        'csv' => masterlist_rows_from_table(masterlist_read_csv($path)),
        'xlsx' => masterlist_rows_from_table(masterlist_read_xlsx($path)),
        'pdf' => masterlist_rows_from_text(masterlist_pdf_text($path)),
        default => throw new RuntimeException('Unsupported file type.'),
    };
    if (!$rows) {
        throw new RuntimeException('No student rows were found. The file needs a Student ID No. and a Student Name on each row.');
    }
    if (count($rows) > MASTERLIST_MAX_ROWS) {
        throw new RuntimeException('The file has more than ' . MASTERLIST_MAX_ROWS . ' rows. Upload one section at a time.');
    }
    return $rows;
}

function masterlist_read_csv(string $path): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) throw new RuntimeException('Could not read the CSV file.');
    $first = (string) fgets($handle);
    rewind($handle);
    $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    $table = [];
    while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
        $table[] = array_map(static fn($cell) => trim((string) mb_convert_encoding((string) $cell, 'UTF-8', 'UTF-8, Windows-1252')), $cells);
        if (count($table) > MASTERLIST_MAX_ROWS + 50) break;
    }
    fclose($handle);
    if ($table) $table[0][0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $table[0][0] ?? '');
    return $table;
}

// Reads the first worksheet of an .xlsx file with PHP's zip and XML support (no extra library).
function masterlist_read_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Could not open the Excel file. Save it again as .xlsx and retry.');
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        foreach ($doc->si ?? [] as $item) {
            $text = '';
            foreach ($item->xpath('.//*[local-name()="t"]') as $t) $text .= (string) $t;
            $shared[] = $text;
        }
    }
    $sheetPath = 'xl/worksheets/sheet1.xml';
    if (($workbook = $zip->getFromName('xl/workbook.xml')) !== false && ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
        $wb = simplexml_load_string($workbook, 'SimpleXMLElement', LIBXML_NONET);
        $firstSheet = $wb->sheets->sheet[0] ?? null;
        $relId = $firstSheet ? (string) $firstSheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
        foreach (simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET)->Relationship ?? [] as $rel) {
            if ((string) $rel['Id'] === $relId) $sheetPath = 'xl/' . ltrim(str_replace('/xl/', '', (string) $rel['Target']), '/');
        }
    }
    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) throw new RuntimeException('The Excel file has no readable worksheet.');
    $sheet = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);
    $table = [];
    foreach ($sheet->sheetData->row ?? [] as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            $column = 0;
            foreach (str_split(preg_replace('/\d+/', '', (string) $cell['r'])) as $letter) $column = $column * 26 + (ord($letter) - 64);
            $type = (string) $cell['t'];
            $value = match ($type) {
                's' => $shared[(int) $cell->v] ?? '',
                'inlineStr' => (string) ($cell->is->t ?? ''),
                default => (string) $cell->v,
            };
            // Numeric IDs can come back as "202400253.0" or "2.02400253E8".
            if ($type === '' && is_numeric($value) && preg_match('/[eE.]/', $value)) $value = number_format((float) $value, 0, '', '');
            $cells[max(0, $column - 1)] = trim($value);
        }
        if ($cells) {
            $filled = array_fill(0, max(array_keys($cells)) + 1, '');
            $table[] = array_replace($filled, $cells);
        }
        if (count($table) > MASTERLIST_MAX_ROWS + 50) break;
    }
    return $table;
}

// Table rows -> student rows. Uses header names when present, otherwise detects the ID column.
function masterlist_rows_from_table(array $table): array
{
    $columns = ['id' => null, 'name' => null, 'last' => null, 'first' => null, 'middle' => null];
    $headerRow = -1;
    foreach (array_slice($table, 0, 10, true) as $index => $cells) {
        $found = [];
        foreach ($cells as $col => $cell) {
            $label = strtolower(preg_replace('/[^a-z ]/i', ' ', (string) $cell));
            $label = trim((string) preg_replace('/\s+/', ' ', $label));
            if ($label === '') continue;
            // A bare "No." column is a row counter, not the student ID.
            if (preg_match('/\bid\b|\bstudent (no|number)\b/', $label) && !str_contains($label, 'name')) $found['id'] ??= $col;
            elseif (preg_match('/\b(last|sur) ?name\b|\bfamily name\b/', $label)) $found['last'] ??= $col;
            elseif (preg_match('/\b(first|given) ?name\b/', $label)) $found['first'] ??= $col;
            elseif (preg_match('/\bmiddle\b|^mi$|\bm i\b/', $label)) $found['middle'] ??= $col;
            elseif (str_contains($label, 'name')) $found['name'] ??= $col;
        }
        if (isset($found['id']) && (isset($found['name']) || isset($found['last']))) {
            $columns = array_replace($columns, $found);
            $headerRow = $index;
            break;
        }
    }
    $rows = [];
    foreach ($table as $index => $cells) {
        if ($index <= $headerRow || !array_filter($cells, static fn($c) => trim((string) $c) !== '')) continue;
        if ($headerRow >= 0) {
            $id = (string) ($cells[$columns['id']] ?? '');
            if ($columns['last'] !== null) {
                $name = trim((string) ($cells[$columns['last']] ?? '')) . ', ' . trim((string) ($cells[$columns['first']] ?? ''));
                if ($columns['middle'] !== null && ($mi = trim((string) ($cells[$columns['middle']] ?? ''))) !== '') $name .= ' ' . $mi;
            } else {
                $name = (string) ($cells[$columns['name']] ?? '');
            }
        } else {
            // No header: the ID is the first cell that looks like an ID; the name is the longest text cell.
            $id = '';
            $name = '';
            foreach ($cells as $cell) {
                $cell = trim((string) $cell);
                if ($id === '' && masterlist_valid_id(masterlist_normalize_id($cell))) { $id = $cell; continue; }
                if (preg_match('/\p{L}/u', $cell) && mb_strlen($cell) > mb_strlen($name)) $name = $cell;
            }
            if ($id === '' && $name === '') continue;
        }
        $rows[] = ['line' => $index + 1, 'id' => $id, 'name' => $name];
    }
    return $rows;
}

function masterlist_pdf_text(string $path): string
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PDF reading is not installed on this server. Upload the masterlist as Excel (.xlsx) or CSV, or run "composer install" (see MASTERLIST-SETUP.md).');
    }
    require_once $autoload;
    try {
        $text = (new \Smalot\PdfParser\Parser())->parseFile($path)->getText();
    } catch (Throwable $error) {
        error_log('JPCS masterlist PDF: ' . $error->getMessage());
        throw new RuntimeException('This PDF could not be read (it may be password-protected or damaged). Export it again, or upload Excel/CSV.');
    }
    if (mb_strlen((string) preg_replace('/\s+/', '', $text)) < 20) {
        $ocr = masterlist_ocr_text($path);
        if ($ocr === null) {
            throw new RuntimeException('This PDF has no selectable text, so it looks like a scanned image. Upload the masterlist as Excel (.xlsx) or CSV, or enable OCR (see MASTERLIST-SETUP.md).');
        }
        $text = $ocr;
    }
    return $text;
}

// Optional OCR for scanned PDFs. Runs only when both tools are configured, e.g.
// JPCS_PDFTOPPM="C:\poppler\Library\bin\pdftoppm.exe" and JPCS_TESSERACT="C:\Program Files\Tesseract-OCR\tesseract.exe".
function masterlist_ocr_text(string $pdfPath): ?string
{
    $pdftoppm = getenv('JPCS_PDFTOPPM') ?: '';
    $tesseract = getenv('JPCS_TESSERACT') ?: '';
    if (!is_file($pdftoppm) || !is_file($tesseract)) return null;
    $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jpcs_ocr_' . bin2hex(random_bytes(6));
    mkdir($work, 0700);
    try {
        exec(escapeshellarg($pdftoppm) . ' -r 300 -gray -png -l 30 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($work . DIRECTORY_SEPARATOR . 'page') . ' 2>&1', $out, $code);
        if ($code !== 0) { error_log('JPCS OCR pdftoppm: ' . implode(' ', $out)); return null; }
        $pages = glob($work . DIRECTORY_SEPARATOR . 'page*.png') ?: [];
        natsort($pages);
        $text = '';
        foreach ($pages as $page) {
            $lines = [];
            exec(escapeshellarg($tesseract) . ' ' . escapeshellarg($page) . ' stdout --psm 6 2>&1', $lines, $code);
            if ($code === 0) $text .= implode("\n", $lines) . "\n";
        }
        return trim($text) === '' ? null : $text;
    } finally {
        array_map('unlink', glob($work . DIRECTORY_SEPARATOR . '*') ?: []);
        @rmdir($work);
    }
}

// PDF/OCR text -> student rows. Each row is a line with an ID number; the name is the rest of the line,
// or the next line when the PDF puts table cells on separate lines.
function masterlist_rows_from_text(string $text): array
{
    $lines = preg_split('/\R/u', $text) ?: [];
    $idPattern = '/(?<![\d-])(\d{4}[\s-]?\d{3,8})(?![\d-])/';
    $sectionPattern = '/\bBS[A-Z]{2,5}\b[\s\w-]*$/u';
    $rows = [];
    $pending = null;
    foreach ($lines as $number => $line) {
        $line = trim((string) preg_replace('/[\t ]+/u', ' ', $line));
        if ($line === '') continue;
        if (preg_match($idPattern, $line, $match)) {
            $rest = trim(str_replace($match[0], ' ', $line));
            $rest = (string) preg_replace('/^\d{1,4}[.)]?\s+/', '', $rest);   // row number "12." / "12)"
            $rest = trim((string) preg_replace($sectionPattern, '', $rest)); // trailing course/section column
            $rest = trim((string) preg_replace('/\s+\b[MF]\b$/', '', $rest)); // trailing sex column
            $hasName = (bool) preg_match('/\p{L}{2,}/u', $rest);
            $rows[] = ['line' => $number + 1, 'id' => $match[1], 'name' => $hasName ? $rest : ''];
            $pending = $hasName ? null : array_key_last($rows);
        } elseif ($pending !== null && preg_match('/\p{L}{2,}/u', $line) && !preg_match('/student|name|section|course|page \d/i', $line)) {
            $rows[$pending]['name'] = (string) preg_replace($sectionPattern, '', $line);
            $pending = null;
        }
    }
    return $rows;
}

/* ---------- Preview classification ---------- */

/**
 * Cleans rows and marks each one: new, update, duplicate, invalid-id, missing-name, locked.
 * @return list<array{line:int,raw_id:string,raw_name:string,id:string,name:string,status:string,note:string,existing:?array}>
 */
function masterlist_classify(mysqli $conn, array $rows, array $allowedSections, string $batch): array
{
    $existing = masterlist_lookup_many($conn, array_column($rows, 'id'), $batch);
    $seen = [];
    $result = [];
    foreach ($rows as $row) {
        $id = masterlist_normalize_id((string) $row['id']);
        $name = masterlist_normalize_name((string) $row['name']);
        $record = $existing[$id] ?? null;
        $status = 'new';
        $note = '';
        if ($id === '') { $status = 'invalid-id'; $note = 'Missing Student ID No.'; }
        elseif (!masterlist_valid_id($id)) { $status = 'invalid-id'; $note = 'ID must be 7–12 digits (dashes allowed).'; }
        elseif ($name === '') { $status = 'missing-name'; $note = 'Missing student name.'; }
        elseif (!masterlist_valid_name($name)) { $status = 'missing-name'; $note = 'Name looks invalid.'; }
        elseif (isset($seen[$id])) { $status = 'duplicate'; $note = 'Same ID as line ' . $seen[$id] . ' in this file.'; }
        elseif ($record && !in_array($record['course_year_section'], $allowedSections, true)) {
            $status = 'locked'; $note = 'Already listed under ' . $record['course_year_section'] . ' in this batch, which you do not manage.';
        } elseif ($record) {
            $status = 'update';
            $note = $record['student_name'] === $name && $record['course_year_section'] !== ''
                ? 'Already listed under ' . $record['course_year_section'] . '.'
                : 'Currently "' . $record['student_name'] . '" (' . $record['course_year_section'] . ').';
        }
        if ($id !== '' && !isset($seen[$id])) $seen[$id] = (int) $row['line'];
        $result[] = ['line' => (int) $row['line'], 'raw_id' => (string) $row['id'], 'raw_name' => (string) $row['name'], 'id' => $id, 'name' => $name, 'status' => $status, 'note' => $note, 'existing' => $record];
    }
    return $result;
}

/* ---------- Saving ---------- */

/**
 * Saves reviewed rows into student_masterlist and logs the upload. Every row is validated
 * again here; the preview is only a convenience.
 * @param list<array{id:string,name:string,include:bool}> $rows
 * @return array{added:int,updated:int,skipped:int,reasons:array<string,int>,upload_id:int}
 */
function masterlist_save_rows(mysqli $conn, array $user, array $allowedSections, string $batch, string $section, string $filename, string $type, array $rows): array
{
    masterlist_ensure_schema($conn);
    if (!in_array($section, $allowedSections, true)) {
        throw new RuntimeException('You cannot upload a masterlist for ' . $section . '.');
    }
    if (!masterlist_valid_batch($batch)) {
        throw new RuntimeException('Choose a batch such as ' . masterlist_current_batch() . '.');
    }
    $counts = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'reasons' => [], 'upload_id' => 0];
    $skip = static function (string $reason) use (&$counts): void {
        $counts['skipped']++;
        $counts['reasons'][$reason] = ($counts['reasons'][$reason] ?? 0) + 1;
    };
    $existing = masterlist_lookup_many($conn, array_column($rows, 'id'), $batch);
    $userId = (int) $user['id'];
    $userName = (string) ($user['name'] ?? $user['username'] ?? '');
    $role = (string) $user['role'];

    $conn->begin_transaction();
    try {
        $log = $conn->prepare('INSERT INTO masterlist_uploads (uploaded_by, uploader_name, uploader_role, original_filename, file_type, batch, course_year_section) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $log->bind_param('issssss', $userId, $userName, $role, $filename, $type, $batch, $section);
        $log->execute();
        $uploadId = (int) $log->insert_id;
        $log->close();

        $insert = $conn->prepare('INSERT INTO student_masterlist (student_id_no, student_name, batch, course_year_section, uploaded_by, uploader_role, upload_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $update = $conn->prepare('UPDATE student_masterlist SET student_name=?, course_year_section=?, uploaded_by=?, uploader_role=?, upload_id=? WHERE id=?');
        $seen = [];
        foreach ($rows as $row) {
            if (empty($row['include'])) { $skip('removed in preview'); continue; }
            $id = masterlist_normalize_id((string) ($row['id'] ?? ''));
            $name = masterlist_normalize_name((string) ($row['name'] ?? ''));
            if (!masterlist_valid_id($id)) { $skip('missing or invalid ID'); continue; }
            if (!masterlist_valid_name($name)) { $skip('missing or invalid name'); continue; }
            if (isset($seen[$id])) { $skip('duplicate ID in file'); continue; }
            $seen[$id] = true;
            $record = $existing[$id] ?? null;
            if ($record === null) {
                $insert->bind_param('ssssisi', $id, $name, $batch, $section, $userId, $role, $uploadId);
                $insert->execute();
                $counts['added']++;
            } elseif (!in_array($record['course_year_section'], $allowedSections, true)) {
                $skip('listed in a section you do not manage');
            } elseif ($record['student_name'] === $name && $record['course_year_section'] === $section) {
                $skip('already up to date');
            } else {
                $recordId = (int) $record['id'];
                $update->bind_param('ssisii', $name, $section, $userId, $role, $uploadId, $recordId);
                $update->execute();
                $counts['updated']++;
            }
        }
        $insert->close();
        $update->close();

        $totals = $conn->prepare('UPDATE masterlist_uploads SET rows_added=?, rows_updated=?, rows_skipped=? WHERE id=?');
        $totals->bind_param('iiii', $counts['added'], $counts['updated'], $counts['skipped'], $uploadId);
        $totals->execute();
        $totals->close();
        $conn->commit();
        $counts['upload_id'] = $uploadId;
        return $counts;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
