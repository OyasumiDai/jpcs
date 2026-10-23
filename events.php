<?php
require_once __DIR__.'/admin_features.php';
require_once __DIR__.'/includes/notifications.php';
$isAdmin = af_admin($conn);
$error = '';
$edit = null;
if (isset($_GET['edit'])) {
    $id=(int)$_GET['edit'];
    $stmt=$conn->prepare('SELECT * FROM events WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute();
    $edit=$stmt->get_result()->fetch_assoc();
    if (!$edit) $error='Event not found.';
}
if (request_method_is('POST')) {
    af_require_admin($conn); af_csrf(); $created=[];
    try {
        $id=(int)($_POST['id'] ?? 0);
        $existing=[];
        if ($id) {
            $stmt=$conn->prepare('SELECT * FROM events WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute();
            $existing=$stmt->get_result()->fetch_assoc();
            if (!$existing) throw new RuntimeException('Event not found.');
        }
        if (isset($_POST['done']) && $id) {
            $stmt=$conn->prepare("UPDATE events SET status='done' WHERE id=?"); $stmt->bind_param('i',$id); $stmt->execute();
            notifications_log_event($conn, af_user($conn, (int) $_SESSION['user_id']), 'event_done', $id, (string) $existing['title']);
        } else {
            $title=af_text('title',100); $description=af_text('description',16000,false);
            $location=af_text('location',255); $date=af_text('event_date',10); $time=af_text('event_time',5);
            $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D',$time)) throw new RuntimeException('Enter a valid date and time.');
            $status=(string)($_POST['status'] ?? 'upcoming');
            if (!in_array($status,['upcoming','done'],true)) throw new RuntimeException('Invalid event status.');
            $poster=af_upload('poster',$created) ?? ($existing['poster'] ?? null);
            $pass=af_upload('pass_image',$created) ?? ($existing['pass_image'] ?? null);
            if ($id) {
                $stmt=$conn->prepare('UPDATE events SET title=?, description=?, event_date=?, event_time=?, location=?, poster=?, pass_image=?, status=? WHERE id=?');
                $stmt->bind_param('ssssssssi',$title,$description,$date,$time,$location,$poster,$pass,$status,$id);
            } else {
                $stmt=$conn->prepare('INSERT INTO events (title,description,event_date,event_time,location,poster,pass_image,status) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->bind_param('ssssssss',$title,$description,$date,$time,$location,$poster,$pass,$status);
            }
            $stmt->execute();
            if ($id) notifications_log_event($conn, af_user($conn, (int) $_SESSION['user_id']), $status === 'done' && ($existing['status'] ?? '') !== 'done' ? 'event_done' : 'event_updated', $id, $title);
        }
        af_success('events.php','Event saved.');
    } catch (Throwable $ex) {
        af_cleanup($created);
        $error=$ex instanceof mysqli_sql_exception ? 'Could not save event. Please try again.' : $ex->getMessage();
        $edit=array_merge($existing ?: [], array_intersect_key($_POST,array_flip(['title','description','event_date','event_time','location','status'])),['id'=>$id]);
    }
}
$events=$conn->query('SELECT * FROM events ORDER BY event_date ASC, event_time ASC')->fetch_all(MYSQLI_ASSOC);
$view=($_GET['view'] ?? '') === 'calendar' ? 'calendar' : 'list';
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
<?php if ($isAdmin) { ?><a class="btn btn-create" href="events.php?createform=1">Create event</a><?php } ?></nav>
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
<p><a href="events.php?<?= $isAdmin?'edit':'event' ?>=<?= (int)$event['id'] ?>#event-<?= (int)$event['id'] ?>"><?= e($event['title']) ?></a><br><small><?= e(substr($event['event_time'] ?? '',0,5)) ?> · <?= e($event['status']) ?></small></p>
<?php } ?></div><?php } ?></div></section>
<?php } else { ?>
<div class="table-wrapper"><table><thead><tr><th>Poster</th><th>Event</th><th>Date</th><th>Time</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($events as $event) { ?>
<tr id="event-<?= (int)$event['id'] ?>"><td><a href="<?= e(image_url($event['poster'])) ?>" target="_blank" rel="noopener"><img src="<?= e(image_url($event['poster'])) ?>" alt="<?= e($event['title']) ?> poster" width="64" height="80" class="event-poster"></a></td><td><strong><?= e($event['title']) ?></strong><details><summary>Description</summary><?= nl2br(e($event['description'])) ?></details></td><td><?= e($event['event_date']) ?></td><td><?= e(substr($event['event_time'] ?? '',0,5)) ?></td><td><?= e($event['location']) ?></td><td><span class="status-badge is-<?= e($event['status']) ?>"><?= e(ucfirst((string) $event['status'])) ?></span></td><td><div class="row-actions">
<?php if ($isAdmin) { ?><a class="btn" href="?edit=<?= (int)$event['id'] ?>">Edit</a>
<?php if ($event['status']==='upcoming') { ?><form method="post" class="inline-action"><?php af_field(); ?><input type="hidden" name="id" value="<?= (int)$event['id'] ?>"><button name="done" value="1" type="submit">Mark done</button></form><?php } } ?>
<a class="btn btn-ghost" href="registrations.php?event_id=<?= (int)$event['id'] ?>">Registrations</a></div></td></tr>
<?php } if (!$events) { ?><tr><td colspan="7">No events yet.</td></tr><?php } ?></tbody></table></div>
<?php }
if ($isAdmin && (isset($_GET['createform']) || $edit)) { $form=$edit ?: []; $isEdit=!empty($form['id']); ?>
<div class="modal ef-modal"><section class="modal-content ef-dialog" role="dialog" aria-modal="true" aria-labelledby="event-form-title">
<form method="post" enctype="multipart/form-data" class="ef-form" data-ef-form><?php af_field(); ?><input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">
<header class="ef-head">
  <span class="ef-head__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h16v14H4z"></path><path d="M8 4v4M16 4v4M4 10h16"></path><?php if (!$isEdit) { ?><path d="M12 13v4M10 15h4"></path><?php } ?></svg></span>
  <div><h2 id="event-form-title"><?= $isEdit ? 'Edit event' : 'Create event' ?></h2><p><?= $isEdit ? 'Update the details students see when they register.' : 'Fill in the details students will see when they register.' ?></p></div>
  <a class="ef-close cancel-btn" href="events.php" aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"></path></svg></a>
</header>
<div class="ef-body">
  <?php if ($error) { ?><p role="alert" class="ef-alert"><?= e($error) ?></p><?php } ?>
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
  <button type="submit"><?= $isEdit ? 'Save changes' : 'Create event' ?></button>
</footer>
</form></section></div>
<?php } ?>
</main><script src="js/event-form.js?v=<?= filemtime(__DIR__ . '/js/event-form.js') ?>" defer></script></body></html>
