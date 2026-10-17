<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

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
$eventID  = isset($_GET['event_id']) ? $_GET['event_id'] : null;
$section  = isset($_GET['section']) ? $_GET['section'] : null;
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
          LEFT JOIN attendance a
            ON r.control_number = a.participant_id AND r.event_id = a.event_id
          WHERE r.event_id='$eventID' AND r.course_year_section='$section' AND r.status='confirmed'
          ORDER BY r.student_name ASC
        ");

        // Counts - using control_number
        $summary = $conn->query("
            SELECT COUNT(*) AS registered,
                   SUM(CASE WHEN a.participant_id IS NOT NULL THEN 1 ELSE 0 END) AS attended,
                   COUNT(*) - SUM(CASE WHEN a.participant_id IS NOT NULL THEN 1 ELSE 0 END) AS not_attended
            FROM registrations r
            LEFT JOIN attendance a
              ON r.control_number = a.participant_id AND r.event_id = a.event_id
            WHERE r.event_id='$eventID' AND r.course_year_section='$section' AND r.status='confirmed'
        ")->fetch_assoc();

        $counts['registered']   = $summary['registered'];
        $counts['attended']     = $summary['attended'];
        $counts['not_attended'] = $summary['not_attended'];
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
<link rel="stylesheet" href="css/theme.css">
<script src="js/theme-init.js"></script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title><?php echo sectionEscape($event ? $event['title'] : "Attendance"); ?> - <?php echo sectionEscape($section); ?></title>

  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      font-family: "Segoe UI", Arial, sans-serif;
      color: #ffffff;
      background:
        radial-gradient(
          ellipse at top right,
          rgba(255, 120, 0, 0.19),
          transparent 48%
        ),
        radial-gradient(
          ellipse at bottom left,
          rgba(255, 120, 0, 0.12),
          transparent 48%
        ),
        #090909;
      background-attachment: fixed;
    }

    /* Sidebar */
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
        radial-gradient(
          ellipse at bottom left,
          rgba(255, 120, 0, 0.22),
          transparent 60%
        ),
        rgba(12, 12, 12, 0.94);
      border-right: 1px solid rgba(255, 153, 51, 0.22);
      box-shadow: 8px 0 35px rgba(0, 0, 0, 0.3);
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
      box-shadow: 0 0 14px rgba(255, 138, 24, 0.6);
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
      transition:
        background 0.2s ease,
        color 0.2s ease;
    }

    .sidebar a:hover {
      color: #ffffff;
      background: rgba(255, 138, 24, 0.12);
    }

    .sidebar a.active {
      color: #ffffff;
      background: linear-gradient(
        100deg,
        rgba(255, 138, 24, 0.28),
        rgba(255, 138, 24, 0.07)
      );
      box-shadow:
        inset 3px 0 0 #ff8a18,
        0 0 18px rgba(255, 138, 24, 0.08);
    }

    a:focus-visible {
      outline: 2px solid #ffb56b;
      outline-offset: 4px;
    }

    /* Content */
    .content {
      flex: 1;
      min-width: 0;
      margin-left: 220px;
      padding: 28px;
    }

    header {
      padding: 28px;
      border: 1px solid rgba(255, 166, 70, 0.3);
      border-radius: 18px;
      background:
        linear-gradient(
          120deg,
          rgba(255, 138, 24, 0.15),
          rgba(255, 138, 24, 0.02) 65%
        ),
        rgba(22, 22, 22, 0.83);
      box-shadow:
        0 14px 35px rgba(0, 0, 0, 0.3),
        inset 0 1px 0 rgba(255, 255, 255, 0.06);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
    }

    header h1 {
      margin: 0;
      color: #ffffff;
      font-size: clamp(23px, 3vw, 32px);
      font-weight: 700;
      letter-spacing: -0.6px;
      line-height: 1.4;
      overflow-wrap: anywhere;
    }

    header h1::after {
      content: "";
      display: block;
      width: 55px;
      height: 3px;
      margin-top: 15px;
      border-radius: 10px;
      background: #ff8a18;
      box-shadow: 0 0 16px rgba(255, 138, 24, 0.5);
    }

    /* Back + Print */
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin: 22px 0;
    }

    .btn-back,
    .print-btn {
      display: inline-block;
      padding: 11px 16px;
      border: 1px solid rgba(255, 166, 70, 0.25);
      border-radius: 10px;
      text-decoration: none;
      font-size: 14px;
      font-weight: 600;
      line-height: 1.5;
      transition:
        background 0.2s ease,
        box-shadow 0.2s ease;
    }

    .btn-back {
      background: rgba(25, 25, 25, 0.85);
      color: #ffad5b;
    }

    .btn-back:hover {
      background: rgba(255, 138, 24, 0.12);
    }

    .print-btn {
      background: linear-gradient(135deg, #ff9c30, #ff7814);
      color: #111111;
      box-shadow: 0 4px 16px rgba(255, 120, 20, 0.15);
    }

    .print-btn:hover {
      background: linear-gradient(135deg, #ffb45b, #ff8a24);
      box-shadow: 0 4px 22px rgba(255, 138, 24, 0.3);
    }

    /* Student list panel */
    .list-panel {
      padding: 24px;
      border: 1px solid rgba(255, 166, 70, 0.25);
      border-radius: 18px;
      background: rgba(20, 20, 20, 0.87);
      box-shadow:
        0 14px 35px rgba(0, 0, 0, 0.25),
        inset 0 1px 0 rgba(255, 255, 255, 0.04);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
    }

    .print-title {
      margin: 0 0 18px;
      color: #ffffff;
      font-size: 22px;
      font-weight: 600;
      line-height: 1.4;
      overflow-wrap: anywhere;
    }

    .print-title::after {
      content: "";
      display: block;
      width: 35px;
      height: 3px;
      margin-top: 12px;
      border-radius: 10px;
      background: #ff8a18;
    }

    .print-summary {
      margin: 0 0 22px;
      padding: 15px 18px;
      border: 1px solid rgba(255, 166, 70, 0.18);
      border-radius: 10px;
      background: rgba(255, 138, 24, 0.06);
      color: #dedede;
      font-size: 14px;
      line-height: 1.8;
    }

    .print-summary strong {
      color: #ffb56b;
    }

    /* Table */
    .table-wrapper {
      overflow-x: auto;
      border: 1px solid rgba(255, 166, 70, 0.18);
      border-radius: 12px;
    }

    .print-table {
      width: 100%;
      min-width: 850px;
      border-collapse: collapse;
    }

    .print-table th,
    .print-table td {
      padding: 15px 14px;
      text-align: left;
      border-bottom: 1px solid rgba(255, 255, 255, 0.07);
      font-size: 14px;
      line-height: 1.5;
    }

    .print-table th {
      background: rgba(255, 138, 24, 0.14);
      color: #ffb56b;
      font-weight: 700;
      white-space: nowrap;
    }

    .print-table td {
      color: #e7e7e7;
      vertical-align: middle;
    }

    .print-table tbody tr:nth-child(even) {
      background: rgba(255, 255, 255, 0.025);
    }

    .print-table tbody tr:hover {
      background: rgba(255, 138, 24, 0.055);
    }

    .print-table tbody tr:last-child td {
      border-bottom: none;
    }

    .status-badge {
      display: inline-block;
      padding: 6px 10px;
      border: 1px solid;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 600;
      white-space: nowrap;
    }

    .attended {
      color: #a7edbd;
      background: rgba(70, 190, 110, 0.1);
      border-color: rgba(100, 220, 140, 0.25);
    }

    .notyet {
      color: #ffb3b3;
      background: rgba(230, 80, 80, 0.1);
      border-color: rgba(255, 120, 120, 0.25);
    }

    .print-table td.empty-state {
      padding: 30px 15px;
      color: #bdbdbd;
      text-align: center;
    }

    .error-message {
      padding: 18px;
      border: 1px solid rgba(255, 111, 111, 0.3);
      border-radius: 12px;
      background: rgba(255, 70, 70, 0.08);
      color: #ffb3b3;
      line-height: 1.6;
    }

    /* Mobile */
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
        border-bottom: 1px solid rgba(255, 153, 51, 0.22);
      }

      .sidebar h2 {
        margin: 0 0 15px;
      }

      .sidebar h2::after {
        margin-top: 10px;
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

      .list-panel {
        padding: 18px;
      }

      .print-title {
        font-size: 20px;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      a {
        transition: none !important;
      }
    }

    /* Clean print layout */
    @media print {
      @page {
        size: landscape;
        margin: 12mm;
      }

      body {
        display: block;
        min-height: auto;
        background: #ffffff !important;
        color: #000000 !important;
        font-family: Arial, sans-serif;
      }

      .sidebar,
      header,
      .actions,
      .btn-back,
      .print-btn {
        display: none !important;
      }

      .content {
        width: 100%;
        margin: 0;
        padding: 0;
      }

      .list-panel {
        padding: 0;
        border: none;
        border-radius: 0;
        background: none;
        box-shadow: none;
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
      }

      .print-title {
        margin: 0 0 10px;
        color: #000000;
        font-size: 22px;
        font-weight: bold;
      }

      .print-title::after {
        display: none;
      }

      .print-summary {
        margin-bottom: 20px;
        padding: 0;
        border: none;
        background: none;
        color: #000000;
        font-size: 14px;
      }

      .print-summary strong {
        color: #000000;
      }

      .table-wrapper {
        overflow: visible;
        border: none;
        border-radius: 0;
      }

      .print-table {
        width: 100%;
        min-width: 0;
        table-layout: fixed;
        border-collapse: collapse;
      }

      .print-table th,
      .print-table td {
        padding: 8px;
        border: 1px solid #000000 !important;
        color: #000000 !important;
        background: #ffffff !important;
        text-align: left;
        font-size: 11px;
        overflow-wrap: anywhere;
        white-space: normal;
      }

      .print-table th {
        font-weight: bold;
      }

      .print-table thead {
        display: table-header-group;
      }

      .print-table tr {
        break-inside: avoid;
      }

      .status-badge {
        padding: 0;
        border: none;
        border-radius: 0;
        background: none !important;
        color: #000000 !important;
        font-size: 11px;
        white-space: normal;
      }
    }
  </style>
    <link rel="stylesheet" href="assets/css/system-ui.css">
    <script src="js/system-ui.js" defer></script>
    <link rel="stylesheet" href="assets/css/glass.css">
    <link rel="stylesheet" href="assets/css/light-clean.css">
    <script src="js/glass-ui.js" defer></script>
</head>

<body class="app-page admin-page">
  <!-- Sidebar -->
  <div class="sidebar">
    <h2><?php echo sectionEscape(ucfirst($role)); ?></h2>

    <a href="dashboard.php">Dashboard</a>
    <a href="officers.php">Officer Information</a>
    <a href="pending.php">Student Registered Pending</a>
    <a href="scanner.php">Event Attendance Scanner</a>
    <a href="attendance.php" class="active">Attendance List per Event</a>
    <a href="register_list.php">Register List</a>
    <a href="events.php">Event Management</a>

    <?php if ($role == 'admin_adviser') { ?>
      <a href="accounts.php">Account Management</a>
    <?php } ?>

    <a href="logout.php">Logout</a>
  </div>

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

        <p class="print-summary">
          <strong>Registered:</strong>
          <?php echo sectionEscape($counts['registered']); ?> /

          <strong>Attended:</strong>
          <?php echo sectionEscape($counts['attended']); ?> /

          <strong>Not Attended:</strong>
          <?php echo sectionEscape($counts['not_attended']); ?>
        </p>

        <div class="table-wrapper">
          <table class="print-table">
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
                    No students registered in this section.
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
