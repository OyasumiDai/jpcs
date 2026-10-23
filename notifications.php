<?php
declare(strict_types=1);
// Notifications for advisers and officers: JSON feed for the sidebar panel, "mark read", and a full page.
require_once __DIR__ . '/admin_features.php';
require_once __DIR__ . '/includes/notifications.php';

$notifyUser = af_user($conn, (int) $_SESSION['user_id']);
if (!in_array($notifyUser['role'] ?? '', ['admin_advisor', 'admin_adviser', 'officer'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

if (request_method_is('POST')) {
    af_csrf();
    notifications_mark_read($conn, (int) $notifyUser['id']);
    if (($_POST['format'] ?? '') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'unread' => 0]);
        exit;
    }
    af_success('notifications.php', 'All notifications marked as read.');
}

$notifyItems = notifications_feed($conn, $notifyUser);
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $notifyPending = (int) ($conn->query("SELECT COUNT(*) AS n FROM registrations WHERE status='pending'")->fetch_assoc()['n'] ?? 0);
    echo json_encode(['unread' => count(array_filter($notifyItems, static fn($item) => $item['unread'])), 'pending' => $notifyPending, 'now' => time(), 'items' => $notifyItems]);
    exit;
}

$notifyUnread = count(array_filter($notifyItems, static fn($item) => $item['unread']));
af_head('Notifications');
?>
<p class="ml-intro">Activity from the last <?= NOTIFICATIONS_DAYS ?> days: registrations, check-ins, masterlist uploads and event changes. Your own actions are not listed.</p>
<div class="notify-page-actions">
  <span><?= $notifyUnread ?> unread</span>
  <?php if ($notifyUnread > 0) { ?><form method="post"><?php af_field(); ?><button type="submit" class="btn-secondary">Mark all as read</button></form><?php } ?>
</div>
<?php if (!$notifyItems) { ?>
  <div class="empty-state">You're all caught up. New activity will appear here.</div>
<?php } else { ?>
  <?php
  $notifyGroups = [];
  foreach ($notifyItems as $item) {
      $day = date('Y-m-d', $item['at']);
      $label = $day === date('Y-m-d') ? 'Today' : ($day === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : ($item['at'] >= strtotime('-6 days midnight') ? 'This week' : 'Earlier'));
      $notifyGroups[$label][] = $item;
  }
  ?>
  <div class="notify-list--page">
    <?php foreach ($notifyGroups as $label => $items) { ?>
    <section class="notify-group"><h3><?= e($label) ?></h3><ul class="notify-list">
      <?php foreach ($items as $item) { ?>
        <li class="notify-item is-<?= e($item['kind']) ?><?= $item['unread'] ? ' is-unread' : '' ?>">
          <a href="<?= e($item['url']) ?>">
            <span class="notify-icon" aria-hidden="true"><?= $item['icon'] ?></span>
            <span class="notify-body"><strong><?= e($item['title']) ?></strong><?php if ($item['detail'] !== '') { ?><small><?= e($item['detail']) ?></small><?php } ?></span>
            <time datetime="<?= e(date('c', $item['at'])) ?>"><?= e($item['ago']) ?></time>
          </a>
        </li>
      <?php } ?>
    </ul></section>
    <?php } ?>
  </div>
<?php } ?>
<?php af_end();
