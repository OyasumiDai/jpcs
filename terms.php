<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/terms.php';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Terms and Agreement | JPCS</title>
  <link rel="icon" type="image/png" href="assets/img/jpcs-logo.png">
  <script src="js/theme-init.js?v=<?= filemtime(__DIR__ . '/js/theme-init.js') ?>"></script>
  <link rel="stylesheet" href="css/theme.css?v=<?= filemtime(__DIR__ . '/css/theme.css') ?>">
  <link rel="stylesheet" href="assets/css/glass-theme.css?v=<?= filemtime(__DIR__ . '/assets/css/glass-theme.css') ?>">
  <script src="js/glass-theme.js?v=<?= filemtime(__DIR__ . '/js/glass-theme.js') ?>" defer></script>
  <link rel="stylesheet" href="assets/css/terms.css?v=<?= filemtime(__DIR__ . '/assets/css/terms.css') ?>">
</head>
<body class="public-page terms-page">
  <nav class="terms-page__top" aria-label="Page">
    <a href="student_register.php">← Back to sign up</a>
    <a href="index.php">JPCS home</a>
  </nav>
  <main class="terms-page__card">
    <ul class="terms-page__toc" aria-label="Sections">
      <?php $i = 0; foreach (jpcs_terms_sections() as $id => $title) { $i++; ?>
        <li><a href="#terms-<?= e($id) ?>"><?= $i ?>. <?= e($title) ?></a></li>
      <?php } ?>
    </ul>
    <?= jpcs_terms_html() ?>
  </main>
</body>
</html>
