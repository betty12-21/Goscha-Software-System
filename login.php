<?php
/**
 * Beauty-php-ai — Login page with animated bubble background.
 */

require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    redirect(home_url());
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);

        if ($email === '' || $password === '') {
            $error = 'Please enter your email and password.';
        } else {
            $result = attempt_login($email, $password, $remember);
            if ($result['success']) {
                flash_set('success', 'Welcome back, ' . e(current_user()['name']) . '!');
                redirect(home_url());
            }
            $error = $result['error'];
        }
    }
}

$page_title = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login · <?php echo e(salon_name()); ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💇‍♀️</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/style.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="auth-page">
    <div class="bubble-field" id="bubbleField"></div>

    <div class="auth-card">
        <a href="index.php" class="auth-logo"><i class="bi bi-scissors"></i></a>
        <h1 class="auth-title h3 text-center mb-1">Welcome Back</h1>
        <p class="auth-subtitle text-center mb-4">Sign in to your <?php echo e(salon_name()); ?> workspace</p>

        <?php if ($error): ?>
            <div class="alert alert-danger rounded-3 shadow-sm py-2"><i class="bi bi-exclamation-circle me-2"></i><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <?php echo csrf_field(); ?>
            <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-envelope text-muted"></i></span>
                    <input type="email" class="form-control border-start-0" id="email" name="email"
                           value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="you@example.com" required autofocus>
                </div>
            </div>

            <div class="mb-3">
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

            <div class="d-flex align-items-center justify-content-between mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
                    <label class="form-check-label small" for="remember">Remember me</label>
                </div>
                <a href="forgot-password.php" class="small text-decoration-none">Forgot password?</a>
            </div>

            <button type="submit" class="btn btn-rose btn-lg w-100 rounded-pill py-2">
                <i class="bi bi-box-arrow-in-right me-2"></i>Login
            </button>
        </form>

        <div class="text-center mt-4">
            <span class="small text-muted">New here?</span>
            <a href="signup.php" class="small fw-bold text-decoration-none">Sign Up</a>
        </div>
        <div class="text-center mt-2">
            <a href="index.php" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Back to homepage</a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        'use strict';

        var field = document.getElementById('bubbleField');
        var colors = ['rgba(183,110,121,.25)', 'rgba(201,164,76,.2)', 'rgba(255,255,255,.7)', 'rgba(232,201,196,.35)'];

        function makeBubble() {
            var size = 12 + Math.random() * 60;
            var b = document.createElement('span');
            b.className = 'bubble';
            b.style.width = size + 'px';
            b.style.height = size + 'px';
            b.style.left = (Math.random() * 100) + '%';
            b.style.background = colors[Math.floor(Math.random() * colors.length)];
            b.style.setProperty('--sway', (Math.random() * 80 - 40) + 'px');
            b.style.animationDuration = (12 + Math.random() * 16) + 's';
            b.style.animationDelay = (Math.random() * -20) + 's';
            field.appendChild(b);
        }

        for (var i = 0; i < 28; i++) {
            makeBubble();
        }

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
