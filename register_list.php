<?php
session_start();
include 'db.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch user role
$userID = $_SESSION['user_id'];
$user = $conn->query("SELECT role, name FROM users WHERE id='$userID'")->fetch_assoc();
$role = $user['role'];

$eventID   = isset($_GET['event_id']) ? $_GET['event_id'] : null;
$yearLevel = isset($_GET['year']) ? $_GET['year'] : null;
$section   = isset($_GET['section']) ? $_GET['section'] : null;

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
        LEFT JOIN attendance a
          ON r.student_id = a.participant_id AND r.event_id = a.event_id
        WHERE r.event_id='$eventID' AND r.status='confirmed'
    ")->fetch_assoc();

    $totalRegistered = $summary['total_registered'];
    $totalAttended = $summary['total_attended'];

    // Section student list
    if ($section) {
        $students = $conn->query("
            SELECT r.student_id, r.student_name, r.course_year_section,
                   CASE
                     WHEN a.participant_id IS NOT NULL THEN '✅ Attended'
                     ELSE CASE WHEN e.status='done' THEN '❌ Not Attended' ELSE '' END
                   END AS status
            FROM registrations r
            LEFT JOIN attendance a
              ON r.student_id = a.participant_id AND r.event_id = a.event_id
            JOIN events e ON r.event_id = e.id
            WHERE r.event_id='$eventID'
              AND r.course_year_section='$section'
              AND r.status='confirmed'
            ORDER BY r.student_name ASC
        ");

        $sectionSummary = $conn->query("
            SELECT COUNT(*) AS section_registered,
                   SUM(CASE WHEN a.participant_id IS NOT NULL THEN 1 ELSE 0 END) AS section_attended
            FROM registrations r
            LEFT JOIN attendance a
              ON r.student_id = a.participant_id AND r.event_id = a.event_id
            WHERE r.event_id='$eventID'
              AND r.course_year_section='$section'
              AND r.status='confirmed'
        ")->fetch_assoc();
    }
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
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register List</title>

  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      font-family: "Segoe UI", Arial, sans-serif;
      color: #fff;
      background:
        radial-gradient(ellipse at top right, rgba(255,120,0,.19), transparent 48%),
        radial-gradient(ellipse at bottom left, rgba(255,120,0,.12), transparent 48%),
        #090909;
      background-attachment: fixed;
    }

    .sidebar {
      position: fixed;
      top: 0;
      left: 0;
      z-index: 20;
      width: 220px;
      height: 100vh;
      padding: 20px 0;
      overflow-y: auto;
      background:
        radial-gradient(ellipse at bottom left, rgba(255,120,0,.22), transparent 60%),
        rgba(12,12,12,.94);
      border-right: 1px solid rgba(255,153,51,.22);
      box-shadow: 8px 0 35px rgba(0,0,0,.3);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
    }

    .sidebar h2 {
      margin: 8px 15px 25px;
      color: #ff9b36;
      text-align: center;
      font-size: 25px;
      overflow-wrap: anywhere;
    }

    .sidebar h2::after {
      content: "";
      display: block;
      width: 38px;
      height: 3px;
      margin: 14px auto 0;
      border-radius: 10px;
      background: #ff8a18;
      box-shadow: 0 0 14px rgba(255,138,24,.6);
    }

    .sidebar a {
      display: block;
      margin: 6px 10px;
      padding: 12px 16px;
      border-radius: 10px;
      color: #d7d7d7;
      text-decoration: none;
      font-size: 14px;
      font-weight: 600;
      line-height: 1.5;
      transition: background .2s ease, color .2s ease;
    }

    .sidebar a:hover {
      color: #fff;
      background: rgba(255,138,24,.12);
    }

    .sidebar a.active {
      color: #fff;
      background: linear-gradient(100deg, rgba(255,138,24,.28), rgba(255,138,24,.07));
      box-shadow: inset 3px 0 0 #ff8a18, 0 0 18px rgba(255,138,24,.08);
    }

    a:focus-visible {
      outline: 2px solid #ffb56b;
      outline-offset: 4px;
    }

    .content {
      flex: 1;
      min-width: 0;
      margin-left: 220px;
      padding: 28px;
    }

    header {
      padding: 28px;
      border: 1px solid rgba(255,166,70,.3);
      border-radius: 18px;
      background:
        linear-gradient(120deg, rgba(255,138,24,.15), rgba(255,138,24,.02) 65%),
        rgba(22,22,22,.83);
      box-shadow: 0 14px 35px rgba(0,0,0,.3), inset 0 1px 0 rgba(255,255,255,.06);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
    }

    header h1 {
      margin: 0;
      font-size: clamp(24px, 3vw, 34px);
      letter-spacing: -.6px;
    }

    header h1::after {
      content: "";
      display: block;
      width: 55px;
      height: 3px;
      margin-top: 15px;
      border-radius: 10px;
      background: #ff8a18;
      box-shadow: 0 0 16px rgba(255,138,24,.5);
    }

    .section {
      padding-top: 26px;
    }

    .section h2 {
      margin: 0 0 20px;
      font-size: 24px;
      line-height: 1.5;
      overflow-wrap: anywhere;
    }

    .section h3 {
      margin: 28px 0 18px;
      color: #ffb56b;
      font-size: 19px;
    }

    .events {
      display: flex;
      flex-wrap: wrap;
      gap: 22px;
      justify-content: center;
    }

    .event-card {
      display: flex;
      flex-direction: column;
      width: 220px;
      overflow: hidden;
      border: 1px solid rgba(255,166,70,.25);
      border-radius: 16px;
      background: rgba(23,23,23,.9);
      box-shadow: 0 12px 25px rgba(0,0,0,.3);
      transition: transform .25s ease, box-shadow .25s ease;
    }

    .event-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 16px 30px rgba(0,0,0,.4), 0 0 22px rgba(255,138,24,.15);
    }

    .event-card img {
      display: block;
      width: 100%;
      height: 150px;
      object-fit: cover;
      background: #1c1c1c;
    }

    .event-card > div {
      display: flex;
      flex: 1;
      flex-direction: column;
      padding: 16px;
      text-align: center;
    }

    .event-card h4 {
      margin: 0 0 10px;
      font-size: 17px;
      line-height: 1.5;
      overflow-wrap: anywhere;
    }

    .event-card p {
      margin: 0 0 18px;
      color: #bdbdbd;
      font-size: 14px;
    }

    .event-card a,
    .print-btn,
    .view-link {
      display: inline-block;
      padding: 10px 15px;
      border: 1px solid rgba(255,180,100,.3);
      border-radius: 9px;
      background: linear-gradient(135deg, #ff9c30, #ff7814);
      color: #111;
      text-decoration: none;
      font-size: 13px;
      font-weight: 700;
      box-shadow: 0 4px 16px rgba(255,120,20,.15);
      transition: box-shadow .2s ease;
    }

    .event-card a {
      display: block;
      margin-top: auto;
    }

    .event-card a:hover,
    .print-btn:hover,
    .view-link:hover {
      box-shadow: 0 4px 22px rgba(255,138,24,.35);
    }

    .back-btn {
      display: inline-block;
      padding: 11px 16px;
      border: 1px solid rgba(255,166,70,.25);
      border-radius: 10px;
      background: rgba(25,25,25,.85);
      color: #ffad5b;
      text-decoration: none;
      font-size: 14px;
      font-weight: 600;
    }

    .back-btn:hover {
      background: rgba(255,138,24,.12);
    }

    .summary {
      margin: 20px 0;
      padding: 18px 20px;
      border: 1px solid rgba(255,166,70,.22);
      border-radius: 12px;
      background: rgba(255,138,24,.06);
      color: #dedede;
      line-height: 1.8;
    }

    .summary strong {
      color: #ffb56b;
    }

    .year-levels {
      display: flex;
      flex-wrap: wrap;
      gap: 18px;
      justify-content: center;
      margin-top: 20px;
    }

    .year-box {
      flex: 1 1 160px;
      max-width: 260px;
      padding: 28px 24px;
      border: 1px solid rgba(255,166,70,.3);
      border-radius: 16px;
      background:
        radial-gradient(ellipse at bottom right, rgba(255,138,24,.18), transparent 70%),
        rgba(24,24,24,.9);
      color: #fff;
      text-align: center;
      text-decoration: none;
      font-size: 19px;
      font-weight: 700;
      box-shadow: 0 10px 25px rgba(0,0,0,.25);
      transition: transform .2s ease, border-color .2s ease;
    }

    .year-box:hover {
      transform: translateY(-4px);
      border-color: #ff9b36;
      color: #ffb56b;
    }

    .table-wrapper {
      overflow-x: auto;
      margin-top: 20px;
      border: 1px solid rgba(255,166,70,.22);
      border-radius: 14px;
      background: rgba(20,20,20,.9);
      box-shadow: 0 12px 30px rgba(0,0,0,.2);
    }

    table {
      width: 100%;
      min-width: 650px;
      border-collapse: collapse;
    }

    th,
    td {
      padding: 16px 14px;
      border-bottom: 1px solid rgba(255,255,255,.07);
      text-align: left;
      font-size: 14px;
      line-height: 1.5;
    }

    th {
      background: rgba(255,138,24,.14);
      color: #ffb56b;
      white-space: nowrap;
    }

    td {
      color: #e7e7e7;
    }

    tbody tr:nth-child(even) {
      background: rgba(255,255,255,.025);
    }

    tbody tr:hover {
      background: rgba(255,138,24,.055);
    }

    tbody tr:last-child td {
      border-bottom: none;
    }

    .empty-state {
      padding: 28px;
      color: #bdbdbd;
      text-align: center;
    }

    .error-message {
      padding: 18px;
      border: 1px solid rgba(255,111,111,.3);
      border-radius: 12px;
      background: rgba(255,70,70,.08);
      color: #ffb3b3;
    }

    @media (max-width: 700px) {
      body {
        display: block;
      }

      .sidebar {
        position: relative;
        width: 100%;
        height: auto;
        padding: 16px 10px;
        border-right: none;
        border-bottom: 1px solid rgba(255,153,51,.22);
      }

      .sidebar h2 {
        margin: 0 0 15px;
      }

      .sidebar a {
        display: inline-block;
        margin: 4px;
        padding: 9px 12px;
        font-size: 13px;
      }

      .content {
        margin-left: 0;
        padding: 18px;
      }

      header {
        padding: 22px;
      }

      .year-box {
        max-width: none;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      a,
      .event-card {
        transition: none !important;
      }

      .year-box:hover,
      .event-card:hover {
        transform: none;
      }
    }

    @media print {
      @page {
        margin: 12mm;
      }

      body {
        display: block;
        min-height: auto;
        background: #fff !important;
        color: #000 !important;
        font-family: Arial, sans-serif;
      }

      .sidebar,
      header,
      .print-btn,
      .back-btn {
        display: none !important;
      }

      .content {
        width: 100%;
        margin: 0;
        padding: 0;
      }

      .section {
        padding: 0;
      }

      .print-title {
        color: #000;
        font-size: 22px;
        font-weight: bold;
        margin-bottom: 20px;
      }

      .summary {
        padding: 0;
        border: none;
        background: none;
        color: #000;
      }

      .summary strong {
        color: #000;
      }

      .table-wrapper {
        overflow: visible;
        border: none;
        border-radius: 0;
        background: none;
        box-shadow: none;
      }

      .print-table {
        width: 100%;
        min-width: 0;
        border-collapse: collapse;
      }

      .print-table th,
      .print-table td {
        border: 1px solid #000;
        padding: 8px;
        color: #000 !important;
        background: #fff !important;
        text-align: left;
        overflow-wrap: anywhere;
      }

      thead {
        display: table-header-group;
      }

      tr {
        break-inside: avoid;
      }
    }
  </style>
</head>

<body>
  <div class="sidebar">
    <h2><?php echo registerListEscape(ucfirst($role)); ?></h2>

    <a href="dashboard.php">Dashboard</a>
    <a href="officers.php">Officer Information</a>
    <a href="pending.php">Student Registered Pending</a>
    <a href="scanner.php">Event Attendance Scanner</a>
    <a href="attendance.php">Attendance List per Event</a>
    <a href="register_list.php" class="active">Register List</a>
    <a href="events.php">Event Management</a>

    <?php if ($role == 'admin_advisor') { ?>
      <a href="accounts.php">Account Management</a>
    <?php } ?>

    <a href="logout.php">Logout</a>
  </div>

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
                src="<?php echo registerListEscape($row['poster']); ?>"
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

        <p class="summary">
          <strong>Registered:</strong>
          <?php echo registerListEscape($totalRegistered); ?> |
          <strong>Attended:</strong>
          <?php echo registerListEscape($totalAttended); ?>
        </p>

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
            <table>
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
                        LEFT JOIN attendance a
                          ON r.student_id = a.participant_id AND r.event_id = a.event_id
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

        <div class="table-wrapper">
          <table class="print-table">
            <thead>
              <tr>
                <th>Student ID</th>
                <th>Name</th>
                <th>Section</th>
              </tr>
            </thead>

            <tbody>
              <?php if ($students && $students->num_rows > 0) { ?>
                <?php while($row = $students->fetch_assoc()) { ?>
                  <tr>
                    <td><?php echo registerListEscape($row['student_id']); ?></td>
                    <td><?php echo registerListEscape($row['student_name']); ?></td>
                    <td><?php echo registerListEscape($row['course_year_section']); ?></td>
                  </tr>
                <?php } ?>
              <?php } else { ?>
                <tr>
                  <td colspan="3" class="empty-state">
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

  <!-- Existing automatic count refresh -->
  <?php if ($event && $section) { ?>
    <script>
      const countsUrl = <?php
        echo json_encode(
            "get_counts.php?event_id=" . urlencode((string)$eventID) .
            "&section=" . urlencode($section),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
      ?>;

      function refreshCounts() {
        fetch(countsUrl)
          .then(response => {
            if (!response.ok) {
              throw new Error("Unable to refresh counts.");
            }
            return response.json();
          })
          .then(data => {
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