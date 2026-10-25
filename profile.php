<?php
declare(strict_types=1);
require_once __DIR__.'/admin_features.php';
require_once __DIR__ . '/includes/audit.php';
$userID=(int)$_SESSION['user_id'];
$user=af_user($conn,$userID);
if (!$user) { http_response_code(403); exit('Account no longer exists.'); }
$message=''; $messageType='error';
if (request_method_is('POST')) {
    af_csrf(); $created=[];
    try {
        if (($_POST['action'] ?? '')==='password') {
            if (!af_matches((string)($_POST['current_password'] ?? ''),$user['password'])) throw new RuntimeException('Current password is incorrect.');
            $hash=af_password((string)($_POST['new_password'] ?? ''),(string)($_POST['confirm_password'] ?? ''));
            $stmt=$conn->prepare('UPDATE users SET password=? WHERE id=?'); $stmt->bind_param('si',$hash,$userID); $stmt->execute();
            session_regenerate_id(true);
            audit_log($conn, 'profile.password', $user['name'] . ' changed their password', 'user', $userID);
            af_success('profile.php','Password updated. Other existing sessions are not revoked.');
        }
        if (($_POST['action'] ?? '')!=='details') throw new RuntimeException('Invalid action.');
        $name=af_text('name',100); $username=af_text('username',50);
        if ($username!==$user['username'] && !af_matches((string)($_POST['details_password'] ?? ''),$user['password'])) throw new RuntimeException('Enter your current password to change your username.');
        $stmt=$conn->prepare('SELECT id FROM users WHERE username=? AND id<>?'); $stmt->bind_param('si',$username,$userID); $stmt->execute();
        if ($stmt->get_result()->num_rows) throw new RuntimeException('Username is already in use.');
        $photo=af_upload('photo',$created) ?? $user['photo'];
        if (isset($_POST['remove_photo']) && !$created) $photo=null;
        $stmt=$conn->prepare('UPDATE users SET name=?, username=?, photo=? WHERE id=?'); $stmt->bind_param('sssi',$name,$username,$photo,$userID); $stmt->execute();
        $_SESSION['name']=$name; $_SESSION['username']=$username;
        audit_log($conn, 'profile.update', $name . ' updated their profile', 'user', $userID, audit_diff($user, ['name' => $name, 'username' => $username, 'photo' => $photo], ['name' => 'Name', 'username' => 'Username', 'photo' => 'Photo']));
        af_success('profile.php','Profile updated.');
    } catch (Throwable $ex) {
        af_cleanup($created);
        $message=$ex instanceof mysqli_sql_exception ? 'Could not save profile. Please try again.' : $ex->getMessage();
    }
}
?><!doctype html>
<html lang="en"><head>
<link rel="icon" type="image/png" href="assets/img/jpcs-logo.png?v=3">
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Profile | JPCS</title>

  <?php require __DIR__.'/includes/admin_head.php'; ?>
</head><body class="profile-page app-page admin-page jpcs-admin feature-profile">
<?php require __DIR__.'/includes/admin_sidebar.php'; ?>
<?php
$profileName = (string) ($_POST['name'] ?? $user['name']);
$profileUsername = (string) ($_POST['username'] ?? $user['username']);
$profileInitials = '';
foreach (preg_split('/\s+/', trim((string) $user['name'])) ?: [] as $part) if ($part !== '') $profileInitials .= mb_strtoupper(mb_substr($part, 0, 1));
$profileInitials = mb_substr($profileInitials !== '' ? $profileInitials : 'JP', 0, 2);
$profileHasPhoto = trim((string) $user['photo']) !== '';
$profileSince = !empty($user['created_at']) ? date('F Y', strtotime($user['created_at'])) : '';
$profileUsernameError = $message !== '' && str_contains($message, 'username');
?>
<main class="content profile-v2">
  <header class="page-header profile-head">
    <div>
      <p class="profile-eyebrow">Account</p>
      <h1>My Profile</h1>
      <p>Manage how you appear in the JPCS workspace and keep your sign-in secure.</p>
    </div>
  </header>
  <?php if ($message) { ?><p role="alert" class="profile-message error"><?= e($message) ?></p><?php } ?>
  <?php if (isset($_SESSION['af_notice'])) { ?><p role="status" class="profile-message success"><?= e($_SESSION['af_notice']) ?></p><?php unset($_SESSION['af_notice']); } ?>

  <section class="profile-hero" aria-label="Profile summary">
    <div class="profile-avatar<?= $profileHasPhoto ? ' has-photo' : '' ?>">
      <img id="profile-photo" src="<?= e(image_url($user['photo'])) ?>" alt="Your profile photo" width="132" height="132"<?= $profileHasPhoto ? '' : ' hidden' ?>>
      <span class="profile-avatar__initials" aria-hidden="true"<?= $profileHasPhoto ? ' hidden' : '' ?>><?= e($profileInitials) ?></span>
      <label class="profile-avatar__edit" for="photo" title="Change photo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"></path><circle cx="12" cy="13" r="3.5"></circle></svg><span class="visually-hidden">Change photo</span></label>
    </div>
    <div class="profile-hero__info">
      <h2 data-profile-name><?= e($user['name']) ?></h2>
      <p class="profile-handle"><span data-profile-at<?= str_contains((string) $user['username'], '@') ? ' hidden' : '' ?>>@</span><span data-profile-username><?= e($user['username']) ?></span></p>
      <div class="profile-chips">
        <span class="profile-chip is-role"><?= e(role_label($user['role'])) ?></span>
        <?php if (trim((string) $user['position']) !== '') { ?><span class="profile-chip"><?= e($user['position']) ?></span><?php } ?>
        <?php if ($profileSince !== '') { ?><span class="profile-chip is-muted">Member since <?= e($profileSince) ?></span><?php } ?>
      </div>
    </div>
  </section>

  <div class="profile-grid">
    <section class="panel profile-panel" aria-labelledby="profile-details-title">
      <header class="profile-panel__head">
        <span class="profile-panel__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c.8-3.6 3.3-5.5 7-5.5s6.2 1.9 7 5.5"></path></svg></span>
        <div><h2 id="profile-details-title">Personal details</h2><p>Your name and the username you sign in with.</p></div>
      </header>
      <form class="profile-form" id="profile-details" method="post" enctype="multipart/form-data" data-profile-details><?php af_field(); ?><input type="hidden" name="action" value="details">
        <div class="profile-field">
          <label for="name">Full name</label>
          <input id="name" name="name" maxlength="100" required autocomplete="name" value="<?= e($profileName) ?>">
        </div>
        <div class="profile-field">
          <label for="username">Username / email</label>
          <input id="username" name="username" maxlength="50" autocomplete="username" required value="<?= e($profileUsername) ?>" data-original="<?= e($user['username']) ?>">
        </div>
        <div class="profile-field profile-verify" data-profile-verify<?= $profileUsername !== $user['username'] || $profileUsernameError ? '' : ' data-collapsed' ?>>
          <label for="details_password">Current password</label>
          <input id="details_password" name="details_password" type="password" autocomplete="current-password">
          <small>Required because you are changing your username.</small>
        </div>
        <div class="profile-field">
          <span class="profile-label">Photo</span>
          <label class="profile-drop" for="photo" data-profile-drop>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V5M7.5 9.5 12 5l4.5 4.5M5 19h14"></path></svg>
            <span><strong>Upload a new photo</strong><small data-profile-file>JPEG, PNG or WebP &middot; max 5 MB</small></span>
            <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
          </label>
          <?php if ($profileHasPhoto) { ?><label class="profile-check"><input type="checkbox" name="remove_photo" value="1" data-profile-remove> Remove current photo</label><?php } ?>
        </div>
        <p class="profile-note"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v5M12 8h.01"></path></svg>Your role and position are managed by an adviser.</p>
        <div class="profile-actions"><button type="submit">Save changes</button></div>
      </form>
    </section>

    <section class="panel profile-panel" aria-labelledby="profile-security-title">
      <header class="profile-panel__head">
        <span class="profile-panel__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></span>
        <div><h2 id="profile-security-title">Security</h2><p>Change the password you use to sign in.</p></div>
      </header>
      <form class="profile-form" method="post" data-profile-password><?php af_field(); ?><input type="hidden" name="action" value="password">
        <div class="profile-field">
          <label for="current_password">Current password</label>
          <div class="profile-secret"><input id="current_password" name="current_password" type="password" autocomplete="current-password" required><button type="button" class="profile-eye" data-profile-eye aria-label="Show password" aria-controls="current_password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button></div>
        </div>
        <div class="profile-field">
          <label for="new_password">New password</label>
          <div class="profile-secret"><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required aria-describedby="profile-strength-text"><button type="button" class="profile-eye" data-profile-eye aria-label="Show password" aria-controls="new_password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button></div>
          <div class="profile-strength" data-profile-strength data-level="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
          <small id="profile-strength-text" data-profile-strength-text>Use 8–72 characters. Mixing letters, numbers and symbols makes it stronger.</small>
        </div>
        <div class="profile-field">
          <label for="confirm_password">Confirm new password</label>
          <div class="profile-secret"><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required><button type="button" class="profile-eye" data-profile-eye aria-label="Show password" aria-controls="confirm_password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button></div>
          <small class="profile-match" data-profile-match aria-live="polite"></small>
        </div>
        <p class="profile-note"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v5M12 8h.01"></path></svg>Other devices stay signed in until their sessions end.</p>
        <div class="profile-actions"><button type="submit">Update password</button></div>
      </form>
    </section>
  </div>
</main>
<script src="js/profile.js?v=<?= filemtime(__DIR__ . '/js/profile.js') ?>" defer></script>
</body></html>
