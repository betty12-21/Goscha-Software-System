<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Inventory module (Administrator only).
 * Products, stock levels, low-stock alerts, purchase records.
 */

require_permission('inventory');

$action = $_GET['action'] ?? 'list';
$tab    = $_GET['view'] ?? 'products';

/* ============================================================
 * Save product
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_product') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token.');
        redirect('inventory.php');
    }
    $id            = (int)($_POST['id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $sku           = trim($_POST['sku'] ?? '');
    $barcode       = trim($_POST['barcode'] ?? '');
    $category      = trim($_POST['category'] ?? '');
    $supplierId    = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $quantity      = max(0, (int)($_POST['quantity'] ?? 0));
    $minimumStock  = max(0, (int)($_POST['minimum_stock'] ?? 5));
    $costPrice     = max(0, (float)($_POST['cost_price'] ?? 0));
    $sellingPrice  = max(0, (float)($_POST['selling_price'] ?? 0));
    $status        = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

    if ($name === '' || $sku === '') {
        flash_set('danger', 'Product name and SKU are required.');
        redirect($id ? "inventory.php?action=edit&id={$id}" : 'inventory.php?action=add');
    }

    try {
        if ($id) {
            db()->prepare(
                'UPDATE inventory_products SET name=:name, sku=:sku, barcode=:barcode, category=:category,
                 supplier_id=:supplier, quantity=:qty, minimum_stock=:min, cost_price=:cost, selling_price=:price, status=:status
                 WHERE id=:id'
            )->execute([
                'name' => $name, 'sku' => $sku, 'barcode' => $barcode ?: null, 'category' => $category ?: null,
                'supplier' => $supplierId, 'qty' => $quantity, 'min' => $minimumStock,
                'cost' => $costPrice, 'price' => $sellingPrice, 'status' => $status, 'id' => $id,
            ]);
            log_activity('update', 'inventory', "Updated product {$name}");
            flash_set('success', 'Product updated.');
        } else {
            db()->prepare(
                'INSERT INTO inventory_products (name, sku, barcode, category, supplier_id, quantity, minimum_stock, cost_price, selling_price, status)
                 VALUES (:name,:sku,:barcode,:category,:supplier,:qty,:min,:cost,:price,:status)'
            )->execute([
                'name' => $name, 'sku' => $sku, 'barcode' => $barcode ?: null, 'category' => $category ?: null,
                'supplier' => $supplierId, 'qty' => $quantity, 'min' => $minimumStock,
                'cost' => $costPrice, 'price' => $sellingPrice, 'status' => $status,
            ]);
            log_activity('create', 'inventory', "Created product {$name}");
            flash_set('success', 'Product created.');
        }
    } catch (Throwable $e) {
        flash_set('danger', 'Save failed. SKU may already be in use.');
    }
    redirect('inventory.php');
}

/* ============================================================
 * Delete product / purchase
 * ============================================================ */
if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'delete_product') {
    require_permission('inventory');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('inventory.php'); }
    try {
        db()->prepare('DELETE FROM inventory_products WHERE id = :id')->execute(['id' => (int)($_POST['id'] ?? $_GET['id'] ?? 0)]);
        flash_set('success', 'Product deleted.');
    } catch (Throwable $e) {
        flash_set('danger', 'Product has related records and cannot be deleted.');
    }
    redirect('inventory.php');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'delete_purchase') {
    require_permission('inventory');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('inventory.php?view=purchases'); }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    try {
        db()->prepare('DELETE FROM purchases WHERE id = :id')->execute(['id' => $id]);
        flash_set('success', 'Purchase record deleted.');
    } catch (Throwable $e) {
        flash_set('danger', 'Could not delete purchase record.');
    }
    redirect('inventory.php?view=purchases');
}

/* ============================================================
 * Record purchase (adds stock)
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_purchase') {
    require_permission('inventory');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('inventory.php?view=purchases'); }

    $productId  = (int)($_POST['product_id'] ?? 0);
    $supplierId = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $quantity   = max(1, (int)($_POST['quantity'] ?? 1));
    $unitPrice  = max(0, (float)($_POST['unit_price'] ?? 0));
    $pDate      = $_POST['purchase_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pDate)) { $pDate = date('Y-m-d'); }

    if (!$productId) {
        flash_set('danger', 'Please select a product.');
        redirect('inventory.php?view=purchases');
    }
    $total = round($quantity * $unitPrice, 2);

    try {
        db()->beginTransaction();
        db()->prepare(
            'INSERT INTO purchases (supplier_id, product_id, quantity, unit_price, total, purchase_date)
             VALUES (:supplier,:product,:qty,:unit,:total,:pdate)'
        )->execute(['supplier' => $supplierId, 'product' => $productId, 'qty' => $quantity, 'unit' => $unitPrice, 'total' => $total, 'pdate' => $pDate]);
        db()->prepare('UPDATE inventory_products SET quantity = quantity + :qty WHERE id = :id')
            ->execute(['qty' => $quantity, 'id' => $productId]);
        db()->commit();

        log_activity('create', 'inventory', "Purchase of {$quantity} units recorded");
        notify('Stock received', 'Purchase recorded and stock updated.', 'success');
        flash_set('success', 'Purchase recorded and stock updated.');
    } catch (Throwable $e) {
        db()->rollBack();
        flash_set('danger', 'Could not record purchase.');
    }
    redirect('inventory.php?view=purchases');
}

/* ============================================================
 * Load data
 * ============================================================ */
$products = db()->query(
    'SELECT p.*, s.name AS supplier_name,
            (SELECT COUNT(*) FROM purchases pu WHERE pu.product_id = p.id) AS purchase_count
     FROM inventory_products p LEFT JOIN suppliers s ON s.id = p.supplier_id
     ORDER BY p.name'
)->fetchAll();

$lowStock = array_filter($products, fn($p) => (int)$p['quantity'] <= (int)$p['minimum_stock']);

$suppliers = db()->query('SELECT * FROM suppliers WHERE status = "active" ORDER BY name')->fetchAll();

$purchases = db()->query(
    'SELECT pu.*, p.name AS product_name, s.name AS supplier_name
     FROM purchases pu
     JOIN inventory_products p ON p.id = pu.product_id
     LEFT JOIN suppliers s ON s.id = pu.supplier_id
     ORDER BY pu.purchase_date DESC, pu.id DESC
     LIMIT 100'
)->fetchAll();

$page_title = 'Inventory';
$active     = 'inventory';
include __DIR__ . '/../includes/header.php';
?>

<ul class="nav nav-pills mb-3 gap-1">
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'products' ? 'active' : ''; ?>" href="inventory.php?view=products" style="<?php echo $tab === 'products' ? 'background:var(--bsai-rose);' : 'color:var(--bsai-charcoal-2);'; ?>">Products <span class="badge bg-danger rounded-pill ms-1"><?php echo count($lowStock); ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'purchases' ? 'active' : ''; ?>" href="inventory.php?view=purchases" style="<?php echo $tab === 'purchases' ? 'background:var(--bsai-rose);' : 'color:var(--bsai-charcoal-2);'; ?>">Purchase History</a></li>
</ul>

<?php if ($tab === 'purchases'): ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Record Purchase</div>
            <div class="card-body">
                <form method="post" action="inventory.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_purchase">
                    <div class="mb-3">
                        <label class="form-label" for="product_id">Product <span class="required">*</span></label>
                        <select class="form-select" id="product_id" name="product_id" required>
                            <option value="">— Select —</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo e($p['name']); ?> (stock: <?php echo (int)$p['quantity']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="supplier_id">Supplier</label>
                        <select class="form-select" id="supplier_id" name="supplier_id">
                            <option value="">— None —</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo e($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="quantity">Quantity <span class="required">*</span></label>
                            <input type="number" class="form-control" id="quantity" name="quantity" min="1" required value="1">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="unit_price">Unit Cost (<?php echo e(currency()); ?>)</label>
                            <input type="number" class="form-control" id="unit_price" name="unit_price" min="0" step="0.01" value="0.00">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="purchase_date">Purchase Date</label>
                        <input type="date" class="form-control" id="purchase_date" name="purchase_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-box-arrow-in-down me-1"></i>Add Stock</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card table-card">
            <div class="card-header">Recent Purchases</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Date</th><th>Product</th><th>Supplier</th><th class="text-end">Qty</th><th class="text-end">Unit Cost</th><th class="text-end">Total</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php if (!$purchases): ?>
                        <tr><td colspan="7"><div class="empty-state"><div class="icon"><i class="bi bi-box-seam"></i></div><p class="text-muted mb-0">No purchases recorded yet.</p></div></td></tr>
                    <?php else: foreach ($purchases as $pu): ?>
                        <tr>
                            <td class="small"><?php echo fmt_date($pu['purchase_date']); ?></td>
                            <td class="fw-semibold"><?php echo e($pu['product_name']); ?></td>
                            <td class="small"><?php echo $pu['supplier_name'] ? e($pu['supplier_name']) : '—'; ?></td>
                            <td class="text-end"><?php echo (int)$pu['quantity']; ?></td>
                            <td class="text-end"><?php echo money($pu['unit_price']); ?></td>
                            <td class="text-end fw-semibold"><?php echo money($pu['total']); ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                        data-confirm="Delete this purchase record?"
                                        data-confirm-action="inventory.php?form_action=delete_purchase&id=<?php echo $pu['id']; ?>"
                                        data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php else: ?>

    <?php if ($action === 'add' || $action === 'edit'): ?>
        <?php
        $product = ['id' => 0, 'name' => '', 'sku' => '', 'barcode' => '', 'category' => '', 'supplier_id' => null, 'quantity' => 0, 'minimum_stock' => 5, 'cost_price' => 0, 'selling_price' => 0, 'status' => 'active'];
        if ($action === 'edit') {
            $stmt = db()->prepare('SELECT * FROM inventory_products WHERE id = :id');
            $stmt->execute(['id' => (int)($_GET['id'] ?? 0)]);
            $found = $stmt->fetch();
            if (!$found) { flash_set('warning', 'Product not found.'); redirect('inventory.php'); }
            $product = $found;
        }
        ?>
        <div class="card" style="max-width:760px;">
            <div class="card-header"><?php echo $action === 'add' ? 'New Product' : 'Edit Product'; ?></div>
            <div class="card-body">
                <form method="post" action="inventory.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_product">
                    <input type="hidden" name="id" value="<?php echo (int)$product['id']; ?>">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="name">Product Name <span class="required">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required value="<?php echo e($product['name']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="sku">SKU <span class="required">*</span></label>
                            <input type="text" class="form-control" id="sku" name="sku" required value="<?php echo e($product['sku']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="barcode">Barcode</label>
                            <input type="text" class="form-control" id="barcode" name="barcode" value="<?php echo e($product['barcode']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="category">Category</label>
                            <input type="text" class="form-control" id="category" name="category" value="<?php echo e($product['category']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="supplier_id">Supplier</label>
                            <select class="form-select" id="supplier_id" name="supplier_id">
                                <option value="">— None —</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo $product['supplier_id'] !== null && (int)$product['supplier_id'] === (int)$s['id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="quantity">Quantity</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" min="0" value="<?php echo (int)$product['quantity']; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="minimum_stock">Minimum Stock</label>
                            <input type="number" class="form-control" id="minimum_stock" name="minimum_stock" min="0" value="<?php echo (int)$product['minimum_stock']; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cost_price">Cost Price (<?php echo e(currency()); ?>)</label>
                            <input type="number" class="form-control" id="cost_price" name="cost_price" min="0" step="0.01" value="<?php echo e($product['cost_price']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="selling_price">Selling Price (<?php echo e(currency()); ?>)</label>
                            <input type="number" class="form-control" id="selling_price" name="selling_price" min="0" step="0.01" value="<?php echo e($product['selling_price']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="status">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="active" <?php echo $product['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $product['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i>Save Product</button>
                        <a href="inventory.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>

    <div class="card table-card">
        <div class="card-body p-3 d-flex justify-content-between align-items-center">
            <div>
                <?php if ($lowStock): ?>
                    <span class="badge bg-danger-subtle text-danger border rounded-pill px-3 py-2"><i class="bi bi-exclamation-triangle me-1"></i><?php echo count($lowStock); ?> product(s) at or below minimum stock</span>
                <?php else: ?>
                    <span class="badge bg-success-subtle text-success border rounded-pill px-3 py-2"><i class="bi bi-check-lg me-1"></i>All stock levels healthy</span>
                <?php endif; ?>
            </div>
            <a href="inventory.php?action=add" class="btn btn-rose rounded-pill"><i class="bi bi-plus-lg me-1"></i>Add Product</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Product</th><th>SKU</th><th>Category</th><th>Supplier</th><th>Stock</th><th>Min</th><th>Cost</th><th>Selling</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="10"><div class="empty-state"><div class="icon"><i class="bi bi-box-seam"></i></div><p class="text-muted mb-0">No products yet.</p></div></td></tr>
                <?php else: foreach ($products as $p):
                    $isLow = (int)$p['quantity'] <= (int)$p['minimum_stock'];
                    ?>
                    <tr <?php echo $isLow ? 'class="table-warning"' : ''; ?>>
                        <td class="fw-semibold"><?php echo e($p['name']); ?></td>
                        <td class="small text-muted"><?php echo e($p['sku']); ?></td>
                        <td class="small"><?php echo e($p['category'] ?: '—'); ?></td>
                        <td class="small"><?php echo e($p['supplier_name'] ?: '—'); ?></td>
                        <td>
                            <span class="badge rounded-pill <?php echo $isLow ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'; ?>"><?php echo (int)$p['quantity']; ?></span>
                            <?php echo $isLow ? '<span class="small text-danger ms-1"><i class="bi bi-exclamation-diamond"></i> low</span>' : ''; ?>
                        </td>
                        <td><?php echo (int)$p['minimum_stock']; ?></td>
                        <td><?php echo money($p['cost_price']); ?></td>
                        <td class="fw-semibold"><?php echo money($p['selling_price']); ?></td>
                        <td><?php echo status_badge($p['status']); ?></td>
                        <td class="text-end">
                            <a href="inventory.php?action=edit&id=<?php echo $p['id']; ?>" class="btn btn-sm btn-light rounded-pill"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                    data-confirm="Delete this product and its history?"
                                    data-confirm-action="inventory.php?form_action=delete_product&id=<?php echo $p['id']; ?>"
                                    data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
