<?php
/**
 * Beauty-php-ai — Super Admin: platform audit log.
 *
 * Every control-panel action (login, lifecycle change, module edit, …) is
 * recorded in super_admin_audit_logs. This screen is read-only and offers
 * search / action / date filters.
 */

require_once __DIR__ . '/../includes/init.php';
require_super_admin();

$q      = trim((string)($_GET['q'] ?? ''));
$action = (string)($_GET['action'] ?? '');
$date   = (string)($_GET['date'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

$where  = [];
$params = [];

if ($q !== '') {
    $where[]      = '(description LIKE :q OR super_admin_name LIKE :q2 OR target_salon_name LIKE :q3)';
    $params['q']  = '%' . $q . '%';
    $params['q2'] = '%' . $q . '%';
    $params['q3'] = '%' . $q . '%';
}

if ($action !== '' && preg_match('/^[a-z0-9_]+$/i', $action)) {
    $where[]          = 'action = :action';
    $params['action'] = $action;
}

if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where[]        = 'DATE(created_at) = :date';
    $params['date'] = $date;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM super_admin_audit_logs {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pg    = paginate($total, $perPage, (int)($_GET['page'] ?? 1));

$listStmt = db()->prepare(
    "SELECT * FROM super_admin_audit_logs {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$pg['offset']}"
);
$listStmt->execute($params);
$logs = $listStmt->fetchAll();

$actions = [];
try {
    $actions = db()->query('SELECT DISTINCT action FROM super_admin_audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $actions = [];
}

$page_title = 'Audit Logs';
$active     = 'audit-logs';
include __DIR__ . '/../includes/super-admin/header.php';
?>

<div class="sa-card mb-3">
    <div class="sa-card-body">
        <form method="get" action="audit-logs.php" class="d-flex flex-wrap gap-2">
            <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>"
                   placeholder="Search description, admin or salon" style="max-width:260px;">
            <select class="form-select" name="action" style="max-width:200px;">
                <option value="">All actions</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?php echo e($a); ?>" <?php echo $action === $a ? 'selected' : ''; ?>>
                        <?php echo e(str_replace('_', ' ', $a)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="date" class="form-control" name="date" value="<?php echo e($date); ?>" style="max-width:170px;">
            <button class="btn btn-charcoal rounded-pill px-3" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
            <?php if ($q !== '' || $action !== '' || $date !== ''): ?>
                <a class="btn btn-light rounded-pill px-3" href="audit-logs.php">Clear</a>
            <?php endif; ?>
            <span class="ms-auto align-self-center small text-muted"><?php echo (int)$total; ?> entr(ies)</span>
        </form>
    </div>
</div>

<div class="sa-card">
    <?php if (!$logs): ?>
        <div class="sa-empty"><i class="bi bi-journal"></i>No audit entries match this filter.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="sa-table">
                <thead>
                    <tr><th>When</th><th>Admin</th><th>Action</th><th>Target salon</th><th>Description</th><th>IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="small text-nowrap"><?php echo e(fmt_datetime($log['created_at'])); ?></td>
                        <td class="small"><?php echo e($log['super_admin_name'] ?: 'System'); ?></td>
                        <td class="small"><span class="sa-status sa-status-inactive"><?php echo e(str_replace('_', ' ', $log['action'])); ?></span></td>
                        <td class="small">
                            <?php if ($log['target_salon_id']): ?>
                                <a class="text-decoration-none" href="<?php echo e(super_admin_url('account-details.php?id=' . (int)$log['target_salon_id'])); ?>">
                                    <?php echo e($log['target_salon_name'] ?: ('#' . (int)$log['target_salon_id'])); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?php echo e($log['description'] ?: '—'); ?></td>
                        <td class="small text-muted"><?php echo e($log['ip_address'] ?: '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pg['totalPages'] > 1): ?>
            <div class="sa-card-body border-top d-flex justify-content-between align-items-center">
                <span class="small text-muted">Page <?php echo (int)$pg['page']; ?> of <?php echo (int)$pg['totalPages']; ?></span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $pg['hasPrev'] ? '' : 'disabled'; ?>">
                            <a class="page-link" href="audit-logs.php?<?php echo e(query_string(['page' => $pg['prevPage']])); ?>">Prev</a>
                        </li>
                        <li class="page-item <?php echo $pg['hasNext'] ? '' : 'disabled'; ?>">
                            <a class="page-link" href="audit-logs.php?<?php echo e(query_string(['page' => $pg['nextPage']])); ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/super-admin/footer.php'; ?>
