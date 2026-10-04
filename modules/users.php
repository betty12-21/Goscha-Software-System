<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — User management (Administrator only).
 * Admins create/manage admin accounts.
 */

require_permission('users');

$action = $_GET['action'] ?? 'list';
$me     = (int)($_SESSION['user_id'] ?? 0);

/* ============================================================
 * Save / create user
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_user') {
    require_permission('users');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('users.php'); }

    $id       = (int)($_POST['id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $status   = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('danger', 'A valid name and email address are required.');
        redirect($id ? "users.php?action=edit&id={$id}" : 'users.php?action=add');
    }
    if (!$id && strlen($password) < 8) {
        flash_set('danger', 'Password must be at least 8 characters.');
        redirect('users.php?action=add');
    }

    try {
        if ($id) {
            $sql  = 'UPDATE users SET name=:name, email=:email, phone=:phone, status=:status';
            $args = ['name' => $name, 'email' => $email, 'phone' => $phone ?: null, 'status' => $status, 'id' => $id];
            if ($password !== '') {
                if (strlen($password) < 8) {
                    flash_set('danger', 'Password must be at least 8 characters.');
                    redirect("users.php?action=edit&id={$id}");
                }
                $sql .= ', password=:password';
                $args['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id=:id';
            db()->prepare($sql)->execute($args);
            log_activity('update', 'users', "Updated user {$email}");
            flash_set('success', 'User updated.');
        } else {
            db()->prepare('INSERT INTO users (name, email, phone, password, role, status) VALUES (:name,:email,:phone,:password,"admin",:status)')
                ->execute([
                    'name' => $name, 'email' => $email, 'phone' => $phone ?: null,
                    'password' => password_hash($password, PASSWORD_DEFAULT), 'status' => $status,
                ]);
            log_activity('create', 'users', "Created admin account {$email}");
            flash_set('success', 'Admin account created.');
        }
    } catch (Throwable $e) {
        flash_set('danger', 'Save failed. That email address may already be in use.');
    }
    redirect('users.php');
}

/* ============================================================
 * Toggle status / delete
 * ============================================================ */
if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'toggle_user') {
    require_permission('users');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('users.php'); }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id === $me) { flash_set('warning', 'You cannot change your own status.'); redirect('users.php'); }
    $stmt = db()->prepare('SELECT status, email FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $u = $stmt->fetch();
    if ($u) {
        $newStatus = $u['status'] === 'active' ? 'inactive' : 'active';
        db()->prepare('UPDATE users SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $id]);
        log_activity('update', 'users', ($newStatus === 'active' ? 'Activated' : 'Deactivated') . " user {$u['email']}");
        flash_set('success', ($newStatus === 'active' ? 'Activated' : 'Deactivated') . ' successfully.');
    }
    redirect('users.php');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'delete_user') {
    require_permission('users');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('users.php'); }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id === $me) { flash_set('warning', 'You cannot delete your own account.'); redirect('users.php'); }
    try {
        db()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
        log_activity('delete', 'users', "Deleted user #{$id}");
        flash_set('success', 'User deleted.');
    } catch (Throwable $e) {
        flash_set('danger', 'Could not delete this user.');
    }
    redirect('users.php');
}

$users = db()->query('SELECT u.*, (SELECT COUNT(*) FROM notifications n WHERE n.user_id = u.id) AS notif_count FROM users u ORDER BY u.role, u.name')->fetchAll();

$page_title = 'Users';
$active     = 'users';
include __DIR__ . '/../includes/header.php';

if ($action === 'add' || $action === 'edit'):
    $user = ['id' => 0, 'name' => '', 'email' => '', 'phone' => '', 'status' => 'active'];
    $isEdit = $action === 'edit';
    if ($isEdit) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => (int)($_GET['id'] ?? 0)]);
        $found = $stmt->fetch();
        if (!$found) { flash_set('warning', 'User not found.'); redirect('users.php'); }
        $user = $found;
    }
    ?>
    <div class="card" style="max-width:680px;">
        <div class="card-header"><?php echo $isEdit ? 'Edit Admin' : 'New Admin Account'; ?></div>
        <div class="card-body">
            <form method="post" action="users.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="save_user">
                <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="name">Full Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required value="<?php echo e($user['name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Email Address <span class="required">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" required value="<?php echo e($user['email']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="phone">Phone</label>
                        <input type="text" class="form-control" id="phone" name="phone" value="<?php echo e($user['phone']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $user['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="password"><?php echo $isEdit ? 'New Password (leave blank to keep current)' : 'Password <span class="required">*</span>'; ?></label>
                        <input type="password" class="form-control" id="password" name="password" <?php echo $isEdit ? '' : 'required'; ?> autocomplete="new-password" minlength="8">
                        <div class="form-text">Minimum 8 characters.</div>
                    </div>
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i><?php echo $isEdit ? 'Save Changes' : 'Create Account'; ?></button>
                    <a href="users.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>

<div class="card table-card">
    <div class="card-body p-3 d-flex justify-content-between align-items-center">
        <span class="small text-muted"><?php echo count($users); ?> admin account(s)</span>
        <a href="users.php?action=add" class="btn btn-rose rounded-pill"><i class="bi bi-person-plus me-1"></i>New Admin</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$users): ?>
                <tr><td colspan="7"><div class="empty-state"><div class="icon"><i class="bi bi-people"></i></div><p class="text-muted mb-0">No users found.</p></div></td></tr>
            <?php else: foreach ($users as $u): ?>
                <tr>
                    <td class="fw-semibold"><?php echo e($u['name']); ?> <?php echo (int)$u['id'] === $me ? '<span class="badge bg-rose-soft text-rose">You</span>' : ''; ?></td>
                    <td><?php echo e($u['email']); ?></td>
                    <td class="small"><?php echo e($u['phone'] ?: '—'); ?></td>
                    <td><span class="badge rounded-pill bg-charcoal text-white">Admin</span></td>
                    <td><?php echo status_badge($u['status']); ?></td>
                    <td class="small"><?php echo fmt_date($u['created_at']); ?></td>
                    <td class="text-end">
                        <a href="users.php?action=edit&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-light rounded-pill"><i class="bi bi-pencil"></i></a>
                        <?php if ((int)$u['id'] !== $me): ?>
                            <button type="button" class="btn btn-sm btn-light rounded-pill <?php echo $u['status'] === 'active' ? 'text-warning' : 'text-success'; ?>"
                                    data-confirm="<?php echo $u['status'] === 'active' ? 'Deactivate this account?' : 'Activate this account?'; ?>"
                                    data-confirm-action="users.php?form_action=toggle_user&id=<?php echo $u['id']; ?>"
                                    data-confirm-label="<?php echo $u['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                <i class="bi <?php echo $u['status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle'; ?>"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                    data-confirm="Delete this user permanently?"
                                    data-confirm-action="users.php?form_action=delete_user&id=<?php echo $u['id']; ?>"
                                    data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                        <?php else: ?>
                            <span class="badge bg-light text-muted">You</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
