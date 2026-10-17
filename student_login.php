<?php
require_once __DIR__ . '/includes/student_auth.php';

$error = '';
$identifier = '';
if (request_method_is('POST')) {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $stmt = $conn->prepare('SELECT * FROM student_accounts WHERE email = ? OR student_id = ? LIMIT 1');
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student || !password_verify($password, $student['password_hash'])) {
        $error = 'Invalid email/student ID or password.';
    } else {
        session_regenerate_id(true);
        $_SESSION['student_id'] = $student['id'];
        $_SESSION['student_name'] = $student['full_name'];
        $_SESSION['student_number'] = $student['student_id'];
        $_SESSION['student_email'] = $student['email'];
        redirect('index.php');
    }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login | JPCS</title>
  <link rel="stylesheet" href="css/theme.css">
  <script src="js/theme-init.js"></script>
  <link rel="stylesheet" href="assets/css/navbar.css">
  <style><?php include __DIR__ . '/assets/css/auth-page.css'; ?></style>
  <link rel="stylesheet" href="assets/css/auth-modern.css">
    <link rel="stylesheet" href="assets/css/system-ui.css">
    <script src="js/system-ui.js" defer></script>
</head>
<body class="auth-page">
  <?php require __DIR__ . '/includes/navbar.php'; ?>
  <main class="auth-layout">
    <section class="auth-intro">
      <img class="theme-logo-dark" src="assets/img/jpcs-logo.png" alt="JPCS logo">
      <img class="theme-logo-light" src="assets/img/jpcs-logo-light.png" alt="JPCS logo">
      <h1>Welcome to <span>JPCS</span></h1>
      <p>Sign in to explore officer information, register for events, and stay connected with the community.</p>
    </section>
    <section class="auth-card">
      <h1>Login</h1>
      <p>Use your email or student ID to continue.</p>
      <?php if ($error) echo '<div class="error" role="alert">'.e($error).'</div>'; ?>
      <?php if (isset($_GET['registered'])) echo '<div class="success">Account created. You can now log in.</div>'; ?>
      <form class="auth-form" method="post">
        <div class="auth-field full"><label for="identifier">Email or student ID</label><div class="input-wrap"><span class="field-icon" aria-hidden="true">&#128100;</span><input id="identifier" name="identifier" value="<?php echo e($identifier); ?>" placeholder="you@example.com or student ID" autocomplete="username" required autofocus></div></div>
        <div class="auth-field full"><label for="password">Password</label><div class="input-wrap password-wrap"><span class="field-icon" aria-hidden="true">&#128272;</span><input id="password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required><button class="password-toggle" type="button" aria-label="Show password" data-password-toggle="password">&#128065;</button></div></div>
        <div class="auth-meta"><label class="check"><input type="checkbox" name="remember"> Remember me</label><a href="mailto:jpcs@cmu.edu.ph?subject=JPCS%20password%20reset">Forgot password?</a></div>
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
    });
  </script>
</body>
</html>
