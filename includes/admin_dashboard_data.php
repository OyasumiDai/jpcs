<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_login();

function dash_query(string $sql, array $params = []): array
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Resolve authorization from the database, not a potentially stale session role.
$dashUser = !empty($_SESSION['username'])
    ? (dash_query('SELECT id, name, role FROM users WHERE username=? LIMIT 1', [$_SESSION['username']])[0] ?? null)
    : (dash_query('SELECT id, name, role FROM users WHERE id=? LIMIT 1', [(string) $_SESSION['user_id']])[0] ?? null);
if (!$dashUser || !in_array($dashUser['role'], ['admin_advisor', 'admin_adviser', 'officer'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
$dashAdmin = in_array($dashUser['role'], ['admin_advisor', 'admin_adviser'], true);
$role = $dashUser['role'];
$dashToday = new DateTimeImmutable('today');
$dashPeriod = is_string($_GET['period'] ?? null) ? $_GET['period'] : '30';
if (!in_array($dashPeriod, ['today', '7', '30', 'semester', 'custom'], true)) $dashPeriod = '30';
$dashEnd = $dashToday;
$dashError = '';
$dashStart = $dashToday->modify('-29 days');
if ($dashPeriod === 'today') $dashStart = $dashToday;
if ($dashPeriod === '7') $dashStart = $dashToday->modify('-6 days');
if ($dashPeriod === 'semester') {
    // No academic calendar exists in the schema: explicit calendar half-year convention.
    $dashStart = $dashToday->setDate((int) $dashToday->format('Y'), (int) $dashToday->format('n') >= 7 ? 7 : 1, 1);
}
if ($dashPeriod === 'custom') {
    $parse = static function ($value): ?DateTimeImmutable {
        if (!is_string($value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    };
    $start = $parse($_GET['start'] ?? null);
    $end = $parse($_GET['end'] ?? null);
    if (!$start || !$end || $start > $end || $start->diff($end)->days > 365) {
        $dashError = 'Choose a valid date range of up to 366 days. Showing the last 30 days.';
        $dashPeriod = '30';
    } else { $dashStart = $start; $dashEnd = $end; }
}
$dashDays = (int) $dashStart->diff($dashEnd)->days + 1;
$dashBounds = [$dashStart->format('Y-m-d'), $dashEnd->modify('+1 day')->format('Y-m-d')];
$dashPrior = [$dashStart->modify("-$dashDays days")->format('Y-m-d'), $dashStart->format('Y-m-d')];
function dash_metrics(array $bounds): array
{
    $r = dash_query("SELECT COUNT(*) registrations, COUNT(DISTINCT NULLIF(TRIM(student_id), '')) students,
        COALESCE(SUM(status='pending'),0) pending, COALESCE(SUM(status='confirmed'),0) confirmed
        FROM registrations WHERE registered_at >= ? AND registered_at < ?", $bounds)[0];
    $a = dash_query('SELECT COUNT(*) scans FROM (SELECT event_id, participant_id FROM attendance WHERE scanned_at >= ? AND scanned_at < ? GROUP BY event_id, participant_id) a', $bounds)[0];
    $e = dash_query('SELECT COUNT(*) events FROM events WHERE event_date >= ? AND event_date < ?', $bounds)[0];
    $c = dash_query('SELECT COUNT(*) created FROM events WHERE created_at >= ? AND created_at < ?', $bounds)[0];
    return array_map('intval', array_merge($r, $a, $e, $c));
}
$dashAvailable = true;
$dashDataError = '';
try {
    $dashMetrics = dash_metrics($dashBounds);
    $dashPrevious = dash_metrics($dashPrior);
    $dashDaily = dash_query('SELECT DATE(registered_at) label, COUNT(*) value FROM registrations WHERE registered_at >= ? AND registered_at < ? GROUP BY DATE(registered_at) ORDER BY label', $dashBounds);
    $dashEventAttendance = dash_query('SELECT e.id, e.title label, COUNT(a.participant_id) value FROM (SELECT event_id, participant_id FROM attendance WHERE scanned_at >= ? AND scanned_at < ? GROUP BY event_id, participant_id) a JOIN events e ON e.id=a.event_id GROUP BY e.id,e.title ORDER BY value DESC, e.id LIMIT 12', $dashBounds);
    $dashStatuses = dash_query('SELECT COALESCE(status, \'Unknown\') label, COUNT(*) value FROM events WHERE event_date >= ? AND event_date < ? GROUP BY status', $dashBounds);
    $dashSections = dash_query("SELECT COALESCE(NULLIF(TRIM(course_year_section),''),'Unknown') label, COUNT(*) value FROM registrations WHERE registered_at >= ? AND registered_at < ? GROUP BY course_year_section ORDER BY value DESC, label", $dashBounds);
    $dashUpcoming = dash_query("SELECT e.id,e.title,e.event_date,e.event_time,e.location, (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id) registrations, (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status='pending') pending FROM events e WHERE e.status='upcoming' AND e.event_date >= ? ORDER BY e.event_date,e.event_time,e.id LIMIT 6", [$dashToday->format('Y-m-d')]);
    $dashPending = dash_query("SELECT r.id,r.event_id,r.student_name,r.registered_at,e.title,e.status event_status FROM registrations r JOIN events e ON e.id=r.event_id WHERE r.status='pending' ORDER BY r.registered_at,r.id LIMIT 6");
    $dashPendingTotal = (int) dash_query("SELECT COUNT(*) n FROM registrations WHERE status='pending'")[0]['n'];
    $dashActivity = dash_query("SELECT * FROM (
        SELECT 'Registration received' kind, student_name label, registered_at happened, event_id FROM registrations WHERE registered_at >= ? AND registered_at < ?
        UNION ALL SELECT 'Attendance scanned', COALESCE((SELECT r.student_name FROM registrations r WHERE r.control_number = attendance.participant_id AND r.event_id = attendance.event_id LIMIT 1), participant_id), scanned_at, event_id FROM attendance WHERE scanned_at >= ? AND scanned_at < ?
        UNION ALL SELECT 'Event created', title, created_at, id FROM events WHERE created_at >= ? AND created_at < ?
        ) activity ORDER BY happened DESC LIMIT 10", array_merge($dashBounds,$dashBounds,$dashBounds));
} catch (Throwable $error) {
    error_log('Dashboard read failed: ' . $error->getMessage());
    $dashAvailable = false;
    $dashDataError = 'Dashboard data is temporarily unavailable. Refresh or contact your administrator.';
    $dashMetrics = $dashPrevious = [];
    $dashDaily = $dashEventAttendance = $dashStatuses = $dashSections = $dashUpcoming = $dashPending = $dashActivity = [];
    $dashPendingTotal = null;
}
$dashKpis = [
    'registrations' => ['Registrations', 'Received in this period', true],
    'students' => ['Unique students', 'Distinct nonempty student IDs in registrations', true],
    'confirmed' => ['Confirmed registrations', 'Current status of registrations received in this period', false],
    'pending' => ['Pending registrations', 'Current status of registrations received in this period', false],
    'scans' => ['Attendance check-ins', 'Distinct event / participant pairs scanned in this period', true],
    'events' => ['Scheduled events', 'Events dated within this period', true],
    'created' => ['Events created', 'Creation timestamps within this period', true],
];
function dash_trend(string $key): string
{
    global $dashKpis,$dashMetrics,$dashPrevious,$dashAvailable;
    if (!$dashAvailable) return 'Unavailable';
    if (!$dashKpis[$key][2]) return 'Historical status trend unavailable';
    $delta = $dashMetrics[$key] - $dashPrevious[$key];
    $prefix = $delta > 0 ? '+' : '';
    return $prefix . $delta . ' vs prior period' . ($dashPrevious[$key] > 0 ? ' (' . $prefix . round($delta / $dashPrevious[$key] * 100, 1) . '%)' : '; prior: 0');
}
$dashQuery = http_build_query(['period'=>$dashPeriod,'start'=>$dashStart->format('Y-m-d'),'end'=>$dashEnd->format('Y-m-d')]);
