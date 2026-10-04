<?php
/**
 * Beauty-php-ai — Top navigation bar (app shell).
 * Expects: $page_title, $active
 */

$user = current_user();
$isAdmin = is_admin();
$base = $isAdmin ? 'admin' : 'receptionist';
$dashUrl = base_url($base . '/dashboard.php');

$unreadCount = 0;
$recentNotes = [];
try {
    $stmt = db()->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id = :uid AND is_read = 0');
    $stmt->execute(['uid' => $user['id']]);
    $unreadCount = (int)$stmt->fetch()['c'];

    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5');
    $stmt->execute(['uid' => $user['id']]);
    $recentNotes = $stmt->fetchAll();
} catch (Throwable $e) {
}

?>
<nav class="navbar app-navbar navbar-expand-lg sticky-top">
    <div class="container-fluid px-3 px-lg-4">
        <button class="btn btn-link d-lg-none text-body p-0 me-2" id="sidebarToggle" type="button" aria-label="Toggle navigation">
            <i class="bi bi-list fs-3"></i>
        </button>

        <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="<?php echo $dashUrl; ?>">
            <span class="brand-mark"><i class="bi bi-scissors"></i></span>
            <span class="brand-name d-none d-sm-inline"><?php echo e(salon_name()); ?></span>
        </a>

        <div class="d-flex align-items-center gap-2 gap-lg-3 ms-auto">

            <div class="dropdown">
                <button class="btn btn-icon position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                    <i class="bi bi-bell"></i>
                    <?php if ($unreadCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?>
                        </span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end notification-drop shadow-lg rounded-3" style="width:340px;">
                    <div class="dropdown-header d-flex justify-content-between align-items-center">
                        <strong>Notifications</strong>
                        <a href="<?php echo base_url($base . '/notifications.php'); ?>" class="small text-decoration-none">View all</a>
                    </div>
                    <?php if (!$recentNotes): ?>
                        <div class="dropdown-item text-muted small">No notifications yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentNotes as $note): ?>
                            <a class="dropdown-item <?php echo $note['is_read'] ? '' : 'fw-semibold'; ?>" href="<?php echo base_url($base . '/notifications.php'); ?>">
                                <div class="small">
                                    <?php if ($note['type'] === 'warning') echo '<i class="bi bi-exclamation-triangle text-warning me-1"></i>'; ?>
                                    <?php if ($note['type'] === 'success') echo '<i class="bi bi-check-circle text-success me-1"></i>'; ?>
                                    <?php if ($note['type'] === 'info') echo '<i class="bi bi-info-circle text-info me-1"></i>'; ?>
                                    <?php if ($note['type'] === 'danger') echo '<i class="bi bi-x-circle text-danger me-1"></i>'; ?>
                                    <?php echo e($note['title']); ?>
                                </div>
                                <div class="small text-muted text-truncate"><?php echo e($note['message']); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dropdown">
                <button class="btn btn-user d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar"><?php echo e(initials($user['name'])); ?></span>
                    <span class="d-none d-md-block text-start">
                        <span class="d-block user-name small lh-1"><?php echo e($user['name']); ?></span>
                        <span class="d-block user-role small text-muted"><?php echo e(ucfirst($user['role'])); ?></span>
                    </span>
                    <i class="bi bi-chevron-down small text-muted"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow rounded-3">
                    <li><span class="dropdown-header small text-muted">Signed in as <strong><?php echo e($user['email']); ?></strong></span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?php echo $dashUrl; ?>"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                    <?php if ($isAdmin): ?>
                        <li><a class="dropdown-item" href="<?php echo base_url($base . '/settings.php'); ?>"><i class="bi bi-gear me-2"></i>Settings</a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="<?php echo base_url('logout.php'); ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>
