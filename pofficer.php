<?php
session_start();
include 'db.php';

// Get all officers and advisor/admin
$result = $conn->query("SELECT * FROM users WHERE role='officer' OR role='admin_advisor' ORDER BY id ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Officer Information</title>

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
  </style>
</head>
<body>
  <!-- Sidebar -->
  <div class="sidebar">
    <h2>JPCS</h2>
    <a href="index.php">Landing Page</a>
    <a href="pofficer.php">Officer Information</a>
    <a href="registration.php">Event Registration</a>
    <a href="login.php">Admin Login</a>
  </div>

  <!-- Content -->
  <div class="content">
    <header>
      <h1>Junior Philippine Computer Society</h1>
      <h2>City of Malabon University</h2>
    </header>

    <h2 class="page-title">Officer Information</h2>

    <div class="officer-grid">
      <?php while($row = $result->fetch_assoc()) {
        $photo = $row['photo'] ? $row['photo'] : 'assets/img/default.png';
      ?>
        <div class="officer-card">
          <img
            src="<?php echo htmlspecialchars($photo, ENT_QUOTES, 'UTF-8'); ?>"
            alt="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"
            onclick="openModal(this)">

          <h4><?php
            echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
          ?></h4>

          <p><?php
            echo htmlspecialchars($row['position'] ?? '', ENT_QUOTES, 'UTF-8');
          ?></p>
        </div>
      <?php } ?>
    </div>
  </div>

  <!-- Modal -->
  <div id="imageModal" class="modal">
    <span class="close" onclick="closeModal()">&times;</span>
    <img class="modal-content" id="modalImg" alt="Officer photo">

    <div class="modal-info" id="modalInfo"></div>

    <div class="modal-controls">
      <button type="button" onclick="prevImage()">Prev</button>
      <button type="button" onclick="nextImage()">Next</button>
    </div>
  </div>

  <script>
    let images = [];
    let names = [];
    let positions = [];
    let currentIndex = 0;

    function showCurrentImage() {
      if (images.length === 0) {
        return;
      }

      document.getElementById("modalImg").src =
        images[currentIndex];

      document.getElementById("modalImg").alt =
        names[currentIndex];

      document.getElementById("modalInfo").textContent =
        names[currentIndex] + " - " + positions[currentIndex];
    }

    function openModal(clickedImage) {
      const cards = Array.from(
        document.querySelectorAll(".officer-card img")
      );

      images = cards.map(img => img.src);

      names = cards.map(
        img => img.nextElementSibling.textContent.trim()
      );

      positions = cards.map(
        img => img.nextElementSibling.nextElementSibling
          .textContent.trim()
      );

      currentIndex = cards.indexOf(clickedImage);

      if (currentIndex < 0) {
        return;
      }

      showCurrentImage();
      document.getElementById("imageModal").style.display = "block";
    }

    function closeModal() {
      document.getElementById("imageModal").style.display = "none";
    }

    function nextImage() {
      if (images.length === 0) {
        return;
      }

      currentIndex = (currentIndex + 1) % images.length;
      showCurrentImage();
    }

    function prevImage() {
      if (images.length === 0) {
        return;
      }

      currentIndex =
        (currentIndex - 1 + images.length) % images.length;

      showCurrentImage();
    }
  </script>
</body>
</html>