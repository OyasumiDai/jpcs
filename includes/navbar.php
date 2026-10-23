<?php
declare(strict_types=1);

require_once __DIR__ . '/student_auth.php';
require_once __DIR__ . '/auth.php';

$activePage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$student = current_student();
$panelUser = current_user();
$panelRole = (string)($panelUser['role'] ?? '');
$isPanelUser = !empty($panelUser['id']) && in_array($panelRole, ['officer', 'admin_advisor', 'admin_adviser'], true);
$studentName = $student['full_name'] ?? '';
$initial = strtoupper(substr($studentName !== '' ? $studentName : 'S', 0, 1));
$panelName = (string)($panelUser['name'] ?? '');
$panelInitial = strtoupper(substr($panelName !== '' ? $panelName : 'J', 0, 1));
$panelPhotoUrl = '';
if ($isPanelUser && isset($conn) && $conn instanceof mysqli) {
  $photoStatement = $conn->prepare('SELECT photo FROM users WHERE id=? LIMIT 1');
  if ($photoStatement) {
    $panelID = (int)$panelUser['id'];
    $photoStatement->bind_param('i', $panelID);
    $photoStatement->execute();
    $photoRecord = $photoStatement->get_result()->fetch_assoc() ?: [];
    $photoStatement->close();
    $storedPhoto = trim((string)($photoRecord['photo'] ?? ''));
    if ($storedPhoto !== '') {
      $panelPhotoUrl = image_url($storedPhoto);
    }
  }
}
$panelReturnLabel = in_array($panelRole, ['admin_advisor', 'admin_adviser'], true) ? 'Advisor Panel' : 'Officer Panel';
?>
<link rel="stylesheet" href="css/theme.css?v=<?= filemtime(dirname(__DIR__) . '/css/theme.css') ?>">
<script src="js/theme-init.js?v=<?= filemtime(dirname(__DIR__) . '/js/theme-init.js') ?>"></script>
<script src="css/theme.js?v=<?= filemtime(dirname(__DIR__) . '/css/theme.js') ?>" defer></script>
<nav class="jpcs-navbar" id="jpcsNavbar" aria-label="Primary navigation">
  <a class="jpcs-brand" href="index.php">
    <img class="jpcs-logo-dark" src="assets/img/jpcs-logo.png" alt="JPCS logo">
    <img class="jpcs-logo-light" src="assets/img/jpcs-logo-light.png" alt="JPCS logo">
    <span class="jpcs-brand-text">
      <span>JPCS</span>
      <span class="jpcs-brand-subtitle">City of Malabon University</span>
    </span>
  </a>

  <button class="jpcs-menu-toggle" type="button" aria-label="Toggle menu" aria-expanded="false" onclick="toggleJpcsMenu()">&#9776;</button>

  <?php if ($student !== null || $isPanelUser) { ?>
    <div class="jpcs-nav-links">
      <a class="<?php echo $activePage === 'index.php' ? 'active' : ''; ?>" href="index.php">Home</a>
      <a class="<?php echo $activePage === 'pofficer.php' ? 'active' : ''; ?>" href="pofficer.php">Officer Information</a>
      <a class="<?php echo $activePage === 'registration.php' ? 'active' : ''; ?>" href="registration.php">Event Registration</a>
    </div>
    <div class="jpcs-nav-actions">
      <button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch theme" title="Switch theme"><span class="theme-icon" aria-hidden="true">&#9790;</span></button>
      <?php if ($student !== null) { ?>
        <div class="jpcs-profile">
          <span class="jpcs-avatar" aria-hidden="true"><?php echo e($initial); ?></span>
          <span><?php echo e($studentName); ?></span>
        </div>
        <a class="jpcs-nav-button outline jpcs-logout-button" href="student_logout.php" aria-label="Logout" title="Logout">
          <svg class="jpcs-logout-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M10 4H5.5A1.5 1.5 0 0 0 4 5.5v13A1.5 1.5 0 0 0 5.5 20H10" />
            <path d="M14 8l4 4-4 4M8 12h10" />
          </svg>
        </a>
      <?php } else { ?>
        <div class="jpcs-profile">
          <span class="jpcs-avatar" aria-hidden="true">
            <?php if ($panelPhotoUrl !== '') { ?><img src="<?php echo e($panelPhotoUrl); ?>" alt=""><?php } else { echo e($panelInitial); } ?>
          </span>
          <span><?php echo e($panelName); ?></span>
        </div>
        <a class="jpcs-nav-button solid" href="dashboard.php"><?php echo e($panelReturnLabel); ?></a>
        <a class="jpcs-nav-button outline jpcs-logout-button" href="logout.php" aria-label="Logout" title="Logout">
          <svg class="jpcs-logout-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M10 4H5.5A1.5 1.5 0 0 0 4 5.5v13A1.5 1.5 0 0 0 5.5 20H10" />
            <path d="M14 8l4 4-4 4M8 12h10" />
          </svg>
        </a>
      <?php } ?>
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
