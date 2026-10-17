<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

// Check kung naka-login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// MULTIPLE IMAGE UPLOAD
if (isset($_POST['upload_images'])) {
    $event_id = $_POST['event_id'];
    $targetDir = "uploads/highlights/gallery/";
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    foreach ($_FILES['images']['name'] as $key => $name) {
        $filePath = $targetDir . time() . "_" . basename($name);
        move_uploaded_file($_FILES['images']['tmp_name'][$key], $filePath);
        $conn->query("INSERT INTO highlight_images (event_id, image_path, uploaded_at) 
                      VALUES ('$event_id','$filePath', NOW())");
    }

    header("Location: dashboard.php?success=" . urlencode("Images added to highlight!"));
    exit();
}

// Fetch Highlights (done events)
$highlights = $conn->query("SELECT * FROM events WHERE status='done' ORDER BY event_date DESC");

// Fetch Upcoming Events
$upcoming = $conn->query("SELECT * FROM events WHERE status='upcoming' ORDER BY event_date ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="stylesheet" href="css/theme.css">
<script src="js/theme-init.js"></script>
  <meta charset="UTF-8">
  <title>JPCS Admin Dashboard</title>
  <style>
    body { margin:0; font-family: Arial, sans-serif; display:flex; }
    .sidebar { width:220px; background:#000; color:#f39c12; height:100vh; position:fixed; left:0; top:0; padding-top:20px; }
    .sidebar h2 { text-align:center; margin-bottom:20px; }
    .sidebar a { display:block; color:#f39c12; padding:12px 20px; text-decoration:none; font-weight:bold; }
    .sidebar a:hover { background:#f39c12; color:#000; }
    .content { margin-left:220px; padding:20px; flex:1; }
    header { background:#f39c12; color:#000; padding:15px; text-align:center; border-radius:8px; }
    .section { padding:40px 20px; text-align:center; }
    .section h3 { color:#f39c12; font-size:2em; margin-bottom:20px; }
    footer { background:#000; color:#f39c12; text-align:center; padding:15px; margin-top:40px; }
    .mv-container { display:flex; justify-content:center; gap:40px; flex-wrap:wrap; max-width:1000px; margin:auto; }
    .mv-card { flex:1; min-width:300px; padding:20px; border-radius:8px; box-shadow:0 4px 8px rgba(0,0,0,0.3); }
    .mv-mission { background:#000; color:#f39c12; }
    .mv-mission p { color:#fff; line-height:1.6; }
    .mv-vision { background:#f39c12; color:#000; }
    .mv-vision p { line-height:1.6; }
    .events { display:flex; justify-content:center; gap:30px; flex-wrap:wrap; max-width:1000px; margin:auto; }
    .event-card { flex:1; min-width:280px; background:#000; color:#f39c12; border-radius:8px; overflow:hidden; box-shadow:0 4px 8px rgba(0,0,0,0.3); }
    .event-card img { width:100%; height:180px; object-fit:cover; cursor:pointer; }
    .event-card div { padding:15px; }
    .event-card p { color:#fff; }
    .calendar { background:#fff; border:1px solid #ddd; padding:20px; border-radius:8px; display:inline-block; text-align:left; }
    #clock { font-size:1.2em; color:#000; margin-top:10px; }
    #welcome-text { font-size:2em; color:#f39c12; opacity:0; transform:translateY(-20px); animation:fadeSlideIn 2s forwards; }
    @keyframes fadeSlideIn { to { opacity:1; transform:translateY(0); } }
    .welcome p { opacity:0; animation:fadeIn 3s forwards; animation-delay:1s; }
    @keyframes fadeIn { to { opacity:1; } }
    .btn-add { background:#f39c12; color:#000; padding:5px 10px; border-radius:4px; text-decoration:none; font-size:12px; }
    .gallery img { width:80px; height:80px; object-fit:cover; margin:5px; border-radius:4px; cursor:pointer; }
    .modal { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); display:flex; justify-content:center; align-items:center; }
    .modal-content { background:#fff; padding:20px; border-radius:8px; width:400px; }
    .close { float:right; font-size:20px; cursor:pointer; }
    /* Gallery viewer modal */
    #viewerModal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); justify-content:center; align-items:center; }
    #viewerModal img { max-width:80%; max-height:80%; }
    #viewerModal .close { position:absolute; top:20px; right:30px; color:#fff; font-size:30px; cursor:pointer; }
    #viewerModal .nav { position:absolute; top:50%; font-size:40px; color:#fff; cursor:pointer; }
    #viewerModal .prev { left:30px; }
    #viewerModal .next { right:30px; }
  </style>
  <script>
    function updateClock() {
      const now = new Date();
      const options = { weekday:'long', year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit' };
      document.getElementById('clock').innerHTML = now.toLocaleString('en-PH', options);
    }
    setInterval(updateClock, 1000);
    window.onload = function() {
      updateClock();
      const welcome = document.getElementById("welcome-text");
      const text = welcome.innerText;
      welcome.innerText = "";
      let i = 0;
      function typing() {
        if (i < text.length) {
          welcome.innerHTML += text.charAt(i);
          i++;
          setTimeout(typing, 100);
        }
      }
      typing();
    };

    // Gallery viewer logic
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
      if (currentIndex < currentImages.length - 1) {
        currentIndex++;
        document.getElementById('viewerImg').src = currentImages[currentIndex];
      }
    }
    function prevImage() {
      if (currentIndex > 0) {
        currentIndex--;
        document.getElementById('viewerImg').src = currentImages[currentIndex];
      }
    }
  </script>
    <link rel="stylesheet" href="assets/css/system-ui.css">
    <script src="js/system-ui.js" defer></script>
</head>
<body class="app-page admin-page">
  <div class="sidebar">
    <h2>Admin</h2>
    <a href="dashboard.php">Dashboard</a>
    <a href="officers.php">Officer Information</a>
    <a href="pending.php">Student Registered Pending</a>
    <a href="scanner.php">Event Attendance Scanner</a>
    <a href="attendance.php">Attendance List per Event</a>
   
    <a href="logout.php">Logout</a>
  </div>


  <div class="content">
    <header>
      <h1>Junior Philippine Computer Society</h1>
      <h2>City of Malabon University</h2>
    </header>

    <div class="section welcome">
      <h3 id="welcome-text">
        Welcome, <?php echo $_SESSION['name']; ?> (<?php echo $_SESSION['position']; ?>)
      </h3>
          <p>You are logged in as <?php echo $_SESSION['role']; ?>.</p>
      <div id="clock"></div>
    </div>

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

    <!-- Highlights Section -->
    <div class="section">
      <h3>Event Highlights</h3>
      <div class="events">
        <?php while($row = $highlights->fetch_assoc()) { ?>
        <div class="event-card">
          <?php if(!empty($row['poster'])) { ?>
            <img src="<?php echo e(image_url($row['poster'])); ?>" alt="Poster">
          <?php } else { ?>
            <img src="assets/img/default.svg" alt="Poster">
          <?php } ?>
          <div>
            <h4><?php echo $row['title']; ?></h4>
            <p><strong>Date:</strong> <?php echo $row['event_date']; ?></p>
            <p><?php echo $row['description']; ?></p>
            <a href="dashboard.php?addimage=<?php echo $row['id']; ?>" class="btn-add">+ Add Image</a>
            
            <!-- Gallery thumbnails -->
            <div class="gallery">
              <?php
              $gallery = $conn->query("SELECT * FROM highlight_images WHERE event_id=".$row['id']." ORDER BY uploaded_at DESC");
              $images = [];
              while($img = $gallery->fetch_assoc()) {
                  $images[] = image_url($img['image_path']);
              }
              foreach($images as $index => $path) {
                  echo "<img src='".$path."' onclick='openViewer(".json_encode($images).",".$index.")'>";
              }
              ?>
            </div>
          </div>
        </div>
        <?php } ?>
      </div>
    </div>

    <!-- Upcoming Events Section -->
    <div class="section">
      <h3>Upcoming Events Calendar</h3>
      <div class="calendar">
        <?php while($row = $upcoming->fetch_assoc()) { ?>
          <p><strong><?php echo $row['event_date']; ?>:</strong> 
             <?php echo $row['title']; ?> 
             (<?php echo $row['event_time']; ?>, <?php echo $row['location']; ?>)</p>
        <?php } ?>
      </div>
    </div>

    <!-- Add Image Modal -->
    <?php if (isset($_GET['addimage'])) { ?>
    <div class="modal">
      <div class="modal-content">
        <span class="close" onclick="window.location='dashboard.php'">&times;</span>
        <h3>Add Images to Highlight</h3>
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="event_id" value="<?php echo $_GET['addimage']; ?>">
          <input type="file" name="images[]" accept="image/*" multiple required>
          <button type="submit" name="upload_images">Upload</button>
          <a href="dashboard.php"><button type="button">Cancel</button></a>
        </form>
      </div>
    </div>
    <?php } ?>

    <!-- Gallery Viewer Modal -->
    <div id="viewerModal">
      <span class="close" onclick="closeViewer()">&times;</span>
      <span class="nav prev" onclick="prevImage()">&#10094;</span>
      <img id="viewerImg" src="">
      <span class="nav next" onclick="nextImage()">&#10095;</span>
    </div>

    <footer>
      <p>&copy; 2026 Junior Philippine Computer Society - City of Malabon University</p>
    </footer>
  </div>
</body>
</html>
