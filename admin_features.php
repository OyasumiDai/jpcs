<?php
declare(strict_types=1);
// Dedicated support for the account, officer, profile and event editors.
require_once __DIR__ . '/includes/auth.php';
require_login();

function af_token(): string {
    return $_SESSION['admin_features_csrf'] ??= bin2hex(random_bytes(32));
}
function af_csrf(): void {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals(af_token(), $_POST['csrf'])) {
        http_response_code(403); exit('Invalid form token. Reload the page and try again.');
    }
}
function af_field(): void { echo '<input type="hidden" name="csrf" value="'.e(af_token()).'">'; }
function af_user(mysqli $conn, int $id): array {
    $stmt = $conn->prepare('SELECT * FROM users WHERE id=?');
    $stmt->bind_param('i', $id); $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}
function af_admin(mysqli $conn): bool {
    return in_array(af_user($conn, (int)$_SESSION['user_id'])['role'] ?? '', ['admin_advisor','admin_adviser'], true);
}
function af_require_admin(mysqli $conn): void {
    if (!af_admin($conn)) { http_response_code(403); exit('Access denied.'); }
}
function af_text(string $key, int $max, bool $required = true): string {
    $value = trim((string)($_POST[$key] ?? ''));
    if (($required && $value === '') || mb_strlen($value) > $max) throw new RuntimeException('Check the '.$key.' field (maximum '.$max.' characters).');
    return $value;
}
function af_password(string $value, string $confirm): string {
    if (strlen($value) < 8 || strlen($value) > 72) throw new RuntimeException('Use a password between 8 and 72 bytes.');
    if ($value !== $confirm) throw new RuntimeException('Passwords do not match.');
    return password_hash($value, PASSWORD_DEFAULT);
}
function af_matches(string $password, string $stored): bool {
    return $stored !== '' && (password_verify($password, $stored) || (!password_get_info($stored)['algo'] && hash_equals($stored, $password)));
}
function af_upload(string $field, array &$created): ?string {
    $file = $_FILES[$field] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (!is_int($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Image upload failed.');
    if ($file['size'] > 5 * 1024 * 1024) throw new RuntimeException('Images must be at most 5 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $size = @getimagesize($file['tmp_name']);
    if (!isset($extensions[$mime]) || !$size || $size[0] * $size[1] > 25000000) throw new RuntimeException('Choose a JPEG, PNG or WebP image up to 25 megapixels.');
    $dir = __DIR__.'/uploads/admin';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Upload storage is unavailable.');
    $path = 'uploads/admin/'.bin2hex(random_bytes(16)).'.'.$extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], __DIR__.'/'.$path)) throw new RuntimeException('Could not save image.');
    $created[] = $path;
    return $path;
}
function af_cleanup(array $created): void { foreach ($created as $path) @unlink(__DIR__.'/'.$path); }
function af_head(string $title): void { global $conn; ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> | JPCS</title><?php require __DIR__.'/includes/admin_head.php'; ?>
</head><body class="app-page admin-page jpcs-admin feature-admin-features">
<?php require __DIR__.'/includes/admin_sidebar.php'; ?>
<main class="content"><h1><?= e($title) ?></h1>
<?php if (isset($_SESSION['af_notice'])) { ?><p role="status" class="success"><?= e($_SESSION['af_notice']) ?></p><?php unset($_SESSION['af_notice']); } ?>
<?php }
function af_success(string $route, string $message): never { $_SESSION['af_notice']=$message; redirect($route); }
function af_end(): void { ?></main></body></html><?php }
