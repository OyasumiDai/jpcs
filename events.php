<?php
require_once __DIR__.'/admin_features.php';
require_once __DIR__ . '/includes/permissions.php';
permissions_require($conn, 'events');
require_once __DIR__.'/includes/notifications.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/event_requests.php';
$isAdmin = af_admin($conn);
$error = '';
$edit = null;
if (isset($_GET['edit'])) {
    $id=(int)$_GET['edit'];
    $stmt=$conn->prepare('SELECT * FROM events WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute();
    $edit=$stmt->get_result()->fetch_assoc();
    if (!$edit) $error='Event not found.';
}
$eventUser = af_user($conn, (int) $_SESSION['user_id']);
event_requests_ensure_schema($conn);
if (request_method_is('POST')) {
    af_csrf(); $created=[];
    $action = (string) ($_POST['action'] ?? '');
    $existing = [];
    $id = (int) ($_POST['id'] ?? 0);
    try {
        /* Adviser: approve or reject an officer's request. */
        if ($action === 'review') {
            af_require_admin($conn);
            $request = event_requests_find($conn, (int) ($_POST['request_id'] ?? 0));
            if (!$request || $request['status'] !== 'pending') throw new RuntimeException('This request was already handled. Refresh to see its status.');
            $note = (string) ($_POST['note'] ?? '');
            $title = (string) ($request['data']['title'] ?? 'event');
            if (($_POST['decision'] ?? '') === 'approve') {
                $current = null;
                if ($request['request_type'] === 'update') {
                    $stmt = $conn->prepare('SELECT * FROM events WHERE id=?'); $stmt->bind_param('i', $request['event_id']); $stmt->execute();
                    $current = $stmt->get_result()->fetch_assoc();
                    if (!$current) throw new RuntimeException('The event no longer exists.');
                }
                $changes = event_requests_changes($request, $current);
                $eventId = event_requests_approve($conn, $request, $eventUser, $note);
                audit_log($conn, 'event.approve', 'Approved ' . $request['requester_name'] . "'s " . ($request['request_type'] === 'create' ? 'new event ' : 'changes to ') . $title, 'event', $eventId, $changes);
                if ($request['request_type'] === 'update') notifications_log_event($conn, $eventUser, 'event_updated', $eventId, $title);
                af_success('events.php?view=approvals', 'Approved — ' . $title . ' is now published.');
            }
            event_requests_close($conn, (int) $request['id'], 'rejected', $eventUser, $note);
            event_requests_discard_files($request);
            audit_log($conn, 'event.reject', 'Rejected ' . $request['requester_name'] . "'s " . ($request['request_type'] === 'create' ? 'new event ' : 'changes to ') . $title, 'event_request', (int) $request['id'], $note !== '' ? ['Reason' => ['', $note]] : []);
            af_success('events.php?view=approvals', 'Request rejected. ' . $request['requester_name'] . ' will see your reason.');
        }
        /* Officer: withdraw their own pending request. */
        if ($action === 'withdraw') {
            $request = event_requests_find($conn, (int) ($_POST['request_id'] ?? 0));
            if (!$request || (int) $request['requested_by'] !== (int) $eventUser['id'] || $request['status'] !== 'pending') throw new RuntimeException('Only your own pending requests can be withdrawn.');
            event_requests_close($conn, (int) $request['id'], 'withdrawn', $eventUser);
            event_requests_discard_files($request);
            audit_log($conn, 'event.withdraw', 'Withdrew request for ' . ($request['data']['title'] ?? 'an event'), 'event_request', (int) $request['id']);
            af_success('events.php?view=requests', 'Request withdrawn.');
        }
        if ($id) {
            $stmt=$conn->prepare('SELECT * FROM events WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute();
            $existing=$stmt->get_result()->fetch_assoc();
            if (!$existing) throw new RuntimeException('Event not found.');
        }
        if (isset($_POST['done']) && $id) {
            af_require_admin($conn);
            $stmt=$conn->prepare("UPDATE events SET status='done' WHERE id=?"); $stmt->bind_param('i',$id); $stmt->execute();
            notifications_log_event($conn, $eventUser, 'event_done', $id, (string) $existing['title']);
            audit_log($conn, 'event.done', 'Marked ' . $existing['title'] . ' as done', 'event', $id, ['Status' => [(string) $existing['status'], 'done']]);
            af_success('events.php','Event saved.');
        }
        $title=af_text('title',100); $description=af_text('description',16000,false);
        $location=af_text('location',255); $date=af_text('event_date',10); $time=af_text('event_time',5);
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D',$time)) throw new RuntimeException('Enter a valid date and time.');
        $status=(string)($_POST['status'] ?? 'upcoming');
        if (!in_array($status,['upcoming','done'],true)) throw new RuntimeException('Invalid event status.');
        if (!$isAdmin && $id && event_requests_pending_for_event($conn, $id)) throw new RuntimeException('This event already has changes waiting for the adviser. Wait for that review, or withdraw your request in My requests.');
        $poster=af_upload('poster',$created) ?? ($existing['poster'] ?? null);
        $pass=af_upload('pass_image',$created) ?? ($existing['pass_image'] ?? null);

        /* Officers: the change waits for an adviser. */
        if (!$isAdmin) {
            $data = ['title' => $title, 'description' => $description, 'event_date' => $date, 'event_time' => $time, 'location' => $location, 'status' => $status, 'poster' => $poster, 'pass_image' => $pass];
            $changes = $id ? event_requests_changes(['data' => $data], $existing) : ['Date' => ['', $date . ' ' . $time], 'Location' => ['', $location]];
            if ($id && !$changes) throw new RuntimeException('Nothing was changed, so no request was sent.');
            $requestId = event_requests_submit($conn, $eventUser, $id ?: null, $data, $created);
            audit_log($conn, 'event.request', 'Submitted ' . ($id ? 'changes to ' : 'new event ') . $title . ' for approval', 'event_request', $requestId, $changes);
            af_success('events.php?view=requests', 'Sent to the adviser for approval. You will be notified when it is reviewed.');
        }

        /* Advisers: publish directly. */
        if ($id) {
            $stmt=$conn->prepare('UPDATE events SET title=?, description=?, event_date=?, event_time=?, location=?, poster=?, pass_image=?, status=? WHERE id=?');
            $stmt->bind_param('ssssssssi',$title,$description,$date,$time,$location,$poster,$pass,$status,$id);
        } else {
            $stmt=$conn->prepare('INSERT INTO events (title,description,event_date,event_time,location,poster,pass_image,status) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->bind_param('ssssssss',$title,$description,$date,$time,$location,$poster,$pass,$status);
        }
        $stmt->execute();
        if ($id) {
            audit_log($conn, 'event.update', 'Updated event ' . $title, 'event', $id, audit_diff(['event_time' => substr((string) $existing['event_time'], 0, 5)] + $existing, ['title' => $title, 'description' => $description, 'event_date' => $date, 'event_time' => $time, 'location' => $location, 'status' => $status, 'poster' => $poster, 'pass_image' => $pass], ['title' => 'Title', 'description' => 'Description', 'event_date' => 'Date', 'event_time' => 'Time', 'location' => 'Location', 'status' => 'Status', 'poster' => 'Poster', 'pass_image' => 'Event pass']));
            notifications_log_event($conn, $eventUser, $status === 'done' && ($existing['status'] ?? '') !== 'done' ? 'event_done' : 'event_updated', $id, $title);
        } else {
            audit_log($conn, 'event.create', 'Created event ' . $title, 'event', (int) $conn->insert_id, ['Date' => ['', $date . ' ' . $time], 'Location' => ['', $location], 'Status' => ['', $status]]);
        }
        af_success('events.php','Event saved.');
    } catch (Throwable $ex) {
        af_cleanup($created);
        $error=$ex instanceof mysqli_sql_exception ? 'Could not save event. Please try again.' : $ex->getMessage();
        if (in_array($action, ['review', 'withdraw'], true)) { $_SESSION['ev_error'] = $error; redirect('events.php?view=' . ($action === 'review' ? 'approvals' : 'requests')); }
        $edit=array_merge($existing ?: [], array_intersect_key($_POST,array_flip(['title','description','event_date','event_time','location','status'])),['id'=>$id]);
    }
}
if (isset($_SESSION['ev_error'])) { $error = $_SESSION['ev_error']; unset($_SESSION['ev_error']); }
$events=$conn->query('SELECT * FROM events ORDER BY event_date ASC, event_time ASC')->fetch_all(MYSQLI_ASSOC);
$view = in_array($_GET['view'] ?? '', ['calendar', 'approvals', 'requests'], true) ? $_GET['view'] : 'list';
if ($view === 'approvals' && !$isAdmin) $view = 'requests';
if ($view === 'requests' && $isAdmin) $view = 'approvals';
$requestRows = static function (string $where, array $params = []) use ($conn): array {
    $stmt = $conn->prepare('SELECT q.*, e.title AS current_title FROM event_requests q LEFT JOIN events e ON e.id = q.event_id WHERE ' . $where . ' ORDER BY q.created_at DESC, q.id DESC LIMIT 50');
    if ($params) $stmt->bind_param(str_repeat('i', count($params)), ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) { $row['data'] = json_decode((string) $row['payload'], true) ?: []; }
    return $rows;
};
$pendingRequests = $isAdmin ? $requestRows("q.status = 'pending'") : [];
$reviewedRequests = $isAdmin ? $requestRows("q.status IN ('approved','rejected')") : [];
$myRequests = $isAdmin ? [] : $requestRows('q.requested_by = ?', [(int) $eventUser['id']]);
$myPendingCount = count(array_filter($myRequests, static fn($r) => $r['status'] === 'pending'));
$pendingByEvent = [];
foreach ($conn->query("SELECT event_id, requester_name FROM event_requests WHERE status = 'pending' AND event_id IS NOT NULL")->fetch_all(MYSQLI_ASSOC) as $pendingRow) $pendingByEvent[(int) $pendingRow['event_id']] = $pendingRow['requester_name'];
$eventsById = array_column($events, null, 'id');
$month=(string)($_GET['month'] ?? date('Y-m'));
$first=DateTimeImmutable::createFromFormat('!Y-m',$month);
if (!$first || $first->format('Y-m') !== $month) { $month=date('Y-m'); $first=new DateTimeImmutable(date('Y-m-01')); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="assets/img/jpcs-logo.png?v=3">

  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Event Management</title>


<?php require __DIR__.'/includes/admin_head.php'; ?>
</head>

<body class="app-page admin-page jpcs-admin feature-events">
<?php require __DIR__.'/includes/admin_sidebar.php'; ?>
<main class="content"><header><h1>Event Management</h1></header>
<?php if ($error) { ?><p class="error" role="alert"><?= e($error) ?></p><?php } ?>
<?php if (isset($_SESSION['af_notice'])) { ?><p class="success" role="status"><?= e($_SESSION['af_notice']) ?></p><?php unset($_SESSION['af_notice']); } ?>
<nav class="toolbar" aria-label="Event views"><a class="btn" href="events.php" <?= $view==='list'?'aria-current="page"':'' ?>>List</a> <a class="btn" href="events.php?view=calendar" <?= $view==='calendar'?'aria-current="page"':'' ?>>Calendar</a>
<?php if ($isAdmin) { ?><a class="btn ev-tab" href="events.php?view=approvals" <?= $view==='approvals'?'aria-current="page"':'' ?>>Approvals<?php if ($pendingRequests) { ?> <span class="ev-count"><?= count($pendingRequests) ?></span><?php } ?></a>
<?php } else { ?><a class="btn ev-tab" href="events.php?view=requests" <?= $view==='requests'?'aria-current="page"':'' ?>>My requests<?php if ($myPendingCount) { ?> <span class="ev-count"><?= $myPendingCount ?></span><?php } ?></a><?php } ?>
<a class="btn btn-create" href="events.php?createform=1"><?= $isAdmin ? 'Create event' : 'Propose event' ?></a></nav>
<?php if (!$isAdmin) { ?><p class="ev-note">New events and changes you submit are reviewed by the adviser before students can see them.</p><?php } ?>
<?php if ($view==='calendar') { ?>
<section aria-label="Monthly event calendar" class="calendar-panel"><div class="calendar-head"><h2><?= e($first->format('F Y')) ?></h2>
<nav class="calendar-nav" aria-label="Change month"><a class="btn" href="?view=calendar&amp;month=<?= $first->modify('-1 month')->format('Y-m') ?>" aria-label="Previous month">&lsaquo; Prev</a><a class="btn" href="?view=calendar&amp;month=<?= date('Y-m') ?>">Today</a><a class="btn" href="?view=calendar&amp;month=<?= $first->modify('+1 month')->format('Y-m') ?>" aria-label="Next month">Next &rsaquo;</a></nav></div>
<div class="event-calendar">
<?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day) { ?><strong><?= $day ?></strong><?php }
for ($blank=1;$blank<(int)$first->format('N');$blank++) echo '<div></div>';
for ($day=1;$day<=(int)$first->format('t');$day++) { $date=$month.'-'.sprintf('%02d',$day); ?>
<?php $dayEvents = array_filter($events, static fn($event) => $event['event_date'] === $date); ?>
<div class="calendar-day<?= $date === date('Y-m-d') ? ' is-today' : '' ?><?= $dayEvents ? ' has-events' : '' ?>"><strong<?= $date === date('Y-m-d') ? ' aria-label="Today, ' . $day . '"' : '' ?>><?= $day ?></strong>
<?php foreach ($events as $event) { if ($event['event_date']!==$date) continue; ?>
<p><a href="events.php?edit=<?= (int)$event['id'] ?>#event-<?= (int)$event['id'] ?>"><?= e($event['title']) ?></a><br><small><?= e(substr($event['event_time'] ?? '',0,5)) ?> · <?= e($event['status']) ?></small></p>
<?php } ?></div><?php } ?></div></section>
<?php } elseif ($view === 'approvals') {
  $fieldLabel = static fn(string $label, string $value): string => in_array($label, ['Poster', 'Event pass'], true) ? ($value !== '' ? '<img class="ev-thumb" src="' . e(image_url($value)) . '" alt="">' : '<em>none</em>') : ($value !== '' ? e(mb_strimwidth($value, 0, 160, '…')) : '<em>empty</em>');
?>
<?php if (!$pendingRequests) { ?>
  <div class="rx-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"></path></svg></span><strong>No requests waiting</strong><p>When an officer proposes a new event or changes one, it appears here for your approval.</p></div>
<?php } ?>
<div class="ev-requests">
<?php foreach ($pendingRequests as $request) { $isNew = $request['request_type'] === 'create'; $changes = event_requests_changes($request, $isNew ? null : ($eventsById[$request['event_id']] ?? null)); ?>
  <article class="ev-request">
    <header class="ev-request__head">
      <span class="ev-type <?= $isNew ? 'is-new' : 'is-update' ?>"><?= $isNew ? 'New event' : 'Changes' ?></span>
      <h2><?= e($request['data']['title'] ?? 'Untitled') ?><?php if (!$isNew && ($request['current_title'] ?? '') !== ($request['data']['title'] ?? '')) { ?> <small>(now “<?= e($request['current_title']) ?>”)</small><?php } ?></h2>
      <p>Requested by <b><?= e($request['requester_name']) ?></b> · <time datetime="<?= e(date('c', strtotime($request['created_at']))) ?>"><?= e(date('M j, g:i A', strtotime($request['created_at']))) ?></time></p>
    </header>
    <div class="ev-diff">
      <?php foreach ($changes as $label => [$old, $new]) { ?>
        <div class="ev-diff__row"><span class="ev-diff__label"><?= e($label) ?></span><?php if (!$isNew) { ?><span class="ev-diff__old"><?= $fieldLabel($label, $old) ?></span><span class="ev-diff__arrow" aria-hidden="true">→</span><?php } ?><span class="ev-diff__new"><?= $fieldLabel($label, $new) ?></span></div>
      <?php } ?>
    </div>
    <form method="post" class="ev-review"><?php af_field(); ?><input type="hidden" name="action" value="review"><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
      <label class="visually-hidden" for="note-<?= (int) $request['id'] ?>">Note to <?= e($request['requester_name']) ?></label>
      <input id="note-<?= (int) $request['id'] ?>" name="note" maxlength="500" placeholder="Optional note to <?= e($request['requester_name']) ?> (shown if you reject)">
      <button type="submit" name="decision" value="reject" class="btn-danger" data-ev-reject>Reject</button>
      <button type="submit" name="decision" value="approve" class="ev-approve">Approve &amp; publish</button>
    </form>
  </article>
<?php } ?>
</div>
<?php if ($reviewedRequests) { ?>
  <h2 class="ev-subhead">Recently reviewed</h2>
  <ul class="ev-history">
    <?php foreach (array_slice($reviewedRequests, 0, 10) as $request) { ?>
      <li><span class="ev-status is-<?= e($request['status']) ?>"><?= e(ucfirst($request['status'])) ?></span><span><b><?= e($request['data']['title'] ?? '') ?></b> · <?= $request['request_type'] === 'create' ? 'new event' : 'changes' ?> by <?= e($request['requester_name']) ?><?= $request['review_note'] ? ' · “' . e($request['review_note']) . '”' : '' ?></span><time><?= e(date('M j', strtotime((string) $request['reviewed_at']))) ?></time></li>
    <?php } ?>
  </ul>
<?php } ?>
<?php } elseif ($view === 'requests') { ?>
<?php if (!$myRequests) { ?>
  <div class="rx-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h16v14H4z"></path><path d="M8 4v4M16 4v4M4 10h16"></path></svg></span><strong>No requests yet</strong><p>Propose a new event or request an edit — the adviser reviews it before it goes live.</p><a class="btn btn-create" href="events.php?createform=1">Propose event</a></div>
<?php } ?>
<ul class="ev-history ev-mine">
  <?php foreach ($myRequests as $request) { ?>
    <li>
      <span class="ev-status is-<?= e($request['status']) ?>"><?= e(['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'][$request['status']]) ?></span>
      <span><b><?= e($request['data']['title'] ?? '') ?></b> · <?= $request['request_type'] === 'create' ? 'new event' : 'changes' ?> · sent <?= e(date('M j, g:i A', strtotime($request['created_at']))) ?>
        <?php if ($request['reviewer_name'] && $request['status'] !== 'withdrawn') { ?><br><small><?= e(ucfirst($request['status'])) ?> by <?= e($request['reviewer_name']) ?><?= $request['review_note'] ? ': “' . e($request['review_note']) . '”' : '' ?></small><?php } ?></span>
      <?php if ($request['status'] === 'pending') { ?><form method="post" class="inline-action" onsubmit="return confirm('Withdraw this request?');"><?php af_field(); ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>"><button type="submit" class="btn-secondary">Withdraw</button></form><?php } ?>
    </li>
  <?php } ?>
</ul>
<?php } else { ?>
<div class="table-wrapper"><table><thead><tr><th>Poster</th><th>Event</th><th>Date</th><th>Time</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($events as $event) { ?>
<tr id="event-<?= (int)$event['id'] ?>"><td><a href="<?= e(image_url($event['poster'])) ?>" target="_blank" rel="noopener"><img src="<?= e(image_url($event['poster'])) ?>" alt="<?= e($event['title']) ?> poster" width="64" height="80" class="event-poster"></a></td><td><strong><?= e($event['title']) ?></strong><details><summary>Description</summary><?= nl2br(e($event['description'])) ?></details></td><td><?= e($event['event_date']) ?></td><td><?= e(substr($event['event_time'] ?? '',0,5)) ?></td><td><?= e($event['location']) ?></td><td><span class="status-badge is-<?= e($event['status']) ?>"><?= e(ucfirst((string) $event['status'])) ?></span></td><td><div class="row-actions">
<?php if (isset($pendingByEvent[(int) $event['id']])) { ?><span class="ev-pending" title="Requested by <?= e($pendingByEvent[(int) $event['id']]) ?>">Change pending</span><?php } ?>
<?php if (!$isAdmin && !isset($pendingByEvent[(int) $event['id']])) { ?><a class="btn" href="?edit=<?= (int)$event['id'] ?>">Request edit</a><?php } ?>
<?php if ($isAdmin) { ?><a class="btn" href="?edit=<?= (int)$event['id'] ?>">Edit</a>
<?php if ($event['status']==='upcoming') { ?><form method="post" class="inline-action"><?php af_field(); ?><input type="hidden" name="id" value="<?= (int)$event['id'] ?>"><button name="done" value="1" type="submit">Mark done</button></form><?php } } ?>
<a class="btn btn-ghost" href="registrations.php?event_id=<?= (int)$event['id'] ?>">Registrations</a></div></td></tr>
<?php } if (!$events) { ?><tr><td colspan="7">No events yet.</td></tr><?php } ?></tbody></table></div>
<?php }
if ((isset($_GET['createform']) || $edit) && !($edit && !$isAdmin && !empty($edit['id']) && isset($pendingByEvent[(int) $edit['id']]) && !$error)) { $form=$edit ?: []; $isEdit=!empty($form['id']); ?>
<div class="modal ef-modal"><section class="modal-content ef-dialog" role="dialog" aria-modal="true" aria-labelledby="event-form-title">
<form method="post" enctype="multipart/form-data" class="ef-form" data-ef-form><?php af_field(); ?><input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">
<header class="ef-head">
  <span class="ef-head__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h16v14H4z"></path><path d="M8 4v4M16 4v4M4 10h16"></path><?php if (!$isEdit) { ?><path d="M12 13v4M10 15h4"></path><?php } ?></svg></span>
  <div><h2 id="event-form-title"><?= $isAdmin ? ($isEdit ? 'Edit event' : 'Create event') : ($isEdit ? 'Request changes' : 'Propose an event') ?></h2><p><?= $isEdit ? 'Update the details students see when they register.' : 'Fill in the details students will see when they register.' ?></p></div>
  <a class="ef-close cancel-btn" href="events.php" aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"></path></svg></a>
</header>
<div class="ef-body">
  <?php if ($error) { ?><p role="alert" class="ef-alert"><?= e($error) ?></p><?php } ?>
  <?php if (!$isAdmin) { ?><p class="ev-approval-note"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"></path><path d="M9 12l2 2 4-4"></path></svg>The adviser reviews this before it is published. You'll get a notification with the decision.</p><?php } ?>
  <fieldset class="ef-section">
    <legend>Details</legend>
    <div class="ef-field">
      <label for="title">Title <span class="ef-count" data-ef-count="title">0/100</span></label>
      <input id="title" name="title" maxlength="100" required placeholder="e.g. CCS Night 2026" value="<?= e($form['title'] ?? '') ?>">
    </div>
    <div class="ef-field">
      <label for="description">Description <span class="ef-optional">optional</span></label>
      <textarea id="description" name="description" maxlength="16000" rows="4" placeholder="What is the event about? Who should attend?"><?= e($form['description'] ?? '') ?></textarea>
    </div>
  </fieldset>
  <fieldset class="ef-section">
    <legend>Schedule &amp; place</legend>
    <div class="ef-row">
      <div class="ef-field"><label for="event_date">Date</label><input id="event_date" type="date" name="event_date" required value="<?= e($form['event_date'] ?? '') ?>"></div>
      <div class="ef-field"><label for="event_time">Time</label><input id="event_time" type="time" name="event_time" required value="<?= e(substr($form['event_time'] ?? '',0,5)) ?>"></div>
    </div>
    <div class="ef-field"><label for="location">Location</label><input id="location" name="location" maxlength="255" required placeholder="e.g. CMU Ground" value="<?= e($form['location'] ?? '') ?>"></div>
    <div class="ef-field">
      <span class="ef-label" id="status-label">Status</span>
      <div class="ef-segment" role="radiogroup" aria-labelledby="status-label">
        <label><input type="radio" name="status" value="upcoming"<?= ($form['status'] ?? 'upcoming') !== 'done' ? ' checked' : '' ?>><span><i class="ef-dot is-upcoming"></i>Upcoming</span></label>
        <label><input type="radio" name="status" value="done"<?= ($form['status'] ?? '') === 'done' ? ' checked' : '' ?>><span><i class="ef-dot is-done"></i>Done</span></label>
      </div>
    </div>
  </fieldset>
  <fieldset class="ef-section">
    <legend>Images</legend>
    <div class="ef-row">
      <?php foreach (['poster'=>['Poster','Shown on event cards'],'pass_image'=>['Event pass','Background of the emailed pass']] as $field=>[$label,$hint]) { $has = trim((string)($form[$field] ?? '')) !== ''; ?>
      <div class="ef-field">
        <span class="ef-label"><?= $label ?></span>
        <label class="ef-drop<?= $has ? ' has-image' : '' ?>" for="<?= $field ?>" data-ef-drop>
          <img id="<?= $field ?>-preview" src="<?= $has ? e(image_url($form[$field])) : '' ?>" alt="<?= $label ?> preview"<?= $has ? '' : ' hidden' ?>>
          <span class="ef-drop__empty">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V5M7.5 9.5 12 5l4.5 4.5M5 19h14"></path></svg>
            <strong>Upload <?= mb_strtolower($label) ?></strong><small><?= $hint ?> &middot; JPEG, PNG, WebP &middot; 5 MB</small>
          </span>
          <span class="ef-drop__change">Change image</span>
          <input id="<?= $field ?>" type="file" name="<?= $field ?>" accept="image/jpeg,image/png,image/webp" data-preview="<?= $field ?>-preview">
        </label>
        <small class="ef-file" data-ef-file="<?= $field ?>"><?= $has ? 'Current image kept unless you choose a new one.' : 'No image yet.' ?></small>
      </div>
      <?php } ?>
    </div>
  </fieldset>
</div>
<footer class="ef-foot modal-actions">
  <a class="btn btn-ghost cancel-btn" href="events.php">Cancel</a>
  <button type="submit"><?= $isAdmin ? ($isEdit ? 'Save changes' : 'Create event') : 'Submit for approval' ?></button>
</footer>
</form></section></div>
<?php } ?>
</main><script src="js/event-form.js?v=<?= filemtime(__DIR__ . '/js/event-form.js') ?>" defer></script></body></html>
