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

if (empty($_SESSION['scanner_csrf'])) {
    $_SESSION['scanner_csrf'] = bin2hex(random_bytes(32));
}
$scanNotice = $_SESSION['scanner_notice'] ?? [];
unset($_SESSION['scanner_notice']);
$successMessage = $scanNotice['success'] ?? '';
$errorMessage = $scanNotice['error'] ?? '';

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

    if (!is_string($_POST['csrf'] ?? null) ||
        !hash_equals($_SESSION['scanner_csrf'], $_POST['csrf'])) {
        $errorMessage = "Session expired. Refresh and try again.";
    } elseif ($event['status'] == 'done') {
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
                        "Attendance recorded for " . $student['student_name'] . ".";
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $event) {
    $_SESSION['scanner_notice'] = ['success' => $successMessage, 'error' => $errorMessage];
    header('Location: scanner.php?event_id=' . (int) $eventID);
    exit;
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
        ORDER BY a.scanned_at DESC, a.id DESC
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
<link rel="icon" type="image/png" href="assets/img/jpcs-logo.png?v=3">

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event Attendance Scanner</title>

    <script src="js/html5-qrcode.min.js" type="text/javascript"></script>


<?php require __DIR__.'/includes/admin_head.php'; ?>
</head>
<body class="app-page admin-page feature-scanner">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

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
                            id="scanForm">
                            <input type="hidden" name="csrf" value="<?= scannerEscape($_SESSION['scanner_csrf']) ?>">
                            <p><label for="qrdata">Manual entry / USB scanner control number</label></p>
                            <input
                                type="text"
                                name="qrdata"
                                id="qrdata" required maxlength="50" autocomplete="off"
                                placeholder="Control number on the event pass">
                            <button type="submit" class="btn-back">Record attendance</button>
                            <p>Use manual entry if the camera is unavailable. Only confirmed registrations for this event are accepted.</p>
                        </form>
                    </div>

                    <script>
                        let scanSubmitted = false;
                        document.getElementById("scanForm").addEventListener("submit", function () {
                            scanSubmitted = true;
                            this.querySelector('button[type="submit"]').disabled = true;
                        });

                        function onScanSuccess(decodedText) {
                            if (scanSubmitted) {
                                return;
                            }

                            document.getElementById("qrdata").value =
                                decodedText;

                            document.getElementById("scanForm").requestSubmit();
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

                <h2>Latest scans</h2>
                <p><?= (int) $totalAttended ?> recorded scans, newest first. Refresh to include scans from other devices.</p>
                <a class="btn-back" href="scanner.php?event_id=<?= (int) $eventID ?>">Refresh scans</a>
                <a class="btn-back" href="attendance_event.php?event_id=<?= (int) $eventID ?>">Attendance report</a>

                <div class="table-container">
                    <table data-admin-table aria-label="Recorded attendance, newest first">
                        <thead><tr>
                            <th>Name</th>
                            <th>Student ID</th>
                            <th>Course / Year &amp; Section</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Time Attended</th>
                        </tr></thead>
                        <tbody>

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
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        </div>
    </div>
</body>
</html>
