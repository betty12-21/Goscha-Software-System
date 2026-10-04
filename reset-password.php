<?php
/**
 * Beauty-php-ai — Reset password using a secure token.
 */

require_once __DIR__ . '/includes/init.php';

$token = trim($_GET['token'] ?? '');
$error = null;
$done  = false;

if ($token === '') {
    $error = 'Missing reset token.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirmation = $_POST['password_confirm'] ?? '';

    if (!csrf_check()) {
        $error = 'Invalid security token. Please try again.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmation) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = db()->prepare(
            'SELECT rt.user_id, u.name FROM password_resets rt
             JOIN users u ON u.id = rt.user_id
             WHERE rt.token = :token AND rt.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = 'This reset link is invalid or has expired.';
        } else {
            $stmt = db()->prepare('UPDATE users SET password = :pass WHERE id = :id');
            $stmt->execute(['pass' => password_hash($password, PASSWORD_DEFAULT), 'id' => $row['user_id']]);

            $stmt = db()->prepare('DELETE FROM password_resets WHERE token = :token');
            $stmt->execute(['token' => $token]);

            log_activity('password_reset', 'auth', "Password reset completed for {$row['name']}");
            flash_set('success', 'Your password has been reset. Please log in.');
            $done = true;
        }
    }
}

$page_title = 'Reset Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password · <?php echo e(salon_name()); ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💇‍♀️</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/style.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="auth-page">
    <div class="bubble-field" id="bubbleField"></div>

    <div class="auth-card">
        <a href="login.php" class="auth-logo"><i class="bi bi-shield-lock"></i></a>
        <h1 class="auth-title h3 text-center mb-1">Reset Password</h1>
        <p class="auth-subtitle text-center mb-4">Choose a new password for your account</p>

        <?php if ($error): ?>
            <div class="alert alert-danger rounded-3 shadow-sm py-2"><i class="bi bi-exclamation-circle me-2"></i><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($done): ?>
            <div class="alert alert-success rounded-3 shadow-sm">Password updated. <a href="login.php">Login now</a>.</div>
        <?php else: ?>
            <form method="post" action="reset-password.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo e($token); ?>">

                <div class="mb-3">
                    <label class="form-label" for="password">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-lock text-muted"></i></span>
                        <input type="password" class="form-control border-start-0" id="password" name="password"
                               minlength="8" required placeholder="At least 8 characters">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label" for="password_confirm">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-lock-fill text-muted"></i></span>
                        <input type="password" class="form-control border-start-0" id="password_confirm" name="password_confirm"
                               minlength="8" required placeholder="Repeat the password">
                    </div>
                </div>

                <button type="submit" class="btn btn-rose btn-lg w-100 rounded-pill py-2">
                    <i class="bi bi-check2-circle me-2"></i>Update Password
                </button>
            </form>
        <?php endif; ?>

        <div class="text-center mt-4">
            <a href="login.php" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Back to login</a>
        </div>
    </div>
</div>

<script>
    (function () {
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
        for (var i = 0; i < 28; i++) { makeBubble(); }
    })();
</script>
</body>
</html>
