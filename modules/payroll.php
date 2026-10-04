<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Payroll module (Administrator only).
 * Each payroll record is linked to a real staff member through
 * payroll.staff_id → staff.id. The staff_name column keeps a snapshot
 * of the name at pay time, so history survives staff renames/deletion.
 * Business rules are unchanged: net = salary + commission + tips + bonus − deductions,
 * one record per staff member per pay period, paid/pending workflow.
 */

require_permission('payroll');

$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_payroll') {
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('payroll.php'); }
    $id         = (int)($_POST['id'] ?? 0);
    $staffId    = (int)($_POST['staff_id'] ?? 0);
    $period     = trim($_POST['pay_period'] ?? '');
    $salary     = max(0, (float)($_POST['salary'] ?? 0));
    $commission = max(0, (float)($_POST['commission'] ?? 0));
    $tips       = max(0, (float)($_POST['tips'] ?? 0));
    $bonus      = max(0, (float)($_POST['bonus'] ?? 0));
    $deductions = max(0, (float)($_POST['deductions'] ?? 0));

    if ($staffId < 1 || !preg_match('/^\d{4}-\d{2}$/', $period)) {
        flash_set('danger', 'Choose a staff member and enter a valid pay period (YYYY-MM).');
        redirect($id ? "payroll.php?action=edit&id={$id}" : 'payroll.php?action=add');
    }

    $stmt = db()->prepare('SELECT first_name, last_name FROM staff WHERE id = :id');
    $stmt->execute(['id' => $staffId]);
    $staffRow = $stmt->fetch();
    if (!$staffRow) {
        flash_set('danger', 'The selected staff member no longer exists.');
        redirect($id ? "payroll.php?action=edit&id={$id}" : 'payroll.php?action=add');
    }
    $staffName = trim($staffRow['first_name'] . ' ' . $staffRow['last_name']);

    $net = round($salary + $commission + $tips + $bonus - $deductions, 2);

    if ($id) {
        db()->prepare(
            'UPDATE payroll SET staff_id=:staff_id, staff_name=:name, salary=:salary, commission=:commission,
             tips=:tips, bonus=:bonus, deductions=:deductions, net_salary=:net, pay_period=:period WHERE id=:id'
        )->execute(['staff_id' => $staffId, 'name' => $staffName, 'salary' => $salary, 'commission' => $commission, 'tips' => $tips, 'bonus' => $bonus, 'deductions' => $deductions, 'net' => $net, 'period' => $period, 'id' => $id]);
        log_activity('update', 'payroll', "Updated payroll record for {$staffName} ({$period})");
        flash_set('success', 'Payroll record updated.');
    } else {
        db()->prepare(
            'INSERT INTO payroll (staff_id, staff_name, salary, commission, tips, bonus, deductions, net_salary, pay_period)
             VALUES (:staff_id,:name,:salary,:commission,:tips,:bonus,:deductions,:net,:period)'
        )->execute(['staff_id' => $staffId, 'name' => $staffName, 'salary' => $salary, 'commission' => $commission, 'tips' => $tips, 'bonus' => $bonus, 'deductions' => $deductions, 'net' => $net, 'period' => $period]);
        log_activity('create', 'payroll', "Created payroll record for {$staffName} ({$period})");
        flash_set('success', 'Payroll record created.');
    }
    redirect('payroll.php');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'mark_paid') {
    require_permission('payroll');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('payroll.php'); }
    db()->prepare("UPDATE payroll SET payment_status = 'paid' WHERE id = :id")->execute(['id' => (int)($_POST['id'] ?? $_GET['id'] ?? 0)]);
    flash_set('success', 'Marked as paid.');
    redirect('payroll.php');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'delete_payroll') {
    require_permission('payroll');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('payroll.php'); }
    db()->prepare('DELETE FROM payroll WHERE id = :id')->execute(['id' => (int)($_POST['id'] ?? $_GET['id'] ?? 0)]);
    flash_set('success', 'Payroll record deleted.');
    redirect('payroll.php');
}

$periodFilter = $_GET['period'] ?? '';
$records = db()->query(
    'SELECT p.*, s.status AS staff_status
     FROM payroll p
     LEFT JOIN staff s ON s.id = p.staff_id
     ORDER BY p.pay_period DESC, p.id DESC'
)->fetchAll();
if (preg_match('/^\d{4}-\d{2}$/', $periodFilter)) {
    $records = array_filter($records, fn($r) => $r['pay_period'] === $periodFilter);
}

$periods = db()->query('SELECT DISTINCT pay_period FROM payroll ORDER BY pay_period DESC')->fetchAll(PDO::FETCH_COLUMN);

$staffOptions = db()->query(
    'SELECT id, first_name, last_name, salary, status FROM staff ORDER BY status ASC, last_name ASC, first_name ASC'
)->fetchAll();

$page_title = 'Payroll';
$active     = 'payroll';
include __DIR__ . '/../includes/header.php';

if ($action === 'add' || $action === 'edit'):
    $record = ['id' => 0, 'staff_id' => 0, 'staff_name' => '', 'salary' => 0, 'commission' => 0, 'tips' => 0, 'bonus' => 0, 'deductions' => 0, 'pay_period' => date('Y-m')];
    if ($action === 'edit') {
        $stmt = db()->prepare('SELECT * FROM payroll WHERE id = :id');
        $stmt->execute(['id' => (int)($_GET['id'] ?? 0)]);
        $found = $stmt->fetch();
        if (!$found) { flash_set('warning', 'Record not found.'); redirect('payroll.php'); }
        $record = $found;
    }
    ?>
    <div class="card" style="max-width:720px;">
        <div class="card-header"><?php echo $action === 'add' ? 'New Payroll Entry' : 'Edit Payroll Entry'; ?></div>
        <div class="card-body">
            <form method="post" action="payroll.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="save_payroll">
                <input type="hidden" name="id" value="<?php echo (int)$record['id']; ?>">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label" for="staff_id">Staff Member <span class="required">*</span></label>
                        <select class="form-select" id="staff_id" name="staff_id" required>
                            <option value="">— Choose staff member —</option>
                            <?php if ($action === 'edit' && (int)$record['staff_id'] < 1): ?>
                                <option value="" disabled selected><?php echo e($record['staff_name']); ?> (deleted — pick a current member)</option>
                            <?php endif; ?>
                            <?php foreach ($staffOptions as $sOpt): ?>
                                <?php $sName = $sOpt['first_name'] . ' ' . $sOpt['last_name']; ?>
                                <option value="<?php echo (int)$sOpt['id']; ?>"
                                        data-salary="<?php echo $sOpt['salary'] !== null ? (float)$sOpt['salary'] : 0; ?>"
                                        <?php echo (int)$record['staff_id'] === (int)$sOpt['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($sName); ?><?php echo $sOpt['status'] === 'inactive' ? ' (inactive)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Base salary and name come from the staff file; amounts below are editable for this pay period.</div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="pay_period">Pay Period <span class="required">*</span></label>
                        <input type="month" class="form-control" id="pay_period" name="pay_period" required value="<?php echo e($record['pay_period']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="salary">Base Salary</label>
                        <input type="number" class="form-control" id="salary" name="salary" min="0" step="0.01" value="<?php echo e((float)$record['salary']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="commission">Commission</label>
                        <input type="number" class="form-control" id="commission" name="commission" min="0" step="0.01" value="<?php echo e($record['commission']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="tips">Tips</label>
                        <input type="number" class="form-control" id="tips" name="tips" min="0" step="0.01" value="<?php echo e($record['tips']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="bonus">Bonus</label>
                        <input type="number" class="form-control" id="bonus" name="bonus" min="0" step="0.01" value="<?php echo e($record['bonus']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="deductions">Deductions</label>
                        <input type="number" class="form-control" id="deductions" name="deductions" min="0" step="0.01" value="<?php echo e($record['deductions']); ?>">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="alert alert-rose rounded-3 w-100 mb-0 py-2">Net: <strong><span id="netPreview"><?php echo money((float)$record['salary'] + (float)$record['commission'] + (float)$record['tips'] + (float)$record['bonus'] - (float)$record['deductions']); ?></span></strong></div>
                    </div>
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i>Save Record</button>
                    <a href="payroll.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var inputs = ['salary', 'commission', 'tips', 'bonus', 'deductions'].map(function (id) { return document.getElementById(id); });
        var preview = document.getElementById('netPreview');
        var staffSelect = document.getElementById('staff_id');
        var salaryInput = document.getElementById('salary');
        function recomputeNet() {
            var net = inputs.reduce(function (sum, i) { return sum + (parseFloat(i.value) || 0); }, 0);
            net -= parseFloat(document.getElementById('deductions').value) || 0;
            preview.textContent = net.toFixed(2);
        }
        inputs.forEach(function (el) { el.addEventListener('input', recomputeNet); });
        if (staffSelect) {
            staffSelect.addEventListener('change', function () {
                var opt = staffSelect.options[staffSelect.selectedIndex];
                if (opt && opt.dataset.salary !== undefined && opt.dataset.salary !== '') {
                    salaryInput.value = opt.dataset.salary;
                    recomputeNet();
                }
            });
        }
    });
    </script>
<?php else: ?>

<div class="card table-card">
    <div class="card-body p-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <form method="get" action="payroll.php" class="d-flex gap-2 align-items-center">
            <select class="form-select" name="period" style="width:180px;">
                <option value="">All periods</option>
                <?php foreach ($periods as $p): ?>
                    <option value="<?php echo e($p); ?>" <?php echo $periodFilter === $p ? 'selected' : ''; ?>><?php echo e($p); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-charcoal" type="submit">Filter</button>
            <a href="payroll.php" class="btn btn-light">Reset</a>
        </form>
        <a href="payroll.php?action=add" class="btn btn-rose rounded-pill"><i class="bi bi-plus-lg me-1"></i>Add Entry</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Staff</th><th>Period</th><th>Salary</th><th>Commission</th><th>Tips</th><th>Bonus</th><th>Deductions</th><th>Net</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$records): ?>
                <tr><td colspan="10"><div class="empty-state"><div class="icon"><i class="bi bi-cash-coin"></i></div><p class="text-muted mb-0">No payroll records found.</p></div></td></tr>
            <?php else: foreach ($records as $r): ?>
                <tr>
                    <td class="fw-semibold">
                        <?php echo e($r['staff_name']); ?>
                        <?php if ($r['staff_status'] === 'inactive'): ?>
                            <span class="badge bg-light text-muted">inactive</span>
                        <?php elseif ($r['staff_id'] === null): ?>
                            <span class="badge bg-light text-muted">deleted</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?php echo e($r['pay_period']); ?></td>
                    <td><?php echo money($r['salary']); ?></td>
                    <td><?php echo money($r['commission']); ?></td>
                    <td><?php echo money($r['tips']); ?></td>
                    <td><?php echo money($r['bonus']); ?></td>
                    <td class="text-danger"><?php echo money($r['deductions']); ?></td>
                    <td class="fw-semibold"><?php echo money($r['net_salary']); ?></td>
                    <td><?php echo status_badge($r['payment_status']); ?></td>
                    <td class="text-end">
                        <?php if ($r['payment_status'] === 'pending'): ?>
                            <button type="button" class="btn btn-sm btn-success rounded-pill"
                                    data-confirm="Mark this payroll as paid?"
                                    data-confirm-action="payroll.php?form_action=mark_paid&id=<?php echo $r['id']; ?>"
                                    data-confirm-label="Mark Paid"><i class="bi bi-check-lg"></i></button>
                        <?php endif; ?>
                        <a href="payroll.php?action=edit&id=<?php echo $r['id']; ?>" class="btn btn-sm btn-light rounded-pill"><i class="bi bi-pencil"></i></a>
                        <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                data-confirm="Delete this payroll record?"
                                data-confirm-action="payroll.php?form_action=delete_payroll&id=<?php echo $r['id']; ?>"
                                data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>