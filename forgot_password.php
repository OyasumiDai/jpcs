<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/password_reset.php';
require_once __DIR__ . '/includes/login_auth.php';

password_reset_ensure_schema($conn);
$_SESSION['reset_csrf'] ??= bin2hex(random_bytes(32));

if (isset($_GET['restart'])) {
    unset($_SESSION['reset_identifier'], $_SESSION['reset_verified']);
    redirect('forgot_password.php');
}

$identifier = (string) ($_SESSION['reset_identifier'] ?? '');
$account = $identifier !== '' ? password_reset_find_account($conn, $identifier) : null;
$step = (string) ($_GET['step'] ?? 'request');
if ($step === 'code' && $identifier === '') $step = 'request';
if ($step === 'password' && empty($_SESSION['reset_verified'])) $step = $identifier !== '' ? 'code' : 'request';
if (!in_array($step, ['request', 'code', 'password'], true)) $step = 'request';

$error = '';
$notice = $_SESSION['reset_notice'] ?? '';
unset($_SESSION['reset_notice']);

/** Sends a code for the identifier, or explains why it could not. Returns an error message or ''. */
$sendCode = static function (string $value) use ($conn): string {
    // Failed lookups share the per-IP limit with failed sign-ins, so the form cannot be used to test long lists of emails.
    $ipLock = login_throttle_status($conn, '')['locked'];
    if ($ipLock > 0) return 'Too many attempts from this device. Try again in ' . login_throttle_wait_text($ipLock) . '.';
    $target = password_reset_find_account($conn, $value);
    if (!$target) {
        login_throttle_fail($conn, 'password-reset-lookup');
        unset($_SESSION['reset_identifier'], $_SESSION['reset_verified']);
        return 'That account does not exist. Check the email or student ID and try again.';
    }
    $_SESSION['reset_identifier'] = $value;
    unset($_SESSION['reset_verified']);
    $wait = password_reset_wait($conn, $target);
    if ($wait > 0) {
        if (password_reset_active($conn, $target)) {
            $_SESSION['reset_notice'] = 'A code was already sent. Check your inbox and spam folder, or request another in ' . ($wait > 90 ? ceil($wait / 60) . ' minutes' : $wait . ' seconds') . '.';
            return '';
        }
        if ($wait <= PASSWORD_RESET_COOLDOWN) return 'Please wait ' . $wait . ' seconds before requesting another code.';
        $minutes = max(1, (int) ceil($wait / 60));
        return 'Too many codes were requested. Try again in ' . $minutes . ($minutes === 1 ? ' minute.' : ' minutes.');
    }
    try {
        password_reset_send($conn, $target);
    } catch (Throwable $exception) {
        error_log('JPCS password reset mail: ' . $exception->getMessage());
        return 'We could not send the email right now. Please try again in a few minutes.';
    }
    return '';
};

if (request_method_is('POST')) {
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals($_SESSION['reset_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'send') {
        $value = trim((string) ($_POST['identifier'] ?? ''));
        if ($value === '') {
            $error = 'Enter your email or student ID.';
        } else {
            $error = $sendCode(mb_substr($value, 0, 150));
            if ($error === '') redirect('forgot_password.php?step=code');
            $identifier = $value;
        }
    } elseif ($action === 'resend' && $identifier !== '') {
        $error = $sendCode($identifier);
        if ($error === '') {
            $_SESSION['reset_notice'] ??= 'A new code is on its way. Earlier codes no longer work.';
            redirect('forgot_password.php?step=code');
        }
        $step = 'code';
    } elseif ($action === 'verify' && $identifier !== '') {
        try {
            $_SESSION['reset_verified'] = password_reset_verify($conn, $account, (string) ($_POST['code'] ?? ''));
            session_regenerate_id(true);
            redirect('forgot_password.php?step=password');
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
            $step = 'code';
        }
    } elseif ($action === 'reset' && !empty($_SESSION['reset_verified'])) {
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 8) {
            $error = 'Use at least 8 characters.';
        } elseif (strlen($password) > 72) {
            $error = 'Use 72 characters or fewer.';
        } elseif ($password !== (string) ($_POST['confirm_password'] ?? '')) {
            $error = 'The passwords do not match.';
        } else {
            try {
                $reset = password_reset_complete($conn, (int) $_SESSION['reset_verified'], $password);
                $isStaff = $reset['account_type'] === 'staff';
                $who = $account ?? ['name' => $reset['email']];
                audit_log($conn, 'auth.password_reset', $who['name'] . ' reset their password with an email code', $isStaff ? 'user' : 'student_account', (int) $reset['account_id'], [], ['id' => $isStaff ? (int) $reset['account_id'] : null, 'name' => $who['name'], 'role' => $isStaff ? '' : 'student']);
                // A new password lifts any sign-in lock caused by the forgotten one.
                login_throttle_clear($conn, array_merge([$identifier, $reset['email']], $account['logins'] ?? []));
                unset($_SESSION['reset_identifier'], $_SESSION['reset_verified']);
                redirect('login.php?reset=1');
            } catch (RuntimeException $exception) {
                unset($_SESSION['reset_verified']);
                $error = $exception->getMessage();
                $step = 'code';
            }
        }
        if ($error !== '' && $step !== 'code') $step = 'password';
    } else {
        $step = 'request';
    }
}

$destination = $account ? password_reset_mask_email($account['email']) : '';
$active = $account && $step === 'code' ? password_reset_active($conn, $account) : null;
$resendWait = $account && $step === 'code' ? min(PASSWORD_RESET_COOLDOWN, password_reset_wait($conn, $account)) : 0;
$steps = ['request' => 'Account', 'code' => 'Code', 'password' => 'New password'];
$stepIndex = array_search($step, array_keys($steps), true);
$h = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password - JPCS</title>
    <link rel="icon" type="image/png" href="assets/img/jpcs-logo.png">
    <link rel="stylesheet" href="css/theme.css?v=<?= filemtime(__DIR__ . '/css/theme.css') ?>">
    <script src="js/theme-init.js?v=<?= filemtime(__DIR__ . '/js/theme-init.js') ?>"></script>
    <script src="css/theme.js?v=<?= filemtime(__DIR__ . '/css/theme.js') ?>" defer></script>
    <link rel="stylesheet" href="assets/css/auth-modern.css?v=<?= filemtime(__DIR__ . '/assets/css/auth-modern.css') ?>">
    <link rel="stylesheet" href="assets/css/system-ui.css?v=<?= filemtime(__DIR__ . '/assets/css/system-ui.css') ?>">
    <script src="js/system-ui.js?v=<?= filemtime(__DIR__ . '/js/system-ui.js') ?>" defer></script>
    <link rel="stylesheet" href="assets/css/glass.css?v=<?= filemtime(__DIR__ . '/assets/css/glass.css') ?>">
    <link rel="stylesheet" href="assets/css/light-clean.css?v=<?= filemtime(__DIR__ . '/assets/css/light-clean.css') ?>">
    <link rel="stylesheet" href="assets/css/glass-theme.css?v=<?= filemtime(__DIR__ . '/assets/css/glass-theme.css') ?>">
    <script src="js/glass-theme.js?v=<?= filemtime(__DIR__ . '/js/glass-theme.js') ?>" defer></script>
    <link rel="stylesheet" href="assets/css/auth-compact.css?v=<?= filemtime(__DIR__ . '/assets/css/auth-compact.css') ?>">
    <link rel="stylesheet" href="assets/css/forgot-password.css?v=<?= filemtime(__DIR__ . '/assets/css/forgot-password.css') ?>">
    <script src="js/glass-ui.js?v=<?= filemtime(__DIR__ . '/js/glass-ui.js') ?>" defer></script>
</head>
<body class="auth-page auth-compact forgot-page">
    <main class="auth-layout">
        <button class="auth-theme-toggle theme-toggle" type="button" data-theme-toggle aria-label="Switch theme" title="Switch theme"><span class="theme-icon" aria-hidden="true"></span></button>
        <section class="auth-intro">
            <img class="theme-logo-dark" src="assets/img/jpcs-logo.png" alt="JPCS logo">
            <img class="theme-logo-light" src="assets/img/jpcs-logo-light.png" alt="JPCS logo">
            <h1>Forgot your <span>password?</span></h1>
            <p>No problem. We'll email you a 6-digit code so you can choose a new password and get back to JPCS.</p>
        </section>
        <section class="auth-card login-container">
            <ol class="fp-steps" aria-label="Steps">
                <?php $i = 0; foreach ($steps as $key => $label) { ?>
                    <li class="<?= $i < $stepIndex ? 'is-done' : ($i === $stepIndex ? 'is-current' : '') ?>"<?= $i === $stepIndex ? ' aria-current="step"' : '' ?>><span><?= $i < $stepIndex ? '✓' : $i + 1 ?></span><?= $h($label) ?></li>
                <?php $i++; } ?>
            </ol>

            <?php if ($step === 'request') { ?>
                <h1>Reset password</h1>
                <p>Enter the email or student ID you use to log in.</p>
            <?php } elseif ($step === 'code') { ?>
                <h1>Check your email</h1>
                <p><?php if ($destination !== '') { ?>We sent a 6-digit code to <strong><?= $h($destination) ?></strong>.<?php } else { ?>If an account matches <strong><?= $h($identifier) ?></strong>, we sent a 6-digit code to its email.<?php } ?> It expires in <?= PASSWORD_RESET_MINUTES ?> minutes.</p>
            <?php } else { ?>
                <h1>Choose a new password</h1>
                <p>Use at least 8 characters. You'll use it the next time you log in.</p>
            <?php } ?>

            <?php if ($notice !== '') { ?><p class="success" role="status"><?= $h($notice) ?></p><?php } ?>
            <?php if ($error !== '') { ?><p class="error" role="alert"><?= $h($error) ?></p><?php } ?>

            <?php if ($step === 'request') { ?>
                <form class="auth-form" method="POST" data-fp-form>
                    <input type="hidden" name="csrf" value="<?= $h($_SESSION['reset_csrf']) ?>">
                    <input type="hidden" name="action" value="send">
                    <div class="auth-field full">
                        <label for="identifier">Email or student ID</label>
                        <div class="input-wrap"><span class="field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m4 7 8 6 8-6"></path></svg></span><input type="text" id="identifier" name="identifier" placeholder="you@example.com or 2024xxxxx" autocomplete="username" value="<?= $h($identifier) ?>" maxlength="150" required autofocus></div>
                    </div>
                    <button class="auth-submit" type="submit">Send code</button>
                </form>
            <?php } elseif ($step === 'code') { ?>
                <form class="auth-form" method="POST" data-fp-form>
                    <input type="hidden" name="csrf" value="<?= $h($_SESSION['reset_csrf']) ?>">
                    <input type="hidden" name="action" value="verify">
                    <div class="auth-field full">
                        <label for="code">6-digit code</label>
                        <input class="fp-code" type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" placeholder="••••••" required autofocus data-fp-code>
                    </div>
                    <button class="auth-submit" type="submit">Verify code</button>
                </form>
                <form method="POST" class="fp-resend">
                    <input type="hidden" name="csrf" value="<?= $h($_SESSION['reset_csrf']) ?>">
                    <input type="hidden" name="action" value="resend">
                    <span>Didn't get it? Check your spam folder, or</span>
                    <button type="submit" class="fp-link" data-fp-resend data-wait="<?= (int) $resendWait ?>">send a new code</button>
                </form>
                <?php if ($active) { ?><p class="fp-expiry" data-fp-expiry="<?= (int) $active['seconds_left'] ?>">Code expires in <b>—</b></p><?php } ?>
            <?php } else { ?>
                <form class="auth-form" method="POST" data-fp-form>
                    <input type="hidden" name="csrf" value="<?= $h($_SESSION['reset_csrf']) ?>">
                    <input type="hidden" name="action" value="reset">
                    <?php if ($account) { ?><input type="text" name="username" value="<?= $h($account['email']) ?>" autocomplete="username" hidden><?php } ?>
                    <div class="auth-field full">
                        <label for="password">New password</label>
                        <div class="input-wrap password-wrap"><span class="field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></span><input type="password" id="password" name="password" minlength="8" maxlength="72" placeholder="At least 8 characters" autocomplete="new-password" required autofocus data-fp-password><button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" title="Show password" data-password-toggle="password"><svg viewBox="0 0 24 24" focusable="false"><path d="M3 12s3.2-5 9-5 9 5 9 5-3.2 5-9 5-9-5-9-5Z"></path><circle cx="12" cy="12" r="2.2"></circle></svg></button></div>
                        <div class="fp-strength" aria-hidden="true"><i data-fp-bar></i></div>
                        <small class="fp-hint" data-fp-hint>Use 8 or more characters. Mixing letters, numbers and symbols makes it stronger.</small>
                    </div>
                    <div class="auth-field full">
                        <label for="confirm_password">Confirm new password</label>
                        <div class="input-wrap password-wrap"><span class="field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></span><input type="password" id="confirm_password" name="confirm_password" minlength="8" maxlength="72" placeholder="Repeat the password" autocomplete="new-password" required data-fp-confirm><button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" title="Show password" data-password-toggle="confirm_password"><svg viewBox="0 0 24 24" focusable="false"><path d="M3 12s3.2-5 9-5 9 5 9 5-3.2 5-9 5-9-5-9-5Z"></path><circle cx="12" cy="12" r="2.2"></circle></svg></button></div>
                        <small class="fp-hint" data-fp-match aria-live="polite"></small>
                    </div>
                    <button class="auth-submit" type="submit">Save new password</button>
                </form>
            <?php } ?>

            <p class="auth-link"><?php if ($step !== 'request') { ?><a href="forgot_password.php?restart=1">Use a different account</a> · <?php } ?>Remembered it? <a href="login.php">Log in</a></p>
            <a class="auth-back" href="index.php">Back to landing page</a>
        </section>
    </main>
    <script src="js/forgot-password.js?v=<?= filemtime(__DIR__ . '/js/forgot-password.js') ?>" defer></script>
</body>
</html>
