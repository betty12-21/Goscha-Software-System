<?php
/**
 * Beauty-php-ai — First-time Setup Wizard.
 *
 * Creates the salon profile, the weekly opening hours with breaks, and the
 * very first Administrator account, in one go. Everything is written in a
 * single transaction: a failure leaves the installation exactly as it was,
 * so the wizard can simply be submitted again.
 *
 * The wizard only runs while `salon_settings.setup_completed_at` is NULL.
 * An upgraded database carries that marker, so an existing installation can
 * never be pushed back through setup by accident.
 *
 * signup.php includes this file when the salon is not configured yet, so the
 * admin sign-up has a public address of its own. Set $setup_action before
 * including so the form posts back to the page that hosted it.
 */

require_once __DIR__ . '/includes/init.php';

if (salon_profile_is_configured()) {
    flash_set('info', 'This salon is already set up.');
    redirect(is_logged_in() ? home_url() : base_url('login.php'));
}

$errors   = [];
$existingAdmins = admin_user_count();

$defaults = [
    'salon_name'        => '',
    'owner_first_name'  => '',
    'owner_last_name'   => '',
    'first_name'        => '',
    'last_name'         => '',
    'email'             => '',
    'phone'             => '',
];

/* A blank week the owner can adjust: Mon–Sat open, Sunday closed. */
$defaultWeek = [];
for ($day = 1; $day <= 6; $day++) {
    $defaultWeek[$day] = ['is_open' => 1, 'opening_time' => '09:00:00', 'closing_time' => '18:00:00'];
}
$defaultWeek[7] = ['is_open' => 0, 'opening_time' => null, 'closing_time' => null];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        foreach ($defaults as $key => $_) {
            $defaults[$key] = trim((string)($_POST[$key] ?? ''));
        }

        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['password_confirm'] ?? '');

        $posted = $_POST['setup_hours'] ?? [];
        $week   = [];

        foreach (scheduling_days() as $day => $label) {
            $row          = $posted[$day] ?? [];
            $isOpen       = !empty($row['is_open']);
            $week[$day]   = [
                'is_open'      => $isOpen,
                'opening_time' => $isOpen ? ($row['opening_time'] ?? '') : null,
                'closing_time' => $isOpen ? ($row['closing_time'] ?? '') : null,
            ];
        }

        if ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }

        /* Step 1 — the week, validated before anything is written. */
        $weekErrors = business_hours_validate_week($week);

        if ($weekErrors) {
            $errors = array_merge($errors, $weekErrors);
        }

        /* Step 2 — the salon identity fields, also validated up front so an
           obviously incomplete form never opens a transaction. */
        foreach (['salon_name' => 'Salon name', 'owner_first_name' => 'Owner first name',
                  'owner_last_name' => 'Owner last name'] as $field => $label) {
            if ($defaults[$field] === '') {
                $errors[] = $label . ' is required.';
            }
        }

        /* Step 3 — every write below shares ONE transaction. The salon profile,
           the Administrator account, the weekly hours and the breaks are either
           all persisted or none of them are. A failure must never leave an
           Administrator without a salon, or a salon without its schedule. */
        if (!$errors) {
            $pdo = db();

            try {
                $pdo->beginTransaction();

                $profile = salon_profile_save([
                    'salon_name'        => $defaults['salon_name'],
                    'owner_first_name'  => $defaults['owner_first_name'],
                    'owner_last_name'   => $defaults['owner_last_name'],
                ]);

                if (!$profile['ok']) {
                    throw new RuntimeException(implode(' ', $profile['errors']));
                }

                $account = create_admin_account([
                    'first_name' => $defaults['first_name'],
                    'last_name'  => $defaults['last_name'],
                    'email'      => $defaults['email'],
                    'phone'      => $defaults['phone'],
                    'password'   => $password,
                ]);

                if (!$account['success']) {
                    throw new RuntimeException((string)($account['error'] ?? 'The Administrator account could not be created.'));
                }

                $saved = business_hours_save($week);

                if (!$saved['ok']) {
                    throw new RuntimeException(implode(' ', $saved['errors']));
                }

                foreach (scheduling_days() as $day => $label) {
                    $breaks = $_POST['setup_hours_breaks'][$day] ?? [];

                    if (is_array($breaks) && $breaks) {
                        $breakResult = business_breaks_save((int)$day, $breaks);

                        if (!$breakResult['ok']) {
                            throw new RuntimeException(implode(' ', $breakResult['errors']));
                        }
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = 'Setup could not be completed: ' . $e->getMessage();
            }
        }

        if (!$errors) {
            salon_setup_mark_complete();

            $login = attempt_login($defaults['email'], $password);

            flash_set(
                'success',
                $login['success']
                    ? 'Setup complete. Welcome to ' . salon_name() . '!'
                    : 'Setup complete. Please sign in with the Administrator account you just created.'
            );

            redirect($login['success'] ? home_url() : base_url('login.php'));
        }
    }
}

/* Re-render the posted week so nothing the owner typed is lost. */
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['setup_hours'] ?? []) : [];
$weekView  = [];

foreach (scheduling_days() as $day => $label) {
    if ($submitted) {
        $row        = $submitted[$day] ?? [];
        $isOpen     = !empty($row['is_open']);
        $weekView[$day] = [
            'is_open'      => $isOpen,
            'opening_time' => $isOpen ? substr((string)($row['opening_time'] ?? ''), 0, 5) : '',
            'closing_time' => $isOpen ? substr((string)($row['closing_time'] ?? ''), 0, 5) : '',
        ];
    } else {
        $row            = $defaultWeek[$day];
        $weekView[$day] = [
            'is_open'      => (bool)$row['is_open'],
            'opening_time' => $row['opening_time'] ? substr((string)$row['opening_time'], 0, 5) : '',
            'closing_time' => $row['closing_time'] ? substr((string)$row['closing_time'], 0, 5) : '',
        ];
    }
}

$breakView = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['setup_hours_breaks'] ?? []) : [];

$page_title = 'First-Time Setup';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup · <?php echo e(salon_name()); ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💇‍♀️</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/style.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="auth-page">
    <div class="auth-card" style="max-width:920px;width:100%;">

        <div class="text-center mb-4">
            <span class="auth-logo"><i class="bi bi-scissors"></i></span>
            <h1 class="auth-title h3 mt-3 mb-1">Let's set up your salon</h1>
            <p class="auth-subtitle">Three short steps. You can change every one of them later in Settings.</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger rounded-3 shadow-sm py-2">
                <div class="fw-semibold mb-1"><i class="bi bi-exclamation-circle me-2"></i>Setup could not be completed</div>
                <ul class="mb-0 ps-4 small">
                    <?php foreach ($errors as $err): ?>
                        <li><?php echo e($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($existingAdmins > 0): ?>
            <div class="alert alert-warning rounded-3 shadow-sm py-2 small">
                <i class="bi bi-info-circle me-2"></i>
                This database already has <?php echo (int)$existingAdmins; ?> Administrator
                account<?php echo $existingAdmins === 1 ? '' : 's'; ?>. Finishing the wizard creates
                <strong>one more</strong> — no existing account is changed or removed.
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo e($setup_action ?? 'setup.php'); ?>" novalidate>
            <?php echo csrf_field(); ?>

            <!-- ============ STEP 1 — SALON ============ -->
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="step-num">1</span>
                        <div>
                            <h2 class="h6 fw-bold mb-0">Salon Information</h2>
                            <div class="small text-muted">Salon name and business details.</div>
                        </div>
                    </div>

                      <div class="mb-3">
                          <label class="form-label fw-semibold" for="salon_name">Salon Name</label>
                          <input type="text" class="form-control" id="salon_name" name="salon_name" maxlength="150"
                                 value="<?php echo e($defaults['salon_name']); ?>" placeholder="Company name" required autofocus>
                          <div class="form-text">The name of your own salon or company.</div>
                      </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="owner_first_name">Owner First Name</label>
                            <input type="text" class="form-control" id="owner_first_name" name="owner_first_name" maxlength="100"
                                   value="<?php echo e($defaults['owner_first_name']); ?>" placeholder="First name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="owner_last_name">Owner Last Name</label>
                            <input type="text" class="form-control" id="owner_last_name" name="owner_last_name" maxlength="100"
                                   value="<?php echo e($defaults['owner_last_name']); ?>" placeholder="Last name" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ STEP 2 — ADMIN ============ -->
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="step-num">2</span>
                        <div>
                            <h2 class="h6 fw-bold mb-0">Administrator Account</h2>
                            <div class="small text-muted">This account signs in to the back office.</div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="first_name">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" maxlength="100"
                                   value="<?php echo e($defaults['first_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="last_name">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" maxlength="100"
                                   value="<?php echo e($defaults['last_name']); ?>" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="email">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?php echo e($defaults['email']); ?>" placeholder="you@example.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="phone">Phone <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="tel" class="form-control" id="phone" name="phone"
                                   value="<?php echo e($defaults['phone']); ?>" placeholder="+251 9XX XXX XXX">
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="password">Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control border-end-0" id="password" name="password"
                                       placeholder="Min 8 characters" required>
                                <button class="btn btn-white border-start-0" type="button" id="togglePass" tabindex="-1">
                                    <i class="bi bi-eye text-muted" id="togglePassIcon"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="password_confirm">Confirm Password</label>
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                                   placeholder="Re-enter" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ STEP 3 — HOURS ============ -->
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="step-num">3</span>
                        <div>
                            <h2 class="h6 fw-bold mb-0">Weekly Opening Hours</h2>
                            <div class="small text-muted">Appointments can only be booked inside these hours.</div>
                        </div>
                    </div>

                    <div class="hours-grid-host">
                        <?php render_weekly_hours_grid($weekView, $breakView, 'setup_hours'); ?>
                    </div>

                    <p class="small text-muted mt-2 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Staff schedules are set per staff member afterwards, in
                        <strong>Staff → Working Hours</strong>. Any staff member without a
                        schedule yet keeps working the hours shown here.
                    </p>
                </div>
            </div>

            <button type="submit" class="btn btn-rose btn-lg w-100 rounded-pill py-2">
                <i class="bi bi-check2-circle me-2"></i>Complete Setup
            </button>

            <p class="text-center small text-muted mt-3 mb-0">
                You will be signed in automatically once setup finishes.
            </p>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        'use strict';

        var toggle = document.getElementById('togglePass');
        var pass   = document.getElementById('password');
        var icon   = document.getElementById('togglePassIcon');

        if (toggle && pass && icon) {
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
