<?php
declare(strict_types=1);

// Forgot password: a 6-digit code is emailed to the account, then the user sets a new password.

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/mail_config.php';
require_once dirname(__DIR__) . '/PHPMailer/src/Exception.php';
require_once dirname(__DIR__) . '/PHPMailer/src/PHPMailer.php';
require_once dirname(__DIR__) . '/PHPMailer/src/SMTP.php';

const PASSWORD_RESET_MINUTES = 15;     // how long a code stays valid
const PASSWORD_RESET_ATTEMPTS = 5;     // wrong guesses allowed per code
const PASSWORD_RESET_COOLDOWN = 60;    // seconds before another code can be sent
const PASSWORD_RESET_HOURLY = 5;       // codes per account per hour

function password_reset_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS password_resets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_type ENUM('staff', 'student') NOT NULL,
        account_id INT UNSIGNED NOT NULL,
        email VARCHAR(150) NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        verified_at DATETIME NULL DEFAULT NULL,
        used_at DATETIME NULL DEFAULT NULL,
        ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_password_resets_account (account_type, account_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $done = true;
}

/**
 * The account an email, student ID or username belongs to, with the address to send the code to.
 * Staff sign in with their email as the username; students use their email or student ID.
 */
function password_reset_find_account(mysqli $conn, string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') return null;

    $stmt = $conn->prepare('SELECT id, name, username FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $identifier);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($staff && filter_var($staff['username'], FILTER_VALIDATE_EMAIL)) {
        return ['type' => 'staff', 'id' => (int) $staff['id'], 'name' => (string) $staff['name'], 'email' => (string) $staff['username'], 'logins' => [(string) $staff['username']]];
    }

    $stmt = $conn->prepare('SELECT id, full_name, email, student_id FROM student_accounts WHERE email = ? OR student_id = ? LIMIT 2');
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (count($students) === 1 && filter_var($students[0]['email'], FILTER_VALIDATE_EMAIL)) {
        return ['type' => 'student', 'id' => (int) $students[0]['id'], 'name' => (string) $students[0]['full_name'], 'email' => (string) $students[0]['email'], 'logins' => [(string) $students[0]['email'], (string) $students[0]['student_id']]];
    }
    return null;
}

/** "da••••@gmail.com" — enough for people to recognise their address. */
function password_reset_mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $keep = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));
    return $keep . str_repeat('•', max(3, min(6, mb_strlen($local) - mb_strlen($keep)))) . '@' . $domain;
}

/** Seconds until another code may be sent (0 = now). */
function password_reset_wait(mysqli $conn, array $account): int
{
    password_reset_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT COUNT(*) AS sent, MAX(TIMESTAMPDIFF(SECOND, created_at, NOW())) AS oldest, MIN(TIMESTAMPDIFF(SECOND, created_at, NOW())) AS newest
        FROM password_resets WHERE account_type = ? AND account_id = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $stmt->bind_param('si', $account['type'], $account['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int) $row['sent'] >= PASSWORD_RESET_HOURLY) return max(1, 3600 - (int) $row['oldest']);
    if ($row['newest'] !== null && (int) $row['newest'] < PASSWORD_RESET_COOLDOWN) return PASSWORD_RESET_COOLDOWN - (int) $row['newest'];
    return 0;
}

/** Creates a new code (older ones stop working) and emails it. */
function password_reset_send(mysqli $conn, array $account): void
{
    password_reset_ensure_schema($conn);
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $minutes = PASSWORD_RESET_MINUTES;

    $stmt = $conn->prepare('UPDATE password_resets SET used_at = NOW() WHERE account_type = ? AND account_id = ? AND used_at IS NULL');
    $stmt->bind_param('si', $account['type'], $account['id']);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('INSERT INTO password_resets (account_type, account_id, email, code_hash, expires_at, ip) VALUES (?, ?, ?, ?, NOW() + INTERVAL ? MINUTE, ?)');
    $stmt->bind_param('sissis', $account['type'], $account['id'], $account['email'], $hash, $minutes, $ip);
    $stmt->execute();
    $resetId = (int) $stmt->insert_id;
    $stmt->close();

    try {
        password_reset_mail($account, $code);
    } catch (Throwable $error) {
        // Without the email the code is useless; remove it so it does not count against the limits.
        $stmt = $conn->prepare('DELETE FROM password_resets WHERE id = ?');
        $stmt->bind_param('i', $resetId);
        $stmt->execute();
        $stmt->close();
        throw $error;
    }
}

/** The newest code that can still be used, or null. */
function password_reset_active(mysqli $conn, array $account): ?array
{
    password_reset_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT *, TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_left FROM password_resets
        WHERE account_type = ? AND account_id = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('si', $account['type'], $account['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/**
 * Checks a code. Returns the reset id on success; throws with a message the user can act on.
 */
function password_reset_verify(mysqli $conn, ?array $account, string $code): int
{
    $code = preg_replace('/\D/', '', $code);
    $reset = $account ? password_reset_active($conn, $account) : null;
    if (!$reset) throw new RuntimeException('This code has expired or is no longer valid. Request a new code.');
    if ((int) $reset['attempts'] >= PASSWORD_RESET_ATTEMPTS) throw new RuntimeException('Too many wrong attempts. Request a new code.');
    if (strlen($code) !== 6 || !password_verify($code, (string) $reset['code_hash'])) {
        $stmt = $conn->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?');
        $stmt->bind_param('i', $reset['id']);
        $stmt->execute();
        $stmt->close();
        $left = PASSWORD_RESET_ATTEMPTS - (int) $reset['attempts'] - 1;
        throw new RuntimeException($left > 0 ? 'That code is incorrect. ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.' : 'Too many wrong attempts. Request a new code.');
    }
    $stmt = $conn->prepare('UPDATE password_resets SET verified_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $reset['id']);
    $stmt->execute();
    $stmt->close();
    return (int) $reset['id'];
}

/** Saves the new password for a verified code and closes every open code for the account. */
function password_reset_complete(mysqli $conn, int $resetId, string $password): array
{
    password_reset_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT * FROM password_resets WHERE id = ? AND verified_at IS NOT NULL AND used_at IS NULL AND expires_at > NOW()');
    $stmt->bind_param('i', $resetId);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$reset) throw new RuntimeException('This reset link has expired. Request a new code.');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $id = (int) $reset['account_id'];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare($reset['account_type'] === 'staff' ? 'UPDATE users SET password = ? WHERE id = ?' : 'UPDATE student_accounts SET password_hash = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $id);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare('UPDATE password_resets SET used_at = NOW() WHERE account_type = ? AND account_id = ? AND used_at IS NULL');
        $stmt->bind_param('si', $reset['account_type'], $id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return $reset;
}

function password_reset_mail(array $account, string $code): void
{
    $config = jpcs_mail_config();
    $body = password_reset_email_html($account['name'], $code, 'cid:jpcs-reset-logo');
    $text = "Hi {$account['name']},\n\nYour JPCS password reset code is: $code\n\nIt expires in " . PASSWORD_RESET_MINUTES . " minutes. If you did not ask to reset your password, ignore this email; your password stays the same.\n\nJPCS Malabon";

    // Local testing: write the email to a folder instead of sending it.
    $capture = getenv('JPCS_MAIL_CAPTURE_DIR');
    if ($capture) {
        if (!is_dir($capture)) mkdir($capture, 0775, true);
        file_put_contents(rtrim($capture, '/\\') . '/reset-' . date('Ymd-His') . '-' . $account['type'] . $account['id'] . '.txt', "To: {$account['email']}\n\n$text");
        return;
    }
    $password = (string) $config['app_password'];
    if ($password === '' || stripos($password, 'YOUR') !== false || stripos($password, 'REPLACE') !== false) {
        throw new RuntimeException('Email is not configured.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $config['address'];
    $mail->Password = $password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 30;
    $mail->setFrom($config['address'], 'JPCS Malabon');
    $mail->addAddress($account['email'], $account['name']);
    $mail->isHTML(true);
    $mail->Subject = 'Your JPCS password reset code: ' . $code;
    $mail->addEmbeddedImage(dirname(__DIR__) . '/assets/img/jpcs-logo-email.png', 'jpcs-reset-logo', 'jpcs-logo.png');
    $mail->Body = $body;
    $mail->AltBody = $text;
    $mail->send();
}

function password_reset_email_html(string $name, string $code, string $logoSrc): string
{
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $digits = implode('', array_map(static fn($d) => '<span style="display:inline-block;width:44px;height:56px;margin:0 3px;border-radius:12px;background:#fff4ea;border:1px solid #f7d9c2;color:#a9480b;font-size:30px;font-weight:800;line-height:56px;text-align:center;font-family:Consolas,Menlo,monospace">' . $h($d) . '</span>', str_split($code)));
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f6ede4;font-family:Segoe UI,Arial,Helvetica,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6ede4;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:22px;box-shadow:0 12px 34px rgba(150,90,45,.18)">'
        . '<tr><td style="background:#f2701f;background-image:linear-gradient(135deg,#ff8a3d,#e0610f);padding:22px 26px;border-radius:22px 22px 0 0">'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="58"><img src="' . $h($logoSrc) . '" width="52" height="52" alt="JPCS" style="display:block;border-radius:50%;background:#ffffff"></td>'
        . '<td style="padding-left:14px;color:#ffffff"><div style="font-size:12px;letter-spacing:.18em;text-transform:uppercase;opacity:.9">JPCS · City of Malabon University</div><div style="font-size:22px;font-weight:800">Password reset</div></td></tr></table></td></tr>'
        . '<tr><td style="padding:26px 26px 6px;color:#2f2621;font-size:15px;line-height:1.6">Hi <b>' . $h($name) . '</b>,<br>Use this code to reset your JPCS password:</td></tr>'
        . '<tr><td align="center" style="padding:14px 10px 6px;white-space:nowrap">' . $digits . '</td></tr>'
        . '<tr><td align="center" style="padding:6px 26px 22px;color:#8a7a6e;font-size:13px">The code expires in <b style="color:#2f2621">' . PASSWORD_RESET_MINUTES . ' minutes</b>.</td></tr>'
        . '<tr><td style="padding:0 26px 26px"><div style="padding:12px 14px;border-radius:14px;background:#faf5f0;color:#75675d;font-size:13px;line-height:1.6"><b style="color:#2f2621">Didn\'t ask for this?</b> Ignore this email — your password stays the same. Never share this code with anyone, including JPCS officers.</div></td></tr>'
        . '</table>'
        . '<div style="padding:18px 6px 0;color:#75675d;font-size:12px">JPCS Malabon · Junior Philippine Computer Society, City of Malabon University</div>'
        . '</td></tr></table></body></html>';
}
