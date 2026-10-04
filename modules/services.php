<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Services module.
 *
 * Implements the three-level service hierarchy:
 *
 *     CATEGORY  ->  SUBCATEGORY (optional)  ->  SERVICE
 *
 * Administrator: full management of all three levels.
 * Receptionist: read-only. They can view the hierarchy and select
 * services during booking, but every mutating handler below is refused
 * server-side by require_permission('services_edit') — hiding the buttons
 * is only a convenience, never the control.
 */

require_permission('services');

$canEdit = can('services_edit');
$action  = $_GET['action'] ?? 'list';
$formAction = $_POST['form_action'] ?? $_GET['form_action'] ?? '';

/* ============================================================
 * Guard: every write below requires the edit permission.
 * ============================================================ */
$writeActions = [
    'save_service', 'toggle_service', 'delete_service', 'reorder_service',
    'save_category', 'toggle_category', 'delete_category', 'reorder_category',
    'save_subcategory', 'toggle_subcategory', 'delete_subcategory', 'reorder_subcategory',
];

if (in_array($formAction, $writeActions, true)) {
    require_permission('services_edit');

    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token.');
        redirect('services.php');
    }
}

/* ============================================================
 * Save service
 * ============================================================ */
if ($formAction === 'save_service') {
    $id       = (int)($_POST['id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $category = (int)($_POST['category_id'] ?? 0);
    $subcat   = (int)($_POST['subcategory_id'] ?? 0);
    $desc     = trim($_POST['description'] ?? '');
    $dur      = max(1, (int)($_POST['duration_minutes'] ?? 30));
    $price    = max(0, (float)($_POST['price'] ?? 0));
    $status   = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

    $input = [
        'id'               => $id,
        'name'             => $name,
        'category_id'      => $category,
        'subcategory_id'   => $subcat,
        'description'      => $desc,
        'duration_minutes' => $dur,
        'price'            => $price,
        'status'           => $status,
    ];

    $back = $id ? "services.php?action=edit&id={$id}" : 'services.php?action=add';

    /* Rule 5: a service belongs to exactly one category. */
    if ($name === '') {
        form_remember($input);
        flash_set('danger', 'Service name is required.');
        redirect($back);
    }

    if ($category <= 0) {
        form_remember($input);
        flash_set('danger', 'Please select a category — a service must belong to exactly one category.');
        redirect($back);
    }

    $categoryExists = db()->query('SELECT COUNT(*) FROM service_categories WHERE id = ' . $category)->fetchColumn();
    if (!$categoryExists) {
        form_remember($input);
        flash_set('danger', 'The selected category no longer exists.');
        redirect($back);
    }

    /* Rule 6 + 7: the subcategory is optional, but when given it must
       belong to the very same category. Enforced here on the server so a
       tampered POST cannot cross-link categories. */
    $hierarchyError = validate_service_hierarchy($category, $subcat);
    if ($hierarchyError !== null) {
        form_remember($input);
        flash_set('danger', $hierarchyError);
        redirect($back);
    }

    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    if ($sortOrder < 0) {
        $sortOrder = 0;
    }

    if ($id) {
        db()->prepare(
            'UPDATE services
                SET category_id = :cat, subcategory_id = :subcat, name = :name, description = :desc,
                    duration_minutes = :dur, price = :price, status = :status,
                    sort_order = CASE WHEN :keep = 1 THEN sort_order ELSE :ord END
              WHERE id = :id'
        )->execute([
            'cat' => $category, 'subcat' => $subcat ?: null, 'name' => $name, 'desc' => $desc,
            'dur' => $dur, 'price' => $price, 'status' => $status,
            'keep' => isset($_POST['sort_order']) ? 0 : 1, 'ord' => $sortOrder, 'id' => $id,
        ]);
        log_activity('update', 'services', "Updated service {$name}");
        flash_set('success', 'Service updated successfully.');
    } else {
        if ($sortOrder <= 0) {
            $sortOrder = next_sort_order('services', $category);
        }
        db()->prepare(
            'INSERT INTO services (category_id, subcategory_id, name, description, duration_minutes, price, status, sort_order)
             VALUES (:cat, :subcat, :name, :desc, :dur, :price, :status, :ord)'
        )->execute([
            'cat' => $category, 'subcat' => $subcat ?: null, 'name' => $name, 'desc' => $desc,
            'dur' => $dur, 'price' => $price, 'status' => $status, 'ord' => $sortOrder,
        ]);
        log_activity('create', 'services', "Created service {$name}");
        flash_set('success', 'Service created successfully.');
    }
    redirect('services.php');
}

/* ============================================================
 * Activate / deactivate service
 *
 * Deactivation is preferred over deletion: appointment_services,
 * waitlist entries and invoices keep referencing the same service row,
 * so historical records stay valid (§15).
 * ============================================================ */
if ($formAction === 'toggle_service') {
    $id = (int)($_POST['id'] ?? 0);
    if (toggle_status('services', $id)) {
        $stmt = db()->prepare('SELECT status, name FROM services WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        $now = $row['status'] === 'active' ? 'activated' : 'deactivated';
        log_activity('update', 'services', "Service {$row['name']} {$now}");
        flash_set('success', "Service {$row['name']} {$now}.");
    } else {
        flash_set('danger', 'Service not found.');
    }
    redirect('services.php' . (isset($_POST['return']) ? '?' . e($_POST['return']) : ''));
}

/* ============================================================
 * Delete service — only when nothing references it
 * ============================================================ */
if ($formAction === 'delete_service') {
    $id = (int)($_POST['id'] ?? 0);
    $used = (int)db()->query('SELECT COUNT(*) FROM appointment_services WHERE service_id = ' . $id)->fetchColumn();
    $wait = (int)db()->query('SELECT COUNT(*) FROM waitlist WHERE service_id = ' . $id)->fetchColumn();

    if ($used > 0 || $wait > 0) {
        flash_set('danger', "This service is used by $used appointment line(s) and $wait booking request(s). Deactivate it instead of deleting it.");
    } else {
        $stmt = db()->prepare('SELECT name FROM services WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $name = $stmt->fetchColumn();
        db()->prepare('DELETE FROM services WHERE id = :id')->execute(['id' => $id]);
        log_activity('delete', 'services', "Deleted service {$name}");
        flash_set('success', 'Service deleted successfully.');
    }
    redirect('services.php');
}

/* ============================================================
 * Change display order
 * ============================================================ */
if (strpos($formAction, 'reorder_') === 0) {
    $entity = substr($formAction, strlen('reorder_'));
    $id     = (int)($_POST['id'] ?? 0);
    $dir    = (int)($_POST['dir'] ?? 0) >= 0 ? 1 : -1;
    $parent = (int)($_POST['parent_id'] ?? 0);
    $view   = in_array($_POST['return_view'] ?? '', ['categories', 'subcategories', 'services'], true)
        ? $_POST['return_view'] : 'services';

    if (in_array($entity, ['service', 'category', 'subcategory'], true)) {
        $table = 'service_' . ($entity === 'category' ? 'categories' : ($entity === 'subcategory' ? 'subcategories' : 'services'));
        if (reorder_sibling($table, 'category_id', $id, $dir)) {
            flash_set('success', ucfirst($entity) . ' moved.');
        } else {
            flash_set('info', ucfirst($entity) . ' is already at the edge of the list.');
        }
    }
    redirect('services.php?view=' . $view . ($parent ? '&category_id=' . $parent : ''));
}

/* ============================================================
 * Save category
 * ============================================================ */
if ($formAction === 'save_category') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
    $sortOrder = max(0, (int)($_POST['sort_order'] ?? 0));

    if ($name === '') {
        flash_set('danger', 'Category name is required.');
        redirect('services.php?view=categories');
    }

    try {
        if ($id) {
            db()->prepare(
                'UPDATE service_categories
                    SET name = :name, description = :desc, status = :status,
                        sort_order = CASE WHEN :keep = 1 THEN sort_order ELSE :ord END
                  WHERE id = :id'
            )->execute([
                'name' => $name, 'desc' => $desc, 'status' => $status,
                'keep' => isset($_POST['sort_order']) ? 0 : 1, 'ord' => $sortOrder, 'id' => $id,
            ]);
            log_activity('update', 'services', "Updated category {$name}");
            flash_set('success', 'Category updated successfully.');
        } else {
            if ($sortOrder <= 0) {
                $sortOrder = next_sort_order('service_categories');
            }
            db()->prepare(
                'INSERT INTO service_categories (name, description, status, sort_order) VALUES (:name, :desc, :status, :ord)'
            )->execute(['name' => $name, 'desc' => $desc, 'status' => $status, 'ord' => $sortOrder]);
            log_activity('create', 'services', "Created category {$name}");
            flash_set('success', 'Category created successfully.');
        }
    } catch (Throwable $e) {
        flash_set('danger', 'A category with this name already exists.');
    }
    redirect('services.php?view=categories');
}

/* ============================================================
 * Activate / deactivate category
 * ============================================================ */
if ($formAction === 'toggle_category') {
    $id = (int)($_POST['id'] ?? 0);
    if (toggle_status('service_categories', $id)) {
        $stmt = db()->prepare('SELECT status, name FROM service_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        $now = $row['status'] === 'active' ? 'activated' : 'deactivated';
        log_activity('update', 'services', "Category {$row['name']} {$now}");
        flash_set('success', "Category {$row['name']} {$now}.");
    } else {
        flash_set('danger', 'Category not found.');
    }
    redirect('services.php?view=categories');
}

/* ============================================================
 * Delete category — refused while anything is attached
 * ============================================================ */
if ($formAction === 'delete_category') {
    $id   = (int)($_POST['id'] ?? 0);
    $subs = (int)db()->query('SELECT COUNT(*) FROM service_subcategories WHERE category_id = ' . $id)->fetchColumn();
    $svcs = (int)db()->query('SELECT COUNT(*) FROM services WHERE category_id = ' . $id)->fetchColumn();

    if ($subs > 0 || $svcs > 0) {
        flash_set('danger', "This category still holds $subs subcategory(ies) and $svcs service(s). Deactivate it instead so existing records stay intact.");
    } else {
        $stmt = db()->prepare('SELECT name FROM service_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $name = $stmt->fetchColumn();
        db()->prepare('DELETE FROM service_categories WHERE id = :id')->execute(['id' => $id]);
        log_activity('delete', 'services', "Deleted category {$name}");
        flash_set('success', 'Category deleted successfully.');
    }
    redirect('services.php?view=categories');
}

/* ============================================================
 * Save subcategory
 * ============================================================ */
if ($formAction === 'save_subcategory') {
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    $catId  = (int)($_POST['category_id'] ?? 0);
    $desc   = trim($_POST['description'] ?? '');
    $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
    $sortOrder = max(0, (int)($_POST['sort_order'] ?? 0));

    /* A subcategory cannot exist without a parent category. */
    if ($name === '' || $catId === 0) {
        flash_set('danger', 'Subcategory name and parent category are both required.');
        redirect('services.php?view=subcategories');
    }

    if (!db()->query('SELECT COUNT(*) FROM service_categories WHERE id = ' . $catId)->fetchColumn()) {
        flash_set('danger', 'The selected category no longer exists.');
        redirect('services.php?view=subcategories');
    }

    try {
        if ($id) {
            /* Re-parenting a subcategory must not leave services pointing
               at a subcategory that belongs to a different category. */
            $stmt = db()->prepare('SELECT COUNT(*) FROM services WHERE subcategory_id = :sub AND category_id <> :cat');
            $stmt->execute(['sub' => $id, 'cat' => $catId]);
            $orphans = (int)$stmt->fetchColumn();

            if ($orphans > 0) {
                flash_set('danger', "This subcategory still has $orphans service(s) that belong to its current category. Move those services first.");
                redirect('services.php?view=subcategories');
            }

            db()->prepare(
                'UPDATE service_subcategories
                    SET category_id = :cat, name = :name, description = :desc, status = :status,
                        sort_order = CASE WHEN :keep = 1 THEN sort_order ELSE :ord END
                  WHERE id = :id'
            )->execute([
                'cat' => $catId, 'name' => $name, 'desc' => $desc, 'status' => $status,
                'keep' => isset($_POST['sort_order']) ? 0 : 1, 'ord' => $sortOrder, 'id' => $id,
            ]);
            log_activity('update', 'services', "Updated subcategory {$name}");
            flash_set('success', 'Subcategory updated successfully.');
    } else {
        if ($sortOrder <= 0) {
            $sortOrder = next_sort_order('service_subcategories', $catId);
        }

            db()->prepare(
                'INSERT INTO service_subcategories (category_id, name, description, status, sort_order)
                 VALUES (:cat, :name, :desc, :status, :ord)'
            )->execute(['cat' => $catId, 'name' => $name, 'desc' => $desc, 'status' => $status, 'ord' => $sortOrder]);
            log_activity('create', 'services', "Created subcategory {$name}");
            flash_set('success', 'Subcategory created successfully.');
        }
    } catch (Throwable $e) {
        flash_set('danger', 'A subcategory with this name already exists in the selected category.');
    }
    redirect('services.php?view=subcategories');
}

/* ============================================================
 * Activate / deactivate subcategory
 * ============================================================ */
if ($formAction === 'toggle_subcategory') {
    $id = (int)($_POST['id'] ?? 0);
    if (toggle_status('service_subcategories', $id)) {
        $stmt = db()->prepare('SELECT status, name FROM service_subcategories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        $now = $row['status'] === 'active' ? 'activated' : 'deactivated';
        log_activity('update', 'services', "Subcategory {$row['name']} {$now}");
        flash_set('success', "Subcategory {$row['name']} {$now}.");
    } else {
        flash_set('danger', 'Subcategory not found.');
    }
    redirect('services.php?view=subcategories');
}

/* ============================================================
 * Delete subcategory — refused while services still use it
 * ============================================================ */
if ($formAction === 'delete_subcategory') {
    $id  = (int)($_POST['id'] ?? 0);
    $svcs = (int)db()->query('SELECT COUNT(*) FROM services WHERE subcategory_id = ' . $id)->fetchColumn();

    if ($svcs > 0) {
        flash_set('danger', "This subcategory still holds $svcs service(s). Deactivate it instead, or move the services first.");
    } else {
        $stmt = db()->prepare('SELECT name FROM service_subcategories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $name = $stmt->fetchColumn();
        db()->prepare('DELETE FROM service_subcategories WHERE id = :id')->execute(['id' => $id]);
        log_activity('delete', 'services', "Deleted subcategory {$name}");
        flash_set('success', 'Subcategory deleted successfully.');
    }
    redirect('services.php?view=subcategories');
}

$page_title = 'Services';
$active     = 'services';

$tab       = $_GET['view'] ?? 'services';
$q         = trim($_GET['q'] ?? '');
$catId     = (int)($_GET['category_id'] ?? 0);
$subId     = (int)($_GET['subcategory_id'] ?? 0);
$statusF   = $_GET['status'] ?? '';
$statusF   = in_array($statusF, ['active', 'inactive'], true) ? $statusF : '';
$display   = ($_GET['display'] ?? '') === 'table' ? 'table' : 'group';
$oldInput  = form_old_take();

$categories    = service_categories_all();
$subcategories = service_subcategories_all();
$counts        = service_hierarchy_counts();

if ($action !== 'list' && !$canEdit) {
    require_permission('services_edit');
}

/* ============================================================
 * Service list query — filtering happens in SQL, not in PHP.
 * ============================================================ */
$where  = [];
$params = [];

if ($q !== '') {
    /* A named placeholder may only appear once per statement when PDO uses
       native prepares, so each column gets its own name. */
    $where[] = '(s.name LIKE :q1 OR s.description LIKE :q2 OR c.name LIKE :q3 OR sc.name LIKE :q4)';
    $like = '%' . $q . '%';
    $params['q1'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
    $params['q4'] = $like;
}
if ($catId > 0) {
    $where[] = 's.category_id = :cat';
    $params['cat'] = $catId;
}
if ($subId > 0) {
    $where[] = 's.subcategory_id = :sub';
    $params['sub'] = $subId;
}
if ($statusF !== '') {
    $where[] = 's.status = :status';
    $params['status'] = $statusF;
}

$sql = 'SELECT s.*, c.name AS category_name, c.sort_order AS category_order,
               sc.name AS subcategory_name, sc.sort_order AS subcategory_order
          FROM services s
          LEFT JOIN service_categories c     ON c.id  = s.category_id
          LEFT JOIN service_subcategories sc ON sc.id = s.subcategory_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY COALESCE(c.sort_order, 999999), c.name,
                  COALESCE(sc.sort_order, 999999), sc.name,
                  s.sort_order, s.name, s.id';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$services = $stmt->fetchAll();

$grouped = group_services_by_hierarchy($services);
$filterActive = ($q !== '' || $catId > 0 || $subId > 0 || $statusF !== '');

include __DIR__ . '/../includes/header.php';
?>

<ul class="nav nav-pills mb-3 gap-1">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'services' ? 'active' : ''; ?>" href="services.php?view=services" style="<?php echo $tab === 'services' ? 'background:var(--bsai-rose);' : 'color:var(--bsai-charcoal-2);'; ?>">
            <i class="bi bi-scissors me-1"></i>Services
        </a>
    </li>
    <?php if ($canEdit): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'categories' ? 'active' : ''; ?>" href="services.php?view=categories" style="<?php echo $tab === 'categories' ? 'background:var(--bsai-rose);' : 'color:var(--bsai-charcoal-2);'; ?>">
            <i class="bi bi-folder2 me-1"></i>Categories
            <span class="badge bg-light text-muted ms-1"><?php echo count($categories); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'subcategories' ? 'active' : ''; ?>" href="services.php?view=subcategories" style="<?php echo $tab === 'subcategories' ? 'background:var(--bsai-rose);' : 'color:var(--bsai-charcoal-2);'; ?>">
            <i class="bi bi-diagram-3 me-1"></i>Subcategories
            <span class="badge bg-light text-muted ms-1"><?php echo count($subcategories); ?></span>
        </a>
    </li>
    <?php endif; ?>
</ul>

<?php if (!$canEdit): ?>
    <div class="alert alert-info rounded-3 py-2 small">
        <i class="bi bi-eye me-2"></i>You can browse the service menu here. Managing categories, subcategories and services is reserved for the administrator.
    </div>
<?php endif; ?>

<?php /* ============================================================
     * CATEGORIES
     * ============================================================ */ ?>
<?php if ($tab === 'categories' && $canEdit): ?>

    <?php
    $catQ   = trim($_GET['cq'] ?? '');
    $catStatus = $_GET['cstatus'] ?? '';
    $catStatus = in_array($catStatus, ['active', 'inactive'], true) ? $catStatus : '';
    $catEditId = (int)($_GET['edit'] ?? 0);
    $editingCat = null;
    if ($catEditId > 0) {
        foreach ($categories as $c) {
            if ((int)$c['id'] === $catEditId) { $editingCat = $c; break; }
        }
    }
    $visibleCats = array_values(array_filter($categories, static function ($c) use ($catQ, $catStatus) {
        if ($catStatus !== '' && $c['status'] !== $catStatus) return false;
        if ($catQ === '') return true;
        return stripos($c['name'] . ' ' . (string)($c['description'] ?? ''), $catQ) !== false;
    }));
    ?>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><?php echo $editingCat ? 'Edit Category' : 'Add Category'; ?></div>
                <div class="card-body">
                    <form method="post" action="services.php?view=categories">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="form_action" value="save_category">
                        <input type="hidden" name="id" value="<?php echo (int)($editingCat['id'] ?? 0); ?>">
                        <div class="mb-3">
                            <label class="form-label" for="catName">Category Name <span class="required">*</span></label>
                            <input type="text" class="form-control" id="catName" name="name" required
                                   value="<?php echo e($editingCat['name'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="catDesc">Description</label>
                            <textarea class="form-control" id="catDesc" name="description" rows="2"><?php echo e($editingCat['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-7">
                                <label class="form-label" for="catStatus">Status</label>
                                <select class="form-select" id="catStatus" name="status">
                                    <option value="active" <?php echo ($editingCat['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($editingCat['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-5">
                                <label class="form-label" for="catSort">Order</label>
                                <input type="number" class="form-control" id="catSort" name="sort_order" min="0"
                                       value="<?php echo (int)($editingCat['sort_order'] ?? 0); ?>">
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-rose rounded-pill px-4" type="submit">
                                <i class="bi bi-check2 me-1"></i><?php echo $editingCat ? 'Save Changes' : 'Add Category'; ?>
                            </button>
                            <?php if ($editingCat): ?>
                                <a href="services.php?view=categories" class="btn btn-light rounded-pill px-3">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card table-card">
                <div class="card-body p-3 border-bottom">
                    <form method="get" action="services.php" class="d-flex flex-wrap gap-2">
                        <input type="hidden" name="view" value="categories">
                        <input type="text" class="form-control" name="cq" value="<?php echo e($catQ); ?>" placeholder="Search categories" style="width:200px;">
                        <select class="form-select" name="cstatus" style="width:160px;">
                            <option value="">All statuses</option>
                            <option value="active" <?php echo $catStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $catStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <button class="btn btn-charcoal" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="services.php?view=categories" class="btn btn-light">Reset</a>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th style="width:80px;">Order</th><th>Name</th><th>Description</th><th class="text-center">Sub</th><th class="text-center">Services</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibleCats as $cat): ?>
                            <tr>
                                <td>
                                    <?php if ($canEdit): ?>
                                    <div class="btn-group btn-group-sm">
                                        <form method="post" action="services.php?view=categories">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="form_action" value="reorder_category">
                                            <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                            <input type="hidden" name="dir" value="-1">
                                            <input type="hidden" name="return_view" value="categories">
                                            <button class="btn btn-light px-1 py-0" type="submit" title="Move up"><i class="bi bi-chevron-up"></i></button>
                                        </form>
                                        <form method="post" action="services.php?view=categories">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="form_action" value="reorder_category">
                                            <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                            <input type="hidden" name="dir" value="1">
                                            <input type="hidden" name="return_view" value="categories">
                                            <button class="btn btn-light px-1 py-0" type="submit" title="Move down"><i class="bi bi-chevron-down"></i></button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold"><?php echo e($cat['name']); ?></td>
                                <td class="text-muted small"><?php echo e($cat['description'] ?: SERVICE_NO_SUBCATEGORY); ?></td>
                                <td class="text-center">
                                    <?php $subCount = count(service_subcategories_of((int)$cat['id'])); ?>
                                    <a href="services.php?view=subcategories&category_id=<?php echo (int)$cat['id']; ?>" class="badge bg-rose-soft text-rose"><?php echo $subCount; ?></a>
                                </td>
                                <td class="text-center"><?php echo (int)($counts['categories'][(int)$cat['id']] ?? 0); ?></td>
                                <td><?php echo status_badge($cat['status']); ?></td>
                                <td class="text-end text-nowrap">
                                    <a href="services.php?view=categories&edit=<?php echo (int)$cat['id']; ?>" class="btn btn-sm btn-light rounded-pill" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <form method="post" action="services.php?view=categories" class="d-inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="toggle_category">
                                        <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                        <button class="btn btn-sm btn-light rounded-pill" type="submit" title="<?php echo $cat['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                            <i class="bi bi-<?php echo $cat['status'] === 'active' ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                            data-confirm="Delete this category? Only possible when it has no subcategories and no services."
                                            data-confirm-action="services.php?form_action=delete_category&amp;id=<?php echo (int)$cat['id']; ?>"
                                            data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$visibleCats): ?>
                            <tr><td colspan="7"><div class="empty-state">
                                <div class="icon"><i class="bi bi-folder2"></i></div>
                                <p class="text-muted mb-0">No categories found.</p>
                            </div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php /* ============================================================
     * SUBCATEGORIES
     * ============================================================ */ ?>
<?php elseif ($tab === 'subcategories' && $canEdit): ?>

    <?php
    $subQ = trim($_GET['sq'] ?? '');
    $subStatus = $_GET['sstatus'] ?? '';
    $subStatus = in_array($subStatus, ['active', 'inactive'], true) ? $subStatus : '';
    $subEditId = (int)($_GET['edit'] ?? 0);
    $editingSub = null;
    foreach ($subcategories as $s) {
        if ((int)$s['id'] === $subEditId) { $editingSub = $s; break; }
    }
    $visibleSubs = array_values(array_filter($subcategories, static function ($s) use ($subQ, $subStatus, $catId) {
        if ($catId > 0 && (int)$s['category_id'] !== $catId) return false;
        if ($subStatus !== '' && $s['status'] !== $subStatus) return false;
        if ($subQ === '') return true;
        return stripos($s['name'] . ' ' . (string)($s['description'] ?? '') . ' ' . (string)($s['category_name'] ?? ''), $subQ) !== false;
    }));
    ?>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><?php echo $editingSub ? 'Edit Subcategory' : 'Add Subcategory'; ?></div>
                <div class="card-body">
                    <form method="post" action="services.php?view=subcategories">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="form_action" value="save_subcategory">
                        <input type="hidden" name="id" value="<?php echo (int)($editingSub['id'] ?? 0); ?>">
                        <div class="mb-3">
                            <label class="form-label" for="subCatParent">Category <span class="required">*</span></label>
                            <select class="form-select" id="subCatParent" name="category_id" required>
                                <option value="">— Select category —</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)($editingSub['category_id'] ?? $catId) === (int)$cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">A subcategory always belongs to exactly one category.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="subCatName">Subcategory Name <span class="required">*</span></label>
                            <input type="text" class="form-control" id="subCatName" name="name" required
                                   value="<?php echo e($editingSub['name'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="subCatDesc">Description</label>
                            <textarea class="form-control" id="subCatDesc" name="description" rows="2"><?php echo e($editingSub['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-7">
                                <label class="form-label" for="subCatStatus">Status</label>
                                <select class="form-select" id="subCatStatus" name="status">
                                    <option value="active" <?php echo ($editingSub['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($editingSub['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-5">
                                <label class="form-label" for="subCatSort">Order</label>
                                <input type="number" class="form-control" id="subCatSort" name="sort_order" min="0"
                                       value="<?php echo (int)($editingSub['sort_order'] ?? 0); ?>">
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-rose rounded-pill px-4" type="submit">
                                <i class="bi bi-check2 me-1"></i><?php echo $editingSub ? 'Save Changes' : 'Add Subcategory'; ?>
                            </button>
                            <?php if ($editingSub): ?>
                                <a href="services.php?view=subcategories" class="btn btn-light rounded-pill px-3">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card table-card">
                <div class="card-body p-3 border-bottom">
                    <form method="get" action="services.php" class="d-flex flex-wrap gap-2">
                        <input type="hidden" name="view" value="subcategories">
                        <input type="text" class="form-control" name="sq" value="<?php echo e($subQ); ?>" placeholder="Search subcategories" style="width:200px;">
                        <select class="form-select" name="category_id" style="width:190px;">
                            <option value="0">All categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int)$cat['id']; ?>" <?php echo $catId === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo e($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select" name="sstatus" style="width:150px;">
                            <option value="">All statuses</option>
                            <option value="active" <?php echo $subStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $subStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <button class="btn btn-charcoal" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
                        <a href="services.php?view=subcategories" class="btn btn-light">Reset</a>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Category</th><th>Name</th><th>Description</th><th class="text-center">Services</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($visibleSubs as $sub): ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold"><?php echo e($sub['category_name'] ?: SERVICE_NO_SUBCATEGORY); ?></span>
                                    <?php if ($canEdit): ?>
                                    <div class="btn-group btn-group-sm mt-1">
                                        <form method="post" action="services.php?view=subcategories">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="form_action" value="reorder_subcategory">
                                            <input type="hidden" name="id" value="<?php echo (int)$sub['id']; ?>">
                                            <input type="hidden" name="dir" value="-1">
                                            <input type="hidden" name="parent_id" value="<?php echo (int)$sub['category_id']; ?>">
                                            <input type="hidden" name="return_view" value="subcategories">
                                            <button class="btn btn-light px-1 py-0" type="submit" title="Move up"><i class="bi bi-chevron-up"></i></button>
                                        </form>
                                        <form method="post" action="services.php?view=subcategories">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="form_action" value="reorder_subcategory">
                                            <input type="hidden" name="id" value="<?php echo (int)$sub['id']; ?>">
                                            <input type="hidden" name="dir" value="1">
                                            <input type="hidden" name="parent_id" value="<?php echo (int)$sub['category_id']; ?>">
                                            <input type="hidden" name="return_view" value="subcategories">
                                            <button class="btn btn-light px-1 py-0" type="submit" title="Move down"><i class="bi bi-chevron-down"></i></button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold"><?php echo e($sub['name']); ?></td>
                                <td class="text-muted small"><?php echo e($sub['description'] ?: SERVICE_NO_SUBCATEGORY); ?></td>
                                <td class="text-center"><?php echo (int)($counts['subcategories'][(int)$sub['id']] ?? 0); ?></td>
                                <td><?php echo status_badge($sub['status']); ?></td>
                                <td class="text-end text-nowrap">
                                    <a href="services.php?view=subcategories&edit=<?php echo (int)$sub['id']; ?>" class="btn btn-sm btn-light rounded-pill" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <form method="post" action="services.php?view=subcategories" class="d-inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="toggle_subcategory">
                                        <input type="hidden" name="id" value="<?php echo (int)$sub['id']; ?>">
                                        <button class="btn btn-sm btn-light rounded-pill" type="submit" title="<?php echo $sub['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                            <i class="bi bi-<?php echo $sub['status'] === 'active' ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                            data-confirm="Delete this subcategory? Only possible when no service uses it."
                                            data-confirm-action="services.php?form_action=delete_subcategory&amp;id=<?php echo (int)$sub['id']; ?>"
                                            data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$visibleSubs): ?>
                            <tr><td colspan="6"><div class="empty-state">
                                <div class="icon"><i class="bi bi-diagram-3"></i></div>
                                <p class="text-muted mb-0">No subcategories found.</p>
                            </div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php /* ============================================================
     * SERVICES — add / edit form
     * ============================================================ */ ?>
<?php elseif ($tab === 'services' && ($action === 'add' || $action === 'edit')): ?>
    <?php
    require_permission('services_edit');

    $service = [
        'id'               => 0,
        'category_id'      => $catId,
        'subcategory_id'   => $subId,
        'name'             => '',
        'description'      => '',
        'duration_minutes' => 30,
        'price'            => 0,
        'status'           => 'active',
    ];

    if ($action === 'edit') {
        $stmt = db()->prepare('SELECT * FROM services WHERE id = :id');
        $stmt->execute(['id' => (int)($_GET['id'] ?? 0)]);
        $found = $stmt->fetch();
        if (!$found) { flash_set('warning', 'Service not found.'); redirect('services.php'); }
        $service = $found;
    }

    /* Repopulate after a rejected submission. */
    $service['name']             = (string)form_old($oldInput, 'name', $service['name']);
    $service['description']      = (string)form_old($oldInput, 'description', $service['description']);
    $service['category_id']      = (int)form_old($oldInput, 'category_id', $service['category_id']);
    $service['subcategory_id']   = (int)form_old($oldInput, 'subcategory_id', $service['subcategory_id']);
    $service['duration_minutes'] = (int)form_old($oldInput, 'duration_minutes', $service['duration_minutes']);
    $service['price']            = (float)form_old($oldInput, 'price', $service['price']);
    $service['status']           = (string)form_old($oldInput, 'status', $service['status']);

    /* Subcategories of the currently selected category only. */
    $formSubs = service_subcategories_of((int)$service['category_id']);
    ?>

    <div class="card" style="max-width:760px;">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span><?php echo $action === 'add' ? 'New Service' : 'Edit Service'; ?></span>
            <a href="services.php?action=add" class="btn btn-rose btn-sm rounded-pill px-3">
                <i class="bi bi-plus-lg me-1"></i>Add Service
            </a>
        </div>
        <div class="card-body">
            <form method="post" action="services.php?view=services" id="serviceForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="save_service">
                <input type="hidden" name="id" value="<?php echo (int)$service['id']; ?>">

                <div class="alert alert-light rounded-3 py-2 small mb-3">
                    <i class="bi bi-diagram-3 me-2 text-rose"></i>
                    A service belongs to one <strong>category</strong>. A <strong>subcategory</strong> is optional —
                    leave it as <em>No subcategory</em> for a <span class="text-nowrap">Category &rarr; Service</span> menu.
                </div>

                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label" for="category_id">1 · Category <span class="required">*</span></label>
                        <select class="form-select" id="category_id" name="category_id" required>
                            <option value="">— Select a category —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)$service['category_id'] === (int)$cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($cat['name']); ?><?php echo $cat['status'] === 'inactive' ? ' (inactive)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="subcategory_id">2 · Subcategory <span class="text-muted small fw-normal">(optional)</span></label>
                        <select class="form-select" id="subcategory_id" name="subcategory_id" disabled>
                            <option value="">No subcategory</option>
                            <?php foreach ($formSubs as $sub): ?>
                                <option value="<?php echo (int)$sub['id']; ?>" <?php echo (int)$service['subcategory_id'] === (int)$sub['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($sub['name']); ?><?php echo $sub['status'] === 'inactive' ? ' (inactive)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" id="subcatHint">Pick a category to see its subcategories.</div>
                    </div>

                    <div class="col-12"><hr class="my-1"></div>

                    <div class="col-md-8">
                        <label class="form-label" for="name">3 · Service Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required
                               value="<?php echo e($service['name']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active" <?php echo $service['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $service['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?php echo e($service['description']); ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="price">Price (<?php echo e(currency()); ?>) <span class="required">*</span></label>
                        <input type="number" class="form-control" id="price" name="price" min="0" step="0.01" required
                               value="<?php echo e($service['price']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="duration_minutes">Duration (minutes) <span class="required">*</span></label>
                        <input type="number" class="form-control" id="duration_minutes" name="duration_minutes" min="5" step="5" required
                               value="<?php echo (int)$service['duration_minutes']; ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="sort_order">Display Order</label>
                        <input type="number" class="form-control" id="sort_order" name="sort_order" min="0"
                               value="<?php echo (int)($service['sort_order'] ?? 0); ?>">
                        <div class="form-text">Lower numbers appear first. 0 keeps the current position.</div>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i>Save Service</button>
                    <a href="services.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
    /* Dependent selection: Category -> Subcategory.
       Subcategories of every category are embedded once and filtered in
       the browser, so switching category is instant (no round trip) and
       still degrades to "No subcategory" when JavaScript is unavailable.
       The server re-validates the pair on submit regardless. */
    (function () {
        var catSel = document.getElementById('category_id');
        var subSel = document.getElementById('subcategory_id');
        var hint   = document.getElementById('subcatHint');
        if (!catSel || !subSel) return;

        var ALL = <?php echo json_encode(array_map(static function ($s) {
            return [
                'id'     => (int)$s['id'],
                'cat'    => (int)$s['category_id'],
                'label'  => $s['name'] . ($s['status'] === 'inactive' ? ' (inactive)' : ''),
            ];
        }, $subcategories)); ?>;

        var current = subSel.value;

        function rebuild() {
            var cat = catSel.value;
            subSel.innerHTML = '';

            var none = document.createElement('option');
            none.value = '';
            none.textContent = 'No subcategory';
            subSel.appendChild(none);

            var shown = 0;
            ALL.forEach(function (s) {
                if (cat === '' || String(s.cat) !== String(cat)) return;
                var opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.label;
                subSel.appendChild(opt);
                shown++;
            });

            if (current && String(current) !== '' ) {
                var match = Array.prototype.some.call(subSel.options, function (o) { return o.value === String(current); });
                subSel.value = match ? String(current) : '';
            }

            subSel.disabled = false;

            if (cat === '') {
                hint.textContent = 'Pick a category to see its subcategories.';
            } else if (shown === 0) {
                hint.textContent = 'This category has no subcategories yet — the service will sit directly under it.';
            } else {
                hint.textContent = shown + ' subcategor' + (shown === 1 ? 'y' : 'ies') + ' available. Leave as "No subcategory" if it does not fit.';
            }
        }

        catSel.addEventListener('change', rebuild);
        rebuild();
    })();
    </script>

<?php /* ============================================================
     * SERVICES — list
     * ============================================================ */ ?>
<?php else: ?>

    <div class="card table-card">
        <div class="card-body p-3 border-bottom">
            <form method="get" action="services.php" class="row g-2 align-items-end">
                <input type="hidden" name="view" value="services">
                <div class="col-6 col-lg-3">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="Service, category, subcategory">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small mb-1">Category</label>
                    <select class="form-select" name="category_id" id="filterCategory">
                        <option value="0">All categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo (int)$cat['id']; ?>" <?php echo $catId === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label small mb-1">Subcategory</label>
                    <select class="form-select" name="subcategory_id" id="filterSubcategory"
                            data-cascade="filterCategory" data-cascade-hint="#filterSubHint">
                        <option value="0" data-all="1">All subcategories</option>
                        <?php
                        $subsForFilter = $catId > 0 ? service_subcategories_of($catId) : $subcategories;
                        foreach ($subsForFilter as $sub): ?>
                            <option value="<?php echo (int)$sub['id']; ?>"
                                    data-parent="<?php echo (int)$sub['category_id']; ?>"
                                    <?php echo $subId === (int)$sub['id'] ? 'selected' : ''; ?>>
                                <?php echo e($catId > 0 ? $sub['name'] : (($sub['category_name'] ? $sub['category_name'] . ' → ' : '') . $sub['name'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="filterSubHint"></div>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small mb-1">Status</label>
                    <select class="form-select" name="status">
                        <option value="">All statuses</option>
                        <option value="active" <?php echo $statusF === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusF === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2 d-flex gap-2">
                    <button class="btn btn-charcoal flex-grow-1" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
                    <?php if ($filterActive): ?>
                        <a href="services.php" class="btn btn-light" title="Reset filters"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card-body py-2 d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div class="small text-muted">
                <i class="bi bi-diagram-3 me-1"></i>
                <?php echo count($services); ?> service(s)<?php echo $filterActive ? ' matching your filters' : ''; ?>
                · <?php echo count($grouped); ?> categor<?php echo count($grouped) === 1 ? 'y' : 'ies'; ?>
                · <?php echo $counts['services']; ?> total
            </div>
            <div class="btn-group btn-group-sm" role="group" aria-label="Display mode">
                <a class="btn <?php echo $display === 'group' ? 'btn-rose' : 'btn-light'; ?>"
                   href="services.php?<?php echo e(query_string(['display' => 'group'])); ?>"><i class="bi bi-list-nested me-1"></i>Grouped</a>
                <a class="btn <?php echo $display === 'table' ? 'btn-rose' : 'btn-light'; ?>"
                   href="services.php?<?php echo e(query_string(['display' => 'table'])); ?>"><i class="bi bi-table me-1"></i>Table</a>
            </div>
        </div>

        <?php if ($display === 'group'): ?>
            <div class="card-body pt-2">
                <?php foreach ($grouped as $node):
                    $cat = $node['category'];
                    /* Count what is actually on screen, not the global total. */
                    $shownInCategory = count($node['direct']);
                    foreach ($node['subcategories'] as $subCount) {
                        $shownInCategory += count($subCount['services']);
                    }
                    ?>
                    <section class="svc-cat mb-4">
                        <header class="svc-cat__head">
                            <span class="svc-cat__icon"><i class="bi bi-folder2-open"></i></span>
                            <span class="svc-cat__name"><?php echo e($cat['name']); ?></span>
                            <span class="svc-cat__count"><?php echo $shownInCategory; ?> service<?php echo $shownInCategory === 1 ? '' : 's'; ?></span>
                            <?php if ($catId !== (int)$cat['id']): ?>
                                <a href="services.php?category_id=<?php echo (int)$cat['id']; ?>" class="small text-decoration-none">filter this category</a>
                            <?php endif; ?>
                            <?php if ($canEdit): ?>
                                <a href="services.php?action=add&category_id=<?php echo (int)$cat['id']; ?>" class="btn btn-sm btn-light rounded-pill ms-auto">
                                    <i class="bi bi-plus-lg me-1"></i>Add in <?php echo e($cat['name']); ?>
                                </a>
                            <?php endif; ?>
                        </header>

                        <div class="row g-3">
                            <?php
                            /* One renderer for both shapes, so a subcategory card and a
                               "no subcategory" card can never drift apart. */
                            $buckets = [];
                            foreach ($node['subcategories'] as $subNode) {
                                $buckets[] = [
                                    'name'     => (string)$subNode['subcategory']['name'],
                                    'icon'     => 'bi-diagram-3',
                                    'services' => $subNode['services'],
                                ];
                            }
                            if (!empty($node['direct'])) {
                                $buckets[] = [
                                    'name'     => SERVICE_NO_SUBCATEGORY,
                                    'icon'     => 'bi-asterisk',
                                    'services' => $node['direct'],
                                ];
                            }

                            foreach ($buckets as $bucket): ?>
                                <div class="col-md-6 col-xl-4">
                                    <div class="svc-sub h-100">
                                        <div class="svc-sub__head">
                                            <i class="bi <?php echo e($bucket['icon']); ?>"></i>
                                            <span><?php echo e($bucket['name']); ?></span>
                                        </div>
                                        <ul class="svc-sub__list">
                                            <?php foreach ($bucket['services'] as $svc): ?>
                                                <li class="svc-row">
                                                    <span class="svc-row__name">
                                                        <?php echo e($svc['name']); ?>
                                                        <?php if ($svc['status'] !== 'active'): ?><span class="badge bg-light text-muted ms-1">Inactive</span><?php endif; ?>
                                                    </span>
                                                    <span class="svc-row__meta"><?php echo minutes_to_duration((int)$svc['duration_minutes']); ?> · <?php echo money($svc['price']); ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <?php if (!$grouped): ?>
                    <div class="empty-state">
                        <div class="icon"><i class="bi bi-scissors"></i></div>
                        <p class="text-muted mb-0">No services found<?php echo $filterActive ? ' for these filters' : ''; ?>.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th>Category</th><th>Subcategory</th><th>Service</th>
                        <th class="text-end">Duration</th><th class="text-end">Price</th>
                        <th>Status</th><th class="text-end">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($services as $svc): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo e($svc['category_name'] ?: SERVICE_NO_SUBCATEGORY); ?></td>
                            <td>
                                <?php if ($svc['subcategory_name']): ?>
                                    <a href="services.php?subcategory_id=<?php echo (int)$svc['subcategory_id']; ?>" class="text-decoration-none"><?php echo e($svc['subcategory_name']); ?></a>
                                <?php else: ?>
                                    <span class="text-muted" title="This service sits directly under its category"><?php echo SERVICE_NO_SUBCATEGORY; ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold"><?php echo e($svc['name']); ?></div>
                                <?php if ($svc['description']): ?>
                                    <div class="small text-muted"><?php echo e(mb_strimwidth($svc['description'], 0, 90, '…')); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap"><?php echo minutes_to_duration((int)$svc['duration_minutes']); ?></td>
                            <td class="text-end fw-semibold text-nowrap"><?php echo money($svc['price']); ?></td>
                            <td><?php echo status_badge($svc['status']); ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($canEdit): ?>
                                    <a href="services.php?action=edit&id=<?php echo (int)$svc['id']; ?>" class="btn btn-sm btn-light rounded-pill" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <form method="post" action="services.php" class="d-inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="toggle_service">
                                        <input type="hidden" name="id" value="<?php echo (int)$svc['id']; ?>">
                                        <input type="hidden" name="return" value="<?php echo e(query_string(['view' => 'services', 'action' => null])); ?>">
                                        <button class="btn btn-sm btn-light rounded-pill" type="submit" title="<?php echo $svc['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                            <i class="bi bi-<?php echo $svc['status'] === 'active' ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                            data-confirm="Delete this service? Only possible when no appointment or booking request uses it."
                                            data-confirm-action="services.php?form_action=delete_service&amp;id=<?php echo (int)$svc['id']; ?>"
                                            data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted">View only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$services): ?>
                        <tr><td colspan="7"><div class="empty-state">
                            <div class="icon"><i class="bi bi-scissors"></i></div>
                            <p class="text-muted mb-0">No services found<?php echo $filterActive ? ' for these filters' : ''; ?>.</p>
                        </div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($canEdit): ?>
        <div class="mt-3 d-flex flex-wrap gap-2">
            <a href="services.php?action=add" class="btn btn-rose rounded-pill px-4"><i class="bi bi-plus-lg me-1"></i>Add Service</a>
            <a href="services.php?view=categories" class="btn btn-light rounded-pill px-4"><i class="bi bi-folder2 me-1"></i>Manage Categories</a>
            <a href="services.php?view=subcategories" class="btn btn-light rounded-pill px-4"><i class="bi bi-diagram-3 me-1"></i>Manage Subcategories</a>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
