<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

function accountEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// CREATE
if (isset($_POST['create'])) {
    $username = $_POST['username'];
    $name = $_POST['name'];
    $role = $_POST['role'];
    $position = $_POST['position'];
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if ($password !== $confirm) {
        header("Location: accounts.php?error=" . urlencode("Passwords do not match!") . "&createform=1");
        exit();
    }

    $photo = null;

    if (!empty($_FILES['photo']['name'])) {
        $targetDir = "uploads/";

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $photo = $targetDir . basename($_FILES['photo']['name']);
        move_uploaded_file($_FILES['photo']['tmp_name'], $photo);
    }

    $stmt = $conn->prepare("
        INSERT INTO users (username, name, password, role, position, photo)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssss", $username, $name, $password, $role, $position, $photo);
    $stmt->execute();
    $stmt->close();

    header("Location: accounts.php?success=" . urlencode("Account created successfully!"));
    exit();
}

// DELETE
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    header("Location: accounts.php?success=" . urlencode("Account deleted successfully!"));
    exit();
}

// UPDATE
if (isset($_POST['update'])) {
    $id = (int)$_POST['id'];
    $username = $_POST['username'];
    $name = $_POST['name'];
    $role = $_POST['role'];
    $position = $_POST['position'];
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if ($password !== "" && $password !== $confirm) {
        header("Location: accounts.php?edit=$id&error=" . urlencode("Passwords do not match!"));
        exit();
    }

    $photo = $_POST['existing_photo'];

    if (!empty($_FILES['photo']['name'])) {
        $targetDir = "uploads/";

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $photo = $targetDir . basename($_FILES['photo']['name']);
        move_uploaded_file($_FILES['photo']['tmp_name'], $photo);
    }

    if ($password !== "") {
        $stmt = $conn->prepare("
            UPDATE users
            SET username=?, name=?, role=?, position=?, photo=?, password=?
            WHERE id=?
        ");
        $stmt->bind_param("ssssssi", $username, $name, $role, $position, $photo, $password, $id);
    } else {
        $stmt = $conn->prepare("
            UPDATE users
            SET username=?, name=?, role=?, position=?, photo=?
            WHERE id=?
        ");
        $stmt->bind_param("sssssi", $username, $name, $role, $position, $photo, $id);
    }

    $stmt->execute();
    $stmt->close();

    header("Location: accounts.php?success=" . urlencode("Account updated successfully!"));
    exit();
}

// Fetch all accounts
// Fetch accounts in the requested position order.
$result = $conn->query("
    SELECT *
    FROM users
    ORDER BY
        CASE
            WHEN role IN ('admin_adviser', 'admin_advisor') THEN 100

            WHEN LOWER(TRIM(position)) = 'president' THEN 1

            WHEN LOWER(position) LIKE '%vice%president%internal%' THEN 2
            WHEN LOWER(position) LIKE '%vice%president%external%' THEN 3

            WHEN LOWER(position) LIKE '%executive%vice%president%'
              OR LOWER(position) LIKE '%vice%president%executive%' THEN 4

            WHEN LOWER(position) LIKE '%vice%president%academic%' THEN 5

            WHEN LOWER(TRIM(position)) = 'secretary' THEN 6
            WHEN LOWER(TRIM(position)) = 'treasurer' THEN 7
            WHEN LOWER(TRIM(position)) = 'auditor' THEN 8

            WHEN LOWER(position) LIKE '%internal%'
             AND (
                 LOWER(position) LIKE '%p.r.o%'
                 OR LOWER(position) LIKE '%pro %'
                 OR LOWER(position) LIKE '%public relations%'
             ) THEN 9

            WHEN LOWER(position) LIKE '%external%'
             AND (
                 LOWER(position) LIKE '%p.r.o%'
                 OR LOWER(position) LIKE '%pro %'
                 OR LOWER(position) LIKE '%public relations%'
             ) THEN 10

            WHEN LOWER(position) LIKE '%director%'
             AND LOWER(position) LIKE '%multimedia%' THEN 11

            WHEN LOWER(position) LIKE '%director%'
             AND LOWER(position) LIKE '%technical%' THEN 12

            WHEN LOWER(position) LIKE '%director%'
             AND LOWER(position) LIKE '%recreational%' THEN 13

            WHEN LOWER(position) LIKE '%director%'
             AND LOWER(position) LIKE '%grievance%' THEN 14

            WHEN LOWER(position) LIKE '%graphic%'
             AND LOWER(position) LIKE '%head%' THEN 15

            WHEN LOWER(position) LIKE '%writing%'
             AND LOWER(position) LIKE '%head%' THEN 16

            WHEN LOWER(position) LIKE '%media%'
             AND LOWER(position) LIKE '%head%' THEN 17

            WHEN LOWER(position) LIKE '%photography%'
             AND LOWER(position) LIKE '%head%' THEN 18

            WHEN LOWER(position) LIKE '%graphic%'
             AND LOWER(position) LIKE '%assistant%' THEN 19

            WHEN LOWER(position) LIKE '%writing%'
             AND LOWER(position) LIKE '%assistant%' THEN 20

            WHEN LOWER(position) LIKE '%media%'
             AND LOWER(position) LIKE '%assistant%' THEN 21

            WHEN LOWER(position) LIKE '%photography%'
             AND LOWER(position) LIKE '%assistant%' THEN 22

            WHEN LOWER(position) LIKE '%technical%'
             AND LOWER(position) LIKE '%committee%' THEN 23

            WHEN LOWER(position) LIKE '%grievance%'
             AND LOWER(position) LIKE '%committee%' THEN 24

            WHEN LOWER(position) LIKE '%recreational%'
             AND LOWER(position) LIKE '%committee%' THEN 25

            WHEN LOWER(position) LIKE '%1st%year%'
              OR LOWER(position) LIKE '%first%year%' THEN 26

            WHEN LOWER(position) LIKE '%2nd%year%'
              OR LOWER(position) LIKE '%second%year%' THEN 27

            WHEN LOWER(position) LIKE '%3rd%year%'
              OR LOWER(position) LIKE '%third%year%' THEN 28

            WHEN LOWER(position) LIKE '%4th%year%'
              OR LOWER(position) LIKE '%fourth%year%' THEN 29

            ELSE 90
        END ASC,
        name ASC,
        username ASC
");

// Fetch account for editing
$editRow = null;

if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $editRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="stylesheet" href="css/theme.css">
<script src="js/theme-init.js"></script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Account Management</title>

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
      transition: background .2s ease;
    }

    .sidebar a:hover {
      color: #fff;
      background: rgba(255,138,24,.12);
    }

    .sidebar a.active {
      color: #fff;
      background: linear-gradient(100deg, rgba(255,138,24,.28), rgba(255,138,24,.07));
      box-shadow: inset 3px 0 0 #ff8a18;
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
        linear-gradient(120deg, rgba(255,138,24,.15), transparent 65%),
        rgba(22,22,22,.83);
      box-shadow: 0 14px 35px rgba(0,0,0,.3);
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

    .toolbar {
      display: flex;
      justify-content: flex-end;
      margin: 24px 0;
    }

    .btn {
      display: inline-block;
      padding: 9px 14px;
      border: 1px solid transparent;
      border-radius: 9px;
      text-decoration: none;
      font-size: 13px;
      font-weight: 700;
      line-height: 1.5;
      white-space: nowrap;
      cursor: pointer;
    }

    .btn-create,
    .btn-edit,
    .submit-btn {
      color: #111;
      background: linear-gradient(135deg, #ff9c30, #ff7814);
      border-color: rgba(255,180,100,.3);
      box-shadow: 0 4px 16px rgba(255,120,20,.15);
    }

    .btn-create:hover,
    .btn-edit:hover,
    .submit-btn:hover {
      box-shadow: 0 4px 22px rgba(255,138,24,.35);
    }

    .btn-create {
      padding: 12px 18px;
      font-size: 14px;
    }

    .btn-delete {
      color: #ffb3b3;
      background: rgba(230,80,80,.1);
      border-color: rgba(255,120,120,.3);
    }

    .btn-delete:hover {
      background: rgba(230,80,80,.2);
    }

    .success,
    .error {
      margin-top: 20px;
      padding: 14px 18px;
      border: 1px solid;
      border-radius: 12px;
      line-height: 1.6;
    }

    .success {
      color: #b5f0c7;
      background: rgba(70,190,110,.1);
      border-color: rgba(100,220,140,.3);
    }

    .error {
      color: #ffb3b3;
      background: rgba(230,80,80,.1);
      border-color: rgba(255,120,120,.3);
    }

    .table-wrapper {
      overflow-x: auto;
      border: 1px solid rgba(255,166,70,.22);
      border-radius: 16px;
      background: rgba(20,20,20,.9);
      box-shadow: 0 12px 30px rgba(0,0,0,.25);
    }

    table {
      width: 100%;
      min-width: 900px;
      border-collapse: collapse;
    }

    th,
    td {
      padding: 16px 14px;
      border-bottom: 1px solid rgba(255,255,255,.07);
      text-align: left;
      font-size: 14px;
      line-height: 1.5;
      vertical-align: middle;
    }

    th {
      color: #ffb56b;
      background: rgba(255,138,24,.14);
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

    .account-photo {
      display: block;
      width: 60px;
      height: 60px;
      object-fit: cover;
      border-radius: 12px;
      border: 1px solid rgba(255,166,70,.3);
    }

    .role-badge {
      display: inline-block;
      padding: 5px 9px;
      border: 1px solid rgba(255,166,70,.22);
      border-radius: 7px;
      background: rgba(255,138,24,.07);
      color: #ffb56b;
      font-size: 12px;
    }

    .row-actions {
      display: flex;
      gap: 8px;
    }

    .empty-state {
      padding: 30px;
      text-align: center;
      color: #aaa;
    }

    /* Create and Edit modals */
    .modal {
      position: fixed;
      inset: 0;
      z-index: 1000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(0,0,0,.78);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
    }

    .modal-content {
      width: 100%;
      max-width: 500px;
      max-height: calc(100dvh - 48px);
      overflow-y: auto;
      padding: 28px;
      border: 1px solid rgba(255,166,70,.35);
      border-radius: 20px;
      background:
        radial-gradient(ellipse at top right, rgba(255,138,24,.12), transparent 55%),
        #151515;
      box-shadow: 0 25px 70px rgba(0,0,0,.6), 0 0 35px rgba(255,138,24,.1);
    }

    .modal-content h3 {
      margin: 0 0 22px;
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
      margin: 14px 0 7px;
      color: #e4e4e4;
      font-size: 13px;
      font-weight: 600;
    }

    input,
    select {
      width: 100%;
      padding: 12px;
      border: 1px solid rgba(255,255,255,.16);
      border-radius: 9px;
      outline: none;
      background: #202020;
      color: #fff;
      font: inherit;
      font-size: 14px;
      color-scheme: dark;
    }

    input::placeholder {
      color: #888;
    }

    input:focus,
    select:focus {
      border-color: #ff9b36;
      box-shadow: 0 0 0 3px rgba(255,138,24,.1);
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
      cursor: pointer;
    }

    .helper {
      margin: 7px 0;
      color: #aaa;
      font-size: 12px;
      line-height: 1.5;
    }

    .current-photo {
      margin-top: 12px;
    }

    .modal-actions {
      display: flex;
      gap: 12px;
      margin-top: 24px;
    }

    .modal-actions > * {
      flex: 1;
      padding: 11px 16px;
      text-align: center;
      font: inherit;
      font-size: 14px;
      font-weight: 700;
    }

    .cancel-btn {
      color: #ddd;
      border-color: rgba(255,255,255,.18);
      background: rgba(255,255,255,.04);
    }

    .cancel-btn:hover {
      background: rgba(255,255,255,.09);
    }

    a:focus-visible,
    button:focus-visible {
      outline: 2px solid #ffb56b;
      outline-offset: 4px;
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

      .modal {
        padding: 16px;
      }

      .modal-content {
        padding: 22px;
        max-height: calc(100dvh - 32px);
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .sidebar a {
        transition: none;
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
  <div class="sidebar">
    <h2>Admin</h2>
    <a href="dashboard.php">Dashboard</a>
    <a href="officers.php">Officer Information</a>
    <a href="pending.php">Student Registered Pending</a>
    <a href="scanner.php">Event Attendance Scanner</a>
    <a href="attendance.php">Attendance List per Event</a>
    <a href="register_list.php">Register List</a>
    <a href="events.php">Event Management</a>
    <a href="accounts.php" class="active">Account Management</a>
    <a href="logout.php">Logout</a>
  </div>

  <div class="content">
    <header>
      <h1>Account Management</h1>
    </header>

    <?php if (isset($_GET['success'])) { ?>
      <div class="success">
        <?php echo accountEscape($_GET['success']); ?>
      </div>
    <?php } ?>

    <?php if (isset($_GET['error'])) { ?>
      <div class="error">
        <?php echo accountEscape($_GET['error']); ?>
      </div>
    <?php } ?>

    <div class="toolbar">
      <a href="accounts.php?createform=1" class="btn btn-create">
        + Create Account
      </a>
    </div>

    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Name</th>
            <th>Role</th>
            <th>Position</th>
            <th>Photo</th>
            <th>Actions</th>
          </tr>
        </thead>

        <tbody>
          <?php if ($result->num_rows > 0) { ?>
            <?php while($row = $result->fetch_assoc()) { ?>
              <tr>
                <td><?php echo accountEscape($row['id']); ?></td>
                <td><?php echo accountEscape($row['username']); ?></td>
                <td><?php echo accountEscape($row['name']); ?></td>

                <td>
                  <span class="role-badge">
                    <?php echo accountEscape($row['role']); ?>
                  </span>
                </td>

                <td><?php echo accountEscape($row['position']); ?></td>

                <td>
                  <?php if ($row['photo']) { ?>
                    <img
                      src="<?php echo accountEscape(image_url($row['photo'])); ?>"
                      alt="<?php echo accountEscape($row['name']); ?>"
                      class="account-photo"
                    >
                  <?php } ?>
                </td>

                <td>
                  <div class="row-actions">
                    <a
                      href="accounts.php?edit=<?php echo (int)$row['id']; ?>"
                      class="btn btn-edit"
                    >
                      Edit
                    </a>

                    <a
                      href="accounts.php?delete=<?php echo (int)$row['id']; ?>"
                      class="btn btn-delete"
                      onclick="return confirm('Delete this account?')"
                    >
                      Delete
                    </a>
                  </div>
                </td>
              </tr>
            <?php } ?>
          <?php } else { ?>
            <tr>
              <td colspan="7" class="empty-state">No accounts found.</td>
            </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>

    <!-- Create Modal -->
    <?php if (isset($_GET['createform'])) { ?>
      <div class="modal">
        <div
          class="modal-content"
          role="dialog"
          aria-modal="true"
          aria-labelledby="createTitle"
        >
          <h3 id="createTitle">Create Account</h3>

          <?php if (isset($_GET['error'])) { ?>
            <div class="error"><?php echo accountEscape($_GET['error']); ?></div>
          <?php } ?>

          <form method="POST" enctype="multipart/form-data">
            <label for="create_username">Username</label>
            <input
              type="text"
              id="create_username"
              name="username"
              placeholder="Username"
              autocomplete="username"
              required
            >

            <label for="create_name">Full Name</label>
            <input
              type="text"
              id="create_name"
              name="name"
              placeholder="Full Name"
              required
            >

            <label for="create_role">Role</label>
            <select id="create_role" name="role" required>
              <option value="admin_adviser">Admin/Adviser</option>
              <option value="officer">Officer</option>
            </select>

            <label for="create_position">Position</label>
            <input
              type="text"
              id="create_position"
              name="position"
              placeholder="Position"
              required
            >

            <label for="create_password">Password</label>
            <input
              type="password"
              id="create_password"
              name="password"
              placeholder="Password"
              autocomplete="new-password"
              required
            >

            <label for="create_confirm">Confirm Password</label>
            <input
              type="password"
              id="create_confirm"
              name="confirm_password"
              placeholder="Confirm Password"
              autocomplete="new-password"
              required
            >

            <label for="create_photo">Photo</label>
            <input type="file" id="create_photo" name="photo" accept="image/*">

            <div class="modal-actions">
              <button type="submit" name="create" class="btn submit-btn">
                Create
              </button>

              <a href="accounts.php" class="btn cancel-btn">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    <?php } ?>

    <!-- Edit Modal -->
    <?php if (isset($_GET['edit']) && $editRow) {
        // Preserve the existing spelling when editing an admin.
        $adminRoleValue = $editRow['role'] === 'admin_advisor'
            ? 'admin_advisor'
            : 'admin_adviser';

        $isEditedAdmin = in_array(
            $editRow['role'],
            ['admin_adviser', 'admin_advisor'],
            true
        );
    ?>
      <div class="modal">
        <div
          class="modal-content"
          role="dialog"
          aria-modal="true"
          aria-labelledby="editTitle"
        >
          <h3 id="editTitle">Edit Account</h3>

          <?php if (isset($_GET['error'])) { ?>
            <div class="error"><?php echo accountEscape($_GET['error']); ?></div>
          <?php } ?>

          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
            <input
              type="hidden"
              name="existing_photo"
              value="<?php echo accountEscape($editRow['photo']); ?>"
            >

            <label for="edit_username">Username</label>
            <input
              type="text"
              id="edit_username"
              name="username"
              value="<?php echo accountEscape($editRow['username']); ?>"
              required
            >

            <label for="edit_name">Full Name</label>
            <input
              type="text"
              id="edit_name"
              name="name"
              value="<?php echo accountEscape($editRow['name']); ?>"
              required
            >

            <label for="edit_role">Role</label>
            <select id="edit_role" name="role" required>
              <option
                value="<?php echo accountEscape($adminRoleValue); ?>"
                <?php echo $isEditedAdmin ? 'selected' : ''; ?>
              >
                Admin/Adviser
              </option>

              <option
                value="officer"
                <?php echo $editRow['role'] === 'officer' ? 'selected' : ''; ?>
              >
                Officer
              </option>
            </select>

            <label for="edit_position">Position</label>
            <input
              type="text"
              id="edit_position"
              name="position"
              value="<?php echo accountEscape($editRow['position']); ?>"
              required
            >

            <label for="edit_password">New Password</label>
            <input
              type="password"
              id="edit_password"
              name="password"
              placeholder="New Password"
              autocomplete="new-password"
            >
            <p class="helper">Leave blank to keep the current password.</p>

            <label for="edit_confirm">Confirm New Password</label>
            <input
              type="password"
              id="edit_confirm"
              name="confirm_password"
              placeholder="Confirm New Password"
              autocomplete="new-password"
            >

            <label for="edit_photo">Photo</label>
            <input type="file" id="edit_photo" name="photo" accept="image/*">

            <?php if ($editRow['photo']) { ?>
              <div class="current-photo">
                <img
                  src="<?php echo accountEscape(image_url($editRow['photo'])); ?>"
                  alt="Current photo"
                  class="account-photo"
                >
              </div>
            <?php } ?>

            <div class="modal-actions">
              <button type="submit" name="update" class="btn submit-btn">
                Update
              </button>

              <a href="accounts.php" class="btn cancel-btn">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    <?php } elseif (isset($_GET['edit'])) { ?>
      <div class="error">Account not found.</div>
    <?php } ?>
  </div>
</body>
</html>
