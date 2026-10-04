<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Invoice view / print.
 * Accessible to both roles via the `invoices` permission.
 * Supports ?id= (invoice id) or ?appointment_id= (find invoice for appointment).
 */

require_permission('invoices');

$id = (int)($_GET['id'] ?? 0);
$appointmentId = (int)($_GET['appointment_id'] ?? 0);

if ($appointmentId) {
    $stmt = db()->prepare('SELECT * FROM invoices WHERE appointment_id = :id ORDER BY id DESC LIMIT 1');
    $stmt->execute(['id' => $appointmentId]);
    $invoice = $stmt->fetch();
} else {
    $stmt = db()->prepare('SELECT * FROM invoices WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $invoice = $stmt->fetch();
}

if (!$invoice) {
    flash_set('danger', 'Invoice not found.');
    redirect('invoices.php');
}

$stmt = db()->prepare('SELECT * FROM customers WHERE id = :id');
$stmt->execute(['id' => $invoice['customer_id']]);
$customer = $stmt->fetch() ?: ['first_name' => '—', 'last_name' => '', 'phone' => '', 'email' => ''];

$invoiceItems = [];
if ($invoice['appointment_id']) {
    $stmt = db()->prepare(
        'SELECT s.name AS service_name, aps.price AS service_price, 1 AS quantity, aps.duration_minutes,
                c.name AS category_name, sc.name AS subcategory_name
         FROM appointment_services aps
         LEFT JOIN services s ON s.id = aps.service_id
         LEFT JOIN service_categories c     ON c.id  = s.category_id
         LEFT JOIN service_subcategories sc ON sc.id = s.subcategory_id
         WHERE aps.appointment_id = :id'
    );
    $stmt->execute(['id' => $invoice['appointment_id']]);
    $invoiceItems = $stmt->fetchAll();
} elseif (!empty($invoice['description'])) {
    $invoiceItems = [[
        'service_name' => $invoice['description'], 'service_price' => $invoice['total'],
        'quantity' => 1, 'duration_minutes' => 0, 'category_name' => null, 'subcategory_name' => null,
    ]];
}

$paymentStmt = db()->prepare('SELECT * FROM payments WHERE appointment_id = :id AND status = "completed"');
$paymentStmt->execute(['id' => $invoice['appointment_id']]);
$payments = $paymentStmt->fetchAll();

$subtotal     = array_sum(array_map(fn($i) => (float)$i['service_price'] * (int)$i['quantity'], $invoiceItems));
$discount     = (float)$invoice['discount'];
$taxRate      = (float)get_setting('tax_rate', 0);
$tax          = round($subtotal * $taxRate / 100, 2);
$total        = round($subtotal - $discount + $tax, 2);
$currencyCode = trim((string)currency());
$fmtCurrency  = $currencyCode !== '' ? ' ' . e($currencyCode) : '';
$salon        = salon_name();
$salonInitial = mb_strtoupper(mb_substr($salon, 0, 1));
$taxLabel     = $taxRate > 0
    ? 'Tax (' . rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.') . '%)'
    : 'Tax';

$page_title = 'Invoice ' . $invoice['invoice_number'];
$active     = 'invoices';
include __DIR__ . '/../includes/header.php';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
.inv-page { max-width: 920px; margin: 0 auto; padding-bottom: 2rem; }

.inv-sheet {
    position: relative;
    background: #fff;
    border: 1px solid #eee1d2;
    border-radius: 24px;
    padding: clamp(1.7rem, 4vw, 3.2rem) clamp(1.4rem, 4vw, 3.4rem);
    box-shadow: 0 24px 60px rgba(60, 45, 40, .10), 0 4px 14px rgba(60, 45, 40, .06);
    overflow: hidden;
}
.inv-sheet::before {
    content: "";
    position: absolute; top: 0; left: 0; right: 0; height: 6px;
    background: linear-gradient(90deg, var(--bsai-rose) 0%, #a34a55 45%, var(--bsai-gold) 100%);
}
.inv-sheet::after {
    content: attr(data-initial);
    position: absolute; right: .04em; bottom: -.16em;
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 16rem; line-height: 1;
    color: rgba(201, 164, 76, .06);
    pointer-events: none; user-select: none;
}
.inv-sheet > * { position: relative; z-index: 1; }

.inv-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 2rem; flex-wrap: wrap; margin-bottom: 2.2rem;
}
.inv-brand { display: flex; align-items: center; gap: 1.05rem; }
.inv-logo {
    width: 66px; height: 66px; flex-shrink: 0;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 18px;
    background: linear-gradient(135deg, var(--bsai-rose) 0%, #8a3145 55%, var(--bsai-gold) 130%);
    color: #fff; font-size: 1.65rem;
    box-shadow: 0 12px 24px rgba(88, 5, 27, .28);
}
.inv-salon-name {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 1.6rem; font-weight: 700; color: var(--bsai-charcoal); line-height: 1.1;
}
.inv-tagline {
    font-size: .68rem; text-transform: uppercase; letter-spacing: 2.4px;
    color: var(--bsai-gold); font-weight: 600; margin-top: .2rem;
}
.inv-address { font-size: .82rem; color: var(--bsai-muted); margin-top: .3rem; }

.inv-meta { text-align: right; }
.inv-title {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 2.5rem; font-weight: 700; line-height: 1; color: var(--bsai-charcoal);
    letter-spacing: .6px;
}
.inv-title::after {
    content: ""; display: block; width: 74px; height: 3px; margin: .55rem 0 0 auto;
    border-radius: 3px; background: linear-gradient(90deg, var(--bsai-gold), var(--bsai-rose));
}
.inv-number {
    font-size: 1.02rem; font-weight: 700; letter-spacing: .6px;
    color: var(--bsai-rose); margin-top: .7rem;
}
.inv-issued { font-size: .78rem; color: var(--bsai-muted); margin-top: .15rem; letter-spacing: .3px; }
.inv-status { margin-top: .85rem; }

.inv-parties { display: grid; grid-template-columns: 1fr 1fr; gap: 1.15rem; margin-bottom: 1.9rem; }
.inv-party {
    background: linear-gradient(180deg, #fdfaf6, #faf5ee);
    border: 1px solid #f0e4d5;
    border-radius: 18px;
    padding: 1.15rem 1.3rem;
}
.inv-party-label {
    display: flex; align-items: center; gap: .45rem;
    font-size: .68rem; text-transform: uppercase; letter-spacing: 1.6px;
    color: var(--bsai-rose); font-weight: 700; margin-bottom: .55rem;
}
.inv-party-name { font-size: 1.08rem; font-weight: 700; color: var(--bsai-charcoal); }
.inv-party-line { font-size: .85rem; color: var(--bsai-muted); margin-top: .18rem; }

.inv-paylist { margin-top: .65rem; border-top: 1px dashed #e5d6c4; padding-top: .55rem; display: grid; gap: .4rem; }
.inv-pay-item { display: flex; justify-content: space-between; align-items: center; gap: .7rem; font-size: .8rem; color: #6a625f; }
.inv-pay-item .pay-method {
    font-size: .65rem; font-weight: 600; color: var(--bsai-rose-dark);
    background: var(--bsai-blush); border-radius: 50rem; padding: .1rem .5rem; margin-left: .3rem;
}
.inv-pay-item .pay-date { display: inline-flex; align-items: center; gap: .35rem; }
.inv-pay-item .amt { font-weight: 700; color: var(--bsai-rose); }

.inv-table { width: 100%; border-collapse: separate; border-spacing: 0; margin: 0; font-size: .92rem; }
.inv-table thead th {
    background: var(--bsai-charcoal); color: #fff;
    font-size: .68rem; text-transform: uppercase; letter-spacing: 1.4px; font-weight: 600;
    padding: .85rem 1rem; text-align: left; white-space: nowrap;
}
.inv-table thead th:first-child { border-radius: 14px 0 0 14px; }
.inv-table thead th:last-child { border-radius: 0 14px 14px 0; text-align: right; }
.inv-table tbody td { padding: .95rem 1rem; border-bottom: 1px solid #f1e7da; vertical-align: middle; }
.inv-table tbody tr:nth-child(even) { background: #fdfaf6; }
.inv-table tbody tr:last-child td { border-bottom: 0; }
.inv-table .ta-c { text-align: center; }
.inv-table .ta-r { text-align: right; }
    .inv-service { font-weight: 600; color: var(--bsai-charcoal); }
    .inv-service-path { font-size: 11px; color: #9b9491; margin-top: 1px; }

.inv-duration { color: var(--bsai-muted); }
.inv-amount { font-weight: 700; color: var(--bsai-rose); white-space: nowrap; }

.inv-totals { display: flex; justify-content: flex-end; margin-top: 1.5rem; }
.inv-totals-box { width: min(330px, 100%); }
.inv-total-row {
    display: flex; justify-content: space-between; align-items: baseline;
    gap: 1.2rem; padding: .5rem .1rem; font-size: .9rem; color: #4a4241;
    border-bottom: 1px dotted #e0d0bd;
}
.inv-total-row .val { font-weight: 600; }
.inv-grand {
    margin-top: 1rem; display: flex; justify-content: space-between; align-items: center; gap: 1.2rem;
    background: linear-gradient(135deg, var(--bsai-charcoal), #453a37);
    color: #fff; padding: 1.05rem 1.25rem; border-radius: 16px;
    box-shadow: 0 14px 28px rgba(44, 38, 37, .22);
}
.inv-grand .lbl { font-size: .75rem; text-transform: uppercase; letter-spacing: 1.8px; color: var(--bsai-gold-soft); font-weight: 700; }
.inv-grand .val { font-family: 'Playfair Display', Georgia, serif; font-size: 1.7rem; font-weight: 700; color: #fff; white-space: nowrap; }

.inv-footer { margin-top: 2.4rem; padding-top: 1.3rem; border-top: 1px solid #f0e4d6; text-align: center; }
.inv-divider { display: flex; align-items: center; justify-content: center; gap: .8rem; color: var(--bsai-gold); font-size: .8rem; margin-bottom: .95rem; }
.inv-divider span { height: 1px; width: 90px; background: linear-gradient(90deg, transparent, var(--bsai-gold)); }
.inv-divider span:last-child { background: linear-gradient(90deg, var(--bsai-gold), transparent); }
.inv-thanks {
    font-family: 'Playfair Display', Georgia, serif; font-style: italic;
    font-size: 1.12rem; color: var(--bsai-rose); font-weight: 600;
}
.inv-contact { margin-top: .75rem; font-size: .8rem; color: var(--bsai-muted); display: flex; justify-content: center; gap: .6rem 1.6rem; flex-wrap: wrap; }
.inv-contact span { display: inline-flex; align-items: center; gap: .4rem; color: inherit; }
.inv-contact i { color: var(--bsai-gold); }

@media (max-width: 640px) {
    .inv-parties { grid-template-columns: 1fr; }
    .inv-meta { text-align: left; }
    .inv-title::after { margin: .55rem 0 0; }
    .inv-logo { width: 54px; height: 54px; font-size: 1.35rem; }
    .inv-table thead th { font-size: .62rem; letter-spacing: 1px; padding: .7rem .6rem; }
    .inv-table tbody td { padding: .8rem .6rem; }
}

@media print {
    body { background: #fff !important; }
    .inv-page { max-width: 100%; margin: 0; padding: 0; }
    .inv-sheet { border: 0; border-radius: 0; padding: 0; box-shadow: none; }
    .inv-sheet::before, .inv-sheet::after { display: none; }
    .inv-table thead th,
    .inv-grand {
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .inv-table tbody tr:nth-child(even) { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<div class="inv-page">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <a href="invoices.php" class="btn btn-light rounded-pill"><i class="bi bi-arrow-left me-1"></i>Back to invoices</a>
        <button class="btn btn-rose rounded-pill px-4" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print Invoice</button>
    </div>

    <div class="print-area">
        <div class="inv-sheet" data-initial="<?php echo e($salonInitial); ?>">

            <header class="inv-header">
                <div class="inv-brand">
                    <div class="inv-logo"><i class="bi bi-scissors"></i></div>
                    <div>
                        <div class="inv-salon-name"><?php echo e($salon); ?></div>
                        <div class="inv-tagline"><?php echo e(get_setting('salon_tagline', 'Beauty &amp; Wellness')); ?></div>
                        <div class="inv-address"><i class="bi bi-geo-alt me-1"></i><?php echo e(get_setting('salon_address', '')); ?></div>
                    </div>
                </div>
                <div class="inv-meta">
                    <div class="inv-title">INVOICE</div>
                    <div class="inv-number"><?php echo e($invoice['invoice_number']); ?></div>
                    <div class="inv-issued">Issued: <?php echo fmt_datetime($invoice['issued_at']); ?></div>
                    <div class="inv-status"><?php echo status_badge($invoice['status']); ?></div>
                </div>
            </header>

            <div class="inv-parties">
                <div class="inv-party">
                    <div class="inv-party-label"><i class="bi bi-person-badge"></i>Billed To</div>
                    <div class="inv-party-name"><?php echo e($customer['first_name'] . ' ' . $customer['last_name']); ?></div>
                    <div class="inv-party-line"><i class="bi bi-telephone me-1"></i><?php echo e($customer['phone']); ?></div>
                    <?php if (!empty($customer['email'])): ?>
                        <div class="inv-party-line"><i class="bi bi-envelope me-1"></i><?php echo e($customer['email']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="inv-party">
                    <div class="inv-party-label"><i class="bi bi-receipt-cutoff"></i>Invoice Details</div>
                    <div class="inv-party-line d-flex justify-content-between">
                        <span>Appointment</span>
                        <span class="fw-semibold text-charcoal">#<?php echo $invoice['appointment_id'] ?: '—'; ?></span>
                    </div>
                    <div class="inv-party-line d-flex justify-content-between align-items-center">
                        <span>Payment status</span>
                        <span><?php echo status_badge($invoice['status']); ?></span>
                    </div>
                    <?php if ($payments): ?>
                        <div class="inv-paylist">
                            <?php foreach ($payments as $pay): ?>
                                <div class="inv-pay-item">
                                    <span class="pay-date"><i class="bi bi-check-circle-fill text-success"></i><?php echo fmt_datetime($pay['payment_date']); ?><span class="pay-method"><?php echo e(ucwords(str_replace('_', ' ', $pay['payment_method']))); ?></span></span>
                                    <span class="amt"><?php echo money($pay['amount']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <table class="inv-table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Duration</th>
                        <th class="ta-c">Qty</th>
                        <th class="ta-r">Unit Price</th>
                        <th class="ta-r">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$invoiceItems): ?>
                        <tr>
                            <td colspan="5" class="ta-c text-muted">No services recorded for this invoice.</td>
                        </tr>
                    <?php else: foreach ($invoiceItems as $item): ?>
                        <tr>
                            <td>
                                <span class="inv-service"><?php echo e($item['service_name'] ?: '—'); ?></span>
                                <?php if (!empty($item['category_name'])): ?>
                                    <div class="inv-service-path"><?php echo e($item['category_name']); ?><?php if (!empty($item['subcategory_name'])): ?> &middot; <?php echo e($item['subcategory_name']); ?><?php endif; ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="inv-duration"><?php echo (int)$item['duration_minutes'] ? minutes_to_duration((int)$item['duration_minutes']) : '—'; ?></td>
                            <td class="ta-c"><?php echo (int)$item['quantity']; ?></td>
                            <td class="ta-r"><?php echo money($item['service_price']); ?></td>
                            <td class="ta-r inv-amount"><?php echo money((float)$item['service_price'] * (int)$item['quantity']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <div class="inv-totals">
                <div class="inv-totals-box">
                    <div class="inv-total-row"><span>Subtotal</span><span class="val"><?php echo money($subtotal); ?></span></div>
                    <?php if ($discount > 0): ?>
                        <div class="inv-total-row"><span>Discount</span><span class="val">– <?php echo money($discount); ?></span></div>
                    <?php endif; ?>
                    <div class="inv-total-row"><span><?php echo e($taxLabel); ?></span><span class="val"><?php echo money($tax); ?></span></div>
                    <div class="inv-grand">
                        <span class="lbl">Total<span class="d-none d-sm-inline">&nbsp;Due</span></span>
                        <span class="val"><?php echo money($total) . $fmtCurrency; ?></span>
                    </div>
                </div>
            </div>

            <footer class="inv-footer">
                <div class="inv-divider"><span></span><i class="bi bi-gem"></i><span></span></div>
                <div class="inv-thanks">Thank you for visiting <?php echo e($salon); ?>!</div>
                <div class="inv-contact">
                    <?php if (get_setting('salon_phone', '')): ?>
                        <span><i class="bi bi-telephone-fill"></i><?php echo e(get_setting('salon_phone')); ?></span>
                    <?php endif; ?>
                    <?php if (get_setting('salon_email', '')): ?>
                        <span><i class="bi bi-envelope-fill"></i><?php echo e(get_setting('salon_email')); ?></span>
                    <?php endif; ?>
                    <?php if (get_setting('salon_address', '')): ?>
                        <span><i class="bi bi-geo-alt-fill"></i><?php echo e(get_setting('salon_address')); ?></span>
                    <?php endif; ?>
                </div>
            </footer>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>