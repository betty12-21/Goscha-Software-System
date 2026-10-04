<?php
/**
 * Beauty-php-ai — Tenant context.
 *
 * Single source of truth for "which salon is this request acting on".
 *
 * The salon id is NEVER read from a form field, query string or cookie. It is
 * resolved from the authenticated session, and the session value is itself
 * re-verified against `users.salon_id` on every request. That second check is
 * what stops a user whose account has been moved between salons from keeping
 * access to the old tenant's data through a stale session.
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

/**
 * Resolve the active salon id for this request.
 *
 * Order of precedence:
 *   1. an explicit id passed by trusted internal code (CLI tests, cron)
 *   2. $_SESSION['salon_id'], re-validated against the logged-in user
 *   3. NULL when unauthenticated
 *
 * @return int|null
 */
function current_salon_id(): ?int
{
    /* 1. trusted override, used by the CLI harness and internal jobs */
    $override = defined('FORCE_SALON_ID') ? (int)FORCE_SALON_ID : 0;
    if ($override > 0) {
        return $override;
    }

    /* No auth layer loaded (pure CLI suites and internal jobs). Treat the
       request as unauthenticated rather than fataling on a missing
       is_logged_in(), so scheduling.php can be exercised on its own. */
    if (!function_exists('is_logged_in') || !is_logged_in()) {
        return null;
    }

    /* 2. the session value, but only after confirming it still matches the
       user row. Reading users.salon_id every time is deliberate: it means a
       moved or deactivated account loses access on the very next request
       rather than whenever its session happens to expire. */
    $sessionSalon = (int)($_SESSION['salon_id'] ?? 0);
    $userSalon    = 0;

    try {
        $stmt = db()->prepare('SELECT salon_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int)$_SESSION['user_id']]);
        $userSalon = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $userSalon = 0;
    }

    if ($userSalon <= 0) {
        return null;
    }

    if ($sessionSalon !== $userSalon) {
        $_SESSION['salon_id'] = $userSalon;
    }

    return $userSalon;
}

/**
 * Like current_salon_id() but never null: falls back to the lowest-id salon.
 *
 * Only for pages that are genuinely public (public availability, public
 * booking). Those pages have no session, so they need a defined tenant to
 * read. Every authenticated back-office page must use current_salon_id()
 * instead so a missing tenant is treated as an error rather than silently
 * showing somebody else's records.
 *
 * @return int
 */
function default_salon_id(): int
{
    $id = current_salon_id();
    if ($id !== null && $id > 0) {
        return $id;
    }

    try {
        $stmt = db()->query('SELECT id FROM salons WHERE status = \'active\' ORDER BY id LIMIT 1');
        $found = (int)($stmt->fetchColumn() ?: 0);
        if ($found > 0) {
            return $found;
        }
    } catch (Throwable $e) {
        /* fall through */
    }

    return 0;
}

/**
 * The active salon row, or NULL.
 *
 * @return array<string,mixed>|null
 */
function current_salon(): ?array
{
    static $cache = [];
    static $loaded = [];

    $id = current_salon_id();
    if ($id === null || $id <= 0) {
        return null;
    }

    if (array_key_exists($id, $loaded)) {
        return $cache[$id];
    }

    try {
        $stmt = db()->prepare('SELECT * FROM salons WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $row = null;
    }

    $cache[$id]  = $row;
    $loaded[$id] = true;

    return $row;
}

/** Display name of the active salon. */
function current_salon_name(): string
{
    $salon = current_salon();

    return (string)($salon['salon_name'] ?? '');
}

/**
 * Hard gate for back-office pages.
 *
 * An authenticated user with no salon, or whose salon has been deactivated,
 * must not reach a module: without a tenant every query would silently fall
 * back to the default salon and leak another business's records.
 */
function require_salon_context(): int
{
    $id = current_salon_id();

    if ($id === null || $id <= 0) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "No salon context: set FORCE_SALON_ID or BEAUTY_DB_NAME for CLI work.\n");
            exit(4);
        }

        /* Session exists but has no tenant: sign out cleanly rather than
           serving unscoped data. */
        logout_user(true);
        flash_set('danger', 'Your account is not linked to a salon. Please sign in again.');
        redirect(base_url('login.php'));
    }

    $salon = current_salon();
    if (!$salon || ($salon['status'] ?? '') !== 'active') {
        logout_user(true);
        flash_set('danger', 'This salon is no longer active.');
        redirect(base_url('login.php'));
    }

    return $id;
}

/**
 * Does the active tenant own this row?
 *
 * The single primitive every ownership check is built from. A NULL id means
 * "no tenant could be resolved", which is treated as NOT owned so a broken
 * session fails closed instead of open.
 *
 * @param int|null $salonId Tenant to test against. Defaults to the
 *                          authenticated tenant. Pass default_salon_id()
 *                          explicitly from code that legitimately serves an
 *                          unauthenticated request (public availability,
 *                          the setup wizard, CLI suites) — otherwise the check
 *                          fails closed and rejects the tenant's own rows.
 */
function salon_owns(string $table, int $id, ?int $salonId = null): bool
{
    $salonId = $salonId ?? current_salon_id();

    if ($salonId === null || $salonId <= 0 || $id <= 0) {
        return false;
    }

    static $allowed = [
        'users', 'salon_settings', 'business_hours', 'business_breaks', 'settings', 'holidays',
        'service_categories', 'service_subcategories', 'services', 'staff', 'staff_working_hours',
        'staff_breaks', 'staff_services', 'customers', 'appointments', 'appointment_services',
        'waitlist', 'invoices', 'payments', 'suppliers', 'inventory_products', 'purchases',
        'product_sales', 'payroll', 'loyalty_ledger', 'notifications', 'audit_logs',
    ];

    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException("salon_owns(): table '{$table}' is not tenant-scoped.");
    }

    try {
        $stmt = db()->prepare("SELECT 1 FROM `{$table}` WHERE id = :id AND salon_id = :sid LIMIT 1");
        $stmt->execute(['id' => $id, 'sid' => $salonId]);

        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Fetch a row only if the active tenant owns it.
 *
 * @return array<string,mixed>|null
 */
function tenant_fetch(string $table, int $id, string $columns = '*'): ?array
{
    $salonId = current_salon_id();

    if ($salonId === null || $salonId <= 0 || $id <= 0) {
        return null;
    }

    try {
        $stmt = db()->prepare("SELECT {$columns} FROM `{$table}` WHERE id = :id AND salon_id = :sid LIMIT 1");
        $stmt->execute(['id' => $id, 'sid' => $salonId]);
        $row = $stmt->fetch();

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Reject a record that belongs to another salon.
 *
 * Used by every save/delete handler that receives an id from the browser.
 * Terminates the request with 403 so a forged URL cannot be mistaken for a
 * missing record.
 */
function require_tenant_record(string $table, int $id): void
{
    if (salon_owns($table, $id)) {
        return;
    }

    http_response_code(403);
    $name = htmlspecialchars($table, ENT_QUOTES, 'UTF-8');

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "403 cross-tenant access blocked: {$table} #{$id}\n");
        exit(5);
    }

    exit("403 Forbidden: this {$name} record does not belong to your salon.");
}

/** Drop cached tenant rows after a write (hours, profile, …). */
function forget_current_salon_cache(): void
{
    /* current_salon() caches per id in function-static arrays that cannot be
       cleared from outside, so the documented way to refresh is a redirect.
       This helper exists as the single place to change if that ever changes. */
}