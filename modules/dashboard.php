<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Dashboard module (role-aware).
 * Admin sees full analytics; Receptionist sees day-to-day operations.
 */

require_permission('dashboard');

$user = current_user();
$role = $user['role'];

/* ------------------------------------------------------------
 * Shared queries
 * ------------------------------------------------------------ */
function dash_one(string $sql): float
{
    $stmt = db()->query($sql);
    $row = $stmt->fetch();
    return (float)($row ? array_values($row)[0] : 0);
}

$todayAppointments = dash_one('SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status NOT IN ("cancelled","no_show")');
$todayRevenue      = dash_one('SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date) = CURDATE() AND status = "completed"');
$totalCustomers    = dash_one('SELECT COUNT(*) FROM customers');
$upcoming          = dash_one('SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND status IN ("pending","confirmed")');
$pendingPayments   = dash_one('SELECT COUNT(*) FROM appointments WHERE payment_status IN ("pending","partial")');
$completedCount    = dash_one('SELECT COUNT(*) FROM appointments WHERE status = "completed"');
$cancelledCount    = dash_one('SELECT COUNT(*) FROM appointments WHERE status = "cancelled"');
$monthRevenue      = dash_one('SELECT COALESCE(SUM(amount),0) FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE()) AND status = "completed"');
$lowStockCount     = dash_one('SELECT COUNT(*) FROM inventory_products WHERE quantity <= minimum_stock');

/* Staff summary (Administrator only) — never exposes salary or emergency contacts */
$totalStaff = $activeStaff = $inactiveStaff = 0;
$recentStaff = [];
try {
    $totalStaff    = (int)dash_one('SELECT COUNT(*) FROM staff');
    $activeStaff   = (int)dash_one("SELECT COUNT(*) FROM staff WHERE status = 'active'");
    $inactiveStaff = max(0, $totalStaff - $activeStaff);
    $recentStaff   = db()->query(
        'SELECT id, CONCAT(first_name, " ", last_name) AS name, status, created_at
         FROM staff ORDER BY created_at DESC, id DESC LIMIT 5'
    )->fetchAll();
} catch (Throwable $e) {
}

$todayList = [];
try {
    $stmt = db()->query(
        'SELECT a.*, CONCAT(c.first_name, " ", c.last_name) AS customer_name
         FROM appointments a JOIN customers c ON c.id = a.customer_id
         WHERE a.appointment_date = CURDATE()
         ORDER BY a.start_time'
    );
    $todayList = $stmt->fetchAll();
} catch (Throwable $e) {
}

if ($role === 'admin') {
    /* Chart data */
    $revenueTrend = [];
    $apptTrend    = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $revenueTrend[] = [
            'label' => date('D', strtotime($d)),
            'value' => dash_one("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date) = '$d' AND status = 'completed'"),
        ];
        $apptTrend[] = [
            'label' => date('D', strtotime($d)),
            'value' => dash_one("SELECT COUNT(*) FROM appointments WHERE appointment_date = '$d'"),
        ];
    }

    $popularServices = [];
    try {
        $stmt = db()->query(
            'SELECT s.name, COUNT(*) AS c FROM appointment_services aps
             JOIN services s ON s.id = aps.service_id
             GROUP BY s.name ORDER BY c DESC LIMIT 6'
        );
        $popularServices = $stmt->fetchAll();
    } catch (Throwable $e) {
    }

    $paymentMethods = [];
    try {
        $stmt = db()->query(
            "SELECT payment_method, COALESCE(SUM(amount),0) AS total FROM payments
             WHERE status = 'completed' GROUP BY payment_method"
        );
        $paymentMethods = $stmt->fetchAll();
    } catch (Throwable $e) {
    }

    $lowStockList = [];
    try {
        $stmt = db()->query(
            'SELECT * FROM inventory_products WHERE quantity <= minimum_stock ORDER BY quantity ASC LIMIT 6'
        );
        $lowStockList = $stmt->fetchAll();
    } catch (Throwable $e) {
    }
}

$page_title = 'Dashboard';
$active     = 'dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4" >
    <?php if ($role === 'admin'): ?>
        <div class="col-6 col-lg-3"><div class="stat-card">
            <span class="stat-icon rose"><i class="bi bi-calendar2-check"></i></span>
            <div><div class="stat-label">Today's Appointments</div><div class="stat-value"><?php echo (int)$todayAppointments; ?></div></div>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card">
            <span class="stat-icon gold"><i class="bi bi-cash-coin"></i></span>
            <div><div class="stat-label">Today's Revenue</div><div class="stat-value"><?php echo money($todayRevenue); ?></div></div>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card">
            <span class="stat-icon green"><i class="bi bi-people"></i></span>
            <div><div class="stat-label">Total Customers</div><div class="stat-value"><?php echo (int)$totalCustomers; ?></div></div>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card">
            <span class="stat-icon blue"><i class="bi bi-calendar2-week"></i></span>
            <div><div class="stat-label">Upcoming Appointments</div><div class="stat-value"><?php echo (int)$upcoming; ?></div></div>
        </div></div>

        <div class="col-6 col-lg-3"><div class="stat-card">
            <span class="stat-icon red"><i class="bi bi-credit-card-2-front"></i></span>
            <div><div class="stat-label">Pending Payments</div><div class="stat-value"><?php echo (int)$pendingPayments; ?></div></div>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card">
            <span class="stat-icon rose"><i class="bi bi-check2-circle"></i></span>
            <div><div class="stat-label">Completed</div><div class="stat-value"><?php echo (int)$completedCount; ?></div></div>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card">
            <span class="stat-icon blue"><i class="bi bi-x-circle"></i></span>
            <div><div class="stat-label">Cancelled</div><div class="stat-value"><?php echo (int)$cancelledCount; ?></div></div>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card">
            <span class="stat-icon red"><i class="bi bi-exclamation-triangle"></i></span>
            <div><div class="stat-label">Low Stock Alerts</div><div class="stat-value"><?php echo (int)$lowStockCount; ?></div></div>
        </div></div>

        <div class="col-6 col-lg-3"><a href="staff.php" class="text-decoration-none"><div class="stat-card">
            <span class="stat-icon blue"><i class="bi bi-person-badge"></i></span>
            <div><div class="stat-label">Total Staff</div><div class="stat-value"><?php echo $totalStaff; ?></div></div>
        </div></a></div>
        <div class="col-6 col-lg-3"><a href="staff.php?status=active" class="text-decoration-none"><div class="stat-card">
            <span class="stat-icon green"><i class="bi bi-person-check"></i></span>
            <div><div class="stat-label">Active Staff</div><div class="stat-value"><?php echo $activeStaff; ?></div></div>
        </div></a></div>
        <div class="col-6 col-lg-3"><a href="staff.php?status=inactive" class="text-decoration-none"><div class="stat-card">
            <span class="stat-icon gold"><i class="bi bi-person-x"></i></span>
            <div><div class="stat-label">Inactive Staff</div><div class="stat-value"><?php echo $inactiveStaff; ?></div></div>
        </div></a></div>
    <?php else: ?>
        <div class="col-6 col-lg-3"><div class="stat-card">
            <span class="stat-icon rose"><i class="bi bi-calendar2-check"></i></span>
            <div><div class="stat-label">Today's Appointments</div><div class="stat-value"><?php echo (int)$todayAppointments; ?></div></div>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card">
            <span class="stat-icon gold"><i class="bi bi-cash-coin"></i></span>
            <div><div class="stat-label">Today's Payments</div><div class="stat-value"><?php echo money($todayRevenue); ?></div></div>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card">
            <span class="stat-icon green"><i class="bi bi-people"></i></span>
            <div><div class="stat-label">Total Customers</div><div class="stat-value"><?php echo (int)$totalCustomers; ?></div></div>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card">
            <span class="stat-icon red"><i class="bi bi-hourglass-split"></i></span>
            <div><div class="stat-label">Pending Appointments</div><div class="stat-value"><?php echo (int)$pendingPayments; ?></div></div>
        </div></div>
    <?php endif; ?>
</div>

<?php if ($role === 'admin' && $recentStaff): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person-badge me-2 text-muted"></i>Today Active Staff</span>
                <a href="staff.php" class="btn btn-sm btn-soft rounded-pill">Manage Staff <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
            <div class="card-body py-2">
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($recentStaff as $rs): ?>
                        <div class="d-flex align-items-center gap-2">
                            <span class="avatar" style="width:32px;height:32px;font-size:.75rem;"><?php echo e(initials($rs['name'])); ?></span>
                            <div>
                                <a href="<?php echo can('staff') ? 'staff.php?action=view&id=' . (int)$rs['id'] : '#'; ?>" class="fw-semibold small text-body text-decoration-none"><?php echo e($rs['name']); ?></a>
                                <div><?php echo status_badge($rs['status']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Today's Appointments</span>
                <a href="appointments.php" class="btn btn-sm btn-soft rounded-pill">View all <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr><th class="ps-3">Time</th><th>Customer</th><th>Services</th><th>Status</th><th class="pe-3">Payment</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$todayList): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No appointments scheduled for today.</td></tr>
                    <?php else: foreach ($todayList as $appt): ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?php echo fmt_time($appt['start_time']); ?></td>
                            <td><?php echo e($appt['customer_name']); ?></td>
                            <td class="text-muted small">
                                <?php
                                $stmt = db()->prepare('SELECT s.name FROM appointment_services aps JOIN services s ON s.id = aps.service_id WHERE aps.appointment_id = :id');
                                $stmt->execute(['id' => $appt['id']]);
                                $names = array_column($stmt->fetchAll(), 'name');
                                echo e(implode(', ', $names ?: ['—']));
                                ?>
                            </td>
                            <td><?php echo status_badge($appt['status']); ?></td>
                            <td class="pe-3"><?php echo status_badge($appt['payment_status']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if ($role === 'admin'): ?>
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Low Stock Alerts</span>
                    <a href="inventory.php" class="btn btn-sm btn-soft rounded-pill">Inventory</a>
                </div>
                <div class="card-body">
                    <?php if (!$lowStockList): ?>
                        <div class="empty-state py-4">
                            <div class="icon" style="font-size:2rem;"><i class="bi bi-box-seam"></i></div>
                            <p class="text-muted mb-0">All products are sufficiently stocked.</p>
                        </div>
                    <?php else: foreach ($lowStockList as $p): ?>
                        <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                            <div>
                                <div class="fw-semibold small"><?php echo e($p['name']); ?></div>
                                <div class="small text-muted">SKU: <?php echo e($p['sku']); ?></div>
                            </div>
                            <span class="badge badge-status bg-danger">LOW STOCK · <?php echo (int)$p['quantity']; ?> left</span>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="card h-100">
                <div class="card-header">Quick Actions</div>
                <div class="card-body d-flex flex-column gap-2">
                    <a href="customers.php?action=add" class="btn btn-soft rounded-pill"><i class="bi bi-person-plus me-2"></i>Quick Add Customer</a>
                    <a href="appointments.php?action=create" class="btn btn-soft rounded-pill"><i class="bi bi-calendar2-plus me-2"></i>Quick Book Appointment</a>
                    <a href="walkins.php" class="btn btn-soft rounded-pill"><i class="bi bi-door-open me-2"></i>Quick Walk-In</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($role === 'admin'): ?>
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Revenue Trend (last 7 days)</div>
            <div class="card-body" style="height:280px;"><canvas id="revChart"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Appointment Trend (last 7 days)</div>
            <div class="card-body" style="height:280px;"><canvas id="apptChart"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">Popular Services</div>
            <div class="card-body" style="height:280px;"><canvas id="svcChart"></canvas></div>
        </div>
    </div>
   
   <!-- <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Payment Methods</div>
            <div class="card-body" style="height:280px;"><canvas id="payChart"></canvas></div>
        </div>
    </div> -->
    
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($role === 'admin'): ?>
        bsaiChart('revChart', 'line', <?php echo json_encode(array_column($revenueTrend, 'label')); ?>, <?php echo json_encode(array_map(fn($r) => (float)$r['value'], $revenueTrend)); ?>, { label: 'Revenue (<?php echo e(currency()); ?>)' });
        bsaiChart('apptChart', 'bar', <?php echo json_encode(array_column($apptTrend, 'label')); ?>, <?php echo json_encode(array_map(fn($r) => (int)$r['value'], $apptTrend)); ?>, { label: 'Appointments' });
        bsaiChart('svcChart', 'bar', <?php echo json_encode(array_column($popularServices, 'name')); ?>, <?php echo json_encode(array_map(fn($r) => (int)$r['c'], $popularServices)); ?>, { label: 'Bookings' });
        bsaiChart('payChart', 'doughnut', <?php echo json_encode(array_column($paymentMethods, 'payment_method')); ?>, <?php echo json_encode(array_map(fn($r) => (float)$r['total'], $paymentMethods)); ?>, { label: 'Amount' });
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
