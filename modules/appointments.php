<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Appointment management module.
 * Supports: multi-service booking, availability checks, rescheduling,
 * status changes and a waitlist (public booking requests).
 */

require_permission('appointments');

$action = $_GET['action'] ?? 'list';

/* ============================================================
 * SAVE appointment (create / edit / reschedule)
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_appointment') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token. Please try again.');
        redirect('appointments.php');
    }

    $id         = (int)($_POST['id'] ?? 0);
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $staffId    = (int)($_POST['staff_id'] ?? 0);
    $serviceIds = array_values(array_filter(array_map('intval', $_POST['service_ids'] ?? [])));
    $date       = $_POST['appointment_date'] ?? '';
    $start      = $_POST['start_time'] ?? '';
    $notes      = trim($_POST['notes'] ?? '');
    $discount   = max(0, (float)($_POST['discount'] ?? 0));
    $newStatus  = $_POST['status'] ?? 'pending';

    /* Assigned staff is OPTIONAL — 0 means unassigned */
    $assignedStaff = null;
    if ($staffId > 0) {
        $stmt = db()->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM staff WHERE id = :id AND status = 'active'");
        $stmt->execute(['id' => $staffId]);
        $assignedStaff = $stmt->fetch();
        if (!$assignedStaff) {
            flash_set('danger', 'The selected staff member is not available.');
            redirect($id ? "appointments.php?action=edit&id={$id}" : 'appointments.php?action=create');
        }
    }

    $errors = [];
    if ($customerId <= 0)            { $errors[] = 'Please select a customer.'; }
    if (!$serviceIds)                { $errors[] = 'Please select at least one service.'; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $errors[] = 'Please select a valid date.'; }
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start)) { $errors[] = 'Please select a valid time.'; }
    if (!in_array($newStatus, ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'], true)) {
        $newStatus = 'pending';
    }

    $startTime = date('H:i:s', strtotime($start));

    /* Load service data.
       Only active services may be newly booked. A service that is already
       attached to this appointment is still accepted while editing, so
       deactivating a service never makes its history uneditable (§15). */
    $services = [];
    if ($serviceIds) {
        $in = implode(',', array_fill(0, count($serviceIds), '?'));
        $stmt = db()->prepare("SELECT * FROM services WHERE id IN ($in)");
        $stmt->execute($serviceIds);
        $services = $stmt->fetchAll();

        $alreadyOnAppointment = [];
        if ($id) {
            $existing = db()->prepare('SELECT service_id FROM appointment_services WHERE appointment_id = :id');
            $existing->execute(['id' => $id]);
            $alreadyOnAppointment = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
        }

        $bookable = [];
        foreach ($services as $svc) {
            if ($svc['status'] === 'active' || in_array((int)$svc['id'], $alreadyOnAppointment, true)) {
                $bookable[(int)$svc['id']] = $svc;
            }
        }
        $services = array_values($bookable);

        if (count($services) !== count(array_unique($serviceIds))) {
            $errors[] = 'One or more selected services are no longer available. Remove them and choose an active service.';
        }
    }

    if ($errors) {
        foreach ($errors as $err) {
            flash_set('danger', $err);
        }
        $errQuery = $id ? "action=edit&id={$id}" : 'action=create';
        $errQuery .= '&customer_id=' . $customerId;
        redirect('appointments.php?' . $errQuery);
    }

    $subtotal = array_sum(array_column($services, 'price'));
    $minutes  = array_sum(array_column($services, 'duration_minutes'));
    $endTime  = date('H:i:s', time_seconds($startTime) + $minutes * 60);
    $taxRate  = (float)get_setting('tax_rate', 15);
    $tax      = ($subtotal - $discount) * ($taxRate / 100);
    $total    = $subtotal - $discount + $tax;

    /* Availability must be re-checked server-side, with the full context:
       salon hours, the assigned staff member's shift and breaks, the service
       duration, the staff member's skills and both staff and customer
       conflicts. slot_is_available() alone only knew about the salon. */
    $problem = scheduling_validate([
        'date'             => $date,
        'start'            => $startTime,
        'duration_minutes' => $minutes,
        'staff_id'         => $assignedStaff ? (int)$assignedStaff['id'] : 0,
        'customer_id'      => $customerId,
        'exclude_id'       => $id ?: 0,
        'service_ids'      => $serviceIds,
    ]);

    if ($problem !== null) {
        flash_set('danger', $problem);
        redirect($id ? "appointments.php?action=edit&id={$id}" : "appointments.php?action=create&customer_id={$customerId}");
    }

    try {
        db()->beginTransaction();

        if ($id) {
            $stmt = db()->prepare(
                'UPDATE appointments SET customer_id = :customer_id, staff_id = :staff_id, appointment_date = :date, start_time = :start,
                 end_time = :end, status = :status, notes = :notes, subtotal = :subtotal, discount = :discount,
                 tax = :tax, total_amount = :total
                 WHERE id = :id'
            );
            $stmt->execute([
                'customer_id' => $customerId, 'staff_id' => $assignedStaff ? (int)$assignedStaff['id'] : null,
                'date' => $date, 'start' => $startTime, 'end' => $endTime,
                'status' => $newStatus, 'notes' => $notes, 'subtotal' => $subtotal, 'discount' => $discount,
                'tax' => $tax, 'total' => $total, 'id' => $id,
            ]);

            db()->prepare('DELETE FROM appointment_services WHERE appointment_id = :id')->execute(['id' => $id]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO appointments (customer_id, staff_id, appointment_date, start_time, end_time, status, notes, subtotal, discount, tax, total_amount, created_by)
                 VALUES (:customer_id, :staff_id, :date, :start, :end, :status, :notes, :subtotal, :discount, :tax, :total, :created_by)'
            );
            $stmt->execute([
                'customer_id' => $customerId, 'staff_id' => $assignedStaff ? (int)$assignedStaff['id'] : null,
                'date' => $date, 'start' => $startTime, 'end' => $endTime,
                'status' => $newStatus, 'notes' => $notes, 'subtotal' => $subtotal, 'discount' => $discount,
                'tax' => $tax, 'total' => $total, 'created_by' => current_user()['id'],
            ]);
            $id = (int)db()->lastInsertId();

            /* Create the invoice automatically */
            $stmt = db()->prepare(
                'INSERT INTO invoices (invoice_number, appointment_id, customer_id, subtotal, discount, tax, total, status)
                 VALUES (:invnum, :appt, :customer, :subtotal, :discount, :tax, :total, "unpaid")'
            );
            $stmt->execute([
                'invnum' => next_invoice_number(), 'appt' => $id, 'customer' => $customerId,
                'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total,
            ]);
        }

        $stmt = db()->prepare(
            'INSERT INTO appointment_services (appointment_id, service_id, price, duration_minutes) VALUES (:appt, :svc, :price, :dur)'
        );
        foreach ($services as $svc) {
            $stmt->execute(['appt' => $id, 'svc' => $svc['id'], 'price' => $svc['price'], 'dur' => $svc['duration_minutes']]);
        }

        /* Mark waitlist entry converted when coming from it */
        $waitlistId = (int)($_POST['waitlist_id'] ?? 0);
        if ($waitlistId > 0) {
            db()->prepare("UPDATE waitlist SET status = 'converted' WHERE id = :id")->execute(['id' => $waitlistId]);
        }

        /* Invoice totals on edit */
        if (isset($_POST['id']) && $_POST['id']) {
            db()->prepare(
                'UPDATE invoices SET subtotal = :s, discount = :d, tax = :t, total = :total WHERE appointment_id = :appt'
            )->execute(['s' => $subtotal, 'd' => $discount, 't' => $tax, 'total' => $total, 'appt' => $id]);
        }

        db()->commit();

        $staffLog = $assignedStaff ? ' · staff: ' . $assignedStaff['name'] : '';
        log_activity('create', 'appointments', "Saved appointment #{$id} for customer #{$customerId} on {$date}{$staffLog}");
        notify('Appointment saved', "Appointment #{$id} was saved on " . fmt_date($date) . ' at ' . fmt_time($startTime), 'success');

        flash_set('success', 'Appointment saved successfully.');
        redirect('appointments.php?action=view&id=' . $id);
    } catch (Throwable $e) {
        db()->rollBack();
        flash_set('danger', 'Something went wrong. Please try again.');
        redirect($id ? "appointments.php?action=edit&id={$id}" : 'appointments.php?action=create');
    }
}

/* ============================================================
 * CHANGE STATUS
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'change_status') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token.');
        redirect('appointments.php');
    }
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if (!in_array($status, ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'], true)) {
        flash_set('danger', 'Invalid status.');
    } else {
        /* Putting a cancelled / no-show appointment back on the calendar is a
           NEW occupancy: its slot may have been rebooked, the staff member may
           no longer work then, or the opening hours may have changed. An
           appointment that never left the calendar is left alone, so a change
           of business hours can never strand one (§14). */
        if (in_array($status, ['pending', 'confirmed', 'in_progress', 'completed'], true)) {
            $stmt = db()->prepare('SELECT * FROM appointments WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $appt = $stmt->fetch();

            if ($appt && in_array($appt['status'], ['cancelled', 'no_show'], true)) {
                $minutes = max(0, intdiv(time_seconds($appt['end_time']) - time_seconds($appt['start_time']), 60));

                $problem = scheduling_validate([
                    'date'             => $appt['appointment_date'],
                    'start'            => $appt['start_time'],
                    'duration_minutes' => $minutes,
                    'staff_id'         => (int)($appt['staff_id'] ?? 0),
                    'customer_id'      => (int)$appt['customer_id'],
                    'exclude_id'       => $id,
                ]);

                if ($problem !== null) {
                    flash_set('danger', 'That appointment cannot be reinstated: ' . $problem);
                    redirect('appointments.php?action=view&id=' . $id);
                }
            }
        }

        db()->prepare('UPDATE appointments SET status = :status WHERE id = :id')->execute(['status' => $status, 'id' => $id]);
        log_activity('update', 'appointments', "Changed appointment #{$id} status to {$status}");

        if ($status === 'cancelled') {
            db()->prepare("UPDATE invoices SET status = 'void' WHERE appointment_id = :id AND status = 'unpaid'")->execute(['id' => $id]);
            notify('Appointment cancelled', "Appointment #{$id} was cancelled.", 'warning');
        } else {
            notify('Appointment status changed', "Appointment #{$id} is now " . ucwords(str_replace('_', ' ', $status)) . '.', 'info');
        }
        flash_set('success', 'Appointment status updated.');
    }
    redirect('appointments.php?action=view&id=' . $id);
}

/* ============================================================
 * DELETE appointment
 * ============================================================ */
if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'delete_appointment') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token.');
        redirect('appointments.php');
    }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    db()->prepare('DELETE FROM appointments WHERE id = :id')->execute(['id' => $id]);
    log_activity('delete', 'appointments', "Deleted appointment #{$id}");
    flash_set('success', 'Appointment deleted.');
    redirect('appointments.php');
}

/* ============================================================
 * WAITLIST actions
 * ============================================================ */
if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'cancel_waitlist') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token.');
        redirect('appointments.php?view=waitlist');
    }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    db()->prepare("UPDATE waitlist SET status = 'cancelled' WHERE id = :id")->execute(['id' => $id]);
    flash_set('success', 'Waitlist request cancelled.');
    redirect('appointments.php?view=waitlist');
}

/* ============================================================
 * LIST (with waitlist tab)
 * ============================================================ */
if ($action === 'list') {
    $tab     = $_GET['view'] ?? 'appointments';
    $q       = trim($_GET['q'] ?? '');
    $date    = $_GET['date'] ?? '';
    $status  = $_GET['status'] ?? '';
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 15;

    $where  = [];
    $params = [];

    if ($q !== '') {
        $like = "%{$q}%";
        $where[]  = '(c.first_name LIKE :q1 OR c.last_name LIKE :q2 OR c.phone LIKE :q3)';
        $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
    }
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $where[]        = 'a.appointment_date = :date';
        $params['date'] = $date;
    }
    if (in_array($status, ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'], true)) {
        $where[]          = 'a.status = :status';
        $params['status'] = $status;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = db()->prepare("SELECT COUNT(*) FROM appointments a JOIN customers c ON c.id = a.customer_id {$whereSql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $pg    = paginate($total, $perPage, $page);

    $stmt = db()->prepare(
        "SELECT a.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, c.phone AS customer_phone,
                CONCAT(s.first_name, ' ', s.last_name) AS staff_name
         FROM appointments a
         JOIN customers c ON c.id = a.customer_id
         LEFT JOIN staff s ON s.id = a.staff_id
         {$whereSql}
         ORDER BY a.appointment_date DESC, a.start_time DESC
         LIMIT {$perPage} OFFSET {$pg['offset']}"
    );
    $stmt->execute($params);
    $appointments = $stmt->fetchAll();

    /* Waitlist */
    $waitlist = [];
    if (can('waitlist')) {
        $stmt = db()->query(
            'SELECT w.*, CONCAT(c.first_name, " ", c.last_name) AS customer_name, s.name AS service_name
             FROM waitlist w
             LEFT JOIN customers c ON c.id = w.customer_id
             LEFT JOIN services s ON s.id = w.service_id
             ORDER BY w.created_at DESC LIMIT 50'
        );
        $waitlist = $stmt->fetchAll();
    }

    $page_title = 'Appointments';
    $active     = 'appointments';
    include __DIR__ . '/../includes/header.php';
    ?>

    <ul class="nav nav-pills mb-3 gap-1">
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'appointments' ? 'active' : ''; ?>" href="appointments.php?view=appointments" style="<?php echo $tab === 'appointments' ? 'background:var(--bsai-rose);' : 'color:var(--bsai-charcoal-2);'; ?>">Appointments</a>
        </li>
        <?php if (can('waitlist')): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'waitlist' ? 'active' : ''; ?>" href="appointments.php?view=waitlist" style="<?php echo $tab === 'waitlist' ? 'background:var(--bsai-rose);' : 'color:var(--bsai-charcoal-2);'; ?>">
                Waitlist
                <?php $wlPending = (int)db()->query("SELECT COUNT(*) FROM waitlist WHERE status = 'waiting'")->fetchColumn(); ?>
                <?php if ($wlPending): ?><span class="badge bg-danger ms-1"><?php echo $wlPending; ?></span><?php endif; ?>
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <?php if ($tab === 'waitlist'): ?>

        <div class="card table-card">
            <div class="card-header">Booking Requests &amp; Waitlist</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Requested</th><th>Name</th><th>Phone</th><th>Service</th><th>Preferred</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$waitlist): ?>
                        <tr><td colspan="7"><div class="empty-state">
                            <div class="icon"><i class="bi bi-journal-plus"></i></div>
                            <p class="text-muted mb-0">No waitlist requests found.</p>
                        </div></td></tr>
                    <?php else: foreach ($waitlist as $w): ?>
                        <tr>
                            <td class="small text-muted"><?php echo fmt_datetime($w['created_at']); ?></td>
                            <td class="fw-semibold"><?php echo e($w['customer_name'] ?: $w['name'] ?: '—'); ?></td>
                            <td><?php echo e($w['phone'] ?: '—'); ?></td>
                            <td><?php echo e($w['service_name'] ?: '—'); ?></td>
                            <td>
                                <?php echo $w['preferred_date'] ? fmt_date($w['preferred_date']) : '—'; ?>
                                <?php echo $w['preferred_time'] ? ' · ' . fmt_time($w['preferred_time']) : ''; ?>
                            </td>
                            <td><?php echo status_badge($w['status']); ?></td>
                            <td class="text-end">
                                <?php if ($w['status'] === 'waiting'): ?>
                                    <a href="appointments.php?action=create&waitlist_id=<?php echo $w['id']; ?><?php echo $w['customer_id'] ? '&customer_id=' . $w['customer_id'] : ''; ?>"
                                       class="btn btn-sm btn-rose rounded-pill"><i class="bi bi-calendar2-plus me-1"></i>Convert</a>
                                    <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                            data-confirm="Cancel this waitlist request?"
                                            data-confirm-action="appointments.php?form_action=cancel_waitlist&id=<?php echo $w['id']; ?>"
                                            data-confirm-label="Cancel"><i class="bi bi-x"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>

    <div class="card table-card">
        <div class="card-body p-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <form method="get" action="appointments.php" class="d-flex flex-wrap gap-2">
                <input type="hidden" name="view" value="appointments">
                <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="Customer name or phone" style="width:220px;">
                <input type="date" class="form-control" name="date" value="<?php echo e($date); ?>">
                <select class="form-select" name="status" style="width:160px;">
                    <option value="">All statuses</option>
                    <?php foreach (['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $status === $st ? 'selected' : ''; ?>><?php echo e(ucwords(str_replace('_', ' ', $st))); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-charcoal" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
                <a href="appointments.php" class="btn btn-light">Reset</a>
            </form>
            <a href="appointments.php?action=create" class="btn btn-rose rounded-pill"><i class="bi bi-calendar2-plus me-1"></i>New Appointment</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>#</th><th>Customer</th><th>Date &amp; Time</th><th>Services</th><th>Staff</th><th>Total</th><th>Status</th><th>Payment</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php if (!$appointments): ?>
                    <tr><td colspan="9"><div class="empty-state">
                        <div class="icon"><i class="bi bi-calendar2-x"></i></div>
                        <p class="text-muted mb-2">No appointments found.</p>
                        <a href="appointments.php?action=create" class="btn btn-sm btn-rose rounded-pill">Create an appointment</a>
                    </div></td></tr>
                <?php else: foreach ($appointments as $a): ?>
                    <tr>
                        <td class="text-muted">#<?php echo $a['id']; ?></td>
                        <td>
                            <a href="customers.php?action=view&id=<?php echo $a['customer_id']; ?>" class="fw-semibold text-body text-decoration-none"><?php echo e($a['customer_name']); ?></a>
                            <div class="small text-muted"><?php echo e($a['customer_phone']); ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?php echo fmt_date($a['appointment_date']); ?></div>
                            <div class="small text-muted"><?php echo fmt_time($a['start_time']); ?> – <?php echo fmt_time($a['end_time']); ?></div>
                        </td>
                        <td class="small text-muted">
                            <?php
                            $stmt = db()->prepare('SELECT s.name FROM appointment_services aps JOIN services s ON s.id = aps.service_id WHERE aps.appointment_id = :id');
                            $stmt->execute(['id' => $a['id']]);
                            echo e(implode(', ', array_column($stmt->fetchAll(), 'name') ?: ['—']));
                            ?>
                        </td>
                        <td>
                            <?php if ($a['staff_name']): ?>
                                <?php if (can('staff')): ?>
                                    <a href="staff.php?action=view&id=<?php echo (int)$a['staff_id']; ?>" class="small text-decoration-none"><?php echo e($a['staff_name']); ?></a>
                                <?php else: ?>
                                    <span class="small"><?php echo e($a['staff_name']); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-light text-muted">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-semibold"><?php echo money($a['total_amount']); ?></td>
                        <td><?php echo status_badge($a['status']); ?></td>
                        <td><?php echo status_badge($a['payment_status']); ?></td>
                        <td class="text-end">
                            <a href="appointments.php?action=view&id=<?php echo $a['id']; ?>" class="btn btn-sm btn-light rounded-pill" title="View"><i class="bi bi-eye"></i></a>
                            <a href="appointments.php?action=edit&id=<?php echo $a['id']; ?>" class="btn btn-sm btn-light rounded-pill" title="Edit / Reschedule"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                    data-confirm="Are you sure you want to delete this appointment?"
                                    data-confirm-action="appointments.php?form_action=delete_appointment&id=<?php echo $a['id']; ?>"
                                    data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pg['totalPages'] > 1): ?>
            <div class="card-body border-top d-flex justify-content-between align-items-center">
                <span class="small text-muted"><?php echo $pg['total']; ?> appointment(s)</span>
                <nav><ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo $pg['hasPrev'] ? '' : 'disabled'; ?>"><a class="page-link" href="appointments.php?<?php echo e(query_string(['page' => $pg['prevPage']])); ?>">Prev</a></li>
                    <li class="page-item disabled"><span class="page-link">Page <?php echo $pg['page']; ?> of <?php echo $pg['totalPages']; ?></span></li>
                    <li class="page-item <?php echo $pg['hasNext'] ? '' : 'disabled'; ?>"><a class="page-link" href="appointments.php?<?php echo e(query_string(['page' => $pg['nextPage']])); ?>">Next</a></li>
                </ul></nav>
            </div>
        <?php endif; ?>
    </div>

    <?php endif; ?>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* ============================================================
 * CREATE / EDIT form
 * ============================================================ */
if ($action === 'create' || $action === 'edit') {
    $isEdit = $action === 'edit';
    $id     = (int)($_GET['id'] ?? 0);

    $appointment = [
        'id' => 0, 'customer_id' => (int)($_GET['customer_id'] ?? 0), 'staff_id' => 0, 'appointment_date' => '', 'start_time' => '',
        'end_time' => '', 'status' => 'pending', 'notes' => '', 'discount' => 0,
    ];
    $selectedServices = [];

    if ($isEdit) {
        $stmt = db()->prepare('SELECT * FROM appointments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $found = $stmt->fetch();
        if (!$found) {
            flash_set('warning', 'Appointment not found.');
            redirect('appointments.php');
        }
        $appointment = $found;

        $stmt = db()->prepare('SELECT * FROM appointment_services WHERE appointment_id = :id');
        $stmt->execute(['id' => $id]);
        $selectedServices = $stmt->fetchAll();
    }

    /* Prefill from waitlist */
    $waitlistId = (int)($_GET['waitlist_id'] ?? 0);
    $waitlist   = null;
    if ($waitlistId) {
        $stmt = db()->prepare('SELECT * FROM waitlist WHERE id = :id');
        $stmt->execute(['id' => $waitlistId]);
        $waitlist = $stmt->fetch();
        if ($waitlist) {
            $appointment['customer_id']      = $appointment['customer_id'] ?: (int)$waitlist['customer_id'];
            $appointment['appointment_date'] = $waitlist['preferred_date'] ?: '';
            $appointment['start_time']       = $waitlist['preferred_time'] ?: '';
            $appointment['notes']            = $waitlist['notes'] ?: $appointment['notes'];
            if ($waitlist['service_id'] && !$isEdit) {
                $stmt = db()->prepare('SELECT * FROM services WHERE id = :id');
                $stmt->execute(['id' => $waitlist['service_id']]);
                $svc = $stmt->fetch();
                if ($svc) {
                    $selectedServices[] = ['service_id' => $svc['id'], 'price' => $svc['price'], 'duration_minutes' => $svc['duration_minutes']];
                }
            }
        }
    }

    $customers = db()->query('SELECT id, first_name, last_name, phone FROM customers ORDER BY first_name, last_name')->fetchAll();

    /* Service menu for the picker, and a lookup that also resolves
       deactivated services so an existing appointment still shows them. */
    $serviceTree    = service_picker_tree(true);
    $serviceLookup  = [];
    foreach (group_services_by_hierarchy(services_for_picker(false)) as $node) {
        foreach ($node['subcategories'] as $subNode) {
            foreach ($subNode['services'] as $svc) { $serviceLookup[(int)$svc['id']] = $svc; }
        }
        foreach ($node['direct'] as $svc) { $serviceLookup[(int)$svc['id']] = $svc; }
    }

    /* Active staff members available for assignment */
    $staffList = [];
    if (can('appointments')) {
        try {
            $staffList = db()->query("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM staff WHERE status = 'active' ORDER BY first_name, last_name")->fetchAll();
        } catch (Throwable $e) {
            $staffList = [];
        }
    }

    $page_title = $isEdit ? 'Edit Appointment' : 'New Appointment';
    $active     = 'appointments';
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><?php echo $isEdit ? 'Edit / Reschedule Appointment' : 'Book New Appointment'; ?></div>
                <div class="card-body">
                    <form method="post" action="appointments.php"
                          data-booking-cascade
                          data-availability-url="<?php echo base_url('availability.php'); ?>"
                          data-allow-unassigned="true"
                          data-chosen-time="<?php echo e($appointment['start_time']); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="form_action" value="save_appointment">
                        <input type="hidden" name="id" value="<?php echo $isEdit ? (int)$appointment['id'] : 0; ?>">
                        <input type="hidden" name="waitlist_id" value="<?php echo (int)$waitlistId; ?>">

                        <?php if ($waitlist): ?>
                            <div class="alert alert-warning rounded-3 py-2 small">
                                <i class="bi bi-info-circle me-2"></i>
                                Converting a booking request from
                                <strong><?php echo e($waitlist['customer_name'] ?: $waitlist['name'] ?: '—'); ?></strong>
                                (<?php echo e($waitlist['phone'] ?: '—'); ?>). The request will be marked as converted.
                            </div>
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="customer_id">Customer <span class="required">*</span></label>
                                <select class="form-select" id="customer_id" name="customer_id" required>
                                    <option value="">— Select customer —</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo (int)$appointment['customer_id'] === (int)$c['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($c['first_name'] . ' ' . $c['last_name'] . ' · ' . $c['phone']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="form-text mb-0">Customer not listed?</span>
                                    <a href="customers.php?action=add" class="btn btn-sm btn-soft rounded-pill"><i class="bi bi-person-plus me-1"></i>Add a customer</a>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Services <span class="required">*</span></label>
                                <div class="d-flex gap-2 align-items-start">
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
                                    <button type="button" class="btn btn-soft rounded-pill px-4 mt-0" id="addServiceBtn"><i class="bi bi-plus-lg me-1"></i>Add</button>
                                </div>
                                <div class="form-text">The total duration of the chosen services decides which start times fit before closing.</div>
                                <div id="serviceRows" class="mt-3">
                                    <?php foreach ($selectedServices as $ss):
                                        $svcMeta = $serviceLookup[(int)$ss['service_id']] ?? null; ?>
                                        <div class="service-row d-flex align-items-center gap-2 mb-2"
                                             data-price="<?php echo e($ss['price']); ?>"
                                             data-dur="<?php echo (int)$ss['duration_minutes']; ?>">
                                            <input type="hidden" name="service_ids[]" value="<?php echo (int)$ss['service_id']; ?>">
                                            <div class="flex-grow-1">
                                                <div class="fw-semibold small">
                                                    <?php echo e($svcMeta['name'] ?? ('Service #' . (int)$ss['service_id'])); ?>
                                                    <?php if ($svcMeta && $svcMeta['status'] !== 'active'): ?>
                                                        <span class="badge bg-light text-muted ms-1">Deactivated</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <?php if ($svcMeta): ?>
                                                        <?php echo e(service_hierarchy_path($svcMeta, ' · ')); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small text-muted"><?php echo money($ss['price']); ?> <?php echo e(currency()); ?> · <?php echo (int)$ss['duration_minutes']; ?> min</div>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-light btn-remove-service"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <template id="serviceRowTemplate"></template>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="appointment_date">Appointment Date <span class="required">*</span></label>
                                <input type="date" class="form-control" id="appointment_date" name="appointment_date" required
                                       min="<?php echo date('Y-m-d'); ?>"
                                       value="<?php echo e($appointment['appointment_date']); ?>"
                                       data-exclude="<?php echo $isEdit ? (int)$appointment['id'] : ''; ?>">
                            </div>
                            <?php if ($staffList): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="staff_id">Assigned Staff</label>
                                <select class="form-select" id="staff_id" name="staff_id">
                                    <option value="">— Unassigned —</option>
                                    <?php foreach ($staffList as $stf): ?>
                                        <option value="<?php echo (int)$stf['id']; ?>" <?php echo (int)($appointment['staff_id'] ?? 0) === (int)$stf['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($stf['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="staffHint">Narrows to staff working on the chosen date once a date is picked.</div>
                            </div>
                            <?php endif; ?>

                            <div class="col-md-6">
                                <label class="form-label" for="start_time">Start Time <span class="required">*</span></label>
                                <select class="form-select" id="start_time" name="start_time" required>
                                    <option value="">Select a date first</option>
                                    <?php if ($isEdit && $appointment['start_time']): ?>
                                        <option value="<?php echo e($appointment['start_time']); ?>" selected><?php echo fmt_time($appointment['start_time']); ?></option>
                                    <?php endif; ?>
                                </select>
                                <div class="small text-muted mt-1" id="availabilityBox"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="status">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <?php foreach (['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'] as $st): ?>
                                        <option value="<?php echo $st; ?>" <?php echo $appointment['status'] === $st ? 'selected' : ''; ?>><?php echo e(ucwords(str_replace('_', ' ', $st))); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="2"><?php echo e($appointment['notes']); ?></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="discountInput">Discount (<?php echo e(currency()); ?>)</label>
                                <input type="number" class="form-control" id="discountInput" name="discount" min="0" step="0.01" value="<?php echo e($appointment['discount']); ?>">
                            </div>
                        </div>

                        <div class="mt-4 d-flex gap-2">
                            <button type="submit" class="btn btn-rose rounded-pill px-4"><i class="bi bi-check2 me-1"></i>Save Appointment</button>
                            <a href="appointments.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">Summary</div>
                <div class="card-body">
                    <dl class="mb-0">
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <dt class="text-muted small">Subtotal</dt>
                            <dd class="fw-semibold mb-0" id="subtotalDisplay">0.00</dd>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <dt class="text-muted small">Total Duration</dt>
                            <dd class="fw-semibold mb-0" id="durationDisplay">0 min</dd>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <dt class="text-muted small">Tax (<?php echo (float)get_setting('tax_rate', 15); ?>%)</dt>
                            <dd class="fw-semibold mb-0" id="taxDisplay">0.00</dd>
                        </div>
                        <div class="d-flex justify-content-between py-2">
                            <dt class="text-muted small">Total</dt>
                            <dd class="fw-bold mb-0 text-rose" id="totalDisplay">0.00</dd>
                        </div>
                    </dl>
                    <input type="hidden" id="taxRateInput" value="<?php echo (float)get_setting('tax_rate', 15); ?>">
                </div>
            </div>

            <?php if ($isEdit): ?>
                <div class="card mt-3">
                    <div class="card-header">Quick Status</div>
                    <div class="card-body d-flex flex-column gap-2">
                        <?php foreach (['confirmed' => 'Confirm', 'in_progress' => 'Start', 'completed' => 'Complete', 'no_show' => 'No Show', 'cancelled' => 'Cancel'] as $st => $label): ?>
                            <?php if ($appointment['status'] !== $st): ?>
                                <form method="post" action="appointments.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="form_action" value="change_status">
                                    <input type="hidden" name="id" value="<?php echo (int)$appointment['id']; ?>">
                                    <input type="hidden" name="status" value="<?php echo $st; ?>">
                                    <button type="submit" class="btn btn-light w-100 rounded-pill text-start"><i class="bi bi-arrow-right-circle me-2 text-rose"></i><?php echo $label; ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* ============================================================
 * VIEW detail
 * ============================================================ */
if ($action === 'view') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare(
        'SELECT a.*, CONCAT(c.first_name, " ", c.last_name) AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                CONCAT(s.first_name, " ", s.last_name) AS staff_name
         FROM appointments a
         JOIN customers c ON c.id = a.customer_id
         LEFT JOIN staff s ON s.id = a.staff_id
         WHERE a.id = :id'
    );
    $stmt->execute(['id' => $id]);
    $appt = $stmt->fetch();
    if (!$appt) {
        flash_set('warning', 'Appointment not found.');
        redirect('appointments.php');
    }

    $stmt = db()->prepare(
        'SELECT aps.*, s.name AS service_name, c.name AS category_name, sc.name AS subcategory_name
           FROM appointment_services aps
           JOIN services s ON s.id = aps.service_id
           LEFT JOIN service_categories c     ON c.id  = s.category_id
           LEFT JOIN service_subcategories sc ON sc.id = s.subcategory_id
          WHERE aps.appointment_id = :id'
    );
    $stmt->execute(['id' => $id]);
    $services = $stmt->fetchAll();

    $payments = [];
    $stmt = db()->prepare('SELECT * FROM payments WHERE appointment_id = :id ORDER BY payment_date DESC');
    $stmt->execute(['id' => $id]);
    $payments = $stmt->fetchAll();

    $invoice = null;
    $stmt = db()->prepare('SELECT * FROM invoices WHERE appointment_id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $invoice = $stmt->fetch();

    $page_title = 'Appointment #' . $id;
    $active     = 'appointments';
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="appointments.php?action=edit&id=<?php echo $id; ?>" class="btn btn-rose rounded-pill"><i class="bi bi-pencil me-1"></i>Edit / Reschedule</a>
        <?php if ($invoice): ?>
            <a href="invoice-view.php?id=<?php echo $invoice['id']; ?>" class="btn btn-charcoal rounded-pill" target="_blank"><i class="bi bi-receipt me-1"></i>Invoice</a>
        <?php endif; ?>
        <?php if (in_array($appt['payment_status'], ['pending', 'partial'], true)): ?>
            <a href="payments.php?action=add&appointment_id=<?php echo $id; ?>" class="btn btn-gold rounded-pill"><i class="bi bi-credit-card me-1"></i>Record Payment</a>
        <?php endif; ?>
        <a href="appointments.php" class="btn btn-light rounded-pill"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Appointment #<?php echo $id; ?></span>
                    <span><?php echo status_badge($appt['status']); ?> <?php echo status_badge($appt['payment_status']); ?></span>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">Customer</dt>
                        <dd class="col-sm-8">
                            <a href="customers.php?action=view&id=<?php echo $appt['customer_id']; ?>" class="fw-semibold"><?php echo e($appt['customer_name']); ?></a>
                            <span class="text-muted small"> · <?php echo e($appt['customer_phone']); ?></span>
                        </dd>
                        <dt class="col-sm-4 text-muted">Date</dt><dd class="col-sm-8"><?php echo fmt_date($appt['appointment_date']); ?></dd>
                        <dt class="col-sm-4 text-muted">Time</dt><dd class="col-sm-8"><?php echo fmt_time($appt['start_time']); ?> – <?php echo fmt_time($appt['end_time']); ?></dd>
                        <dt class="col-sm-4 text-muted">Assigned Staff</dt>
                        <dd class="col-sm-8">
                            <?php if (!empty($appt['staff_name'])): ?>
                                <?php if (can('staff')): ?>
                                    <a href="staff.php?action=view&id=<?php echo (int)$appt['staff_id']; ?>" class="fw-semibold"><?php echo e($appt['staff_name']); ?></a>
                                <?php else: ?>
                                    <span class="fw-semibold"><?php echo e($appt['staff_name']); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Unassigned</span>
                            <?php endif; ?>
                        </dd>
                        <dt class="col-sm-4 text-muted">Services</dt>
                        <dd class="col-sm-8">
                            <?php foreach ($services as $s): ?>
                                <div>
                                    <?php echo e($s['service_name']); ?>
                                    <span class="text-muted small">(<?php echo (int)$s['duration_minutes']; ?> min · <?php echo money($s['price']); ?>)</span>
                                    <?php if ($s['category_name']): ?>
                                        <div class="small text-muted">
                                            <?php echo e(implode(' › ', array_values(array_filter([
                                                $s['category_name'] ?: null,
                                                $s['subcategory_name'] ?: null,
                                            ])))); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </dd>
                        <dt class="col-sm-4 text-muted">Subtotal</dt><dd class="col-sm-8"><?php echo money($appt['subtotal']); ?></dd>
                        <dt class="col-sm-4 text-muted">Discount</dt><dd class="col-sm-8"><?php echo money($appt['discount']); ?></dd>
                        <dt class="col-sm-4 text-muted">Tax</dt><dd class="col-sm-8"><?php echo money($appt['tax']); ?></dd>
                        <dt class="col-sm-4 text-muted">Total</dt><dd class="col-sm-8 fw-bold text-rose"><?php echo money($appt['total_amount']); ?> <?php echo e(currency()); ?></dd>
                        <dt class="col-sm-4 text-muted">Notes</dt><dd class="col-sm-8"><?php echo $appt['notes'] ? e($appt['notes']) : '—'; ?></dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header">Status Management</div>
                <div class="card-body d-flex flex-column gap-2">
                    <?php foreach (['confirmed' => 'Confirm', 'in_progress' => 'Mark In Progress', 'completed' => 'Mark Completed', 'no_show' => 'Mark No Show', 'cancelled' => 'Cancel Appointment'] as $st => $label): ?>
                        <?php if ($appt['status'] !== $st): ?>
                            <form method="post" action="appointments.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="form_action" value="change_status">
                                <input type="hidden" name="id" value="<?php echo $id; ?>">
                                <input type="hidden" name="status" value="<?php echo $st; ?>">
                                <button type="submit" class="btn btn-light w-100 rounded-pill text-start">
                                    <i class="bi bi-arrow-right-circle me-2 text-rose"></i><?php echo $label; ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Payments</div>
                <div class="card-body p-0">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th class="ps-3">Date</th><th>Method</th><th class="pe-3 text-end">Amount</th></tr></thead>
                        <tbody>
                        <?php if (!$payments): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">No payments recorded.</td></tr>
                        <?php else: foreach ($payments as $p): ?>
                            <tr>
                                <td class="ps-3 small"><?php echo fmt_datetime($p['payment_date']); ?></td>
                                <td class="small"><?php echo e(ucwords(str_replace('_', ' ', $p['payment_method']))); ?></td>
                                <td class="pe-3 text-end fw-semibold"><?php echo money($p['amount']); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

redirect('appointments.php');
