<?php
declare(strict_types=1);

require_once __DIR__ . '/student_auth.php';

$activePage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$student = current_student();
$studentName = $student['full_name'] ?? '';
$initial = strtoupper(substr($studentName !== '' ? $studentName : 'S', 0, 1));
?>
<link rel="stylesheet" href="css/theme.css">
<script src="js/theme-init.js"></script>
<script src="css/theme.js" defer></script>
<nav class="jpcs-navbar" id="jpcsNavbar" aria-label="Primary navigation">
  <a class="jpcs-brand" href="index.php">
    <img class="jpcs-logo-dark" src="assets/img/jpcs-logo.png" alt="JPCS logo">
    <img class="jpcs-logo-light" src="assets/img/jpcs-logo-light.png" alt="JPCS logo">
    <span>JPCS</span>
  </a>

  <button class="jpcs-menu-toggle" type="button" aria-label="Toggle menu" aria-expanded="false" onclick="toggleJpcsMenu()">&#9776;</button>

  <?php if ($student !== null) { ?>
    <div class="jpcs-nav-links">
      <a class="<?php echo $activePage === 'index.php' ? 'active' : ''; ?>" href="index.php">Home</a>
      <a class="<?php echo $activePage === 'pofficer.php' ? 'active' : ''; ?>" href="pofficer.php">Officer Information</a>
      <a class="<?php echo $activePage === 'registration.php' ? 'active' : ''; ?>" href="registration.php">Event Registration</a>
    </div>
    <div class="jpcs-nav-actions">
      <button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch theme" title="Switch theme"><span class="theme-icon" aria-hidden="true">&#9790;</span></button>
      <div class="jpcs-profile">
        <span class="jpcs-avatar" aria-hidden="true"><?php echo e($initial); ?></span>
        <span><?php echo e($studentName); ?></span>
      </div>
      <a class="jpcs-nav-button outline" href="student_logout.php">Logout</a>
    </div>
  <?php } else { ?>
    <div class="jpcs-nav-actions">
      <button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch theme" title="Switch theme"><span class="theme-icon" aria-hidden="true">&#9790;</span></button>
      <a class="jpcs-nav-button outline" href="login.php">Login</a>
      <a class="jpcs-nav-button solid" href="student_register.php">Register</a>
    </div>
  <?php } ?>
</nav>
<script>
function toggleJpcsMenu() {
  const navbar = document.getElementById('jpcsNavbar');
  const button = navbar.querySelector('.jpcs-menu-toggle');
  const open = navbar.classList.toggle('open');
  button.setAttribute('aria-expanded', open ? 'true' : 'false');
}
</script>
