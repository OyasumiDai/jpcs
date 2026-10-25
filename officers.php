<?php
require_once __DIR__ . '/admin_features.php';
require_once __DIR__ . '/includes/public_officers.php';
require_login();
require_once __DIR__ . '/includes/permissions.php';
permissions_require($conn, 'officers');
ensure_public_officers_table($conn);

$userID = (int) ($_SESSION['user_id'] ?? 0);
$userStatement = $conn->prepare('SELECT role, name FROM users WHERE id=? LIMIT 1');
$userStatement->bind_param('i', $userID);
$userStatement->execute();
$currentUser = $userStatement->get_result()->fetch_assoc() ?: [];
$userStatement->close();

$officerResult = $conn->query("SELECT id, username, name, role, position, photo, created_at
    FROM users WHERE role IN ('officer','admin_advisor','admin_adviser') ORDER BY id ASC");
$officers = [];
$positionSet = [];
while ($officer = $officerResult->fetch_assoc()) {
    $officer['source'] = 'account';
    $officer['email'] = filter_var($officer['username'], FILTER_VALIDATE_EMAIL) ? $officer['username'] : '';
    $officer['position'] = trim((string) ($officer['position'] ?? ''));
    $positionSet[$officer['position'] !== '' ? $officer['position'] : 'Unassigned'] = true;
    $officers[] = $officer;
}
$profileResult = $conn->query('SELECT id, name, position, photo, created_at FROM officer_profiles ORDER BY id ASC');
while ($profile = $profileResult->fetch_assoc()) {
    $profile['source'] = 'profile'; $profile['username'] = ''; $profile['role'] = 'officer'; $profile['email'] = '';
    $profile['position'] = trim((string) ($profile['position'] ?? ''));
    $positionSet[$profile['position'] !== '' ? $profile['position'] : 'Unassigned'] = true;
    $officers[] = $profile;
}
$positions = array_keys($positionSet);
natcasesort($positions);
$canManage = af_admin($conn);

function officerEscape($value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8'); }
function officerInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: []; $initials = '';
    foreach ($parts as $part) if ($part !== '') $initials .= strtoupper(substr($part, 0, 1));
    return officerEscape(substr($initials !== '' ? $initials : 'JPCS', 0, 2));
}
function officerRoleLabel(string $role): string { return in_array($role, ['admin_advisor', 'admin_adviser'], true) ? 'Adviser' : 'Officer'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/img/jpcs-logo.png?v=3">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Officer Information</title><?php require __DIR__ . '/includes/admin_head.php'; ?>
</head>
<body class="app-page admin-page jpcs-admin feature-officers">
<?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
<main class="content officers-page">
    <header class="page-header">
        <div><nav class="page-breadcrumb" aria-label="Breadcrumb"><a href="dashboard.php">Dashboard</a><span aria-hidden="true">›</span><span>Officer Information</span></nav><h1>Officer Information</h1><p>Meet the JPCS leadership team at City of Malabon University.</p></div>
        <?php if ($canManage) { ?><button class="btn btn-create" type="button" data-officer-add>Add Officer</button><?php } ?>
    </header>
    <?php if (isset($_SESSION['af_notice'])) { ?><p class="success" role="status"><?= e($_SESSION['af_notice']) ?></p><?php unset($_SESSION['af_notice']); } ?>
    <section class="officer-summary" aria-label="Officer summary">
        <article class="officer-summary-card"><span class="officer-summary-label">Total officers</span><strong><?= count($officers) ?></strong></article>
        <article class="officer-summary-card"><span class="officer-summary-label">Positions filled</span><strong><?= count($positionSet) ?></strong></article>
        <article class="officer-summary-card"><span class="officer-summary-label">Adviser and officer roles</span><strong><?= count(array_unique(array_map(static fn(array $item): string => officerRoleLabel((string) $item['role']), $officers))) ?></strong></article>
    </section>
    <section class="officer-toolbar panel-card" aria-label="Officer filters">
        <div class="officer-toolbar-main"><label class="officer-search"><span class="sr-only">Search officers</span><input type="search" data-officer-search placeholder="Search name, position, ID or email" autocomplete="off"></label><label><span class="sr-only">Filter by position</span><select data-officer-position><option value="">All positions</option><?php foreach ($positions as $position) { ?><option value="<?= officerEscape(strtolower($position)) ?>"><?= officerEscape($position) ?></option><?php } ?></select></label><label><span class="sr-only">Filter by role</span><select data-officer-role><option value="">All roles</option><option value="advisor">Adviser</option><option value="officer">Officer</option></select></label><label><span class="sr-only">Sort officers</span><select data-officer-sort><option value="hierarchy">Position hierarchy</option><option value="name">Name A–Z</option><option value="newest">Newest added</option></select></label></div>
        <div class="officer-toolbar-actions"><span class="officer-result-count" data-officer-count><?= count($officers) ?> officers</span><button class="btn btn-secondary" type="button" data-officer-clear>Clear filters</button><div class="officer-view-toggle" role="group" aria-label="Officer view"><button type="button" class="is-active" data-officer-view="cards" aria-pressed="true">Cards</button><button type="button" data-officer-view="table" aria-pressed="false">Table</button></div></div>
    </section>
    <p class="officer-empty admin-table-empty" data-officer-empty hidden>No officers match your current filters.</p>
    <section class="officer-grid" data-officer-cards aria-label="Officer cards">
    <?php foreach ($officers as $officer) { $name=(string)$officer['name']; $position=$officer['position']!==''?$officer['position']:'Unassigned'; $roleLabel=officerRoleLabel((string)$officer['role']); $photo=$officer['photo']?image_url($officer['photo']):''; $searchText=strtolower($name.' '.$position.' '.$officer['username'].' '.$roleLabel.' '.$officer['id']); $isProfile=($officer['source'] ?? 'account')==='profile'; ?>
        <article class="officer-card" data-officer-item data-name="<?= officerEscape($name) ?>" data-search="<?= officerEscape($searchText) ?>" data-position="<?= officerEscape(strtolower($position)) ?>" data-officer-position-value="<?= officerEscape($position === 'Unassigned' ? '' : $position) ?>" data-role="<?= officerEscape(strtolower($roleLabel)) ?>" data-created="<?= officerEscape((string)$officer['created_at']) ?>" data-officer-source="<?= $isProfile ? 'profile' : 'account' ?>" data-officer-id="<?= (int)$officer['id'] ?>" data-officer-photo="<?= officerEscape($photo) ?>" data-officer-username="<?= officerEscape((string)$officer['username']) ?>">
            <button class="officer-card-main" type="button" data-officer-details aria-label="View details for <?= officerEscape($name) ?>"><?php if ($photo) { ?><img class="officer-avatar" src="<?= officerEscape($photo) ?>" alt=""><?php } else { ?><span class="officer-avatar officer-avatar-initials" aria-hidden="true"><?= officerInitials($name) ?></span><?php } ?><span class="officer-card-copy"><strong><?= officerEscape($name) ?></strong><span class="role-badge"><?= officerEscape($position) ?></span><small><?= officerEscape($roleLabel) ?> · <?= $isProfile ? 'Public profile' : 'Officer account #'.(int)$officer['id'] ?></small></span></button>
            <div class="officer-card-hover-actions"><button type="button" data-officer-details>View details</button><?php if ($canManage) { ?><button class="btn btn-secondary" type="button" data-officer-edit>Edit</button><?php } ?></div>
            <template data-officer-detail><div class="officer-detail-content"><?php if ($photo) { ?><img class="officer-detail-photo" src="<?= officerEscape($photo) ?>" alt=""><?php } else { ?><span class="officer-detail-photo officer-avatar-initials" aria-hidden="true"><?= officerInitials($name) ?></span><?php } ?><h2><?= officerEscape($name) ?></h2><p><span class="role-badge"><?= officerEscape($position) ?></span> <span class="status-badge"><?= $isProfile ? 'Published profile' : 'Active account' ?></span></p><dl><dt>Role</dt><dd><?= officerEscape($roleLabel) ?></dd><dt><?= $isProfile ? 'Profile type' : 'Username / email' ?></dt><dd><?= $isProfile ? 'Public officer profile' : officerEscape($officer['username']) ?></dd><dt>Date added</dt><dd><?= officerEscape((string)$officer['created_at']) ?></dd></dl></div></template>
        </article>
    <?php } ?>
    </section>
    <section class="officer-table-wrap" data-officer-table hidden aria-label="Officer table"><div class="table-wrapper"><table><thead><tr><th>Officer</th><th>Position</th><th>Role</th><th>Contact</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($officers as $officer){$name=(string)$officer['name'];$position=$officer['position']!==''?$officer['position']:'Unassigned';$roleLabel=officerRoleLabel((string)$officer['role']);$isProfile=($officer['source'] ?? 'account')==='profile';$rowSearch=strtolower($name.' '.$position.' '.$officer['username'].' '.$roleLabel.' '.$officer['id']);$rowPhoto=$officer['photo']?image_url($officer['photo']):'';?><tr data-officer-row data-name="<?= officerEscape($name) ?>" data-search="<?= officerEscape($rowSearch) ?>" data-position="<?= officerEscape(strtolower($position)) ?>" data-officer-position-value="<?= officerEscape($position === 'Unassigned' ? '' : $position) ?>" data-role="<?= officerEscape(strtolower($roleLabel)) ?>" data-created="<?= officerEscape((string)$officer['created_at']) ?>" data-officer-source="<?= $isProfile ? 'profile' : 'account' ?>" data-officer-id="<?= (int)$officer['id'] ?>" data-officer-photo="<?= officerEscape($rowPhoto) ?>" data-officer-username="<?= officerEscape((string)$officer['username']) ?>"><td><strong><?= officerEscape($name) ?></strong><small><?= $isProfile ? 'Public profile' : 'Account #'.(int)$officer['id'] ?></small></td><td><?= officerEscape($position) ?></td><td><span class="role-badge"><?= officerEscape($roleLabel) ?></span></td><td><?php if($officer['email']){?><a href="mailto:<?= officerEscape($officer['email']) ?>"><?= officerEscape($officer['email']) ?></a><?php }else{?>Public profile<?php }?></td><td><span class="status-badge">Published</span></td><td><button type="button" class="btn btn-secondary" data-officer-row-details>View</button><?php if($canManage){?> <button type="button" class="btn btn-secondary" data-officer-edit>Edit</button><?php }?></td></tr><?php }?></tbody></table></div></section>
</main>
<div class="officer-drawer-backdrop" data-officer-backdrop hidden></div><aside class="officer-drawer" data-officer-drawer aria-label="Officer details" hidden><button class="officer-drawer-close" type="button" data-officer-close aria-label="Close officer details">×</button><div data-officer-drawer-content></div></aside>
<?php if ($canManage) { ?>
<div class="officer-modal-backdrop" data-officer-modal-backdrop hidden></div>
<section class="officer-modal" data-officer-modal role="dialog" aria-modal="true" aria-labelledby="officer-modal-title" hidden>
    <div class="officer-modal-card">
        <div class="officer-modal-header"><div><span class="officer-modal-eyebrow">Public profile <span class="officer-modal-step">Step 1 of 1</span></span><h2 id="officer-modal-title">Add Officer</h2><p>Create a polished public profile for the JPCS landing page. No login account will be created.</p></div><button type="button" data-officer-modal-close aria-label="Close Add Officer form">×</button></div>
        <form class="officer-modal-form" data-officer-add-form method="post" action="officers_admin.php?create=1" enctype="multipart/form-data" novalidate>
            <?php af_field(); ?><input type="hidden" name="profile_id" value="">
            <div class="officer-form-grid"><label><span class="officer-field-label">Full name <em>Required</em></span><input name="name" maxlength="100" required placeholder="e.g. Alex Dela Cruz"><small class="officer-field-meta"><span data-character-count="name">0/100</span><span data-error-for="name"></span></small></label><label><span class="officer-field-label">Position <em>Required</em></span><input name="position" maxlength="100" required placeholder="e.g. President"><small class="officer-field-meta"><span data-character-count="position">0/100</span><span data-error-for="position"></span></small></label></div>
            <label class="officer-upload" data-officer-drop><span class="officer-upload-preview" data-officer-preview><span aria-hidden="true">＋</span></span><span><strong>Upload profile photo <em>Optional</em></strong><small>Drop an image here or browse your device. JPG, PNG, or WEBP up to 5 MB.</small></span><input name="photo" type="file" accept="image/jpeg,image/png,image/webp" data-officer-photo></label>
            <div class="officer-modal-actions"><small class="officer-form-status" data-officer-form-status role="status">Your profile will be visible to guests and students.</small><button type="button" class="btn btn-secondary" data-officer-modal-close>Cancel</button><button type="submit" class="btn btn-create" data-officer-save>Add public officer</button></div>
        </form>
    </div>
</section>
<?php } ?>
<?php if ($canManage) { ?>
<div class="officer-modal-backdrop" data-officer-edit-backdrop hidden></div>
<section class="officer-modal" data-officer-edit-modal role="dialog" aria-modal="true" aria-labelledby="officer-edit-title" hidden>
    <div class="officer-modal-card">
        <div class="officer-modal-header"><div><span class="officer-modal-eyebrow">Profile settings</span><h2 id="officer-edit-title">Edit Officer</h2><p data-officer-edit-description>Update the information shown in this panel.</p></div><button type="button" data-officer-edit-close aria-label="Close edit officer form">×</button></div>
        <form class="officer-modal-form" data-officer-edit-form method="post" enctype="multipart/form-data" novalidate>
            <?php af_field(); ?><input type="hidden" name="id" value=""><input type="hidden" name="profile_id" value="">
            <div class="officer-form-grid"><label><span class="officer-field-label">Full name <em>Required</em></span><input name="name" maxlength="100" required><small class="officer-field-meta"><span data-edit-character-count="name">0/100</span><span data-edit-error-for="name"></span></small></label><label><span class="officer-field-label">Position</span><input name="position" maxlength="100"><small class="officer-field-meta"><span data-edit-character-count="position">0/100</span><span data-edit-error-for="position"></span></small></label></div>
            <label class="officer-upload" data-officer-edit-drop><span class="officer-upload-preview" data-officer-edit-preview><span aria-hidden="true">＋</span></span><span><strong>Replace profile photo <em>Optional</em></strong><small>Drop a new JPG, PNG, or WEBP image up to 5 MB.</small></span><input name="photo" type="file" accept="image/jpeg,image/png,image/webp" data-officer-edit-photo></label>
            <p class="officer-edit-account-note" data-officer-edit-account-note></p>
            <div class="officer-modal-actions"><small class="officer-form-status" data-officer-edit-status role="status">Changes are saved securely.</small><button type="button" class="btn btn-secondary" data-officer-edit-close>Cancel</button><button type="submit" class="btn btn-create" data-officer-edit-save>Save changes</button></div>
        </form>
    </div>
</section>
<?php } ?>
<script src="js/admin-officers.js?v=<?= filemtime(__DIR__ . '/js/admin-officers.js') ?>" defer></script>
</body></html>
