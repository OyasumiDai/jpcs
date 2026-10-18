<?php
require_once __DIR__.'/admin_features.php';
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
<section aria-label="Monthly event calendar"><h2><?= e($first->format('F Y')) ?></h2>
<a href="?view=calendar&amp;month=<?= $first->modify('-1 month')->format('Y-m') ?>">Previous month</a> · <a href="?view=calendar&amp;month=<?= $first->modify('+1 month')->format('Y-m') ?>">Next month</a>
<div class="event-calendar">
<?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day) { ?><strong><?= $day ?></strong><?php }
for ($blank=1;$blank<(int)$first->format('N');$blank++) echo '<div></div>';
for ($day=1;$day<=(int)$first->format('t');$day++) { $date=$month.'-'.sprintf('%02d',$day); ?>
<div class="calendar-day"><strong><?= $day ?></strong>
<?php foreach ($events as $event) { if ($event['event_date']!==$date) continue; ?>
<p><a href="events.php?<?= $isAdmin?'edit':'event' ?>=<?= (int)$event['id'] ?>#event-<?= (int)$event['id'] ?>"><?= e($event['title']) ?></a><br><small><?= e(substr($event['event_time'] ?? '',0,5)) ?> · <?= e($event['status']) ?></small></p>
<?php } ?></div><?php } ?></div></section>
<?php } else { ?>
<div class="table-wrapper"><table><thead><tr><th>Poster</th><th>Event</th><th>Date</th><th>Time</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($events as $event) { ?>
<tr id="event-<?= (int)$event['id'] ?>"><td><a href="<?= e(image_url($event['poster'])) ?>" target="_blank" rel="noopener"><img src="<?= e(image_url($event['poster'])) ?>" alt="<?= e($event['title']) ?> poster" width="64" height="80" class="event-poster"></a></td><td><strong><?= e($event['title']) ?></strong><details><summary>Description</summary><?= nl2br(e($event['description'])) ?></details></td><td><?= e($event['event_date']) ?></td><td><?= e(substr($event['event_time'] ?? '',0,5)) ?></td><td><?= e($event['location']) ?></td><td><?= e($event['status']) ?></td><td>
<?php if ($isAdmin) { ?><a class="btn" href="?edit=<?= (int)$event['id'] ?>">Edit</a>
<?php if ($event['status']==='upcoming') { ?><form method="post" class="inline-action"><?php af_field(); ?><input type="hidden" name="id" value="<?= (int)$event['id'] ?>"><button name="done" value="1" type="submit">Mark done</button></form><?php } } ?>
<a href="register_list.php?event_id=<?= (int)$event['id'] ?>">Registrations</a></td></tr>
<?php } if (!$events) { ?><tr><td colspan="7">No events yet.</td></tr><?php } ?></tbody></table></div>
<?php }
if ($isAdmin && (isset($_GET['createform']) || $edit)) { $form=$edit ?: []; ?>
<div class="modal"><section class="modal-content" role="dialog" aria-modal="true" aria-labelledby="event-form-title"><h2 id="event-form-title"><?= !empty($form['id'])?'Edit event':'Create event' ?></h2>
<?php if ($error) { ?><p role="alert"><?= e($error) ?></p><?php } ?>
<form method="post" enctype="multipart/form-data"><?php af_field(); ?><input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">
<label for="title">Title</label><input id="title" name="title" maxlength="100" required value="<?= e($form['title'] ?? '') ?>">
<label for="description">Description</label><textarea id="description" name="description" maxlength="16000"><?= e($form['description'] ?? '') ?></textarea>
<label for="event_date">Date</label><input id="event_date" type="date" name="event_date" required value="<?= e($form['event_date'] ?? '') ?>">
<label for="event_time">Time</label><input id="event_time" type="time" name="event_time" required value="<?= e(substr($form['event_time'] ?? '',0,5)) ?>">
<label for="location">Location</label><input id="location" name="location" maxlength="255" required value="<?= e($form['location'] ?? '') ?>">
<label for="status">Status</label><select id="status" name="status"><option value="upcoming">Upcoming</option><option value="done" <?= ($form['status'] ?? '')==='done'?'selected':'' ?>>Done</option></select>
<?php foreach (['poster'=>'Poster','pass_image'=>'Event pass image'] as $field=>$label) { ?>
<label for="<?= $field ?>"><?= $label ?> (JPEG, PNG, WebP; max 5 MB)</label><input id="<?= $field ?>" type="file" name="<?= $field ?>" accept="image/jpeg,image/png,image/webp" data-preview="<?= $field ?>-preview">
<img id="<?= $field ?>-preview" src="<?= e(image_url($form[$field] ?? null)) ?>" alt="<?= $label ?> preview" class="event-image-preview">
<?php } ?><p>Leave image fields empty to keep existing images.</p><button type="submit">Save event</button> <a href="events.php">Cancel</a></form></section></div>
<?php } ?>
</main><script>
document.querySelectorAll('[data-preview]').forEach(input => input.addEventListener('change', () => {
 const image=document.getElementById(input.dataset.preview), file=input.files[0];
 if (image.dataset.objectUrl) URL.revokeObjectURL(image.dataset.objectUrl);
 if (file) { image.dataset.objectUrl=URL.createObjectURL(file); image.src=image.dataset.objectUrl; }
}));
</script></body></html>
