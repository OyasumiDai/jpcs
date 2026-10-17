<?php
require_once __DIR__ . '/includes/student_auth.php';

$errors = [];
$values = ['full_name' => '', 'student_id' => '', 'email' => '', 'course_year' => ''];
$courseOptions = ['BSIT 1st Year', 'BSIT 2nd Year', 'BSIT 3rd Year', 'BSIT 4th Year'];
$termsAccepted = false;

if (request_method_is('POST')) {
    foreach ($values as $key => $_) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $termsAccepted = isset($_POST['terms']);

    if ($values['full_name'] === '' || $values['student_id'] === '' || $values['email'] === '' || $values['course_year'] === '') $errors[] = 'Complete all required fields.';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (!in_array($values['course_year'], $courseOptions, true)) $errors[] = 'Choose a valid course/year.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirmPassword) $errors[] = 'Passwords do not match.';
    if (!$termsAccepted) $errors[] = 'Please accept the terms and privacy notice.';

    if (!$errors) {
        $stmt = $conn->prepare('SELECT id FROM student_accounts WHERE student_id = ? OR email = ? LIMIT 1');
        $stmt->bind_param('ss', $values['student_id'], $values['email']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) $errors[] = 'That student ID or email is already registered.';
        $stmt->close();
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO student_accounts (full_name, student_id, email, course_year, password_hash) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $values['full_name'], $values['student_id'], $values['email'], $values['course_year'], $hash);
        $stmt->execute();
        $stmt->close();
        redirect('student_login.php?registered=1');
    }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register | JPCS</title>
  <link rel="stylesheet" href="css/theme.css">
  <script src="js/theme-init.js"></script>
  <link rel="stylesheet" href="assets/css/navbar.css">
  <style><?php include __DIR__ . '/assets/css/auth-page.css'; ?></style>
  <link rel="stylesheet" href="assets/css/auth-modern.css">
    <link rel="stylesheet" href="assets/css/system-ui.css">
    <script src="js/system-ui.js" defer></script>
    <link rel="stylesheet" href="assets/css/glass.css">
    <link rel="stylesheet" href="assets/css/light-clean.css">
    <script src="js/glass-ui.js" defer></script>
</head>
<body class="auth-page">
  <?php require __DIR__ . '/includes/navbar.php'; ?>
  <main class="auth-layout">
    <section class="auth-intro">
      <img class="theme-logo-dark" src="assets/img/jpcs-logo.png" alt="JPCS logo">
      <img class="theme-logo-light" src="assets/img/jpcs-logo-light.png" alt="JPCS logo">
      <h1>Join the <span>JPCS</span> community</h1>
      <p>Create your account to discover events, training opportunities, and a community that helps you grow.</p>
    </section>
    <section class="auth-card">
      <h1>Create your account</h1>
      <p>Register as a JPCS student member.</p>
      <?php foreach ($errors as $error) echo '<div class="error" role="alert">'.e($error).'</div>'; ?>
      <form class="auth-form" method="post" id="registerForm">
        <div class="auth-fields">
          <div class="auth-field full"><label for="full_name">Full name</label><div class="input-wrap"><span class="field-icon" aria-hidden="true">&#128100;</span><input id="full_name" name="full_name" value="<?php echo e($values['full_name']); ?>" placeholder="Your full name" autocomplete="name" required></div></div>
          <div class="auth-field"><label for="student_id">Student ID</label><input id="student_id" name="student_id" value="<?php echo e($values['student_id']); ?>" placeholder="e.g. 2024-001" required></div>
          <div class="auth-field"><label for="email">Email address</label><input id="email" type="email" name="email" value="<?php echo e($values['email']); ?>" placeholder="you@example.com" autocomplete="email" required></div>
          <div class="auth-field"><label for="course_year">Course / year</label><select id="course_year" name="course_year" required><option value="">Select course/year</option><?php foreach ($courseOptions as $option) echo '<option '.($values['course_year'] === $option ? 'selected' : '').'>'.e($option).'</option>'; ?></select></div>
          <div class="auth-field"><label for="password">Password</label><div class="input-wrap password-wrap"><input id="password" type="password" name="password" minlength="8" placeholder="At least 8 characters" autocomplete="new-password" required><button class="password-toggle" type="button" aria-label="Show password" data-password-toggle="password">&#128065;</button></div><div class="password-strength" aria-hidden="true"><span id="strengthBar"></span></div><small class="strength-text" id="strengthText">Use 8 or more characters.</small></div>
          <div class="auth-field"><label for="confirm_password">Confirm password</label><div class="input-wrap password-wrap"><input id="confirm_password" type="password" name="confirm_password" minlength="8" placeholder="Repeat your password" autocomplete="new-password" required><button class="password-toggle" type="button" aria-label="Show password" data-password-toggle="confirm_password">&#128065;</button></div></div>
        </div>
        <label class="check" for="terms"><input id="terms" type="checkbox" name="terms" <?php echo $termsAccepted ? 'checked' : ''; ?> required> I agree to the JPCS terms and privacy notice.</label>
        <button class="auth-submit" type="submit">Create account</button>
      </form>
      <p class="auth-link">Already have an account? <a href="login.php">Login</a></p>
      <a class="auth-back" href="index.php">Back to landing page</a>
    </section>
  </main>
  <script>
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
      button.addEventListener('click', function () {
        const input = document.getElementById(this.dataset.passwordToggle);
        const visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        this.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
      });
    });
    const password = document.getElementById('password');
    const bar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    password.addEventListener('input', function () {
      const value = this.value;
      let score = 0;
      if (value.length >= 8) score++;
      if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score++;
      if (/\d/.test(value) && /[^A-Za-z0-9]/.test(value)) score++;
      const labels = ['Use 8 or more characters.', 'Weak password', 'Good password', 'Strong password'];
      bar.style.width = (score * 33.33) + '%';
      bar.style.background = score < 2 ? '#ef4444' : score === 2 ? '#f59e0b' : '#22c55e';
      strengthText.textContent = labels[score];
    });
  </script>
</body>
</html>
