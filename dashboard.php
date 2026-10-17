<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$userID = (int) $_SESSION['user_id'];
$user = $conn->query(
    "SELECT role, name FROM users WHERE id='$userID'"
)->fetch_assoc();

$role = $user['role'];

function dashboardEscape(mixed $value): string
{
    return e($value);
}

// Upload images.
if (isset($_POST['upload_images'])) {
    $event_id = (int) $_POST['event_id'];
    $targetDir = "uploads/highlights/gallery/";

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    foreach ($_FILES['images']['name'] as $key => $name) {
        if ($_FILES['images']['error'][$key] !== UPLOAD_ERR_OK) {
            continue;
        }

        $filePath = $targetDir . uniqid('highlight_', true) .
                    "_" . basename($name);

        if (move_uploaded_file(
            $_FILES['images']['tmp_name'][$key],
            $filePath
        )) {
            $stmt = $conn->prepare("
                INSERT INTO highlight_images
                    (event_id, image_path, uploaded_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->bind_param("is", $event_id, $filePath);
            $stmt->execute();
            $stmt->close();
        }
    }

    header("Location: dashboard.php");
    exit();
}

// Delete image.
if (isset($_POST['remove_id'])) {
    $remove_id = (int) $_POST['remove_id'];

    $res = $conn->query("
        SELECT image_path, event_id
        FROM highlight_images
        WHERE id='$remove_id'
    ");

    if ($res && $res->num_rows > 0) {
        $img = $res->fetch_assoc();

        if (file_exists($img['image_path'])) {
            unlink($img['image_path']);
        }

        $conn->query(
            "DELETE FROM highlight_images WHERE id='$remove_id'"
        );
    }

    exit("deleted");
}

$highlights = $conn->query("
    SELECT * FROM events
    WHERE status='done'
    ORDER BY event_date DESC
");

$upcoming = $conn->query("
    SELECT * FROM events
    WHERE status='upcoming'
    ORDER BY event_date ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JPCS Admin Dashboard</title>

<style>
    * { box-sizing: border-box; }

    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        font-family: "Segoe UI", Arial, sans-serif;
        color: #fff;
        background:
            radial-gradient(ellipse at top right,
                rgba(255,125,0,.2), transparent 45%),
            radial-gradient(ellipse at bottom left,
                rgba(255,125,0,.14), transparent 45%),
            #090909;
        background-attachment: fixed;
        color-scheme: dark;
    }

    .sidebar {
        width: 220px;
        height: 100vh;
        position: fixed;
        left: 0;
        top: 0;
        padding-top: 20px;
        overflow-y: auto;
        z-index: 20;
        background:
            radial-gradient(ellipse at bottom left,
                rgba(255,125,0,.23), transparent 60%),
            rgba(12,12,12,.94);
        border-right: 1px solid rgba(255,152,31,.28);
        box-shadow: 6px 0 30px rgba(0,0,0,.3);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
    }

    .sidebar h2 {
        text-align: center;
        margin-bottom: 24px;
        font-size: 27px;
        overflow-wrap: anywhere;
    }

    .sidebar h2::after {
        content: "";
        display: block;
        width: 42px;
        height: 3px;
        margin: 12px auto 0;
        border-radius: 20px;
        background: #ff850a;
        box-shadow: 0 0 14px rgba(255,133,10,.65);
    }

    .sidebar a {
        display: block;
        margin: 6px 10px;
        padding: 12px 16px;
        color: #e5ded7;
        text-decoration: none;
        font-weight: 600;
        font-size: 14px;
        line-height: 1.5;
        border-radius: 10px;
    }

    .sidebar a[href="dashboard.php"] {
        color: #fff;
        background: linear-gradient(110deg,
            rgba(255,133,10,.35), rgba(255,133,10,.08));
        box-shadow:
            inset 3px 0 0 #ff850a,
            inset 0 0 0 1px rgba(255,133,10,.45),
            0 0 20px rgba(255,125,0,.12);
    }

    .sidebar a:hover {
        color: #fff;
        background: rgba(255,133,10,.18);
    }

    .content {
        margin-left: 220px;
        padding: 20px;
        flex: 1;
        min-width: 0;
    }

    header, .welcome, .mv-card, .event-card, .modal-content {
        border: 1px solid rgba(255,166,70,.3);
        border-radius: 18px;
        background:
            radial-gradient(ellipse at bottom right,
                rgba(255,133,10,.15), transparent 65%),
            rgba(24,21,18,.88);
        box-shadow:
            0 12px 35px rgba(0,0,0,.25),
            inset 0 1px 0 rgba(255,255,255,.07);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
    }

    header {
        padding: 15px;
        text-align: center;
    }

    header h1 {
        font-weight: 800;
        line-height: 1.3;
    }

    header h2 {
        color: #d3cac1;
        font-weight: 400;
    }

    .section {
        padding: 35px 20px;
        text-align: center;
    }

    .section h3 {
        color: #ffae55;
        font-size: 2em;
        margin: 0 0 24px;
        line-height: 1.3;
    }

    .welcome {
        position: relative;
        isolation: isolate;
        overflow: hidden;
        margin-top: 24px;
        padding: 40px 30px;
        text-align: left;
    }

    .welcome::after {
        content: "";
        position: absolute;
        z-index: -1;
        pointer-events: none;
        width: 380px;
        height: 145px;
        right: -130px;
        bottom: -10px;
        transform: rotate(-30deg);
        border-radius: 50%;
        border: 1px solid rgba(255,166,70,.45);
        background: linear-gradient(150deg,
            transparent, rgba(255,133,10,.25));
        box-shadow: 0 10px 35px rgba(255,125,0,.15);
    }

    .welcome h3 {
        min-height: 1.3em;
        color: #fff;
        font-size: clamp(24px, 2.6vw, 36px);
    }

    .welcome p, #clock {
        color: #ddd3c9;
        line-height: 1.8;
    }

    .mv-container {
        display: flex;
        justify-content: center;
        gap: 40px;
        flex-wrap: wrap;
        max-width: 1000px;
        margin: auto;
    }

    .mv-card {
        flex: 1;
        min-width: 300px;
        padding: 26px;
    }

    .mv-card h4 {
        color: #ffae55;
        font-size: 22px;
        margin: 0 0 16px;
    }

    .mv-card p {
        color: #eee7e0;
        line-height: 1.8;
    }

    .events {
        display: flex;
        justify-content: center;
        gap: 20px;
        flex-wrap: wrap;
        max-width: 1000px;
        margin: auto;
    }

    .event-card {
        width: 280px;
        overflow: hidden;
        margin: 15px;
        text-align: left;
    }

    .event-card:hover {
        border-color: rgba(255,152,31,.65);
        box-shadow:
            0 12px 35px rgba(0,0,0,.3),
            0 0 24px rgba(255,125,0,.12);
    }

    .event-card > img {
        display: block;
        width: 100%;
        height: 160px;
        object-fit: cover;
    }

    .event-card > div { padding: 18px; }

    .event-card h4 {
        margin: 10px 0;
        font-size: 1.15em;
        color: #fff;
        overflow-wrap: anywhere;
    }

    .event-card p {
        color: #d3cac1;
        font-size: .9em;
        margin: 8px 0;
        line-height: 1.7;
        overflow-wrap: anywhere;
    }

    .event-card strong { color: #ffae55; }

    .highlight-slide {
        display: block;
        width: 100%;
        height: 180px;
        object-fit: cover;
        cursor: pointer;
        border-radius: 10px;
        margin-top: 14px;
    }

    .btn, .btn-add, button {
        display: inline-block;
        padding: 10px 18px;
        background: linear-gradient(135deg, #ffa52f, #ff7900);
        color: #17100a;
        border: 1px solid rgba(255,184,107,.5);
        border-radius: 10px;
        text-decoration: none;
        font-family: inherit;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 5px 18px rgba(255,125,0,.18);
    }

    .btn-add {
        margin-top: 10px;
        font-size: 12px;
    }

    .btn:hover, .btn-add:hover, button:hover {
        background: linear-gradient(135deg, #ffb64c, #ff921a);
        box-shadow: 0 5px 25px rgba(255,125,0,.3);
    }

    .modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1000;
        width: 100%;
        height: 100%;
        padding: 20px;
        background: rgba(0,0,0,.92);
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }

    .modal-content {
        padding: 26px;
        width: 400px;
        max-width: 100%;
        max-height: 90vh;
        overflow-y: auto;
    }

    .modal-content h3 {
        color: #ffae55;
        margin-top: 5px;
    }

    .modal .close {
        float: right;
        font-size: 30px;
        cursor: pointer;
        color: #fff;
    }

    .modal input[type="file"] {
        display: block;
        width: 100%;
        margin: 20px 0;
        padding: 12px;
        border: 1px solid rgba(255,166,70,.3);
        border-radius: 10px;
        background: #111;
    }

    .modal input::file-selector-button {
        padding: 8px 10px;
        margin-right: 10px;
        border: 0;
        border-radius: 6px;
        background: #ff981f;
        color: #111;
        cursor: pointer;
    }

    #viewerModal img {
        max-width: 80%;
        max-height: 75%;
        object-fit: contain;
        border-radius: 12px;
        box-shadow: 0 0 30px rgba(255,125,0,.1);
    }

    #viewerModal .close {
        position: absolute;
        top: 20px;
        right: 30px;
        font-size: 36px;
    }

    #viewerModal .nav {
        position: absolute;
        top: 50%;
        color: #ffae55;
        font-size: 40px;
        cursor: pointer;
        user-select: none;
    }

    #viewerModal .prev { left: 30px; }
    #viewerModal .next { right: 30px; }

    #deleteForm {
        position: absolute;
        bottom: 20px;
    }

    footer {
        color: #b5ada5;
        text-align: center;
        padding: 15px;
        margin-top: 40px;
        font-size: 13px;
        border-top: 1px solid rgba(255,255,255,.1);
    }

    a:focus-visible, button:focus-visible, input:focus-visible {
        outline: 3px solid #ffae55;
        outline-offset: 4px;
    }

    @media (max-width: 700px) {
        body { display: block; }

        .sidebar {
            position: relative;
            width: 100%;
            height: auto;
            padding: 16px 10px;
            border-right: 0;
            border-bottom: 1px solid rgba(255,152,31,.28);
        }

        .sidebar h2 { margin: 0 0 16px; }

        .sidebar a {
            display: inline-block;
            margin: 4px;
            padding: 10px 12px;
            font-size: 13px;
        }

        .content { margin-left: 0; padding: 16px; }
        header h1 { font-size: 25px; }
        header h2 { font-size: 17px; }
        .section { padding: 28px 0; }
        .welcome { padding: 28px 22px; }
        .mv-container { gap: 20px; }
        .mv-card { min-width: 0; flex-basis: 100%; }
        .event-card { margin: 8px 0; max-width: 100%; }
        #viewerModal .prev { left: 10px; }
        #viewerModal .next { right: 10px; }
    }
</style>

<script>
let currentImages = [];
let currentIndex = 0;
let currentEventId = null;
const galleries = {};
const slideTimers = {};

function updateClock() {
    const clock = document.getElementById("clock");

    if (!clock) return;

    const options = {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit"
    };

    clock.textContent = new Date().toLocaleString("en-PH", options);
}

window.addEventListener("load", function() {
    updateClock();
    setInterval(updateClock, 1000);

    const welcome = document.getElementById("welcome-text");
    const text = welcome.textContent.trim();

    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        welcome.textContent = text;
        return;
    }

    welcome.textContent = "";
    let i = 0;

    function typing() {
        if (i < text.length) {
            welcome.textContent += text.charAt(i);
            i++;
            setTimeout(typing, 100);
        }
    }

    typing();
});

function openViewer(images, index, eventId) {
    if (!images.length) return;

    currentImages = images;
    currentIndex = index;
    currentEventId = eventId;

    updateViewer();
    document.getElementById("viewerModal").style.display = "flex";
}

function closeViewer() {
    document.getElementById("viewerModal").style.display = "none";
}

function nextImage() {
    if (!currentImages.length) return;
    currentIndex = (currentIndex + 1) % currentImages.length;
    updateViewer();
}

function prevImage() {
    if (!currentImages.length) return;

    currentIndex =
        (currentIndex - 1 + currentImages.length) % currentImages.length;

    updateViewer();
}

function updateViewer() {
    if (!currentImages.length) return;

    document.getElementById("viewerImg").src =
        currentImages[currentIndex].image_path;

    document.getElementById("removeId").value =
        currentImages[currentIndex].id;
}

function startSlideshow(eventId) {
    let index = 0;

    slideTimers[eventId] = setInterval(function() {
        const images = galleries[eventId] || [];
        const slide = document.getElementById("slide-" + eventId);

        if (!slide || !images.length) return;

        index = (index + 1) % images.length;
        slide.src = images[index].image_path;
    }, 5000);
}

async function deleteImage() {
    const removeId = document.getElementById("removeId").value;
    const button = document.getElementById("deleteButton");

    button.disabled = true;

    try {
        const response = await fetch("dashboard.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: "remove_id=" + encodeURIComponent(removeId)
        });

        const text = await response.text();

        if (!response.ok || text.trim() !== "deleted") {
            throw new Error("Deletion failed.");
        }

        currentImages = currentImages.filter(
            img => String(img.id) !== String(removeId)
        );

        galleries[currentEventId] = currentImages;

        const slide = document.getElementById(
            "slide-" + currentEventId
        );

        if (currentImages.length) {
            currentIndex = Math.min(
                currentIndex,
                currentImages.length - 1
            );

            updateViewer();

            if (slide) {
                slide.src = currentImages[currentIndex].image_path;
            }
        } else {
            closeViewer();
            clearInterval(slideTimers[currentEventId]);
            if (slide) slide.remove();
        }
    } catch (error) {
        alert("Image could not be deleted. Please try again.");
    } finally {
        button.disabled = false;
    }
}

function openAddImageModal(eventId) {
    document.getElementById("addImageEventId").value = eventId;
    document.getElementById("addImageModal").style.display = "flex";
}

function closeAddImageModal() {
    document.getElementById("addImageModal").style.display = "none";
}
</script>
<link rel="stylesheet" href="css/theme.css">
<script src="js/theme-init.js"></script>
<script src="css/theme.js" defer></script>
<script>
(function () {
    let theme = "dark";

    try {
        if (localStorage.getItem("jpcs-theme") === "light") {
            theme = "light";
        }
    } catch (error) {}

    document.documentElement.setAttribute("data-theme", theme);
})();
</script>
    <link rel="stylesheet" href="assets/css/system-ui.css">
    <script src="js/system-ui.js" defer></script>
    <link rel="stylesheet" href="assets/css/glass.css">
    <link rel="stylesheet" href="assets/css/light-clean.css">
    <script src="js/glass-ui.js" defer></script>
</head>
<body class="app-page admin-page">
<div class="sidebar">
    <h2><?php echo dashboardEscape(ucfirst($role)); ?></h2>
    <a href="dashboard.php">Dashboard</a>
    <a href="officers.php">Officer Information</a>
    <a href="pending.php">Student Registered Pending</a>
    <a href="scanner.php">Event Attendance Scanner</a>
    <a href="attendance.php">Attendance List per Event</a>
    <a href="register_list.php">Register List</a>
    <a href="events.php">Event Management</a>

    <?php if (in_array(
        $role,
        ['admin_adviser', 'admin_advisor'],
        true
    )) { ?>
       <a href="events.php">Event Management</a>
<a href="accounts.php">Account Management</a>
<a href="logout.php">Logout</a>
</div>
    <?php } ?>

    <a href="logout.php">Logout</a>
</div>

<div class="content">
    <header>
        <h1>Junior Philippine Computer Society</h1>
        <h2>City of Malabon University</h2>
    </header>

    <div class="section welcome">
        <h3 id="welcome-text">
            Welcome, <?php echo dashboardEscape($_SESSION['name']); ?>
            (<?php echo dashboardEscape($_SESSION['position']); ?>)
        </h3>
        <p>
            You are logged in as
            <?php echo dashboardEscape($_SESSION['role']); ?>.
        </p>
        <div id="clock"></div>
    </div>

    <!-- Mission & Vision -->
    <div class="section">
        <h3>Mission &amp; Vision</h3>

        <div class="mv-container">
            <div class="mv-card mv-mission">
                <h4>Our Mission</h4>
                <p>
                    To foster growth in computer science and IT students
                    through events, training, and collaboration.
                </p>
            </div>

            <div class="mv-card mv-vision">
                <h4>Our Vision</h4>
                <p>
                    To be a leading student organization that empowers
                    future IT professionals with skills, creativity,
                    and leadership.
                </p>
            </div>
        </div>
    </div>

    <!-- Event Highlights -->
    <div class="section">
        <h3>Event Highlights</h3>

        <div class="events">
            <?php while ($row = $highlights->fetch_assoc()) {
                $eventId = (int) $row['id'];

                $gallery = $conn->query("
                    SELECT * FROM highlight_images
                    WHERE event_id=$eventId
                    ORDER BY uploaded_at DESC
                ");

                $images = [];

                while ($img = $gallery->fetch_assoc()) {
                    $img['image_path'] = image_url($img['image_path']);
                    $images[] = $img;
                }
            ?>
                <div class="event-card">
                    <?php if (!empty($row['poster'])) { ?>
                        <img
                            src="<?php echo dashboardEscape(image_url($row['poster'])); ?>"
                            alt="Poster">
                    <?php } else { ?>
                        <img src="assets/img/default.svg" alt="Poster">
                    <?php } ?>

                    <div>
                        <h4><?php echo dashboardEscape($row['title']); ?></h4>

                        <p>
                            <strong>Date:</strong>
                            <?php echo dashboardEscape($row['event_date']); ?>
                        </p>

                        <p><?php
                            echo dashboardEscape($row['description']);
                        ?></p>

                        <a
                            href="javascript:void(0)"
                            class="btn-add"
                            onclick="openAddImageModal(<?php echo $eventId; ?>)">
                            + Add Image
                        </a>

                        <?php if (!empty($images)) { ?>
                            <img
                            src="<?php echo dashboardEscape($images[0]['image_path']); ?>"
                                id="slide-<?php echo $eventId; ?>"
                                class="highlight-slide"
                                alt="Event highlight"
                                onclick="openViewer(
                                    galleries[<?php echo $eventId; ?>],
                                    0,
                                    <?php echo $eventId; ?>
                                )">

                            <script>
                                galleries[<?php echo $eventId; ?>] =
                                    <?php echo json_encode(
                                        $images,
                                        JSON_HEX_TAG |
                                        JSON_HEX_AMP |
                                        JSON_HEX_APOS |
                                        JSON_HEX_QUOT
                                    ); ?>;

                                startSlideshow(<?php echo $eventId; ?>);
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
            <?php while ($row = $upcoming->fetch_assoc()) { ?>
                <div class="event-card">
                    <?php if (
                        !empty($row['poster']) &&
                        file_exists($row['poster'])
                    ) { ?>
                        <img
                            src="<?php echo dashboardEscape(image_url($row['poster'])); ?>"
                            alt="Poster">
                    <?php } else { ?>
                        <img src="assets/img/default.svg" alt="Poster">
                    <?php } ?>

                    <div>
                        <h4><?php echo dashboardEscape($row['title']); ?></h4>

                        <p>
                            <strong>Date:</strong>
                            <?php echo dashboardEscape($row['event_date']); ?>
                            <strong>Time:</strong>
                            <?php echo dashboardEscape($row['event_time']); ?>
                        </p>

                        <p>
                            <strong>Location:</strong>
                            <?php echo dashboardEscape($row['location']); ?>
                        </p>

                        <p><?php
                            echo dashboardEscape($row['description']);
                        ?></p>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>

    <!-- Add Image Modal -->
    <div id="addImageModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeAddImageModal()">&times;</span>
            <h3>Add Images to Highlight</h3>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="event_id" id="addImageEventId">
                <input
                    type="file"
                    name="images[]"
                    accept="image/*"
                    multiple
                    required>
                <button type="submit" name="upload_images">Upload</button>
            </form>
        </div>
    </div>

    <!-- Gallery Viewer -->
    <div id="viewerModal" class="modal">
        <span class="close" onclick="closeViewer()">&times;</span>
        <span class="nav prev" onclick="prevImage()">&#10094;</span>
        <img id="viewerImg" alt="Event highlight">

        <form id="deleteForm">
            <input type="hidden" name="remove_id" id="removeId">
            <button
                type="button"
                id="deleteButton"
                onclick="deleteImage()">
                Delete
            </button>
        </form>

        <span class="nav next" onclick="nextImage()">&#10095;</span>
    </div>

    <footer>
        <p>
            &copy; 2026 Junior Philippine Computer Society -
            City of Malabon University
        </p>
    </footer>
</div>
</body>
</html>
