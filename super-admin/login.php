<?php
/**
 * Beauty-php-ai — Super Admin Control Panel login.
 *
 * Platform operators sign in here. This is a separate identity space from
 * the salon login (login.php): it reads `super_admins`, never `users`, and
 * sets its own session key. No public registration exists for super admins;
 * the first one is created with scripts/provision_super_admin.php.
 */

require_once __DIR__ . '/../includes/init.php';

if (is_super_admin_logged_in() && current_super_admin() !== null) {
    redirect(super_admin_url('dashboard.php'));
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $email    = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $result = attempt_super_admin_login($email, $password);

        if ($result['success']) {
            flash_set('success', 'Welcome to the control panel.');
            redirect(super_admin_url('dashboard.php'));
        }

        $error = $result['error'] ?? 'Sign-in failed.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Control Panel Login · <?php echo e(app_name()); ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🛡️</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/super-admin.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="sa-auth-page">
    <div class="sa-auth-card">
        <div class="sa-auth-mark"><i class="bi bi-shield-lock-fill"></i></div>
        <h1 class="sa-auth-title h4">Super Admin</h1>
        <p class="sa-auth-sub"><?php echo e(app_name()); ?> control panel</p>

        <?php if ($error): ?>
            <div class="alert alert-danger rounded-3 shadow-sm py-2">
                <i class="bi bi-exclamation-circle me-2"></i><?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <?php echo csrf_field(); ?>

            <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-envelope text-muted"></i></span>
                    <input type="email" class="form-control border-start-0" id="email" name="email"
                           value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="admin@example.com" required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="password">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-lock text-muted"></i></span>
                    <input type="password" class="form-control border-start-0 border-end-0" id="password" name="password"
                           placeholder="Your password" required>
                    <button class="btn btn-white border-start-0" type="button" id="togglePass" tabindex="-1">
                        <i class="bi bi-eye text-muted" id="togglePassIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-rose btn-lg w-100 rounded-pill py-2">
                <i class="bi bi-box-arrow-in-right me-2"></i>Sign in
            </button>
        </form>

        <div class="text-center mt-4">
            <a href="<?php echo e(base_url('index.php')); ?>" class="small text-muted text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i>Back to homepage
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        'use strict';
        var toggle = document.getElementById('togglePass');
        var pass = document.getElementById('password');
        var icon = document.getElementById('togglePassIcon');
        if (toggle) {
            toggle.addEventListener('click', function () {
                var show = pass.type === 'password';
                pass.type = show ? 'text' : 'password';
                icon.className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye') + ' text-muted';
            });
        }
    })();
</script>
</body>
</html>
