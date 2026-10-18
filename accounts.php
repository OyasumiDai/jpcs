<?php
require_once __DIR__.'/admin_features.php';
require_admin();
af_require_admin($conn);
function accountEscape($value) { return e($value); }
if (request_method_is('POST')) {
    af_csrf();
    $created = [];
    $id = (int)($_POST['id'] ?? 0);
    try {
        $conn->begin_transaction();
        // Lock all account rows to serialize role changes and duplicate checks.
        $conn->query('SELECT id FROM users ORDER BY id FOR UPDATE');
        $existing = $id ? af_user($conn, $id) : [];
        if ($id && !$existing) throw new RuntimeException('Account not found.');
        $username = af_text('username', 50);
        $name = af_text('name', 100);
        $position = af_text('position', 100, false);
        $role = (string)($_POST['role'] ?? '');
        if (!in_array($role, ['officer','admin_advisor','admin_adviser'], true)) throw new RuntimeException('Invalid role.');
        // Match the deployed enum spelling without changing the schema.
        $column = $conn->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch_assoc()['Type'];
        if ($role !== 'officer') $role = str_contains($column, "'admin_advisor'") ? 'admin_advisor' : 'admin_adviser';
        if ($id === (int)$_SESSION['user_id'] && $role === 'officer') throw new RuntimeException('You cannot remove your own administrator access.');
        if ($existing && $existing['role'] !== 'officer' && $role === 'officer') {
            $count = $conn->query("SELECT COUNT(*) FROM users WHERE role IN ('admin_advisor','admin_adviser')")->fetch_row()[0];
            if ($count <= 1) throw new RuntimeException('Keep at least one administrator.');
        }
        $stmt = $conn->prepare('SELECT id FROM users WHERE username=? AND id<>?');
        $stmt->bind_param('si', $username, $id); $stmt->execute();
        if ($stmt->get_result()->num_rows) throw new RuntimeException('Username is already in use.');
        $password = (string)($_POST['password'] ?? '');
        $hash = (!$existing || $password !== '') ? af_password($password, (string)($_POST['confirm_password'] ?? '')) : $existing['password'];
        $photo = af_upload('photo', $created) ?? ($existing['photo'] ?? null);
        if ($existing) {
            $stmt = $conn->prepare('UPDATE users SET username=?, name=?, role=?, position=?, photo=?, password=? WHERE id=?');
            $stmt->bind_param('ssssssi', $username, $name, $role, $position, $photo, $hash, $id);
        } else {
            $stmt = $conn->prepare('INSERT INTO users (username,name,role,position,photo,password) VALUES (?,?,?,?,?,?)');
            $stmt->bind_param('ssssss', $username, $name, $role, $position, $photo, $hash);
        }
        $stmt->execute(); $conn->commit();
        if ($id === (int)$_SESSION['user_id']) {
            $_SESSION['username']=$username; $_SESSION['name']=$name; $_SESSION['position']=$position; $_SESSION['role']=$role;
        }
        redirect('accounts.php?success='.urlencode('Account saved. Password changes apply at next sign-in.'));
    } catch (Throwable $ex) {
        $conn->rollback(); af_cleanup($created);
        $message = $ex instanceof mysqli_sql_exception ? 'Could not save account. Please try again.' : $ex->getMessage();
        redirect('accounts.php?'.($id ? 'edit='.$id : 'createform=1').'&error='.urlencode($message));
    }
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
<link rel="icon" type="image/png" href="assets/img/jpcs-logo.png?v=3">

  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Account Management</title>


<?php require __DIR__.'/includes/admin_head.php'; ?>
</head>

<body class="app-page admin-page jpcs-admin feature-accounts">
  <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

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

    <p>Deactivation is unavailable with the current account schema. Role and password changes take full effect after existing sessions end.</p><div class="toolbar">
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

                    <a class="btn" href="accounts.php?edit=<?php echo (int)$row['id']; ?>#edit_password">Reset password</a>
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

          <form method="POST" enctype="multipart/form-data"><?php af_field(); ?>
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
              <option value="admin_advisor">Admin/Adviser</option>
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

          <form method="POST" enctype="multipart/form-data"><?php af_field(); ?>
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
            <p class="helper">Leave blank to keep the current password. To reset it, enter and confirm a new password (8–72 bytes).</p>

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
