<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/permissions.php';
permissions_require($conn, 'registrations');

// Attendance per event now lives in registrations.php.
$eventParam = filter_var($_GET['event_id'] ?? '', FILTER_VALIDATE_INT);
redirect('registrations.php' . ($eventParam ? '?event_id=' . (int) $eventParam . '&tab=attendance' : ''));

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch user role
$userID = $_SESSION['user_id'];
$user = $conn->query("SELECT role, name FROM users WHERE id='$userID'")->fetch_assoc();
$role = $user['role'];

// Get event ID
$eventID = filter_var($_GET['event_id'] ?? '', FILTER_VALIDATE_INT);
$eventID = $eventID === false ? null : $eventID;
$event = null;
$total = ['registered' => 0, 'attended' => 0, 'not_attended' => 0];
$sections = null;

// Accept event ID 0; only skip if null
if ($eventID !== null) {
    $event = $conn->query("SELECT * FROM events WHERE id='$eventID'")->fetch_assoc();

    if ($event) {
        // Overall summary
        $summary = $conn->query("
            SELECT COUNT(*) AS registered,
                   SUM(CASE WHEN a.participant_id IS NOT NULL THEN 1 ELSE 0 END) AS attended,
                   SUM(CASE WHEN a.participant_id IS NULL THEN 1 ELSE 0 END) AS not_attended
            FROM registrations r
            LEFT JOIN (SELECT event_id, participant_id FROM attendance GROUP BY event_id, participant_id) a
              ON r.control_number = a.participant_id AND r.event_id = a.event_id
            WHERE r.event_id='$eventID' AND r.status='confirmed'
        ")->fetch_assoc();

        $total['registered'] = $summary['registered'];
        $total['attended'] = $summary['attended'] ?? 0;
        $total['not_attended'] = $summary['not_attended'] ?? 0;

        // Section summary
        $sections = $conn->query("
            SELECT r.course_year_section,
                   COUNT(*) AS registered,
                   SUM(CASE WHEN a.participant_id IS NOT NULL THEN 1 ELSE 0 END) AS attended,
                   SUM(CASE WHEN a.participant_id IS NULL THEN 1 ELSE 0 END) AS not_attended
            FROM registrations r
            LEFT JOIN (SELECT event_id, participant_id FROM attendance GROUP BY event_id, participant_id) a
              ON r.control_number = a.participant_id AND r.event_id = a.event_id
            WHERE r.event_id='$eventID' AND r.status='confirmed'
            GROUP BY r.course_year_section
            ORDER BY r.course_year_section ASC
        ");
    }
}

// Escape text for display
function attendanceEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="assets/img/jpcs-logo.png?v=3">

  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title><?php echo attendanceEscape($event ? $event['title'] : "Attendance"); ?></title>


<?php require __DIR__.'/includes/admin_head.php'; ?>
</head>

<body class="app-page admin-page feature-attendance-event">
  <!-- Sidebar -->
  <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

  <!-- Content -->
  <div class="content">
    <header>
      <h1>
        <?php echo attendanceEscape($event ? $event['title'] : "No Event Selected"); ?>
        Attendance
      </h1>
    </header>

    <a href="attendance.php" class="btn-back">
      ← Back to Event Selection
    </a>

    <?php if ($event) { ?>
      <p><?= attendanceEscape($event['event_date']) ?> &middot; <?= attendanceEscape(ucfirst($event['status'])) ?>.
         <?= (int) $total['registered'] ?> confirmed registrations; all sections loaded below.</p>
      <p><a class="btn-back" href="scanner.php?event_id=<?= (int) $eventID ?>">Open scanner</a>
         <a class="btn-back" href="attendance_event.php?event_id=<?= (int) $eventID ?>">Refresh report</a></p>

      <!-- Same totals, styled as cards -->
      <div class="summary-grid">
        <div class="summary-card">
          <span class="summary-label">Registered</span>
          <span class="summary-value">
            <?php echo attendanceEscape($total['registered']); ?>
          </span>
        </div>

        <div class="summary-card">
          <span class="summary-label">Attended</span>
          <span class="summary-value">
            <?php echo attendanceEscape($total['attended']); ?>
          </span>
        </div>

        <div class="summary-card">
          <span class="summary-label">Not Attended</span>
          <span class="summary-value">
            <?php echo attendanceEscape($total['not_attended']); ?>
          </span>
        </div>
      </div>

      <div class="table-panel">
        <h2>Summary per Section</h2>

        <div class="table-wrapper">
          <table data-admin-table aria-label="Attendance summary per section">
            <thead>
              <tr>
                <th>Section</th>
                <th>Registered</th>
                <th>Attended</th>
                <th>Not Attended</th>
                <th>Action</th>
              </tr>
            </thead>

            <tbody>
              <?php if ($sections && $sections->num_rows > 0) { ?>
                <?php while($row = $sections->fetch_assoc()) { ?>
                  <tr>
                    <td>
                      <?php echo attendanceEscape($row['course_year_section']); ?>
                    </td>

                    <td>
                      <?php echo attendanceEscape($row['registered']); ?>
                    </td>

                    <td>
                      <?php echo attendanceEscape($row['attended']); ?>
                    </td>

                    <td>
                      <?php echo attendanceEscape($row['not_attended']); ?>
                    </td>

                    <td>
                      <a
                        href="attendance_section.php?event_id=<?php echo urlencode((string) $eventID); ?>&amp;section=<?php echo urlencode($row['course_year_section']); ?>"
                        class="view-link"
                      >
                        View List
                      </a>
                    </td>
                  </tr>
                <?php } ?>
              <?php } else { ?>
                <tr>
                  <td colspan="5" class="empty-state">
                    No attendance records yet.
                  </td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php } else { ?>

      <p class="error-message">
        No event selected. Please go back and choose an event.
      </p>

    <?php } ?>
  </div>
</body>
</html>
