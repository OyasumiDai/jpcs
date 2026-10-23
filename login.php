<?php
require_once __DIR__ . '/includes/student_auth.php';
require_once __DIR__ . '/includes/login_auth.php';

$error = '';
$username = '';
if (request_method_is('POST')) {
    // Accept identifier from older student forms as well as the shared form.
    $username = trim((string) ($_POST['username'] ?? $_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $account = find_login_account($conn, $username, $password);
    if ($account === null) {
        $error = 'Invalid login details. Check your email, student ID or username and password.';
    } else {
        // Retain the existing one-time upgrade of legacy staff passwords.
        if ($account['legacy']) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $id = $account['record']['id'];
            $update = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
            $update->bind_param('si', $newHash, $id);
            $update->execute();
            $update->close();
        }
        redirect(set_login_session($account));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - JPCS</title>
    <link rel="icon" type="image/png" href="assets/img/jpcs-logo.png">
    <link rel="stylesheet" href="css/theme.css?v=<?= filemtime(__DIR__ . '/css/theme.css') ?>">
    <script src="js/theme-init.js?v=<?= filemtime(__DIR__ . '/js/theme-init.js') ?>"></script>
    <script src="css/theme.js?v=<?= filemtime(__DIR__ . '/css/theme.js') ?>" defer></script>
    <link rel="stylesheet" href="assets/css/navbar.css?v=<?= filemtime(__DIR__ . '/assets/css/navbar.css') ?>">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 110px 20px 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: "Segoe UI", Arial, sans-serif;
            color: #fff;
            color-scheme: dark;
            background:
                radial-gradient(
                    ellipse at 80% 10%,
                    rgba(255, 125, 0, 0.22),
                    transparent 45%
                ),
                radial-gradient(
                    ellipse at 10% 90%,
                    rgba(255, 125, 0, 0.18),
                    transparent 45%
                ),
                #090909;
        }

        .login-container {
            position: relative;
            isolation: isolate;
            width: 100%;
            max-width: 430px;
            padding: 38px 32px;
            overflow: hidden;
            text-align: center;
            border: 1px solid rgba(255, 166, 70, 0.4);
            border-radius: 22px;
            background:
                radial-gradient(
                    ellipse at top right,
                    rgba(255, 133, 10, 0.17),
                    transparent 60%
                ),
                rgba(23, 20, 17, 0.88);
            box-shadow:
                0 24px 65px rgba(0, 0, 0, 0.5),
                0 0 35px rgba(255, 125, 0, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .login-container::before {
            content: "";
            position: absolute;
            z-index: -1;
            pointer-events: none;
            width: 260px;
            height: 95px;
            right: -110px;
            top: -30px;
            border-radius: 50%;
            transform: rotate(-30deg);
            border: 1px solid rgba(255, 166, 70, 0.4);
            background: linear-gradient(
                150deg,
                transparent,
                rgba(255, 133, 10, 0.24)
            );
            box-shadow: 0 10px 35px rgba(255, 125, 0, 0.13);
        }

        .brand {
            margin: 0;
            color: #fff;
            font-size: 36px;
            font-weight: 800;
            letter-spacing: 3px;
        }

        .brand span {
            color: #ff981f;
        }

        .organization {
            margin: 10px 0 25px;
            color: #c9bfb5;
            font-size: 12px;
            line-height: 1.7;
        }

        .divider {
            width: 48px;
            height: 3px;
            margin: 0 auto 25px;
            border-radius: 20px;
            background: #ff850a;
            box-shadow: 0 0 15px rgba(255, 133, 10, 0.6);
        }

        h2 {
            margin: 0 0 25px;
            color: #fff;
            font-size: 27px;
            font-weight: 750;
        }

        form {
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #eee7e0;
            font-size: 14px;
            font-weight: 600;
        }

        .field {
            margin-bottom: 20px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            min-height: 48px;
            padding: 12px 14px;
            font-family: inherit;
            font-size: 16px;
            color: #fff;
            background: rgba(8, 8, 8, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 11px;
        }

        input::placeholder {
            color: #a69b91;
        }

        input:focus {
            outline: 2px solid #ff981f;
            outline-offset: 2px;
            border-color: #ff981f;
            box-shadow: 0 0 18px rgba(255, 133, 10, 0.12);
        }

        button {
            width: 100%;
            min-height: 48px;
            margin-top: 5px;
            padding: 12px;
            font-family: inherit;
            font-size: 16px;
            font-weight: 750;
            color: #17100a;
            background: linear-gradient(135deg, #ffa52f, #ff7900);
            border: 1px solid rgba(255, 184, 107, 0.5);
            border-radius: 11px;
            cursor: pointer;
            box-shadow: 0 6px 22px rgba(255, 115, 0, 0.24);
        }

        button:hover {
            background: linear-gradient(135deg, #ffb64c, #ff921a);
            box-shadow: 0 6px 30px rgba(255, 133, 10, 0.4);
        }

        .error {
            margin: 20px 0 0;
            padding: 12px;
            color: #fff;
            background: rgba(255, 133, 10, 0.1);
            border: 1px solid #ffae55;
            border-radius: 10px;
            font-size: 14px;
            line-height: 1.6;
        }

        .back-link {
            margin: 25px 0 0;
            color: #c9bfb5;
            font-size: 14px;
        }

        a {
            color: #ffae55;
            text-decoration: none;
            font-weight: 600;
        }

        a:hover {
            color: #ffd1a0;
            text-decoration: underline;
        }

        a:focus-visible,
        button:focus-visible {
            outline: 3px solid #ffae55;
            outline-offset: 4px;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 30px 24px;
            }

            .brand {
                font-size: 32px;
            }
        }
    </style>
    <link rel="stylesheet" href="assets/css/auth-modern.css?v=<?= filemtime(__DIR__ . '/assets/css/auth-modern.css') ?>">
    <link rel="stylesheet" href="assets/css/system-ui.css?v=<?= filemtime(__DIR__ . '/assets/css/system-ui.css') ?>">
    <script src="js/system-ui.js?v=<?= filemtime(__DIR__ . '/js/system-ui.js') ?>" defer></script>
    <link rel="stylesheet" href="assets/css/glass.css?v=<?= filemtime(__DIR__ . '/assets/css/glass.css') ?>">
    <link rel="stylesheet" href="assets/css/light-clean.css?v=<?= filemtime(__DIR__ . '/assets/css/light-clean.css') ?>">
    <link rel="stylesheet" href="assets/css/glass-theme.css?v=<?= filemtime(__DIR__ . '/assets/css/glass-theme.css') ?>">
    <script src="js/glass-theme.js?v=<?= filemtime(__DIR__ . '/js/glass-theme.js') ?>" defer></script>
    <link rel="stylesheet" href="assets/css/auth-compact.css?v=<?= filemtime(__DIR__ . '/assets/css/auth-compact.css') ?>">
    <script src="js/glass-ui.js?v=<?= filemtime(__DIR__ . '/js/glass-ui.js') ?>" defer></script>
</head>
<body class="auth-page auth-compact">
    <main class="auth-layout">
        <button class="auth-theme-toggle theme-toggle" type="button" data-theme-toggle aria-label="Switch theme" title="Switch theme"><span class="theme-icon" aria-hidden="true"></span></button>
        <section class="auth-intro">
            <img class="theme-logo-dark" src="assets/img/jpcs-logo.png" alt="JPCS logo">
            <img class="theme-logo-light" src="assets/img/jpcs-logo-light.png" alt="JPCS logo">
            <h1>Welcome back to <span>JPCS</span></h1>
            <p>Stay connected with the Junior Philippine Computer Society and continue building the future through technology.</p>
        </section>
        <section class="auth-card login-container">
            <h1>Login</h1>
            <p>Students, officers and advisers can sign in here.</p>
            <?php if (isset($_GET['registered'])) { ?><p class="success" role="status">Account created. You can now log in.</p><?php } ?>
            <?php if ($error !== '') { ?><p class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php } ?>
            <form class="auth-form" method="POST">
                <div class="auth-field full">
                    <label for="username">Email, student ID or username</label>
                    <div class="input-wrap"><span class="field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c.8-3.3 3.2-5 7-5s6.2 1.7 7 5"></path></svg></span><input type="text" id="username" name="username" placeholder="Email, student ID or username" autocomplete="username" value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>" required></div>
                </div>
                <div class="auth-field full">
                    <label for="password">Password</label>
                    <div class="input-wrap password-wrap"><span class="field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></span><input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required><button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" title="Show password" data-password-toggle="password"><svg viewBox="0 0 24 24" focusable="false"><path d="M3 12s3.2-5 9-5 9 5 9 5-3.2 5-9 5-9-5-9-5Z"></path><circle cx="12" cy="12" r="2.2"></circle></svg></button></div>
                </div>
                <div class="auth-meta"><label class="check"><input type="checkbox" name="remember" id="remember"> Remember me</label><a href="mailto:jpcs@cmu.edu.ph?subject=JPCS%20password%20reset">Forgot password?</a></div>
                <button class="auth-submit" type="submit">Login</button>
            </form>
            <p class="auth-link">Don't have an account? <a href="student_register.php">Register</a></p>
            <a class="auth-back" href="index.php">Back to landing page</a>
        </section>
    </main>
    <script>
      document.querySelector('[data-password-toggle]')?.addEventListener('click', function () {
        const input = document.getElementById(this.dataset.passwordToggle);
        const visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        this.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
        this.setAttribute('aria-pressed', visible ? 'false' : 'true');
        this.setAttribute('title', visible ? 'Show password' : 'Hide password');
        this.innerHTML = visible
          ? '<svg viewBox="0 0 24 24" focusable="false"><path d="M3 12s3.2-5 9-5 9 5 9 5-3.2 5-9 5-9-5-9-5Z"></path><circle cx="12" cy="12" r="2.2"></circle></svg>'
          : '<svg viewBox="0 0 24 24" focusable="false"><path d="M3 12s3.2-5 9-5 9 5 9 5-3.2 5-9 5-9-5-9-5Z"></path><circle cx="12" cy="12" r="2.2"></circle><path d="M4 4l16 16"></path></svg>';
      });
    </script>
</body>
</html>
