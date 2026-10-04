<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Staff Management module (Administrator only).
 *
 * Staff are salon employees/professionals whose information is stored
 * and managed by the Administrator. They are NOT system users: creating,
 * editing or deleting a staff record never creates or touches a login
 * account. System login roles remain admin + receptionist only.
 */

require_permission('staff');

$action = $_GET['action'] ?? 'list';

/* ------------------------------------------------------------
 * Save staff member (create / update)
 * ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_staff') {
    require_permission('staff');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('staff.php'); }

    $id          = (int)($_POST['id'] ?? 0);
    $firstName   = trim($_POST['first_name'] ?? '');
    $lastName    = trim($_POST['last_name'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $dob         = trim($_POST['date_of_birth'] ?? '');
    $gender      = $_POST['gender'] ?? '';
    $address     = trim($_POST['address'] ?? '');
    $emgName     = trim($_POST['emergency_contact_name'] ?? '');
    $emgPhone    = trim($_POST['emergency_contact_phone'] ?? '');
    $salaryRaw   = trim($_POST['salary'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');
    $status      = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    $errors = [];
    if ($firstName === '') { $errors[] = 'First name is required.'; }
    if ($lastName === '')  { $errors[] = 'Last name is required.'; }
    if ($phone === '')     { $errors[] = 'Phone number is required.'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is not valid.';
    }
    if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        $errors[] = 'Date of birth is not valid.';
    }
    if (!in_array($gender, ['', 'female', 'male', 'other'], true)) {
        $errors[] = 'Gender is not valid.';
    }
    if ($salaryRaw !== '' && !is_numeric($salaryRaw)) {
        $errors[] = 'Salary must be a number.';
    }

    if (!$errors && $email !== '') {
        $stmt = db()->prepare('SELECT id FROM staff WHERE email = :email AND id <> :id LIMIT 1');
        $stmt->execute(['email' => $email, 'id' => $id]);
        if ($stmt->fetch()) {
            $errors[] = 'Another staff member already uses that email address.';
        }
    }

    if ($errors) {
        foreach ($errors as $err) { flash_set('danger', $err); }
        redirect($id ? "staff.php?action=edit&id={$id}" : 'staff.php?action=add');
    }

    $fullName = "{$firstName} {$lastName}";
    $data = [
        'first_name'              => $firstName,
        'last_name'               => $lastName,
        'phone'                   => $phone,
        'email'                   => $email !== '' ? $email : null,
        'date_of_birth'           => $dob !== '' ? $dob : null,
        'gender'                  => $gender !== '' ? $gender : null,
        'address'                 => $address !== '' ? $address : null,
        'emergency_contact_name'  => $emgName !== '' ? $emgName : null,
        'emergency_contact_phone' => $emgPhone !== '' ? $emgPhone : null,
        'salary'                  => $salaryRaw !== '' ? (float)$salaryRaw : null,
        'notes'                   => $notes !== '' ? $notes : null,
        'status'                  => $status,
    ];

    $isEditSave = $id > 0;

    try {
        if ($isEditSave) {
            $data['id'] = $id;
            db()->prepare(
                'UPDATE staff SET
                    first_name = :first_name, last_name = :last_name, phone = :phone, email = :email,
                    date_of_birth = :date_of_birth, gender = :gender, address = :address,
                    emergency_contact_name = :emergency_contact_name,
                    emergency_contact_phone = :emergency_contact_phone,
                    salary = :salary, notes = :notes, status = :status
                 WHERE id = :id'
            )->execute($data);
            log_activity('update', 'staff', "Administrator updated staff member: {$fullName}.");
            flash_set('success', 'Staff member updated.');
        } else {
            db()->prepare(
                'INSERT INTO staff
                    (first_name, last_name, phone, email, date_of_birth, gender, address,
                     emergency_contact_name, emergency_contact_phone, salary, notes, status)
                 VALUES
                    (:first_name, :last_name, :phone, :email, :date_of_birth, :gender, :address,
                     :emergency_contact_name, :emergency_contact_phone, :salary, :notes, :status)'
            )->execute($data);
            $id = (int)db()->lastInsertId();
            log_activity('create', 'staff', "Administrator added staff member: {$fullName}.");
            flash_set('success', 'Staff member saved.');
        }
    } catch (Throwable $e) {
        flash_set('danger', 'Something went wrong while saving. Please try again.');
        redirect($isEditSave ? "staff.php?action=edit&id={$id}" : 'staff.php?action=add');
    }
    redirect("staff.php?action=view&id={$id}");
}

/* ------------------------------------------------------------
 * Save staff working hours
 * ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_staff_hours') {
    require_permission('staff');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('staff.php'); }

    $staffId = (int)($_POST['staff_id'] ?? 0);
    if ($staffId <= 0) {
        flash_set('danger', 'Invalid staff member.');
        redirect('staff.php');
    }

    $stmt = db()->prepare('SELECT id, first_name, last_name FROM staff WHERE id = :id');
    $stmt->execute(['id' => $staffId]);
    $staff = $stmt->fetch();
    if (!$staff) {
        flash_set('danger', 'Staff member not found.');
        redirect('staff.php');
    }

    $posted = $_POST['staff_hours'] ?? [];
    $week = [];

    foreach (scheduling_days() as $day => $label) {
        $row = $posted[$day] ?? [];
        $week[$day] = [
            'is_working' => !empty($row['is_working']),
            'start_time' => !empty($row['is_working']) ? ($row['start_time'] ?? '') : null,
            'end_time'   => !empty($row['is_working']) ? ($row['end_time'] ?? '') : null,
        ];
    }

    $result = staff_working_hours_save($staffId, $week);

    if (!$result['ok']) {
        foreach ($result['errors'] as $err) {
            flash_set('danger', $err);
        }
        redirect("staff.php?action=hours&id={$staffId}");
    }

    $breakErrors = [];
    foreach (scheduling_days() as $day => $label) {
        $breaks = $_POST['staff_hours_breaks'][$day] ?? [];
        if (!is_array($breaks)) { $breaks = []; }
        /* Always called, even when empty: an empty list is what removes the
           day's breaks, so skipping it would silently keep the old ones. */
        $breakResult = staff_breaks_save($staffId, (int)$day, $breaks);
        if (!$breakResult['ok']) {
            $breakErrors = array_merge($breakErrors, $breakResult['errors']);
        }
    }

    if ($breakErrors) {
        foreach ($breakErrors as $err) {
            flash_set('danger', $err);
        }
        redirect("staff.php?action=hours&id={$staffId}");
    }

    log_activity('update', 'staff', "Administrator updated working hours for staff member: {$staff['first_name']} {$staff['last_name']}.");
    flash_set('success', 'Working hours updated successfully.');
    redirect("staff.php?action=view&id={$staffId}");
}

/* ------------------------------------------------------------
 * Save the services a staff member performs (their allow-list)
 * ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_staff_services') {
    require_permission('staff');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('staff.php'); }

    $staffId = (int)($_POST['staff_id'] ?? 0);

    $stmt = db()->prepare('SELECT first_name, last_name FROM staff WHERE id = :id');
    $stmt->execute(['id' => $staffId]);
    $staff = $stmt->fetch();

    if ($staffId <= 0 || !$staff) {
        flash_set('danger', 'Staff member not found.');
        redirect('staff.php');
    }

    $posted = $_POST['service_ids'] ?? [];
    $ids    = is_array($posted) ? array_map('intval', $posted) : [];

    try {
        staff_services_save($staffId, $ids);
    } catch (Throwable $e) {
        flash_set('danger', 'Could not save the service list. Please try again.');
        redirect("staff.php?action=hours&id={$staffId}");
    }

    log_activity('update', 'staff', "Administrator updated the services performed by {$staff['first_name']} {$staff['last_name']}.");
    flash_set('success', $ids
        ? 'Service list updated.'
        : 'Service list cleared — this staff member can now perform every service.');
    redirect("staff.php?action=hours&id={$staffId}");
}

/* ------------------------------------------------------------
 * Toggle active / inactive
 * ------------------------------------------------------------ */
if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'toggle_staff') {
    require_permission('staff');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('staff.php'); }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT first_name, last_name, status FROM staff WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $s = $stmt->fetch();
    if ($s) {
        $newStatus = $s['status'] === 'active' ? 'inactive' : 'active';
        db()->prepare('UPDATE staff SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $id]);
        $name = "{$s['first_name']} {$s['last_name']}";
        log_activity('update', 'staff', "Administrator " . ($newStatus === 'active' ? 'activated' : 'deactivated') . " staff member: {$name}.");
        flash_set('success', ($newStatus === 'active' ? 'Activated' : 'Deactivated') . ' successfully.');
    }
    redirect('staff.php');
}

/* ------------------------------------------------------------
 * Delete staff member
 * NOTE: appointments.staff_id is ON DELETE SET NULL — history is
 * preserved, the appointments simply become unassigned.
 * ------------------------------------------------------------ */
if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'delete_staff') {
    require_permission('staff');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('staff.php'); }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    try {
        $stmt = db()->prepare('SELECT first_name, last_name FROM staff WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $s = $stmt->fetch();

        $stmt = db()->prepare('DELETE FROM staff WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($s) {
            log_activity('delete', 'staff', "Administrator deleted staff member: {$s['first_name']} {$s['last_name']}.");
        }
        flash_set('success', 'Staff member deleted.');
    } catch (Throwable $e) {
        flash_set('danger', 'Could not delete this staff member. Please try again.');
    }
    redirect('staff.php');
}

$activePage = 'staff';

/* ============================================================
 * STAFF WORKING HOURS
 * ============================================================ */
if ($action === 'hours') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $staff = $stmt->fetch();
    if (!$staff) {
        flash_set('warning', 'Staff member not found.');
        redirect('staff.php');
    }

    $fullName = trim($staff['first_name'] . ' ' . $staff['last_name']);

    $page_title = 'Working Hours & Services — ' . $fullName;
    $active     = $activePage;
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="staff.php?action=view&id=<?php echo $id; ?>" class="btn btn-light rounded-pill"><i class="bi bi-arrow-left me-1"></i>Back to Profile</a>
    </div>

    <div class="card" style="max-width:900px;">
        <div class="card-header">
            <i class="bi bi-clock me-2 text-muted"></i>Weekly Working Hours — <?php echo e($fullName); ?>
        </div>
        <div class="card-body">
            <div class="alert alert-light border small text-muted mb-3">
                <i class="bi bi-info-circle me-1"></i>
                Staff working hours must sit inside the salon's business hours for each day.
                If the salon is closed on a day, staff cannot be scheduled to work that day.
            </div>

            <form method="post" action="staff.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="save_staff_hours">
                <input type="hidden" name="staff_id" value="<?php echo $id; ?>">

                <div class="hours-grid-host">
                    <?php render_staff_hours_grid($id, 'staff_hours'); ?>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-rose rounded-pill px-4"><i class="bi bi-check2 me-1"></i>Save Working Hours</button>
                    <a href="staff.php?action=view&id=<?php echo $id; ?>" class="btn btn-light rounded-pill px-4">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <?php
    /* ---- Services this staff member performs ------------------- */
    $services = db()->query(
        "SELECT id, name, duration_minutes FROM services WHERE status = 'active' ORDER BY name"
    )->fetchAll();
    $allowedServices = staff_service_ids($id);
    ?>

    <div class="card mt-3" style="max-width:900px;">
        <div class="card-header">
            <i class="bi bi-check2-square me-2 text-muted"></i>Services Performed — <?php echo e($fullName); ?>
        </div>
        <div class="card-body">
            <div class="alert alert-light border small text-muted mb-3">
                <i class="bi bi-info-circle me-1"></i>
                Tick the services this staff member performs. Leaving <strong>every box unticked</strong>
                means they are unrestricted and can perform any service. Once a service is ticked, the
                list becomes the only set of services they can be booked for.
            </div>

            <?php if (!$services): ?>
                <p class="text-muted small mb-0">No active services yet. Add services first, then return here.</p>
            <?php else: ?>
                <form method="post" action="staff.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_staff_services">
                    <input type="hidden" name="staff_id" value="<?php echo $id; ?>">

                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-sm btn-light rounded-pill" id="svcAll">Select all</button>
                        <button type="button" class="btn btn-sm btn-light rounded-pill" id="svcNone">Clear all</button>
                    </div>

                    <div class="row g-2">
                        <?php foreach ($services as $svc): ?>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input svc-box" type="checkbox" name="service_ids[]"
                                           id="svc_<?php echo (int)$svc['id']; ?>" value="<?php echo (int)$svc['id']; ?>"
                                           <?php echo in_array((int)$svc['id'], $allowedServices, true) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="svc_<?php echo (int)$svc['id']; ?>">
                                        <?php echo e($svc['name']); ?>
                                        <span class="text-muted small">(<?php echo (int)$svc['duration_minutes']; ?> min)</span>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-rose rounded-pill px-4"><i class="bi bi-check2 me-1"></i>Save Service List</button>
                    </div>
                </form>

                <script>
                    (function () {
                        'use strict';
                        var boxes = document.querySelectorAll('.svc-box');
                        document.getElementById('svcAll').addEventListener('click', function () {
                            boxes.forEach(function (b) { b.checked = true; });
                        });
                        document.getElementById('svcNone').addEventListener('click', function () {
                            boxes.forEach(function (b) { b.checked = false; });
                        });
                    })();
                </script>
            <?php endif; ?>
        </div>
    </div>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* ============================================================
 * LIST
 * ============================================================ */
if ($action === 'list') {
    $q        = trim($_GET['q'] ?? '');
    $statusF  = $_GET['status'] ?? '';
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = 15;

    $where  = [];
    $params = [];
    if ($q !== '') {
        /* Native prepares (emulation off) forbid reusing a named
           placeholder — each occurrence gets its own name. */
        $like    = "%{$q}%";
        $where[] = '(first_name LIKE :q1 OR last_name LIKE :q2 OR phone LIKE :q3 OR COALESCE(email,"") LIKE :q4)';
        $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
    }
    if ($statusF === 'active' || $statusF === 'inactive') {
        $where[] = 'status = :status';
        $params['status'] = $statusF;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total = db()->prepare("SELECT COUNT(*) FROM staff {$whereSql}");
    $total->execute($params);
    $total = (int)$total->fetchColumn();
    $pg    = paginate($total, $perPage, $page);

    $stmt = db()->prepare(
        "SELECT s.*,
                (SELECT COUNT(*) FROM appointments a WHERE a.staff_id = s.id) AS appt_count,
                (SELECT COUNT(*) FROM appointments a WHERE a.staff_id = s.id AND a.status IN ('pending','confirmed','in_progress')) AS upcoming_count
         FROM staff s {$whereSql}
         ORDER BY s.status ASC, s.last_name ASC, s.first_name ASC
         LIMIT {$perPage} OFFSET {$pg['offset']}"
    );
    $stmt->execute($params);
    $staffList = $stmt->fetchAll();

    $page_title = 'Staff';
    $active     = $activePage;
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="card table-card">
        <div class="card-body p-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <form method="get" action="staff.php" class="d-flex flex-wrap gap-2 flex-grow-1" style="max-width:560px;">
                <input type="hidden" name="action" value="list">
                <div class="input-group" style="max-width:340px;">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0" name="q" value="<?php echo e($q); ?>"
                           placeholder="Search by name, phone or email" data-table-filter="#staffTable" data-href-filter>
                </div>
                <select class="form-select" style="max-width:150px;" name="status" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <option value="active" <?php echo $statusF === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $statusF === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
                <button class="btn btn-charcoal" type="submit">Search</button>
            </form>
            <a href="staff.php?action=add" class="btn btn-rose rounded-pill"><i class="bi bi-person-plus me-1"></i>Add Staff</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="staffTable">
                <thead>
                    <tr><th>Name</th><th>Phone</th><th>Email</th><th>Salary</th><th>Appointments</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php if (!$staffList): ?>
                    <tr><td colspan="7">
                        <div class="empty-state">
                            <div class="icon"><i class="bi bi-person-badge"></i></div>
                            <p class="text-muted mb-2">No staff members found.</p>
                            <a href="staff.php?action=add" class="btn btn-sm btn-rose rounded-pill">Add your first staff member</a>
                        </div>
                    </td></tr>
                <?php else: foreach ($staffList as $st): ?>
                    <?php $fullName = $st['first_name'] . ' ' . $st['last_name']; ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar"><?php echo e(initials($fullName)); ?></span>
                                <div>
                                    <a href="staff.php?action=view&id=<?php echo (int)$st['id']; ?>" class="fw-semibold text-body text-decoration-none">
                                        <?php echo e($fullName); ?>
                                    </a>
                                    <?php if ($st['upcoming_count'] > 0): ?>
                                        <span class="badge rounded-pill bg-rose-soft text-rose ms-1"><?php echo (int)$st['upcoming_count']; ?> upcoming</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?php echo e($st['phone']); ?></td>
                        <td class="text-muted"><?php echo $st['email'] ? e($st['email']) : '<span class="badge bg-light text-muted">No email</span>'; ?></td>
                        <td><?php echo $st['salary'] !== null ? fmt_money($st['salary']) : '—'; ?></td>
                        <td><?php echo (int)$st['appt_count']; ?></td>
                        <td><?php echo status_badge($st['status']); ?></td>
                        <td class="text-end">
                            <a href="staff.php?action=view&id=<?php echo (int)$st['id']; ?>" class="btn btn-sm btn-light rounded-pill" title="View"><i class="bi bi-eye"></i></a>
                            <a href="staff.php?action=edit&id=<?php echo (int)$st['id']; ?>" class="btn btn-sm btn-light rounded-pill" title="Edit"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-light rounded-pill <?php echo $st['status'] === 'active' ? 'text-warning' : 'text-success'; ?>"
                                    data-confirm="<?php echo $st['status'] === 'active' ? 'Deactivate this staff member?' : 'Activate this staff member?'; ?>"
                                    data-confirm-action="staff.php?form_action=toggle_staff&id=<?php echo (int)$st['id']; ?>"
                                    data-confirm-label="<?php echo $st['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                <i class="bi <?php echo $st['status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle'; ?>"></i>
                            </button>
<button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                    data-confirm="Delete this staff member permanently? Their past appointments will be kept but become unassigned, and payroll history is preserved with the name snapshot."
                                    data-confirm-action="staff.php?form_action=delete_staff&id=<?php echo (int)$st['id']; ?>"
                                    data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pg['totalPages'] > 1): ?>
            <div class="card-body border-top d-flex justify-content-between align-items-center">
                <span class="small text-muted"><?php echo $pg['total']; ?> staff member(s)</span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $pg['hasPrev'] ? '' : 'disabled'; ?>">
                            <a class="page-link" href="staff.php?<?php echo e(query_string(['page' => $pg['prevPage']])); ?>">Prev</a>
                        </li>
                        <li class="page-item disabled"><span class="page-link">Page <?php echo $pg['page']; ?> of <?php echo $pg['totalPages']; ?></span></li>
                        <li class="page-item <?php echo $pg['hasNext'] ? '' : 'disabled'; ?>">
                            <a class="page-link" href="staff.php?<?php echo e(query_string(['page' => $pg['nextPage']])); ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>

    <div class="alert alert-light border mt-3 small text-muted mb-0">
        <i class="bi bi-info-circle me-1"></i>
        Staff members are salon employees managed here by the Administrator. They are <strong>not system users</strong> — staff records never receive login accounts.
    </div>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* ============================================================
 * ADD / EDIT
 * ============================================================ */
if ($action === 'add' || $action === 'edit') {
    $staff = [
        'id' => 0, 'first_name' => '', 'last_name' => '', 'phone' => '', 'email' => '',
        'date_of_birth' => '', 'gender' => '', 'address' => '',
        'emergency_contact_name' => '', 'emergency_contact_phone' => '',
        'salary' => '', 'notes' => '', 'status' => 'active',
    ];
    $isEdit = $action === 'edit';

    if ($isEdit) {
        $stmt = db()->prepare('SELECT * FROM staff WHERE id = :id');
        $stmt->execute(['id' => (int)($_GET['id'] ?? 0)]);
        $found = $stmt->fetch();
        if (!$found) {
            flash_set('warning', 'Staff member not found.');
            redirect('staff.php');
        }
        $staff = $found;
    }

    $page_title = $isEdit ? 'Edit Staff Member' : 'Add Staff Member';
    $active     = $activePage;
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="card" style="max-width:900px;">
        <div class="card-header">
            <?php echo $isEdit ? 'Edit Staff — ' . e($staff['first_name'] . ' ' . $staff['last_name']) : 'New Staff Member'; ?>
        </div>
        <div class="card-body">
            <form method="post" action="staff.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="save_staff">
                <input type="hidden" name="id" value="<?php echo (int)$staff['id']; ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="first_name">First Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="first_name" name="first_name" required maxlength="100" value="<?php echo e($staff['first_name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="last_name">Last Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="last_name" name="last_name" required maxlength="100" value="<?php echo e($staff['last_name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="phone">Phone Number <span class="required">*</span></label>
                        <input type="tel" class="form-control" id="phone" name="phone" required maxlength="20" value="<?php echo e($staff['phone']); ?>" placeholder="e.g. 0911 234 567">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Email Address <span class="text-muted small">(optional)</span></label>
                        <input type="email" class="form-control" id="email" name="email" maxlength="255" value="<?php echo e($staff['email']); ?>" placeholder="leave blank if not available">
                        <div class="form-text">Used for contact only — it never creates a login account.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="date_of_birth">Date of Birth</label>
                        <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" value="<?php echo e($staff['date_of_birth']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="gender">Gender</label>
                        <select class="form-select" id="gender" name="gender">
                            <option value="">— Not set —</option>
                            <option value="female" <?php echo $staff['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                            <option value="male" <?php echo $staff['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="other" <?php echo $staff['gender'] === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="address">Address</label>
                        <input type="text" class="form-control" id="address" name="address" maxlength="255" value="<?php echo e($staff['address']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="emergency_contact_name">Emergency Contact Name</label>
                        <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name" maxlength="150" value="<?php echo e($staff['emergency_contact_name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="emergency_contact_phone">Emergency Contact Phone</label>
                        <input type="tel" class="form-control" id="emergency_contact_phone" name="emergency_contact_phone" maxlength="20" value="<?php echo e($staff['emergency_contact_phone']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="salary">Salary <span class="text-muted small">(<?php echo e(currency()); ?>, optional)</span></label>
                        <input type="number" step="0.01" min="0" class="form-control" id="salary" name="salary" value="<?php echo e($staff['salary']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active" <?php echo $staff['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $staff['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Specialties, certifications, availability…"><?php echo e($staff['notes']); ?></textarea>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-rose rounded-pill px-4"><i class="bi bi-check2 me-1"></i><?php echo $isEdit ? 'Save Changes' : 'Save Staff Member'; ?></button>
                    <a href="<?php echo $isEdit ? 'staff.php?action=view&id=' . (int)$staff['id'] : 'staff.php'; ?>" class="btn btn-light rounded-pill px-4">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* ============================================================
 * VIEW PROFILE — professional card-based staff profile.
 * ============================================================ */
if ($action === 'view') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $staff = $stmt->fetch();
    if (!$staff) {
        flash_set('warning', 'Staff member not found.');
        redirect('staff.php');
    }

    $fullName     = trim($staff['first_name'] . ' ' . $staff['last_name']);
    $emgAvailable = $staff['emergency_contact_name'] !== null || $staff['emergency_contact_phone'] !== null;

    /* Recent appointment / service assignments */
    $appts = db()->prepare(
        'SELECT a.*, c.first_name AS c_first, c.last_name AS c_last
         FROM appointments a
         JOIN customers c ON c.id = a.customer_id
         WHERE a.staff_id = :id
         ORDER BY a.appointment_date DESC, a.start_time DESC
         LIMIT 10'
    );
    $appts->execute(['id' => $id]);
    $appts = $appts->fetchAll();

/* All-time assignment statistics */
    $totals = db()->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN status IN ("pending","confirmed","in_progress") THEN 1 ELSE 0 END) AS upcoming,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) AS completed,
                COALESCE(SUM(CASE WHEN status = "completed" THEN total_amount ELSE 0 END), 0) AS revenue
         FROM appointments WHERE staff_id = :id'
    );
    $totals->execute(['id' => $id]);
    $totals = $totals->fetch();

    /* Recent payroll history (linked via payroll.staff_id) */
    $payrollHistory = db()->prepare(
        'SELECT * FROM payroll WHERE staff_id = :id ORDER BY pay_period DESC, id DESC LIMIT 12'
    );
    $payrollHistory->execute(['id' => $id]);
    $payrollHistory = $payrollHistory->fetchAll();
    $payrollTotals = db()->prepare(
        'SELECT COUNT(*) AS entries,
                COALESCE(SUM(net_salary),0) AS total_paid,
                SUM(CASE WHEN payment_status = "paid" THEN 1 ELSE 0 END) AS paid_entries
         FROM payroll WHERE staff_id = :id'
    );
    $payrollTotals->execute(['id' => $id]);
    $payrollTotals = $payrollTotals->fetch();
    $stats = [
        'total'     => (int)($totals['total'] ?? 0),
        'upcoming'  => (int)($totals['upcoming'] ?? 0),
        'completed' => (int)($totals['completed'] ?? 0),
        'revenue'   => (float)($totals['revenue'] ?? 0),
    ];

    $page_title = 'Staff Profile — ' . $fullName;
    $active     = $activePage;
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="staff.php?action=edit&id=<?php echo $id; ?>" class="btn btn-rose rounded-pill"><i class="bi bi-pencil me-1"></i>Edit Profile</a>
        <a href="staff.php?action=hours&id=<?php echo $id; ?>" class="btn btn-charcoal rounded-pill"><i class="bi bi-clock me-1"></i>Working Hours</a>
        <a href="staff.php" class="btn btn-light rounded-pill"><i class="bi bi-arrow-left me-1"></i>Back to Staff</a>
    </div>

    <!-- Profile header -->
    <div class="card mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-column flex-md-row align-items-md-center gap-3">
                <span class="avatar mx-auto mx-md-0" style="width:84px;height:84px;font-size:1.75rem;"><?php echo e(initials($fullName)); ?></span>
                <div class="flex-grow-1 text-center text-md-start">
                    <div class="d-flex align-items-center justify-content-center justify-content-md-start gap-2 flex-wrap">
                        <h3 class="fw-bold mb-0"><?php echo e($fullName); ?></h3>
                        <?php echo status_badge($staff['status']); ?>
                    </div>
                    <div class="text-muted mt-1 d-flex flex-wrap gap-3 justify-content-center justify-content-md-start">
                        <span><i class="bi bi-telephone me-1"></i><?php echo e($staff['phone']); ?></span>
                        <?php if ($staff['email']): ?>
                            <span><i class="bi bi-envelope me-1"></i><?php echo e($staff['email']); ?></span>
                        <?php endif; ?>
                        <span><i class="bi bi-calendar-check me-1"></i>Joined <?php echo fmt_date($staff['created_at']); ?></span>
                    </div>
                </div>
                <div class="d-flex gap-2 justify-content-center">
                    <a href="tel:<?php echo e(preg_replace('/[\s\-]/', '', $staff['phone'])); ?>" class="btn btn-sm btn-light rounded-pill px-3" title="Call staff member">
                        <i class="bi bi-telephone-fill me-1"></i>Call
                    </a>
                    <button type="button" class="btn btn-sm btn-light rounded-pill px-3 <?php echo $staff['status'] === 'active' ? 'text-warning' : 'text-success'; ?>"
                            data-confirm="<?php echo $staff['status'] === 'active' ? 'Deactivate this staff member?' : 'Activate this staff member?'; ?>"
                            data-confirm-action="staff.php?form_action=toggle_staff&id=<?php echo $id; ?>"
                            data-confirm-label="<?php echo $staff['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                        <i class="bi <?php echo $staff['status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle'; ?> me-1"></i>
                        <?php echo $staff['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Assignment statistics -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="stat-card h-100">
            <span class="stat-icon blue"><i class="bi bi-calendar2-check"></i></span>
            <div><div class="stat-label">Total Appointments</div><div class="stat-value"><?php echo $stats['total']; ?></div></div>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card h-100">
            <span class="stat-icon rose"><i class="bi bi-hourglass-split"></i></span>
            <div><div class="stat-label">Upcoming</div><div class="stat-value"><?php echo $stats['upcoming']; ?></div></div>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card h-100">
            <span class="stat-icon green"><i class="bi bi-check2-circle"></i></span>
            <div><div class="stat-label">Completed</div><div class="stat-value"><?php echo $stats['completed']; ?></div></div>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card h-100">
            <span class="stat-icon gold"><i class="bi bi-cash-stack"></i></span>
            <div><div class="stat-label">Revenue Generated</div><div class="stat-value" style="font-size:.95rem;"><?php echo money($stats['revenue']); ?> <?php echo e(currency()); ?></div></div>
        </div></div>
    </div>

    <div class="row g-3 mb-4">
        <!-- Personal Information -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-person-vcard me-2 text-muted"></i>Personal Information</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5 fw-normal text-muted">First Name</dt>
                        <dd class="col-sm-7"><?php echo e($staff['first_name']); ?></dd>
                        <dt class="col-sm-5 fw-normal text-muted">Last Name</dt>
                        <dd class="col-sm-7"><?php echo e($staff['last_name']); ?></dd>
                        <dt class="col-sm-5 fw-normal text-muted">Full Name</dt>
                        <dd class="col-sm-7 fw-semibold"><?php echo e($fullName); ?></dd>
                        <dt class="col-sm-5 fw-normal text-muted">Date of Birth</dt>
                        <dd class="col-sm-7"><?php echo $staff['date_of_birth'] ? fmt_date($staff['date_of_birth']) : '—'; ?></dd>
                        <dt class="col-sm-5 fw-normal text-muted">Gender</dt>
                        <dd class="col-sm-7"><?php echo $staff['gender'] ? e(ucfirst($staff['gender'])) : '—'; ?></dd>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-chat-left-text me-2 text-muted"></i>Contact Information</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5 fw-normal text-muted">Phone Number</dt>
                        <dd class="col-sm-7">
                            <a href="tel:<?php echo e(preg_replace('/[\s\-]/', '', $staff['phone'])); ?>" class="text-decoration-none"><?php echo e($staff['phone']); ?></a>
                        </dd>
                        <dt class="col-sm-5 fw-normal text-muted">Email Address</dt>
                        <dd class="col-sm-7">
                            <?php if ($staff['email']): ?>
                                <a href="mailto:<?php echo e($staff['email']); ?>" class="text-decoration-none"><?php echo e($staff['email']); ?></a>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </dd>
                        <dt class="col-sm-5 fw-normal text-muted">Address</dt>
                        <dd class="col-sm-7"><?php echo $staff['address'] ? e($staff['address']) : '<span class="text-muted">—</span>'; ?></dd>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Emergency Contact -->
        <div class="col-lg-6">
            <div class="card h-100 <?php echo $emgAvailable ? '' : 'opacity-75'; ?>">
                <div class="card-header"><i class="bi bi-heart-pulse me-2 text-danger"></i>Emergency Contact</div>
                <div class="card-body">
                    <?php if (!$emgAvailable): ?>
                        <p class="text-muted mb-0"><i class="bi bi-info-circle me-1"></i>No emergency contact on file.</p>
                    <?php else: ?>
                        <dl class="row mb-0">
                            <dt class="col-sm-5 fw-normal text-muted">Contact Name</dt>
                            <dd class="col-sm-7"><?php echo $staff['emergency_contact_name'] ? e($staff['emergency_contact_name']) : '—'; ?></dd>
                            <dt class="col-sm-5 fw-normal text-muted">Contact Phone</dt>
                            <dd class="col-sm-7">
                                <?php if ($staff['emergency_contact_phone']): ?>
                                    <a href="tel:<?php echo e(preg_replace('/[\s\-]/', '', $staff['emergency_contact_phone'])); ?>" class="text-decoration-none"><?php echo e($staff['emergency_contact_phone']); ?></a>
                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            </dd>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Employment Information -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-briefcase me-2 text-muted"></i>Employment Information</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5 fw-normal text-muted">Salary</dt>
                        <dd class="col-sm-7 fw-semibold"><?php echo $staff['salary'] !== null ? fmt_money($staff['salary']) : '<span class="text-muted fw-normal">Not specified</span>'; ?></dd>
                        <dt class="col-sm-5 fw-normal text-muted">Employment Status</dt>
                        <dd class="col-sm-7"><?php echo status_badge($staff['status']); ?></dd>
                        <dt class="col-sm-5 fw-normal text-muted">Joined On</dt>
                        <dd class="col-sm-7"><?php echo fmt_date($staff['created_at']); ?></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <!-- Notes -->
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-journal-text me-2 text-muted"></i>Notes</div>
        <div class="card-body">
            <?php if ($staff['notes']): ?>
                <p class="mb-0" style="white-space:pre-line;"><?php echo e($staff['notes']); ?></p>
            <?php else: ?>
                <p class="text-muted mb-0"><i class="bi bi-info-circle me-1"></i>No notes recorded for this staff member.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Appointment / service assignments -->
    <div class="card table-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-calendar2-week me-2 text-muted"></i>Appointment &amp; Service Assignments</span>
            <span class="badge bg-light text-dark border"><?php echo $stats['total']; ?> total</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Date</th><th>Time</th><th>Customer</th><th>Services</th><th>Total</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (!$appts): ?>
                    <tr><td colspan="6">
                        <div class="empty-state py-4">
                            <div class="icon"><i class="bi bi-calendar-x"></i></div>
                            <p class="text-muted mb-0">No appointments assigned yet.</p>
                        </div>
                    </td></tr>
                <?php else: foreach ($appts as $a): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo fmt_date($a['appointment_date']); ?></td>
                        <td><?php echo fmt_time($a['start_time']); ?></td>
                        <td><?php echo e($a['c_first'] . ' ' . $a['c_last']); ?></td>
                        <td class="small text-muted">
                            <?php
                            $svc = db()->prepare('SELECT s.name FROM appointment_services aps JOIN services s ON s.id = aps.service_id WHERE aps.appointment_id = :id');
                            $svc->execute(['id' => $a['id']]);
                            echo e(implode(', ', array_column($svc->fetchAll(), 'name') ?: ['—']));
                            ?>
                        </td>
                        <td class="fw-semibold"><?php echo money($a['total_amount']); ?> <?php echo e(currency()); ?></td>
                        <td><?php echo status_badge($a['status']); ?></td>
                    </tr>
<?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Payroll history (linked through payroll.staff_id) -->
    <div class="card table-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-cash-stack me-2 text-muted"></i>Payroll History</span>
            <span class="badge bg-light text-dark border"><?php echo (int)$payrollTotals['entries']; ?> record(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Period</th><th>Salary</th><th>Commission</th><th>Tips</th><th>Bonus</th><th>Deductions</th><th>Net</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (!$payrollHistory): ?>
                    <tr><td colspan="8">
                        <div class="empty-state py-4">
                            <div class="icon"><i class="bi bi-cash-coin"></i></div>
                            <p class="text-muted mb-0">No payroll records for this staff member yet.</p>
                        </div>
                    </td></tr>
                <?php else: foreach ($payrollHistory as $ph): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo e($ph['pay_period']); ?></td>
                        <td><?php echo money($ph['salary']); ?></td>
                        <td><?php echo money($ph['commission']); ?></td>
                        <td><?php echo money($ph['tips']); ?></td>
                        <td><?php echo money($ph['bonus']); ?></td>
                        <td class="text-danger"><?php echo money($ph['deductions']); ?></td>
                        <td class="fw-semibold"><?php echo money($ph['net_salary']); ?></td>
                        <td><?php echo status_badge($ph['payment_status']); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ((int)$payrollTotals['entries'] > 0): ?>
            <div class="card-body border-top small text-muted">
                <?php echo (int)$payrollTotals['entries']; ?> pay run(s) on file —
                <strong><?php echo (int)$payrollTotals['paid_entries']; ?> paid</strong>,
                total paid out <strong><?php echo money((float)$payrollTotals['total_paid']); ?> <?php echo e(currency()); ?></strong>.
            </div>
        <?php endif; ?>
    </div>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

redirect('staff.php');
