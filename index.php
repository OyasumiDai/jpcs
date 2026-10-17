<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/student_auth.php';

$student = current_student();

// Fetch highlights (done events, limit 3 for landing page)
$highlights = $conn->query("SELECT * FROM events WHERE status='done' ORDER BY event_date DESC LIMIT 3");

// Fetch upcoming events for calendar
$upcoming = $conn->query("SELECT * FROM events WHERE status='upcoming' ORDER BY event_date ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>JPCS Event System - Landing Page</title>
  <link rel="icon" type="image/png" href="assets/img/jpcs-logo.png">
  <link rel="stylesheet" href="css/theme.css">
  <script src="js/theme-init.js"></script>
  <link rel="stylesheet" href="assets/css/navbar.css">
  <style>
  :root {
    --orange: #ff850a;
    --orange-light: #ffae55;
    --white: #fff;
    --muted: #c7c2bd;
    --border: rgba(255, 174, 85, 0.25);
    --glass: rgba(24, 20, 17, 0.78);
  }

  * {
    box-sizing: border-box;
  }

  html {
    scroll-behavior: smooth;
  }

  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    font-family: "Segoe UI", Arial, sans-serif;
    color: var(--white);
    background:
      radial-gradient(
        ellipse at 85% 0%,
        rgba(255, 115, 0, 0.19),
        transparent 38%
      ),
      radial-gradient(
        ellipse at 10% 90%,
        rgba(255, 115, 0, 0.12),
        transparent 38%
      ),
      #090909;
    background-attachment: fixed;
  }

  a,
  button {
    -webkit-tap-highlight-color: transparent;
  }

  a:focus-visible,
  button:focus-visible {
    outline: 3px solid var(--orange-light);
    outline-offset: 5px;
  }

  /* Sidebar */
  .sidebar {
    width: 240px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    z-index: 20;
    padding: 34px 18px;
    overflow-y: auto;
    color: var(--white);
    background:
      radial-gradient(
        ellipse at bottom left,
        rgba(255, 115, 0, 0.22),
        transparent 55%
      ),
      rgba(12, 12, 12, 0.92);
    border-right: 1px solid var(--border);
    box-shadow: 8px 0 40px rgba(0, 0, 0, 0.22);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
  }

  .sidebar h2 {
    margin: 0 0 38px;
    padding: 0 16px 24px;
    text-align: left;
    font-size: 36px;
    font-weight: 800;
    letter-spacing: 2px;
    color: var(--white);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  }

  .sidebar h2::after {
    content: "";
    display: block;
    width: 42px;
    height: 3px;
    margin-top: 10px;
    border-radius: 10px;
    background: var(--orange);
    box-shadow: 0 0 15px rgba(255, 133, 10, 0.65);
  }

  .sidebar a {
    display: block;
    margin-bottom: 12px;
    padding: 15px 17px;
    color: #ddd8d3;
    border: 1px solid transparent;
    border-radius: 13px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.5;
    transition:
      background 0.2s ease,
      border-color 0.2s ease,
      box-shadow 0.2s ease,
      color 0.2s ease;
  }

  .sidebar a[href="index.php"] {
    color: var(--white);
    background: linear-gradient(
      110deg,
      rgba(255, 133, 10, 0.32),
      rgba(255, 133, 10, 0.08)
    );
    border-color: rgba(255, 133, 10, 0.65);
    box-shadow:
      inset 3px 0 0 var(--orange),
      0 0 22px rgba(255, 115, 0, 0.13);
  }

  .sidebar a:hover {
    color: var(--white);
    background: rgba(255, 133, 10, 0.14);
    border-color: rgba(255, 133, 10, 0.4);
    box-shadow: 0 0 20px rgba(255, 115, 0, 0.1);
  }

  /* Main content */
  .content {
    flex: 1;
    min-width: 0;
    margin-left: 0;
    padding: 105px 30px 30px;
  }

  header,
  .mv-card,
  .event-card,
  .content > .section:first-of-type,
  .content > .section:last-of-type {
    position: relative;
    overflow: hidden;
    background: var(--glass);
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow:
      0 15px 40px rgba(0, 0, 0, 0.25),
      inset 0 1px 0 rgba(255, 255, 255, 0.07);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
  }

  /* Organization header */
  header {
    padding: 30px 24px;
    text-align: center;
    background:
      radial-gradient(
        ellipse at top right,
        rgba(255, 133, 10, 0.2),
        transparent 60%
      ),
      rgba(18, 18, 18, 0.85);
  }

  header h1 {
    margin: 0 0 12px;
    font-size: clamp(24px, 2.6vw, 38px);
    font-weight: 800;
    line-height: 1.25;
    letter-spacing: -0.7px;
    color: var(--white);
  }

  header h2 {
    margin: 0;
    font-size: clamp(15px, 1.5vw, 20px);
    font-weight: 400;
    color: var(--muted);
  }

  /* Section headings */
  .section {
    padding: 32px 0;
    text-align: left;
  }

  .section h3 {
    margin: 0 0 24px;
    color: var(--white);
    font-size: clamp(24px, 2.2vw, 30px);
    font-weight: 750;
    letter-spacing: -0.5px;
    line-height: 1.3;
  }

  .section h3::after {
    content: "";
    display: block;
    width: 45px;
    height: 3px;
    margin-top: 12px;
    background: var(--orange);
    border-radius: 20px;
    box-shadow: 0 0 14px rgba(255, 133, 10, 0.5);
  }

  .section > p {
    color: var(--muted);
    font-size: 16px;
    line-height: 1.8;
  }

  /* Welcome panel */
  .content > .section:first-of-type {
    isolation: isolate;
    margin-top: 26px;
    padding: 48px 36px;
    background:
      radial-gradient(
        ellipse at 100% 100%,
        rgba(255, 119, 0, 0.25),
        transparent 58%
      ),
      linear-gradient(
        120deg,
        rgba(34, 25, 18, 0.9),
        rgba(12, 12, 12, 0.9)
      );
  }

  .content > .section:first-of-type::before,
  .content > .section:first-of-type::after {
    content: "";
    position: absolute;
    pointer-events: none;
    z-index: -1;
    width: 350px;
    height: 140px;
    right: -100px;
    border-radius: 50%;
    border: 1px solid rgba(255, 154, 47, 0.48);
    background: linear-gradient(
      160deg,
      transparent 25%,
      rgba(255, 133, 10, 0.13) 65%,
      rgba(255, 133, 10, 0.32)
    );
    box-shadow:
      inset 0 -8px 25px rgba(255, 133, 10, 0.12),
      0 12px 35px rgba(255, 115, 0, 0.12);
  }

  .content > .section:first-of-type::before {
    top: -20px;
    transform: rotate(-35deg);
  }

  .content > .section:first-of-type::after {
    bottom: -25px;
    right: -65px;
    transform: rotate(25deg);
  }

  .content > .section:first-of-type h3 {
    max-width: 760px;
    margin-bottom: 16px;
    font-size: clamp(26px, 3vw, 42px);
  }

  .content > .section:first-of-type p {
    max-width: 650px;
    margin-bottom: 0;
  }

  /* Mission and vision */
  .mv-container {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 24px;
    max-width: none;
    margin: 0;
  }

  .mv-card {
    min-width: 0;
    padding: 32px;
    color: var(--white);
    background:
      radial-gradient(
        ellipse at bottom right,
        rgba(255, 133, 10, 0.16),
        transparent 65%
      ),
      var(--glass);
    transition:
      border-color 0.2s ease,
      box-shadow 0.2s ease;
  }

  .mv-card:hover {
    border-color: rgba(255, 133, 10, 0.6);
    box-shadow:
      0 15px 40px rgba(0, 0, 0, 0.25),
      0 0 25px rgba(255, 133, 10, 0.1);
  }

  .mv-card h4 {
    margin: 0 0 18px;
    color: var(--orange-light);
    font-size: 23px;
    font-weight: 700;
  }

  .mv-mission p,
  .mv-vision p {
    margin: 0;
    color: #e0dbd6;
    font-size: 16px;
    line-height: 1.9;
  }

  /* Event cards */
  .events {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    align-items: start;
    gap: 24px;
    max-width: none;
    margin: 0;
  }

  .event-card {
    width: auto;
    min-width: 0;
    margin: 0;
    color: var(--white);
    background:
      radial-gradient(
        ellipse at bottom right,
        rgba(255, 133, 10, 0.1),
        transparent 65%
      ),
      var(--glass);
    transition:
      border-color 0.2s ease,
      box-shadow 0.2s ease;
  }

  .event-card:hover {
    border-color: rgba(255, 133, 10, 0.6);
    box-shadow:
      0 18px 45px rgba(0, 0, 0, 0.35),
      0 0 22px rgba(255, 133, 10, 0.12);
  }

  .event-card > img {
    display: block;
    width: 100%;
    height: 210px;
    object-fit: cover;
    border-bottom: 1px solid var(--border);
  }

  .event-card > div {
    padding: 22px;
  }

  .event-card h4 {
    margin: 0 0 16px;
    color: var(--white);
    font-size: 21px;
    font-weight: 700;
    line-height: 1.4;
    overflow-wrap: anywhere;
  }

  .event-card p {
    margin: 10px 0;
    color: var(--muted);
    font-size: 14px;
    line-height: 1.8;
    overflow-wrap: anywhere;
  }

  .event-card strong {
    color: var(--orange-light);
    font-weight: 600;
  }

  /* Existing buttons and links */
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 46px;
    margin: 14px 12px 0 0;
    padding: 12px 24px;
    border: 1px solid rgba(255, 184, 107, 0.5);
    border-radius: 12px;
    color: #15100b;
    background: linear-gradient(135deg, #ffa52f, #ff7900);
    text-decoration: none;
    font-size: 14px;
    font-weight: 750;
    line-height: 1.5;
    box-shadow: 0 6px 22px rgba(255, 115, 0, 0.22);
    transition:
      background 0.2s ease,
      box-shadow 0.2s ease;
  }

  .btn:hover {
    color: #15100b;
    background: linear-gradient(135deg, #ffb64c, #ff921a);
    box-shadow: 0 6px 30px rgba(255, 133, 10, 0.4);
  }

  .event-card .btn {
    display: flex;
    width: 100%;
    margin-right: 0;
  }

  .gallery img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    margin: 5px;
    border: 1px solid var(--border);
    border-radius: 9px;
    cursor: pointer;
  }

  /* Existing gallery viewer */
  #viewerModal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1000;
    width: 100%;
    height: 100%;
    padding: 60px;
    background: rgba(0, 0, 0, 0.94);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    justify-content: center;
    align-items: center;
  }

  #viewerModal img {
    max-width: 85%;
    max-height: 85%;
    object-fit: contain;
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: 0 0 40px rgba(255, 133, 10, 0.12);
  }

  #viewerModal .close,
  #viewerModal .nav {
    position: absolute;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    color: var(--white);
    background: rgba(255, 133, 10, 0.12);
    border: 1px solid var(--border);
    border-radius: 50%;
    cursor: pointer;
    user-select: none;
  }

  #viewerModal .close:hover,
  #viewerModal .nav:hover {
    color: var(--orange-light);
    background: rgba(255, 133, 10, 0.22);
  }

  #viewerModal .close {
    top: 20px;
    right: 25px;
    font-size: 32px;
  }

  #viewerModal .nav {
    top: 50%;
    transform: translateY(-50%);
    font-size: 24px;
  }

  #viewerModal .prev {
    left: 20px;
  }

  #viewerModal .next {
    right: 20px;
  }

  /* Get Started */
  .content > .section:last-of-type {
    margin-top: 12px;
    padding: 34px;
    background:
      radial-gradient(
        ellipse at right,
        rgba(255, 133, 10, 0.18),
        transparent 65%
      ),
      var(--glass);
  }

  .content > .section:last-of-type p {
    max-width: 720px;
  }

  .content > .section:last-of-type .btn[href="login.php"] {
    color: var(--white);
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(255, 255, 255, 0.25);
    box-shadow: none;
  }

  .content > .section:last-of-type .btn[href="login.php"]:hover {
    background: rgba(255, 133, 10, 0.12);
    border-color: var(--orange);
  }

  footer {
    margin-top: 32px;
    padding: 20px;
    text-align: center;
    background: transparent;
    color: #b5ada5;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    font-size: 13px;
    line-height: 1.7;
  }

  footer p {
    margin: 0;
  }

  /* Tablet */
  @media (max-width: 1150px) {
    .events {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .content {
      padding: 24px;
    }
  }

  /* Mobile */
  @media (max-width: 760px) {
    body {
      display: block;
    }

    .sidebar {
      position: relative;
      width: 100%;
      height: auto;
      padding: 20px;
      border-right: 0;
      border-bottom: 1px solid var(--border);
    }

    .sidebar h2 {
      margin-bottom: 16px;
      padding: 0 0 14px;
      font-size: 30px;
    }

    .sidebar a {
      display: inline-block;
      margin: 4px;
      padding: 10px 12px;
      font-size: 13px;
      vertical-align: middle;
    }

    .content {
      margin-left: 0;
      padding: 18px;
    }

    header {
      padding: 26px 20px;
    }

    .content > .section:first-of-type {
      padding: 32px 24px;
    }

    .mv-container,
    .events {
      grid-template-columns: 1fr;
      gap: 18px;
    }

    .mv-card {
      padding: 26px;
    }

    .section {
      padding: 26px 0;
    }

    .content > .section:last-of-type {
      padding: 26px;
    }

    #viewerModal {
      padding: 60px 12px;
    }

    #viewerModal img {
      max-width: 100%;
      max-height: 75%;
    }

    #viewerModal .nav {
      top: auto;
      bottom: 20px;
      transform: none;
    }

    #viewerModal .prev {
      left: calc(50% - 60px);
    }

    #viewerModal .next {
      right: calc(50% - 60px);
    }
  }

  @media (prefers-reduced-motion: reduce) {
    html {
      scroll-behavior: auto;
    }

    * {
      transition: none !important;
    }
  }
</style>
  <script>
    let currentImages = [];
    let currentIndex = 0;
	
    function openViewer(images, index) {
      currentImages = images;
      currentIndex = index;
      document.getElementById('viewerImg').src = currentImages[currentIndex];
      document.getElementById('viewerModal').style.display = 'flex';
    }
    function closeViewer() {
      document.getElementById('viewerModal').style.display = 'none';
    }
    function nextImage() {
      currentIndex = (currentIndex + 1) % currentImages.length;
      document.getElementById('viewerImg').src = currentImages[currentIndex];
    }
    function prevImage() {
      currentIndex = (currentIndex - 1 + currentImages.length) % currentImages.length;
      document.getElementById('viewerImg').src = currentImages[currentIndex];
    }
  </script>
  <link rel="stylesheet" href="assets/css/landing-page.css">
  <link rel="stylesheet" href="assets/css/hero-banner.css">
    <link rel="stylesheet" href="assets/css/system-ui.css">
    <script src="js/system-ui.js" defer></script>
    <link rel="stylesheet" href="assets/css/glass.css">
    <link rel="stylesheet" href="assets/css/light-clean.css">
    <script src="js/glass-ui.js" defer></script>
</head>
<body class="public-page">
  <?php require __DIR__ . '/includes/navbar.php'; ?>

  <!-- Content -->
  <div class="content">
    <?php require __DIR__ . '/includes/hero-banner.php'; ?>


    <div class="section highlights-section">
      <h3>What we build together</h3>
      <div class="highlights-grid">
        <article class="highlight-card"><span class="highlight-icon" aria-hidden="true">&#9733;</span><h4>Events</h4><p>Connect through meaningful activities and campus experiences.</p></article>
        <article class="highlight-card"><span class="highlight-icon" aria-hidden="true">&#9881;</span><h4>Training</h4><p>Learn practical skills that turn curiosity into confidence.</p></article>
        <article class="highlight-card"><span class="highlight-icon" aria-hidden="true">&#9829;</span><h4>Collaboration</h4><p>Work with peers, mentors, and communities that inspire.</p></article>
        <article class="highlight-card"><span class="highlight-icon" aria-hidden="true">&#9734;</span><h4>Leadership</h4><p>Grow as a responsible and creative future IT professional.</p></article>
      </div>
    </div>

    <!-- Mission & Vision -->
    <div class="section">
      <h3>Mission & Vision</h3>
      <div class="mv-container">
        <div class="mv-card mv-mission">
          <h4>Our Mission</h4>
          <p>To foster growth in computer science and IT students through events, training, and collaboration.</p>
        </div>
        <div class="mv-card mv-vision">
          <h4>Our Vision</h4>
          <p>To be a leading student organization that empowers future IT professionals with skills, creativity, and leadership.</p>
        </div>
      </div>
    </div>

    <!-- Event Highlights -->
    <div class="section">
      <h3>Event Highlights</h3>
      <div class="events">
        <?php while($row = $highlights->fetch_assoc()) { ?>
        <div class="event-card">
          <?php if(!empty($row['poster']) && file_exists($row['poster'])) { ?>
            <img src="<?php echo e(image_url($row['poster'])); ?>" alt="Poster">
          <?php } else { ?>
            <img src="assets/img/default.svg" alt="Poster">
          <?php } ?>
          <div>
            <h4><?php echo $row['title']; ?></h4>
            <p><strong>Date:</strong> <?php echo $row['event_date']; ?> 
               <strong>Time:</strong> <?php echo $row['event_time']; ?></p>
            <p><strong>Location:</strong> <?php echo $row['location']; ?></p>
            <p><?php echo $row['description']; ?></p>
          <?php
$gallery = $conn->query("SELECT * FROM highlight_images WHERE event_id=".$row['id']." ORDER BY uploaded_at DESC");
$images = [];
while($img = $gallery->fetch_assoc()) {
    $images[] = image_url($img['image_path']);
}
if(!empty($images)) {
?>
  <img src="<?php echo e(image_url($images[0])); ?>" 
       id="slide-<?php echo $row['id']; ?>" 
       style="width:100%;height:180px;object-fit:cover;cursor:pointer;border-radius:8px;margin-top:10px;"
       onclick='openViewer(<?php echo json_encode($images); ?>,0)'>
  <script>
    (function(){
      let imgs=<?php echo json_encode($images); ?>;
      let idx=0;
      if(imgs.length>1){
        setInterval(function(){
          idx=(idx+1)%imgs.length;
          document.getElementById("slide-<?php echo $row['id']; ?>").src=imgs[idx];
        },5000);
      }
    })();
  </script>
<?php } ?>

          </div>
        </div>
        <?php } ?>
      </div>
    </div>

    <!-- Upcoming Events -->
    <div class="section">
      <h3>Upcoming Events</h3>
      <div class="events">
        <?php while($row = $upcoming->fetch_assoc()) { ?>
        <div class="event-card">
          <?php if(!empty($row['poster']) && file_exists($row['poster'])) { ?>
            <img src="<?php echo e(image_url($row['poster'])); ?>" alt="Poster">
          <?php } else { ?>
            <img src="assets/img/default.svg" alt="Poster">
          <?php } ?>
          <div>
            <h4><?php echo $row['title']; ?></h4>
            <p><strong>Date:</strong> <?php echo $row['event_date']; ?> 
               <strong>Time:</strong> <?php echo $row['event_time']; ?></p>
            <p><strong>Location:</strong> <?php echo $row['location']; ?></p>
              <p><?php echo $row['description']; ?></p>
            <a href="<?php echo $student ? 'registration.php?event_id=' . (int) $row['id'] : 'student_register.php'; ?>" class="btn"><?php echo $student ? 'Register Now' : 'Register to join'; ?></a>
          </div>
        </div>
        <?php } ?>
      </div>
    </div>

    <!-- Gallery Viewer Modal -->
    <div id="viewerModal">
      <span class="close" onclick="closeViewer()">&times;</span>
      <span class="nav prev" onclick="prevImage()">&#10094;</span>
      <img id="viewerImg" src="">
      <span class="nav next" onclick="nextImage()">&#10095;</span>
    </div>

    <div class="section">
      <h3>Get Started</h3>
      <p>Register for upcoming events, explore officer information, and stay connected with JPCS activities.</p>
      <a href="student_register.php" class="btn">Register Now</a>
      <a href="login.php" class="btn">Login</a>
    </div>

    <footer>
      <div>
        <div class="footer-brand"><img class="theme-logo-dark" src="assets/img/jpcs-logo.png" alt="JPCS logo"><img class="theme-logo-light" src="assets/img/jpcs-logo-light.png" alt="JPCS logo"><span>JPCS</span></div>
        <p class="footer-copy">A student-led community empowering future IT professionals through technology, leadership, and innovation.</p>
      </div>
      <div>
        <strong>Quick links</strong>
        <div class="footer-links"><a href="index.php">Home</a><a href="student_register.php">Register</a><a href="login.php">Login</a></div>
      </div>
      <div class="footer-social" aria-label="Social links">
        <a href="mailto:jpcs@cmu.edu.ph" aria-label="Email JPCS">&#9993;</a>
        <a href="index.php" aria-label="JPCS home">&#8962;</a>
      </div>
      <p>&copy; 2026 Junior Philippine Computer Society - City of Malabon University</p>
    </footer>
  </div>
</body>
</html>
