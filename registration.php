<?php
require_once __DIR__ . '/includes/student_auth.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/masterlist.php';
require_once __DIR__ . '/includes/registration_confirm.php';

$registrationUser = current_user();
$registrationPanelAccess = !empty($registrationUser['id'])
    && in_array((string) ($registrationUser['role'] ?? ''), ['officer', 'admin_advisor', 'admin_adviser'], true);
if (current_student() === null && !$registrationPanelAccess) {
    redirect('student_login.php');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

/*
 * EMAIL CONFIGURATION
 * Ilagay ang generated Gmail App Password sa loob ng quotes.
 * Dapat galing ito sa account na nasa $gmailAddress.
 */
['address' => $gmailAddress, 'app_password' => $gmailAppPassword] = jpcs_mail_config();

$successMessage = '';
$errorMessage = '';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$values = [
    'event_id' => (
        isset($_GET['event_id']) && is_string($_GET['event_id'])
    ) ? $_GET['event_id'] : '',
    'surname' => '',
    'given_name' => '',
    'middle_initial' => '',
    'student_id' => '',
    'course_year_section' => '',
    'email' => '',
    'contact' => ''
];

$courseOptions = masterlist_section_groups();

$allowedCourses = [];

foreach ($courseOptions as $year => $sections) {
    foreach ($sections as $section) {
        $allowedCourses[] = $year . ' - ' . $section;
    }
}

// A signed-in student's form starts from their own masterlist entry.
$registrationStudent = current_student();
$masterlistNotice = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $registrationStudent !== null) {
    $values['student_id'] = $registrationStudent['student_number'];
    $ownEntry = masterlist_find($conn, $registrationStudent['student_number']);
    if ($ownEntry) {
        $values = array_replace($values, masterlist_split_name($ownEntry['student_name']));
        if (in_array($ownEntry['course_year_section'], $allowedCourses, true)) {
            $values['course_year_section'] = $ownEntry['course_year_section'];
        }
        $masterlistNotice = 'Your details were filled in from the JPCS masterlist. Registrations from listed students are confirmed automatically.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    foreach ($values as $key => $value) {
        $values[$key] = (
            isset($_POST[$key]) && is_string($_POST[$key])
        ) ? trim($_POST[$key]) : '';
    }

    $event_id = filter_var($values['event_id'], FILTER_VALIDATE_INT);
    $surname = $values['surname'];
    $given_name = $values['given_name'];
    $middle_initial = strtoupper(
        trim($values['middle_initial'], " .\t\n\r")
    );
    $student_id = $values['student_id'];
    $course_year_section = $values['course_year_section'];
    $email = $values['email'];
    $contact = $values['contact'];

    // Preserve student_name for compatibility with your other pages.
    $student_name = $surname . ', ' . $given_name;

    if ($middle_initial !== '') {
        $student_name .= ' ' . $middle_initial . '.';
    }

    if (
        $event_id === false ||
        $event_id <= 0 ||
        $surname === '' ||
        $given_name === '' ||
        $student_id === '' ||
        $contact === ''
    ) {
        $errorMessage = 'Please complete all required fields.';
    } elseif (
        $middle_initial !== '' &&
        !preg_match('/^[A-Za-z]$/', $middle_initial)
    ) {
        $errorMessage =
            'Enter one letter for the middle initial, or leave it blank.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Please enter a valid email address.';
    } elseif (!in_array($course_year_section, $allowedCourses, true)) {
        $errorMessage = 'Please select a valid course, year, and section.';
    } elseif (
        $gmailAppPassword === '' ||
        stripos($gmailAppPassword, 'YOUR_') !== false
    ) {
        $errorMessage =
            'Email is not configured. Set the Gmail App Password in '
            . 'registration.php. No registration was added.';
    } else {
        $saved = false;
        $event = null;

        try {
            $stmt = $conn->prepare(
                "SELECT title, event_date
                 FROM events
                 WHERE id = ? AND status = 'upcoming'"
            );

            if (!$stmt) {
                throw new \RuntimeException($conn->error);
            }

            $stmt->bind_param('i', $event_id);
            $stmt->execute();
            $event = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$event) {
                $errorMessage = 'Please select an available upcoming event.';
            } else {
                // Avoid adding the same student again for the same event.
                $stmt = $conn->prepare(
                    'SELECT id
                     FROM registrations
                     WHERE event_id = ? AND student_id = ?
                     LIMIT 1'
                );

                if (!$stmt) {
                    throw new \RuntimeException($conn->error);
                }

                $stmt->bind_param('is', $event_id, $student_id);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($existing) {
                    $errorMessage =
                        'This student is already registered for this event. '
                        . 'Please check Student Registered Pending; '
                        . 'no duplicate registration was added.';
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO registrations
                         (
                             event_id,
                             student_name,
                             student_id,
                             course_year_section,
                             email,
                             contact,
                             status
                         )
                         VALUES (?, ?, ?, ?, ?, ?, 'pending')"
                    );

                    if (!$stmt) {
                        throw new \RuntimeException($conn->error);
                    }

                    $stmt->bind_param(
                        'isssss',
                        $event_id,
                        $student_name,
                        $student_id,
                        $course_year_section,
                        $email,
                        $contact
                    );

                    $saved = $stmt->execute();
                    $newRegistrationId = (int) $stmt->insert_id;
                    $stmt->close();

                    if (!$saved) {
                        throw new \RuntimeException(
                            'Registration insert failed.'
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('Registration database error: ' . $e->getMessage());

            $errorMessage =
                'Registration could not be saved. Please check the '
                . 'database configuration.';
        }

        // Listed students are confirmed right away: only for the signed-in student's own ID,
        // or when an officer/adviser registers them, so nobody can confirm someone else's ID.
        $autoConfirmed = false;
        if ($saved && $event && masterlist_find($conn, $student_id) !== null) {
            $ownId = $registrationStudent !== null
                && masterlist_normalize_id($registrationStudent['student_number']) === masterlist_normalize_id($student_id);
            if ($ownId || $registrationPanelAccess) {
                try {
                    $confirmation = jpcs_confirm_registration($conn, $newRegistrationId, (int) $event_id, 'confirm', 'Masterlist (auto)');
                    $autoConfirmed = true;
                    if ($confirmation['type'] === 'success') {
                        $successMessage = 'You are on the JPCS masterlist, so your registration is confirmed. '
                            . 'Your QR code was emailed to ' . $email . '.';
                    } else {
                        $successMessage = 'You are on the JPCS masterlist, so your registration is confirmed. '
                            . 'Control Number: ' . $confirmation['control_number'] . '.';
                        $errorMessage = 'The QR email could not be sent yet; an officer can resend it from Student Registered Pending.';
                    }
                } catch (\Throwable $confirmError) {
                    error_log('JPCS masterlist auto-confirm: ' . $confirmError->getMessage());
                }
            }
        }
        if ($autoConfirmed) {
            foreach ($values as $key => $value) {
                $values[$key] = '';
            }
        } elseif ($saved && $event) {
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

                $mail->setFrom($gmailAddress, 'JPCS-CMU Chapter');
                $mail->addAddress($email, $student_name);
                $mail->isHTML(true);

                $eventTitle = $event['title'];
                $eventDate = $event['event_date'];

                $safeName = h($student_name);
                $safeTitle = h($eventTitle);
                $safeDate = h($eventDate);

                $mail->Subject = "Registration Received - $eventTitle";

                $mail->Body = "
                    <h3>Hi $safeName,</h3>
                    <p>
                        We received your registration for
                        <b>$safeTitle</b> scheduled on
                        <b>$safeDate</b>.
                    </p>
                    <p>
                        Your status is currently <b>Pending</b>.
                        Please wait for confirmation from the admin.
                    </p>
                    <p>Regards,<br>JPCS-CMU Chapter</p>
                ";

                $mail->AltBody =
                    "Hi $student_name,\n\n"
                    . "We received your registration for $eventTitle "
                    . "scheduled on $eventDate.\n\n"
                    . "Your status is Pending. Please wait for "
                    . "confirmation from the admin.\n\n"
                    . 'JPCS-CMU Chapter';

                $mail->send();

                $successMessage =
                    "Registration successful! Email sent to $email.";
            } catch (Exception $e) {
                $successMessage =
                    'Registration saved successfully. Status: Pending.';

                $detail = $mail->ErrorInfo ?: $e->getMessage();

                error_log('JPCS mail error: ' . $detail);

                if (stripos($detail, 'authenticate') !== false) {
                    $errorMessage =
                        'Gmail rejected the sender login. Check that '
                        . 'the App Password was generated from '
                        . $gmailAddress
                        . '. Your registration is already saved; '
                        . 'do not register again.';
                } else {
                    $errorMessage =
                        'Email could not be sent. Mailer Error: '
                        . $detail
                        . ' Your registration is already saved.';
                }
            }

            foreach ($values as $key => $value) {
                $values[$key] = '';
            }
        }
    }
}

$events = $conn->query(
    "SELECT *
     FROM events
     WHERE status = 'upcoming'
     ORDER BY event_date ASC"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event Registration</title>
    <link rel="icon" type="image/png" href="assets/img/jpcs-logo.png">
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme-init.js"></script>
    <link rel="stylesheet" href="assets/css/navbar.css">

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
            color-scheme: dark;
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
            z-index: 10;
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
            margin-bottom: 20px;
            color: #fff;
            font-size: 32px;
            font-weight: 800;
            letter-spacing: 2px;
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
            padding: 12px 20px;
            color: #e5ded7;
            text-decoration: none;
            font-weight: 600;
            line-height: 1.5;
            border-radius: 10px;
        }

        .sidebar a[href="registration.php"] {
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

        header {
            padding: 15px;
            text-align: center;
            border-radius: 16px;
            border: 1px solid rgba(255, 166, 70, 0.3);
            background:
                radial-gradient(
                    ellipse at top right,
                    rgba(255, 133, 10, 0.24),
                    transparent 65%
                ),
                rgba(22, 19, 16, 0.85);
            box-shadow:
                0 12px 35px rgba(0, 0, 0, 0.25),
                inset 0 1px 0 rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        header h1 {
            color: #fff;
            font-weight: 800;
            line-height: 1.3;
        }

        header h2 {
            color: #d3cac1;
            font-weight: 400;
        }

        .section {
            padding: 40px 20px;
            text-align: center;
        }

        .section h3 {
            color: #ffae55;
            font-size: 2em;
            margin: 0 0 28px;
        }

        .section h3::after {
            content: "";
            display: block;
            width: 50px;
            height: 3px;
            margin: 14px auto 0;
            border-radius: 20px;
            background: #ff850a;
            box-shadow: 0 0 15px rgba(255, 133, 10, 0.55);
        }

        form {
            max-width: 600px;
            margin: auto;
            padding: 30px;
            text-align: left;
            border: 1px solid rgba(255, 166, 70, 0.35);
            border-radius: 20px;
            background:
                radial-gradient(
                    ellipse at top right,
                    rgba(255, 133, 10, 0.14),
                    transparent 55%
                ),
                radial-gradient(
                    ellipse at bottom left,
                    rgba(255, 133, 10, 0.1),
                    transparent 55%
                ),
                rgba(24, 21, 18, 0.9);
            box-shadow:
                0 18px 45px rgba(0, 0, 0, 0.35),
                0 0 28px rgba(255, 125, 0, 0.07);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        .field {
            margin-top: 18px;
            min-width: 0;
        }

        form > .field:first-child {
            margin-top: 0;
        }

        label {
            display: block;
            color: #eee7e0;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.5;
        }

        input,
        select {
            width: 100%;
            min-height: 46px;
            padding: 11px 13px;
            margin-top: 8px;
            font-family: inherit;
            font-size: 15px;
            color: #fff;
            background: rgba(8, 8, 8, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.17);
            border-radius: 10px;
        }

        input:focus,
        select:focus {
            outline: 2px solid #ff981f;
            outline-offset: 2px;
            border-color: #ff981f;
            box-shadow: 0 0 18px rgba(255, 133, 10, 0.12);
        }

        select option,
        select optgroup {
            background: #191919;
            color: #fff;
        }

        .name-fields {
            display: grid;
            grid-template-columns: 1fr 1fr 100px;
            gap: 14px;
        }

        .hint {
            margin: 10px 0 0;
            color: #c7bfb7;
            font-size: 12px;
            line-height: 1.6;
        }

        .content form button {
            width: 100%;
            min-height: 48px;
            margin-top: 26px;
            padding: 12px 20px;
            font-family: inherit;
            font-size: 16px;
            font-weight: 750;
            color: #17100a;
            background: linear-gradient(135deg, #ffa52f, #ff7900);
            border: 1px solid rgba(255, 184, 107, 0.5);
            border-radius: 11px;
            cursor: pointer;
            box-shadow: 0 6px 22px rgba(255, 115, 0, 0.24);
        }

        .content form button:hover {
            background: linear-gradient(135deg, #ffb64c, #ff921a);
            box-shadow: 0 6px 30px rgba(255, 133, 10, 0.4);
        }

        a:focus-visible,
        .content form button:focus-visible {
            outline: 3px solid #ffae55;
            outline-offset: 4px;
        }

        .success,
        .error {
            max-width: 600px;
            margin: 0 auto 18px;
            padding: 14px 18px;
            border-radius: 12px;
            text-align: center;
            font-weight: 600;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .success {
            color: #fff;
            background: rgba(255, 133, 10, 0.14);
            border: 1px solid rgba(255, 166, 70, 0.5);
        }

        .error {
            color: #fff;
            background: #24201d;
            border: 2px solid #ffae55;
        }

        footer {
            margin-top: 40px;
            padding: 15px;
            color: #b5ada5;
            text-align: center;
            font-size: 13px;
            line-height: 1.7;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
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

            header h1 {
                font-size: 25px;
            }

            header h2 {
                font-size: 17px;
            }

            .section {
                padding: 30px 0;
            }

            .section h3 {
                font-size: 26px;
            }

            form {
                padding: 24px 20px;
            }

            .name-fields {
                grid-template-columns: 1fr;
                gap: 0;
            }

            input,
            select {
                font-size: 16px;
            }
        }
        .sidebar { display: none !important; }
        .content { margin-left: 0 !important; width: 100%; padding-top: 100px !important; }
    </style>
    <link rel="stylesheet" href="assets/css/system-ui.css">
    <script src="js/system-ui.js" defer></script>
    <link rel="stylesheet" href="assets/css/glass.css">
    <link rel="stylesheet" href="assets/css/light-clean.css">
    <link rel="stylesheet" href="assets/css/glass-theme.css?v=<?= filemtime(__DIR__ . '/assets/css/glass-theme.css') ?>">
    <script src="js/glass-theme.js?v=<?= filemtime(__DIR__ . '/js/glass-theme.js') ?>" defer></script>
    <script src="js/glass-ui.js" defer></script>
</head>
<body class="app-page student-page">
    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <div class="content">
        <header>
            <h1>Junior Philippine Computer Society</h1>
            <h2>City of Malabon University</h2>
        </header>

        <div class="section">
            <h3>Event Registration Form</h3>

            <?php if ($successMessage !== '') { ?>
                <div class="success" role="status">
                    <?php echo h($successMessage); ?>
                </div>
            <?php } ?>

            <?php if ($masterlistNotice !== '' && $successMessage === '') { ?>
                <div class="masterlist-notice" role="status"><?php echo h($masterlistNotice); ?></div>
            <?php } ?>

            <?php if ($errorMessage !== '') { ?>
                <div class="error" role="alert">
                    <?php echo h($errorMessage); ?>
                </div>
            <?php } ?>

            <form method="POST">
                <div class="field">
                    <label for="event_id">Select Upcoming Event:</label>
                    <select id="event_id" name="event_id" required>
                        <option value="">-- Choose Event --</option>

                        <?php while ($row = $events->fetch_assoc()) { ?>
                            <option
                                value="<?php echo h($row['id']); ?>"
                                <?php
                                if (
                                    (string)$values['event_id'] ===
                                    (string)$row['id']
                                ) {
                                    echo 'selected';
                                }
                                ?>>
                                <?php
                                echo h(
                                    $row['title'] .
                                    ' (' . $row['event_date'] . ')'
                                );
                                ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="name-fields">
                    <div class="field">
                        <label for="surname">Surname:</label>
                        <input
                            type="text"
                            id="surname"
                            name="surname"
                            autocomplete="family-name"
                            value="<?php echo h($values['surname']); ?>"
                            required>
                    </div>

                    <div class="field">
                        <label for="given_name">Given Name:</label>
                        <input
                            type="text"
                            id="given_name"
                            name="given_name"
                            autocomplete="given-name"
                            value="<?php echo h($values['given_name']); ?>"
                            required>
                    </div>

                    <div class="field">
                        <label for="middle_initial">Middle Initial:</label>
                        <input
                            type="text"
                            id="middle_initial"
                            name="middle_initial"
                            maxlength="2"
                            pattern="[A-Za-z][.]?"
                            aria-describedby="middle_hint"
                            value="<?php echo h($values['middle_initial']); ?>">
                    </div>
                </div>

                <p class="hint" id="middle_hint">
                    Middle initial is optional. Enter one letter, such as R.
                </p>

                <div class="field">
                    <label for="student_id">Student Number / ID:</label>
                    <input
                        type="text"
                        id="student_id"
                        name="student_id"
                        value="<?php echo h($values['student_id']); ?>"
                        required>
                </div>

                <div class="field">
                    <label for="course_year_section">
                        Course / Year &amp; Section:
                    </label>

                    <select
                        id="course_year_section"
                        name="course_year_section"
                        required>
                        <option value="">
                            -- Select Course / Year &amp; Section --
                        </option>

                        <?php foreach ($courseOptions as $year => $sections) { ?>
                            <optgroup label="<?php echo h($year); ?>">
                                <?php foreach ($sections as $section) {
                                    $courseValue = $year . ' - ' . $section;
                                    $courseLabel =
                                        str_replace('BSIT ', '', $year) .
                                        ' - ' . $section;
                                ?>
                                    <option
                                        value="<?php echo h($courseValue); ?>"
                                        <?php
                                        if (
                                            $values['course_year_section'] ===
                                            $courseValue
                                        ) {
                                            echo 'selected';
                                        }
                                        ?>>
                                        <?php echo h($courseLabel); ?>
                                    </option>
                                <?php } ?>
                            </optgroup>
                        <?php } ?>
                    </select>
                </div>

                <div class="field">
                    <label for="email">Personal Email:</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        autocomplete="email"
                        value="<?php echo h($values['email']); ?>"
                        required>
                </div>

                <div class="field">
                    <label for="contact">Contact Number:</label>
                    <input
                        type="text"
                        id="contact"
                        name="contact"
                        autocomplete="tel"
                        value="<?php echo h($values['contact']); ?>"
                        required>
                </div>

                <button type="submit" name="register">Register</button>
            </form>
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
