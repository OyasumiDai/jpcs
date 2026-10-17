<?php
session_start();
include 'db.php';

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

// SAME SETTINGS AS YOUR WORKING registration.php
$gmailAddress = 'samanthayvette041@gmail.com';
$gmailAppPassword = 'qwxj ugjw wioo ofqn'; // Paste the working Gmail App Password here.

$gmailAppPassword = preg_replace('/\s+/', '', $gmailAppPassword);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

function pendingEscape($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Use the login username when available because old users may share ID 0.
if (!empty($_SESSION['username'])) {
    $stmt = $conn->prepare(
        'SELECT role, name FROM users WHERE username=? LIMIT 1'
    );
    $stmt->bind_param('s', $_SESSION['username']);
} else {
    $userID = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare(
        'SELECT role, name FROM users WHERE id=? LIMIT 1'
    );
    $stmt->bind_param('i', $userID);
}
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: login.php');
    exit();
}

$role = $user['role'];

if (empty($_SESSION['pending_csrf'])) {
    $_SESSION['pending_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['pending_csrf'];

$selectedEventID = isset($_GET['event_id'])
    ? filter_var($_GET['event_id'], FILTER_VALIDATE_INT)
    : null;

if ($selectedEventID === false) {
    $selectedEventID = null;
}

function pendingDirectory($directory) {
    if (!is_dir($directory) &&
        !mkdir($directory, 0755, true) &&
        !is_dir($directory)) {
        throw new RuntimeException('Cannot create folder: ' . basename($directory));
    }

    if (!is_writable($directory)) {
        throw new RuntimeException('Folder is not writable: ' . basename($directory));
    }
}

// Generate the existing personalized event pass.
function pendingPass($student) {
    $template = trim((string) ($student['pass_image'] ?? ''));

    if ($template === '') {
        return null;
    }

    $path = __DIR__ . '/' . ltrim($template, '/\\');

    if (!is_file($path)) {
        throw new RuntimeException('The uploaded event pass template is missing.');
    }

    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException(
            'PHP GD is required to generate the event pass.'
        );
    }

    $image = @imagecreatefromstring(file_get_contents($path));

    if ($image === false) {
        throw new RuntimeException('Cannot read the event pass image.');
    }

    try {
        $black = imagecolorallocate($image, 0, 0, 0);
        $font = null;

        foreach ([
            __DIR__ . '/fonts/Cartoon_Regular.ttf',
            __DIR__ . '/fonts/Cartoon_Regular'
        ] as $candidate) {
            if (is_file($candidate)) {
                $font = $candidate;
                break;
            }
        }

        $nameText = 'Name: ' . $student['student_name'];
        $controlText = 'Control No: ' . $student['control_number'];

        if ($font && function_exists('imagettftext')) {
            imagettftext($image, 20, 0, 50, 200, $black, $font, $nameText);
            imagettftext($image, 20, 0, 50, 240, $black, $font, $controlText);
        } else {
            imagestring($image, 5, 50, 180, $nameText, $black);
            imagestring($image, 5, 50, 220, $controlText, $black);
        }

        pendingDirectory(__DIR__ . '/passes');

        $output = __DIR__ . '/passes/pass_' . (int) $student['id'] . '.png';

        if (!imagepng($image, $output)) {
            throw new RuntimeException('Cannot save the generated event pass.');
        }

        return $output;
    } finally {
        imagedestroy($image);
    }
}

// Confirm, resend, and remove are POST actions.
// Refreshing the page will not repeat these actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = '';
    $messageType = 'error';

    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Session expired. Refresh and try again.');
        }

        $id = filter_var($_POST['registration_id'] ?? '', FILTER_VALIDATE_INT);
        $eventID = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT);
        $action = (string) ($_POST['action'] ?? '');

        if ($id === false || $id <= 0 ||
            $eventID === false ||
            !in_array($action, ['confirm', 'resend', 'remove'], true)) {
            throw new RuntimeException('Invalid registration action.');
        }

        $selectedEventID = $eventID;

        $stmt = $conn->prepare("
            SELECT r.*, e.title, e.event_date, e.pass_image,
                   e.status AS event_status
            FROM registrations r
            JOIN events e ON e.id=r.event_id
            WHERE r.id=? AND r.event_id=?
        ");
        $stmt->bind_param('ii', $id, $eventID);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$student) {
            throw new RuntimeException('Registration not found.');
        }

        if ($action === 'remove') {
            if ($student['status'] !== 'pending') {
                throw new RuntimeException(
                    'Only pending registrations can be removed here.'
                );
            }

            $stmt = $conn->prepare("
                DELETE FROM registrations
                WHERE id=? AND event_id=? AND status='pending'
            ");
            $stmt->bind_param('ii', $id, $eventID);
            $stmt->execute();
            $removed = $stmt->affected_rows;
            $stmt->close();

            if ($removed !== 1) {
                throw new RuntimeException('Registration was not removed.');
            }

            $message = 'Registration removed.';
            $messageType = 'success';
        } else {
            if ($action === 'confirm' && $student['status'] !== 'pending') {
                throw new RuntimeException(
                    'Already confirmed. Use Resend Email below.'
                );
            }

            if ($action === 'resend' && $student['status'] !== 'confirmed') {
                throw new RuntimeException('Confirm this registration first.');
            }

            if ($action === 'confirm' && $student['event_status'] !== 'upcoming') {
                throw new RuntimeException('This event is no longer upcoming.');
            }

            if (!filter_var($student['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(
                    'The student email address is invalid.'
                );
            }

            if ($gmailAppPassword === '' ||
                stripos($gmailAppPassword, 'YOUR') !== false ||
                stripos($gmailAppPassword, 'REPLACE') !== false) {
                throw new RuntimeException(
                    'Email is not configured. Put the working registration.php '
                    . 'Gmail App Password into $gmailAppPassword in pending.php.'
                );
            }

            if (!is_file(__DIR__ . '/phpqrcode/qrlib.php')) {
                throw new RuntimeException('Missing phpqrcode/qrlib.php.');
            }

            if (!function_exists('imagepng')) {
                throw new RuntimeException(
                    'Enable the PHP GD extension to generate QR images.'
                );
            }

            require_once __DIR__ . '/phpqrcode/qrlib.php';

            $controlNumber = trim((string) $student['control_number']);
            $needsNewControl = ($controlNumber === '');

            if ($controlNumber !== '') {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) AS total
                    FROM registrations
                    WHERE control_number=? AND id<>?
                ");
                $stmt->bind_param('si', $controlNumber, $id);
                $stmt->execute();
                $duplicateCount = (int) $stmt->get_result()
                    ->fetch_assoc()['total'];
                $stmt->close();

                $needsNewControl = $duplicateCount > 0;
            }

            if ($needsNewControl && $controlNumber !== '') {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) AS total
                    FROM attendance
                    WHERE event_id=? AND participant_id=?
                ");
                $stmt->bind_param('is', $eventID, $controlNumber);
                $stmt->execute();
                $attendanceCount = (int) $stmt->get_result()
                    ->fetch_assoc()['total'];
                $stmt->close();

                if ($attendanceCount > 0) {
                    throw new RuntimeException(
                        'This duplicate control number already has attendance. '
                        . 'Resolve that attendance before replacing the QR.'
                    );
                }
            }

            if ($needsNewControl) {
                do {
                    $controlNumber = 'EVT-' . $eventID . '-' . $id . '-'
                        . strtoupper(bin2hex(random_bytes(4)));

                    $stmt = $conn->prepare("
                        SELECT COUNT(*) AS total
                        FROM registrations WHERE control_number=?
                    ");
                    $stmt->bind_param('s', $controlNumber);
                    $stmt->execute();
                    $exists = (int) $stmt->get_result()->fetch_assoc()['total'];
                    $stmt->close();
                } while ($exists > 0);
            }

            $student['control_number'] = $controlNumber;

            pendingDirectory(__DIR__ . '/qrcodes');

            // Filename uses the unique registration ID.
            $qrFile = __DIR__ . '/qrcodes/registration_' . $id . '.png';

            QRcode::png($controlNumber, $qrFile, QR_ECLEVEL_L, 5);

            if (!is_file($qrFile) || filesize($qrFile) === 0) {
                throw new RuntimeException('QR image generation failed.');
            }

            $passFile = pendingPass($student);

            // Save confirmation before sending so the QR is valid on receipt.
            // If sending fails, the student stays visible in the resend table.
            if ($action === 'confirm') {
                $adminName = $user['name'];

                $stmt = $conn->prepare("
                    UPDATE registrations
                    SET status='confirmed', confirmed_by=?, control_number=?
                    WHERE id=? AND event_id=? AND status='pending'
                ");
                $stmt->bind_param(
                    'ssii', $adminName, $controlNumber, $id, $eventID
                );
            } else {
                $stmt = $conn->prepare("
                    UPDATE registrations SET control_number=?
                    WHERE id=? AND event_id=? AND status='confirmed'
                ");
                $stmt->bind_param('sii', $controlNumber, $id, $eventID);
            }

            $stmt->execute();

            if ($action === 'confirm' && $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException(
                    'Registration changed. Refresh before trying again.'
                );
            }

            $stmt->close();

            $mail = new PHPMailer(true);

            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = $gmailAddress;
                $mail->Password = $gmailAppPassword;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->CharSet = 'UTF-8';
                $mail->Timeout = 30;

                $mail->setFrom($gmailAddress, 'JPCS Malabon');
                $mail->addAddress(
                    $student['email'], $student['student_name']
                );

                $safeName = pendingEscape($student['student_name']);
                $safeTitle = pendingEscape($student['title']);
                $safeDate = pendingEscape($student['event_date']);
                $safeControl = pendingEscape($controlNumber);

                $passText = $passFile
                    ? 'Your QR code and event pass are attached.'
                    : 'Your QR code is attached.';

                $mail->isHTML(true);
                $mail->Subject = 'Registration Confirmed - ' . $student['title'];

                $mail->Body = "
                    <h3>Hi $safeName,</h3>
                    <p>Your registration for <b>$safeTitle</b>
                    on <b>$safeDate</b> is <b>CONFIRMED</b>.</p>
                    <p>Control Number: <b>$safeControl</b></p>
                    <p>$passText Please present your QR code
                    at the event entrance.</p>
                    <p>If you received an older QR code,
                    use the QR attached to this email.</p>
                    <p>Regards,<br>JPCS Malabon Admin</p>
                ";

                $mail->AltBody =
                    "Hi {$student['student_name']},\n\n"
                    . "Confirmed: {$student['title']}\n"
                    . "Date: {$student['event_date']}\n"
                    . "Control Number: $controlNumber\n\n"
                    . "$passText Use this email's QR at the entrance.\n\n"
                    . "JPCS Malabon Admin";

                $mail->addAttachment($qrFile, 'JPCS-QR-' . $id . '.png');

                if ($passFile) {
                    $mail->addAttachment($passFile, 'EventPass.png');
                }

                $mail->send();

                $message = 'Confirmation email sent to ' . $student['email']
                    . '. Control Number: ' . $controlNumber;
                $messageType = 'success';
            } catch (Throwable $mailError) {
                $details = $mail->ErrorInfo ?: $mailError->getMessage();

                // Do not display the App Password in an error.
                $details = str_replace(
                    [$gmailAppPassword, chunk_split($gmailAppPassword, 4, ' ')],
                    '[REDACTED]',
                    $details
                );

                error_log('JPCS confirmation email: ' . $details);

                $message = 'Registration is confirmed, but email failed. '
                    . 'Use Resend Email below after fixing this error. '
                    . 'Mailer Error: ' . $details;
            }
        }
    } catch (Throwable $error) {
        $message = $error->getMessage();
        error_log('JPCS pending: ' . $message);
    }

    $_SESSION['pending_notice'] = [
        'type' => $messageType,
        'message' => $message
    ];

    $redirect = 'pending.php';
    if ($selectedEventID !== null) {
        $redirect .= '?event_id=' . (int) $selectedEventID;
    }
    header('Location: ' . $redirect);
    exit();
}

$notice = $_SESSION['pending_notice'] ?? null;
unset($_SESSION['pending_notice']);

$events = $conn->query("
    SELECT id, title, event_date, poster, status
    FROM events WHERE status='upcoming'
    ORDER BY event_date DESC
");

$eventInfo = null;
$pendingStudents = [];
$confirmedStudents = [];
$pageError = '';

if ($selectedEventID !== null) {
    $stmt = $conn->prepare('SELECT * FROM events WHERE id=?');
    $stmt->bind_param('i', $selectedEventID);
    $stmt->execute();
    $eventInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($eventInfo) {
        $stmt = $conn->prepare("
            SELECT * FROM registrations
            WHERE event_id=?
            ORDER BY registered_at ASC, id ASC
        ");
        $stmt->bind_param('i', $selectedEventID);
        $stmt->execute();
        $rows = $stmt->get_result();

        while ($row = $rows->fetch_assoc()) {
            if ($row['status'] === 'pending') {
                $pendingStudents[] = $row;
            } elseif ($row['status'] === 'confirmed') {
                $confirmedStudents[] = $row;
            }
        }
        $stmt->close();
    } else {
        $pageError = 'Event not found. Please select another event.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Registered Pending</title>
    <style>
        * { box-sizing:border-box; }
        body {
            margin:0; min-height:100vh; display:flex;
            font-family:"Segoe UI",Arial,sans-serif; color:#fff;
            background:
                radial-gradient(ellipse at top right,rgba(255,125,0,.2),transparent 45%),
                radial-gradient(ellipse at bottom left,rgba(255,125,0,.14),transparent 45%),
                #090909;
            background-attachment:fixed;
        }
        .sidebar {
            width:220px; height:100vh; position:fixed;
            left:0; top:0; padding-top:20px; overflow-y:auto; z-index:20;
            background:
                radial-gradient(ellipse at bottom left,rgba(255,125,0,.23),transparent 60%),
                rgba(12,12,12,.94);
            border-right:1px solid rgba(255,152,31,.28);
            box-shadow:6px 0 30px rgba(0,0,0,.3);
            backdrop-filter:blur(16px);
            -webkit-backdrop-filter:blur(16px);
        }
        .sidebar h2 {
            text-align:center; margin-bottom:24px;
            color:#fff; font-size:27px; overflow-wrap:anywhere;
        }
        .sidebar h2::after {
            content:""; display:block; width:42px; height:3px;
            margin:12px auto 0; border-radius:20px; background:#ff850a;
            box-shadow:0 0 14px rgba(255,133,10,.65);
        }
        .sidebar a {
            display:block; margin:6px 10px; padding:12px 16px;
            color:#e5ded7; text-decoration:none; font-weight:600;
            font-size:14px; line-height:1.5; border-radius:10px;
        }
        .sidebar a[href="pending.php"] {
            color:#fff;
            background:linear-gradient(110deg,rgba(255,133,10,.35),rgba(255,133,10,.08));
            box-shadow:inset 3px 0 0 #ff850a,
                inset 0 0 0 1px rgba(255,133,10,.45),
                0 0 20px rgba(255,125,0,.12);
        }
        .sidebar a:hover { color:#fff; background:rgba(255,133,10,.18); }
        .content { margin-left:220px; padding:20px; flex:1; min-width:0; }
        header {
            padding:24px 20px; text-align:center;
            border:1px solid rgba(255,166,70,.3); border-radius:18px;
            background:
                radial-gradient(ellipse at bottom right,rgba(255,133,10,.2),transparent 65%),
                rgba(24,21,18,.88);
            box-shadow:0 12px 35px rgba(0,0,0,.25),
                inset 0 1px 0 rgba(255,255,255,.07);
            backdrop-filter:blur(16px);
        }
        header h1 {
            margin:0; color:#fff; font-size:clamp(25px,3vw,36px);
            font-weight:800; line-height:1.3;
        }
        .section { padding:24px 20px; }
        .section h2,.section h3 {
            color:#ffae55; line-height:1.5; overflow-wrap:anywhere;
        }
        .section h3 { margin:28px 0 20px; font-size:25px; }
        .section h3::after {
            content:""; display:block; width:45px; height:3px;
            margin-top:12px; border-radius:20px; background:#ff850a;
            box-shadow:0 0 14px rgba(255,133,10,.5);
        }
        .grid { display:flex; flex-wrap:wrap; gap:20px; justify-content:center; }
        .card {
            width:200px; border:1px solid rgba(255,166,70,.3);
            border-radius:16px; overflow:hidden; text-align:center;
            background:
                radial-gradient(ellipse at bottom right,rgba(255,133,10,.18),transparent 65%),
                rgba(25,21,18,.88);
            box-shadow:0 10px 28px rgba(0,0,0,.3);
        }
        .card:hover {
            border-color:rgba(255,152,31,.75);
            box-shadow:0 12px 32px rgba(0,0,0,.35),0 0 24px rgba(255,125,0,.15);
        }
        .card a { display:block; height:100%; color:#fff; text-decoration:none; }
        .card img { display:block; width:100%; height:250px; object-fit:cover; }
        .card h4 { margin:16px 12px; line-height:1.6; overflow-wrap:anywhere; }
        .event-date { color:#ffae55; font-size:13px; font-weight:400; }
        .table-container {
            margin-top:20px; overflow-x:auto;
            border:1px solid rgba(255,166,70,.3); border-radius:14px;
            background:rgba(24,21,18,.88);
            box-shadow:0 12px 35px rgba(0,0,0,.25);
        }
        table { width:100%; min-width:850px; border-collapse:collapse; }
        th,td {
            padding:15px 12px; text-align:center; font-size:14px; line-height:1.6;
            border-bottom:1px solid rgba(255,255,255,.09); overflow-wrap:anywhere;
        }
        th { color:#ffba70; background:rgba(255,133,10,.16); font-weight:700; }
        td { color:#eee7e0; }
        tr:nth-child(even) td { background:rgba(255,255,255,.025); }
        tr:hover td { background:rgba(255,133,10,.055); }
        tr:last-child td { border-bottom:0; }
        .actions { display:flex; justify-content:center; gap:8px; flex-wrap:wrap; }
        .actions form { margin:0; }
        .btn-confirm,.btn-remove,.btn-back {
            display:inline-block; padding:9px 15px; border-radius:9px;
            text-decoration:none; font:650 13px/1.5 "Segoe UI",Arial,sans-serif;
            cursor:pointer;
        }
        .btn-confirm {
            color:#17100a; background:linear-gradient(135deg,#ffa52f,#ff7900);
            border:1px solid rgba(255,184,107,.5);
            box-shadow:0 4px 15px rgba(255,125,0,.16);
        }
        .btn-confirm:hover { background:linear-gradient(135deg,#ffb64c,#ff921a); }
        .btn-remove {
            color:#fff; background:rgba(255,255,255,.04);
            border:1px solid rgba(255,255,255,.3);
        }
        .btn-remove:hover {
            border-color:#ffae55; background:rgba(255,133,10,.12);
        }
        .btn-back {
            color:#ffae55; background:rgba(255,133,10,.08);
            border:1px solid rgba(255,166,70,.3); margin-bottom:15px;
        }
        .btn-back:hover { background:rgba(255,133,10,.18); }
        .success,.error {
            margin:20px 0 15px; padding:14px 18px;
            color:#fff; background:rgba(255,133,10,.12);
            border:1px solid rgba(255,166,70,.5); border-radius:12px;
            line-height:1.7; overflow-wrap:anywhere;
        }
        .error { border-width:2px; }
        .empty-state,.hint { color:#d3cac1; line-height:1.7; }
        .empty-state { text-align:center; padding:20px; }
        a:focus-visible,button:focus-visible {
            outline:3px solid #ffae55; outline-offset:4px;
        }
        @media(max-width:700px) {
            body { display:block; }
            .sidebar {
                position:relative; width:100%; height:auto; padding:16px 10px;
                border-right:0; border-bottom:1px solid rgba(255,152,31,.28);
            }
            .sidebar h2 { margin:0 0 16px; }
            .sidebar a {
                display:inline-block; margin:4px; padding:10px 12px; font-size:13px;
            }
            .content { margin-left:0; padding:16px; }
            .section { padding:24px 0; }
            .card { max-width:100%; }
        }
    </style>
</head>
<body>
<div class="sidebar">
    <h2><?= pendingEscape(ucfirst($role)) ?></h2>
    <a href="dashboard.php">Dashboard</a>
    <a href="officers.php">Officer Information</a>
    <a href="pending.php">Student Registered Pending</a>
    <a href="scanner.php">Event Attendance Scanner</a>
    <a href="attendance.php">Attendance List per Event</a>
    <a href="register_list.php">Register List</a>
    <a href="events.php">Event Management</a>
    <?php if (in_array($role, ['admin_adviser', 'admin_advisor'], true)) { ?>
        <a href="accounts.php">Account Management</a>
    <?php } ?>
    <a href="logout.php">Logout</a>
</div>

<div class="content">
    <header><h1>Student Registered Pending</h1></header>

    <?php if ($notice) { ?>
        <div class="<?= pendingEscape($notice['type']) ?>" role="status">
            <?= pendingEscape($notice['message']) ?>
        </div>
    <?php } ?>

    <?php if ($pageError !== '') { ?>
        <div class="error" role="alert"><?= pendingEscape($pageError) ?></div>
    <?php } ?>

    <div class="section">
    <?php if (!$eventInfo) { ?>
        <h3>Select Event</h3>
        <div class="grid">
        <?php if ($events->num_rows > 0) { ?>
            <?php while ($row = $events->fetch_assoc()) { ?>
                <div class="card">
                    <a href="pending.php?event_id=<?= (int) $row['id'] ?>">
                        <img src="<?= pendingEscape(
                            $row['poster'] ?: 'uploads/events/default.jpg'
                        ) ?>" alt="Event Poster">
                        <h4>
                            <?= pendingEscape($row['title']) ?><br>
                            <span class="event-date">
                                (<?= pendingEscape($row['event_date']) ?>)
                            </span>
                        </h4>
                    </a>
                </div>
            <?php } ?>
        <?php } else { ?>
            <p class="empty-state">No upcoming events available.</p>
        <?php } ?>
        </div>
    <?php } else { ?>
        <a href="pending.php" class="btn-back">← Back to Events</a>

        <h2><?= pendingEscape(
            $eventInfo['title'] . ' (' . $eventInfo['event_date'] . ')'
        ) ?></h2>

        <h3>Pending Registrations</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Name</th><th>Student ID</th>
                        <th>Course / Year &amp; Section</th>
                        <th>Email</th><th>Contact</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingStudents as $row) { ?>
                    <tr>
                        <td><?= pendingEscape($row['student_name']) ?></td>
                        <td><?= pendingEscape($row['student_id']) ?></td>
                        <td><?= pendingEscape($row['course_year_section']) ?></td>
                        <td><?= pendingEscape($row['email']) ?></td>
                        <td><?= pendingEscape($row['contact']) ?></td>
                        <td>
                            <div class="actions">
                                <form method="POST"
                                      onsubmit="return confirm('Confirm this registration and send email?')">
                                    <input type="hidden" name="csrf"
                                           value="<?= pendingEscape($csrf) ?>">
                                    <input type="hidden" name="event_id"
                                           value="<?= (int) $row['event_id'] ?>">
                                    <input type="hidden" name="registration_id"
                                           value="<?= (int) $row['id'] ?>">
                                    <button name="action" value="confirm"
                                            class="btn-confirm">Confirm</button>
                                </form>

                                <form method="POST"
                                      onsubmit="return confirm('Remove this registration?')">
                                    <input type="hidden" name="csrf"
                                           value="<?= pendingEscape($csrf) ?>">
                                    <input type="hidden" name="event_id"
                                           value="<?= (int) $row['event_id'] ?>">
                                    <input type="hidden" name="registration_id"
                                           value="<?= (int) $row['id'] ?>">
                                    <button name="action" value="remove"
                                            class="btn-remove">Remove</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$pendingStudents) { ?>
                    <tr><td colspan="6">No pending registrations for this event.</td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>

        <h3>Confirmed Registrations</h3>
        <p class="hint">
            If a confirmation email failed, use Resend Email.
            Students do not need to register again.
        </p>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Name</th><th>Student ID</th>
                        <th>Course / Year &amp; Section</th>
                        <th>Email</th><th>Control Number</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($confirmedStudents as $row) { ?>
                    <tr>
                        <td><?= pendingEscape($row['student_name']) ?></td>
                        <td><?= pendingEscape($row['student_id']) ?></td>
                        <td><?= pendingEscape($row['course_year_section']) ?></td>
                        <td><?= pendingEscape($row['email']) ?></td>
                        <td><?= pendingEscape($row['control_number']) ?></td>
                        <td>
                            <form method="POST"
                                  onsubmit="return confirm('Resend the confirmation email to this student?')">
                                <input type="hidden" name="csrf"
                                       value="<?= pendingEscape($csrf) ?>">
                                <input type="hidden" name="event_id"
                                       value="<?= (int) $row['event_id'] ?>">
                                <input type="hidden" name="registration_id"
                                       value="<?= (int) $row['id'] ?>">
                                <button name="action" value="resend"
                                        class="btn-confirm">Resend Email</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$confirmedStudents) { ?>
                    <tr><td colspan="6">No confirmed registrations yet.</td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
    </div>
</div>
</body>
</html>