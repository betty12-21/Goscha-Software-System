<?php
/**
 * Beauty-php-ai — Super Admin: my profile.
 *
 * The signed-in operator can change their own name, email address and
 * password. Email stays unique across super admins because it is the login
 * identity, and changing the password requires the current one.
 */

require_once __DIR__ . '/../includes/init.php';
require_super_admin();

$me = (int)(current_super_admin()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token. Please try again.');
        redirect(super_admin_url('profile.php'));
    }

    $action = (string)($_POST['action'] ?? '');
    $errors = [];

    if ($action === 'profile') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));

        if ($name === '' || mb_strlen($name) > 150) {
            $errors[] = 'Please enter your name (150 characters or fewer).';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $dupe = db()->prepare('SELECT id FROM super_admins WHERE email = :email AND id <> :id LIMIT 1');
            $dupe->execute(['email' => $email, 'id' => $me]);
            if ($dupe->fetch()) {
                $errors[] = 'Another super admin already uses that email address.';
            }
        }

        if (!$errors) {
            db()->prepare('UPDATE super_admins SET name = :name, email = :email WHERE id = :id')
               ->execute(['name' => $name, 'email' => $email, 'id' => $me]);

            super_admin_audit('profile_update', 'Updated their own name/email.');
            flash_set('success', 'Profile updated.');
            redirect(super_admin_url('profile.php'));
        }

        flash_set('danger', implode(' ', $errors));
        redirect(super_admin_url('profile.php'));
    }

    if ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $row = db()->prepare('SELECT password FROM super_admins WHERE id = :id LIMIT 1');
        $row->execute(['id' => $me]);
        $hash = (string)$row->fetchColumn();

        if (!password_verify($current, $hash)) {
            $errors[] = 'Your current password is incorrect.';
        }
        if (strlen($new) < 10) {
            $errors[] = 'The new password must be at least 10 characters long.';
        } elseif ($new !== $confirm) {
            $errors[] = 'The new passwords do not match.';
        }

        if (!$errors) {
            db()->prepare('UPDATE super_admins SET password = :pw WHERE id = :id')
               ->execute(['pw' => password_hash($new, PASSWORD_DEFAULT), 'id' => $me]);

            super_admin_audit('password_change', 'Changed their own password.');
            flash_set('success', 'Password changed.');
            redirect(super_admin_url('profile.php'));
        }

        flash_set('danger', implode(' ', $errors));
        redirect(super_admin_url('profile.php'));
    }

    flash_set('danger', 'Unsupported action.');
    redirect(super_admin_url('profile.php'));
}

$admin = current_super_admin();

$page_title = 'My Profile';
$active     = 'profile';
include __DIR__ . '/../includes/super-admin/header.php';
?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="sa-card h-100">
            <div class="sa-card-body">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-person-vcard me-2"></i>Account details</h2>
                <form method="post" action="profile.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="profile">

                    <div class="mb-3">
                        <label class="form-label small" for="name">Name</label>
                        <input type="text" class="form-control" id="name" name="name" maxlength="150"
                               value="<?php echo e($admin['name']); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small" for="email">Email address</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?php echo e($admin['email']); ?>" required>
                    </div>

                    <div class="small text-muted mb-3">
                        Last signed in: <?php echo $admin['last_login_at'] ? e(fmt_datetime($admin['last_login_at'])) : '—'; ?>
                    </div>

                    <button type="submit" class="btn btn-rose rounded-pill px-4">Save changes</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="sa-card h-100">
            <div class="sa-card-body">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-shield-lock me-2"></i>Change password</h2>
                <form method="post" action="profile.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="password">

                    <div class="mb-3">
                        <label class="form-label small" for="current_password">Current password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small" for="new_password">New password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="10" required>
                        <div class="form-text">At least 10 characters.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small" for="confirm_password">Confirm new password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="10" required>
                    </div>

                    <button type="submit" class="btn btn-charcoal rounded-pill px-4">Change password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/super-admin/footer.php'; ?>
