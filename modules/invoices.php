<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Invoices module (list + status management).
 */

require_permission('invoices');

$action = $_GET['action'] ?? 'list';

/* ============================================================
 * Mark invoice paid / void
 * ============================================================ */
if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'invoice_status') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token.');
        redirect('invoices.php');
    }
    $id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $status = $_POST['status'] ?? '';

    $stmt = db()->prepare('SELECT * FROM invoices WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $inv = $stmt->fetch();
    if (!$inv) {
        flash_set('danger', 'Invoice not found.');
        redirect('invoices.php');
    }

    if ($status === 'paid') {
        db()->prepare("UPDATE invoices SET status = 'paid' WHERE id = :id")->execute(['id' => $id]);

        if ($inv['appointment_id']) {
            db()->prepare("UPDATE appointments SET payment_status = 'paid' WHERE id = :id")->execute(['id' => $inv['appointment_id']]);

            /* Record an implicit payment for the outstanding balance */
            $stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE appointment_id = :id AND status = "completed"');
            $stmt->execute(['id' => $inv['appointment_id']]);
            $paid = (float)$stmt->fetch()['paid'];

            if ($paid < (float)$inv['total'] - 0.001) {
                $due = (float)$inv['total'] - $paid;
                db()->prepare(
                    'INSERT INTO payments (appointment_id, customer_id, amount, payment_method, transaction_reference, status)
                     VALUES (:appt, :cust, :amt, "cash", :ref, "completed")'
                )->execute([
                    'appt' => $inv['appointment_id'], 'cust' => $inv['customer_id'], 'amt' => $due,
                    'ref' => 'Marked paid via invoice #' . $inv['invoice_number'],
                ]);
                award_loyalty((int)$inv['customer_id'], $due);
            }
        }

        log_activity('update', 'invoices', "Marked invoice {$inv['invoice_number']} as paid");
        flash_set('success', 'Invoice marked as paid.');
    } elseif ($status === 'void') {
        db()->prepare("UPDATE invoices SET status = 'void' WHERE id = :id")->execute(['id' => $id]);
        log_activity('update', 'invoices', "Voided invoice {$inv['invoice_number']}");
        flash_set('success', 'Invoice voided.');
    }
    redirect('invoices.php');
}

/* ============================================================
 * LIST
 * ============================================================ */
$q     = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page  = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where  = [];
$params = [];
if ($q !== '') {
    $like = "%{$q}%";
    $where[] = '(c.first_name LIKE :q1 OR c.last_name LIKE :q2 OR i.invoice_number LIKE :q3)';
    $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
}
if (in_array($status, ['unpaid', 'partial', 'paid', 'void'], true)) {
    $where[] = 'i.status = :status';
    $params['status'] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare("SELECT COUNT(*) FROM invoices i JOIN customers c ON c.id = i.customer_id {$whereSql}");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pg    = paginate($total, $perPage, $page);

$stmt = db()->prepare(
    "SELECT i.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name
     FROM invoices i JOIN customers c ON c.id = i.customer_id
     {$whereSql}
     ORDER BY i.issued_at DESC
     LIMIT {$perPage} OFFSET {$pg['offset']}"
);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$page_title = 'Invoices';
$active     = 'invoices';
include __DIR__ . '/../includes/header.php';
?>

<div class="card table-card">
    <div class="card-body p-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <form method="get" action="invoices.php" class="d-flex flex-wrap gap-2">
            <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="Customer or invoice number" style="width:230px;">
            <select class="form-select" name="status" style="width:150px;">
                <option value="">All statuses</option>
                <?php foreach (['unpaid', 'partial', 'paid', 'void'] as $st): ?>
                    <option value="<?php echo $st; ?>" <?php echo $status === $st ? 'selected' : ''; ?>><?php echo e(ucfirst($st)); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-charcoal" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="invoices.php" class="btn btn-light">Reset</a>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Invoice #</th><th>Customer</th><th>Appointment</th><th>Issued</th><th>Total</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$invoices): ?>
                <tr><td colspan="7"><div class="empty-state">
                    <div class="icon"><i class="bi bi-receipt"></i></div>
                    <p class="text-muted mb-0">No invoices found.</p>
                </div></td></tr>
            <?php else: foreach ($invoices as $inv): ?>
                <tr>
                    <td class="fw-semibold"><?php echo e($inv['invoice_number']); ?></td>
                    <td><?php echo e($inv['customer_name']); ?></td>
                    <td class="small"><?php echo $inv['appointment_id'] ? '#<a class="text-decoration-none" href="appointments.php?action=view&id=' . $inv['appointment_id'] . '">' . $inv['appointment_id'] . '</a>' : '—'; ?></td>
                    <td class="small"><?php echo fmt_datetime($inv['issued_at']); ?></td>
                    <td class="fw-semibold"><?php echo money($inv['total']); ?></td>
                    <td><?php echo status_badge($inv['status']); ?></td>
                    <td class="text-end">
                        <a href="invoice-view.php?id=<?php echo $inv['id']; ?>" class="btn btn-sm btn-light rounded-pill" target="_blank" title="View / Print"><i class="bi bi-printer"></i></a>
                        <?php if ($inv['status'] === 'unpaid' || $inv['status'] === 'partial'): ?>
                            <form method="post" action="invoices.php" class="d-inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="form_action" value="invoice_status">
                                <input type="hidden" name="id" value="<?php echo $inv['id']; ?>">
                                <input type="hidden" name="status" value="paid">
                                <button class="btn btn-sm btn-success rounded-pill" type="submit"><i class="bi bi-check-lg"></i> Mark Paid</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($inv['status'] !== 'void'): ?>
                            <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                    data-confirm="Void this invoice?"
                                    data-confirm-action="invoices.php?form_action=invoice_status&id=<?php echo $inv['id']; ?>&status=void"
                                    data-confirm-label="Void"><i class="bi bi-x-lg"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pg['totalPages'] > 1): ?>
        <div class="card-body border-top d-flex justify-content-between align-items-center">
            <span class="small text-muted"><?php echo $pg['total']; ?> invoice(s)</span>
            <nav><ul class="pagination pagination-sm mb-0">
                <li class="page-item <?php echo $pg['hasPrev'] ? '' : 'disabled'; ?>"><a class="page-link" href="invoices.php?<?php echo e(query_string(['page' => $pg['prevPage']])); ?>">Prev</a></li>
                <li class="page-item disabled"><span class="page-link">Page <?php echo $pg['page']; ?> of <?php echo $pg['totalPages']; ?></span></li>
                <li class="page-item <?php echo $pg['hasNext'] ? '' : 'disabled'; ?>"><a class="page-link" href="invoices.php?<?php echo e(query_string(['page' => $pg['nextPage']])); ?>">Next</a></li>
            </ul></nav>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
