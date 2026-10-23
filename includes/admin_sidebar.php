<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$sidebarUser = current_user();
$sidebarRole = (string) ($sidebarUser['role'] ?? '');
$sidebarIsAdmin = in_array($sidebarRole, ['admin_advisor', 'admin_adviser'], true);
$sidebarName = (string) ($sidebarUser['name'] ?? '');
$sidebarInitials = '';
foreach (preg_split('/\s+/', trim($sidebarName)) ?: [] as $part) {
    if ($part !== '') {
        $sidebarInitials .= strtoupper(substr($part, 0, 1));
    }
}
$sidebarInitials = substr($sidebarInitials !== '' ? $sidebarInitials : 'JPCS', 0, 2);
$sidebarPhoto = '';
$sidebarPending = 0;

if (!empty($sidebarUser['id'])) {
    $sidebarID = (int) $sidebarUser['id'];
    $sidebarStatement = $conn->prepare('SELECT photo, position FROM users WHERE id=? LIMIT 1');
    if ($sidebarStatement) {
        $sidebarStatement->bind_param('i', $sidebarID);
        $sidebarStatement->execute();
        $sidebarRecord = $sidebarStatement->get_result()->fetch_assoc() ?: [];
        $sidebarStatement->close();
        $sidebarPhoto = (string) ($sidebarRecord['photo'] ?? '');
        $sidebarRoleLabel = (string) ($sidebarRecord['position'] ?? '');
    }
}
$sidebarRoleLabel = !empty($sidebarRoleLabel) ? $sidebarRoleLabel : ($sidebarIsAdmin ? 'Advisor' : 'Officer');
$sidebarPage = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
$sidebarPhotoUrl = $sidebarPhoto !== '' && function_exists('image_url') ? image_url($sidebarPhoto) : '';

try {
    $sidebarPendingResult = $conn->query("SELECT COUNT(*) AS total FROM registrations WHERE status='pending'");
    $sidebarPendingRow = $sidebarPendingResult ? $sidebarPendingResult->fetch_assoc() : [];
    $sidebarPending = (int) ($sidebarPendingRow['total'] ?? 0);
} catch (Throwable $exception) {
    $sidebarPending = 0;
}

// Unread notifications for the sidebar badge; the panel itself loads them from notifications.php.
require_once __DIR__ . '/notifications.php';
$sidebarUnread = 0;
try {
    $sidebarUnread = notifications_unread_count($conn, $sidebarUser);
} catch (Throwable $exception) {
    error_log('JPCS notifications: ' . $exception->getMessage());
}
$sidebarNotifyToken = $_SESSION['admin_features_csrf'] ??= bin2hex(random_bytes(32));

$sidebarLink = static function (string $href, string $label, string $icon, bool $visible = true, ?int $badge = null) use ($sidebarPage): string {
    if (!$visible) return '';
    $active = $sidebarPage === $href || ($href === 'attendance.php' && in_array($sidebarPage, ['attendance_event.php', 'attendance_section.php'], true));
    $current = $active ? ' aria-current="page"' : '';
    $badgeMarkup = $badge !== null && $badge > 0 ? '<span class="admin-sidebar__badge">' . $badge . '</span>' : '';
    return '<a class="admin-sidebar__link' . ($active ? ' is-active' : '') . '" href="' . e($href) . '" title="' . e($label) . '" data-tooltip="' . e($label) . '"' . $current . '><span class="admin-sidebar__icon" aria-hidden="true">' . $icon . '</span><span class="admin-sidebar__label">' . e($label) . '</span>' . $badgeMarkup . '</a>';
};
?>
<script>(function(){try{if(localStorage.getItem('jpcs-admin-sidebar-collapsed')==='true'&&window.innerWidth>860){document.documentElement.setAttribute('data-admin-sidebar-collapsed','true');}}catch(e){}}());</script>
<script>document.body.classList.add('jpcs-admin');</script>
<?php require_once __DIR__ . '/admin_head.php'; ?>
<div class="admin-topbar" role="banner">
  <button class="admin-sidebar-toggle" type="button" aria-label="Open navigation" aria-controls="adminSidebar" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
  <div class="admin-topbar__heading"><a href="dashboard.php">JPCS / Workspace</a><strong data-admin-page-title>Dashboard</strong></div>
  <div class="admin-topbar__actions">
    <button class="theme-toggle" type="button" data-admin-theme aria-label="Switch theme" title="Switch theme"><span class="theme-icon" aria-hidden="true"></span></button>
    <button class="admin-bell" type="button" data-notify-open aria-label="Notifications" title="Notifications"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg></button>
    <details class="admin-menu"><summary aria-label="Account menu"><span class="admin-sidebar__avatar"><?php if ($sidebarPhotoUrl !== '') { ?><img src="<?php echo e($sidebarPhotoUrl); ?>" alt=""><?php } else { echo e($sidebarInitials); } ?></span></summary><div class="admin-menu__panel"><strong><?php echo e($sidebarName); ?></strong><small><?php echo e($sidebarRoleLabel); ?></small><span class="admin-menu__divider" aria-hidden="true"></span><a class="admin-menu__link" href="profile.php"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c.8-3.6 3.3-5.5 7-5.5s6.2 1.9 7 5.5"></path></svg>My Profile</a><a class="admin-menu__link" href="index.php"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle><path d="M4 12h16M12 4c2 2.2 3 4.9 3 8s-1 5.8-3 8M12 4c-2 2.2-3 4.9-3 8s1 5.8 3 8"></path></svg>Landing Page</a><span class="admin-menu__divider" aria-hidden="true"></span><a class="admin-menu__link is-danger" href="logout.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5M14 8l4 4-4 4M9 12h9"></path></svg>Logout</a></div></details>
  </div>
</div>
<div class="admin-sidebar-overlay" data-sidebar-overlay hidden></div>
<aside class="admin-sidebar" id="adminSidebar">
  <div class="admin-sidebar__brand-row">
    <a class="admin-sidebar__brand" href="dashboard.php" aria-label="JPCS dashboard" title="Dashboard">
      <span class="admin-sidebar__brand-mark"><img src="assets/img/jpcs-logo.png" alt=""><span class="admin-sidebar__expand-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"></path><path d="M4 5v14"></path></svg></span></span>
      <span class="admin-sidebar__brand-copy"><strong>JPCS</strong><small>City of Malabon University</small></span>
    </a>
    <button class="admin-sidebar__expand" type="button" aria-label="Open sidebar" aria-expanded="false" aria-controls="adminSidebar" data-tooltip="Open sidebar"><img src="assets/img/jpcs-logo.png" alt=""><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9 4v16m4-12 4 4-4 4"/></svg></button>
    <button class="admin-sidebar__collapse" type="button" aria-label="Collapse sidebar" aria-expanded="true" title="Collapse sidebar"><svg viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"></path><path d="M20 5v14"></path></svg></button>
  </div>
  <div class="admin-sidebar__divider"></div>
  <nav class="admin-sidebar__nav" aria-label="Admin navigation">
    <p class="admin-sidebar__section-label">Overview</p>
    <?php echo $sidebarLink('dashboard.php', 'Dashboard', '<svg viewBox="0 0 24 24"><rect x="4" y="4" width="6" height="6" rx="1"></rect><rect x="14" y="4" width="6" height="6" rx="1"></rect><rect x="4" y="14" width="6" height="6" rx="1"></rect><rect x="14" y="14" width="6" height="6" rx="1"></rect></svg>'); ?>
    <?php echo $sidebarLink('officers.php', 'Officer Information', '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"></circle><path d="M3 20c.6-3.3 2.6-5 6-5s5.4 1.7 6 5"></path><path d="M16 5.5a3 3 0 0 1 0 5.8M17 15c2.1.5 3.4 1.9 4 5"></path></svg>'); ?>
    <p class="admin-sidebar__section-label">Members &amp; Attendance</p>
    <?php echo $sidebarLink('pending.php', 'Student Registered Pending', '<svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"></path><path d="M8 9h8M8 13h5"></path></svg>', true, $sidebarPending); ?>
    <?php echo $sidebarLink('scanner.php', 'Event Attendance Scanner', '<svg viewBox="0 0 24 24"><path d="M4 8V5h3M17 5h3v3M20 16v3h-3M7 19H4v-3"></path><path d="M8 12h8M8 15h5"></path></svg>'); ?>
    <?php echo $sidebarLink('attendance.php', 'Attendance List per Event', '<svg viewBox="0 0 24 24"><path d="M5 4h14v16H5z"></path><path d="M8 8h8M8 12h8M8 16h5"></path></svg>'); ?>
    <?php echo $sidebarLink('register_list.php', 'Register List', '<svg viewBox="0 0 24 24"><path d="M5 4h14v16H5z"></path><path d="M8 8h8M8 12h6M8 16h4"></path></svg>'); ?>
    <?php echo $sidebarLink('masterlist.php', 'Student Masterlist', '<svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6z"></path><path d="M14 3v5h5"></path><path d="M9 12h7M9 16h7M9 8h2"></path></svg>'); ?>
    <p class="admin-sidebar__section-label">Events &amp; Settings</p>
    <?php echo $sidebarLink('events.php', 'Event Management', '<svg viewBox="0 0 24 24"><path d="M4 6h16v14H4z"></path><path d="M8 4v4M16 4v4M4 10h16"></path></svg>'); ?>
    <?php echo $sidebarLink('accounts.php', 'Account Management', '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"></circle><path d="M5 20c.8-3.3 3.2-5 7-5s6.2 1.7 7 5"></path><path d="M19 3v4M17 5h4"></path></svg>', $sidebarIsAdmin); ?>
  </nav>
  <div class="admin-sidebar__bottom">
    <div class="admin-sidebar__divider"></div>
    <a class="admin-sidebar__link admin-sidebar__notify<?php echo $sidebarPage === 'notifications.php' ? ' is-active' : ''; ?>" href="notifications.php" title="Notifications" data-tooltip="Notifications" data-notify-open data-notify-token="<?php echo e($sidebarNotifyToken); ?>"<?php echo $sidebarPage === 'notifications.php' ? ' aria-current="page"' : ''; ?>><span class="admin-sidebar__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"></path></svg></span><span class="admin-sidebar__label">Notifications</span><span class="admin-sidebar__badge" data-notify-count<?php echo $sidebarUnread > 0 ? '' : ' hidden'; ?>><?php echo $sidebarUnread > 9 ? '9+' : $sidebarUnread; ?></span></a>
    <a class="admin-sidebar__profile" href="profile.php" aria-label="My Profile" title="My Profile - <?php echo e($sidebarName); ?>, <?php echo e($sidebarRoleLabel); ?>" data-tooltip="My Profile">
      <span class="admin-sidebar__avatar"><?php if ($sidebarPhotoUrl !== '') { ?><img src="<?php echo e($sidebarPhotoUrl); ?>" alt=""><?php } else { echo e($sidebarInitials); } ?></span>
      <span><strong>My Profile</strong><small><?php echo e($sidebarName); ?> · <?php echo e($sidebarRoleLabel); ?></small></span>
    </a>
    <a class="admin-sidebar__public" href="index.php" title="Landing Page" data-tooltip="Landing Page"><span class="admin-sidebar__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><path d="M4 12h16M12 4c2 2.2 3 4.9 3 8s-1 5.8-3 8c-2-2.2-3-4.9-3-8s1-5.8 3-8Z"></path></svg></span><span class="admin-sidebar__label">Landing Page</span></a>
    <a class="admin-sidebar__logout" href="logout.php" title="Logout" data-tooltip="Logout"><span class="admin-sidebar__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 5H5v14h5M14 8l4 4-4 4M9 12h9"></path></svg></span><span class="admin-sidebar__label">Logout</span></a>
  </div>
</aside>
<script src="js/notifications.js?v=<?php echo filemtime(__DIR__ . '/../js/notifications.js'); ?>" defer></script>
<script src="js/admin-sidebar.js?v=<?php echo filemtime(__DIR__ . '/../js/admin-sidebar.js'); ?>" defer></script>
<script src="js/admin-panel.js?v=<?php echo filemtime(__DIR__ . '/../js/admin-panel.js'); ?>" defer></script>
