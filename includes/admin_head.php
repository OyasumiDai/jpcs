<?php
// Restore appearance before the first paint; every admin page shares these assets.
if (!defined('JPCS_ADMIN_HEAD')) {
    define('JPCS_ADMIN_HEAD', true);
?>
<script src="js/theme-init.js"></script>
<script>try{document.documentElement.dataset.adminSidebarCollapsed=String(innerWidth>860&&localStorage.getItem('jpcs-admin-sidebar-collapsed')==='true');}catch(error){}</script>
<?php foreach (['admin-tokens','admin-panel','admin-sidebar','admin-features','admin-dashboard'] as $adminStyle) {
    $adminStylePath = __DIR__ . '/../assets/css/' . $adminStyle . '.css';
    if (is_file($adminStylePath)) { ?>
<link rel="stylesheet" href="assets/css/<?= $adminStyle ?>.css?v=<?= filemtime($adminStylePath) ?>">
<?php } } } ?>
