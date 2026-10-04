<?php
/**
 * Beauty-php-ai — Role-based authorization.
 *
 * Two system roles exist: admin and receptionist.
 * Every protected page MUST verify the role server-side.
 * Hiding menu items is never enough.
 *
 * Permission model:
 *   - 'admin'        → implicit wildcard, every permission.
 *   - 'receptionist' → front-desk permissions only. Staff Management,
 *     Users, Settings, Payroll, Reports, Suppliers, Inventory and
 *     Audit Logs are NOT granted and are rejected server-side (403),
 *     even when the URL is entered manually.
 */

const ROLE_PERMISSIONS = [
    'admin' => ['*'],

    'receptionist' => [
        'dashboard',
        'customers',
        'appointments',
        'calendar',
        'walkins',
        'services',
        'payments',
        'invoices',
        'notifications',
    ],
];

/**
 * All permissions granted to a role ('*' means everything).
 */
function role_permissions(string $role): array
{
    return ROLE_PERMISSIONS[$role] ?? [];
}

/**
 * Check whether the current user may perform an action / open a module.
 */
function can(string $permission): bool
{
    $role = current_user_role();

    if ($role === null) {
        return false;
    }

    $granted = role_permissions($role);

    return in_array('*', $granted, true) || in_array($permission, $granted, true);
}

/**
 * TRUE when the signed-in user is an administrator.
 */
function is_admin(): bool
{
    return current_user_role() === 'admin';
}

/**
 * Dashboard URL for the current user's role.
 */
function home_url(): string
{
    return base_url(is_admin() ? 'admin/dashboard.php' : 'receptionist/dashboard.php');
}

/**
 * Redirect to login when not authenticated.
 */
function require_login(): void
{
    start_app_session();
    //check_session_timeout();
    try_remember_login();

    if (!is_logged_in()) {
        flash_set('warning', 'Please log in to continue.');
        redirect(base_url('login.php'));
    }
}

/**
 * Require a specific role, otherwise reject access (403).
 */
function require_role(string $role): void
{
    require_login();

    if (current_user_role() !== $role) {
        render_forbidden();
    }
}

/**
 * Require any of the given roles.
 */
function require_any_role(array $roles): void
{
    require_login();

    if (!in_array(current_user_role(), $roles, true)) {
        render_forbidden();
    }
}

/**
 * Require a specific permission.
 */
function require_permission(string $permission): void
{
    require_login();

    if (!can($permission)) {
        render_forbidden();
    }
}

/**
 * Render the 403 access-denied page and stop execution.
 */
function render_forbidden(): void
{
    http_response_code(403);
    log_activity('forbidden', 'auth', 'Access denied to ' . ($_SERVER['REQUEST_URI'] ?? 'unknown URL'));

    $page_title = 'Unauthorized Access';

    /* The 403 page renders standalone so it never leaks the app chrome. */
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo e($page_title); ?> · <?php echo e(salon_name()); ?></title>
        <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💇‍♀️</text></svg>">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <link href="<?php echo base_url('assets/css/style.css'); ?>" rel="stylesheet">
    </head>
    <body class="app-page">
        <div class="d-flex flex-column align-items-center justify-content-center text-center" style="min-height:100vh;">
            <div class="display-1 mb-3 text-danger fw-bold">403</div>
            <h1 class="h4 mb-2">Unauthorized Access</h1>
            <p class="text-muted mb-4">You do not have permission to access this page.<br>This attempt has been recorded.</p>
            <div>
                <a href="<?php echo e(home_url()); ?>" class="btn btn-dark rounded-pill px-4">Back to Dashboard</a>
                <a href="<?php echo e(base_url('logout.php')); ?>" class="btn btn-light rounded-pill px-4">Log out</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
