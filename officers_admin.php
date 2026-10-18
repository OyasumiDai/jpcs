<?php
require_once __DIR__ . '/admin_features.php';
require_once __DIR__ . '/includes/public_officers.php';
ensure_public_officers_table($conn);

$isPublicProfile = isset($_GET['create']) || isset($_GET['profile']) || isset($_POST['profile_id']);
if (!$isPublicProfile && !isset($_GET['edit']) && !request_method_is('POST')) { require __DIR__ . '/officers.php'; exit; }
af_require_admin($conn);

$error = '';
$created = [];

if ($isPublicProfile) {
    $profileID = (int) ($_POST['profile_id'] ?? $_GET['profile'] ?? 0);
    $profile = [];
    if ($profileID > 0) {
        $profileStatement = $conn->prepare('SELECT * FROM officer_profiles WHERE id=? LIMIT 1');
        $profileStatement->bind_param('i', $profileID);
        $profileStatement->execute();
        $profile = $profileStatement->get_result()->fetch_assoc() ?: [];
        $profileStatement->close();
        if (!$profile) { http_response_code(404); exit('Public officer profile not found.'); }
    }
    if (request_method_is('POST')) {
        try {
            af_csrf();
            $name = af_text('name', 100);
            $position = af_text('position', 100, false);
            $photo = af_upload('photo', $created) ?? ($profile['photo'] ?? null);
            if (isset($_POST['remove_photo']) && !$created) $photo = null;
            if ($profileID > 0) {
                $statement = $conn->prepare('UPDATE officer_profiles SET name=?, position=?, photo=? WHERE id=?');
                $statement->bind_param('sssi', $name, $position, $photo, $profileID);
            } else {
                $statement = $conn->prepare('INSERT INTO officer_profiles (name, position, photo) VALUES (?, ?, ?)');
                $statement->bind_param('sss', $name, $position, $photo);
            }
            $statement->execute(); $statement->close();
            af_success('officers.php', $profileID > 0 ? 'Public officer profile updated.' : 'Public officer profile added.');
        } catch (Throwable $exception) {
            af_cleanup($created);
            $error = $exception instanceof mysqli_sql_exception ? 'Could not save the public officer profile.' : $exception->getMessage();
        }
    }
    af_head($profileID > 0 ? 'Edit public officer' : 'Add public officer');
    ?>
    <?php if ($error) { ?><p role="alert" class="error"><?= e($error) ?></p><?php } ?>
    <p>This profile is displayed publicly on the landing page. It does not create a login account.</p>
    <form method="post" enctype="multipart/form-data"><?php af_field(); ?><input type="hidden" name="profile_id" value="<?= $profileID ?>">
        <p><img id="officer-preview" class="officer-photo-preview" src="<?= e(image_url($profile['photo'] ?? null)) ?>" alt="Officer photo" width="140" height="140"></p>
        <label for="name">Full name</label><input id="name" name="name" maxlength="100" required value="<?= e($_POST['name'] ?? ($profile['name'] ?? '')) ?>">
        <label for="position">Position</label><input id="position" name="position" maxlength="100" value="<?= e($_POST['position'] ?? ($profile['position'] ?? '')) ?>">
        <label for="photo">Photo (JPEG, PNG or WebP; max 5 MB)</label><input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
        <?php if (!empty($profile['photo'])) { ?><label><input type="checkbox" name="remove_photo" value="1"> Remove current photo</label><?php } ?>
        <button type="submit"><?= $profileID > 0 ? 'Save profile' : 'Add public officer' ?></button> <a href="officers.php">Cancel</a>
    </form>
    <script>document.getElementById('photo').addEventListener('change',function(){const img=document.getElementById('officer-preview');if(this.files[0])img.src=URL.createObjectURL(this.files[0]);});</script>
    <?php af_end(); exit;
}

$id = (int) ($_POST['id'] ?? $_GET['edit'] ?? 0);
$officer = af_user($conn, $id);
if (!$officer) { http_response_code(404); exit('Officer not found.'); }
if (request_method_is('POST')) {
    try {
        af_csrf();
        $name = af_text('name', 100); $position = af_text('position', 100, false);
        $photo = af_upload('photo', $created) ?? $officer['photo'];
        if (isset($_POST['remove_photo']) && !$created) $photo = null;
        $statement = $conn->prepare('UPDATE users SET name=?, position=?, photo=? WHERE id=?');
        $statement->bind_param('sssi', $name, $position, $photo, $id); $statement->execute(); $statement->close();
        if ($id === (int) $_SESSION['user_id']) { $_SESSION['name'] = $name; $_SESSION['position'] = $position; }
        af_success('officers.php', 'Officer details updated.');
    } catch (Throwable $exception) {
        af_cleanup($created);
        $error = $exception instanceof mysqli_sql_exception ? 'Could not save officer. Please try again.' : $exception->getMessage();
    }
}
af_head('Edit officer');
?>
<?php if ($error) { ?><p role="alert" class="error"><?= e($error) ?></p><?php } ?>
<form method="post" enctype="multipart/form-data"><?php af_field(); ?><input type="hidden" name="id" value="<?= $id ?>">
<p><img id="officer-preview" class="officer-photo-preview" src="<?= e(image_url($officer['photo'])) ?>" alt="Officer photo" width="140" height="140"></p>
<label for="name">Full name</label><input id="name" name="name" maxlength="100" required value="<?= e($_POST['name'] ?? $officer['name']) ?>">
<label for="position">Position</label><input id="position" name="position" maxlength="100" value="<?= e($_POST['position'] ?? $officer['position']) ?>">
<label for="photo">Photo (JPEG, PNG or WebP; max 5 MB)</label><input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
<label><input type="checkbox" name="remove_photo" value="1"> Remove current photo</label>
<p>Username / contact: <?= e($officer['username']) ?>. Login details remain managed in Account Management.</p>
<button type="submit">Save officer</button> <a href="officers.php">Cancel</a>
</form><script>document.getElementById('photo').addEventListener('change',function(){const img=document.getElementById('officer-preview');if(this.files[0])img.src=URL.createObjectURL(this.files[0]);});</script>
<?php af_end(); ?>
