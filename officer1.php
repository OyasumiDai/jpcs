<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

// Get all officers and advisor/admin
$result = $conn->query("SELECT * FROM users WHERE role='officer' OR role='admin_advisor' ORDER BY id ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<link rel="stylesheet" href="css/theme.css">
<script src="js/theme-init.js"></script>
  <meta charset="UTF-8">
  <title>Officer Information</title>
  <style>
    body { margin:0; font-family: Arial, sans-serif; display:flex; }
    /* Sidebar */
    .sidebar {
      width: 220px; background: #000; color: #f39c12;
      height: 100vh; position: fixed; left:0; top:0; padding-top: 20px;
    }
    .sidebar h2 { text-align:center; margin-bottom:20px; }
    .sidebar a {
      display:block; color:#f39c12; padding:12px 20px;
      text-decoration:none; font-weight:bold;
    }
    .sidebar a:hover { background:#f39c12; color:#000; }
    /* Content */
    .content { margin-left:220px; padding:20px; flex:1; }
    header { background:#f39c12; color:#000; padding:15px; text-align:center; border-radius:8px; }
    /* Officer grid */
    .officer-grid {
      display:flex; flex-wrap:wrap; gap:30px; justify-content:center; padding:30px;
    }
    .officer-card {
      background:#fff; border:1px solid #ddd; border-radius:8px;
      width:220px; text-align:center; box-shadow:0 4px 8px rgba(0,0,0,0.2);
      transition: transform 0.3s;
    }
    .officer-card:hover { transform: scale(1.05); }
    .officer-card img {
      width:100%; height:220px; object-fit:cover;
      border-top-left-radius:8px; border-top-right-radius:8px;
    }
    .officer-card h4 { margin:10px 0 5px 0; color:#f39c12; }
    .officer-card p { margin:0 0 15px 0; color:#000; font-weight:bold; }
  </style>
    <link rel="stylesheet" href="assets/css/system-ui.css">
    <script src="js/system-ui.js" defer></script>
    <link rel="stylesheet" href="assets/css/glass.css">
    <link rel="stylesheet" href="assets/css/light-clean.css">
    <script src="js/glass-ui.js" defer></script>
</head>
<body class="app-page officer-page">
  <!-- Sidebar -->
  <div class="sidebar">
    <h2>Admin</h2>
      <a href="index.php">Landing Page</a>
    <a href="officer1.php">Officer Information</a>
    <a href="registration.php">Event Registration</a>
    <a href="login.php">Admin Login</a>
  </div>

  <!-- Content -->
  <div class="content">
    <header>
      <h1>Junior Philippine Computer Society</h1>
      <h2>City of Malabon University</h2>
    </header>

    <h2 style="text-align:center; color:#f39c12; margin-top:20px;">Officer Information</h2>
    <div class="officer-grid">
      <?php while($row = $result->fetch_assoc()) { ?>
        <div class="officer-card">
          <?php if($row['photo']) { ?>
            <img src="<?php echo e(image_url($row['photo'])); ?>" alt="<?php echo e($row['name']); ?>">
          <?php } else { ?>
            <img src="assets/img/default.svg" alt="No Photo">
          <?php } ?>
          <h4><?php echo $row['name']; ?></h4>
          <p><?php echo $row['position']; ?></p>
        </div>
      <?php } ?>
    </div>
  </div>
</body>
</html>
