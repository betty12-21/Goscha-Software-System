<?php
/**
 * Beauty-php-ai — Super Admin dashboard.
 *
 * Platform-level overview: salon lifecycle counts, the review queue
 * (pending registrations) and the most recent control-panel activity.
 */

require_once __DIR__ . '/../includes/init.php';
require_super_admin();

$stats = super_admin_salon_stats();

$recentSalons = [];
try {
    $recentSalons = db()->query(
        'SELECT id, salon_name, email, phone, status, created_at
           FROM salons ORDER BY created_at DESC, id DESC LIMIT 6'
    )->fetchAll();
} catch (Throwable $e) {
    $recentSalons = [];
}

$pending = [];
try {
    $stmt = db()->query(
        'SELECT id, salon_name, email, created_at FROM salons
          WHERE status = "pending" ORDER BY created_at ASC LIMIT 5'
    );
    $pending = $stmt->fetchAll();
} catch (Throwable $e) {
    $pending = [];
}

$recentAudit = [];
try {
    $recentAudit = db()->query(
        'SELECT * FROM super_admin_audit_logs ORDER BY id DESC LIMIT 8'
    )->fetchAll();
} catch (Throwable $e) {
    $recentAudit = [];
}

$page_title = 'Dashboard';
$active     = 'dashboard';
include __DIR__ . '/../includes/super-admin/header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="sa-stat">
            <span class="sa-stat-icon"><i class="bi bi-shop"></i></span>
            <div>
                <div class="sa-stat-value"><?php echo (int)$stats['total']; ?></div>
                <div class="sa-stat-label">Total salons</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="sa-stat">
            <span class="sa-stat-icon" style="background:#fff3cd;color:#8a6100;"><i class="bi bi-hourglass-split"></i></span>
            <div>
                <div class="sa-stat-value"><?php echo (int)$stats['by_status']['pending']; ?></div>
                <div class="sa-stat-label">Pending</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="sa-stat">
            <span class="sa-stat-icon" style="background:#d8f3e3;color:#14663a;"><i class="bi bi-check-circle"></i></span>
            <div>
                <div class="sa-stat-value"><?php echo (int)$stats['active']; ?></div>
                <div class="sa-stat-label">Active</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="sa-stat">
            <span class="sa-stat-icon" style="background:#ffe1cc;color:#99500a;"><i class="bi bi-pause-circle"></i></span>
            <div>
                <div class="sa-stat-value"><?php echo (int)$stats['suspended']; ?></div>
                <div class="sa-stat-label">Suspended</div>
            </div>
        </div>
    </div>
</div>

<?php if ($pending): ?>
<div class="sa-card mb-4">
    <div class="sa-card-body">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-inbox me-2 text-warning"></i>Awaiting approval</h2>
        <div class="table-responsive">
            <table class="sa-table">
                <thead><tr><th>Salon</th><th>Owner email</th><th>Registered</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pending as $s): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo e($s['salon_name']); ?></td>
                        <td class="text-muted"><?php echo e($s['email']); ?></td>
                        <td class="text-muted small"><?php echo e(fmt_datetime($s['created_at'])); ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-rose rounded-pill px-3"
                               href="<?php echo e(super_admin_url('account-details.php?id=' . (int)$s['id'])); ?>">
                                Review
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="sa-card h-100">
            <div class="sa-card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h6 fw-bold mb-0"><i class="bi bi-clock-history me-2"></i>Recent registrations</h2>
                    <a href="<?php echo e(super_admin_url('accounts.php')); ?>" class="small text-decoration-none">View all</a>
                </div>
                <?php if (!$recentSalons): ?>
                    <div class="sa-empty"><i class="bi bi-shop"></i>No salons registered yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="sa-table">
                            <thead><tr><th>Salon</th><th>Status</th><th>Registered</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentSalons as $s): ?>
                                <tr>
                                    <td>
                                        <a class="fw-semibold text-decoration-none"
                                           href="<?php echo e(super_admin_url('account-details.php?id=' . (int)$s['id'])); ?>">
                                            <?php echo e($s['salon_name']); ?>
                                        </a>
                                        <div class="small text-muted"><?php echo e($s['email'] ?: '—'); ?></div>
                                    </td>
                                    <td><?php echo sa_status_badge((string)$s['status']); ?></td>
                                    <td class="small text-muted"><?php echo e(fmt_datetime($s['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="sa-card h-100">
            <div class="sa-card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h6 fw-bold mb-0"><i class="bi bi-journal-text me-2"></i>Recent activity</h2>
                    <a href="<?php echo e(super_admin_url('audit-logs.php')); ?>" class="small text-decoration-none">Full log</a>
                </div>
                <?php if (!$recentAudit): ?>
                    <div class="sa-empty"><i class="bi bi-journal"></i>No activity recorded yet.</div>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($recentAudit as $log): ?>
                            <li class="d-flex gap-2 py-2 border-bottom">
                                <i class="bi bi-dot text-muted"></i>
                                <div class="small">
                                    <div><?php echo e($log['description'] ?: $log['action']); ?></div>
                                    <div class="text-muted" style="font-size:.75rem;">
                                        <?php echo e($log['super_admin_name'] ?: 'System'); ?>
                                        · <?php echo e(fmt_datetime($log['created_at'])); ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/super-admin/footer.php'; ?>
