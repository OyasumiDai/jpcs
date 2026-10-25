<?php
require_once __DIR__.'/admin_features.php';
require_once __DIR__ . '/includes/audit.php';
require_admin();
af_require_admin($conn);
require_once __DIR__ . '/includes/permissions.php';
permissions_ensure_schema($conn);
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
        if ($existing && $existing['role'] !== 'officer') throw new RuntimeException('The adviser account is managed in My Profile.');
        $username = af_text('username', 50);
        $name = af_text('name', 100);
        $position = af_text('position', 100, false);
        $role = 'officer';
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
        $stmt->execute(); $savedId = $existing ? $id : (int) $conn->insert_id;
        $permissionChange = null;
        if ($role === 'officer') {
            $chosenFeatures = array_map('strval', (array) ($_POST['features'] ?? []));
            $permissionChange = permissions_save($conn, $savedId, $chosenFeatures, (int) $_SESSION['user_id']);
        }
        $conn->commit();
        if ($existing) {
            $changes = audit_diff($existing, ['username' => $username, 'name' => $name, 'role' => $role, 'position' => $position, 'photo' => $photo], ['username' => 'Username', 'name' => 'Name', 'role' => 'Role', 'position' => 'Position', 'photo' => 'Photo']);
            if ($password !== '') $changes['Password'] = ['', 'reset'];
            if ($permissionChange && $permissionChange[0] !== $permissionChange[1]) $changes['Feature access'] = [permissions_labels($permissionChange[0]), permissions_labels($permissionChange[1])];
            audit_log($conn, 'account.update', 'Updated account ' . $username, 'user', $savedId, $changes);
        } else {
            audit_log($conn, 'account.create', 'Created ' . role_label($role) . ' account ' . $username, 'user', $savedId, ['Name' => ['', $name], 'Role' => ['', role_label($role)], 'Position' => ['', $position]] + ($permissionChange ? ['Feature access' => ['', permissions_labels($permissionChange[1])]] : []));
        }
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
    WHERE role = 'officer'
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
    $stmt = $conn->prepare("SELECT * FROM users WHERE id=? AND role='officer'");
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
  <?php
  $accRows = $result->fetch_all(MYSQLI_ASSOC);
  $accTotal = count(PANEL_FEATURES);
  $accFull = 0;
  foreach ($accRows as &$accRow) {
      $accRow['features'] = permissions_for($conn, $accRow);
      if (count($accRow['features']) === $accTotal) $accFull++;
      $accInitials = '';
      foreach (preg_split('/\s+/', trim((string) $accRow['name'])) ?: [] as $part) if ($part !== '') $accInitials .= mb_strtoupper(mb_substr($part, 0, 1));
      $accRow['initials'] = mb_substr($accInitials !== '' ? $accInitials : 'O', 0, 2);
  }
  unset($accRow);
  $accFormOpen = isset($_GET['createform']) || $editRow;
  $accForm = $editRow ?: [];
  $accFormFeatures = $editRow ? permissions_for($conn, $editRow) : array_keys(PANEL_FEATURES);
  ?>

  <main class="content">
    <header class="page-header ac-head">
      <div>
        <h1>Account Management</h1>
        <p>Create officer accounts and choose which panel features each officer can use. Your own adviser account is managed in <a href="profile.php">My Profile</a>.</p>
      </div>
      <a href="accounts.php?createform=1" class="btn btn-create ac-new"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>New officer</a>
    </header>

    <?php if (isset($_GET['success'])) { ?><p class="success" role="status"><?php echo accountEscape($_GET['success']); ?></p><?php } ?>
    <?php if (isset($_GET['error']) && !$accFormOpen) { ?><p class="error" role="alert"><?php echo accountEscape($_GET['error']); ?></p><?php } ?>

    <div class="ac-stats">
      <div class="stat-tile"><span>Officer accounts</span><strong><?php echo count($accRows); ?></strong></div>
      <div class="stat-tile is-good"><span>Full access</span><strong><?php echo $accFull; ?></strong></div>
      <div class="stat-tile is-warn"><span>Limited access</span><strong><?php echo count($accRows) - $accFull; ?></strong></div>
    </div>

    <div class="ac-toolbar">
      <label class="rx-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg><span class="visually-hidden">Search accounts</span><input type="search" placeholder="Search name, username or position" data-ac-search></label>
      <div class="rx-segment" role="group" aria-label="Filter by access">
        <button type="button" data-ac-filter="all" aria-pressed="true">All</button>
        <button type="button" data-ac-filter="full" aria-pressed="false">Full access</button>
        <button type="button" data-ac-filter="limited" aria-pressed="false">Limited</button>
      </div>
    </div>

    <?php if (!$accRows) { ?>
      <div class="rx-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"></circle><path d="M5 20c.8-3.3 3.2-5 7-5s6.2 1.7 7 5"></path></svg></span><strong>No officer accounts yet</strong><p>Create one so officers can sign in to the panel.</p><a class="btn btn-create" href="accounts.php?createform=1">New officer</a></div>
    <?php } else { ?>
    <div class="ac-grid">
      <?php foreach ($accRows as $i => $row) { $full = count($row['features']) === $accTotal; ?>
        <article class="ac-card" data-ac-card data-access="<?php echo $full ? 'full' : 'limited'; ?>" data-search="<?php echo accountEscape(mb_strtolower($row['name'] . ' ' . $row['username'] . ' ' . $row['position'])); ?>" style="--ac-delay: <?php echo min($i, 10) * 50; ?>ms">
          <div class="ac-card__top">
            <span class="ac-avatar"><?php if ($row['photo']) { ?><img src="<?php echo accountEscape(image_url($row['photo'])); ?>" alt=""><?php } else { echo accountEscape($row['initials']); } ?></span>
            <div class="ac-card__who">
              <strong><?php echo accountEscape($row['name']); ?></strong>
              <span><?php echo accountEscape($row['position'] !== '' && $row['position'] !== null ? $row['position'] : 'Officer'); ?></span>
              <small><?php echo accountEscape($row['username']); ?></small>
            </div>
          </div>
          <div class="ac-card__access">
            <div class="ac-card__access-head"><span>Feature access</span><b class="<?php echo $full ? 'is-full' : 'is-limited'; ?>"><?php echo $full ? 'Full access' : count($row['features']) . ' of ' . $accTotal; ?></b></div>
            <div class="ac-meter" aria-hidden="true"><?php foreach (PANEL_FEATURES as $key => $feature) { ?><span class="<?php echo in_array($key, $row['features'], true) ? 'is-on' : ''; ?>" title="<?php echo accountEscape($feature['label']); ?>"></span><?php } ?></div>
            <ul class="ac-features">
              <?php foreach (PANEL_FEATURES as $key => $feature) { $on = in_array($key, $row['features'], true); ?>
                <li class="<?php echo $on ? 'is-on' : 'is-off'; ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $on ? '<path d="m5 12.5 4.5 4.5L19 7.5"></path>' : '<path d="M7 7l10 10M17 7 7 17"></path>'; ?></svg><?php echo accountEscape($feature['label']); ?><span class="visually-hidden"><?php echo $on ? ' (allowed)' : ' (no access)'; ?></span></li>
              <?php } ?>
            </ul>
          </div>
          <div class="ac-card__actions">
            <a class="btn" href="accounts.php?edit=<?php echo (int) $row['id']; ?>">Edit &amp; access</a>
            <a class="btn btn-ghost" href="accounts.php?edit=<?php echo (int) $row['id']; ?>&amp;focus=password">Reset password</a>
          </div>
        </article>
      <?php } ?>
    </div>
    <div class="rx-empty" data-ac-empty hidden><strong>No accounts match.</strong></div>
    <?php } ?>

    <?php if ($accFormOpen) { $isEdit = (bool) $editRow; ?>
    <div class="modal ef-modal">
      <section class="modal-content ef-dialog" role="dialog" aria-modal="true" aria-labelledby="ac-form-title">
        <form method="POST" enctype="multipart/form-data" class="ef-form ac-form" data-ac-form><?php af_field(); ?>
          <?php if ($isEdit) { ?><input type="hidden" name="id" value="<?php echo (int) $editRow['id']; ?>"><?php } ?>
          <input type="hidden" name="role" value="officer">
          <header class="ef-head">
            <span class="ef-head__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"></circle><path d="M5 20c.8-3.3 3.2-5 7-5s6.2 1.7 7 5"></path><?php if (!$isEdit) { ?><path d="M19 3v4M17 5h4"></path><?php } ?></svg></span>
            <div><h2 id="ac-form-title"><?php echo $isEdit ? 'Edit officer account' : 'New officer account'; ?></h2><p><?php echo $isEdit ? 'Update details, reset the password or change feature access.' : 'The officer signs in with this username and password.'; ?></p></div>
            <a class="ef-close cancel-btn" href="accounts.php" aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"></path></svg></a>
          </header>
          <div class="ef-body">
            <?php if (isset($_GET['error'])) { ?><p role="alert" class="ef-alert"><?php echo accountEscape($_GET['error']); ?></p><?php } ?>
            <fieldset class="ef-section">
              <legend>Profile</legend>
              <div class="ac-profile">
                <label class="ac-photo" for="ac_photo" data-ac-photo>
                  <?php $hasPhoto = !empty($accForm['photo']); ?>
                  <img src="<?php echo $hasPhoto ? accountEscape(image_url($accForm['photo'])) : ''; ?>" alt=""<?php echo $hasPhoto ? '' : ' hidden'; ?> data-ac-photo-img>
                  <span class="ac-photo__empty"<?php echo $hasPhoto ? ' hidden' : ''; ?>><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"></path><circle cx="12" cy="13" r="3.5"></circle></svg>Photo</span>
                  <input type="file" id="ac_photo" name="photo" accept="image/jpeg,image/png,image/webp">
                </label>
                <div class="ac-profile__fields">
                  <div class="ef-field"><label for="ac_name">Full name</label><input id="ac_name" name="name" maxlength="100" required placeholder="e.g. Juan Dela Cruz" value="<?php echo accountEscape($accForm['name'] ?? ''); ?>"></div>
                  <div class="ef-field"><label for="ac_position">Position <span class="ef-optional">optional</span></label><input id="ac_position" name="position" maxlength="100" placeholder="e.g. Secretary" value="<?php echo accountEscape($accForm['position'] ?? ''); ?>"></div>
                </div>
              </div>
            </fieldset>
            <fieldset class="ef-section">
              <legend>Sign-in</legend>
              <div class="ef-field"><label for="ac_username">Username or email</label><input id="ac_username" name="username" maxlength="50" required autocomplete="off" placeholder="e.g. secretary@cmu.edu.ph" value="<?php echo accountEscape($accForm['username'] ?? ''); ?>"></div>
              <div class="ef-row">
                <div class="ef-field"><label for="ac_password"><?php echo $isEdit ? 'New password' : 'Password'; ?><?php if ($isEdit) { ?> <span class="ef-optional">optional</span><?php } ?></label>
                  <div class="profile-secret"><input id="ac_password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password"<?php echo $isEdit ? '' : ' required'; ?> data-ac-password><button type="button" class="profile-eye" data-ac-eye aria-label="Show password" aria-controls="ac_password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button></div>
                </div>
                <div class="ef-field"><label for="ac_confirm">Confirm password</label>
                  <div class="profile-secret"><input id="ac_confirm" name="confirm_password" type="password" minlength="8" maxlength="72" autocomplete="new-password"<?php echo $isEdit ? '' : ' required'; ?> data-ac-confirm><button type="button" class="profile-eye" data-ac-eye aria-label="Show password" aria-controls="ac_confirm"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button></div>
                </div>
              </div>
              <div class="ac-password-help">
                <div class="profile-strength" data-ac-strength data-level="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                <small data-ac-password-note><?php echo $isEdit ? 'Leave both empty to keep the current password.' : 'Use 8–72 characters.'; ?></small>
                <button type="button" class="btn btn-ghost ac-generate" data-ac-generate>Generate password</button>
              </div>
            </fieldset>
            <fieldset class="ef-section perm-fieldset" data-perm-fieldset>
              <legend>Feature access <small>The officer only sees the features ticked here.</small></legend>
              <div class="perm-actions"><button type="button" class="btn btn-ghost" data-perm-all>Select all</button><button type="button" class="btn btn-ghost" data-perm-none>Clear</button><span class="perm-count" data-perm-count></span></div>
              <div class="perm-grid">
                <?php foreach (PANEL_FEATURES as $featureKey => $feature) { ?>
                  <label class="perm-option"><input type="checkbox" name="features[]" value="<?php echo accountEscape($featureKey); ?>"<?php echo in_array($featureKey, $accFormFeatures, true) ? ' checked' : ''; ?>><span><strong><?php echo accountEscape($feature['label']); ?></strong><small><?php echo accountEscape($feature['hint']); ?></small></span></label>
                <?php } ?>
              </div>
              <p class="perm-note">Dashboard, My Profile and Notifications are always available.</p>
            </fieldset>
          </div>
          <footer class="ef-foot modal-actions">
            <a class="btn btn-ghost cancel-btn" href="accounts.php">Cancel</a>
            <button type="submit"><?php echo $isEdit ? 'Save changes' : 'Create account'; ?></button>
          </footer>
        </form>
      </section>
    </div>
    <?php } ?>
  </main>
  <script src="js/accounts.js?v=<?php echo filemtime(__DIR__ . '/js/accounts.js'); ?>" defer></script>
</body>
</html>
