<?php
/**
 * Beauty-php-ai — Register your business.
 *
 * Public, self-service Administrator registration. One submission creates:
 *   - a `salons` row
 *   - that salon's seven weekly-hours rows, defaulted (see
 *     default_weekly_hours()) because the form does not ask for a schedule
 *   - the Administrator account that owns it
 *
 * all inside one transaction (see includes/salon_registration.php).
 *
 * Duplicate registrations are refused on the email address, which is the login
 * identity: one Administrator belongs to exactly one salon for the life of the
 * account, so re-registering the same email can only ever be an attempt to
 * create a second salon with an identity that already exists.
 */

require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    redirect(home_url());
}

$error    = null;
$errors   = [];
$first    = trim($_POST['reg_first'] ?? '');
$last     = trim($_POST['reg_last'] ?? '');
$phone    = trim($_POST['reg_phone'] ?? '');
$email    = trim($_POST['reg_email'] ?? '');
$salonName = trim($_POST['reg_salon_name'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error  = 'Your session expired. Please try again.';
        $errors = [$error];
    } else {
        $result = register_salon([
            'salon_name'       => $salonName,
            'first_name'       => $first,
            'last_name'        => $last,
            'phone'            => $phone,
            'email'            => $email,
            'password'         => (string)($_POST['reg_password'] ?? ''),
            'password_confirm' => (string)($_POST['reg_confirm'] ?? ''),
        ]);

        if ($result['success']) {
            /* Sign the new owner straight in, so they land on a dashboard
               that already carries their salon context. */
            $login = attempt_login($email, (string)($_POST['reg_password'] ?? ''));

            if ($login['success']) {
                flash_set('success', 'Welcome! Your salon "' . $salonName . '" is ready.');
                redirect(home_url());
            }

            flash_set('success', 'Your salon "' . $salonName . '" has been created. Please sign in.');
            redirect(base_url('login.php'));
        }

        $errors = $result['errors'] ?? [];
        $error  = $result['error'] ?? 'Registration failed.';
    }
}

$page_title = 'Register Your Business';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Your Business</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💇‍♀️</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/style.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="auth-page">
    <div class="bubble-field" id="bubbleField"></div>

    <div class="auth-card" style="max-width: 640px;">
        <a href="index.php" class="auth-logo"><i class="bi bi-scissors"></i></a>
        <h1 class="auth-title h3 text-center mb-1">Register Your Business</h1>
        <p class="auth-subtitle text-center mb-4">Create your salon and owner account</p>

        <?php if ($error): ?>
            <div class="alert alert-danger rounded-3 shadow-sm py-2">
                <i class="bi bi-exclamation-circle me-2"></i><?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($errors && count($errors) > 1): ?>
            <ul class="small text-danger mb-3 ps-3">
                <?php foreach ($errors as $e): ?>
                    <li><?php echo e($e); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" action="signup.php" novalidate>
            <?php echo csrf_field(); ?>

            <div class="mb-3">
                <label class="form-label" for="regSalonName">Salon Name</label>
                <input type="text" class="form-control" id="regSalonName" name="reg_salon_name"
                       value="<?php echo e($salonName); ?>" placeholder="Salon name "
                       maxlength="150" required autofocus>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label" for="regFirst">First Name</label>
                    <input type="text" class="form-control" id="regFirst" name="reg_first"
                           value="<?php echo e($first); ?>" placeholder="First name" maxlength="100" required>
                </div>
                <div class="col-6">
                    <label class="form-label" for="regLast">Last Name</label>
                    <input type="text" class="form-control" id="regLast" name="reg_last"
                           value="<?php echo e($last); ?>" placeholder="Last name" maxlength="100" required>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label" for="regPhone">Phone Number</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-telephone text-muted"></i></span>
                        <input type="tel" class="form-control border-start-0" id="regPhone" name="reg_phone"
                               value="<?php echo e($phone); ?>" placeholder="+251 9XX XXX XXX">
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="regEmail">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-envelope text-muted"></i></span>
                        <input type="email" class="form-control border-start-0" id="regEmail" name="reg_email"
                               value="<?php echo e($email); ?>" placeholder="you@example.com" required>
                    </div>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label" for="regPass">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control border-end-0" id="regPass" name="reg_password"
                               placeholder="Min 8 chars" minlength="8" required>
                        <button class="btn btn-white border-start-0" type="button" data-toggle-pass="regPass" tabindex="-1">
                            <i class="bi bi-eye text-muted"></i>
                        </button>
                    </div>
                </div>
                <div class="col-6">
                    <label class="form-label" for="regConfirm">Confirm Password</label>
                    <input type="password" class="form-control" id="regConfirm" name="reg_confirm"
                           placeholder="Re-enter" minlength="8" required>
                </div>
            </div>

            <button type="submit" class="btn btn-rose btn-lg w-100 rounded-pill py-2 mt-4">
                <i class="bi bi-shop me-2"></i>Create My Salon
            </button>
        </form>

        <div class="text-center mt-4">
            <span class="small text-muted">Already have an account?</span>
            <a href="login.php" class="small fw-bold text-decoration-none">Login</a>
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

        Array.prototype.forEach.call(document.querySelectorAll('[data-toggle-pass]'), function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.getAttribute('data-toggle-pass'));
                if (!input) {
                    return;
                }
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.querySelector('i').className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye') + ' text-muted';
            });
        });
    })();
</script>
</body>
</html>