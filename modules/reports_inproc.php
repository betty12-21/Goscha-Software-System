<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Reports (Administrator only).
 * Sales, appointments, customers, services, payments, inventory and payroll analytics
 * with date-range filtering.
 */

require_permission('reports');

$tab  = $_GET['view'] ?? 'sales';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to = date('Y-m-d'); }

function qstr(): string
{
    return '&from=' . urlencode($_GET['from'] ?? date('Y-m-01')) . '&to=' . urlencode($_GET['to'] ?? date('Y-m-d'));
}

/* ---------- Summary metrics ---------- */
$metrics = db()->prepare(
    'SELECT COUNT(*) AS appt_count,
            COALESCE(SUM(CASE WHEN a.status = "completed" THEN 1 ELSE 0 END),0) AS completed_count,
            COALESCE(SUM(CASE WHEN a.payment_status = "paid" THEN a.total_amount ELSE 0 END),0) AS revenue_paid
     FROM appointments a WHERE DATE(a.appointment_date) BETWEEN :from AND :to'
);
$metrics->execute(['from' => $from, 'to' => $to]);
$m = $metrics->fetch();

$custCount = (int)db()->query('SELECT COUNT(*) FROM customers')->fetchColumn();

/* ---------- Report data ---------- */
$data = [];

if ($tab === 'sales') {
    $stmt = db()->prepare(
        'SELECT DATE(p.payment_date) AS d, COUNT(*) AS c, COALESCE(SUM(p.amount),0) AS revenue
         FROM payments p WHERE p.status = "completed" AND DATE(p.payment_date) BETWEEN :from AND :to
         GROUP BY DATE(p.payment_date) ORDER BY d'
    );
    $stmt->execute(['from' => $from, 'to' => $to]);
    $data = $stmt->fetchAll();

    $salesPayments = (int)array_sum(array_column($data, 'c'));
    $salesRevenue  = (float)array_sum(array_column($data, 'revenue'));
    $salesDays     = count($data);
    $salesMaxDay   = $salesDays ? max(array_column($data, 'revenue')) : 0;
    $salesAvgDay   = $salesDays ? $salesRevenue / $salesDays : 0;
    $salesBestDay  = null;
    foreach ($data as $row) {
        if ((float)$row['revenue'] === (float)$salesMaxDay && $salesMaxDay > 0) {
            $salesBestDay = $row['d'];
            break;
        }
    }
} elseif ($tab === 'appointments') {
    $stmt = db()->prepare(
        'SELECT a.status, COUNT(*) AS c FROM appointments a
         WHERE DATE(a.appointment_date) BETWEEN :from AND :to GROUP BY a.status'
    );
    $stmt->execute(['from' => $from, 'to' => $to]);
    $data = $stmt->fetchAll();

    $daily = db()->prepare(
        'SELECT a.appointment_date AS d, COUNT(*) AS c, COALESCE(SUM(a.total_amount),0) AS rev
         FROM appointments a WHERE DATE(a.appointment_date) BETWEEN :from AND :to
         GROUP BY a.appointment_date ORDER BY a.appointment_date'
    );
    $daily->execute(['from' => $from, 'to' => $to]);
    $dailyData = $daily->fetchAll();
} elseif ($tab === 'customers') {
    $stmt = db()->prepare(
        'SELECT c.id, CONCAT(c.first_name, " ", c.last_name) AS name, c.phone, c.loyalty_points,
                COUNT(a.id) AS appts, COALESCE(SUM(a.total_amount),0) AS spent
         FROM customers c LEFT JOIN appointments a ON a.customer_id = c.id AND DATE(a.appointment_date) BETWEEN :from AND :to
         GROUP BY c.id ORDER BY spent DESC LIMIT 20'
    );
    $stmt->execute(['from' => $from, 'to' => $to]);
    $data = $stmt->fetchAll();

    $customersTotal     = count($data);
    $customersSpentSum  = (float)array_sum(array_column($data, 'spent'));
    $customersApptsSum  = (int)array_sum(array_column($data, 'appts'));
    $customersMaxSpent  = $customersTotal ? (float)max(array_column($data, 'spent')) : 0;
    $customersAvgSpent  = $customersTotal ? $customersSpentSum / $customersTotal : 0;
    $customersTopName   = null;
    $customersTopPoints = 0;
    foreach ($data as $row) {
        if ((float)$row['spent'] === $customersMaxSpent && $customersMaxSpent > 0) {
            $customersTopName   = $row['name'];
            $customersTopPoints = (int)$row['loyalty_points'];
            break;
        }
    }
} elseif ($tab === 'services') {
    $stmt = db()->prepare(
'SELECT s.name AS name, COUNT(*) AS qty, SUM(av.price) AS revenue
         FROM appointment_services av
         JOIN services s ON s.id = av.service_id
         JOIN appointments a ON a.id = av.appointment_id
         WHERE DATE(a.appointment_date) BETWEEN :from AND :to
         GROUP BY s.id, s.name ORDER BY revenue DESC LIMIT 20'
    );
$stmt->execute(['from' => $from, 'to' => $to]);
    $data = $stmt->fetchAll();

    $servicesRevenue = (float)array_sum(array_column($data, 'revenue'));
    $servicesQty     = (int)array_sum(array_column($data, 'qty'));
    $servicesCount   = count($data);
    $servicesMaxRev  = $servicesCount ? max(array_column($data, 'revenue')) : 0;
    $servicesTopName = $servicesCount > 0 ? $data[0]['name'] : null;
} elseif ($tab === 'payments') {
    $stmt = db()->prepare(
        'SELECT p.payment_method AS method, COUNT(*) AS c, COALESCE(SUM(p.amount),0) AS revenue
         FROM payments p WHERE p.status = "completed" AND DATE(p.payment_date) BETWEEN :from AND :to
         GROUP BY p.payment_method'
    );
    $stmt->execute(['from' => $from, 'to' => $to]);
    $data = $stmt->fetchAll();
} elseif ($tab === 'inventory') {
    $data = db()->query(
        'SELECT p.name, p.sku, p.quantity, p.minimum_stock, p.cost_price, p.selling_price,
                (p.quantity * p.cost_price) AS stock_value, s.name AS supplier_name
         FROM inventory_products p LEFT JOIN suppliers s ON s.id = p.supplier_id
         ORDER BY p.name'
    )->fetchAll();
    $inventoryValue = (float)db()->query('SELECT COALESCE(SUM(quantity * cost_price),0) FROM inventory_products')->fetchColumn();
} elseif ($tab === 'payroll') {
    $stmt = db()->prepare('SELECT pay_period, COUNT(*) AS entries, COALESCE(SUM(net_salary),0) AS total, SUM(CASE WHEN payment_status = "paid" THEN 1 ELSE 0 END) AS paid_count FROM payroll WHERE pay_period BETWEEN :from AND :to GROUP BY pay_period ORDER BY pay_period DESC');
    /* pay_period is YYYY-MM; compare against month of range */
    $stmt->execute(['from' => substr($from, 0, 7), 'to' => substr($to, 0, 7)]);
    $data = $stmt->fetchAll();
}

$page_title = 'Reports';
$active     = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<div class="card mb-3">
    <div class="card-body p-3">
        <form method="get" action="reports.php" class="d-flex flex-wrap gap-2 align-items-center">
            <input type="hidden" name="view" value="<?php echo e($tab); ?>">
            <label class="form-label mb-0 text-muted small">From</label>
            <input type="date" class="form-control" name="from" value="<?php echo e($from); ?>">
            <label class="form-label mb-0 text-muted small">To</label>
            <input type="date" class="form-control" name="to" value="<?php echo e($to); ?>">
            <button class="btn btn-charcoal rounded-pill px-4" type="submit"><i class="bi bi-funnel me-1"></i>Run Report</button>
            <a href="reports.php?view=<?php echo e($tab); ?>" class="btn btn-light rounded-pill">Reset</a>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="stat-card"><div class="stat-icon bg-rose-soft text-rose"><i class="bi bi-calendar-check"></i></div>
            <div><div class="stat-label">Appointments</div><div class="stat-value"><?php echo (int)$m['appt_count']; ?></div></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card"><div class="stat-icon bg-rose-soft text-rose"><i class="bi bi-check2-circle"></i></div>
            <div><div class="stat-label">Completed</div><div class="stat-value"><?php echo (int)$m['completed_count']; ?></div></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card"><div class="stat-icon bg-rose-soft text-rose"><i class="bi bi-cash-stack"></i></div>
            <div><div class="stat-label">Revenue (Paid)</div><div class="stat-value"><?php echo money($m['revenue_paid']); ?></div></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card"><div class="stat-icon bg-rose-soft text-rose"><i class="bi bi-people"></i></div>
            <div><div class="stat-label">Customers</div><div class="stat-value"><?php echo $custCount; ?></div></div></div>
    </div>
</div>

<ul class="nav nav-pills mb-3 gap-1 flex-wrap">
    <?php $tabs = ['sales' => 'Sales', 'appointments' => 'Appointments', 'customers' => 'Customers', 'services' => 'Services', 'payments' => 'Payments', 'inventory' => 'Inventory', 'payroll' => 'Payroll']; ?>
    <?php foreach ($tabs as $key => $label): ?>
        <li class="nav-item"><a class="nav-link <?php echo $tab === $key ? 'active' : ''; ?>" href="reports.php?view=<?php echo $key; ?><?php echo qstr(); ?>" style="<?php echo $tab === $key ? 'background:var(--bsai-rose);' : 'color:var(--bsai-charcoal-2);'; ?>"><?php echo $label; ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'sales'): ?>
    <div class="row g-3">
        <div class="col-6 col-lg-3">
            <div class="stat-card"><div class="stat-icon rose"><i class="bi bi-cash-stack"></i></div>
                <div><div class="stat-label">Total Revenue</div><div class="stat-value"><?php echo money($salesRevenue); ?></div></div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card"><div class="stat-icon gold"><i class="bi bi-credit-card"></i></div>
                <div><div class="stat-label">Payments</div><div class="stat-value"><?php echo $salesPayments; ?></div></div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card"><div class="stat-icon rose"><i class="bi bi-calendar-week"></i></div>
                <div><div class="stat-label">Avg per day</div><div class="stat-value"><?php echo money($salesAvgDay); ?></div></div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card"><div class="stat-icon gold"><i class="bi bi-trophy"></i></div>
                <div><div class="stat-label">Best day</div><div class="stat-value"><?php echo $salesBestDay ? date('M j', strtotime($salesBestDay)) : '—'; ?></div></div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span>Daily Revenue</span>
            <span class="badge bg-rose-soft text-rose rounded-pill"><?php echo $salesDays; ?> day(s) with sales</span>
        </div>
        <div class="card-body">
            <div class="chart-wrap" style="min-height:220px;"><canvas id="salesChart" height="90"></canvas></div>
            <div class="table-responsive mt-4">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Date</th><th class="text-end">Payments</th><th>Revenue</th><th class="text-end">Share</th></tr></thead>
                    <tbody>
                    <?php foreach ($data as $row):
                        $pct = $salesMaxDay > 0 ? round(((float)$row['revenue'] / $salesMaxDay) * 100) : 0;
                        $share = $salesRevenue > 0 ? round(((float)$row['revenue'] / $salesRevenue) * 100, 1) : 0;
                        $isBest = $salesBestDay && $row['d'] === $salesBestDay; ?>
                        <tr>
                            <td class="fw-semibold"><?php echo fmt_date($row['d']); ?><?php echo $isBest ? ' <i class="bi bi-trophy-fill text-gold ms-1" title="Best day"></i>' : ''; ?></td>
                            <td class="text-end"><?php echo (int)$row['c']; ?></td>
                            <td style="min-width:180px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height:8px;background:var(--bsai-blush);">
                                        <div class="progress-bar rounded-pill" role="progressbar" style="width:<?php echo $pct; ?>%;background:<?php echo $isBest ? 'var(--bsai-gold)' : 'var(--bsai-rose)'; ?>;"></div>
                                    </div>
                                    <span class="fw-semibold small text-nowrap"><?php echo money($row['revenue']); ?></span>
                                </div>
                            </td>
                            <td class="text-end text-muted small"><?php echo $share; ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$data): ?><tr><td colspan="4"><div class="empty-state"><div class="icon"><i class="bi bi-inbox"></i></div><p class="text-muted mb-0">No sales in this period.</p></div></td></tr><?php endif; ?>
                    </tbody>
                    <tfoot>
                    <tr class="table-light">
                        <td class="fw-bold">Total</td>
                        <td class="text-end fw-bold"><?php echo $salesPayments; ?></td>
                        <td class="fw-bold"><?php echo money($salesRevenue); ?></td>
                        <td></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'appointments'): ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card"><div class="card-header">By Status</div>
                <div class="card-body">
                    <canvas id="apptStatusChart" height="120"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card"><div class="card-header">Daily Volume & Revenue</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Date</th><th class="text-end">Appointments</th><th class="text-end">Value</th></tr></thead>
                            <tbody>
                            <?php foreach ($dailyData ?? [] as $row): ?>
                                <tr><td><?php echo fmt_date($row['d']); ?></td><td class="text-end"><?php echo (int)$row['c']; ?></td><td class="text-end fw-semibold"><?php echo money($row['rev']); ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($dailyData)): ?><tr><td colspan="3"><div class="empty-state"><p class="text-muted mb-0">No appointments in this period.</p></div></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'customers'): ?>
    <div class="card table-card"><div class="card-header">Top Customers</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>#</th><th>Customer</th><th>Phone</th><th class="text-end">Appointments</th><th class="text-end">Total Spent</th><th class="text-end">Loyalty Points</th></tr></thead>
                <tbody>
                <?php $i = 1; foreach ($data as $row): ?>
                    <tr>
                        <td class="text-muted"><?php echo $i++; ?></td>
                        <td class="fw-semibold"><?php echo e($row['name']); ?></td>
                        <td class="small"><?php echo e($row['phone']); ?></td>
                        <td class="text-end"><?php echo (int)$row['appts']; ?></td>
                        <td class="text-end fw-semibold"><?php echo money($row['spent']); ?></td>
                        <td class="text-end"><span class="badge bg-rose-soft text-rose rounded-pill"><?php echo (int)$row['loyalty_points']; ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$data): ?><tr><td colspan="6"><div class="empty-state"><p class="text-muted mb-0">No customers in this period.</p></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'services'): ?>
    <div class="row g-3">
        <div class="col-6 col-lg-3">
            <div class="stat-card"><div class="stat-icon rose"><i class="bi bi-cash-stack"></i></div>
                <div><div class="stat-label">Service Revenue</div><div class="stat-value"><?php echo money($servicesRevenue); ?></div></div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card"><div class="stat-icon gold"><i class="bi bi-bag-check"></i></div>
                <div><div class="stat-label">Units Sold</div><div class="stat-value"><?php echo $servicesQty; ?></div></div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card"><div class="stat-icon rose"><i class="bi bi-scissors"></i></div>
                <div><div class="stat-label">Services</div><div class="stat-value"><?php echo $servicesCount; ?></div></div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card"><div class="stat-icon gold"><i class="bi bi-trophy"></i></div>
                <div><div class="stat-label">Top service</div><div class="stat-value" style="font-size:1.05rem;"><?php echo e($servicesTopName ?? '—'); ?></div></div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span><i class="bi bi-scissors me-2 text-rose"></i>Most Popular Services</span>
            <span class="badge bg-rose-soft text-rose rounded-pill"><?php echo $servicesCount; ?> service(s)</span>
        </div>
        <div class="card-body">
            <div class="d-flex align-items-center gap-2 small text-muted">
                <i class="bi bi-info-circle me-1"></i>The wider the gold bar, the more revenue that service earned.
            </div>
            <div class="chart-wrap" style="min-height:220px;"><canvas id="servicesChart" height="90"></canvas></div>
            <div class="table-responsive mt-4">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Service</th><th class="text-end">Units Sold</th><th>Revenue</th><th class="text-end">Share</th></tr></thead>
                    <tbody>
                    <?php foreach ($data as $row):
                        $pct = $servicesMaxRev > 0 ? round(((float)$row['revenue'] / $servicesMaxRev) * 100) : 0;
                        $share = $servicesRevenue > 0 ? round(((float)$row['revenue'] / $servicesRevenue) * 100, 1) : 0;
                        $isBest = $data[0]['name'] === $row['name']; ?>
                        <tr>
                            <td class="fw-semibold"><?php echo e($row['name']); ?><?php echo $isBest ? ' <i class="bi bi-trophy-fill text-gold ms-1" title="Best seller"></i>' : ''; ?></td>
                            <td class="text-end"><?php echo (int)$row['qty']; ?></td>
                            <td style="min-width:180px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height:8px;background:var(--bsai-blush);">
                                        <div class="progress-bar rounded-pill" role="progressbar" style="width:<?php echo $pct; ?>%;background:<?php echo $isBest ? 'var(--bsai-gold)' : 'var(--bsai-rose)'; ?>;"></div>
                                    </div>
                                    <span class="fw-semibold small text-nowrap"><?php echo money($row['revenue']); ?></span>
                                </div>
                            </td>
                            <td class="text-end text-muted small"><?php echo $share; ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$data): ?><tr><td colspan="4"><div class="empty-state"><div class="icon"><i class="bi bi-inbox"></i></div><p class="text-muted mb-0">No service data in this period.</p></div></td></tr><?php endif; ?>
                    </tbody>
                    <tfoot>
                    <tr class="table-light">
                        <td class="fw-bold">Total</td>
                        <td class="text-end fw-bold"><?php echo $servicesQty; ?></td>
                        <td class="fw-bold"><?php echo money($servicesRevenue); ?></td>
                        <td></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'payments'): ?>
    <div class="card"><div class="card-header">Payments by Method</div>
        <div class="card-body">
            <canvas id="paymentsChart" height="90"></canvas>
            <div class="table-responsive mt-3">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Method</th><th class="text-end">Payments</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($data as $row): ?>
                        <tr><td class="fw-semibold"><?php echo e(ucwords(str_replace('_', ' ', $row['method']))); ?></td><td class="text-end"><?php echo (int)$row['c']; ?></td><td class="text-end fw-semibold"><?php echo money($row['revenue']); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$data): ?><tr><td colspan="3"><div class="empty-state"><p class="text-muted mb-0">No payment data in this period.</p></div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'inventory'): ?>
    <div class="mb-3">
        <span class="badge bg-rose-soft text-rose rounded-pill px-3 py-2"><i class="bi bi-box-seam me-1"></i>Inventory value: <?php echo money($inventoryValue); ?></span>
    </div>
    <div class="card table-card"><div class="card-header">Stock Summary</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Product</th><th>SKU</th><th>Supplier</th><th class="text-end">Stock</th><th class="text-end">Min</th><th class="text-end">Cost</th><th class="text-end">Selling</th><th class="text-end">Stock Value</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($data as $row): ?>
                    <tr <?php echo (int)$row['quantity'] <= (int)$row['minimum_stock'] ? 'class="table-warning"' : ''; ?>>
                        <td class="fw-semibold"><?php echo e($row['name']); ?></td>
                        <td class="small text-muted"><?php echo e($row['sku']); ?></td>
                        <td class="small"><?php echo e($row['supplier_name'] ?: '—'); ?></td>
                        <td class="text-end"><?php echo (int)$row['quantity']; ?></td>
                        <td class="text-end"><?php echo (int)$row['minimum_stock']; ?></td>
                        <td class="text-end"><?php echo money($row['cost_price']); ?></td>
                        <td class="text-end"><?php echo money($row['selling_price']); ?></td>
                        <td class="text-end fw-semibold"><?php echo money($row['stock_value']); ?></td>
                        <td><?php echo status_badge((int)$row['quantity'] <= (int)$row['minimum_stock'] ? 'low' : 'in_stock'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$data): ?><tr><td colspan="9"><div class="empty-state"><p class="text-muted mb-0">No inventory records.</p></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'payroll'): ?>
    <div class="card table-card"><div class="card-header">Payroll by Period</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Period</th><th class="text-end">Entries</th><th class="text-end">Paid</th><th class="text-end">Total Net Pay</th></tr></thead>
                <tbody>
                <?php foreach ($data as $row): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo e($row['pay_period']); ?></td>
                        <td class="text-end"><?php echo (int)$row['entries']; ?></td>
                        <td class="text-end"><?php echo (int)$row['paid_count']; ?></td>
                        <td class="text-end fw-semibold"><?php echo money($row['total']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$data): ?><tr><td colspan="4"><div class="empty-state"><p class="text-muted mb-0">No payroll in this period.</p></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($tab === 'sales'): ?>
    bsaiChart('salesChart', 'bar', <?php echo json_encode(array_column($data, 'd')); ?>, <?php echo json_encode(array_map(fn($r) => (float)$r['revenue'], $data)); ?>, { label: 'Revenue (<?php echo e(currency()); ?>)' });
    <?php elseif ($tab === 'appointments'): ?>
    bsaiChart('apptStatusChart', 'doughnut', <?php echo json_encode(array_map(fn($r) => ucwords(str_replace('_', ' ', $r['status'])), $data)); ?>, <?php echo json_encode(array_map(fn($r) => (int)$r['c'], $data)); ?>, {});
    <?php elseif ($tab === 'services'): ?>
    bsaiChart('servicesChart', 'bar', <?php echo json_encode(array_column($data, 'name')); ?>, <?php echo json_encode(array_map(fn($r) => (float)$r['revenue'], $data)); ?>, { label: 'Revenue (<?php echo e(currency()); ?>)' });
    <?php elseif ($tab === 'payments'): ?>
    bsaiChart('paymentsChart', 'doughnut', <?php echo json_encode(array_map(fn($r) => ucwords(str_replace('_', ' ', $r['method'])), $data)); ?>, <?php echo json_encode(array_map(fn($r) => (float)$r['revenue'], $data)); ?>, {});
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
