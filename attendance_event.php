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

// Get event ID
$eventID = isset($_GET['event_id']) ? $_GET['event_id'] : null;
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
            LEFT JOIN attendance a
              ON r.control_number = a.participant_id AND r.event_id = a.event_id
            WHERE r.event_id='$eventID' AND r.status='confirmed'
        ")->fetch_assoc();

        $total['registered'] = $summary['registered'];
        $total['attended'] = $summary['attended'];
        $total['not_attended'] = $summary['not_attended'];

        // Section summary
        $sections = $conn->query("
            SELECT r.course_year_section,
                   COUNT(*) AS registered,
                   SUM(CASE WHEN a.participant_id IS NOT NULL THEN 1 ELSE 0 END) AS attended,
                   SUM(CASE WHEN a.participant_id IS NULL THEN 1 ELSE 0 END) AS not_attended
            FROM registrations r
            LEFT JOIN attendance a
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
<link rel="stylesheet" href="css/theme.css">
<script src="js/theme-init.js"></script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title><?php echo attendanceEscape($event ? $event['title'] : "Attendance"); ?></title>

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
      font-size: clamp(24px, 3vw, 34px);
      font-weight: 700;
      letter-spacing: -0.6px;
      line-height: 1.3;
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

    /* Back button */
    .btn-back {
      display: inline-block;
      margin: 22px 0;
      padding: 11px 16px;
      border: 1px solid rgba(255, 166, 70, 0.25);
      border-radius: 10px;
      background: rgba(25, 25, 25, 0.85);
      color: #ffad5b;
      text-decoration: none;
      font-size: 14px;
      font-weight: 600;
      transition:
        background 0.2s ease,
        border-color 0.2s ease;
    }

    .btn-back:hover {
      background: rgba(255, 138, 24, 0.12);
      border-color: rgba(255, 166, 70, 0.55);
    }

    /* Existing totals presented as cards */
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 18px;
      margin-bottom: 28px;
    }

    .summary-card {
      position: relative;
      overflow: hidden;
      padding: 24px;
      border: 1px solid rgba(255, 166, 70, 0.25);
      border-radius: 16px;
      background:
        linear-gradient(
          145deg,
          rgba(255, 138, 24, 0.08),
          transparent 65%
        ),
        rgba(23, 23, 23, 0.88);
      box-shadow:
        0 10px 25px rgba(0, 0, 0, 0.24),
        inset 0 1px 0 rgba(255, 255, 255, 0.05);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
    }

    .summary-card::after {
      content: "";
      position: absolute;
      right: -35px;
      bottom: -40px;
      width: 110px;
      height: 110px;
      border-radius: 50%;
      background: rgba(255, 138, 24, 0.12);
      filter: blur(22px);
      pointer-events: none;
    }

    .summary-label {
      display: block;
      margin-bottom: 12px;
      color: #c9c9c9;
      font-size: 14px;
      font-weight: 600;
    }

    .summary-value {
      position: relative;
      z-index: 1;
      display: block;
      color: #ffffff;
      font-size: 36px;
      font-weight: 700;
      line-height: 1.1;
    }

    .summary-card:nth-child(2) .summary-value {
      color: #ffad5b;
    }

    /* Section table */
    .table-panel {
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

    .table-panel h2 {
      margin: 0 0 20px;
      color: #ffffff;
      font-size: 22px;
      font-weight: 600;
    }

    .table-panel h2::after {
      content: "";
      display: block;
      width: 35px;
      height: 3px;
      margin-top: 10px;
      border-radius: 10px;
      background: #ff8a18;
    }

    .table-wrapper {
      overflow-x: auto;
      border: 1px solid rgba(255, 166, 70, 0.18);
      border-radius: 12px;
    }

    table {
      width: 100%;
      min-width: 650px;
      border-collapse: collapse;
    }

    th,
    td {
      padding: 16px 14px;
      text-align: center;
      border-bottom: 1px solid rgba(255, 255, 255, 0.07);
      font-size: 14px;
      line-height: 1.5;
    }

    th {
      background: rgba(255, 138, 24, 0.14);
      color: #ffb56b;
      font-weight: 700;
      white-space: nowrap;
    }

    td {
      color: #e7e7e7;
    }

    th:first-child,
    td:first-child {
      text-align: left;
    }

    tbody tr:nth-child(even) {
      background: rgba(255, 255, 255, 0.025);
    }

    tbody tr:hover {
      background: rgba(255, 138, 24, 0.055);
    }

    tbody tr:last-child td {
      border-bottom: none;
    }

    .view-link {
      display: inline-block;
      padding: 8px 14px;
      border: 1px solid rgba(255, 180, 100, 0.3);
      border-radius: 8px;
      background: linear-gradient(135deg, #ff9c30, #ff7814);
      color: #111111;
      text-decoration: none;
      font-size: 13px;
      font-weight: 700;
      white-space: nowrap;
      box-shadow: 0 4px 12px rgba(255, 120, 20, 0.12);
      transition: box-shadow 0.2s ease;
    }

    .view-link:hover {
      box-shadow: 0 4px 20px rgba(255, 138, 24, 0.3);
    }

    td.empty-state {
      padding: 30px 15px;
      color: #bdbdbd;
      text-align: center;
    }

    .error-message {
      margin-top: 0;
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

      .summary-grid {
        grid-template-columns: 1fr;
        gap: 12px;
      }

      .summary-card {
        padding: 20px;
      }

      .table-panel {
        padding: 18px;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      a {
        transition: none !important;
      }
    }
  </style>
    <link rel="stylesheet" href="assets/css/system-ui.css">
    <script src="js/system-ui.js" defer></script>
</head>

<body class="app-page admin-page">
  <!-- Sidebar -->
  <div class="sidebar">
    <h2><?php echo attendanceEscape(ucfirst($role)); ?></h2>

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
        <?php echo attendanceEscape($event ? $event['title'] : "No Event Selected"); ?>
        Attendance
      </h1>
    </header>

    <a href="attendance.php" class="btn-back">
      ← Back to Event Selection
    </a>

    <?php if ($event) { ?>

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
          <table>
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
                        href="attendance_section.php?event_id=<?php echo urlencode($eventID); ?>&amp;section=<?php echo urlencode($row['course_year_section']); ?>"
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
