<?php
/**
 * Beauty-php-ai — Super Admin: salon account details.
 *
 * One salon's full record plus the full lifecycle control panel. Every
 * status change is validated against the transition table in
 * includes/super_admin.php and carries an optional reason that the salon
 * sees in its own notifications.
 */

require_once __DIR__ . '/../includes/init.php';
require_super_admin();

$salonId = (int)($_GET['id'] ?? $_POST['salon_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token. Please try again.');
        redirect(super_admin_url('account-details.php?id=' . $salonId));
    }

    $action = (string)($_POST['action'] ?? '');
    $notes  = trim((string)($_POST['notes'] ?? ''));

    if ($action === 'save_notes') {
        try {
            db()->prepare('UPDATE salons SET admin_notes = :n, updated_at = NOW() WHERE id = :id')
               ->execute(['n' => $notes !== '' ? mb_substr($notes, 0, 5000) : null, 'id' => $salonId]);

            $nameStmt = db()->prepare('SELECT salon_name FROM salons WHERE id = :id');
            $nameStmt->execute(['id' => $salonId]);
            super_admin_audit('salon_notes', 'Updated internal notes.', $salonId, (string)$nameStmt->fetchColumn());
        } catch (Throwable $e) {
            flash_set('danger', 'The notes could not be saved.');
            redirect(super_admin_url('account-details.php?id=' . $salonId));
        }

        flash_set('success', 'Notes saved.');
        redirect(super_admin_url('account-details.php?id=' . $salonId));
    }

    $targetStatus = [
        'approve'    => 'active',
        'restore'    => 'active',
        'reject'     => 'rejected',
        'suspend'    => 'suspended',
        'cancel'     => 'cancelled',
        'deactivate' => 'inactive',
    ][$action] ?? null;

    if ($targetStatus === null) {
        flash_set('danger', 'Unsupported action.');
        redirect(super_admin_url('account-details.php?id=' . $salonId));
    }

    $result = super_admin_set_status($salonId, $targetStatus, [
        'reason' => (string)($_POST['reason'] ?? ''),
        'notes'  => (string)($_POST['notes'] ?? ''),
    ]);

    flash_set($result['success'] ? 'success' : 'danger', $result['success'] ? 'Salon status updated.' : $result['error']);
    redirect(super_admin_url('account-details.php?id=' . $salonId));
}

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM salons WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$salon = $stmt->fetch();

if (!$salon) {
    flash_set('danger', 'Salon not found.');
    redirect(super_admin_url('accounts.php'));
}

$salonId = (int)$salon['id'];
$status  = (string)$salon['status'];

$counts = ['users' => 0, 'customers' => 0, 'appointments' => 0, 'invoices' => 0, 'staff' => 0];
try {
    $counts['users'] = (int)db()->query('SELECT COUNT(*) FROM users WHERE salon_id = ' . $salonId)->fetchColumn();
    $counts['customers'] = (int)db()->query('SELECT COUNT(*) FROM customers WHERE salon_id = ' . $salonId)->fetchColumn();
    $counts['appointments'] = (int)db()->query('SELECT COUNT(*) FROM appointments WHERE salon_id = ' . $salonId)->fetchColumn();
    $counts['invoices'] = (int)db()->query('SELECT COUNT(*) FROM invoices WHERE salon_id = ' . $salonId)->fetchColumn();
    $counts['staff'] = (int)db()->query('SELECT COUNT(*) FROM staff WHERE salon_id = ' . $salonId)->fetchColumn();
} catch (Throwable $e) {
    /* Leave zeros. */
}

$enabledModules = enabled_modules($salonId);

$usersStmt = db()->prepare(
    'SELECT id, name, email, role, status
       FROM users WHERE salon_id = :sid ORDER BY (role = "admin") DESC, id ASC LIMIT 25'
);
$usersStmt->execute(['sid' => $salonId]);
$users = $usersStmt->fetchAll();

$auditStmt = db()->prepare(
    'SELECT * FROM super_admin_audit_logs WHERE target_salon_id = :sid ORDER BY id DESC LIMIT 12'
);
$auditStmt->execute(['sid' => $salonId]);
$audit = $auditStmt->fetchAll();

$transitions   = salon_status_transitions()[$status] ?? [];
$profileLabels = [];
foreach (access_profiles() as $key => $p) {
    $profileLabels[$key] = $p['label'];
}

$actionMeta = [
    'active'    => [$status === 'pending' ? 'approve' : 'restore', $status === 'pending' ? 'Approve' : 'Restore access', 'btn-rose'],
    'rejected'  => ['reject', 'Reject', 'btn-outline-danger'],
    'suspended' => ['suspend', 'Suspend', 'btn-outline-warning'],
    'cancelled' => ['cancel', 'Cancel salon', 'btn-outline-danger'],
    'inactive'  => ['deactivate', 'Set inactive', 'btn-outline-secondary'],
    'pending'   => ['pending', 'Move to pending', 'btn-outline-secondary'],
];

$page_title = 'Account · ' . $salon['salon_name'];
$active     = 'accounts';
include __DIR__ . '/../includes/super-admin/header.php';
?>

<a href="<?php echo e(super_admin_url('accounts.php')); ?>" class="small text-decoration-none d-inline-block mb-3">
    <i class="bi bi-arrow-left me-1"></i>All accounts
</a>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="sa-card h-100">
            <div class="sa-card-body">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                    <div>
                        <h2 class="h4 fw-bold mb-1"><?php echo e($salon['salon_name']); ?></h2>
                        <div class="small text-muted">Salon #<?php echo $salonId; ?> · joined <?php echo e(fmt_datetime($salon['created_at'])); ?></div>
                    </div>
                    <?php echo sa_status_badge($status); ?>
                </div>

                <div class="row g-3 small">
                    <div class="col-sm-6">
                        <div class="text-muted">Owner</div>
                        <div class="fw-semibold"><?php echo e(trim(($salon['owner_first_name'] ?? '') . ' ' . ($salon['owner_last_name'] ?? '')) ?: '—'); ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted">Email</div>
                        <div class="fw-semibold"><?php echo e($salon['email'] ?: '—'); ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted">Phone</div>
                        <div class="fw-semibold"><?php echo e($salon['phone'] ?: '—'); ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted">Plan</div>
                        <div class="fw-semibold"><?php echo e($profileLabels[$salon['access_profile']] ?? ucfirst((string)$salon['access_profile'])); ?>
                            <span class="text-muted">(<?php echo count($enabledModules); ?>/<?php echo count(module_keys()); ?> modules)</span>
                        </div>
                    </div>
                </div>

                <?php if ($status !== 'active' && !empty($salon['status_reason'])): ?>
                    <div class="alert alert-warning rounded-3 mt-3 mb-0 py-2 small">
                        <strong>Reason on record:</strong> <?php echo e($salon['status_reason']); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($salon['admin_notes'])): ?>
                    <div class="mt-3">
                        <div class="text-muted small">Admin notes</div>
                        <div class="small"><?php echo nl2br(e($salon['admin_notes'])); ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="sa-card h-100">
            <div class="sa-card-body">
                <h3 class="h6 fw-bold mb-3">Activity</h3>
                <div class="row g-2 text-center">
                    <div class="col-4"><div class="sa-stat-value"><?php echo (int)$counts['users']; ?></div><div class="sa-stat-label">Users</div></div>
                    <div class="col-4"><div class="sa-stat-value"><?php echo (int)$counts['staff']; ?></div><div class="sa-stat-label">Staff</div></div>
                    <div class="col-4"><div class="sa-stat-value"><?php echo (int)$counts['customers']; ?></div><div class="sa-stat-label">Clients</div></div>
                    <div class="col-6 mt-2"><div class="sa-stat-value" style="font-size:1.2rem;"><?php echo (int)$counts['appointments']; ?></div><div class="sa-stat-label">Appointments</div></div>
                    <div class="col-6 mt-2"><div class="sa-stat-value" style="font-size:1.2rem;"><?php echo (int)$counts['invoices']; ?></div><div class="sa-stat-label">Invoices</div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="sa-card mb-3">
    <div class="sa-card-body">
        <h3 class="h6 fw-bold mb-3"><i class="bi bi-sliders me-2"></i>Module access</h3>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach (module_keys() as $key): ?>
                <?php $on = in_array($key, $enabledModules, true); ?>
                <span class="sa-module" style="cursor:default;">
                    <i class="bi <?php echo e(module_icon($key)); ?>" style="color:<?php echo $on ? 'var(--bsai-rose)' : '#bbb'; ?>;"></i>
                    <span class="sa-module-label" style="color:<?php echo $on ? '' : '#aaa'; ?>;"><?php echo e(module_label($key)); ?></span>
                    <i class="bi <?php echo $on ? 'bi-check-circle-fill text-success' : 'bi-dash-circle' ; ?> sa-module-tick"></i>
                </span>
            <?php endforeach; ?>
        </div>
        <a class="btn btn-sm btn-charcoal rounded-pill px-3"
           href="<?php echo e(super_admin_url('access-control.php?salon_id=' . $salonId)); ?>">
            <i class="bi bi-pencil-square me-1"></i>Edit module permissions
        </a>
    </div>
</div>

<div class="sa-card mb-3">
    <div class="sa-card-body">
        <h3 class="h6 fw-bold mb-3"><i class="bi bi-toggle-on me-2"></i>Lifecycle actions</h3>
        <form method="post" action="account-details.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="salon_id" value="<?php echo $salonId; ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small" for="reason">Reason (shown to the salon)</label>
                    <input type="text" class="form-control" id="reason" name="reason" maxlength="500"
                           placeholder="Optional for approval; strongly recommended for suspension">
                </div>
                <div class="col-md-6">
                    <label class="form-label small" for="notes">Internal admin notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2"
                              placeholder="Private notes (not shown to the salon)"><?php echo e((string)($salon['admin_notes'] ?? '')); ?></textarea>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3 align-items-center">
                <?php foreach ($transitions as $target): ?>
                    <?php [$act, $label, $cls] = $actionMeta[$target] ?? [$target, ucfirst($target), 'btn-outline-secondary']; ?>
                    <button type="submit" name="action" value="<?php echo e($act); ?>" class="btn btn-sm rounded-pill px-3 <?php echo e($cls); ?>">
                        <?php echo e($label); ?>
                    </button>
                <?php endforeach; ?>
                <button type="submit" name="action" value="save_notes" class="btn btn-sm btn-light rounded-pill px-3 ms-auto">
                    <i class="bi bi-save me-1"></i>Save notes only
                </button>
            </div>
            <?php if (!$transitions): ?>
                <div class="text-muted small mt-2">No further transitions are available from "<?php echo e($status); ?>".</div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="sa-card h-100">
            <div class="sa-card-body">
                <h3 class="h6 fw-bold mb-3"><i class="bi bi-people me-2"></i>Accounts (<?php echo (int)$counts['users']; ?>)</h3>
                <?php if (!$users): ?>
                    <div class="sa-empty"><i class="bi bi-person"></i>No user accounts.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="sa-table">
                            <thead><tr><th>Name</th><th>Role</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo e($u['name']); ?></div>
                                        <div class="small text-muted"><?php echo e($u['email']); ?></div>
                                    </td>
                                    <td class="small"><?php echo e(ucfirst($u['role'])); ?></td>
                                    <td><?php echo status_badge((string)$u['status']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="sa-card h-100">
            <div class="sa-card-body">
                <h3 class="h6 fw-bold mb-3"><i class="bi bi-journal-text me-2"></i>Platform history</h3>
                <?php if (!$audit): ?>
                    <div class="sa-empty"><i class="bi bi-journal"></i>No platform actions for this salon yet.</div>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($audit as $log): ?>
                            <li class="py-2 border-bottom small">
                                <div><?php echo e($log['description'] ?: $log['action']); ?></div>
                                <div class="text-muted" style="font-size:.75rem;">
                                    <?php echo e($log['super_admin_name'] ?: 'System'); ?> · <?php echo e(fmt_datetime($log['created_at'])); ?>
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
