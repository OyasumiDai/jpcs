<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/permissions.php';
permissions_require($conn, 'registrations');

// Section attendance now lives in registrations.php (Registered tab, filtered to the section).
$eventParam = filter_var($_GET['event_id'] ?? '', FILTER_VALIDATE_INT);
$sectionParam = is_string($_GET['section'] ?? null) ? trim($_GET['section']) : '';
redirect('registrations.php' . ($eventParam ? '?' . http_build_query(['event_id' => (int) $eventParam, 'tab' => 'registered'] + ($sectionParam !== '' ? ['section' => $sectionParam] : [])) : ''));

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch user role
$userID = $_SESSION['user_id'];
$user = $conn->query("SELECT role, name FROM users WHERE id='$userID'")->fetch_assoc();
$role = $user['role'];

// Get event + section
$eventID = filter_var($_GET['event_id'] ?? '', FILTER_VALIDATE_INT);
$eventID = $eventID === false ? null : $eventID;
$section = is_string($_GET['section'] ?? null) ? trim($_GET['section']) : '';
$sectionSql = $conn->real_escape_string($section);
$attendanceFilter = in_array($_GET['attendance'] ?? '', ['attended', 'absent'], true) ? $_GET['attendance'] : '';
$attendanceWhere = $attendanceFilter === 'attended' ? ' AND a.id IS NOT NULL' : ($attendanceFilter === 'absent' ? ' AND a.id IS NULL' : '');
$event    = null;
$students = null;
$counts   = ['registered' => 0, 'attended' => 0, 'not_attended' => 0];

if ($eventID !== null && $section) {
    $event = $conn->query("SELECT * FROM events WHERE id='$eventID'")->fetch_assoc();

    if ($event) {
        // Students list (alphabetical) - JOIN using control_number
        $students = $conn->query("
          SELECT r.student_name, r.student_id, r.course_year_section, r.email, r.contact,
                 CASE WHEN a.id IS NOT NULL THEN '✔ Attended' ELSE '❌ Not Attended' END AS attendance_status
          FROM registrations r
          LEFT JOIN (SELECT event_id, participant_id, MIN(id) AS id FROM attendance GROUP BY event_id, participant_id) a
            ON r.control_number = a.participant_id AND r.event_id = a.event_id
          WHERE r.event_id='$eventID' AND r.course_year_section='$sectionSql' AND r.status='confirmed' $attendanceWhere
          ORDER BY r.student_name ASC
        ");

        // Counts - using control_number
        $summary = $conn->query("
            SELECT COUNT(*) AS registered,
                   SUM(CASE WHEN a.participant_id IS NOT NULL THEN 1 ELSE 0 END) AS attended,
                   COUNT(*) - SUM(CASE WHEN a.participant_id IS NOT NULL THEN 1 ELSE 0 END) AS not_attended
            FROM registrations r
            LEFT JOIN (SELECT event_id, participant_id FROM attendance GROUP BY event_id, participant_id) a
              ON r.control_number = a.participant_id AND r.event_id = a.event_id
            WHERE r.event_id='$eventID' AND r.course_year_section='$sectionSql' AND r.status='confirmed'
        ")->fetch_assoc();

        $counts['registered']   = $summary['registered'];
        $counts['attended']     = $summary['attended'] ?? 0;
        $counts['not_attended'] = $summary['not_attended'] ?? 0;
    }
}

// Escape displayed text
function sectionEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="assets/img/jpcs-logo.png?v=3">

  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title><?php echo sectionEscape($event ? $event['title'] : "Attendance"); ?> - <?php echo sectionEscape($section); ?></title>


<?php require __DIR__.'/includes/admin_head.php'; ?>
</head>

<body class="app-page admin-page feature-attendance-section">
  <!-- Sidebar -->
  <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

  <!-- Content -->
  <div class="content">
    <header>
      <h1>
        <?php echo sectionEscape($event ? $event['title'] : "No Event Selected"); ?>
        - <?php echo sectionEscape($section); ?> Attendance
      </h1>
    </header>

    <!-- Back + Print buttons -->
    <div class="actions">
      <a
        href="attendance_event.php?event_id=<?php echo urlencode((string)$eventID); ?>"
        class="btn-back"
      >
        ← Back to Section Selection
      </a>

      <a href="javascript:window.print()" class="print-btn">
        🖨️ Print Section List
      </a>
    </div>

    <?php if ($event && $students) { ?>

      <div class="list-panel">
        <h2 class="print-title">
          <?php echo sectionEscape($event['title']); ?>
          - <?php echo sectionEscape($section); ?>
        </h2>

        <div class="stat-row print-summary" role="group" aria-label="Section attendance summary">
          <div class="stat-tile"><span>Registered</span><strong><?php echo sectionEscape($counts['registered']); ?></strong></div>
          <div class="stat-tile is-good"><span>Attended</span><strong><?php echo sectionEscape($counts['attended']); ?></strong></div>
          <div class="stat-tile is-warn"><span>Not Attended</span><strong><?php echo sectionEscape($counts['not_attended']); ?></strong></div>
        </div>

        <form method="get" class="actions">
          <input type="hidden" name="event_id" value="<?= (int) $eventID ?>">
          <input type="hidden" name="section" value="<?= sectionEscape($section) ?>">
          <label for="attendance-filter">Attendance</label>
          <select id="attendance-filter" name="attendance">
            <option value="">All confirmed students</option>
            <option value="attended" <?= $attendanceFilter === 'attended' ? 'selected' : '' ?>>Attended</option>
            <option value="absent" <?= $attendanceFilter === 'absent' ? 'selected' : '' ?>>Not yet attended</option>
          </select>
          <button type="submit">Apply filter</button>
        </form>
        <p><?= (int) $students->num_rows ?> matching students loaded. Summary totals cover the entire section.</p>
        <div class="table-wrapper">
          <table class="print-table" data-admin-table aria-label="Section attendance">
            <thead>
              <tr>
                <th>Name</th>
                <th>Student ID</th>
                <th>Course / Year &amp; Section</th>
                <th>Email</th>
                <th>Contact</th>
                <th>Status</th>
              </tr>
            </thead>

            <tbody>
              <?php if ($students->num_rows > 0) { ?>

                <?php while($row = $students->fetch_assoc()) { ?>
                  <tr>
                    <td>
                      <?php echo sectionEscape($row['student_name']); ?>
                    </td>

                    <td>
                      <?php echo sectionEscape($row['student_id']); ?>
                    </td>

                    <td>
                      <?php echo sectionEscape($row['course_year_section']); ?>
                    </td>

                    <td>
                      <?php echo sectionEscape($row['email']); ?>
                    </td>

                    <td>
                      <?php echo sectionEscape($row['contact']); ?>
                    </td>

                    <td>
                      <span class="status-badge <?php echo ($row['attendance_status'] == '✔ Attended') ? 'attended' : 'notyet'; ?>">
                        <?php echo sectionEscape($row['attendance_status']); ?>
                      </span>
                    </td>
                  </tr>
                <?php } ?>

              <?php } else { ?>

                <tr>
                  <td colspan="6" class="empty-state">
                    No confirmed students match this attendance filter.
                  </td>
                </tr>

              <?php } ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php } else { ?>

      <p class="error-message">
        No section selected. Please go back and choose a section.
      </p>

    <?php } ?>
  </div>
</body>
</html>
