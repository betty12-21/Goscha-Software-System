<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Payments module.
 * Administrator and Receptionist can process appointment payments.
 */

require_permission('payments');

$action = $_GET['action'] ?? 'list';

/* ============================================================
 * Save payment
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_payment') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token.');
        redirect('payments.php');
    }

    $appointmentId = (int)($_POST['appointment_id'] ?? 0);
    $amount        = max(0, (float)($_POST['amount'] ?? 0));
    $method        = in_array($_POST['payment_method'] ?? '', ['cash', 'bank_transfer'], true) ? $_POST['payment_method'] : 'cash';
    $reference     = trim($_POST['transaction_reference'] ?? '');

    if ($appointmentId <= 0 || $amount <= 0) {
        flash_set('danger', 'Please select an appointment and enter a valid amount.');
        redirect('payments.php?action=add');
    }

    $stmt = db()->prepare(
        'SELECT a.*, CONCAT(c.first_name, " ", c.last_name) AS customer_name FROM appointments a
         JOIN customers c ON c.id = a.customer_id WHERE a.id = :id'
    );
    $stmt->execute(['id' => $appointmentId]);
    $appt = $stmt->fetch();
    if (!$appt) {
        flash_set('danger', 'Appointment not found.');
        redirect('payments.php?action=add');
    }

    try {
        db()->beginTransaction();

        $stmt = db()->prepare(
            'INSERT INTO payments (appointment_id, customer_id, amount, payment_method, transaction_reference, status)
             VALUES (:appt, :cust, :amount, :method, :ref, "completed")'
        );
        $stmt->execute([
            'appt' => $appointmentId, 'cust' => $appt['customer_id'], 'amount' => $amount,
            'method' => $method, 'ref' => $reference !== '' ? $reference : null,
        ]);

        /* Recalculate payment status from total collected */
        $stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE appointment_id = :id AND status = "completed"');
        $stmt->execute(['id' => $appointmentId]);
        $paid = (float)$stmt->fetch()['paid'];

        if ($paid >= (float)$appt['total_amount'] - 0.001) {
            $payStatus = 'paid';
            $invStatus = 'paid';
            award_loyalty((int)$appt['customer_id'], $amount);
        } elseif ($paid > 0) {
            $payStatus = 'partial';
            $invStatus = 'partial';
        } else {
            $payStatus = 'pending';
            $invStatus = 'unpaid';
        }

        db()->prepare('UPDATE appointments SET payment_status = :s WHERE id = :id')->execute(['s' => $payStatus, 'id' => $appointmentId]);
        db()->prepare('UPDATE invoices SET status = :s WHERE appointment_id = :id')->execute(['s' => $invStatus, 'id' => $appointmentId]);

        db()->commit();

        log_activity('payment', 'payments', "Payment of " . money($amount) . " recorded for appointment #{$appointmentId}");
        notify('Payment received', "Payment of " . money($amount) . ' ' . currency() . " received for appointment #{$appointmentId}.", 'success');

        flash_set('success', 'Payment completed successfully.');
        redirect('invoice-view.php?appointment_id=' . $appointmentId);
    } catch (Throwable $e) {
        db()->rollBack();
        flash_set('danger', 'Something went wrong. Please try again.');
        redirect('payments.php?action=add');
    }
}

/* ============================================================
 * Refund payment
 * ============================================================ */
if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'refund_payment') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token.');
        redirect('payments.php');
    }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

    $stmt = db()->prepare('SELECT appointment_id, amount FROM payments WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $pay = $stmt->fetch();
    if ($pay) {
        db()->prepare("UPDATE payments SET status = 'refunded' WHERE id = :id")->execute(['id' => $id]);

        $stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE appointment_id = :id AND status = "completed"');
        $stmt->execute(['id' => $pay['appointment_id']]);
        $paid = (float)$stmt->fetch()['paid'];

        $stmt = db()->prepare('SELECT total_amount FROM appointments WHERE id = :id');
        $stmt->execute(['id' => $pay['appointment_id']]);
        $total = (float)$stmt->fetch()['total_amount'];

        $payStatus = $paid >= $total - 0.001 ? 'paid' : ($paid > 0 ? 'partial' : 'pending');
        $invStatus = $paid >= $total - 0.001 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');

        db()->prepare('UPDATE appointments SET payment_status = :s WHERE id = :id')->execute(['s' => $payStatus, 'id' => $pay['appointment_id']]);
        db()->prepare('UPDATE invoices SET status = :s WHERE appointment_id = :id')->execute(['s' => $invStatus, 'id' => $pay['appointment_id']]);

        log_activity('payment', 'payments', "Refunded payment #{$id}");
        flash_set('success', 'Payment marked as refunded.');
    }
    redirect('payments.php');
}

/* ============================================================
 * ADD payment form
 * ============================================================ */
if ($action === 'add') {
    $appointmentId = (int)($_GET['appointment_id'] ?? 0);

    $openAppointments = [];
    $stmt = db()->query(
        'SELECT a.id, a.appointment_date, a.start_time, a.total_amount, a.payment_status,
                CONCAT(c.first_name, " ", c.last_name) AS customer_name
         FROM appointments a JOIN customers c ON c.id = a.customer_id
         WHERE a.status NOT IN ("cancelled", "no_show") AND a.payment_status IN ("pending", "partial")
         ORDER BY a.appointment_date DESC, a.start_time DESC'
    );
    $openAppointments = $stmt->fetchAll();

    $selected = null;
    if ($appointmentId) {
        foreach ($openAppointments as $a) {
            if ((int)$a['id'] === $appointmentId) {
                $selected = $a;
                break;
            }
        }
    }

    $page_title = 'Record Payment';
    $active     = 'payments';
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="card" style="max-width:640px;">
        <div class="card-header">Record a Payment</div>
        <div class="card-body">
            <form method="post" action="payments.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="save_payment">

                <div class="mb-3">
                    <label class="form-label" for="appointment_id">Appointment <span class="required">*</span></label>
                    <select class="form-select" id="appointment_id" name="appointment_id" required>
                        <option value="">— Select appointment —</option>
                        <?php foreach ($openAppointments as $a): ?>
                            <option value="<?php echo $a['id']; ?>" <?php echo $selected && (int)$selected['id'] === (int)$a['id'] ? 'selected' : ''; ?>>
                                #<?php echo $a['id']; ?> · <?php echo e($a['customer_name']); ?> · <?php echo fmt_date($a['appointment_date']); ?> ·
                                Due <?php echo money($a['total_amount']); ?> (<?php echo e(ucfirst($a['payment_status'])); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$openAppointments): ?>
                        <div class="form-text">No appointments with pending payments.</div>
                    <?php endif; ?>
                </div>

                <?php if ($selected): ?>
                    <div class="alert alert-info rounded-3 py-2">
                        <strong><?php echo e($selected['customer_name']); ?></strong> — total due
                        <strong><?php echo money($selected['total_amount']); ?> <?php echo e(currency()); ?></strong>
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="amount">Amount <span class="required">*</span></label>
                        <input type="number" class="form-control" id="amount" name="amount" min="0.01" step="0.01" required
                               value="<?php echo $selected ? money($selected['total_amount']) : ''; ?>" placeholder="0.00">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="payment_method">Payment Method</label>
                        <select class="form-select" id="payment_method" name="payment_method">
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="transaction_reference">Transaction Reference</label>
                        <input type="text" class="form-control" id="transaction_reference" name="transaction_reference" placeholder="e.g. TRF-1234 / receipt #">
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-credit-card me-1"></i>Complete Payment</button>
                    <a href="payments.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* ============================================================
 * LIST
 * ============================================================ */
$q     = trim($_GET['q'] ?? '');
$date  = $_GET['date'] ?? '';
$method = $_GET['method'] ?? '';
$page  = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where  = [];
$params = [];
if ($q !== '') {
    $like = "%{$q}%";
    $where[] = '(c.first_name LIKE :q1 OR c.last_name LIKE :q2 OR c.phone LIKE :q3 OR p.transaction_reference LIKE :q4)';
    $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
}
if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where[] = 'DATE(p.payment_date) = :date';
    $params['date'] = $date;
}
if (in_array($method, ['cash', 'bank_transfer'], true)) {
    $where[] = 'p.payment_method = :method';
    $params['method'] = $method;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare("SELECT COUNT(*) FROM payments p JOIN customers c ON c.id = p.customer_id {$whereSql}");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pg    = paginate($total, $perPage, $page);

$stmt = db()->prepare(
    "SELECT p.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name
     FROM payments p JOIN customers c ON c.id = p.customer_id
     {$whereSql}
     ORDER BY p.payment_date DESC
     LIMIT {$perPage} OFFSET {$pg['offset']}"
);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$page_title = 'Payments';
$active     = 'payments';
include __DIR__ . '/../includes/header.php';
?>

<div class="card table-card">
    <div class="card-body p-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <form method="get" action="payments.php" class="d-flex flex-wrap gap-2">
            <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="Customer or reference" style="width:210px;">
            <input type="date" class="form-control" name="date" value="<?php echo e($date); ?>">
            <select class="form-select" name="method" style="width:170px;">
                <option value="">All methods</option>
                <option value="cash" <?php echo $method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                <option value="bank_transfer" <?php echo $method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
            </select>
            <button class="btn btn-charcoal" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
            <a href="payments.php" class="btn btn-light">Reset</a>
        </form>
        <a href="payments.php?action=add" class="btn btn-rose rounded-pill"><i class="bi bi-credit-card me-1"></i>Record Payment</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>#</th><th>Customer</th><th>Appointment</th><th>Amount</th><th>Method</th><th>Reference</th><th>Date</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$payments): ?>
                <tr><td colspan="9"><div class="empty-state">
                    <div class="icon"><i class="bi bi-credit-card"></i></div>
                    <p class="text-muted mb-2">No payments found.</p>
                    <a href="payments.php?action=add" class="btn btn-sm btn-rose rounded-pill">Record a payment</a>
                </div></td></tr>
            <?php else: foreach ($payments as $p): ?>
                <tr>
                    <td class="text-muted">#<?php echo $p['id']; ?></td>
                    <td class="fw-semibold"><?php echo e($p['customer_name']); ?></td>
                    <td class="small"><a href="appointments.php?action=view&id=<?php echo $p['appointment_id']; ?>" class="text-decoration-none">#<?php echo $p['appointment_id']; ?></a></td>
                    <td class="fw-semibold"><?php echo money($p['amount']); ?></td>
                    <td><?php echo e(ucwords(str_replace('_', ' ', $p['payment_method']))); ?></td>
                    <td class="small text-muted"><?php echo $p['transaction_reference'] ? e($p['transaction_reference']) : '—'; ?></td>
                    <td class="small"><?php echo fmt_datetime($p['payment_date']); ?></td>
                    <td><?php echo status_badge($p['status']); ?></td>
                    <td class="text-end">
                        <?php if ($p['status'] === 'completed'): ?>
                            <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                    data-confirm="Mark this payment as refunded?"
                                    data-confirm-action="payments.php?form_action=refund_payment&id=<?php echo $p['id']; ?>"
                                    data-confirm-label="Refund"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pg['totalPages'] > 1): ?>
        <div class="card-body border-top d-flex justify-content-between align-items-center">
            <span class="small text-muted"><?php echo $pg['total']; ?> payment(s)</span>
            <nav><ul class="pagination pagination-sm mb-0">
                <li class="page-item <?php echo $pg['hasPrev'] ? '' : 'disabled'; ?>"><a class="page-link" href="payments.php?<?php echo e(query_string(['page' => $pg['prevPage']])); ?>">Prev</a></li>
                <li class="page-item disabled"><span class="page-link">Page <?php echo $pg['page']; ?> of <?php echo $pg['totalPages']; ?></span></li>
                <li class="page-item <?php echo $pg['hasNext'] ? '' : 'disabled'; ?>"><a class="page-link" href="payments.php?<?php echo e(query_string(['page' => $pg['nextPage']])); ?>">Next</a></li>
            </ul></nav>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
