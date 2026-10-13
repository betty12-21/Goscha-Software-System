<?php
/**
 * Beauty-php-ai — Module access control.
 *
 * The platform operator (super admin) can switch individual modules on or
 * off per salon. This is a second, independent gate on top of the role
 * permissions in permissions.php:
 *
 *   role permission  → "may this ROLE ever see the module?"
 *   module permission → "has THIS salon been granted the module?"
 *
 * A page is reachable only when BOTH allow it. Because the module gate is
 * checked in require_login(), hiding a sidebar link is never the only
 * defence: a disabled module is refused server-side even when its URL is
 * typed in directly.
 *
 * Default-deny: `salon_modules` is authoritative. A module that has no row
 * for a salon is NOT granted. The only exception is a database that predates
 * migration 004 and therefore has no `salon_modules` table at all — there we
 * fall back to "everything on" so an un-migrated install keeps working.
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

/**
 * Every gateable module: key => [label, icon, tier].
 *
 * `tier` places the module in an access profile (see access_profiles()):
 *   1 = Standard, 2 = Professional, 3 = Full.
 * Adding a module here automatically makes it gateable and listed in the
 * Super Admin panel; nothing else needs to change.
 */
const ACCESS_MODULES = [
    'dashboard'     => ['label' => 'Dashboard',     'icon' => 'bi-grid',             'tier' => 1],
    'customers'     => ['label' => 'Customers',     'icon' => 'bi-people',           'tier' => 1],
    'appointments'  => ['label' => 'Appointments',  'icon' => 'bi-calendar2-check',  'tier' => 1],
    'calendar'      => ['label' => 'Calendar',      'icon' => 'bi-calendar3',        'tier' => 1],
    'walkins'       => ['label' => 'Walk-ins',      'icon' => 'bi-door-open',        'tier' => 1],
    'services'      => ['label' => 'Services',      'icon' => 'bi-scissors',         'tier' => 1],
    'payments'      => ['label' => 'Payments',      'icon' => 'bi-credit-card',      'tier' => 1],
    'invoices'      => ['label' => 'Invoices',      'icon' => 'bi-receipt',          'tier' => 1],
    'notifications' => ['label' => 'Notifications', 'icon' => 'bi-bell',             'tier' => 1],
    'staff'         => ['label' => 'Staff',         'icon' => 'bi-person-badge',     'tier' => 2],
    'reports'       => ['label' => 'Reports',       'icon' => 'bi-bar-chart-line',   'tier' => 2],
    'inventory'     => ['label' => 'Inventory',     'icon' => 'bi-box-seam',         'tier' => 3],
    'suppliers'     => ['label' => 'Suppliers',     'icon' => 'bi-truck',            'tier' => 3],
    'payroll'       => ['label' => 'Payroll',       'icon' => 'bi-cash-stack',       'tier' => 3],
    'settings'      => ['label' => 'Settings',      'icon' => 'bi-gear',             'tier' => 3],
];

/**
 * The purchasable access profiles, lowest tier first.
 *
 * A profile grants every module whose tier is <= the profile's tier, so the
 * tiers stay cumulative and a new tier-2 module is automatically part of
 * Professional and Full.
 */
function access_profiles(): array
{
    return [
        'standard' => [
            'label'       => 'Standard',
            'tier'        => 1,
            'description' => 'Front desk: customers, appointments, calendar, walk-ins, services, payments, invoices and notifications.',
        ],
        'professional' => [
            'label'       => 'Professional',
            'tier'        => 2,
            'description' => 'Everything in Standard plus Staff management and Reports.',
        ],
        'full' => [
            'label'       => 'Full',
            'tier'        => 3,
            'description' => 'Every module including Inventory, Suppliers, Payroll and Settings.',
        ],
    ];
}

/**
 * Is the named access profile valid?
 */
function access_profile_exists(string $profile): bool
{
    return array_key_exists($profile, access_profiles());
}

/**
 * Module keys, in display order.
 *
 * @return array<int,string>
 */
function module_keys(): array
{
    return array_keys(ACCESS_MODULES);
}

/**
 * Human label for a module key.
 */
function module_label(string $key): string
{
    return ACCESS_MODULES[$key]['label'] ?? ucwords(str_replace(['-', '_'], ' ', $key));
}

/**
 * Bootstrap icon class for a module key.
 */
function module_icon(string $key): string
{
    return ACCESS_MODULES[$key]['icon'] ?? 'bi-circle';
}

/**
 * The tier a module belongs to (1 = Standard, 2 = Professional, 3 = Full).
 */
function module_tier(string $key): int
{
    return (int)(ACCESS_MODULES[$key]['tier'] ?? 1);
}

/**
 * Every module a profile grants.
 *
 * @return array<int,string>
 */
function profile_modules(string $profile): array
{
    if (!access_profile_exists($profile)) {
        return [];
    }

    $tier = (int)access_profiles()[$profile]['tier'];

    return array_values(array_filter(
        module_keys(),
        static fn(string $key): bool => module_tier($key) <= $tier
    ));
}

/**
 * Does the `salon_modules` table exist?
 *
 * False means migration 004 has not been applied. Cached for the process.
 */
function access_modules_schema_ready(): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'salon_modules\''
        );
        $stmt->execute();
        $ready = ((int)$stmt->fetchColumn()) === 1;
    } catch (Throwable $e) {
        $ready = false;
    }

    return $ready;
}

/**
 * Invalidate the per-salon module cache after a write.
 *
 * Works the same way as forget_settings_cache(): a global generation counter
 * that enabled_modules() consults, because a function-static cache cannot be
 * reached from outside.
 */
function forget_module_cache(?int $salonId = null): void
{
    $GLOBALS['__module_cache_generation'] = module_cache_generation() + 1;
}

/**
 * Monotonic counter bumped by forget_module_cache().
 */
function module_cache_generation(): int
{
    return (int)($GLOBALS['__module_cache_generation'] ?? 0);
}

/**
 * The module keys enabled for a salon.
 *
 * @param int|null $salonId Defaults to the active tenant.
 * @return array<int,string>
 */
function enabled_modules(?int $salonId = null): array
{
    $salonId = $salonId ?? current_salon_id();

    /* No tenant resolved: nothing is enabled. Callers that legitimately have
       no session (SA panel) pass an explicit salon id. */
    if ($salonId === null || $salonId <= 0) {
        return [];
    }

    /* Pre-migration install: treat every module as on so the app keeps
       working until 004_super_admin.sql is applied. */
    if (!access_modules_schema_ready()) {
        return module_keys();
    }

    static $cache = [];

    $generation = module_cache_generation();

    if (!isset($cache[$salonId]) || ($cache[$salonId]['gen'] ?? -1) !== $generation) {
        $keys = [];
        try {
            $stmt = db()->prepare(
                'SELECT module_key FROM salon_modules WHERE salon_id = :sid AND enabled = 1'
            );
            $stmt->execute(['sid' => $salonId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
                $keys[] = (string)$key;
            }
        } catch (Throwable $e) {
            $keys = [];
        }

        $cache[$salonId] = ['gen' => $generation, 'keys' => $keys];
    }

    return $cache[$salonId]['keys'];
}

/**
 * Is one module enabled for a salon? Default-deny for a missing row.
 */
function module_enabled(string $key, ?int $salonId = null): bool
{
    return in_array($key, enabled_modules($salonId), true);
}

/**
 * Can the signed-in user reach a module?
 *
 * Combines the role permission with the salon's module grant. Keys that are
 * not gateable modules (e.g. 'audit-logs', which is role-only) fall through
 * to the role check alone.
 */
function can_access_module(string $key, ?int $salonId = null): bool
{
    if (!can($key)) {
        return false;
    }

    if (!array_key_exists($key, ACCESS_MODULES)) {
        return true;
    }

    return module_enabled($key, $salonId);
}

/**
 * Map a script basename to the module that gates it.
 *
 * Used by require_login() so every module page is refused server-side when
 * its module is disabled. Returns null for scripts that are not module pages
 * (public pages, setup, the SA panel, …), which are left to their own guards.
 */
function module_key_for_script(string $basename): ?string
{
    static $map = [
        'dashboard.php'     => 'dashboard',
        'customers.php'     => 'customers',
        'appointments.php'  => 'appointments',
        'calendar.php'      => 'calendar',
        'walkins.php'       => 'walkins',
        'services.php'      => 'services',
        'payments.php'      => 'payments',
        'invoices.php'      => 'invoices',
        'invoice-view.php'  => 'invoices',
        'inventory.php'     => 'inventory',
        'suppliers.php'     => 'suppliers',
        'payroll.php'       => 'payroll',
        'reports.php'       => 'reports',
        'notifications.php' => 'notifications',
        'staff.php'         => 'staff',
        'settings.php'      => 'settings',
        'availability.php'  => 'appointments',
    ];

    return $map[strtolower($basename)] ?? null;
}

/* ------------------------------------------------------------------
 * Writes (Super Admin panel)
 * ------------------------------------------------------------------ */

/**
 * Replace a salon's module grants with an explicit list.
 *
 * Every known module gets a row so the panel can show an explicit
 * enabled/disabled state rather than "missing means off". Unknown keys are
 * ignored.
 *
 * @param array<int,string> $enabled
 * @param int|null          $updatedBy super_admins.id
 */
function save_salon_modules(int $salonId, array $enabled, ?int $updatedBy = null): void
{
    if ($salonId <= 0) {
        return;
    }

    $enabled = array_values(array_intersect(array_map('strval', $enabled), module_keys()));

    $stmt = db()->prepare(
        'INSERT INTO salon_modules (salon_id, module_key, enabled, updated_by, updated_at)
         VALUES (:sid, :key, :enabled, :by, NOW())
         ON DUPLICATE KEY UPDATE enabled = :enabled2, updated_by = :by2, updated_at = NOW()'
    );

    foreach (module_keys() as $key) {
        $on = in_array($key, $enabled, true) ? 1 : 0;
        $stmt->execute([
            'sid'       => $salonId,
            'key'       => $key,
            'enabled'   => $on,
            'enabled2'  => $on,
            'by'        => $updatedBy,
            'by2'       => $updatedBy,
        ]);
    }

    forget_module_cache($salonId);
}

/**
 * Apply a named access profile to a salon and record it on the salon row.
 */
function apply_access_profile(int $salonId, string $profile, ?int $updatedBy = null): void
{
    if ($salonId <= 0 || !access_profile_exists($profile)) {
        return;
    }

    save_salon_modules($salonId, profile_modules($profile), $updatedBy);

    try {
        db()->prepare('UPDATE salons SET access_profile = :p, updated_at = NOW() WHERE id = :id')
           ->execute(['p' => $profile, 'id' => $salonId]);
    } catch (Throwable $e) {
        /* The module rows are the authority; the label is a convenience. */
    }
}

/**
 * Seed a newly-created salon with the default profile's modules.
 *
 * Called from registration so a fresh salon has explicit rows from day one.
 */
function seed_salon_modules(int $salonId, string $profile = 'full', ?int $updatedBy = null): void
{
    /* Pre-migration install: nothing to seed, and enabled_modules() already
       treats every module as on until the table exists. */
    if (!access_modules_schema_ready()) {
        return;
    }

    if (!access_profile_exists($profile)) {
        $profile = 'full';
    }

    save_salon_modules($salonId, profile_modules($profile), $updatedBy);
}
