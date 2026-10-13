<?php
/**
 * Beauty-php-ai — Super Admin: salon accounts.
 *
 * Searchable, filterable list of every salon on the install. Non-destructive
 * lifecycle actions (approve / restore, which only ever widen access) are
 * available inline; restrictive actions (reject, suspend, cancel, deactivate)
 * live on the account details page so an operator must enter a reason.
 */

require_once __DIR__ . '/../includes/init.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token. Please try again.');
        redirect(super_admin_url('accounts.php'));
    }

    $salonId = (int)($_POST['salon_id'] ?? 0);
    $action  = (string)($_POST['action'] ?? '');

    if ($salonId <= 0) {
        flash_set('danger', 'Unknown salon.');
        redirect(super_admin_url('accounts.php'));
    }

    if ($action === 'approve' || $action === 'restore') {
        $result = super_admin_set_status($salonId, 'active');
        flash_set($result['success'] ? 'success' : 'danger', $result['success'] ? 'Salon is now active.' : $result['error']);
    } else {
        flash_set('danger', 'Unsupported action.');
    }

    redirect(super_admin_url('accounts.php'));
}

$q      = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where  = [];
$params = [];

if ($q !== '') {
    $where[]         = '(s.salon_name LIKE :q OR s.email LIKE :q2 OR s.phone LIKE :q3)';
    $params['q']     = '%' . $q . '%';
    $params['q2']    = '%' . $q . '%';
    $params['q3']    = '%' . $q . '%';
}

if (in_array($status, salon_statuses(), true)) {
    $where[]          = 's.status = :status';
    $params['status'] = $status;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM salons s {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pg    = paginate($total, $perPage, $page);

$listStmt = db()->prepare(
    "SELECT s.*,
            (SELECT COUNT(*) FROM users u WHERE u.salon_id = s.id) AS user_count
       FROM salons s
       {$whereSql}
       ORDER BY (s.status = 'pending') DESC, s.created_at DESC, s.id DESC
       LIMIT {$perPage} OFFSET {$pg['offset']}"
);
$listStmt->execute($params);
$salons = $listStmt->fetchAll();

$profileLabels = [];
foreach (access_profiles() as $key => $p) {
    $profileLabels[$key] = $p['label'];
}

$page_title = 'Salon Accounts';
$active     = 'accounts';
include __DIR__ . '/../includes/super-admin/header.php';
?>

<div class="sa-card mb-3">
    <div class="sa-card-body">
        <form method="get" action="accounts.php" class="d-flex flex-wrap gap-2">
            <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>"
                   placeholder="Search name, email or phone" style="max-width:280px;">
            <select class="form-select" name="status" style="max-width:180px;">
                <option value="">All statuses</option>
                <?php foreach (salon_statuses() as $st): ?>
                    <option value="<?php echo e($st); ?>" <?php echo $status === $st ? 'selected' : ''; ?>>
                        <?php echo e(ucfirst($st)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-charcoal rounded-pill px-3" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
            <?php if ($q !== '' || $status !== ''): ?>
                <a class="btn btn-light rounded-pill px-3" href="accounts.php">Clear</a>
            <?php endif; ?>
            <span class="ms-auto align-self-center small text-muted"><?php echo (int)$total; ?> salon(s)</span>
        </form>
    </div>
</div>

<div class="sa-card">
    <?php if (!$salons): ?>
        <div class="sa-empty"><i class="bi bi-shop"></i>No salons match this filter.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="sa-table">
                <thead>
                    <tr>
                        <th>Salon</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Plan</th>
                        <th>Users</th>
                        <th>Registered</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($salons as $s):
                    $id = (int)$s['id'];
                    $st = (string)$s['status'];
                    ?>
                    <tr>
                        <td>
                            <a class="fw-semibold text-decoration-none"
                               href="<?php echo e(super_admin_url('account-details.php?id=' . $id)); ?>">
                                <?php echo e($s['salon_name']); ?>
                            </a>
                            <div class="small text-muted">#<?php echo $id; ?></div>
                        </td>
                        <td class="small">
                            <?php echo e($s['email'] ?: '—'); ?><br>
                            <span class="text-muted"><?php echo e($s['phone'] ?: ''); ?></span>
                        </td>
                        <td><?php echo sa_status_badge($st); ?></td>
                        <td class="small text-muted"><?php echo e($profileLabels[$s['access_profile']] ?? ucfirst((string)$s['access_profile'])); ?></td>
                        <td class="small"><?php echo (int)$s['user_count']; ?></td>
                        <td class="small text-muted"><?php echo e(fmt_datetime($s['created_at'])); ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($st === 'pending') : ?>
                                <form method="post" action="accounts.php" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="salon_id" value="<?php echo $id; ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="btn btn-sm btn-rose rounded-pill px-3" type="submit">
                                        <i class="bi bi-check2 me-1"></i>Approve
                                    </button>
                                </form>
                            <?php elseif ($st === 'suspended' || $st === 'rejected' || $st === 'cancelled') : ?>
                                <form method="post" action="accounts.php" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="salon_id" value="<?php echo $id; ?>">
                                    <input type="hidden" name="action" value="restore">
                                    <button class="btn btn-sm btn-outline-success rounded-pill px-3" type="submit">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                                    </button>
                                </form>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-light rounded-pill px-3"
                               href="<?php echo e(super_admin_url('account-details.php?id=' . $id)); ?>">Manage</a>
                        </td>
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
                            <a class="page-link" href="accounts.php?<?php echo e(query_string(['page' => $pg['prevPage']])); ?>">Prev</a>
                        </li>
                        <li class="page-item <?php echo $pg['hasNext'] ? '' : 'disabled'; ?>">
                            <a class="page-link" href="accounts.php?<?php echo e(query_string(['page' => $pg['nextPage']])); ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/super-admin/footer.php'; ?>
