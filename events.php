<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$success = "";

// Fetch user role
$userID = $_SESSION['user_id'];
$user = $conn->query("SELECT role, name FROM users WHERE id='$userID'")->fetch_assoc();
$role = $user['role'];

// Accept both existing admin role spellings
$isAdmin = in_array($role, ['admin_adviser', 'admin_advisor'], true);

// CREATE EVENT
if (isset($_POST['create'])) {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $event_date = $_POST['event_date'];
    $event_time = $_POST['event_time'];
    $location = $_POST['location'];

    $poster = null;
    $passImage = null;

    // Poster upload
    if (!empty($_FILES['poster']['name'])) {
        $targetDir = "uploads/events/";

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $poster = $targetDir . time() . "_" . basename($_FILES['poster']['name']);
        move_uploaded_file($_FILES['poster']['tmp_name'], $poster);
    }

    // Event pass upload
    if (!empty($_FILES['pass_image']['name'])) {
        $targetDir = "uploads/events/";

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $passImage = $targetDir . time() . "_" . basename($_FILES['pass_image']['name']);
        move_uploaded_file($_FILES['pass_image']['tmp_name'], $passImage);
    }

    $conn->query("
        INSERT INTO events
        (title, description, event_date, event_time, location, poster, pass_image, status, created_at)
        VALUES
        ('$title','$description','$event_date','$event_time','$location','$poster','$passImage','upcoming',NOW())
    ");

    $success = "Event added successfully!";
    header("Location: events.php?success=" . urlencode($success));
    exit();
}

// MARK EVENT AS DONE
if (isset($_GET['done'])) {
    $id = $_GET['done'];
    $conn->query("UPDATE events SET status='done' WHERE id=$id");

    $success = "Event marked as done!";
    header("Location: events.php?success=" . urlencode($success));
    exit();
}

// FETCH EVENTS
$result = $conn->query("SELECT * FROM events ORDER BY event_date ASC");

if (!$result) {
    die("Query failed: " . $conn->error);
}

function eventEscape($value) {
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
  <title>Event Management</title>

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
      background: linear-gradient(
        100deg,
        rgba(255,138,24,.28),
        rgba(255,138,24,.07)
      );
      box-shadow:
        inset 3px 0 0 #ff8a18,
        0 0 18px rgba(255,138,24,.08);
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
      border: 1px solid rgba(255,166,70,.3);
      border-radius: 18px;
      background:
        linear-gradient(
          120deg,
          rgba(255,138,24,.15),
          rgba(255,138,24,.02) 65%
        ),
        rgba(22,22,22,.83);
      box-shadow:
        0 14px 35px rgba(0,0,0,.3),
        inset 0 1px 0 rgba(255,255,255,.06);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
    }

    header h1 {
      margin: 0;
      font-size: clamp(24px, 3vw, 34px);
      letter-spacing: -.6px;
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
      box-shadow: 0 0 16px rgba(255,138,24,.5);
    }

    .success {
      margin-top: 20px;
      padding: 14px 18px;
      border: 1px solid rgba(100,220,140,.3);
      border-radius: 12px;
      background: rgba(70,190,110,.1);
      color: #b5f0c7;
      line-height: 1.6;
    }

    .toolbar {
      display: flex;
      justify-content: flex-end;
      margin: 26px 0 22px;
    }

    .btn-create,
    .btn-edit,
    .submit-btn {
      display: inline-block;
      padding: 11px 17px;
      border: 1px solid rgba(255,180,100,.3);
      border-radius: 10px;
      background: linear-gradient(135deg, #ff9c30, #ff7814);
      color: #111;
      text-decoration: none;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 4px 16px rgba(255,120,20,.15);
      transition: box-shadow .2s ease;
    }

    .btn-create:hover,
    .btn-edit:hover,
    .submit-btn:hover {
      box-shadow: 0 4px 22px rgba(255,138,24,.35);
    }

    /* Event cards */
    .event-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 22px;
      justify-content: center;
      align-items: stretch;
    }

    .event-card {
      display: flex;
      flex-direction: column;
      width: 250px;
      overflow: hidden;
      border: 1px solid rgba(255,166,70,.25);
      border-radius: 16px;
      background:
        linear-gradient(145deg, rgba(255,138,24,.07), transparent 60%),
        rgba(23,23,23,.9);
      box-shadow:
        0 12px 25px rgba(0,0,0,.3),
        inset 0 1px 0 rgba(255,255,255,.05);
      transition: transform .25s ease, box-shadow .25s ease;
    }

    .event-card:hover {
      transform: translateY(-5px);
      box-shadow:
        0 16px 30px rgba(0,0,0,.4),
        0 0 22px rgba(255,138,24,.12);
    }

    .event-card img {
      display: block;
      width: 100%;
      height: 150px;
      object-fit: cover;
      background: #1c1c1c;
      border-bottom: 1px solid rgba(255,166,70,.15);
    }

    .event-card .info {
      display: flex;
      flex: 1;
      flex-direction: column;
      padding: 18px;
    }

    .event-card h3 {
      margin: 0 0 14px;
      color: #fff;
      font-size: 19px;
      line-height: 1.4;
      overflow-wrap: anywhere;
    }

    .event-card p {
      margin: 5px 0;
      color: #c9c9c9;
      font-size: 13px;
      line-height: 1.7;
      overflow-wrap: anywhere;
    }

    .event-card p strong {
      color: #ffb56b;
      font-weight: 600;
    }

    .event-card .description {
      margin: 12px 0;
    }

    .status-row {
      padding-top: 10px;
    }

    .status-badge {
      display: inline-block;
      margin-left: 5px;
      padding: 4px 9px;
      border: 1px solid rgba(255,166,70,.3);
      border-radius: 7px;
      background: rgba(255,138,24,.09);
      color: #ffb56b;
      font-size: 12px;
      font-weight: 600;
    }

    .status-badge.done {
      background: rgba(255,255,255,.05);
      border-color: rgba(255,255,255,.18);
      color: #d5d5d5;
    }

    .event-card .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 9px;
      margin-top: auto;
      padding-top: 18px;
    }

    .event-card .actions a {
      padding: 8px 14px;
      font-size: 12px;
      text-align: center;
      text-decoration: none;
      border-radius: 8px;
      font-weight: 700;
    }

    .btn-done {
      border: 1px solid rgba(100,220,140,.3);
      background: rgba(70,190,110,.12);
      color: #b5f0c7;
    }

    .btn-done:hover {
      background: rgba(70,190,110,.22);
    }

    /* Add Event modal */
    .modal {
      position: fixed;
      inset: 0;
      z-index: 1000;
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 24px;
      overflow-y: auto;
      background: rgba(0,0,0,.78);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
    }

    .modal-content {
      width: 100%;
      max-width: 520px;
      max-height: calc(100dvh - 48px);
      overflow-y: auto;
      padding: 28px;
      border: 1px solid rgba(255,166,70,.35);
      border-radius: 20px;
      background:
        radial-gradient(ellipse at top right, rgba(255,138,24,.12), transparent 55%),
        #151515;
      box-shadow:
        0 25px 70px rgba(0,0,0,.6),
        0 0 35px rgba(255,138,24,.1);
    }

    .modal-content h3 {
      margin: 0 0 24px;
      color: #fff;
      font-size: 25px;
    }

    .modal-content h3::after {
      content: "";
      display: block;
      width: 35px;
      height: 3px;
      margin-top: 12px;
      border-radius: 10px;
      background: #ff8a18;
    }

    label {
      display: block;
      margin: 15px 0 7px;
      color: #e4e4e4;
      font-size: 13px;
      font-weight: 600;
    }

    input,
    textarea {
      width: 100%;
      padding: 12px;
      border: 1px solid rgba(255,255,255,.16);
      border-radius: 9px;
      outline: none;
      background: rgba(255,255,255,.045);
      color: #fff;
      font: inherit;
      font-size: 14px;
      color-scheme: dark;
    }

    input::placeholder,
    textarea::placeholder {
      color: #858585;
    }

    input:focus,
    textarea:focus {
      border-color: #ff9b36;
      box-shadow: 0 0 0 3px rgba(255,138,24,.1);
    }

    textarea {
      min-height: 100px;
      resize: vertical;
    }

    .date-time {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    input[type="file"] {
      padding: 9px;
      font-size: 12px;
    }

    input[type="file"]::file-selector-button {
      margin-right: 10px;
      padding: 8px 10px;
      border: 1px solid rgba(255,166,70,.3);
      border-radius: 6px;
      background: rgba(255,138,24,.12);
      color: #ffb56b;
      font-weight: 600;
      cursor: pointer;
    }

    .modal-actions {
      display: flex;
      gap: 12px;
      margin-top: 25px;
    }

    .modal-actions > * {
      flex: 1;
      text-align: center;
    }

    .cancel-btn {
      display: inline-block;
      padding: 11px 17px;
      border: 1px solid rgba(255,255,255,.18);
      border-radius: 10px;
      background: rgba(255,255,255,.04);
      color: #ddd;
      text-decoration: none;
      font-size: 14px;
      font-weight: 600;
    }

    .cancel-btn:hover {
      background: rgba(255,255,255,.09);
    }

    a:focus-visible,
    button:focus-visible {
      outline: 2px solid #ffb56b;
      outline-offset: 4px;
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

      .event-card {
        width: min(100%, 320px);
      }

      .modal {
        padding: 16px;
      }

      .modal-content {
        padding: 22px;
        max-height: calc(100dvh - 32px);
      }

      .date-time {
        grid-template-columns: 1fr;
        gap: 0;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      a,
      .event-card {
        transition: none !important;
      }

      .event-card:hover {
        transform: none;
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
  <h2><?php echo htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8'); ?></h2>

  <a href="dashboard.php" class="active">Dashboard</a>
  <a href="officers.php">Officer Information</a>
  <a href="pending.php">Student Registered Pending</a>
  <a href="scanner.php">Event Attendance Scanner</a>
  <a href="attendance.php">Attendance List per Event</a>
  <a href="register_list.php">Register List</a>
  <a href="events.php">Event Management</a>

  <?php if (in_array($role, ['admin_adviser', 'admin_advisor'], true)) { ?>
    <a href="accounts.php">Account Management</a>
  <?php } ?>

  <a href="logout.php">Logout</a>
</div>

  <!-- Content -->
  <div class="content">
    <header>
      <h1>Event Management</h1>
    </header>

    <?php if (isset($_GET['success'])) { ?>
      <div class="success">
        <?php echo eventEscape($_GET['success']); ?>
      </div>
    <?php } ?>

    <div class="toolbar">
      <a href="events.php?createform=1" class="btn-create">
        + Add Event
      </a>
    </div>

    <div class="event-grid">
      <?php while($row = $result->fetch_assoc()) { ?>
        <div class="event-card">
          <?php if (!empty($row['poster']) && file_exists($row['poster'])) { ?>
            <img
              src="<?php echo eventEscape(image_url($row['poster'])); ?>"
              alt="Poster"
            >
          <?php } else { ?>
            <img src="assets/img/default.svg" alt="Poster">
          <?php } ?>

          <div class="info">
            <h3><?php echo eventEscape($row['title']); ?></h3>

            <p>
              <strong>Date:</strong>
              <?php echo eventEscape($row['event_date']); ?>

              <strong>Time:</strong>
              <?php echo eventEscape($row['event_time']); ?>
            </p>

            <p>
              <strong>Location:</strong>
              <?php echo eventEscape($row['location']); ?>
            </p>

            <p class="description">
              <?php echo eventEscape($row['description']); ?>
            </p>

            <p class="status-row">
              <strong>Status:</strong>

              <span class="status-badge <?php echo $row['status'] == 'done' ? 'done' : ''; ?>">
                <?php echo eventEscape(ucfirst($row['status'])); ?>
              </span>
            </p>

            <div class="actions">
              <a
                href="events.php?edit=<?php echo urlencode((string)$row['id']); ?>"
                class="btn-edit"
              >
                Edit
              </a>

              <?php if ($row['status'] == 'upcoming') { ?>
                <a
                  href="events.php?done=<?php echo urlencode((string)$row['id']); ?>"
                  class="btn-done"
                  onclick="return confirm('Mark this event as done?')"
                >
                  Done
                </a>
              <?php } ?>
            </div>
          </div>
        </div>
      <?php } ?>
    </div>

    <!-- Add Event Modal -->
    <?php if (isset($_GET['createform'])) { ?>
      <div class="modal">
        <div
          class="modal-content"
          role="dialog"
          aria-modal="true"
          aria-labelledby="addEventTitle"
        >
          <h3 id="addEventTitle">Add Event</h3>

          <form method="POST" enctype="multipart/form-data">
            <label for="title">Event Title</label>
            <input
              type="text"
              id="title"
              name="title"
              placeholder="Event Title"
              required
            >

            <label for="description">Description</label>
            <textarea
              id="description"
              name="description"
              placeholder="Description"
              required
            ></textarea>

            <div class="date-time">
              <div>
                <label for="event_date">Event Date</label>
                <input
                  type="date"
                  id="event_date"
                  name="event_date"
                  required
                >
              </div>

              <div>
                <label for="event_time">Event Time</label>
                <input
                  type="time"
                  id="event_time"
                  name="event_time"
                  required
                >
              </div>
            </div>

            <label for="location">Location</label>
            <input
              type="text"
              id="location"
              name="location"
              placeholder="Location"
              required
            >

            <label for="poster">Upload Poster:</label>
            <input
              type="file"
              id="poster"
              name="poster"
              accept="image/*"
            >

            <label for="pass_image">
              Upload Event Pass (for email only):
            </label>
            <input
              type="file"
              id="pass_image"
              name="pass_image"
              accept="image/*"
            >

            <div class="modal-actions">
              <button type="submit" name="create" class="submit-btn">
                Add Event
              </button>

              <a href="events.php" class="cancel-btn">
                Cancel
              </a>
            </div>
          </form>
        </div>
      </div>
    <?php } ?>
  </div>
</body>
</html>
