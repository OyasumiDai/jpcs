<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/permissions.php';
permissions_require($conn, 'registrations');

// Attendance now lives in registrations.php.
redirect('registrations.php');

// Fetch user role
$userID = $_SESSION['user_id'];
$user = $conn->query("SELECT role, name FROM users WHERE id='$userID'")->fetch_assoc();
$role = $user['role'];

// Event filters are independent of the shared table controls.
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$status = in_array($_GET['status'] ?? '', ['upcoming', 'done'], true) ? $_GET['status'] : '';
$eventQuery = $conn->prepare("SELECT * FROM events WHERE (?='' OR title LIKE CONCAT('%', ?, '%')) AND (?='' OR status=?) ORDER BY event_date DESC");
$eventQuery->bind_param('ssss', $search, $search, $status, $status);
$eventQuery->execute();
$events = $eventQuery->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="assets/img/jpcs-logo.png?v=3">

  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Attendance List per Event</title>


<?php require __DIR__.'/includes/admin_head.php'; ?>
</head>

<body class="app-page admin-page feature-attendance">
  <!-- Sidebar -->
  <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

  <!-- Content -->
  <div class="content">
    <header>
      <h1>Attendance List per Event</h1>
    </header>

    <form method="get" class="actions">
      <label for="event-search">Find event</label>
      <input id="event-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Event title">
      <label for="event-status">Status</label>
      <select id="event-status" name="status">
        <option value="">All events</option>
        <option value="upcoming" <?= $status === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
        <option value="done" <?= $status === 'done' ? 'selected' : '' ?>>Done</option>
      </select>
      <button type="submit">Apply filters</button>
      <a href="attendance.php">Reset</a>
    </form>
    <p role="status"><?= (int) $events->num_rows ?> matching events</p>
    <div class="events">
      <?php if ($events->num_rows === 0) { ?><p>No events match these filters.</p><?php } ?>
      <?php while($row = $events->fetch_assoc()) { ?>
        <div class="event-card">
          <img src="<?php echo e(image_url($row['poster'])); ?>" alt="Poster">

          <h3><?php echo e($row['title']); ?></h3>
          <p><?= e($row['event_date']) ?> &middot; <?= e(ucfirst($row['status'])) ?></p>

          <a href="attendance_event.php?event_id=<?php echo $row['id']; ?>">
            View Attendance
          </a>
        </div>
      <?php } ?>
    </div>
  </div>
</body>
</html>
