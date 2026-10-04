<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Suppliers module (Administrator only).
 */

require_permission('suppliers');

$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_supplier') {
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('suppliers.php'); }
    $id      = (int)($_POST['id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact_person'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $status  = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

    if ($name === '') {
        flash_set('danger', 'Supplier name is required.');
        redirect($id ? "suppliers.php?action=edit&id={$id}" : 'suppliers.php?action=add');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('danger', 'Please enter a valid email address.');
        redirect($id ? "suppliers.php?action=edit&id={$id}" : 'suppliers.php?action=add');
    }

    if ($id) {
        db()->prepare('UPDATE suppliers SET name=:name, contact_person=:contact, phone=:phone, email=:email, address=:address, status=:status WHERE id=:id')
            ->execute(['name' => $name, 'contact' => $contact ?: null, 'phone' => $phone ?: null, 'email' => $email ?: null, 'address' => $address ?: null, 'status' => $status, 'id' => $id]);
        log_activity('update', 'suppliers', "Updated supplier {$name}");
        flash_set('success', 'Supplier updated.');
    } else {
        db()->prepare('INSERT INTO suppliers (name, contact_person, phone, email, address, status) VALUES (:name,:contact,:phone,:email,:address,:status)')
            ->execute(['name' => $name, 'contact' => $contact ?: null, 'phone' => $phone ?: null, 'email' => $email ?: null, 'address' => $address ?: null, 'status' => $status]);
        log_activity('create', 'suppliers', "Created supplier {$name}");
        flash_set('success', 'Supplier created.');
    }
    redirect('suppliers.php');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'delete_supplier') {
    require_permission('suppliers');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('suppliers.php'); }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    try {
        db()->prepare('DELETE FROM suppliers WHERE id = :id')->execute(['id' => $id]);
        flash_set('success', 'Supplier deleted.');
    } catch (Throwable $e) {
        flash_set('danger', 'Supplier has related records and cannot be deleted.');
    }
    redirect('suppliers.php');
}

$q = trim($_GET['q'] ?? '');
$suppliers = db()->query('SELECT s.*, (SELECT COUNT(*) FROM inventory_products p WHERE p.supplier_id = s.id) AS product_count FROM suppliers s ORDER BY s.name')->fetchAll();
if ($q !== '') {
    $suppliers = array_filter($suppliers, fn($s) => stripos($s['name'] . ' ' . $s['contact_person'] . ' ' . $s['phone'], $q) !== false);
}

$page_title = 'Suppliers';
$active     = 'suppliers';
include __DIR__ . '/../includes/header.php';

if ($action === 'add' || $action === 'edit'):
    $supplier = ['id' => 0, 'name' => '', 'contact_person' => '', 'phone' => '', 'email' => '', 'address' => '', 'status' => 'active'];
    if ($action === 'edit') {
        $stmt = db()->prepare('SELECT * FROM suppliers WHERE id = :id');
        $stmt->execute(['id' => (int)($_GET['id'] ?? 0)]);
        $found = $stmt->fetch();
        if (!$found) { flash_set('warning', 'Supplier not found.'); redirect('suppliers.php'); }
        $supplier = $found;
    }
    ?>
    <div class="card" style="max-width:720px;">
        <div class="card-header"><?php echo $action === 'add' ? 'New Supplier' : 'Edit Supplier'; ?></div>
        <div class="card-body">
            <form method="post" action="suppliers.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="save_supplier">
                <input type="hidden" name="id" value="<?php echo (int)$supplier['id']; ?>">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="name">Supplier Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required value="<?php echo e($supplier['name']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active" <?php echo $supplier['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $supplier['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="contact_person">Contact Person</label>
                        <input type="text" class="form-control" id="contact_person" name="contact_person" value="<?php echo e($supplier['contact_person']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="phone">Phone</label>
                        <input type="text" class="form-control" id="phone" name="phone" value="<?php echo e($supplier['phone']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?php echo e($supplier['email']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="address">Address</label>
                        <input type="text" class="form-control" id="address" name="address" value="<?php echo e($supplier['address']); ?>">
                    </div>
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i>Save Supplier</button>
                    <a href="suppliers.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>

<div class="card table-card">
    <div class="card-body p-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <form method="get" action="suppliers.php" class="d-flex gap-2">
            <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="Search suppliers" style="width:220px;">
            <button class="btn btn-charcoal" type="submit"><i class="bi bi-search me-1"></i>Search</button>
            <a href="suppliers.php" class="btn btn-light">Reset</a>
        </form>
        <a href="suppliers.php?action=add" class="btn btn-rose rounded-pill"><i class="bi bi-plus-lg me-1"></i>Add Supplier</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Name</th><th>Contact</th><th>Phone</th><th>Email</th><th>Address</th><th>Products</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$suppliers): ?>
                <tr><td colspan="8"><div class="empty-state"><div class="icon"><i class="bi bi-truck"></i></div><p class="text-muted mb-0">No suppliers found.</p></div></td></tr>
            <?php else: foreach ($suppliers as $s): ?>
                <tr>
                    <td class="fw-semibold"><?php echo e($s['name']); ?></td>
                    <td class="small"><?php echo e($s['contact_person'] ?: '—'); ?></td>
                    <td class="small"><?php echo e($s['phone'] ?: '—'); ?></td>
                    <td class="small"><?php echo e($s['email'] ?: '—'); ?></td>
                    <td class="small text-muted"><?php echo e($s['address'] ?: '—'); ?></td>
                    <td><?php echo (int)$s['product_count']; ?></td>
                    <td><?php echo status_badge($s['status']); ?></td>
                    <td class="text-end">
                        <a href="suppliers.php?action=edit&id=<?php echo $s['id']; ?>" class="btn btn-sm btn-light rounded-pill"><i class="bi bi-pencil"></i></a>
                        <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                data-confirm="Delete this supplier?"
                                data-confirm-action="suppliers.php?form_action=delete_supplier&id=<?php echo $s['id']; ?>"
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
