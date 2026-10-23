<?php
declare(strict_types=1);
require_once __DIR__.'/admin_features.php';
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
<main class="content"><header class="page-header"><h1>My Profile</h1></header><section class="profile-card">
<?php if ($message) { ?><p role="alert" class="profile-message error"><?= e($message) ?></p><?php } ?>
<?php if (isset($_SESSION['af_notice'])) { ?><p role="status" class="profile-message success"><?= e($_SESSION['af_notice']) ?></p><?php unset($_SESSION['af_notice']); } ?>
<p><?= e($user['position']) ?> · <?= e(role_label($user['role'])) ?></p>
<h2>Personal details</h2><form class="profile-form" method="post" enctype="multipart/form-data"><?php af_field(); ?><input type="hidden" name="action" value="details">
<img id="profile-photo" src="<?= e(image_url($user['photo'])) ?>" alt="Your profile photo" width="120" height="120" class="profile-photo">
<label for="name">Full name</label><input id="name" name="name" maxlength="100" required value="<?= e($_POST['name'] ?? $user['name']) ?>">
<label for="username">Username / email</label><input id="username" name="username" maxlength="50" autocomplete="username" required value="<?= e($_POST['username'] ?? $user['username']) ?>">
<label for="details_password">Current password (required when changing username)</label><input id="details_password" name="details_password" type="password" autocomplete="current-password">
<label for="photo">Photo (JPEG, PNG or WebP; max 5 MB)</label><input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
<label><input type="checkbox" name="remove_photo" value="1"> Remove current photo</label>
<p>Your administrator manages your role and position.</p><button type="submit">Save profile</button></form>
<h2>Change password</h2><form class="profile-form" method="post"><?php af_field(); ?><input type="hidden" name="action" value="password">
<label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
<label for="new_password">New password (8–72 bytes)</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required>
<label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required><button type="submit">Update password</button></form>
</section></main><script>
document.getElementById('photo').addEventListener('change', function() {
 const img=document.getElementById('profile-photo');
 if (img.dataset.preview) URL.revokeObjectURL(img.dataset.preview);
 if (this.files[0]) { img.dataset.preview=URL.createObjectURL(this.files[0]); img.src=img.dataset.preview; }
});
</script></body></html>
