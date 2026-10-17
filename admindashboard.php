<!DOCTYPE html>
<html lang="en">
<head>
<link rel="stylesheet" href="css/theme.css">
<script src="js/theme-init.js"></script>
  <meta charset="UTF-8">
  <title>JPCS Admin Dashboard</title>
  <style>
    body {
      margin:0;
      font-family: Arial, sans-serif;
      display:flex;
    }
    .sidebar {
      width: 220px;
      background: #000;
      color: #f39c12;
      height: 100vh;
      position: fixed;
      left:0;
      top:0;
      padding-top: 20px;
    }
    .sidebar h2 {
      text-align:center;
      margin-bottom:20px;
    }
    .sidebar a {
      display:block;
      color:#f39c12;
      padding:12px 20px;
      text-decoration:none;
      font-weight:bold;
    }
    .sidebar a:hover {
      background:#f39c12;
      color:#000;
    }
    .content {
      margin-left:220px;
      padding:20px;
      flex:1;
    }
    header {
      background:#f39c12;
      color:#000;
      padding:15px;
      text-align:center;
      border-radius:8px;
    }
    .section { padding:30px; }
    .section h3 { color:#f39c12; }
    .officer-grid {
      display:flex;
      flex-wrap:wrap;
      gap:20px;
      justify-content:center;
    }
    .officer-card {
      background:#000;
      color:#f39c12;
      width:220px;
      border-radius:8px;
      overflow:hidden;
      box-shadow:0 4px 8px rgba(0,0,0,0.3);
      text-align:center;
    }
    .officer-card img {
      width:100%;
      height:180px;
      object-fit:cover;
    }
    .officer-card h4 {
      margin:10px 0 5px 0;
    }
    .officer-card p {
      color:#fff;
      margin-bottom:10px;
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
    <h2>Admin</h2>
    <a href="dashboard.php">Landing Page</a>
    <a href="officers.php">Officer Information</a>
    <a href="pending.php">Student Registered Pending</a>
    <a href="scanner.php">Event Attendance Scanner</a>
    <a href="attendance.php">Attendance List per Event</a>
    <a href="accounts.php">Account Management</a>
    <a href="logout.php">Logout</a>
  </div>

  <!-- Content -->
  <div class="content">
    <header>
      <h1>JPCS Admin Dashboard</h1>
    </header>

    <div class="section">
      <h3>Officer Information</h3>
      <div class="officer-grid">
        <!-- Officer Card Example -->
        <div class="officer-card">
          <img src="assets/img/president.jpg" alt="President">
          <h4>Juan Dela Cruz</h4>
          <p>President</p>
        </div>
        <div class="officer-card">
          <img src="assets/img/vicepresident.jpg" alt="Vice President">
          <h4>Maria Santos</h4>
          <p>Vice President</p>
        </div>
        <div class="officer-card">
          <img src="assets/img/secretary.jpg" alt="Secretary">
          <h4>Carlos Reyes</h4>
          <p>Secretary</p>
        </div>
        <!-- Add more officer cards here -->
      </div>
    </div>
  </div>
</body>
</html>
