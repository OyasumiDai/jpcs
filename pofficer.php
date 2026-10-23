<?php
require_once __DIR__ . '/includes/student_auth.php';
require_once __DIR__ . '/includes/auth.php';

$officerPageUser = current_user();
$officerPagePanelAccess = !empty($officerPageUser['id'])
  && in_array((string) ($officerPageUser['role'] ?? ''), ['officer', 'admin_advisor', 'admin_adviser'], true);
if (current_student() === null && !$officerPagePanelAccess) {
  redirect('student_login.php');
}

require_once __DIR__ . '/includes/public_officers.php';

// Adviser, president, then officers: shared with the landing page.
$officerTiers = jpcs_officer_tiers($conn);
$officerPeople = array_merge(...array_values($officerTiers));
$officerTierLabels = JPCS_OFFICER_TIER_LABELS;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Officer Information</title>
  <link rel="icon" type="image/png" href="assets/img/jpcs-logo.png">
  <link rel="stylesheet" href="css/theme.css?v=<?= filemtime(__DIR__ . '/css/theme.css') ?>">
  <script src="js/theme-init.js?v=<?= filemtime(__DIR__ . '/js/theme-init.js') ?>"></script>
  <link rel="stylesheet" href="assets/css/navbar.css?v=<?= filemtime(__DIR__ . '/assets/css/navbar.css') ?>">

  <style>
    body {
      margin: 0;
      font-family: "Segoe UI", Arial, sans-serif;
      display: flex;
      min-height: 100vh;
      color: #fff;
      background:
        radial-gradient(
          ellipse at top right,
          rgba(255, 125, 0, 0.18),
          transparent 45%
        ),
        radial-gradient(
          ellipse at bottom left,
          rgba(255, 125, 0, 0.12),
          transparent 45%
        ),
        #090909;
      background-attachment: fixed;
    }

    /* Sidebar */
    .sidebar {
      width: 220px;
      background:
        radial-gradient(
          ellipse at bottom left,
          rgba(255, 125, 0, 0.23),
          transparent 60%
        ),
        rgba(12, 12, 12, 0.94);
      color: #ff981f;
      height: 100vh;
      position: fixed;
      left: 0;
      top: 0;
      padding-top: 20px;
      overflow-y: auto;
      border-right: 1px solid rgba(255, 152, 31, 0.28);
      box-shadow: 6px 0 30px rgba(0, 0, 0, 0.3);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      z-index: 10;
    }

    .sidebar h2 {
      text-align: center;
      margin-bottom: 20px;
      color: #fff;
      font-size: 32px;
      font-weight: 800;
      letter-spacing: 2px;
      text-shadow: 0 0 18px rgba(255, 125, 0, 0.2);
    }

    .sidebar h2::after {
      content: "";
      display: block;
      width: 42px;
      height: 3px;
      margin: 12px auto 0;
      border-radius: 20px;
      background: #ff850a;
      box-shadow: 0 0 14px rgba(255, 133, 10, 0.65);
    }

    .sidebar a {
      display: block;
      color: #e5ded7;
      padding: 12px 20px;
      text-decoration: none;
      font-weight: 600;
      border-radius: 10px;
      margin: 6px 10px;
      line-height: 1.5;
      transition:
        background 0.2s,
        color 0.2s,
        box-shadow 0.2s;
    }

    .sidebar a[href="pofficer.php"] {
      background: linear-gradient(
        110deg,
        rgba(255, 133, 10, 0.35),
        rgba(255, 133, 10, 0.08)
      );
      color: #fff;
      box-shadow:
        inset 3px 0 0 #ff850a,
        inset 0 0 0 1px rgba(255, 133, 10, 0.45),
        0 0 20px rgba(255, 125, 0, 0.12);
    }

    .sidebar a:hover {
      background: rgba(255, 133, 10, 0.18);
      color: #fff;
      box-shadow: inset 0 0 0 1px rgba(255, 133, 10, 0.35);
    }

    /* Content */
    .content {
      margin-left: 220px;
      padding: 20px;
      flex: 1;
      min-width: 0;
    }

    header {
      background:
        radial-gradient(
          ellipse at top right,
          rgba(255, 133, 10, 0.24),
          transparent 65%
        ),
        rgba(22, 19, 16, 0.85);
      color: #fff;
      padding: 15px;
      text-align: center;
      border: 1px solid rgba(255, 166, 70, 0.3);
      border-radius: 16px;
      box-shadow:
        0 12px 35px rgba(0, 0, 0, 0.25),
        inset 0 1px 0 rgba(255, 255, 255, 0.08);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
    }

    header h1 {
      font-weight: 800;
      letter-spacing: -0.5px;
      line-height: 1.3;
    }

    header h2 {
      color: #d3cac1;
      font-weight: 400;
    }

    .page-title {
      text-align: center;
      color: #ffae55;
      margin-top: 20px;
      font-size: 28px;
      font-weight: 750;
    }

    .page-title::after {
      content: "";
      display: block;
      width: 50px;
      height: 3px;
      margin: 12px auto 0;
      border-radius: 20px;
      background: #ff850a;
      box-shadow: 0 0 15px rgba(255, 133, 10, 0.55);
    }

    /* Officer grid */
    .officer-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 30px;
      justify-content: center;
      padding: 30px;
    }

    .officer-card {
      background:
        radial-gradient(
          ellipse at bottom right,
          rgba(255, 133, 10, 0.18),
          transparent 65%
        ),
        rgba(25, 21, 18, 0.85);
      border: 1px solid rgba(255, 166, 70, 0.3);
      border-radius: 16px;
      width: 220px;
      text-align: center;
      box-shadow:
        0 10px 28px rgba(0, 0, 0, 0.3),
        inset 0 1px 0 rgba(255, 255, 255, 0.06);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      transition:
        transform 0.3s,
        border-color 0.3s,
        box-shadow 0.3s;
    }

    .officer-card:hover {
      transform: scale(1.05);
      border-color: rgba(255, 152, 31, 0.8);
      box-shadow:
        0 14px 35px rgba(0, 0, 0, 0.4),
        0 0 24px rgba(255, 125, 0, 0.2);
    }

    .officer-card img {
      width: 100%;
      height: 220px;
      object-fit: cover;
      border-top-left-radius: 15px;
      border-top-right-radius: 15px;
      cursor: pointer;
      display: block;
    }

    .officer-card h4 {
      margin: 10px 0 5px;
      padding: 0 12px;
      color: #ffae55;
      font-size: 17px;
      line-height: 1.5;
      overflow-wrap: anywhere;
    }

    .officer-card p {
      margin: 0 0 15px;
      padding: 0 12px;
      color: #eee7e0;
      font-size: 14px;
      font-weight: 600;
      line-height: 1.6;
      overflow-wrap: anywhere;
    }

    /* Photo viewer */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow-y: auto;
      background: rgba(0, 0, 0, 0.93);
      text-align: center;
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
    }

    .modal-content {
      margin-top: 50px;
      max-width: 80%;
      max-height: 70%;
      object-fit: contain;
      border: 1px solid rgba(255, 152, 31, 0.4);
      border-radius: 14px;
      box-shadow: 0 0 35px rgba(255, 125, 0, 0.15);
    }

    .close {
      position: absolute;
      top: 20px;
      right: 35px;
      color: #fff;
      font-size: 40px;
      font-weight: bold;
      cursor: pointer;
      line-height: 1;
    }

    .close:hover {
      color: #ffae55;
    }

    .modal-controls {
      margin-top: 15px;
      margin-bottom: 25px;
      color: #fff;
    }

    .modal-controls button {
      padding: 10px 20px;
      margin: 0 10px;
      font-family: inherit;
      font-weight: bold;
      color: #16100b;
      background: linear-gradient(135deg, #ffa52f, #ff7900);
      border: 1px solid rgba(255, 184, 107, 0.5);
      border-radius: 10px;
      cursor: pointer;
      box-shadow: 0 5px 18px rgba(255, 125, 0, 0.2);
    }

    .modal-controls button:hover {
      background: linear-gradient(135deg, #ffb64c, #ff921a);
      box-shadow: 0 5px 24px rgba(255, 125, 0, 0.35);
    }

    .modal-info {
      margin-top: 10px;
      padding: 0 20px;
      color: #ffae55;
      font-size: 18px;
      font-weight: bold;
      line-height: 1.6;
    }

    a:focus-visible,
    button:focus-visible {
      outline: 3px solid #ffae55;
      outline-offset: 4px;
    }

    @media (max-width: 700px) {
      body {
        display: block;
      }

      .sidebar {
        position: relative;
        width: 100%;
        height: auto;
        padding: 16px 0;
        border-right: 0;
        border-bottom: 1px solid rgba(255, 152, 31, 0.28);
      }

      .sidebar h2 {
        margin: 0 0 16px;
      }

      .sidebar a {
        display: inline-block;
        margin: 4px;
        padding: 10px 12px;
        font-size: 13px;
      }

      .content {
        margin-left: 0;
        padding: 16px;
      }

      header h1 {
        font-size: 25px;
      }

      header h2 {
        font-size: 17px;
      }

      .officer-grid {
        padding: 20px 0;
        gap: 24px;
      }

      .modal-content {
        margin-top: 70px;
        max-width: 90%;
        max-height: 65%;
      }

      .close {
        right: 20px;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .officer-card,
      .sidebar a {
        transition: none;
      }

      .officer-card:hover {
        transform: none;
      }
    }

    .sidebar { display: none !important; }
    .content { margin-left: 0 !important; padding: 100px 20px 20px !important; }
  </style>
    <link rel="stylesheet" href="assets/css/system-ui.css?v=<?= filemtime(__DIR__ . '/assets/css/system-ui.css') ?>">
    <script src="js/system-ui.js?v=<?= filemtime(__DIR__ . '/js/system-ui.js') ?>" defer></script>
    <link rel="stylesheet" href="assets/css/glass.css?v=<?= filemtime(__DIR__ . '/assets/css/glass.css') ?>">
    <link rel="stylesheet" href="assets/css/light-clean.css?v=<?= filemtime(__DIR__ . '/assets/css/light-clean.css') ?>">
    <link rel="stylesheet" href="assets/css/glass-theme.css?v=<?= filemtime(__DIR__ . '/assets/css/glass-theme.css') ?>">
    <link rel="stylesheet" href="assets/css/officer-directory.css?v=<?= filemtime(__DIR__ . '/assets/css/officer-directory.css') ?>">
    <script src="js/glass-theme.js?v=<?= filemtime(__DIR__ . '/js/glass-theme.js') ?>" defer></script>
    <script src="js/glass-ui.js?v=<?= filemtime(__DIR__ . '/js/glass-ui.js') ?>" defer></script>
</head>
<body class="app-page student-page officer-page">
  <?php require __DIR__ . '/includes/navbar.php'; ?>

  <div class="content od-page">
    <header>
      <h1>Junior Philippine Computer Society</h1>
      <h2>City of Malabon University</h2>
    </header>

    <section class="od-hero" aria-labelledby="od-title">
      <p class="od-eyebrow">Meet the team</p>
      <h2 class="od-title" id="od-title">Officer Information</h2>
      <p class="od-lead">The adviser and student officers leading JPCS this school year. Select a card to view the full photo.</p>
      <label class="od-search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
        <span class="visually-hidden">Search officers</span>
        <input type="search" placeholder="Search by name or position" data-od-search autocomplete="off">
      </label>
      <p class="od-count" data-od-count aria-live="polite"></p>
    </section>

    <div class="od-tree">
      <?php $cardIndex = 0; foreach ($officerTiers as $tier => $members) { if (!$members) continue; ?>
        <section class="od-tier is-<?= e($tier) ?>" data-od-tier aria-labelledby="od-tier-<?= e($tier) ?>">
          <h3 class="od-tier__label" id="od-tier-<?= e($tier) ?>"><span><?= e($officerTierLabels[$tier]) ?></span></h3>
          <div class="od-tier__grid">
            <?php foreach ($members as $person) { $index = $cardIndex++; ?>
              <article class="od-card is-<?= e($tier) ?>" data-od-card data-search="<?= e(mb_strtolower($person['name'] . ' ' . $person['position'])) ?>" style="--od-delay: <?= min($index, 12) * 60 ?>ms">
                <button type="button" class="od-card__open" data-od-open="<?= $index ?>" aria-label="View photo of <?= e($person['name']) ?>">
                  <span class="od-card__photo">
                    <?php if ($person['photo'] !== '') { ?>
                      <img src="<?= e(image_url($person['photo'])) ?>" alt="" decoding="async">
                    <?php } else { ?>
                      <span class="od-card__initials" aria-hidden="true"><?= e($person['initials']) ?></span>
                    <?php } ?>
                    <span class="od-card__zoom" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"></circle><path d="m20 20-4.2-4.2M11 8v6M8 11h6"></path></svg></span>
                  </span>
                  <span class="od-card__body">
                    <strong class="od-card__name"><?= e($person['name']) ?></strong>
                    <span class="od-card__position"><?= e($person['position'] !== '' ? $person['position'] : 'Member') ?></span>
                  </span>
                </button>
              </article>
            <?php } ?>
          </div>
        </section>
      <?php } ?>
    </div>
    <div class="od-empty" data-od-empty hidden>
      <strong>No officers match your search.</strong>
      <button type="button" class="od-chip" data-od-reset>Clear search</button>
    </div>
  </div>

  <div class="od-viewer" data-od-viewer role="dialog" aria-modal="true" aria-labelledby="od-viewer-name" hidden>
    <div class="od-viewer__backdrop" data-od-close></div>
    <figure class="od-viewer__panel">
      <button type="button" class="od-viewer__close" data-od-close aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"></path></svg></button>
      <div class="od-viewer__stage">
        <button type="button" class="od-viewer__nav is-prev" data-od-prev aria-label="Previous officer"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"></path></svg></button>
        <img data-od-image alt="">
        <span class="od-viewer__initials" data-od-initials hidden></span>
        <button type="button" class="od-viewer__nav is-next" data-od-next aria-label="Next officer"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"></path></svg></button>
      </div>
      <figcaption class="od-viewer__caption">
        <span class="od-card__role" data-od-role></span>
        <strong id="od-viewer-name" data-od-name></strong>
        <span data-od-position></span>
        <small data-od-counter></small>
      </figcaption>
    </figure>
  </div>

  <script type="application/json" id="od-data"><?= json_encode(array_map(static fn($p) => ['name' => $p['name'], 'position' => $p['position'] !== '' ? $p['position'] : 'Member', 'group' => $p['tier'], 'photo' => $p['photo'] !== '' ? image_url($p['photo']) : '', 'initials' => $p['initials']], $officerPeople), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
  <script src="js/officer-directory.js?v=<?= filemtime(__DIR__ . '/js/officer-directory.js') ?>" defer></script>
</body>
</html>
