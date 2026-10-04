<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Customer management module.
 * Administrator and Receptionist both manage customers.
 * Email is OPTIONAL.
 */

require_permission('customers');

$action = $_GET['action'] ?? 'list';
$user   = current_user();

/* ------------------------------------------------------------
 * Save customer (create / update)
 * ------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($action === 'add') || ($action === 'edit'))) {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token. Please try again.');
        redirect('customers.php');
    }

    $id        = (int)($_POST['id'] ?? 0);
    $first     = trim($_POST['first_name'] ?? '');
    $last      = trim($_POST['last_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $dob       = $_POST['date_of_birth'] ?? null;
    $gender    = $_POST['gender'] ?? null;
    $address   = trim($_POST['address'] ?? '');
    $skin      = trim($_POST['skin_type'] ?? '');
    $hair      = trim($_POST['hair_type'] ?? '');
    $allergies = trim($_POST['allergies'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    $errors = [];
    if ($first === '') { $errors[] = 'First name is required.'; }
    if ($last === '')  { $errors[] = 'Last name is required.'; }
    if ($phone === '') { $errors[] = 'Phone number is required.'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is not valid.';
    }
    if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dob)) {
        $errors[] = 'Date of birth is not valid.';
    }

    if ($errors) {
        foreach ($errors as $err) {
            flash_set('danger', $err);
        }
        redirect($action === 'edit' ? "customers.php?action=edit&id={$id}" : 'customers.php?action=add');
    }

    $data = [
        'first_name'     => $first,
        'last_name'      => $last,
        'phone'          => $phone,
        'email'          => $email !== '' ? $email : null,
        'date_of_birth'  => $dob !== '' ? $dob : null,
        'gender'         => $gender !== '' ? $gender : null,
        'address'        => $address !== '' ? $address : null,
        'skin_type'      => $skin !== '' ? $skin : null,
        'hair_type'      => $hair !== '' ? $hair : null,
        'allergies'      => $allergies !== '' ? $allergies : null,
        'notes'          => $notes !== '' ? $notes : null,
    ];

    try {
        if ($action === 'add') {
            $stmt = db()->prepare(
                'INSERT INTO customers (first_name, last_name, phone, email, date_of_birth, gender, address, skin_type, hair_type, allergies, notes)
                 VALUES (:first_name, :last_name, :phone, :email, :date_of_birth, :gender, :address, :skin_type, :hair_type, :allergies, :notes)'
            );
            $stmt->execute($data);
            $newId = (int)db()->lastInsertId();
            log_activity('create', 'customers', "Created customer {$first} {$last}");
            notify('Customer created', "{$first} {$last} was added as a new customer.", 'info');
            flash_set('success', 'Customer saved successfully.');
            redirect("customers.php?action=view&id={$newId}");
        } else {
            $data['id'] = $id;
            $stmt = db()->prepare(
                'UPDATE customers SET
                    first_name = :first_name, last_name = :last_name, phone = :phone, email = :email,
                    date_of_birth = :date_of_birth, gender = :gender, address = :address,
                    skin_type = :skin_type, hair_type = :hair_type, allergies = :allergies, notes = :notes
                 WHERE id = :id'
            );
            $stmt->execute($data);
            log_activity('update', 'customers', "Updated customer {$first} {$last}");
            flash_set('success', 'Customer updated successfully.');
            redirect("customers.php?action=view&id={$id}");
        }
    } catch (Throwable $e) {
        flash_set('danger', 'Something went wrong. Please try again.');
        redirect($action === 'edit' ? "customers.php?action=edit&id={$id}" : 'customers.php?action=add');
    }
}

/* ------------------------------------------------------------
 * Delete customer
 * ------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') && (($_POST['action'] ?? $_GET['action'] ?? '') === 'delete')) {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token.');
        redirect('customers.php');
    }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    try {
        $stmt = db()->prepare('SELECT first_name, last_name FROM customers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $c = $stmt->fetch();

        $stmt = db()->prepare('DELETE FROM customers WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($c) {
            log_activity('delete', 'customers', "Deleted customer {$c['first_name']} {$c['last_name']}");
        }
        flash_set('success', 'Customer deleted successfully.');
    } catch (Throwable $e) {
        flash_set('danger', 'Something went wrong. Please try again.');
    }
    redirect('customers.php');
}

/* ------------------------------------------------------------
 * LIST
 * ------------------------------------------------------------ */
if ($action === 'list') {
    $q      = trim($_GET['q'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 15;

    $where  = '';
    $params = [];
    if ($q !== '') {
        $like = "%{$q}%";
        $where  = 'WHERE first_name LIKE :q1 OR last_name LIKE :q2 OR phone LIKE :q3 OR COALESCE(email,"") LIKE :q4';
        $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
    }

    $countStmt = db()->prepare("SELECT COUNT(*) FROM customers {$where}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pg    = paginate($total, $perPage, $page);

    $stmt = db()->prepare(
        "SELECT c.*,
                (SELECT MAX(a.appointment_date) FROM appointments a WHERE a.customer_id = c.id AND a.status = 'completed') AS last_appointment,
                (SELECT COALESCE(SUM(a.total_amount),0) FROM appointments a WHERE a.customer_id = c.id) AS total_spent
         FROM customers c {$where}
         ORDER BY c.created_at DESC
         LIMIT {$perPage} OFFSET {$pg['offset']}"
    );
    $stmt->execute($params);
    $customers = $stmt->fetchAll();

    $page_title = 'Customers';
    $active     = 'customers';
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="card table-card">
        <div class="card-body p-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <form method="get" action="customers.php" class="d-flex gap-2 flex-grow-1" style="max-width:420px;">
                <input type="hidden" name="action" value="list">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0" name="q" value="<?php echo e($q); ?>"
                           placeholder="Search by name, phone or email" data-table-filter="#customerTable" data-href-filter>
                </div>
                <button class="btn btn-charcoal" type="submit">Search</button>
            </form>
            <a href="customers.php?action=add" class="btn btn-rose rounded-pill"><i class="bi bi-person-plus me-1"></i>Add Customer</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="customerTable">
                <thead>
                    <tr><th>Name</th><th>Phone</th><th>Email</th><th>Loyalty Points</th><th>Total Spent</th><th>Last Appointment</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php if (!$customers): ?>
                    <tr><td colspan="7">
                        <div class="empty-state">
                            <div class="icon"><i class="bi bi-people"></i></div>
                            <p class="text-muted mb-2">No customers found.</p>
                            <a href="customers.php?action=add" class="btn btn-sm btn-rose rounded-pill">Add your first customer</a>
                        </div>
                    </td></tr>
                <?php else: foreach ($customers as $c): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar"><?php echo e(initials($c['first_name'] . ' ' . $c['last_name'])); ?></span>
                                <a href="customers.php?action=view&id=<?php echo $c['id']; ?>" class="fw-semibold text-body text-decoration-none">
                                    <?php echo e($c['first_name'] . ' ' . $c['last_name']); ?>
                                </a>
                            </div>
                        </td>
                        <td><?php echo e($c['phone']); ?></td>
                        <td class="text-muted"><?php echo $c['email'] ? e($c['email']) : '<span class="badge bg-light text-muted">No email</span>'; ?></td>
                        <td><span class="badge badge-status bg-gold text-dark"><?php echo (int)$c['loyalty_points']; ?> pts</span></td>
                        <td class="fw-semibold"><?php echo money($c['total_spent']); ?></td>
                        <td class="text-muted"><?php echo $c['last_appointment'] ? fmt_date($c['last_appointment']) : '—'; ?></td>
                        <td class="text-end">
                            <a href="customers.php?action=view&id=<?php echo $c['id']; ?>" class="btn btn-sm btn-light rounded-pill" title="View"><i class="bi bi-eye"></i></a>
                            <a href="customers.php?action=edit&id=<?php echo $c['id']; ?>" class="btn btn-sm btn-light rounded-pill" title="Edit"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                    data-confirm="Are you sure you want to delete this customer? This will also remove their appointments and payments."
                                    data-confirm-action="customers.php?action=delete&id=<?php echo $c['id']; ?>"
                                    data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pg['totalPages'] > 1): ?>
            <div class="card-body border-top d-flex justify-content-between align-items-center">
                <span class="small text-muted"><?php echo $pg['total']; ?> customer(s)</span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $pg['hasPrev'] ? '' : 'disabled'; ?>">
                            <a class="page-link" href="customers.php?<?php echo e(query_string(['page' => $pg['prevPage']])); ?>">Prev</a>
                        </li>
                        <li class="page-item disabled"><span class="page-link">Page <?php echo $pg['page']; ?> of <?php echo $pg['totalPages']; ?></span></li>
                        <li class="page-item <?php echo $pg['hasNext'] ? '' : 'disabled'; ?>">
                            <a class="page-link" href="customers.php?<?php echo e(query_string(['page' => $pg['nextPage']])); ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* ------------------------------------------------------------
 * ADD / EDIT form
 * ------------------------------------------------------------ */
if ($action === 'add' || $action === 'edit') {
    $customer = [
        'id' => 0, 'first_name' => '', 'last_name' => '', 'phone' => '', 'email' => '',
        'date_of_birth' => '', 'gender' => '', 'address' => '', 'skin_type' => '',
        'hair_type' => '', 'allergies' => '', 'notes' => '',
    ];

    if ($action === 'edit') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM customers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $found = $stmt->fetch();
        if (!$found) {
            flash_set('warning', 'Customer not found.');
            redirect('customers.php');
        }
        $customer = $found;
    }

    $page_title = $action === 'add' ? 'Add Customer' : 'Edit Customer';
    $active     = 'customers';
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="card" style="max-width:900px;">
        <div class="card-header"><?php echo $action === 'add' ? 'New Customer' : 'Edit Customer — ' . e($customer['first_name'] . ' ' . $customer['last_name']); ?></div>
        <div class="card-body">
            <form method="post" action="customers.php?action=<?php echo $action; ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$customer['id']; ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="first_name">First Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="first_name" name="first_name" required value="<?php echo e($customer['first_name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="last_name">Last Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="last_name" name="last_name" required value="<?php echo e($customer['last_name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="phone">Phone Number <span class="required">*</span></label>
                        <input type="tel" class="form-control" id="phone" name="phone" required value="<?php echo e($customer['phone']); ?>" placeholder="e.g. 0911 234 567">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Email Address <span class="text-muted small">(optional)</span></label>
                        <input type="email" class="form-control" id="email" name="email" value="<?php echo e($customer['email']); ?>" placeholder="leave blank if not available">
                        <div class="form-text">Email is optional — customers can be saved without one.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="date_of_birth">Date of Birth</label>
                        <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" value="<?php echo e($customer['date_of_birth'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="gender">Gender</label>
                        <select class="form-select" id="gender" name="gender">
                            <option value="">— Not set —</option>
                            <option value="female" <?php echo $customer['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                            <option value="male" <?php echo $customer['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="other" <?php echo $customer['gender'] === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="address">Address</label>
                        <input type="text" class="form-control" id="address" name="address" value="<?php echo e($customer['address'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="skin_type">Skin Type</label>
                        <select class="form-select" id="skin_type" name="skin_type">
                            <option value="">— Not set —</option>
                            <?php foreach (['Normal','Oily','Dry','Combination','Sensitive'] as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo $customer['skin_type'] === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="hair_type">Hair Type</label>
                        <select class="form-select" id="hair_type" name="hair_type">
                            <option value="">— Not set —</option>
                            <?php foreach (['Straight','Wavy','Curly','Coily','Short','Long'] as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo $customer['hair_type'] === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="allergies">Allergies</label>
                        <textarea class="form-control" id="allergies" name="allergies" rows="2"><?php echo e($customer['allergies'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo e($customer['notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-rose rounded-pill px-4"><i class="bi bi-check2 me-1"></i>Save Customer</button>
                    <a href="customers.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* ------------------------------------------------------------
 * VIEW profile
 * ------------------------------------------------------------ */
if ($action === 'view') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM customers WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $customer = $stmt->fetch();
    if (!$customer) {
        flash_set('warning', 'Customer not found.');
        redirect('customers.php');
    }

    $fullName = $customer['first_name'] . ' ' . $customer['last_name'];

    $appts = [];
    $stmt = db()->prepare(
        'SELECT a.* FROM appointments a WHERE a.customer_id = :id ORDER BY a.appointment_date DESC, a.start_time DESC LIMIT 20'
    );
    $stmt->execute(['id' => $id]);
    $appts = $stmt->fetchAll();

    $ledger = [];
    $stmt = db()->prepare('SELECT * FROM loyalty_ledger WHERE customer_id = :id ORDER BY created_at DESC LIMIT 20');
    $stmt->execute(['id' => $id]);
    $ledger = $stmt->fetchAll();

    $stats = [
        'total_spent'    => 0,
        'appt_count'     => count($appts),
        'last_appt'      => null,
        'upcoming_appt'  => null,
        'pending_amount' => 0,
    ];
    foreach ($appts as $a) {
        $stats['total_spent'] += (float)$a['total_amount'];
        if ($a['status'] === 'completed') {
            $stats['last_appt'] = $stats['last_appt'] ?? $a;
        }
        if (in_array($a['status'], ['pending', 'confirmed', 'in_progress'])) {
            $stats['upcoming_appt'] = $stats['upcoming_appt'] ?? $a;
        }
        if (in_array($a['payment_status'], ['pending', 'partial'])) {
            $stats['pending_amount'] += (float)$a['total_amount'];
        }
    }

    $page_title = $fullName;
    $active     = 'customers';
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="customers.php?action=edit&id=<?php echo $id; ?>" class="btn btn-rose rounded-pill"><i class="bi bi-pencil me-1"></i>Edit Customer</a>
        <a href="appointments.php?action=create&customer_id=<?php echo $id; ?>" class="btn btn-charcoal rounded-pill"><i class="bi bi-calendar2-plus me-1"></i>Book Appointment</a>
        <a href="customers.php" class="btn btn-light rounded-pill"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body text-center">
                    <span class="avatar mb-3" style="width:72px;height:72px;font-size:1.5rem;"><?php echo e(initials($fullName)); ?></span>
                    <h4 class="fw-bold mb-1"><?php echo e($fullName); ?></h4>
                    <p class="text-muted mb-2"><?php echo e($customer['phone']); ?><?php echo $customer['email'] ? ' · ' . e($customer['email']) : ''; ?></p>
                    <span class="badge badge-status bg-gold text-dark"><?php echo (int)$customer['loyalty_points']; ?> Loyalty Points</span>
                </div>
                <div class="card-body border-top">
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted">Gender</dt><dd class="col-7"><?php echo $customer['gender'] ? e(ucfirst($customer['gender'])) : '—'; ?></dd>
                        <dt class="col-5 text-muted">Date of Birth</dt><dd class="col-7"><?php echo $customer['date_of_birth'] ? fmt_date($customer['date_of_birth']) : '—'; ?></dd>
                        <dt class="col-5 text-muted">Address</dt><dd class="col-7"><?php echo $customer['address'] ? e($customer['address']) : '—'; ?></dd>
                        <dt class="col-5 text-muted">Skin Type</dt><dd class="col-7"><?php echo $customer['skin_type'] ? e($customer['skin_type']) : '—'; ?></dd>
                        <dt class="col-5 text-muted">Hair Type</dt><dd class="col-7"><?php echo $customer['hair_type'] ? e($customer['hair_type']) : '—'; ?></dd>
                        <dt class="col-5 text-muted">Allergies</dt><dd class="col-7"><?php echo $customer['allergies'] ? e($customer['allergies']) : '—'; ?></dd>
                        <dt class="col-5 text-muted">Notes</dt><dd class="col-7"><?php echo $customer['notes'] ? e($customer['notes']) : '—'; ?></dd>
                        <dt class="col-5 text-muted">Member Since</dt><dd class="col-7"><?php echo fmt_date($customer['created_at']); ?></dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3"><div class="stat-card">
                    <span class="stat-icon gold"><i class="bi bi-cash-stack"></i></span>
                    <div><div class="stat-label">Total Spent</div><div class="stat-value" style="font-size:1.15rem;"><?php echo money($stats['total_spent']); ?></div></div>
                </div></div>
                <div class="col-6 col-lg-3"><div class="stat-card">
                    <span class="stat-icon rose"><i class="bi bi-calendar2-check"></i></span>
                    <div><div class="stat-label">Appointments</div><div class="stat-value"><?php echo $stats['appt_count']; ?></div></div>
                </div></div>
                <div class="col-6 col-lg-3"><div class="stat-card">
                    <span class="stat-icon green"><i class="bi bi-calendar-heart"></i></span>
                    <div><div class="stat-label">Last Visit</div><div class="stat-value" style="font-size:.95rem;"><?php echo $stats['last_appt'] ? fmt_date($stats['last_appt']['appointment_date']) : '—'; ?></div></div>
                </div></div>
                <div class="col-6 col-lg-3"><div class="stat-card">
                    <span class="stat-icon blue"><i class="bi bi-hourglass-split"></i></span>
                    <div><div class="stat-label">Upcoming</div><div class="stat-value" style="font-size:.95rem;"><?php echo $stats['upcoming_appt'] ? fmt_date($stats['upcoming_appt']['appointment_date']) : '—'; ?></div></div>
                </div></div>
            </div>

            <div class="card table-card mb-3">
                <div class="card-header">Appointment History</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Date</th><th>Time</th><th>Services</th><th>Total</th><th>Status</th><th>Payment</th></tr></thead>
                        <tbody>
                        <?php if (!$appts): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No appointments yet.</td></tr>
                        <?php else: foreach ($appts as $a): ?>
                            <tr>
                                <td><?php echo fmt_date($a['appointment_date']); ?></td>
                                <td><?php echo fmt_time($a['start_time']); ?></td>
                                <td class="small text-muted">
                                    <?php
                                    $stmt = db()->prepare('SELECT s.name FROM appointment_services aps JOIN services s ON s.id = aps.service_id WHERE aps.appointment_id = :id');
                                    $stmt->execute(['id' => $a['id']]);
                                    echo e(implode(', ', array_column($stmt->fetchAll(), 'name') ?: ['—']));
                                    ?>
                                </td>
                                <td class="fw-semibold"><?php echo money($a['total_amount']); ?></td>
                                <td><?php echo status_badge($a['status']); ?></td>
                                <td><?php echo status_badge($a['payment_status']); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card table-card">
                <div class="card-header">Loyalty History</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Date</th><th>Points</th><th>Reason</th></tr></thead>
                        <tbody>
                        <?php if (!$ledger): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">No loyalty activity yet.</td></tr>
                        <?php else: foreach ($ledger as $l): ?>
                            <tr>
                                <td><?php echo fmt_datetime($l['created_at']); ?></td>
                                <td class="<?php echo $l['points_change'] >= 0 ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                                    <?php echo ($l['points_change'] >= 0 ? '+' : '') . (int)$l['points_change']; ?>
                                </td>
                                <td><?php echo e($l['reason'] ?? '—'); ?></td>
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

redirect('customers.php');
