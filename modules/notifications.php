<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Notifications (both roles).
 */

require_permission('notifications');

$me = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? 'list';

/* ============================================================
 * Mark read / all read / delete
 * ============================================================ */
if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'mark_read') {
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('notifications.php'); }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    db()->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user")->execute(['id' => $id, 'user' => $me]);
    redirect('notifications.php');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'mark_all_read') {
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('notifications.php'); }
    db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user AND is_read = 0')->execute(['user' => $me]);
    flash_set('success', 'All notifications marked as read.');
    redirect('notifications.php');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'delete_notification') {
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('notifications.php'); }
    db()->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :user')->execute(['id' => (int)($_POST['id'] ?? $_GET['id'] ?? 0), 'user' => $me]);
    redirect('notifications.php');
}

$filter = ($_GET['filter'] ?? '') === 'unread' ? 'unread' : 'all';
if ($filter === 'unread') {
    $notifications = db()->prepare('SELECT * FROM notifications WHERE user_id = :user AND is_read = 0 ORDER BY created_at DESC LIMIT 100');
    $notifications->execute(['user' => $me]);
    $notifications = $notifications->fetchAll();
} else {
    $notifications = db()->prepare('SELECT * FROM notifications WHERE user_id = :user ORDER BY created_at DESC LIMIT 100');
    $notifications->execute(['user' => $me]);
    $notifications = $notifications->fetchAll();
}

$unreadCount = (int)db()->query('SELECT COUNT(*) FROM notifications WHERE user_id = ' . (int)$me . ' AND is_read = 0')->fetchColumn();

$typeIcon = ['success' => 'check-circle', 'danger' => 'exclamation-triangle', 'warning' => 'exclamation-circle', 'info' => 'info-circle'];

$page_title = 'Notifications';
$active     = 'notifications';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-body p-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div class="d-flex gap-2">
            <a href="notifications.php?filter=all" class="btn btn-sm <?php echo $filter === 'all' ? 'btn-charcoal' : 'btn-light'; ?> rounded-pill">All</a>
            <a href="notifications.php?filter=unread" class="btn btn-sm <?php echo $filter === 'unread' ? 'btn-charcoal' : 'btn-light'; ?> rounded-pill">Unread <?php echo $unreadCount ? "<span class='badge bg-danger rounded-pill ms-1'>{$unreadCount}</span>" : ''; ?></a>
        </div>
        <?php if ($unreadCount): ?>
            <form method="post" action="notifications.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="mark_all_read">
                <button class="btn btn-sm btn-rose rounded-pill" type="submit"><i class="bi bi-check2-all me-1"></i>Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>

    <ul class="list-group list-group-flush">
        <?php if (!$notifications): ?>
            <li class="list-group-item"><div class="empty-state">
                <div class="icon"><i class="bi bi-bell-slash"></i></div>
                <p class="text-muted mb-0">You're all caught up.</p>
            </div></li>
        <?php else: foreach ($notifications as $n): ?>
            <li class="list-group-item <?php echo !$n['is_read'] ? 'bg-rose-soft bg-opacity-25' : ''; ?>">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div class="d-flex gap-3">
                        <div class="notif-icon"><i class="bi bi-<?php echo $typeIcon[$n['type']] ?? 'info-circle'; ?>"></i></div>
                        <div>
                            <div class="fw-semibold"><?php echo e($n['title']); ?>
                                <?php if (!$n['is_read']): ?><span class="badge bg-rose rounded-pill ms-1">new</span><?php endif; ?>
                            </div>
                            <div class="small text-muted"><?php echo e($n['message']); ?></div>
                            <div class="small text-muted mt-1"><i class="bi bi-clock me-1"></i><?php echo time_ago($n['created_at']); ?></div>
                        </div>
                    </div>
                    <div class="d-flex gap-1">
                        <?php if (!$n['is_read']): ?>
                            <form method="post" action="notifications.php" class="d-inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="form_action" value="mark_read">
                                <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
                                <button class="btn btn-sm btn-light rounded-pill" title="Mark as read"><i class="bi bi-check2"></i></button>
                            </form>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                data-confirm="Delete this notification?"
                                data-confirm-action="notifications.php?form_action=delete_notification&id=<?php echo $n['id']; ?>"
                                data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            </li>
        <?php endforeach; endif; ?>
    </ul>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
