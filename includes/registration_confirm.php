<?php
declare(strict_types=1);

// Confirming a registration: control number, QR code, personalised pass, status change
// and confirmation email. Shared by pending review and masterlist auto-confirmation.

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/mail_config.php';
require_once dirname(__DIR__) . '/PHPMailer/src/Exception.php';
require_once dirname(__DIR__) . '/PHPMailer/src/PHPMailer.php';
require_once dirname(__DIR__) . '/PHPMailer/src/SMTP.php';

function jpcs_ensure_directory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create folder: ' . basename($directory));
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('Folder is not writable: ' . basename($directory));
    }
}

// Generate the existing personalized event pass.
function jpcs_registration_pass(array $student): ?string
{
    $template = trim((string) ($student['pass_image'] ?? ''));
    if ($template === '') {
        return null;
    }
    $root = dirname(__DIR__);
    $path = $root . '/' . ltrim($template, '/\\');
    if (!is_file($path)) {
        throw new RuntimeException('The uploaded event pass template is missing.');
    }
    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException('PHP GD is required to generate the event pass.');
    }
    $image = @imagecreatefromstring(file_get_contents($path));
    if ($image === false) {
        throw new RuntimeException('Cannot read the event pass image.');
    }
    try {
        $black = imagecolorallocate($image, 0, 0, 0);
        $font = null;
        foreach ([$root . '/fonts/Cartoon_Regular.ttf', $root . '/fonts/Cartoon_Regular'] as $candidate) {
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
        jpcs_ensure_directory($root . '/passes');
        $output = $root . '/passes/pass_' . (int) $student['id'] . '.png';
        if (!imagepng($image, $output)) {
            throw new RuntimeException('Cannot save the generated event pass.');
        }
        return $output;
    } finally {
        imagedestroy($image);
    }
}

/**
 * Confirm a pending registration ($action 'confirm') or re-send a confirmed one ('resend').
 * Throws RuntimeException when nothing was changed. When the status was saved but the
 * email failed, returns type 'error' with the mailer detail so it can be re-sent later.
 * @return array{type:string,message:string,control_number:string}
 */
function jpcs_confirm_registration(mysqli $conn, int $id, int $eventID, string $action, string $confirmedBy): array
{
    if (!in_array($action, ['confirm', 'resend'], true)) {
        throw new RuntimeException('Invalid registration action.');
    }
    $mailConfig = jpcs_mail_config();
    $gmailAddress = $mailConfig['address'];
    $gmailAppPassword = $mailConfig['app_password'];

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
    if ($action === 'confirm' && $student['status'] !== 'pending') {
        throw new RuntimeException('Already confirmed. Use Resend Email below.');
    }
    if ($action === 'resend' && $student['status'] !== 'confirmed') {
        throw new RuntimeException('Confirm this registration first.');
    }
    if ($action === 'confirm' && $student['event_status'] !== 'upcoming') {
        throw new RuntimeException('This event is no longer upcoming.');
    }
    if (!filter_var($student['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The student email address is invalid.');
    }
    if ($gmailAppPassword === '' || stripos($gmailAppPassword, 'YOUR') !== false || stripos($gmailAppPassword, 'REPLACE') !== false) {
        throw new RuntimeException('Email is not configured. Set the Gmail App Password in includes/mail_config.php.');
    }
    $root = dirname(__DIR__);
    if (!is_file($root . '/phpqrcode/qrlib.php')) {
        throw new RuntimeException('Missing phpqrcode/qrlib.php.');
    }
    if (!function_exists('imagepng')) {
        throw new RuntimeException('Enable the PHP GD extension to generate QR images.');
    }
    require_once $root . '/phpqrcode/qrlib.php';

    $controlNumber = trim((string) $student['control_number']);
    $needsNewControl = ($controlNumber === '');

    if ($controlNumber !== '') {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM registrations WHERE control_number=? AND id<>?');
        $stmt->bind_param('si', $controlNumber, $id);
        $stmt->execute();
        $duplicateCount = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        $needsNewControl = $duplicateCount > 0;
    }

    if ($needsNewControl && $controlNumber !== '') {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM attendance WHERE event_id=? AND participant_id=?');
        $stmt->bind_param('is', $eventID, $controlNumber);
        $stmt->execute();
        $attendanceCount = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        if ($attendanceCount > 0) {
            throw new RuntimeException('This duplicate control number already has attendance. Resolve that attendance before replacing the QR.');
        }
    }

    if ($needsNewControl) {
        do {
            $controlNumber = 'EVT-' . $eventID . '-' . $id . '-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM registrations WHERE control_number=?');
            $stmt->bind_param('s', $controlNumber);
            $stmt->execute();
            $exists = (int) $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
        } while ($exists > 0);
    }

    $student['control_number'] = $controlNumber;

    jpcs_ensure_directory($root . '/qrcodes');
    // Filename uses the unique registration ID.
    $qrFile = $root . '/qrcodes/registration_' . $id . '.png';
    QRcode::png($controlNumber, $qrFile, QR_ECLEVEL_L, 5);
    if (!is_file($qrFile) || filesize($qrFile) === 0) {
        throw new RuntimeException('QR image generation failed.');
    }

    $passFile = jpcs_registration_pass($student);

    // Save confirmation before sending so the QR is valid on receipt.
    // If sending fails, the student stays visible in the resend table.
    if ($action === 'confirm') {
        $stmt = $conn->prepare("
            UPDATE registrations
            SET status='confirmed', confirmed_by=?, control_number=?
            WHERE id=? AND event_id=? AND status='pending'
        ");
        $stmt->bind_param('ssii', $confirmedBy, $controlNumber, $id, $eventID);
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
        throw new RuntimeException('Registration changed. Refresh before trying again.');
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
        $mail->addAddress($student['email'], $student['student_name']);

        $safeName = e($student['student_name']);
        $safeTitle = e($student['title']);
        $safeDate = e($student['event_date']);
        $safeControl = e($controlNumber);
        $passText = $passFile ? 'Your QR code and event pass are attached.' : 'Your QR code is attached.';

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
            . 'JPCS Malabon Admin';
        $mail->addAttachment($qrFile, 'JPCS-QR-' . $id . '.png');
        if ($passFile) {
            $mail->addAttachment($passFile, 'EventPass.png');
        }
        $mail->send();

        return ['type' => 'success', 'message' => 'Confirmation email sent to ' . $student['email'] . '. Control Number: ' . $controlNumber, 'control_number' => $controlNumber];
    } catch (Throwable $mailError) {
        $details = $mail->ErrorInfo ?: $mailError->getMessage();
        // Do not display the App Password in an error.
        $details = str_replace([$gmailAppPassword, chunk_split($gmailAppPassword, 4, ' ')], '[REDACTED]', $details);
        error_log('JPCS confirmation email: ' . $details);
        return ['type' => 'error', 'message' => 'Registration is confirmed, but email failed. Use Resend Email below after fixing this error. Mailer Error: ' . $details, 'control_number' => $controlNumber];
    }
}
