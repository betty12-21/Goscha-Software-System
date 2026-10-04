<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Audit logs (Administrator only).
 */

require_permission('audit_logs');

$q       = trim($_GET['q'] ?? '');
$module  = $_GET['module'] ?? '';
$date    = $_GET['date'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where  = [];
$params = [];
if ($q !== '') {
    $like = "%{$q}%";
    $where[] = '(l.description LIKE :q1 OR u.name LIKE :q2 OR u.email LIKE :q3)';
    $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
}
if (preg_match('/^[a-z_]+$/', $module) && $module !== '') {
    $where[] = 'l.module = :module';
    $params['module'] = $module;
}
if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where[] = 'DATE(l.created_at) = :date';
    $params['date'] = $date;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare("SELECT COUNT(*) FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id {$whereSql}");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pg    = paginate($total, $perPage, $page);

$stmt = db()->prepare(
    "SELECT l.*, u.name AS user_name, u.email AS user_email
     FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id
     {$whereSql}
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT {$perPage} OFFSET {$pg['offset']}"
);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$modules = db()->query('SELECT DISTINCT module FROM audit_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);

$actionColors = [
    'login' => 'success', 'logout' => 'secondary', 'create' => 'info', 'update' => 'warning',
    'delete' => 'danger', 'payment' => 'primary', 'status' => 'info', 'settings' => 'dark',
];

$page_title = 'Audit Logs';
$active     = 'audit_logs';
include __DIR__ . '/../includes/header.php';
?>

<div class="card table-card">
    <div class="card-body p-3">
        <form method="get" action="audit-logs.php" class="d-flex flex-wrap gap-2">
            <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="Search user or action" style="width:220px;">
            <select class="form-select" name="module" style="width:160px;">
                <option value="">All modules</option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?php echo e($m); ?>" <?php echo $module === $m ? 'selected' : ''; ?>><?php echo e(ucwords(str_replace('-', ' ', $m))); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" class="form-control" name="date" value="<?php echo e($date); ?>">
            <button class="btn btn-charcoal" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="audit-logs.php" class="btn btn-light">Reset</a>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Module</th><th>Description</th><th>IP</th></tr></thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="6"><div class="empty-state"><div class="icon"><i class="bi bi-journal-text"></i></div><p class="text-muted mb-0">No audit records found.</p></div></td></tr>
            <?php else: foreach ($logs as $log): ?>
                <tr>
                    <td class="small text-nowrap"><?php echo fmt_datetime($log['created_at']); ?></td>
                    <td class="small"><?php echo $log['user_name'] ? e($log['user_name']) : '<span class="text-muted">—</span>'; ?><br><span class="text-muted"><?php echo $log['user_email'] ? e($log['user_email']) : 'System'; ?></span></td>
                    <td><span class="badge rounded-pill bg-<?php echo $actionColors[$log['action']] ?? 'light'; ?> text-white"><?php echo e(ucfirst($log['action'])); ?></span></td>
                    <td class="small"><?php echo e(ucwords(str_replace('-', ' ', $log['module']))); ?></td>
                    <td class="small"><?php echo e($log['description']); ?></td>
                    <td class="small text-muted"><?php echo e($log['ip_address'] ?: '—'); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pg['totalPages'] > 1): ?>
        <div class="card-body border-top d-flex justify-content-between align-items-center">
            <span class="small text-muted"><?php echo $pg['total']; ?> record(s)</span>
            <nav><ul class="pagination pagination-sm mb-0">
                <li class="page-item <?php echo $pg['hasPrev'] ? '' : 'disabled'; ?>"><a class="page-link" href="audit-logs.php?<?php echo e(query_string(['page' => $pg['prevPage']])); ?>">Prev</a></li>
                <li class="page-item disabled"><span class="page-link">Page <?php echo $pg['page']; ?> of <?php echo $pg['totalPages']; ?></span></li>
                <li class="page-item <?php echo $pg['hasNext'] ? '' : 'disabled'; ?>"><a class="page-link" href="audit-logs.php?<?php echo e(query_string(['page' => $pg['nextPage']])); ?>">Next</a></li>
            </ul></nav>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
