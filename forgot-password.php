<?php
/**
 * Beauty-php-ai — Forgot password.
 *
 * Note: SMS/email sending requires an external provider which is NOT configured,
 * so the reset link is shown on-screen instead of pretending an email was sent.
 */

require_once __DIR__ . '/includes/init.php';

$message = null;
$link    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $message = ['danger', 'Invalid security token. Please try again.'];
    } else {
        $email = trim($_POST['email'] ?? '');

        $stmt = db()->prepare('SELECT id, name FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            $token    = bin2hex(random_bytes(32));
            $expires  = date('Y-m-d H:i:s', time() + 3600);

            $stmt = db()->prepare(
                'INSERT INTO password_resets (user_id, token, expires_at) VALUES (:uid, :token, :expires)'
            );
            $stmt->execute(['uid' => $user['id'], 'token' => $token, 'expires' => $expires]);

            log_activity('password_reset_request', 'auth', "Password reset requested for {$user['name']}");

            $link    = 'reset-password.php?token=' . $token;
            $message = ['success', 'A password reset link has been generated.'];
        } else {
            $message = ['success', 'If that email address exists, a reset link will be available.'];
        }
    }
}

$page_title = 'Forgot Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password · <?php echo e(salon_name()); ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💇‍♀️</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/style.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="auth-page">
    <div class="bubble-field" id="bubbleField"></div>

    <div class="auth-card">
        <a href="login.php" class="auth-logo"><i class="bi bi-key"></i></a>
        <h1 class="auth-title h3 text-center mb-1">Forgot Password</h1>
        <p class="auth-subtitle text-center mb-4">Enter your account email to reset your password</p>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message[0]; ?> rounded-3 shadow-sm py-2">
                <?php echo e($message[1]); ?>
                <?php if ($link): ?>
                    <div class="mt-2 p-2 bg-light rounded small text-break">
                        Reset link (email delivery is not configured — please bookmark it):
                        <a href="<?php echo e($link); ?>"><?php echo e($link); ?></a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="forgot-password.php">
            <?php echo csrf_field(); ?>
            <div class="mb-3">
                <label class="form-label" for="email">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-envelope text-muted"></i></span>
                    <input type="email" class="form-control border-start-0" id="email" name="email" required
                           value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="you@example.com" autofocus>
                </div>
            </div>

            <button type="submit" class="btn btn-rose btn-lg w-100 rounded-pill py-2">
                <i class="bi bi-send me-2"></i>Request Reset Link
            </button>
        </form>

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
