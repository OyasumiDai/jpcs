<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = (int) $_SESSION['user_id'];
$user = $conn->query(
    "SELECT role, name FROM users WHERE id=$userID"
)->fetch_assoc();

if (!$user) {
    header("Location: login.php");
    exit();
}

$role = $user['role'];

function scannerEscape($value) {
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$successMessage = "";
$errorMessage = "";

// Accept valid integer IDs, including 0.
$eventID = filter_input(
    INPUT_GET,
    'event_id',
    FILTER_VALIDATE_INT
);

$hasEventID = ($eventID !== null && $eventID !== false);

$event = null;
$attendance = null;
$totalRegistered = 0;
$totalAttended = 0;

if ($hasEventID) {
    $event = $conn->query(
        "SELECT * FROM events WHERE id=$eventID"
    )->fetch_assoc();
}

// Handle scanned control number.
if (isset($_POST['qrdata']) && $event) {
    $qrdata = is_string($_POST['qrdata'])
        ? trim($_POST['qrdata'])
        : '';

    if ($event['status'] == 'done') {
        $errorMessage =
            "This event is already marked as DONE. Scanning disabled.";
    } elseif ($qrdata === '') {
        $errorMessage = "The QR code is empty.";
    } else {
        $stmt = $conn->prepare("
            SELECT *
            FROM registrations
            WHERE control_number = ?
              AND event_id = ?
              AND status = 'confirmed'
        ");

        $stmt->bind_param("si", $qrdata, $eventID);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($student) {
            $stmt = $conn->prepare("
                SELECT *
                FROM attendance
                WHERE event_id = ?
                  AND participant_id = ?
            ");

            $stmt->bind_param("is", $eventID, $qrdata);
            $stmt->execute();
            $alreadyAttended =
                $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($alreadyAttended) {
                $errorMessage = "Already attended.";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO attendance
                        (event_id, participant_id, scanned_at)
                    VALUES (?, ?, NOW())
                ");

                $stmt->bind_param("is", $eventID, $qrdata);

                if ($stmt->execute()) {
                    $successMessage =
                        "Attendance recorded successfully.";
                } else {
                    $errorMessage =
                        "Attendance could not be saved. Please try again.";
                }

                $stmt->close();
            }
        } else {
            $errorMessage =
                "Invalid or unconfirmed registration.";
        }
    }
}

// Fetch updated attendance after processing a scan.
if ($event) {
    $attendance = $conn->query("
        SELECT
            a.scanned_at,
            r.student_name,
            r.student_id,
            r.course_year_section,
            r.email,
            r.contact
        FROM attendance a
        LEFT JOIN registrations r
            ON TRIM(a.participant_id) = TRIM(r.control_number)
           AND a.event_id = r.event_id
        WHERE a.event_id = $eventID
        ORDER BY a.scanned_at ASC
    ");

    $totalRegistered = $conn->query("
        SELECT COUNT(*) AS reg_total
        FROM registrations
        WHERE event_id=$eventID
          AND status='confirmed'
    ")->fetch_assoc()['reg_total'];

    $totalAttended = $conn->query("
        SELECT COUNT(*) AS att_total
        FROM attendance
        WHERE event_id=$eventID
    ")->fetch_assoc()['att_total'];
}

$upcoming = $conn->query("
    SELECT *
    FROM events
    WHERE status='upcoming'
    ORDER BY event_date ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="stylesheet" href="css/theme.css">
<script src="js/theme-init.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event Attendance Scanner</title>

    <script src="js/html5-qrcode.min.js" type="text/javascript"></script>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            font-family: "Segoe UI", Arial, sans-serif;
            color: #fff;
            background:
                radial-gradient(
                    ellipse at top right,
                    rgba(255, 125, 0, 0.2),
                    transparent 45%
                ),
                radial-gradient(
                    ellipse at bottom left,
                    rgba(255, 125, 0, 0.14),
                    transparent 45%
                ),
                #090909;
            background-attachment: fixed;
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
                radial-gradient(
                    ellipse at bottom left,
                    rgba(255, 125, 0, 0.23),
                    transparent 60%
                ),
                rgba(12, 12, 12, 0.94);
            border-right: 1px solid rgba(255, 152, 31, 0.28);
            box-shadow: 6px 0 30px rgba(0, 0, 0, 0.3);
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
            box-shadow: 0 0 14px rgba(255, 133, 10, 0.65);
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

        .sidebar a[href="scanner.php"] {
            color: #fff;
            background: linear-gradient(
                110deg,
                rgba(255, 133, 10, 0.35),
                rgba(255, 133, 10, 0.08)
            );
            box-shadow:
                inset 3px 0 0 #ff850a,
                inset 0 0 0 1px rgba(255, 133, 10, 0.45),
                0 0 20px rgba(255, 125, 0, 0.12);
        }

        .sidebar a:hover {
            color: #fff;
            background: rgba(255, 133, 10, 0.18);
        }

        .content {
            margin-left: 220px;
            padding: 20px;
            flex: 1;
            min-width: 0;
        }

        header,
        .scanner-panel,
        .summary-panel,
        .event-card {
            border: 1px solid rgba(255, 166, 70, 0.3);
            border-radius: 18px;
            background:
                radial-gradient(
                    ellipse at bottom right,
                    rgba(255, 133, 10, 0.15),
                    transparent 65%
                ),
                rgba(24, 21, 18, 0.88);
            box-shadow:
                0 12px 35px rgba(0, 0, 0, 0.25),
                inset 0 1px 0 rgba(255, 255, 255, 0.07);
        }

        header {
            padding: 24px 20px;
            text-align: center;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        header h1 {
            margin: 0;
            font-size: clamp(25px, 3vw, 36px);
            line-height: 1.3;
        }

        .section {
            padding: 24px 20px;
        }

        h2,
        h3 {
            color: #ffae55;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .success,
        .error {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 18px;
            color: #fff;
            background: rgba(255, 133, 10, 0.12);
            border: 1px solid rgba(255, 166, 70, 0.5);
            line-height: 1.7;
            overflow-wrap: anywhere;
        }

        .error {
            border-width: 2px;
        }

        .events {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
        }

        .event-card {
            width: 220px;
            overflow: hidden;
        }

        .event-card:hover {
            border-color: rgba(255, 152, 31, 0.75);
            box-shadow:
                0 12px 35px rgba(0, 0, 0, 0.3),
                0 0 24px rgba(255, 125, 0, 0.15);
        }

        .event-card img {
            display: block;
            width: 100%;
            height: 150px;
            object-fit: cover;
        }

        .event-card > div {
            padding: 16px;
            text-align: center;
        }

        .event-card h4 {
            margin: 8px 0;
            font-size: 18px;
            line-height: 1.5;
        }

        .event-card p {
            color: #d3cac1;
            font-size: 14px;
        }

        .event-card a,
        .btn-back,
        #reader button {
            padding: 10px 15px;
            background: linear-gradient(135deg, #ffa52f, #ff7900);
            color: #17100a;
            border: 1px solid rgba(255, 184, 107, 0.5);
            border-radius: 10px;
            font-family: inherit;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 5px 18px rgba(255, 125, 0, 0.18);
        }

        .event-card a {
            display: block;
            margin-top: 14px;
        }

        .event-card a:hover,
        #reader button:hover {
            background: linear-gradient(135deg, #ffb64c, #ff921a);
        }

        .btn-back {
            display: inline-block;
            margin-bottom: 12px;
            background: rgba(255, 133, 10, 0.08);
            color: #ffae55;
            border-color: rgba(255, 166, 70, 0.3);
            box-shadow: none;
        }

        .scanner-panel {
            padding: 24px;
            margin: 24px 0;
            text-align: center;
        }

        .scanner-panel h3 {
            margin-top: 0;
        }

        #reader {
            width: 400px;
            max-width: 100%;
            margin: auto;
            color: #fff;
            background: #111;
            border-radius: 12px;
        }

        #reader a {
            color: #ffae55;
        }

        #reader button {
            margin: 6px;
            max-width: 100%;
            white-space: normal;
        }

        #reader select {
            max-width: 100%;
            padding: 8px;
            margin: 8px 0;
            color: #fff;
            background: #191919;
            border: 1px solid rgba(255, 166, 70, 0.4);
            border-radius: 8px;
        }

        .summary-panel {
            padding: 20px 24px;
            margin-bottom: 26px;
        }

        .summary-panel h2 {
            margin: 0 0 12px;
        }

        .summary-panel p {
            margin: 0;
            color: #eee7e0;
            line-height: 1.8;
        }

        .summary-panel strong {
            color: #ffae55;
        }

        .table-container {
            overflow-x: auto;
            border: 1px solid rgba(255, 166, 70, 0.3);
            border-radius: 14px;
            background: rgba(24, 21, 18, 0.88);
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 15px 12px;
            text-align: center;
            font-size: 14px;
            line-height: 1.6;
            border-bottom: 1px solid rgba(255, 255, 255, 0.09);
            overflow-wrap: anywhere;
        }

        th {
            background: rgba(255, 133, 10, 0.16);
            color: #ffba70;
        }

        td {
            color: #eee7e0;
        }

        tr:nth-child(even) td {
            background: rgba(255, 255, 255, 0.025);
        }

        tr:hover td {
            background: rgba(255, 133, 10, 0.055);
        }

        tr:last-child td {
            border-bottom: 0;
        }

        a:focus-visible,
        button:focus-visible,
        select:focus-visible {
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
                padding: 16px 10px;
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

            .section {
                padding: 24px 0;
            }

            .scanner-panel {
                padding: 20px 10px;
            }

            .event-card {
                max-width: 100%;
            }
        }
        #reader video {
    transform: scaleX(-1);
}
    </style>
    <link rel="stylesheet" href="assets/css/system-ui.css">
    <script src="js/system-ui.js" defer></script>
    <link rel="stylesheet" href="assets/css/glass.css">
    <link rel="stylesheet" href="assets/css/light-clean.css">
    <script src="js/glass-ui.js" defer></script>
</head>
<body class="app-page admin-page">
    <div class="sidebar">
        <h2><?php echo scannerEscape(ucfirst($role)); ?></h2>

        <a href="dashboard.php">Dashboard</a>
        <a href="officers.php">Officer Information</a>
        <a href="pending.php">Student Registered Pending</a>
        <a href="scanner.php">Event Attendance Scanner</a>
        <a href="attendance.php">Attendance List per Event</a>
        <a href="register_list.php">Register List</a>
        <a href="events.php">Event Management</a>

        <?php if (in_array(
            $role,
            ['admin_advisor', 'admin_adviser'],
            true
        )) { ?>
            <a href="accounts.php">Account Management</a>
        <?php } ?>

        <a href="logout.php">Logout</a>
    </div>

    <div class="content">
        <header>
            <h1>Event Attendance Scanner</h1>
        </header>

        <div class="section">
            <?php if ($successMessage !== '') { ?>
                <div class="success" role="status">
                    <?php echo scannerEscape($successMessage); ?>
                </div>
            <?php } ?>

            <?php if ($errorMessage !== '') { ?>
                <div class="error" role="alert">
                    <?php echo scannerEscape($errorMessage); ?>
                </div>
            <?php } ?>

            <?php if (!$hasEventID) { ?>
                <h2>Select Upcoming Event</h2>

                <div class="events">
                    <?php if ($upcoming->num_rows > 0) { ?>
                        <?php while ($row = $upcoming->fetch_assoc()) { ?>
                            <div class="event-card">
                                <img
                                    src="<?php echo scannerEscape(image_url($row['poster'])); ?>"
                                    alt="Poster">

                                <div>
                                    <h4><?php echo scannerEscape($row['title']); ?></h4>
                                    <p><?php echo scannerEscape($row['event_date']); ?></p>

                                    <a href="scanner.php?event_id=<?php echo (int) $row['id']; ?>">
                                        Open Scanner
                                    </a>
                                </div>
                            </div>
                        <?php } ?>
                    <?php } else { ?>
                        <p>No upcoming events available.</p>
                    <?php } ?>
                </div>

            <?php } elseif (!$event) { ?>
                <div class="error">
                    Event not found. Please select a valid event.
                </div>

                <a href="scanner.php" class="btn-back">
                    ← Back to Events
                </a>

            <?php } else { ?>
                <a href="scanner.php" class="btn-back">
                    ← Back to Events
                </a>

                <h2>
                    <?php echo scannerEscape($event['title']); ?>
                    (<?php echo scannerEscape($event['event_date']); ?>)
                </h2>

                <?php if ($event['status'] == 'done') { ?>
                    <div class="error">
                        This event is already marked as DONE.
                        Scanning disabled.
                    </div>
                <?php } else { ?>
                    <div class="scanner-panel">
                        <h3>Scan QR via Camera</h3>

                        <div id="scannerError" class="error" hidden></div>
                        <div id="reader"></div>

                        <form
                            method="POST"
                            id="scanForm"
                            style="display:none;">
                            <input
                                type="hidden"
                                name="qrdata"
                                id="qrdata">
                        </form>
                    </div>

                    <script>
                        let scanSubmitted = false;

                        function onScanSuccess(decodedText) {
                            if (scanSubmitted) {
                                return;
                            }

                            scanSubmitted = true;

                            document.getElementById("qrdata").value =
                                decodedText;

                            document.getElementById("scanForm").submit();
                        }

                        function showScannerError(message) {
                            const box =
                                document.getElementById("scannerError");

                            box.textContent = message;
                            box.hidden = false;
                        }

                        if (typeof Html5QrcodeScanner === "undefined") {
                            showScannerError(
                                "Scanner library failed to load. " +
                                "Check that js/html5-qrcode.min.js exists."
                            );
                        } else {
                            try {
                                const html5QrcodeScanner =
                                    new Html5QrcodeScanner(
                                        "reader",
                                        {
                                            fps: 10,
                                            qrbox: 250
                                        }
                                    );

                                html5QrcodeScanner.render(onScanSuccess);
                            } catch (error) {
                                console.error(error);

                                showScannerError(
                                    "Scanner could not start: " +
                                    (error.message || String(error))
                                );
                            }
                        }
                    </script>
                <?php } ?>

                <div class="summary-panel">
                    <h2>Summary</h2>

                    <p>
                        <strong>Registered:</strong>
                        <?php echo (int) $totalRegistered; ?>
                        |
                        <strong>Attended:</strong>
                        <?php echo (int) $totalAttended; ?>
                    </p>
                </div>

                <h2>Attendance List</h2>

                <div class="table-container">
                    <table>
                        <tr>
                            <th>Name</th>
                            <th>Student ID</th>
                            <th>Course / Year &amp; Section</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Time Attended</th>
                        </tr>

                        <?php if (
                            $attendance &&
                            $attendance->num_rows > 0
                        ) { ?>
                            <?php while ($row = $attendance->fetch_assoc()) { ?>
                                <tr>
                                    <td><?php echo scannerEscape($row['student_name']); ?></td>
                                    <td><?php echo scannerEscape($row['student_id']); ?></td>
                                    <td><?php echo scannerEscape($row['course_year_section']); ?></td>
                                    <td><?php echo scannerEscape($row['email']); ?></td>
                                    <td><?php echo scannerEscape($row['contact']); ?></td>
                                    <td><?php echo scannerEscape($row['scanned_at']); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="6">
                                    No attendance recorded yet.
                                </td>
                            </tr>
                        <?php } ?>
                    </table>
                </div>
            <?php } ?>
        </div>
    </div>
</body>
</html>
