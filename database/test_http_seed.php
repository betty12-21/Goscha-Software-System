<?php
/**
 * Beauty-php-ai — seed two independent tenants for the HTTP acceptance tests.
 *
 * Run against a throwaway database built from database/beauty_php_ai.sql +
 * database/migrations/002_multi_tenant.sql + database/migrations/003_settings_module.sql:
 *
 *   $env:BEAUTY_DB_NAME='beauty_http_scratch'
 *   $env:BEAUTY_DB_ALLOW_WRITE='1'
 *   php database/test_http_seed.php
 *
 * Creates:
 *   salon 1  GOSCHA Beauty Salon  owner Betty   betty@goscha.test / BettyPass!23
 *   salon 2  Bella Beauty Salon   owner Sarah   sarah@bella.test  / SarahPass!23
 *
 * Each salon gets its own hours, settings, category, service, staff member,
 * customer and a completed appointment + payment, so a leak in any module is
 * visible as the other salon's row.
 */

require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

$dbName = DB_NAME;
if ($dbName === DB_LIVE_NAME) {
    fwrite(STDERR, "Refusing to seed the live database ({$dbName}). Set BEAUTY_DB_NAME.\n");
    exit(1);
}
if (!DB_WRITES_ENABLED) {
    fwrite(STDERR, "Refusing to seed without BEAUTY_DB_ALLOW_WRITE=1.\n");
    exit(1);
}

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

/* Fresh slate for the tenant tables only. */
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'payments', 'invoices', 'appointment_services', 'appointments', 'waitlist',
    'customers', 'staff_services', 'staff_working_hours', 'staff_breaks', 'staff',
    'services', 'service_subcategories', 'service_categories',
    'business_breaks', 'business_hours', 'notifications', 'salon_settings',
    'settings', 'audit_logs', 'users', 'salons',
] as $t) {
    $pdo->exec("TRUNCATE TABLE `{$t}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$hash = static fn(string $p): string => password_hash($p, PASSWORD_DEFAULT);

$tenants = [
    1 => ['salon' => 'GOSCHA Beauty Salon', 'first' => 'Betty', 'last' => 'Goscha',
          'email' => 'betty@goscha.test', 'pw' => 'BettyPass!23',
          'cat' => 'GOSCHA Hair', 'svc' => 'GOSCHA Cut', 'svc_price' => 100.00,
          'staff' => ['Goscha', 'Stylist'], 'cust' => ['Goscha', 'Customer'],
          'appt_time' => '10:00:00', 'pay' => 100.00],
    2 => ['salon' => 'Bella Beauty Salon', 'first' => 'Sarah', 'last' => 'Bella',
          'email' => 'sarah@bella.test', 'pw' => 'SarahPass!23',
          'cat' => 'Bella Nails', 'svc' => 'Bella Manicure', 'svc_price' => 200.00,
          'staff' => ['Bella', 'Stylist'], 'cust' => ['Bella', 'Customer'],
          'appt_time' => '14:00:00', 'pay' => 200.00],
];

$apptDate = date('Y-m-d');   /* today, so dashboards show it */

foreach ($tenants as $sid => $t) {
    $pdo->prepare(
        'INSERT INTO salons (id, salon_name, owner_first_name, owner_last_name, email, status, setup_completed_at)
         VALUES (:id, :name, :of, :ol, :email, "active", NOW())'
    )->execute(['id' => $sid, 'name' => $t['salon'], 'of' => $t['first'], 'ol' => $t['last'], 'email' => $t['email']]);

    $pdo->prepare(
        'INSERT INTO salon_settings (salon_id, salon_name, owner_first_name, owner_last_name, setup_completed_at)
         VALUES (:sid, :name, :of, :ol, NOW())'
    )->execute(['sid' => $sid, 'name' => $t['salon'], 'of' => $t['first'], 'ol' => $t['last']]);

    $pdo->prepare(
        'INSERT INTO users (salon_id, name, first_name, last_name, email, phone, password, role, status)
         VALUES (:sid, :name, :of, :ol, :email, :phone, :pw, "admin", "active")'
    )->execute([
        'sid' => $sid, 'name' => $t['first'] . ' ' . $t['last'], 'of' => $t['first'], 'ol' => $t['last'],
        'email' => $t['email'], 'phone' => '0911' . $sid . '00000', 'pw' => $hash($t['pw']),
    ]);

    for ($d = 1; $d <= 7; $d++) {
        $open = $d <= 6;
        $pdo->prepare(
            'INSERT INTO business_hours (salon_id, day_of_week, is_open, opening_time, closing_time)
             VALUES (:sid, :d, :open, :from, :to)'
        )->execute([
            'sid' => $sid, 'd' => $d, 'open' => $open ? 1 : 0,
            'from' => $open ? '09:00:00' : null, 'to' => $open ? '18:00:00' : null,
        ]);
    }

    foreach ([['salon_name', $t['salon']], ['currency', 'ETB'], ['slot_interval_minutes', '15']] as [$k, $v]) {
        $pdo->prepare('INSERT INTO settings (salon_id, setting_key, setting_value) VALUES (:sid, :k, :v)')
            ->execute(['sid' => $sid, 'k' => $k, 'v' => $v]);
    }

    $pdo->prepare('INSERT INTO service_categories (salon_id, name, status, sort_order) VALUES (:sid, :n, "active", 1)')
        ->execute(['sid' => $sid, 'n' => $t['cat']]);
    $catId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO services (salon_id, category_id, name, duration_minutes, price, status, sort_order)
         VALUES (:sid, :cat, :n, 60, :price, "active", 1)'
    )->execute(['sid' => $sid, 'cat' => $catId, 'n' => $t['svc'], 'price' => $t['svc_price']]);
    $svcId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO staff (salon_id, first_name, last_name, phone, status) VALUES (:sid, :f, :l, :p, "active")'
    )->execute(['sid' => $sid, 'f' => $t['staff'][0], 'l' => $t['staff'][1], 'p' => '0922' . $sid . '00000']);
    $staffId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO customers (salon_id, first_name, last_name, phone) VALUES (:sid, :f, :l, :p)'
    )->execute(['sid' => $sid, 'f' => $t['cust'][0], 'l' => $t['cust'][1], 'p' => '0933' . $sid . '00000']);
    $custId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO appointments
            (salon_id, customer_id, staff_id, appointment_date, start_time, end_time, status,
             subtotal, discount, tax, total_amount, payment_status, created_at, updated_at)
         VALUES (:sid, :cust, :staff, :date, :start, :end, "completed",
             :total, 0, 0, :total, "paid", NOW(), NOW())'
    )->execute([
        'sid' => $sid, 'cust' => $custId, 'staff' => $staffId, 'date' => $apptDate,
        'start' => $t['appt_time'],
        'end' => date('H:i:s', strtotime($t['appt_time']) + 3600),
        'total' => $t['svc_price'],
    ]);
    $apptId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO appointment_services (salon_id, appointment_id, service_id, price, duration_minutes)
         VALUES (:sid, :appt, :svc, :price, 60)'
    )->execute(['sid' => $sid, 'appt' => $apptId, 'svc' => $svcId, 'price' => $t['svc_price']]);

    $pdo->prepare(
        'INSERT INTO invoices (salon_id, invoice_number, appointment_id, customer_id, subtotal, discount, tax, total, status, issued_at)
         VALUES (:sid, :num, :appt, :cust, :total, 0, 0, :total, "paid", NOW())'
    )->execute([
        'sid' => $sid, 'num' => 'INV-' . $sid . '0001', 'appt' => $apptId,
        'cust' => $custId, 'total' => $t['svc_price'],
    ]);

    $pdo->prepare(
        'INSERT INTO payments (salon_id, appointment_id, customer_id, amount, payment_method, payment_date, status)
         VALUES (:sid, :appt, :cust, :amt, "cash", NOW(), "completed")'
    )->execute(['sid' => $sid, 'appt' => $apptId, 'cust' => $custId, 'amt' => $t['pay']]);
}

echo "Seeded tenants:\n";
foreach ($pdo->query('SELECT id, salon_name FROM salons ORDER BY id') as $row) {
    $users = $pdo->prepare('SELECT email FROM users WHERE salon_id = :s ORDER BY id');
    $users->execute(['s' => $row['id']]);
    printf("  [%d] %-24s %s\n", $row['id'], $row['salon_name'],
        implode(', ', array_column($users->fetchAll(), 'email')));
}
echo "Date used for appointments: {$apptDate}\n";
