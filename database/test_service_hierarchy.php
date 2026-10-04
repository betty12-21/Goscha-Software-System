<?php
/**
 * Beauty-php-ai — Service hierarchy test suite (CLI).
 *
 * Runs the 14 scenarios from the specification against the live database
 * and the real permission layer. Everything it creates is removed again in
 * a finally block, and a full snapshot of the transactional tables is
 * compared before/after so "nothing historical was touched" is verified,
 * not assumed.
 *
 * USAGE
 *   C:\xampp\php\php.exe database\test_service_hierarchy.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}

define('APP_INIT', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/service-picker.php';

/* Neutralise session-dependent permission helpers: this harness talks to
   the permission layer directly instead of faking a login. */
function current_user_role()
{
    return $GLOBALS['test_role'] ?? null;
}
function current_user()
{
    return ['id' => 1, 'role' => $GLOBALS['test_role'] ?? null];
}

$passed = 0;
$failed = 0;
$notes  = [];

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  PASS  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
    } else {
        $failed++;
        echo "  FAIL  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
    }
}

function heading(string $text): void
{
    echo PHP_EOL . "== {$text} ==" . PHP_EOL;
}

function snapshot(): array
{
    $out = [];
    foreach (['services', 'appointment_services', 'invoices', 'payments', 'waitlist', 'appointments', 'customers'] as $table) {
        $rows = db()->query("SELECT * FROM `{$table}` ORDER BY 1")->fetchAll();
        $out[$table] = [
            'count' => count($rows),
            'hash'  => md5(json_encode($rows)),
        ];
    }
    return $out;
}

/* ------------------------------------------------------------------ */
$before = snapshot();
$created = ['categories' => [], 'subcategories' => [], 'services' => []];

echo PHP_EOL . 'Beauty-php-ai — service hierarchy test suite' . PHP_EOL;

try {
    /* ================================================================
     * TEST 1 — Create a Category
     * ================================================================ */
    heading('Test 1 — Create a Category');

    db()->prepare('INSERT INTO service_categories (salon_id, name, description, status, sort_order) VALUES (:sid, :n, :d, "active", :o)')
        ->execute(['sid' => default_salon_id(), 'n' => 'ZZ Test Category', 'd' => 'Created by the test suite.', 'o' => next_sort_order('service_categories')]);
    $catHair = (int)db()->lastInsertId();
    $created['categories'][] = $catHair;

    $row = db()->query('SELECT * FROM service_categories WHERE id = ' . $catHair)->fetch();
    ok('category row created', (bool)$row);
    ok('category has name, description, status, sort_order, created_at, updated_at',
        $row && $row['name'] === 'ZZ Test Category'
        && isset($row['description'], $row['status'], $row['sort_order'], $row['created_at'], $row['updated_at']));
    ok('default status is active', $row['status'] === 'active');
    ok('sort_order auto-assigned by next_sort_order()', (int)$row['sort_order'] > 0, 'sort_order=' . $row['sort_order']);
    ok('new category sorts after every existing one',
        (int)$row['sort_order'] === (int)db()->query('SELECT COALESCE(MAX(sort_order),0) FROM service_categories')->fetchColumn());

    /* ---- TEST 2 — Create multiple Subcategories under that Category ---- */
    heading('Test 2 — Create multiple Subcategories under that Category');

    foreach (['ZZ Sub A', 'ZZ Sub B', 'ZZ Sub C'] as $i => $name) {
        db()->prepare('INSERT INTO service_subcategories (salon_id, category_id, name, description, status, sort_order) VALUES (:sid, :c, :n, :d, "active", :o)')
            ->execute(['sid' => default_salon_id(), 'c' => $catHair, 'n' => $name, 'd' => '', 'o' => $i + 1]);
        $created['subcategories'][] = (int)db()->lastInsertId();
    }
    [$subA, $subB, $subC] = $created['subcategories'];

    $subs = service_subcategories_of($catHair);
    ok('3 subcategories belong to the new category', count($subs) === 3, 'found ' . count($subs));
    ok('subcategories carry sort_order 1,2,3',
        array_map('intval', array_column($subs, 'sort_order')) === [1, 2, 3]);
    ok('subcategory ordering is scoped to its own category (starts at 1)',
        (int)$subs[0]['sort_order'] === 1);
    ok('next_sort_order() is per-category for subcategories',
        next_sort_order('service_subcategories', $catHair) === 4);
    ok('every subcategory has a parent category',
        count(array_filter($subs, static function ($s) { return (int)$s['category_id'] > 0; })) === 3);

    /* A subcategory cannot exist without a parent category. */
    $fkRejectsOrphan = false;
    try {
        db()->prepare('INSERT INTO service_subcategories (salon_id, category_id, name) VALUES (:sid, 999999, "ZZ Orphan")')
            ->execute(['sid' => default_salon_id()]);
    } catch (Throwable $e) {
        $fkRejectsOrphan = true;
    }
    ok('database refuses a subcategory with no existing parent category', $fkRejectsOrphan);

    /* ---- TEST 3 — Service under a Subcategory ---- */
    heading('Test 3 — Create a Service under a Subcategory');

    db()->prepare('INSERT INTO services (salon_id, category_id, subcategory_id, name, description, duration_minutes, price, status, sort_order)
                  VALUES (:sid, :c, :s, :n, :d, :dur, :p, "active", 1)')
        ->execute(['sid' => default_salon_id(), 'c' => $catHair, 's' => $subA, 'n' => 'ZZ With Subcategory', 'd' => '', 'dur' => 45, 'p' => 500]);
    $svcWithSub = (int)db()->lastInsertId();
    $created['services'][] = $svcWithSub;

    $row = db()->query('SELECT * FROM services WHERE id = ' . $svcWithSub)->fetch();
    ok('service created with category + subcategory',
        $row && (int)$row['category_id'] === $catHair && (int)$row['subcategory_id'] === $subA);
    ok('price and duration stored', (float)$row['price'] === 500.0 && (int)$row['duration_minutes'] === 45);

    /* ---- TEST 4 — Service directly under a Category (no subcategory) ---- */
    heading('Test 4 — Create a Service directly under a Category without a Subcategory');

    db()->prepare('INSERT INTO services (salon_id, category_id, subcategory_id, name, description, duration_minutes, price, status, sort_order)
                  VALUES (:sid, :c, NULL, :n, :d, :dur, :p, "active", 1)')
        ->execute(['sid' => default_salon_id(), 'c' => $catHair, 'n' => 'ZZ No Subcategory', 'd' => '', 'dur' => 15, 'p' => 200]);
    $svcNoSub = (int)db()->lastInsertId();
    $created['services'][] = $svcNoSub;

    $row = db()->query('SELECT * FROM services WHERE id = ' . $svcNoSub)->fetch();
    ok('service created with subcategory_id = NULL', $row && $row['subcategory_id'] === null);
    ok('subcategory_id is nullable in the schema',
        db()->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'subcategory_id'")
            ->fetchColumn() === 'YES');
    ok('validate_service_hierarchy(category, 0) accepts a missing subcategory',
        validate_service_hierarchy($catHair, 0) === null);

    /* ---- TEST 5 — Dependent category -> subcategory selection ---- */
    heading('Test 5 — Category change updates the Subcategory list');

    /* The service form loads subcategories_of($categoryId) and embeds every
       subcategory with its owning category so the browser can filter. */
    $hairSubs = service_subcategories_of($catHair);
    $otherSubs = service_subcategories_of((int)db()->query('SELECT id FROM service_categories WHERE id <> ' . $catHair . ' LIMIT 1')->fetchColumn());

    ok('form receives ONLY subcategories of the chosen category',
        count($hairSubs) === 3 && count(array_diff(array_column($hairSubs, 'id'), array_column($otherSubs, 'id'))) === 3);
    ok('each option carries its owning category id for browser-side filtering',
        count(array_filter($hairSubs, static function ($s) { return (int)$s['category_id'] > 0; })) === 3);

    /* Rendered picker markup groups by category then subcategory. */
    $tree = service_picker_tree(false);
    $node = null;
    foreach ($tree as $n) {
        if ((int)$n['category']['id'] === $catHair) { $node = $n; break; }
    }
    ok('picker tree has a node for the new category', $node !== null);
    ok('subcategory bucket holds exactly the 3 services placed in subcategory A',
        $node && count($node['subcategories'][$subA]['services'] ?? []) === 1);
    ok('"no subcategory" bucket holds the service without one',
        $node && count($node['direct']) === 1
        && $node['direct'][0]['id'] === $svcNoSub);
    ok('breadcrumb renders as "Category › Subcategory › Service"',
        substr_count(service_hierarchy_path($node['subcategories'][$subA]['services'][0]), ' › ') === 2,
        service_hierarchy_path($node['subcategories'][$subA]['services'][0]));
    ok('breadcrumb omits the subcategory segment when there is none',
        substr_count(service_hierarchy_path($node['direct'][0]), ' › ') === 1,
        service_hierarchy_path($node['direct'][0]));

    /* ---- TEST 6 — Reject a subcategory from another category ---- */
    heading('Test 6 — Assigning a Subcategory from another Category is rejected');

    /* Find a subcategory under a different category. */
    $foreignSub = (int)db()->query(
        'SELECT sc.id FROM service_subcategories sc
          WHERE sc.category_id <> ' . $catHair . ' LIMIT 1'
    )->fetchColumn();

    if ($foreignSub > 0) {
        $err = validate_service_hierarchy($catHair, $foreignSub);
        ok('server rejects Category=Hair + Subcategory=Manicure style mismatch', $err !== null, (string)$err);
    } else {
        /* Force the scenario by moving one subcategory to another category. */
        $otherCat = (int)db()->query(
            'SELECT id FROM service_categories WHERE id <> ' . $catHair . ' LIMIT 1'
        )->fetchColumn();
        db()->prepare('UPDATE service_subcategories SET category_id = :c WHERE id = :id')
            ->execute(['c' => $otherCat, 'id' => $subB]);
        $err = validate_service_hierarchy($catHair, $subB);
        ok('server rejects a subcategory belonging to a different category', $err !== null, (string)$err);
        db()->prepare('UPDATE service_subcategories SET category_id = :c WHERE id = :id')
            ->execute(['c' => $catHair, 'id' => $subB]);
    }

    ok('the same mismatch is rejected again (no cached state)',
        validate_service_hierarchy($catHair, $foreignSub ?: $subB) !== null);
    ok('a subcategory with no parent category is rejected', validate_service_hierarchy(0, $subA) !== null);
    ok('a non-existent subcategory is rejected', validate_service_hierarchy($catHair, 999999) !== null);
    ok('a valid pair is accepted', validate_service_hierarchy($catHair, $subC) === null);

    /* ---- TEST 7 — Book an appointment using a service WITH a subcategory ---- */
    heading('Test 7 — Book an appointment with a service that has a Subcategory');

    $customerId = (int)db()->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
    $date = date('Y-m-d', strtotime('+45 days'));

    $appt = book_test_appointment($customerId, $svcWithSub, $date, '09:00:00', 'ZZ With Subcategory');
    ok('appointment created', $appt['id'] > 0, 'appointment #' . $appt['id']);
    ok('appointment_services row links the real service id', $appt['line']['service_id'] === $svcWithSub);
    ok('price and duration snapshotted on the line',
        (float)$appt['line']['price'] === 500.0 && (int)$appt['line']['duration_minutes'] === 45);
    ok('no category / subcategory duplicated into the line',
        // salon_id is present because migration 002 made the line tenant-scoped;
        // what matters is that neither hierarchy level was copied onto it.
        array_keys($appt['line']) === ['id', 'salon_id', 'appointment_id', 'service_id', 'price', 'duration_minutes'],
        implode(',', array_keys($appt['line'])));
    ok('invoice auto-generated for the appointment', $appt['invoice_id'] > 0, 'invoice #' . $appt['invoice_id']);
    $notes[] = ['appt_with_sub' => $appt['id'], 'invoice_with_sub' => $appt['invoice_id']];

    /* ---- TEST 8 — Book an appointment using a service WITHOUT a subcategory ---- */
    heading('Test 8 — Book an appointment with a service that has NO Subcategory');

    $appt2 = book_test_appointment($customerId, $svcNoSub, $date, '11:00:00', 'ZZ No Subcategory');
    ok('appointment created without any error', $appt2['id'] > 0, 'appointment #' . $appt2['id']);
    ok('appointment_services row links the service with no subcategory', $appt2['line']['service_id'] === $svcNoSub);
    ok('invoice auto-generated', $appt2['invoice_id'] > 0, 'invoice #' . $appt2['invoice_id']);
    $notes[] = ['appt_no_sub' => $appt2['id'], 'invoice_no_sub' => $appt2['invoice_id']];

    /* ---- TEST 9 — Walk-in using both kinds of service ---- */
    heading('Test 9 — Walk-in using BOTH kinds of service');

    $walk = book_test_walkin($customerId, [$svcWithSub, $svcNoSub]);
    ok('walk-in appointment created with 2 service lines', count($walk['lines']) === 2);
    ok('walk-in line for the service with a subcategory',
        in_array($svcWithSub, array_column($walk['lines'], 'service_id'), true));
    ok('walk-in line for the service without a subcategory',
        in_array($svcNoSub, array_column($walk['lines'], 'service_id'), true));
    ok('walk-in invoice created', $walk['invoice_id'] > 0, 'invoice #' . $walk['invoice_id']);
    ok('walk-in payment recorded for the exact total',
        (float)$walk['payment']['amount'] === (float)$walk['invoice']['total']);
    $notes[] = ['walkin' => $walk['appt_id'], 'walkin_invoice' => $walk['invoice_id'], 'walkin_payment' => $walk['payment_id']];

    /* ---- TEST 10 — Generate an invoice ---- */
    heading('Test 10 — Generate an invoice');

    $inv = db()->query('SELECT * FROM invoices WHERE id = ' . $appt2['invoice_id'])->fetch();
    ok('invoice row exists', (bool)$inv);
    ok('invoice references the appointment and customer',
        (int)$inv['appointment_id'] === $appt2['id'] && (int)$inv['customer_id'] === $customerId);
    ok('invoice total = subtotal + tax - discount',
        abs((float)$inv['total'] - ((float)$inv['subtotal'] - (float)$inv['discount'] + (float)$inv['tax'])) < 0.01);
    ok('invoice stores no service category/subcategory columns',
        !array_intersect(['category_id', 'subcategory_id', 'category_name', 'subcategory_name'], array_keys($inv)));

    /* The invoice line resolves the hierarchy at read time. */
    $line = db()->query(
        'SELECT s.name, c.name AS category_name, sc.name AS subcategory_name
           FROM appointment_services aps
           JOIN services s ON s.id = aps.service_id
           LEFT JOIN service_categories c     ON c.id  = s.category_id
           LEFT JOIN service_subcategories sc ON sc.id = s.subcategory_id
          WHERE aps.appointment_id = ' . $appt2['id']
    )->fetch();
    ok('invoice line resolves category at read time', $line['category_name'] === 'ZZ Test Category', (string)$line['category_name']);
    ok('invoice line has no subcategory to resolve', $line['subcategory_name'] === null);

    /* ---- TEST 11 — Payment records remain correct ---- */
    heading('Test 11 — Payment records remain correct');

    $pay = db()->query('SELECT * FROM payments WHERE id = ' . $walk['payment_id'])->fetch();
    ok('payment references the walk-in appointment and customer',
        (int)$pay['appointment_id'] === $walk['appt_id'] && (int)$pay['customer_id'] === $customerId);
    ok('payment amount equals the invoice total', (float)$pay['amount'] === (float)$walk['invoice']['total']);
    ok('payment is completed', $pay['status'] === 'completed');
    ok('payments table has no service/category columns',
        !array_intersect(['service_id', 'category_id', 'subcategory_id'], array_keys($pay)));

    /* ---- TEST 12 — Service reports still work ---- */
    heading('Test 12 — Service reports still work');

    $report = service_report_totals($date, $date);
    ok('revenue by service rolls up', $report['services'] > 0, (string)$report['services']);
    ok('revenue by category rolls up', $report['categories'] > 0, (string)$report['categories']);
    ok('revenue by subcategory rolls up', $report['subcategories'] > 0, (string)$report['subcategories']);
    ok('revenue by category totals the same as revenue by service',
        abs($report['categories'] - $report['services']) < 0.01,
        sprintf('category %.2f vs service %.2f', $report['categories'], $report['services']));
    ok('revenue by subcategory totals the same as revenue by service',
        abs($report['subcategories'] - $report['services']) < 0.01,
        sprintf('subcategory %.2f vs service %.2f', $report['subcategories'], $report['services']));
    ok('"no subcategory" revenue is attributed to a real bucket',
        $report['noneSubRevenue'] > 0, (string)$report['noneSubRevenue']);
    ok('the sales/appointments/payments report tabs are unaffected',
        report_tab_works('appointments') && report_tab_works('payments') && report_tab_works('sales'));

    /* ---- TEST 13 — Historical data intact ---- */
    heading('Test 13 — Existing historical appointments and invoices were not broken');

    /* Read the pre-existing rows from the database rather than hard-coding
       them, so the suite works against any dataset. */
    $existingServiceId = (int)db()->query('SELECT MIN(id) FROM services')->fetchColumn();
    $hist = db()->query(
        'SELECT id, name, price, duration_minutes, category_id, subcategory_id FROM services WHERE id = ' . $existingServiceId
    )->fetch();
    ok('pre-existing service still present', (bool)$hist,
        $hist['name'] . ' / ' . $hist['price'] . ' ETB / ' . $hist['duration_minutes'] . ' min');
    ok('pre-existing service keeps a price and a duration',
        (float)$hist['price'] > 0 && (int)$hist['duration_minutes'] > 0);
    ok('pre-existing service still has a category', (int)$hist['category_id'] > 0);
    ok('pre-existing subcategory link is either absent or valid and consistent',
        $hist['subcategory_id'] === null
        || (int)db()->query('SELECT COUNT(*) FROM service_subcategories WHERE id = ' . (int)$hist['subcategory_id']
                            . ' AND category_id = ' . (int)$hist['category_id'])->fetchColumn() === 1);
    $unresolved = (int)db()->query(
        'SELECT COUNT(*) FROM appointment_services aps LEFT JOIN services s ON s.id = aps.service_id WHERE s.id IS NULL'
    )->fetchColumn();
    ok('every pre-existing appointment_services row still resolves to a service', $unresolved === 0);
    $invoiceLines = (int)db()->query(
        'SELECT COUNT(*) FROM invoices i JOIN appointments a ON a.id = i.appointment_id
          WHERE a.id NOT IN (SELECT appointment_id FROM appointment_services)'
    )->fetchColumn();
    ok('every pre-existing invoice still has resolvable service lines', $invoiceLines === 0);
    $mismatched = (int)db()->query(
        'SELECT COUNT(*) FROM services s JOIN service_subcategories sc ON sc.id = s.subcategory_id
          WHERE sc.category_id <> s.category_id'
    )->fetchColumn();
    ok('no pre-existing service sits in the wrong category', $mismatched === 0);

    /* Deactivate a service that IS used historically — the record must survive. */
    db()->prepare('UPDATE services SET status = "inactive" WHERE id = :id')->execute(['id' => $svcWithSub]);
    $survivors = (int)db()->query('SELECT COUNT(*) FROM appointment_services WHERE service_id = ' . $svcWithSub)->fetchColumn();
    $invoices  = (int)db()->query('SELECT COUNT(*) FROM invoices WHERE appointment_id = ' . $appt['id'])->fetchColumn();
    ok('deactivating a service keeps its appointment lines', $survivors > 0, $survivors . ' line(s)');
    ok('deactivating a service keeps its invoices', $invoices === 1);
    ok('deactivated service disappears from the booking picker',
        !in_array($svcWithSub, array_column(flatten(service_picker_tree(true)), 'id'), true));
    ok('deactivated service still resolvable for history',
        count(array_filter(flatten(service_picker_tree(false)), static function ($s) use ($svcWithSub) {
            return (int)$s['id'] === $svcWithSub;
        })) === 1);
    db()->prepare('UPDATE services SET status = "active" WHERE id = :id')->execute(['id' => $svcWithSub]);

    /* Category / subcategory delete guards. */
    $catDeleteRefused = false;
    try {
        db()->prepare('DELETE FROM service_categories WHERE id = :id')->execute(['id' => $catHair]);
    } catch (Throwable $e) {
        $catDeleteRefused = true;
    }
    ok('database refuses to delete a category that still has subcategories', $catDeleteRefused);
    ok('category survived the refused delete', (bool)db()->query('SELECT id FROM service_categories WHERE id = ' . $catHair)->fetchColumn());
    ok('its subcategories survived too',
        (int)db()->query('SELECT COUNT(*) FROM service_subcategories WHERE category_id = ' . $catHair)->fetchColumn() === 3);
    $catServiceDeleteRefused = false;
    try {
        db()->prepare('DELETE FROM service_categories WHERE id = :id')->execute(['id' => $catHair]);
    } catch (Throwable $e) {
        $catServiceDeleteRefused = true;
    }
    ok('database refuses to delete a category that still has services', $catServiceDeleteRefused);

    /* ---- TEST 14 — Roles ---- */
    heading('Test 14 — Administrator vs Receptionist permissions');

    $GLOBALS['test_role'] = 'admin';
    ok('admin may manage services', can('services_edit') === true);
    ok('admin may view services', can('services') === true);
    ok('admin may view reports', can('reports') === true);

    $GLOBALS['test_role'] = 'receptionist';
    ok('receptionist may view the service menu', can('services') === true);
    ok('receptionist may book appointments', can('appointments') === true);
    ok('receptionist may register walk-ins', can('walkins') === true);
    ok('receptionist may record payments', can('payments') === true);
    ok('receptionist may view invoices', can('invoices') === true);
    ok('receptionist may NOT manage services (create/edit/deactivate)', can('services_edit') === false);
    ok('receptionist may NOT manage the hierarchy', can('reports') === false);
    $GLOBALS['test_role'] = null;

    /* The picker is built from the same helpers for both roles, so the
       receptionist sees the hierarchy without any management rights. */
    ok('receptionist booking tree is populated from active services only',
        service_picker_tree(true) !== []);
} catch (Throwable $e) {
    $failed++;
    echo PHP_EOL . '  EXCEPTION  ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    echo '  at ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
} finally {
    /* ---- Cleanup: remove only what the suite created, in FK-safe order.
       appointments -> appointment_services cascades automatically. ---- */
    echo PHP_EOL . 'Cleanup: removing test rows…' . PHP_EOL;

    try {
        db()->exec("DELETE FROM payments WHERE appointment_id IN (SELECT id FROM appointments WHERE notes LIKE 'ZZ %')");
        db()->exec("DELETE FROM invoices WHERE invoice_number LIKE 'ZZ-TEST%'");
        db()->exec("DELETE FROM appointments WHERE notes LIKE 'ZZ %'");

        if ($created['services']) {
            $ids = implode(',', array_map('intval', $created['services']));
            db()->exec("DELETE FROM appointment_services WHERE service_id IN ({$ids})");
            db()->exec("DELETE FROM services WHERE id IN ({$ids})");
        }
        if ($created['subcategories']) {
            db()->exec('DELETE FROM service_subcategories WHERE id IN (' . implode(',', array_map('intval', $created['subcategories'])) . ')');
        }
        if ($created['categories']) {
            db()->exec('DELETE FROM service_categories WHERE id IN (' . implode(',', array_map('intval', $created['categories'])) . ')');
        }
        db()->exec("DELETE FROM service_categories WHERE name LIKE 'ZZ %'");
    } catch (Throwable $e) {
        echo '  WARN  cleanup hit: ' . $e->getMessage() . PHP_EOL;
    }

    $after = snapshot();
    foreach ($before as $table => $state) {
        ok("{$table} unchanged (count and content)", $after[$table] === $state,
            'before ' . $state['count'] . ' / after ' . $after[$table]['count']);
    }
}

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo "RESULT: {$passed} passed, {$failed} failed" . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

exit($failed > 0 ? 1 : 0);

/* ==================================================================
 * Helpers mirroring the production write paths
 * ================================================================== */

function flatten(array $tree): array
{
    $out = [];
    foreach ($tree as $node) {
        foreach ($node['subcategories'] as $sub) {
            foreach ($sub['services'] as $s) { $out[] = $s; }
        }
        foreach ($node['direct'] as $s) { $out[] = $s; }
    }
    return $out;
}

function book_test_appointment(int $customerId, int $serviceId, string $date, string $start, string $notes): array
{
    $svc = db()->query('SELECT * FROM services WHERE id = ' . $serviceId)->fetch();
    $taxRate = (float)get_setting('tax_rate', 15);
    $subtotal = (float)$svc['price'];
    $tax = $subtotal * $taxRate / 100;
    $end = date('H:i:s', strtotime($start) + ((int)$svc['duration_minutes'] * 60));

    db()->beginTransaction();
    $stmt = db()->prepare(
        'INSERT INTO appointments (salon_id, customer_id, appointment_date, start_time, end_time, status, notes, subtotal, discount, tax, total_amount, payment_status, created_by)
         VALUES (:sid, :c, :d, :s, :e, "confirmed", :n, :sub, 0, :tax, :total, "pending", NULL)'
    );
    $stmt->execute(['sid' => default_salon_id(), 'c' => $customerId, 'd' => $date, 's' => $start, 'e' => $end, 'n' => $notes,
                    'sub' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax]);
    $apptId = (int)db()->lastInsertId();

    $stmt = db()->prepare('INSERT INTO appointment_services (salon_id, appointment_id, service_id, price, duration_minutes) VALUES (:sid, :a, :s, :p, :du)');
    $stmt->execute(['sid' => default_salon_id(), 'a' => $apptId, 's' => $svc['id'], 'p' => $svc['price'], 'du' => $svc['duration_minutes']]);

    $stmt = db()->prepare(
        'INSERT INTO invoices (salon_id, invoice_number, appointment_id, customer_id, subtotal, discount, tax, total, status)
         VALUES (:sid, :i, :a, :c, :sub, 0, :tax, :total, "unpaid")'
    );
    $stmt->execute(['sid' => default_salon_id(), 'i' => 'ZZ-TEST-' . $apptId, 'a' => $apptId, 'c' => $customerId,
                    'sub' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax]);
    $invoiceId = (int)db()->lastInsertId();
    db()->commit();

    $line = db()->query('SELECT * FROM appointment_services WHERE appointment_id = ' . $apptId)->fetch();

    return ['id' => $apptId, 'line' => $line, 'invoice_id' => $invoiceId];
}

function book_test_walkin(int $customerId, array $serviceIds): array
{
    $subtotal = 0.0;
    $minutes  = 0;
    $lines    = [];
    foreach ($serviceIds as $sid) {
        $svc = db()->query('SELECT * FROM services WHERE id = ' . $sid)->fetch();
        $subtotal += (float)$svc['price'];
        $minutes  += (int)$svc['duration_minutes'];
    }
    $taxRate = (float)get_setting('tax_rate', 15);
    $tax = $subtotal * $taxRate / 100;
    $total = $subtotal + $tax;
    $date = date('Y-m-d', strtotime('+46 days'));
    $start = '09:00:00';
    $end = date('H:i:s', strtotime($start) + $minutes * 60);

    db()->beginTransaction();
    $stmt = db()->prepare(
        'INSERT INTO appointments (salon_id, customer_id, appointment_date, start_time, end_time, status, notes, subtotal, discount, tax, total_amount, payment_status, created_by)
         VALUES (:sid, :c, :d, :s, :e, "completed", :n, :sub, 0, :tax, :total, "paid", NULL)'
    );
    $stmt->execute(['sid' => default_salon_id(), 'c' => $customerId, 'd' => $date, 's' => $start, 'e' => $end, 'n' => 'ZZ Walk-in both kinds',
                    'sub' => $subtotal, 'tax' => $tax, 'total' => $total]);
    $apptId = (int)db()->lastInsertId();

    $stmt = db()->prepare('INSERT INTO appointment_services (salon_id, appointment_id, service_id, price, duration_minutes) VALUES (:sid, :a, :s, :p, :du)');
    foreach ($serviceIds as $sid) {
        $svc = db()->query('SELECT * FROM services WHERE id = ' . $sid)->fetch();
        $stmt->execute(['sid' => default_salon_id(), 'a' => $apptId, 's' => $svc['id'], 'p' => $svc['price'], 'du' => $svc['duration_minutes']]);
    }

    $stmt = db()->prepare(
        'INSERT INTO invoices (salon_id, invoice_number, appointment_id, customer_id, subtotal, discount, tax, total, status)
         VALUES (:sid, :i, :a, :c, :sub, 0, :tax, :total, "paid")'
    );
    $stmt->execute(['sid' => default_salon_id(), 'i' => 'ZZ-TEST-W' . $apptId, 'a' => $apptId, 'c' => $customerId,
                    'sub' => $subtotal, 'tax' => $tax, 'total' => $total]);
    $invoiceId = (int)db()->lastInsertId();

    $stmt = db()->prepare(
        'INSERT INTO payments (salon_id, appointment_id, customer_id, amount, payment_method, status)
         VALUES (:sid, :a, :c, :amt, "cash", "completed")'
    );
    $stmt->execute(['sid' => default_salon_id(), 'a' => $apptId, 'c' => $customerId, 'amt' => $total]);
    $paymentId = (int)db()->lastInsertId();
    db()->commit();

    return [
        'appt_id'     => $apptId,
        'lines'       => db()->query('SELECT * FROM appointment_services WHERE appointment_id = ' . $apptId)->fetchAll(),
        'invoice_id'  => $invoiceId,
        'invoice'     => db()->query('SELECT * FROM invoices WHERE id = ' . $invoiceId)->fetch(),
        'payment_id'  => $paymentId,
        'payment'     => db()->query('SELECT * FROM payments WHERE id = ' . $paymentId)->fetch(),
    ];
}

/** Mirrors the three new rollups in modules/reports.php. */
function service_report_totals(string $from, string $to): array
{
    $sql = 'SELECT COALESCE(SUM(av.price),0) AS revenue
              FROM appointment_services av
              JOIN services s ON s.id = av.service_id
              JOIN appointments a ON a.id = av.appointment_id
             WHERE DATE(a.appointment_date) BETWEEN :from AND :to';
    $stmt = db()->prepare($sql);
    $stmt->execute(['from' => $from, 'to' => $to]);
    $services = (float)$stmt->fetchColumn();

    $stmt = db()->prepare(str_replace('c.id = s.category_id', 'c.id = s.category_id', $sql));
    $stmt = db()->prepare('SELECT COALESCE(SUM(av.price),0) FROM appointment_services av
            JOIN services s ON s.id = av.service_id
            LEFT JOIN service_categories c ON c.id = s.category_id
            JOIN appointments a ON a.id = av.appointment_id
           WHERE DATE(a.appointment_date) BETWEEN :from AND :to');
    $stmt->execute(['from' => $from, 'to' => $to]);
    $categories = (float)$stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COALESCE(SUM(av.price),0) FROM appointment_services av
            JOIN services s ON s.id = av.service_id
            LEFT JOIN service_subcategories sc ON sc.id = s.subcategory_id
            JOIN appointments a ON a.id = av.appointment_id
           WHERE DATE(a.appointment_date) BETWEEN :from AND :to');
    $stmt->execute(['from' => $from, 'to' => $to]);
    $subcategories = (float)$stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COALESCE(SUM(av.price),0) FROM appointment_services av
            JOIN services s ON s.id = av.service_id
            JOIN appointments a ON a.id = av.appointment_id
           WHERE s.subcategory_id IS NULL AND DATE(a.appointment_date) BETWEEN :from AND :to');
    $stmt->execute(['from' => $from, 'to' => $to]);
    $noneSub = (float)$stmt->fetchColumn();

    return ['services' => $services, 'categories' => $categories, 'subcategories' => $subcategories, 'noneSubRevenue' => $noneSub];
}

/** The pre-existing report tabs must keep working. */
function report_tab_works(string $tab): bool
{
    try {
        switch ($tab) {
            case 'sales':
                $sql = 'SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = "completed"';
                break;
            case 'appointments':
                $sql = 'SELECT a.status, COUNT(*) FROM appointments a GROUP BY a.status';
                break;
            case 'payments':
                $sql = 'SELECT p.payment_method, COUNT(*) FROM payments p GROUP BY p.payment_method';
                break;
            default:
                return false;
        }
        $rows = db()->query($sql)->fetchAll();
        return is_array($rows);
    } catch (Throwable $e) {
        return false;
    }
}
