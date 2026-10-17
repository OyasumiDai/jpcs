<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

// Fetch user role
$userID = $_SESSION['user_id'];
$user = $conn->query("SELECT role, name FROM users WHERE id='$userID'")->fetch_assoc();
$role = $user['role'];

// Fetch all events (done or active)
$events = $conn->query("SELECT * FROM events ORDER BY event_date DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="stylesheet" href="css/theme.css">
<script src="js/theme-init.js"></script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Attendance List per Event</title>

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
      text-align: center;
      color: #ff9b36;
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
        color 0.2s ease,
        box-shadow 0.2s ease;
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

    .sidebar a:focus-visible,
    .event-card a:focus-visible {
      outline: 2px solid #ffb56b;
      outline-offset: 4px;
    }

    /* Main content */
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

    /* Event cards */
    .events {
      display: flex;
      flex-wrap: wrap;
      gap: 20px;
      margin-top: 28px;
      align-items: stretch;
    }

    .event-card {
      display: flex;
      flex-direction: column;
      width: 200px;
      overflow: hidden;
      text-align: center;
      border: 1px solid rgba(255, 166, 70, 0.25);
      border-radius: 16px;
      background:
        linear-gradient(
          145deg,
          rgba(255, 138, 24, 0.08),
          transparent 60%
        ),
        rgba(23, 23, 23, 0.9);
      box-shadow:
        0 12px 25px rgba(0, 0, 0, 0.3),
        inset 0 1px 0 rgba(255, 255, 255, 0.05);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      transition:
        transform 0.25s ease,
        border-color 0.25s ease,
        box-shadow 0.25s ease;
    }

    .event-card:hover {
      transform: translateY(-5px);
      border-color: rgba(255, 153, 51, 0.65);
      box-shadow:
        0 16px 30px rgba(0, 0, 0, 0.4),
        0 0 22px rgba(255, 138, 24, 0.12);
    }

    .event-card img {
      display: block;
      width: 100%;
      height: 150px;
      object-fit: cover;
      background: #1c1c1c;
      border-bottom: 1px solid rgba(255, 166, 70, 0.15);
    }

    .event-card h3 {
      flex: 1;
      margin: 0;
      padding: 18px 14px;
      color: #ffffff;
      font-size: 16px;
      font-weight: 600;
      line-height: 1.5;
      overflow-wrap: anywhere;
    }

    .event-card a {
      display: block;
      margin: 0 12px 14px;
      padding: 11px 8px;
      border: 1px solid rgba(255, 180, 100, 0.3);
      border-radius: 10px;
      background: linear-gradient(135deg, #ff9c30, #ff7814);
      color: #111111;
      text-decoration: none;
      font-size: 13px;
      font-weight: 700;
      box-shadow: 0 4px 16px rgba(255, 120, 20, 0.18);
      transition:
        background 0.2s ease,
        box-shadow 0.2s ease;
    }

    .event-card a:hover {
      background: linear-gradient(135deg, #ffb45b, #ff8a24);
      box-shadow: 0 4px 22px rgba(255, 138, 24, 0.35);
    }

    /* Mobile layout */
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

      .events {
        justify-content: center;
        gap: 16px;
        margin-top: 22px;
      }

      .event-card {
        width: min(100%, 280px);
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .sidebar a,
      .event-card,
      .event-card a {
        transition: none;
      }

      .event-card:hover {
        transform: none;
      }
    }
  </style>
    <link rel="stylesheet" href="assets/css/system-ui.css">
    <script src="js/system-ui.js" defer></script>
</head>

<body class="app-page admin-page">
  <!-- Sidebar -->
  <div class="sidebar">
    <h2><?php echo ucfirst($role); ?></h2>

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
      <h1>Attendance List per Event</h1>
    </header>

    <div class="events">
      <?php while($row = $events->fetch_assoc()) { ?>
        <div class="event-card">
          <img src="<?php echo e(image_url($row['poster'])); ?>" alt="Poster">

          <h3><?php echo $row['title']; ?></h3>

          <a href="attendance_event.php?event_id=<?php echo $row['id']; ?>">
            View Attendance
          </a>
        </div>
      <?php } ?>
    </div>
  </div>
</body>
</html>
