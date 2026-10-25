<?php
declare(strict_types=1);
// Student Masterlist: upload (PDF/XLSX/CSV) with preview, organised management, history and officer section access.
require_once __DIR__ . '/admin_features.php';
require_once __DIR__ . '/includes/permissions.php';
permissions_require($conn, 'masterlist');
require_once __DIR__ . '/includes/masterlist.php';
require_once __DIR__ . '/includes/audit.php';

masterlist_ensure_schema($conn);
$mlUser = af_user($conn, (int) $_SESSION['user_id']);
if (!in_array($mlUser['role'] ?? '', ['admin_advisor', 'admin_adviser', 'officer'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$mlAdviser = masterlist_is_adviser($mlUser);
$mlSections = masterlist_user_sections($conn, $mlUser);
$mlTabs = ['upload' => 'Upload', 'entries' => 'Masterlist', 'history' => 'Upload history'] + ($mlAdviser ? ['access' => 'Section access'] : []);
$mlTab = is_string($_GET['tab'] ?? null) && isset($mlTabs[$_GET['tab']]) ? $_GET['tab'] : 'upload';

// Year levels and section letters this user may pick, e.g. ['BSIT' => ['1st Year' => ['A', 'D']]].
$mlChoices = [];
foreach ($mlSections as $section) {
    $parts = masterlist_split_section($section);
    $mlChoices[$parts['course']][$parts['year']][] = $parts['letter'];
}

function ml_flash(string $type, string $message, string $route): never
{
    $_SESSION['ml_flash'] = ['type' => $type, 'message' => $message];
    redirect($route);
}

function ml_in(int $count): string
{
    return implode(',', array_fill(0, $count, '?'));
}

// Reads course / year level / section letter from a form and returns "BSIT 1st Year - D".
function ml_posted_section(array $allowed): string
{
    $course = count(MASTERLIST_COURSES) === 1 ? MASTERLIST_COURSES[0] : (string) ($_POST['course'] ?? '');
    $section = masterlist_make_section($course, (string) ($_POST['year_level'] ?? ''), (string) ($_POST['section_letter'] ?? ''));
    if (!in_array($section, $allowed, true)) throw new RuntimeException('Choose a year level and section that you manage.');
    return $section;
}

function ml_posted_batch(): string
{
    $batch = (string) ($_POST['batch'] ?? '');
    if (!masterlist_valid_batch($batch)) throw new RuntimeException('Choose the batch (school year) for this masterlist.');
    return $batch;
}

// Batch, course, year level and section pickers shared by the upload and edit forms.
function ml_section_fields(array $choices, string $prefix, string $batch = '', string $section = ''): void
{
    $current = masterlist_split_section($section);
    $batches = masterlist_batches();
    if ($batch !== '' && !in_array($batch, $batches, true)) $batches[] = $batch;
    ?>
    <div class="ml-section-fields" data-ml-section-fields data-ml-choices="<?= e(json_encode($choices)) ?>">
      <label for="<?= $prefix ?>-batch">Batch<select id="<?= $prefix ?>-batch" name="batch" required>
        <?php if ($batch === '') { ?><option value="">Choose batch</option><?php } ?>
        <?php foreach ($batches as $option) { ?><option value="<?= e($option) ?>"<?= $option === ($batch !== '' ? $batch : masterlist_current_batch()) ? ' selected' : '' ?>><?= e(masterlist_batch_label($option)) ?></option><?php } ?>
      </select></label>
      <?php if (count(MASTERLIST_COURSES) > 1) { ?>
      <label for="<?= $prefix ?>-course">Course<select id="<?= $prefix ?>-course" name="course" required>
        <?php foreach (array_keys($choices) as $course) { ?><option<?= $course === $current['course'] ? ' selected' : '' ?>><?= e($course) ?></option><?php } ?>
      </select></label>
      <?php } ?>
      <label for="<?= $prefix ?>-year">Year level<select id="<?= $prefix ?>-year" name="year_level" required data-ml-year>
        <option value="">Choose year</option>
        <?php foreach (MASTERLIST_YEAR_LEVELS as $year) { $usable = false; foreach ($choices as $years) $usable = $usable || isset($years[$year]); ?>
          <option value="<?= e($year) ?>"<?= $year === $current['year'] ? ' selected' : '' ?><?= $usable ? '' : ' disabled' ?>><?= e($year) ?></option>
        <?php } ?>
      </select></label>
      <label for="<?= $prefix ?>-letter">Section<select id="<?= $prefix ?>-letter" name="section_letter" required data-ml-letter>
        <option value="">Choose section</option>
        <?php foreach (masterlist_section_letters() as $letter) { ?><option value="<?= $letter ?>"<?= $letter === $current['letter'] ? ' selected' : '' ?>>Section <?= $letter ?></option><?php } ?>
      </select></label>
    </div>
    <?php
}

/* ---------- Actions (POST, then redirect) ---------- */
if (request_method_is('POST')) {
    af_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'preview') {
            $batch = ml_posted_batch();
            $section = ml_posted_section($mlSections);
            $file = masterlist_validate_upload($_FILES['masterlist'] ?? []);
            $rows = masterlist_parse($file['path'], $file['type']);
            $token = bin2hex(random_bytes(12));
            $_SESSION['ml_preview'] = ['token' => $token, 'filename' => $file['name'], 'type' => $file['type'], 'batch' => $batch, 'section' => $section, 'rows' => $rows];
            redirect('masterlist.php?tab=upload&preview=' . $token);
        }
        if ($action === 'cancel') {
            unset($_SESSION['ml_preview']);
            ml_flash('success', 'Upload cancelled. Nothing was saved.', 'masterlist.php?tab=upload');
        }
        if ($action === 'confirm') {
            $preview = $_SESSION['ml_preview'] ?? null;
            if (!$preview || !hash_equals($preview['token'], (string) ($_POST['token'] ?? ''))) {
                throw new RuntimeException('This preview has expired. Upload the file again.');
            }
            // The preview script packs every row into one field so long lists stay within PHP's input limit.
            $submitted = json_decode((string) ($_POST['rows_json'] ?? ''), true);
            if (!is_array($submitted)) {
                $submitted = [];
                foreach ((array) ($_POST['rows'] ?? []) as $row) {
                    $submitted[] = ['id' => (string) ($row['id'] ?? ''), 'name' => (string) ($row['name'] ?? ''), 'include' => !empty($row['include'])];
                }
            }
            $rows = array_map(static fn($row) => ['id' => (string) ($row['id'] ?? ''), 'name' => (string) ($row['name'] ?? ''), 'include' => !empty($row['include'])], array_slice(array_values($submitted), 0, MASTERLIST_MAX_ROWS));
            $result = masterlist_save_rows($conn, $mlUser, $mlSections, $preview['batch'], $preview['section'], $preview['filename'], $preview['type'], $rows);
            unset($_SESSION['ml_preview']);
            audit_log($conn, 'masterlist.upload', 'Uploaded ' . $preview['section'] . ' masterlist (' . masterlist_batch_label($preview['batch']) . ')', 'masterlist_upload', $result['upload_id'], ['File' => ['', $preview['filename']], 'Added' => ['', (string) $result['added']], 'Updated' => ['', (string) $result['updated']], 'Skipped' => ['', (string) $result['skipped']]]);
            $reasons = [];
            foreach ($result['reasons'] as $reason => $count) $reasons[] = $count . ' ' . $reason;
            ml_flash('success', sprintf('Saved %s for %s (%s): %d added, %d updated, %d skipped%s.', $preview['filename'], $preview['section'], masterlist_batch_label($preview['batch']), $result['added'], $result['updated'], $result['skipped'], $reasons ? ' (' . implode(', ', $reasons) . ')' : ''), 'masterlist.php?tab=entries&batch=' . urlencode($preview['batch']));
        }
        if ($action === 'update_entry' || $action === 'delete_entry') {
            $entryId = (int) ($_POST['entry_id'] ?? 0);
            $stmt = $conn->prepare('SELECT * FROM student_masterlist WHERE id=?');
            $stmt->bind_param('i', $entryId);
            $stmt->execute();
            $entry = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$entry || !in_array($entry['course_year_section'], $mlSections, true)) throw new RuntimeException('That entry is not in one of your sections.');
            $back = 'masterlist.php?tab=entries' . (isset($_POST['return']) && is_string($_POST['return']) ? '&' . preg_replace('/[^\w=&%.+-]/', '', $_POST['return']) : '');
            if ($action === 'delete_entry') {
                $stmt = $conn->prepare('DELETE FROM student_masterlist WHERE id=?');
                $stmt->bind_param('i', $entryId);
                $stmt->execute();
                $stmt->close();
                audit_log($conn, 'masterlist.delete', 'Removed ' . $entry['student_name'] . ' from the ' . $entry['course_year_section'] . ' masterlist', 'masterlist', $entryId, ['Student ID' => [$entry['student_id_no'], ''], 'Batch' => [$entry['batch'], '']]);
                ml_flash('success', 'Removed ' . $entry['student_name'] . ' (' . $entry['student_id_no'] . ') from the ' . masterlist_batch_label($entry['batch']) . ' masterlist.', $back);
            }
            $newId = masterlist_normalize_id((string) ($_POST['student_id_no'] ?? ''));
            $newName = masterlist_normalize_name((string) ($_POST['student_name'] ?? ''));
            if (!masterlist_valid_id($newId)) throw new RuntimeException('Student ID No. must be 7–12 digits (dashes allowed).');
            if (!masterlist_valid_name($newName)) throw new RuntimeException('Enter the student name, for example "Dela Cruz, Juan M."');
            $newBatch = ml_posted_batch();
            $newSection = ml_posted_section($mlSections);
            $other = masterlist_lookup_many($conn, [$newId], $newBatch)[$newId] ?? null;
            if ($other && (int) $other['id'] !== $entryId) throw new RuntimeException('Student ID ' . $newId . ' is already in the ' . masterlist_batch_label($newBatch) . ' masterlist as ' . $other['student_name'] . '.');
            $role = (string) $mlUser['role'];
            $userId = (int) $mlUser['id'];
            $stmt = $conn->prepare('UPDATE student_masterlist SET student_id_no=?, student_name=?, batch=?, course_year_section=?, uploaded_by=?, uploader_role=? WHERE id=?');
            $stmt->bind_param('ssssisi', $newId, $newName, $newBatch, $newSection, $userId, $role, $entryId);
            $stmt->execute();
            $stmt->close();
            audit_log($conn, 'masterlist.update', 'Edited masterlist entry ' . $newName, 'masterlist', $entryId, audit_diff($entry, ['student_id_no' => $newId, 'student_name' => $newName, 'batch' => $newBatch, 'course_year_section' => $newSection], ['student_id_no' => 'Student ID', 'student_name' => 'Name', 'batch' => 'Batch', 'course_year_section' => 'Section']));
            ml_flash('success', 'Saved changes for ' . $newName . '.', $back);
        }
        if ($action === 'save_access') {
            af_require_admin($conn);
            $officerId = (int) ($_POST['officer_id'] ?? 0);
            $officer = af_user($conn, $officerId);
            if (($officer['role'] ?? '') !== 'officer') throw new RuntimeException('Choose an officer account.');
            $chosen = array_values(array_intersect(masterlist_sections(), array_map('strval', (array) ($_POST['sections'] ?? []))));
            $adviserId = (int) $mlUser['id'];
            $conn->begin_transaction();
            $stmt = $conn->prepare('DELETE FROM officer_sections WHERE user_id=?');
            $stmt->bind_param('i', $officerId);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare('INSERT INTO officer_sections (user_id, course_year_section, assigned_by) VALUES (?, ?, ?)');
            foreach ($chosen as $section) {
                $stmt->bind_param('isi', $officerId, $section, $adviserId);
                $stmt->execute();
            }
            $stmt->close();
            $conn->commit();
            audit_log($conn, 'masterlist.access', 'Set section access for ' . $officer['name'], 'user', $officerId, ['Sections' => ['', $chosen ? implode(', ', $chosen) : 'none']]);
            ml_flash('success', $officer['name'] . ' now manages ' . ($chosen ? count($chosen) . ' section' . (count($chosen) === 1 ? '' : 's') . ' (' . implode(', ', $chosen) . ')' : 'no sections') . '.', 'masterlist.php?tab=access');
        }
        throw new RuntimeException('Unknown action.');
    } catch (mysqli_sql_exception $error) {
        error_log('JPCS masterlist DB: ' . $error->getMessage());
        ml_flash('error', 'The database rejected this change, so nothing was saved. Please try again.', 'masterlist.php?tab=' . (in_array($action, ['update_entry', 'delete_entry'], true) ? 'entries' : ($action === 'save_access' ? 'access' : 'upload')));
    } catch (RuntimeException $error) {
        $tab = in_array($action, ['update_entry', 'delete_entry'], true) ? 'entries' : ($action === 'save_access' ? 'access' : 'upload');
        $keep = $action === 'confirm' && isset($_SESSION['ml_preview']) ? '&preview=' . $_SESSION['ml_preview']['token'] : '';
        ml_flash('error', $error->getMessage(), 'masterlist.php?tab=' . $tab . $keep);
    } catch (Throwable $error) {
        error_log('JPCS masterlist: ' . $error->getMessage());
        ml_flash('error', 'Something went wrong while saving. Nothing was changed. Please try again.', 'masterlist.php?tab=' . ($action === 'save_access' ? 'access' : 'upload'));
    }
}

$mlFlash = $_SESSION['ml_flash'] ?? null;
unset($_SESSION['ml_flash']);

af_head('Student Masterlist');
?>
<p class="ml-intro">Upload each section's official list of students for a batch (school year). Listed students can sign up, get their event registrations confirmed automatically, and are marked in pending review.</p>
<?php if ($mlFlash) { ?><p class="<?= $mlFlash['type'] === 'success' ? 'success' : 'error' ?>" role="<?= $mlFlash['type'] === 'success' ? 'status' : 'alert' ?>"><?= e($mlFlash['message']) ?></p><?php } ?>
<nav class="toolbar ml-tabs" aria-label="Masterlist sections">
  <?php foreach ($mlTabs as $key => $label) { ?><a class="btn" href="masterlist.php?tab=<?= e($key) ?>"<?= $key === $mlTab ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php } ?>
</nav>
<?php if (!$mlSections) { ?>
  <div class="empty-state">No sections are assigned to your account yet. Ask an adviser to give you access under Student Masterlist &rsaquo; Section access.</div>
<?php } ?>

<?php /* ---------- Upload + preview ---------- */ if ($mlTab === 'upload' && $mlSections) {
  $preview = $_SESSION['ml_preview'] ?? null;
  if ($preview && hash_equals($preview['token'], (string) ($_GET['preview'] ?? ''))) {
    $classified = masterlist_classify($conn, $preview['rows'], $mlSections, $preview['batch']);
    $tally = array_count_values(array_column($classified, 'status'));
    $labels = ['new' => 'New', 'update' => 'Will update', 'duplicate' => 'Duplicate ID', 'invalid-id' => 'Invalid ID', 'missing-name' => 'Missing name', 'locked' => 'Other section'];
?>
  <section class="table-panel ml-preview" aria-labelledby="ml-preview-title">
    <h2 id="ml-preview-title">Review <?= e($preview['filename']) ?></h2>
    <p class="ml-target"><span><?= e(masterlist_batch_label($preview['batch'])) ?></span><span><?= e($preview['section']) ?></span></p>
    <p>Check every row before saving. Edit any cell, or untick <strong>Keep</strong> to leave a row out. Rows with problems are left out until they are fixed. Students already in this batch are updated unless you untick them.</p>
    <p class="ml-tally" data-ml-tally>
      <?php foreach ($labels as $key => $label) { ?><span class="ml-status is-<?= e($key) ?>" data-count="<?= e($key) ?>"><?= e($label) ?>: <b><?= (int) ($tally[$key] ?? 0) ?></b></span><?php } ?>
      <label class="ml-problems-toggle"><input type="checkbox" data-ml-problems> Show problems only</label>
    </p>
    <form method="post" data-ml-preview-form>
      <?php af_field(); ?>
      <input type="hidden" name="action" value="confirm">
      <input type="hidden" name="token" value="<?= e($preview['token']) ?>">
      <input type="hidden" name="rows_json" value="">
      <div class="table-wrapper">
        <table class="ml-preview-table" data-no-enhance>
          <thead><tr><th scope="col">Keep</th><th scope="col">Line</th><th scope="col">Student ID No.</th><th scope="col">Student Name</th><th scope="col">Status</th></tr></thead>
          <tbody>
          <?php foreach ($classified as $i => $row) { $ok = in_array($row['status'], ['new', 'update'], true); ?>
            <tr data-ml-row data-status="<?= e($row['status']) ?>" data-existing="<?= $row['existing'] ? e($row['existing']['student_id_no']) : '' ?>" data-locked="<?= $row['status'] === 'locked' ? '1' : '' ?>"<?= !$ok && $row['status'] !== 'locked' ? ' data-auto-off="1"' : '' ?>>
              <td><input type="checkbox" name="rows[<?= $i ?>][include]" value="1" aria-label="Keep line <?= (int) $row['line'] ?>"<?= $ok ? ' checked' : '' ?><?= $row['status'] === 'locked' ? ' disabled' : '' ?>></td>
              <td><?= (int) $row['line'] ?></td>
              <td><input type="text" name="rows[<?= $i ?>][id]" value="<?= e($row['id'] !== '' ? $row['id'] : $row['raw_id']) ?>" inputmode="numeric" maxlength="20" aria-label="Student ID No., line <?= (int) $row['line'] ?>"></td>
              <td><input type="text" name="rows[<?= $i ?>][name]" value="<?= e($row['name'] !== '' ? $row['name'] : $row['raw_name']) ?>" maxlength="150" aria-label="Student name, line <?= (int) $row['line'] ?>"></td>
              <td><span class="ml-status is-<?= e($row['status']) ?>" data-ml-status><?= e($labels[$row['status']]) ?></span><small data-ml-note><?= e($row['note']) ?></small></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
      <div class="form-actions ml-actions">
        <button type="submit" class="btn-primary">Confirm &amp; Save</button>
        <button type="submit" form="ml-cancel-form" class="btn-secondary">Cancel upload</button>
        <span class="ml-save-summary" data-ml-summary></span>
      </div>
    </form>
    <form method="post" id="ml-cancel-form"><?php af_field(); ?><input type="hidden" name="action" value="cancel"></form>
  </section>
<?php } else { ?>
  <section class="panel ml-upload" aria-labelledby="ml-upload-title">
    <h2 id="ml-upload-title">Upload a masterlist</h2>
    <form method="post" enctype="multipart/form-data" class="ml-upload-form">
      <?php af_field(); ?>
      <input type="hidden" name="action" value="preview">
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= MASTERLIST_MAX_BYTES ?>">
      <p class="ml-step">1. Choose where these students belong</p>
      <?php ml_section_fields($mlChoices, 'ml-up'); ?>
      <p class="ml-step">2. Choose the file</p>
      <label class="ml-drop" for="ml-file" data-ml-drop>
        <strong>Choose a file or drop it here</strong>
        <span>PDF (text-based), Excel .xlsx or CSV &middot; up to 10 MB &middot; up to <?= MASTERLIST_MAX_ROWS ?> students</span>
        <span class="ml-file-name" data-ml-file-name></span>
        <input id="ml-file" type="file" name="masterlist" accept=".pdf,.xlsx,.csv,application/pdf,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      </label>
      <p class="ml-hint">Each row needs a <strong>Student ID No.</strong> and a <strong>Student Name</strong>. For Excel/CSV, a header row such as "Student ID No. | Student Name" (or "Last Name | First Name | MI") is detected automatically. Scanned PDFs cannot be read yet; export the list to Excel instead.</p>
      <div class="form-actions"><button type="submit" class="btn-primary">Read file &amp; preview</button></div>
    </form>
  </section>
<?php } } ?>

<?php /* ---------- Masterlist, organised by batch > year level > section ---------- */ if ($mlTab === 'entries' && $mlSections) {
  $sectionIn = 'course_year_section IN (' . ml_in(count($mlSections)) . ')';
  $stmt = $conn->prepare("SELECT DISTINCT batch FROM student_masterlist WHERE $sectionIn ORDER BY batch DESC");
  $stmt->bind_param(str_repeat('s', count($mlSections)), ...$mlSections);
  $stmt->execute();
  $knownBatches = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'batch');
  $stmt->close();
  $q = trim((string) ($_GET['q'] ?? ''));
  // Default view: the newest batch that has students.
  $batchFilter = isset($_GET['batch']) && is_string($_GET['batch']) ? $_GET['batch'] : ($knownBatches[0] ?? masterlist_current_batch());
  if ($batchFilter !== 'all' && !in_array($batchFilter, $knownBatches, true) && !masterlist_valid_batch($batchFilter)) $batchFilter = 'all';
  $yearFilter = is_string($_GET['year'] ?? null) && in_array($_GET['year'], MASTERLIST_YEAR_LEVELS, true) ? $_GET['year'] : '';
  $letterFilter = is_string($_GET['letter'] ?? null) && in_array($_GET['letter'], masterlist_section_letters(), true) ? $_GET['letter'] : '';

  $where = [$sectionIn];
  $params = $mlSections;
  if ($batchFilter !== 'all') { $where[] = 'batch = ?'; $params[] = $batchFilter; }
  if ($yearFilter !== '') { $where[] = "course_year_section LIKE CONCAT('% ', ?, ' - %')"; $params[] = $yearFilter; }
  if ($letterFilter !== '') { $where[] = "course_year_section LIKE CONCAT('% - ', ?)"; $params[] = $letterFilter; }
  if ($q !== '') { $where[] = '(student_name LIKE ? OR student_id_no LIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . masterlist_normalize_id($q) . '%'; }
  $sqlWhere = implode(' AND ', $where);
  $types = str_repeat('s', count($params));

  // Groups (batch + section) are paginated, so a section is never split across pages.
  $stmt = $conn->prepare("SELECT batch, course_year_section, COUNT(*) AS students FROM student_masterlist WHERE $sqlWhere GROUP BY batch, course_year_section ORDER BY batch DESC, course_year_section");
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  $total = array_sum(array_column($groups, 'students'));
  $groupsPerPage = 8;
  $pages = max(1, (int) ceil(count($groups) / $groupsPerPage));
  $page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
  $pageGroups = array_slice($groups, ($page - 1) * $groupsPerPage, $groupsPerPage);
  $students = [];
  if ($pageGroups) {
    $pairs = [];
    $pairParams = $params;
    foreach ($pageGroups as $group) { $pairs[] = '(batch = ? AND course_year_section = ?)'; $pairParams[] = $group['batch']; $pairParams[] = $group['course_year_section']; }
    $stmt = $conn->prepare("SELECT m.*, u.name AS uploader FROM student_masterlist m LEFT JOIN users u ON u.id = m.uploaded_by WHERE $sqlWhere AND (" . implode(' OR ', $pairs) . ') ORDER BY m.batch DESC, m.course_year_section, m.student_name');
    $stmt->bind_param(str_repeat('s', count($pairParams)), ...$pairParams);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $students[$row['batch']][$row['course_year_section']][] = $row;
    $stmt->close();
  }
  $editId = (int) ($_GET['edit'] ?? 0);
  $filterQuery = array_filter(['batch' => $batchFilter, 'year' => $yearFilter, 'letter' => $letterFilter, 'q' => $q], static fn($v) => $v !== '');
  $returnQuery = http_build_query($filterQuery + ($page > 1 ? ['page' => $page] : []));
  $pageLink = static fn(int $n) => 'masterlist.php?' . http_build_query(['tab' => 'entries'] + $filterQuery + ($n > 1 ? ['page' => $n] : []));
  $yearLevels = [];
  foreach ($mlChoices as $years) $yearLevels += $years;
?>
  <form method="get" class="toolbar ml-filters" role="search">
    <input type="hidden" name="tab" value="entries">
    <label>Batch<select name="batch">
      <option value="all"<?= $batchFilter === 'all' ? ' selected' : '' ?>>All batches</option>
      <?php foreach (array_unique(array_merge($knownBatches, [$batchFilter !== 'all' ? $batchFilter : masterlist_current_batch()])) as $option) { ?><option value="<?= e($option) ?>"<?= $option === $batchFilter ? ' selected' : '' ?>><?= e(masterlist_batch_label($option)) ?></option><?php } ?>
    </select></label>
    <label>Year level<select name="year">
      <option value="">All years</option>
      <?php foreach (MASTERLIST_YEAR_LEVELS as $year) if (isset($yearLevels[$year])) { ?><option value="<?= e($year) ?>"<?= $year === $yearFilter ? ' selected' : '' ?>><?= e($year) ?></option><?php } ?>
    </select></label>
    <label>Section<select name="letter">
      <option value="">All sections</option>
      <?php foreach (masterlist_section_letters() as $letter) { ?><option value="<?= $letter ?>"<?= $letter === $letterFilter ? ' selected' : '' ?>>Section <?= $letter ?></option><?php } ?>
    </select></label>
    <label class="ml-search">Search<input type="search" name="q" value="<?= e($q) ?>" placeholder="Name or Student ID No."></label>
    <button type="submit">Apply</button>
    <?php if ($yearFilter !== '' || $letterFilter !== '' || $q !== '') { ?><a class="btn btn-ghost" href="masterlist.php?tab=entries&amp;batch=<?= e(urlencode($batchFilter)) ?>">Clear</a><?php } ?>
  </form>
  <div class="ml-overview">
    <p role="status" class="ml-count"><strong><?= $total ?></strong> student<?= $total === 1 ? '' : 's' ?> in <strong><?= count($groups) ?></strong> section<?= count($groups) === 1 ? '' : 's' ?> &middot; <?= e($batchFilter === 'all' ? 'all batches' : masterlist_batch_label($batchFilter)) ?></p>
    <?php if (count($pageGroups) > 1) { ?><button type="button" class="btn btn-ghost" data-ml-toggle-all>Collapse all</button><?php } ?>
  </div>
  <?php if (!$groups) { ?>
    <div class="empty-state"><?= $q !== '' || $yearFilter !== '' || $letterFilter !== '' ? 'No students match these filters.' : 'No students in ' . e($batchFilter === 'all' ? 'any batch' : masterlist_batch_label($batchFilter)) . ' yet. Upload a masterlist to get started.' ?></div>
  <?php } ?>
  <?php $shownBatch = null; foreach ($pageGroups as $group) {
    $rows = $students[$group['batch']][$group['course_year_section']] ?? [];
    if ($batchFilter === 'all' && $group['batch'] !== $shownBatch) { $shownBatch = $group['batch']; ?><h2 class="ml-batch-heading"><?= e(masterlist_batch_label($shownBatch)) ?></h2><?php } ?>
  <details class="ml-group" open>
    <summary>
      <span class="ml-group-title"><?= e($group['course_year_section']) ?></span>
      <span class="ml-group-meta"><?= e(masterlist_batch_label($group['batch'])) ?> &middot; <?= (int) $group['students'] ?> student<?= (int) $group['students'] === 1 ? '' : 's' ?></span>
    </summary>
    <div class="table-wrapper">
      <table data-no-enhance>
        <thead><tr><th scope="col">#</th><th scope="col">Student ID No.</th><th scope="col">Student Name</th><th scope="col">Last updated by</th><th scope="col">Updated</th><th scope="col">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $n => $entry) { if ((int) $entry['id'] === $editId) { ?>
          <tr class="ml-editing"><td colspan="6">
            <form method="post" class="ml-edit-form">
              <?php af_field(); ?>
              <input type="hidden" name="action" value="update_entry">
              <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
              <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
              <label>Student ID No.<input type="text" name="student_id_no" value="<?= e($entry['student_id_no']) ?>" inputmode="numeric" maxlength="20" required></label>
              <label>Student Name<input type="text" name="student_name" value="<?= e($entry['student_name']) ?>" maxlength="150" required></label>
              <?php ml_section_fields($mlChoices, 'ml-edit', $entry['batch'], $entry['course_year_section']); ?>
              <div class="row-actions"><button type="submit" class="btn-primary">Save</button><a class="btn btn-ghost" href="<?= e($pageLink($page)) ?>">Cancel</a></div>
            </form>
          </td></tr>
        <?php } else { ?>
          <tr>
            <td><?= $n + 1 ?></td>
            <td><?= e($entry['student_id_no']) ?></td>
            <td><?= e($entry['student_name']) ?></td>
            <td><?= e($entry['uploader'] ?? 'Removed account') ?> <small>(<?= e(role_label($entry['uploader_role'])) ?>)</small></td>
            <td><?= e(date('M j, Y g:i A', strtotime($entry['updated_at']))) ?></td>
            <td><div class="row-actions">
              <a class="btn" href="<?= e($pageLink($page) . '&edit=' . (int) $entry['id']) ?>">Edit</a>
              <form method="post" class="inline-action" onsubmit="return confirm('Remove <?= e(addslashes($entry['student_name'])) ?> from the masterlist?');">
                <?php af_field(); ?><input type="hidden" name="action" value="delete_entry"><input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>"><input type="hidden" name="return" value="<?= e($returnQuery) ?>">
                <button type="submit" class="btn-danger">Delete</button>
              </form>
            </div></td>
          </tr>
        <?php } } ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php } ?>
  <?php if ($pages > 1) { ?>
  <nav class="admin-table-pagination ml-pagination" aria-label="Masterlist pages">
    <span>Page <?= $page ?> of <?= $pages ?> &middot; <?= $groupsPerPage ?> sections per page</span>
    <?php if ($page > 1) { ?><a class="btn" href="<?= e($pageLink($page - 1)) ?>">Previous</a><?php } ?>
    <?php for ($n = max(1, $page - 2); $n <= min($pages, $page + 2); $n++) { ?><a class="btn" href="<?= e($pageLink($n)) ?>"<?= $n === $page ? ' aria-current="page"' : '' ?>><?= $n ?></a><?php } ?>
    <?php if ($page < $pages) { ?><a class="btn" href="<?= e($pageLink($page + 1)) ?>">Next</a><?php } ?>
  </nav>
  <?php } ?>
<?php } ?>

<?php /* ---------- Upload history ---------- */ if ($mlTab === 'history' && $mlSections) {
  $stmt = $conn->prepare('SELECT * FROM masterlist_uploads WHERE course_year_section IN (' . ml_in(count($mlSections)) . ') ORDER BY created_at DESC, id DESC LIMIT 100');
  $stmt->bind_param(str_repeat('s', count($mlSections)), ...$mlSections);
  $stmt->execute();
  $uploads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
?>
  <div class="table-wrapper">
    <table>
      <thead><tr><th scope="col">When</th><th scope="col">Uploaded by</th><th scope="col">File</th><th scope="col">Batch</th><th scope="col">Section</th><th scope="col">Added</th><th scope="col">Updated</th><th scope="col">Skipped</th></tr></thead>
      <tbody>
      <?php foreach ($uploads as $upload) { ?>
        <tr>
          <td><?= e(date('M j, Y g:i A', strtotime($upload['created_at']))) ?></td>
          <td><?= e($upload['uploader_name']) ?> <small>(<?= e(role_label($upload['uploader_role'])) ?>)</small></td>
          <td><?= e($upload['original_filename']) ?> <small><?= e(strtoupper($upload['file_type'])) ?></small></td>
          <td><?= e(masterlist_batch_label($upload['batch'])) ?></td>
          <td><?= e($upload['course_year_section']) ?></td>
          <td><?= (int) $upload['rows_added'] ?></td>
          <td><?= (int) $upload['rows_updated'] ?></td>
          <td><?= (int) $upload['rows_skipped'] ?></td>
        </tr>
      <?php } if (!$uploads) { ?><tr><td colspan="8">No uploads yet.</td></tr><?php } ?>
      </tbody>
    </table>
  </div>
<?php } ?>

<?php /* ---------- Officer section access (advisers) ---------- */ if ($mlTab === 'access' && $mlAdviser) {
  $officers = $conn->query("SELECT id, name, username, position FROM users WHERE role='officer' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
  $assigned = [];
  foreach ($conn->query('SELECT user_id, course_year_section FROM officer_sections')->fetch_all(MYSQLI_ASSOC) as $row) $assigned[(int) $row['user_id']][] = $row['course_year_section'];
?>
  <p>Officers can upload and manage the masterlist only for the sections ticked here, in every batch. Advisers always have access to every section.</p>
  <?php foreach ($officers as $officer) { $mine = $assigned[(int) $officer['id']] ?? []; ?>
  <form method="post" class="panel ml-access">
    <?php af_field(); ?>
    <input type="hidden" name="action" value="save_access">
    <input type="hidden" name="officer_id" value="<?= (int) $officer['id'] ?>">
    <h2><?= e($officer['name']) ?> <small><?= e($officer['position'] ?: 'Officer') ?> &middot; <?= count($mine) ?> section<?= count($mine) === 1 ? '' : 's' ?></small></h2>
    <fieldset>
      <legend class="visually-hidden">Sections for <?= e($officer['name']) ?></legend>
      <?php foreach (masterlist_section_groups() as $year => $letters) { ?>
        <div class="ml-access-year"><strong><?= e($year) ?></strong>
          <?php foreach ($letters as $letter) { $section = $year . ' - ' . $letter; ?>
            <label><input type="checkbox" name="sections[]" value="<?= e($section) ?>"<?= in_array($section, $mine, true) ? ' checked' : '' ?>> <?= e($letter) ?></label>
          <?php } ?>
        </div>
      <?php } ?>
    </fieldset>
    <div class="form-actions"><button type="submit" class="btn-primary">Save access</button></div>
  </form>
  <?php } if (!$officers) { ?><div class="empty-state">There are no officer accounts yet.</div><?php } ?>
<?php } ?>
<script src="js/masterlist.js?v=<?= filemtime(__DIR__ . '/js/masterlist.js') ?>" defer></script>
<?php af_end();
