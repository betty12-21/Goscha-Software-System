<?php
/**
 * Beauty-php-ai — Sidebar navigation (role-aware).
 * Expects: $active
 *
 * Each item: permission key => [label, icon, adminOnly].
 * Items are shown ONLY when the current user's role grants the
 * matching permission (see includes/permissions.php). The Receptionist
 * therefore never sees Staff Management, Users, Payroll, etc.
 */

$user = current_user();
$isAdmin = is_admin();
$base = $isAdmin ? 'admin' : 'receptionist';

$items = [
    'dashboard'    => ['Dashboard', 'bi-grid', false],
    'customers'    => ['Customers', 'bi-people', false],
    'staff'        => ['Staff', 'bi-person-badge', true],
    'appointments' => ['Appointments', 'bi-calendar2-check', false],
    'calendar'     => ['Calendar', 'bi-calendar3', false],
  //  'walkins'      => ['Walk-ins', 'bi-door-open', false],
    'services'     => ['Services', 'bi-scissors', false],
    'payments'     => ['Payments', 'bi-credit-card', false],
    'invoices'     => ['Invoices', 'bi-receipt', false],
    'inventory'    => ['Inventory', 'bi-box-seam', true],
    'suppliers'    => ['Suppliers', 'bi-truck', true],
    'payroll'      => ['Payroll', 'bi-cash-stack', true],
    'reports'      => ['Reports', 'bi-bar-chart-line', true],
    'notifications'=> ['Notifications', 'bi-bell', false],
    'users'        => ['Users', 'bi-person-gear', true],
    'audit-logs'   => ['Audit Logs', 'bi-journal-text', true],
    'settings'     => ['Settings', 'bi-gear', true],
];

?>
<aside class="app-sidebar" id="appSidebar">
    <div class="sidebar-header d-flex align-items-center justify-content-between d-lg-none">
        <span class="fw-semibold">Menu</span>
        <button class="btn btn-link text-body p-0" id="sidebarClose" type="button"><i class="bi bi-x-lg"></i></button>
    </div>

    <div class="sidebar-label small text-uppercase">Main Menu</div>
    <ul class="sidebar-nav list-unstyled mb-4">
        <?php foreach ($items as $key => $item):
            [$label, $icon, $adminOnly] = $item;

            /* Server-side permission check — never render a link the
               current role is not allowed to open. */
            if ($adminOnly && !$isAdmin) {
                continue;
            }
            if (!can($key)) {
                continue;
            }

            $url   = base_url($base . '/' . $key . '.php');
            $class = ($active === $key) ? 'active' : '';
            ?>
            <li>
                <a href="<?php echo $url; ?>" class="<?php echo $class; ?>">
                    <i class="bi <?php echo $icon; ?>"></i>
                    <span><?php echo e($label); ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="sidebar-footer mt-auto">
        <a href="<?php echo base_url('logout.php'); ?>" class="btn btn-sm btn-outline-danger rounded-pill w-100">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
    </div>
</aside>
