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
 * Branded QR code: high error correction, larger modules and the JPCS logo in the centre.
 * Level H recovers up to 30% of the code, so a centred logo of about a fifth of the width still scans.
 */
function jpcs_branded_qr(string $text, string $file): void
{
    require_once dirname(__DIR__) . '/phpqrcode/qrlib.php';
    QRcode::png($text, $file, QR_ECLEVEL_H, 10, 2);
    $logoPath = dirname(__DIR__) . '/assets/img/jpcs-logo.png';
    if (!is_file($logoPath) || !function_exists('imagecreatefrompng')) return;
    $qr = @imagecreatefrompng($file);
    $logo = @imagecreatefrompng($logoPath);
    if (!$qr || !$logo) return;
    try {
        // Work in true colour so the logo keeps its colours and smooth edges.
        $size = imagesx($qr);
        $canvas = imagecreatetruecolor($size, $size);
        imagecopy($canvas, $qr, 0, 0, 0, 0, $size, $size);
        $badge = (int) round($size * 0.24);
        $x = (int) (($size - $badge) / 2);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $orange = imagecolorallocate($canvas, 242, 112, 31);
        // White circle with a thin orange ring behind the logo keeps the quiet zone around it.
        imagefilledellipse($canvas, $x + intdiv($badge, 2), $x + intdiv($badge, 2), $badge + 10, $badge + 10, $orange);
        imagefilledellipse($canvas, $x + intdiv($badge, 2), $x + intdiv($badge, 2), $badge + 4, $badge + 4, $white);
        $inner = (int) round($badge * 0.86);
        $offset = $x + intdiv($badge - $inner, 2);
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $logo, $offset, $offset, 0, 0, $inner, $inner, imagesx($logo), imagesy($logo));
        imagepng($canvas, $file, 6);
        imagedestroy($canvas);
    } finally {
        imagedestroy($qr);
        imagedestroy($logo);
    }
}

/**
 * The confirmation letter, styled as an event ticket. $qrSrc / $logoSrc are image sources:
 * "cid:..." for the real email, or data URIs for previews. Inline styles only, for email clients.
 */
function jpcs_ticket_email_html(array $student, string $controlNumber, string $qrSrc, string $logoSrc): string
{
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $date = $student['event_date'] ? date('l, F j, Y', strtotime((string) $student['event_date'])) : '';
    $time = !empty($student['event_time']) ? date('g:i A', strtotime((string) $student['event_time'])) : '';
    $location = trim((string) ($student['location'] ?? ''));
    $row = static fn($label, $value) => $value === '' ? '' : '<tr><td style="padding:6px 0;color:#8a7a6e;font-size:12px;text-transform:uppercase;letter-spacing:.08em;width:92px;vertical-align:top">' . $label . '</td><td style="padding:6px 0;color:#2f2621;font-size:15px;font-weight:600">' . $value . '</td></tr>';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f6ede4;font-family:Segoe UI,Arial,Helvetica,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6ede4;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
        // Greeting
        . '<tr><td style="padding:0 6px 18px;color:#2f2621;font-size:15px;line-height:1.6">Hi <b>' . $h($student['student_name']) . '</b>,<br>Your registration is <b style="color:#1d7a4c">confirmed</b>. Here is your event ticket — show the QR code at the entrance.</td></tr>'
        // Ticket
        . '<tr><td style="background:#ffffff;border-radius:22px;overflow:hidden;box-shadow:0 12px 34px rgba(150,90,45,.18)">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        // Header band
        . '<tr><td style="background:#f2701f;background-image:linear-gradient(135deg,#ff8a3d,#e0610f);padding:22px 26px;border-radius:22px 22px 0 0">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td width="58" style="vertical-align:middle"><img src="' . $h($logoSrc) . '" width="52" height="52" alt="JPCS" style="display:block;border-radius:50%;background:#ffffff"></td>'
        . '<td style="vertical-align:middle;padding-left:14px;color:#ffffff"><div style="font-size:12px;letter-spacing:.18em;text-transform:uppercase;opacity:.9">JPCS · City of Malabon University</div><div style="font-size:22px;font-weight:800;line-height:1.25">Event Ticket</div></td>'
        . '<td align="right" style="vertical-align:middle"><span style="display:inline-block;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,.22);color:#ffffff;font-size:11px;font-weight:700;letter-spacing:.06em;white-space:nowrap">ADMIT ONE</span></td>'
        . '</tr></table></td></tr>'
        // Event details
        . '<tr><td style="padding:24px 26px 8px"><div style="color:#a9480b;font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase">Event</div>'
        . '<div style="color:#2f2621;font-size:26px;font-weight:800;line-height:1.25;margin:4px 0 14px">' . $h($student['title']) . '</div>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">' . $row('Date', $h($date)) . $row('Time', $h($time)) . $row('Venue', $h($location)) . '</table></td></tr>'
        // Perforation
        . '<tr><td style="padding:14px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td width="16" style="background:#f6ede4;height:28px;border-radius:0 28px 28px 0"></td><td style="border-top:2px dashed #e7d6c8"></td><td width="16" style="background:#f6ede4;height:28px;border-radius:28px 0 0 28px"></td></tr></table></td></tr>'
        // Stub: attendee + QR. Inline-blocks sit side by side on wide screens and stack on phones.
        . '<tr><td style="padding:4px 18px 26px;text-align:center;font-size:0">'
        . '<div style="display:inline-block;width:100%;max-width:300px;vertical-align:top;text-align:left;font-size:15px;padding:0 8px 14px;box-sizing:border-box"><div style="color:#a9480b;font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;margin-bottom:6px">Attendee</div>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">' . $row('Name', $h($student['student_name'])) . $row('Student ID', $h($student['student_id'])) . $row('Section', $h($student['course_year_section'])) . '</table>'
        . '<div style="margin-top:14px;padding:12px 14px;border-radius:14px;background:#fff4ea;border:1px solid #f7d9c2"><div style="color:#8a7a6e;font-size:11px;letter-spacing:.12em;text-transform:uppercase">Control number</div><div style="color:#a9480b;font-size:18px;font-weight:800;font-family:Consolas,Menlo,monospace;letter-spacing:.04em;word-break:break-all">' . $h($controlNumber) . '</div></div></div>'
        . '<div style="display:inline-block;width:230px;vertical-align:top;text-align:center;font-size:15px;padding:0 8px;box-sizing:border-box"><div style="padding:10px;border-radius:18px;background:#ffffff;border:2px solid #f2701f"><img src="' . $h($qrSrc) . '" width="190" height="190" alt="QR code ' . $h($controlNumber) . '" style="display:block;width:190px;height:190px;margin:0 auto"></div><div style="margin-top:8px;color:#8a7a6e;font-size:12px">Scan at the entrance</div></div>'
        . '</td></tr>'
        . '</table></td></tr>'
        // Notes
        . '<tr><td style="padding:18px 6px 0;color:#75675d;font-size:13px;line-height:1.6">'
        . '<b style="color:#2f2621">Before the event</b><br>• Bring this email on your phone, or print it — the QR code works either way.<br>• This ticket replaces any QR code you received earlier.<br>• The QR code is also attached as an image in case your email app hides pictures.'
        . '</td></tr>'
        . '<tr><td style="padding:22px 6px 0;color:#75675d;font-size:13px;line-height:1.6">See you there!<br><b style="color:#2f2621">JPCS Malabon</b> · Junior Philippine Computer Society, City of Malabon University</td></tr>'
        . '</table></td></tr></table></body></html>';
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
        SELECT r.*, e.title, e.event_date, e.event_time, e.location, e.pass_image,
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
    jpcs_branded_qr($controlNumber, $qrFile);
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

        $passText = $passFile ? 'Your QR code and event pass are attached.' : 'Your QR code is attached.';
        $mail->isHTML(true);
        $mail->Subject = 'Your ticket: ' . $student['title'];
        // The QR code and logo are embedded so they show inside the letter.
        $mail->addEmbeddedImage($qrFile, 'jpcs-ticket-qr', 'JPCS-QR-' . $id . '.png');
        $mail->addEmbeddedImage(dirname(__DIR__) . '/assets/img/jpcs-logo-email.png', 'jpcs-ticket-logo', 'jpcs-logo.png');
        $mail->Body = jpcs_ticket_email_html($student, $controlNumber, 'cid:jpcs-ticket-qr', 'cid:jpcs-ticket-logo');
        $mail->AltBody =
            "Hi {$student['student_name']},

"
            . "Your registration is confirmed. Here is your event ticket.

"
            . "Event: {$student['title']}
"
            . "Date: {$student['event_date']}" . (!empty($student['event_time']) ? ' ' . substr((string) $student['event_time'], 0, 5) : '') . "
"
            . (!empty($student['location']) ? "Venue: {$student['location']}
" : '')
            . "Control Number: $controlNumber

"
            . "$passText Show the QR code at the entrance.

"
            . 'JPCS Malabon';
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
