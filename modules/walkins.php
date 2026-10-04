<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Walk-in management module.
 * Flow: find or create customer → book services → process payment → receipt.
 */

require_permission('walkins');

$action = $_GET['action'] ?? 'list';

/* ============================================================
 * SAVE walk-in booking
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_walkin') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token. Please try again.');
        redirect('walkins.php');
    }

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $staffId    = (int)($_POST['staff_id'] ?? 0);
    $serviceIds = array_values(array_filter(array_map('intval', $_POST['service_ids'] ?? [])));
    $date       = $_POST['appointment_date'] ?? '';
    $start      = $_POST['start_time'] ?? '';
    $method     = in_array($_POST['payment_method'] ?? '', ['cash', 'bank_transfer'], true) ? $_POST['payment_method'] : 'cash';
    $reference  = trim($_POST['transaction_reference'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');
    $discount   = max(0, (float)($_POST['discount'] ?? 0));

    $errors = [];

    if (!$serviceIds) { $errors[] = 'Please select at least one service.'; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $errors[] = 'Please select a valid date.'; }
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start)) { $errors[] = 'Please select a valid time.'; }

    $startTime = date('H:i:s', strtotime($start));

    $services = [];
    $minutes  = 0;
    if ($serviceIds && !$errors) {
        $in = implode(',', array_fill(0, count($serviceIds), '?'));
        $stmt = db()->prepare("SELECT * FROM services WHERE id IN ($in) AND status = 'active'");
        $stmt->execute($serviceIds);
        $services = $stmt->fetchAll();
        if (count($services) !== count($serviceIds)) {
            $errors[] = 'One or more selected services are not available.';
        }
        $minutes = array_sum(array_column($services, 'duration_minutes'));
    }

    /* A walk-in is always served by somebody, and §11 gives a walk-in no
       exemption: the same salon hours, staff shift, duration, break and
       conflict rules apply as to a booked appointment. */
    $assignedStaff = null;
    if ($staffId <= 0) {
        $errors[] = 'Please choose the staff member serving this walk-in.';
    } else {
        $stmt = db()->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM staff WHERE id = :id AND status = 'active'");
        $stmt->execute(['id' => $staffId]);
        $assignedStaff = $stmt->fetch();
        if (!$assignedStaff) {
            $errors[] = 'The selected staff member is not available.';
        }
    }

    /* The customer row is created only once the booking itself is legal, so
       a rejected time never leaves a stray customer behind. */
    $newCustomer = null;
    if ($customerId <= 0) {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');

        if ($firstName === '' || $lastName === '' || $phone === '') {
            $errors[] = 'Please select an existing customer or fill in the new customer name and phone.';
        } else {
            $newCustomer = ['fn' => $firstName, 'ln' => $lastName, 'ph' => $phone, 'em' => $email !== '' ? $email : null];
        }
    } else {
        $stmt = db()->prepare('SELECT id FROM customers WHERE id = :id');
        $stmt->execute(['id' => $customerId]);
        if (!$stmt->fetch()) {
            $errors[] = 'That customer no longer exists. Please pick the customer again.';
            $customerId = 0;
        }
    }

    if ($errors) {
        foreach ($errors as $err) {
            flash_set('danger', $err);
        }
        redirect('walkins.php' . ($customerId > 0 ? '?customer_id=' . $customerId : ''));
    }

    $problem = scheduling_validate([
        'date'             => $date,
        'start'            => $startTime,
        'duration_minutes' => $minutes,
        'staff_id'         => $staffId,
        'customer_id'      => $customerId,
        'service_ids'      => $serviceIds,
    ]);

    if ($problem !== null) {
        flash_set('danger', $problem);
        redirect('walkins.php' . ($customerId > 0 ? '?customer_id=' . $customerId : ''));
    }

    if ($newCustomer !== null) {
        $stmt = db()->prepare(
            'INSERT INTO customers (first_name, last_name, phone, email) VALUES (:fn, :ln, :ph, :em)'
        );
        $stmt->execute($newCustomer);
        $customerId = (int)db()->lastInsertId();
    }

    $subtotal = array_sum(array_column($services, 'price'));
    $endTime  = date('H:i:s', time_seconds($startTime) + $minutes * 60);
    $taxRate  = (float)get_setting('tax_rate', 15);
    $tax      = ($subtotal - $discount) * ($taxRate / 100);
    $total    = $subtotal - $discount + $tax;

    try {
        db()->beginTransaction();

        $stmt = db()->prepare(
            'INSERT INTO appointments (customer_id, staff_id, appointment_date, start_time, end_time, status, notes, subtotal, discount, tax, total_amount, payment_status, created_by)
             VALUES (:customer_id, :staff_id, :date, :start, :end, "confirmed", :notes, :subtotal, :discount, :tax, :total, "paid", :created_by)'
        );
        $stmt->execute([
            'customer_id' => $customerId, 'staff_id' => $staffId, 'date' => $date, 'start' => $startTime, 'end' => $endTime,
            'notes' => $notes, 'subtotal' => $subtotal, 'discount' => $discount,
            'tax' => $tax, 'total' => $total, 'created_by' => current_user()['id'],
        ]);
        $apptId = (int)db()->lastInsertId();

        $stmt = db()->prepare(
            'INSERT INTO appointment_services (appointment_id, service_id, price, duration_minutes) VALUES (:appt, :svc, :price, :dur)'
        );
        foreach ($services as $svc) {
            $stmt->execute(['appt' => $apptId, 'svc' => $svc['id'], 'price' => $svc['price'], 'dur' => $svc['duration_minutes']]);
        }

        /* Invoice */
        $invNum = next_invoice_number();
        $stmt = db()->prepare(
            'INSERT INTO invoices (invoice_number, appointment_id, customer_id, subtotal, discount, tax, total, status)
             VALUES (:num, :appt, :cust, :sub, :disc, :tax, :total, "paid")'
        );
        $stmt->execute([
            'num' => $invNum, 'appt' => $apptId, 'cust' => $customerId,
            'sub' => $subtotal, 'disc' => $discount, 'tax' => $tax, 'total' => $total,
        ]);
        $invId = (int)db()->lastInsertId();

        /* Payment */
        $stmt = db()->prepare(
            'INSERT INTO payments (appointment_id, customer_id, amount, payment_method, transaction_reference, status)
             VALUES (:appt, :cust, :amount, :method, :ref, "completed")'
        );
        $stmt->execute([
            'appt' => $apptId, 'cust' => $customerId, 'amount' => $total,
            'method' => $method, 'ref' => $reference !== '' ? $reference : null,
        ]);

        award_loyalty($customerId, $total);

        db()->commit();

        log_activity('create', 'walkins', "Walk-in appointment #{$apptId} booked and paid ({$invNum})");
        notify('Walk-in completed', "Walk-in appointment #{$apptId} booked and payment of " . money($total) . ' ' . currency() . ' received.', 'success');

        flash_set('success', 'Walk-in booked and payment completed successfully.');
        redirect('invoice-view.php?id=' . $invId);
    } catch (Throwable $e) {
        db()->rollBack();
        flash_set('danger', 'Something went wrong. Please try again.');
        redirect('walkins.php');
    }
}

/* ============================================================
 * LIST (recent walk-in appointments)
 * ============================================================ */
$page_title = 'Walk-ins';
$active     = 'walkins';

$customerId = (int)($_GET['customer_id'] ?? 0);
$preselect  = null;
if ($customerId > 0) {
    $stmt = db()->prepare('SELECT * FROM customers WHERE id = :id');
    $stmt->execute(['id' => $customerId]);
    $preselect = $stmt->fetch();
}

$customers  = db()->query('SELECT id, first_name, last_name, phone FROM customers ORDER BY first_name LIMIT 500')->fetchAll();
$serviceTree = service_picker_tree(true);

$staffList = [];
try {
    $staffList = db()->query("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM staff WHERE status = 'active' ORDER BY first_name, last_name")->fetchAll();
} catch (Throwable $e) {
    $staffList = [];
}


$recent = [];
try {
    $stmt = db()->query(
        'SELECT a.*, CONCAT(c.first_name, " ", c.last_name) AS customer_name, c.phone AS customer_phone
         FROM appointments a JOIN customers c ON c.id = a.customer_id
         ORDER BY a.created_at DESC LIMIT 10'
    );
    $recent = $stmt->fetchAll();
} catch (Throwable $e) {
}

include __DIR__ . '/../includes/header.php';
?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">New Walk-In</div>
            <div class="card-body">
                <!-- Step 1: customer -->
                <h6 class="fw-bold text-rose mb-2"><span class="badge bg-rose me-1">1</span>Customer</h6>

                <?php if ($preselect): ?>
                    <div class="d-flex align-items-center justify-content-between bg-blush rounded-4 p-3 mb-4">
                        <div class="d-flex align-items-center gap-3">
                            <span class="avatar"><?php echo e(initials($preselect['first_name'] . ' ' . $preselect['last_name'])); ?></span>
                            <div>
                                <div class="fw-bold"><?php echo e($preselect['first_name'] . ' ' . $preselect['last_name']); ?></div>
                                <div class="small text-muted"><?php echo e($preselect['phone']); ?> · <?php echo (int)$preselect['loyalty_points']; ?> pts</div>
                            </div>
                        </div>
                        <a href="walkins.php" class="btn btn-sm btn-light rounded-pill"><i class="bi bi-x-lg"></i> Change</a>
                    </div>
                <?php else: ?>
                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <input type="text" class="form-control" id="customerSearch" placeholder="Search existing customers…">
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-light w-100 rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#newCustomerBox">
                                <i class="bi bi-person-plus me-1"></i>New Customer
                            </button>
                        </div>
                    </div>

                    <div class="list-group mb-3" id="customerList" style="max-height:220px;overflow-y:auto;">
                        <?php foreach ($customers as $c): ?>
                            <a href="walkins.php?customer_id=<?php echo $c['id']; ?>" class="list-group-item list-group-item-action customer-item d-flex justify-content-between align-items-center"
                               data-search="<?php echo e(strtolower($c['first_name'] . ' ' . $c['last_name'] . ' ' . $c['phone'])); ?>">
                                <div>
                                    <div class="fw-semibold"><?php echo e($c['first_name'] . ' ' . $c['last_name']); ?></div>
                                    <div class="small text-muted"><?php echo e($c['phone']); ?></div>
                                </div>
                                <i class="bi bi-arrow-right-circle text-rose"></i>
                            </a>
                        <?php endforeach; ?>
                        <div class="list-group-item text-center text-muted" id="customerNoResults" style="display:none;">No matching customers.</div>
                    </div>

                    <div class="collapse mb-4" id="newCustomerBox">
                        <div class="bg-blush rounded-4 p-3">
                            <h6 class="fw-bold mb-3">New Customer</h6>
                            <div class="row g-2">
                                <div class="col-md-6"><input type="text" class="form-control" id="newFirst" placeholder="First name *"></div>
                                <div class="col-md-6"><input type="text" class="form-control" id="newLast" placeholder="Last name *"></div>
                                <div class="col-md-6"><input type="tel" class="form-control" id="newPhone" placeholder="Phone *"></div>
                                <div class="col-md-6"><input type="email" class="form-control" id="newEmail" placeholder="Email (optional)"></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <hr>

                <!-- Step 2: booking -->
                <h6 class="fw-bold text-rose mb-2"><span class="badge bg-rose me-1">2</span>Services &amp; Time</h6>

                <form method="post" action="walkins.php"
                      data-booking-cascade
                      data-availability-url="<?php echo base_url('availability.php'); ?>"
                      data-allow-unassigned="false">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_walkin">
                    <input type="hidden" name="customer_id" id="customerIdInput" value="<?php echo $customerId; ?>">
                    <input type="hidden" name="first_name" id="walkinFirst">
                    <input type="hidden" name="last_name" id="walkinLast">
                    <input type="hidden" name="phone" id="walkinPhone">
                    <input type="hidden" name="email" id="walkinEmail">

                    <div class="d-flex gap-2 align-items-start mb-3">
                        <div class="flex-grow-1">
                            <?php
                            render_service_picker([
                                'id'           => 'serviceSelect',
                                'tree'         => $serviceTree,
                                'placeholder'  => '— Choose a service —',
                                'showPrice'    => true,
                                'showDuration' => true,
                            ]);
                            ?>
                        </div>
                        <button type="button" class="btn btn-soft rounded-pill px-4 mt-0" id="addServiceBtn"><i class="bi bi-plus-lg"></i></button>
                    </div>

                    <div id="serviceRows" class="mb-3"></div>
                    <template id="serviceRowTemplate"></template>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label" for="appointment_date">Date <span class="required">*</span></label>
                            <input type="date" class="form-control" id="appointment_date" name="appointment_date"
                                   min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="staff_id">Served By <span class="required">*</span></label>
                            <?php if ($staffList): ?>
                                <select class="form-select" id="staff_id" name="staff_id" required>
                                    <?php foreach ($staffList as $stf): ?>
                                        <option value="<?php echo (int)$stf['id']; ?>"><?php echo e($stf['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="staffHint">Only staff working on the chosen date are listed.</div>
                            <?php else: ?>
                                <div class="alert alert-warning py-2 small mb-0">
                                    No active staff member can serve a walk-in. Add staff and set their working hours first.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label" for="start_time">Time <span class="required">*</span></label>
                            <select class="form-select" id="start_time" name="start_time" required disabled>
                                <option value="">Select a date first</option>
                            </select>
                            <div class="small text-muted mt-1" id="availabilityBox"></div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label" for="payment_method">Payment Method</label>
                            <select class="form-select" id="payment_method" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="transaction_reference">Reference (optional)</label>
                            <input type="text" class="form-control" id="transaction_reference" name="transaction_reference" placeholder="e.g. TRF-1234">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="discountInput">Discount</label>
                            <input type="number" class="form-control" id="discountInput" name="discount" min="0" step="0.01" value="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                    </div>

                    <div class="bg-cream rounded-4 p-3 mb-3">
                        <div class="d-flex justify-content-between py-1"><span class="text-muted">Subtotal</span><span class="fw-semibold" id="subtotalDisplay">0.00</span></div>
                        <div class="d-flex justify-content-between py-1"><span class="text-muted">Tax (<?php echo (float)get_setting('tax_rate', 15); ?>%)</span><span class="fw-semibold" id="taxDisplay">0.00</span></div>
                        <div class="d-flex justify-content-between py-1 border-top mt-1 pt-2"><span class="fw-bold">Total to collect</span><span class="fw-bold text-rose fs-5" id="totalDisplay">0.00</span></div>
                    </div>
                    <input type="hidden" id="taxRateInput" value="<?php echo (float)get_setting('tax_rate', 15); ?>">

                    <button type="submit" class="btn btn-rose btn-lg w-100 rounded-pill" id="submitWalkin" disabled>
                        <i class="bi bi-check2-circle me-2"></i>Complete Walk-In &amp; Process Payment
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card table-card">
            <div class="card-header">Recent Walk-In Appointments</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Customer</th><th>Date</th><th>Total</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$recent): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No walk-ins yet.</td></tr>
                    <?php else: foreach ($recent as $r): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo e($r['customer_name']); ?></td>
                            <td class="small"><?php echo fmt_date($r['appointment_date']); ?> · <?php echo fmt_time($r['start_time']); ?></td>
                            <td class="fw-semibold"><?php echo money($r['total_amount']); ?></td>
                            <td><?php echo status_badge($r['payment_status']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    /* Customer search filter */
    var searchInput = document.getElementById('customerSearch');
    var list = document.getElementById('customerList');
    if (searchInput && list) {
        searchInput.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var visible = 0;
            list.querySelectorAll('.customer-item').forEach(function (item) {
                var show = item.dataset.search.indexOf(q) !== -1;
                item.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            document.getElementById('customerNoResults').style.display = visible ? 'none' : '';
        });
    }

    /* New customer fields → hidden inputs */
    var newFirst = document.getElementById('newFirst');
    var newLast = document.getElementById('newLast');
    var newPhone = document.getElementById('newPhone');
    var newEmail = document.getElementById('newEmail');
    function syncNew() {
        document.getElementById('walkinFirst').value = newFirst.value;
        document.getElementById('walkinLast').value = newLast.value;
        document.getElementById('walkinPhone').value = newPhone.value;
        document.getElementById('walkinEmail').value = newEmail.value;
        syncSubmit();
    }
    if (newFirst) { [newFirst, newLast, newPhone, newEmail].forEach(function (el) { el.addEventListener('input', syncNew); }); }

    /* Service rows, totals and the date -> staff -> time cascade are shared
       with the appointment form (assets/js/app.js). A walk-in only adds the
       rule that it needs a customer, a service and a real slot before it can
       be paid — a closed day leaves the time select disabled, so the button
       stays off instead of inviting a submission the server must refuse. */
    var form          = document.querySelector('[data-booking-cascade]');
    var rowsBox       = document.getElementById('serviceRows');
    var submitBtn     = document.getElementById('submitWalkin');
    var customerInput = document.getElementById('customerIdInput');
    var timeSel       = document.getElementById('start_time');

    function syncSubmit() {
        var hasCustomer = !!(customerInput && customerInput.value) ||
            !!(newFirst && newFirst.value.trim() && newLast.value.trim() && newPhone.value.trim());
        var hasTime = !!timeSel && !timeSel.disabled && !!timeSel.value;
        submitBtn.disabled = !(rowsBox.querySelectorAll('.service-row').length && hasCustomer && hasTime);
    }

    rowsBox.addEventListener('serviceschanged', syncSubmit);
    timeSel.addEventListener('change', syncSubmit);
    if (form) form.addEventListener('cascadechange', syncSubmit);
    syncSubmit();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
