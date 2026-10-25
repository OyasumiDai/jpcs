<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/permissions.php';
permissions_require($conn, 'registrations');

// The register list now lives in registrations.php (Registered tab).
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

$eventID = filter_var($_GET['event_id'] ?? '', FILTER_VALIDATE_INT);
$eventID = $eventID === false ? null : $eventID;
$yearLevel = is_string($_GET['year'] ?? null) ? $_GET['year'] : null;
$section = is_string($_GET['section'] ?? null) ? trim($_GET['section']) : null;
$sectionSql = $conn->real_escape_string($section ?? '');

$event = null;
$totalRegistered = 0;
$totalAttended = 0;
$students = null;
$sectionSummary = ['section_registered' => 0, 'section_attended' => 0];

// Predefined sections per year level
$yearSections = [
    "1st Year" => [
        "BSIT 1st Year - A", "BSIT 1st Year - B",
        "BSIT 1st Year - C", "BSIT 1st Year - D"
    ],
    "2nd Year" => [
        "BSIT 2nd Year - A", "BSIT 2nd Year - B",
        "BSIT 2nd Year - C", "BSIT 2nd Year - D"
    ],
    "3rd Year" => [
        "BSIT 3rd Year - A", "BSIT 3rd Year - B",
        "BSIT 3rd Year - C", "BSIT 3rd Year - D"
    ],
    "4th Year" => [
        "BSIT 4th Year - A", "BSIT 4th Year - B",
        "BSIT 4th Year - C", "BSIT 4th Year - D"
    ]
];

if ($eventID !== null) {
    $event = $conn->query("SELECT * FROM events WHERE id='$eventID'")->fetch_assoc();

    // Event summary
    $summary = $conn->query("
        SELECT COUNT(*) AS total_registered,
               SUM(CASE WHEN a.participant_id IS NOT NULL THEN 1 ELSE 0 END) AS total_attended
        FROM registrations r
        LEFT JOIN (SELECT event_id, participant_id FROM attendance GROUP BY event_id, participant_id) a
          ON r.control_number = a.participant_id AND r.event_id = a.event_id
        WHERE r.event_id='$eventID' AND r.status='confirmed'
    ")->fetch_assoc();

    $totalRegistered = $summary['total_registered'];
    $totalAttended = $summary['total_attended'] ?? 0;

    // Section student list
    if ($section) {
        $students = $conn->query("
            SELECT r.student_id, r.student_name, r.course_year_section,
                   CASE
                     WHEN a.participant_id IS NOT NULL THEN 'Attended'
                     ELSE CASE WHEN e.status='done' THEN 'Not attended' ELSE 'Not yet attended' END
                   END AS status
            FROM registrations r
            LEFT JOIN (SELECT event_id, participant_id FROM attendance GROUP BY event_id, participant_id) a
              ON r.control_number = a.participant_id AND r.event_id = a.event_id
            JOIN events e ON r.event_id = e.id
            WHERE r.event_id='$eventID'
              AND r.course_year_section='$sectionSql'
              AND r.status='confirmed'
            ORDER BY r.student_name ASC
        ");

        $sectionSummary = $conn->query("
            SELECT COUNT(*) AS section_registered,
                   COALESCE(SUM(CASE WHEN a.participant_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS section_attended
            FROM registrations r
            LEFT JOIN (SELECT event_id, participant_id FROM attendance GROUP BY event_id, participant_id) a
              ON r.control_number = a.participant_id AND r.event_id = a.event_id
            WHERE r.event_id='$eventID'
              AND r.course_year_section='$sectionSql'
              AND r.status='confirmed'
        ")->fetch_assoc();
    }
}

// Live totals use this authenticated route.
if (($_GET['counts'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$event || !$section) {
        http_response_code(404);
        echo json_encode(['error' => 'Event or section not found.']);
    } else {
        echo json_encode(['registered' => (int) $sectionSummary['section_registered'], 'attended' => (int) $sectionSummary['section_attended']]);
    }
    exit;
}

// Fetch all events
$events = $conn->query("SELECT * FROM events ORDER BY event_date ASC");

function registerListEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="assets/img/jpcs-logo.png?v=3">

  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register List</title>


<?php require __DIR__.'/includes/admin_head.php'; ?>
</head>

<body class="app-page admin-page feature-register-list">
  <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

  <div class="content">
    <header>
      <h1>Register List</h1>
    </header>

    <div class="section">
      <?php if ($eventID === null) { ?>

        <h2>Select Event</h2>

        <div class="events">
          <?php while($row = $events->fetch_assoc()) { ?>
            <div class="event-card">
              <img
                src="<?php echo registerListEscape(image_url($row['poster'])); ?>"
                alt="Poster"
              >

              <div>
                <h4><?php echo registerListEscape($row['title']); ?></h4>
                <p><?php echo registerListEscape($row['event_date']); ?></p>

                <a href="register_list.php?event_id=<?php echo urlencode((string)$row['id']); ?>">
                  View Event
                </a>
              </div>
            </div>
          <?php } ?>
        </div>

      <?php } elseif (!$event) { ?>

        <p class="error-message">Event not found. Please select an event.</p>
        <a href="register_list.php" class="back-btn">← Back to Events</a>

      <?php } elseif (!$yearLevel && !$section) { ?>

        <h2>
          <?php echo registerListEscape($event['title']); ?>
          (<?php echo registerListEscape($event['event_date']); ?>)
        </h2>

        <a href="register_list.php" class="back-btn">← Back to Events</a>

        <div class="stat-row summary" role="group" aria-label="Registration summary">
          <div class="stat-tile"><span>Registered</span><strong><?php echo registerListEscape($totalRegistered); ?></strong></div>
          <div class="stat-tile is-good"><span>Attended</span><strong><?php echo registerListEscape($totalAttended); ?></strong></div>
        </div>

        <h3>Select Year Level</h3>

        <div class="year-levels">
          <?php foreach ($yearSections as $year => $availableSections) { ?>
            <a
              class="year-box"
              href="register_list.php?event_id=<?php echo urlencode((string)$eventID); ?>&amp;year=<?php echo urlencode($year); ?>"
            >
              <?php echo registerListEscape($year); ?>
            </a>
          <?php } ?>
        </div>

      <?php } elseif ($yearLevel && !$section) { ?>

        <h2>
          <?php echo registerListEscape($event['title']); ?>
          - <?php echo registerListEscape($yearLevel); ?>
        </h2>

        <a
          href="register_list.php?event_id=<?php echo urlencode((string)$eventID); ?>"
          class="back-btn"
        >
          ← Back to Event
        </a>

        <?php if (isset($yearSections[$yearLevel])) { ?>
          <div class="table-wrapper">
            <table data-admin-table aria-label="Registration counts by section">
              <thead>
                <tr>
                  <th>Section</th>
                  <th>Registered</th>
                  <th>Attended</th>
                  <th>Action</th>
                </tr>
              </thead>

              <tbody>
                <?php foreach ($yearSections[$yearLevel] as $sec) {
                    $secSummary = $conn->query("
                        SELECT COUNT(*) AS section_registered,
                               SUM(CASE WHEN a.participant_id IS NOT NULL THEN 1 ELSE 0 END) AS section_attended
                        FROM registrations r
                        LEFT JOIN (SELECT event_id, participant_id FROM attendance GROUP BY event_id, participant_id) a
                          ON r.control_number = a.participant_id AND r.event_id = a.event_id
                        WHERE r.event_id='$eventID'
                          AND r.course_year_section='$sec'
                          AND r.status='confirmed'
                    ")->fetch_assoc();

                    $regCount = $secSummary['section_registered'] ?? 0;
                    $attCount = $secSummary['section_attended'] ?? 0;
                ?>
                  <tr>
                    <td><?php echo registerListEscape($sec); ?></td>
                    <td><?php echo registerListEscape($regCount); ?></td>
                    <td><?php echo registerListEscape($attCount); ?></td>
                    <td>
                      <a
                        class="view-link"
                        href="register_list.php?event_id=<?php echo urlencode((string)$eventID); ?>&amp;year=<?php echo urlencode($yearLevel); ?>&amp;section=<?php echo urlencode($sec); ?>"
                      >
                        View Students
                      </a>
                    </td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        <?php } else { ?>
          <p class="error-message">Invalid year level. Please go back and choose a year.</p>
        <?php } ?>

      <?php } elseif ($section) { ?>

        <h2 class="print-title">
          <?php echo registerListEscape($event['title']); ?>
          - <?php echo registerListEscape($section); ?>
        </h2>

        <a
          href="register_list.php?event_id=<?php echo urlencode((string)$eventID); ?>&amp;year=<?php echo urlencode((string)$yearLevel); ?>"
          class="back-btn"
        >
          ← Back to Year Level
        </a>

        <p class="summary">
          <strong>Registered:</strong>
          <span id="regCount"><?php echo registerListEscape($sectionSummary['section_registered']); ?></span>
          |
          <strong>Attended:</strong>
          <span id="attCount"><?php echo registerListEscape($sectionSummary['section_attended']); ?></span>
        </p>

        <p>
          <a href="javascript:window.print()" class="print-btn">
            🖨️ Print Section List
          </a>
        </p>

        <p><?= $students ? (int) $students->num_rows : 0 ?> confirmed students loaded.
          Totals refresh every 10 seconds; <a href="register_list.php?event_id=<?= (int) $eventID ?>&amp;year=<?= urlencode((string) $yearLevel) ?>&amp;section=<?= urlencode($section) ?>">refresh the list</a> for new registrations or scans.</p>
        <div class="table-wrapper">
          <table class="print-table" data-admin-table aria-label="Confirmed registrations in section">
            <thead>
              <tr>
                <th>Student ID</th>
                <th>Name</th>
                <th>Section</th>
                <th>Attendance</th>
              </tr>
            </thead>

            <tbody>
              <?php if ($students && $students->num_rows > 0) { ?>
                <?php while($row = $students->fetch_assoc()) { ?>
                  <tr>
                    <td><?php echo registerListEscape($row['student_id']); ?></td>
                    <td><?php echo registerListEscape($row['student_name']); ?></td>
                    <td><?php echo registerListEscape($row['course_year_section']); ?></td>
                    <td><?php echo registerListEscape($row['status']); ?></td>
                  </tr>
                <?php } ?>
              <?php } else { ?>
                <tr>
                  <td colspan="4" class="empty-state">
                    No students registered in this section.
                  </td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>

      <?php } ?>
    </div>
  </div>

  <!-- Live summary totals; table rows remain the loaded snapshot. -->
  <?php if ($event && $section) { ?>
    <script>
      const countsUrl = <?php
        echo json_encode(
            "register_list.php?counts=1&event_id=" . urlencode((string)$eventID) .
            "&section=" . urlencode($section),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
      ?>;

      function refreshCounts() {
        if (document.hidden) return;
        fetch(countsUrl, {cache: 'no-store'})
          .then(response => {
            if (!response.ok) {
              throw new Error("Unable to refresh counts.");
            }
            return response.json();
          })
          .then(data => {
            if (!Number.isInteger(data.registered) || !Number.isInteger(data.attended)) return;
            document.getElementById("regCount").textContent = data.registered;
            document.getElementById("attCount").textContent = data.attended;
          })
          .catch(error => {
            console.error("Count refresh failed:", error);
          });
      }

      setInterval(refreshCounts, 10000);
    </script>
  <?php } ?>
</body>
</html>
