<?php
/**
 * Beauty-php-ai — cross-tenant HTTP acceptance suite (CLI).
 *
 * Drives the real application through PHP's built-in web server against a
 * throwaway database, logs in as two different salon owners, and asserts
 * that neither can see the other's dashboard, customers, staff, services,
 * or records by forging a URL. It also proves a write lands on the writer's
 * own salon.
 *
 * The database server is seeded first (database/test_http_seed.php), then a
 * `php -S` process is started and torn down inside this single run — the
 * shell that launched us kills stray children, so the server must not
 * outlive the script.
 *
 * USAGE
 *   $env:BEAUTY_DB_NAME='beauty_http_scratch'
 *   $env:BEAUTY_DB_ALLOW_WRITE='1'
 *   C:\xampp\php\php.exe database\test_tenant_http.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}

$dbName = getenv('BEAUTY_DB_NAME') ?: '';
if ($dbName === '') {
    fwrite(STDERR, "BEAUTY_DB_NAME is not set. Refusing to guess a database.\n");
    exit(2);
}
if ($dbName === 'beauty_php_ai') {
    fwrite(STDERR, "Refusing to run the HTTP suite against the live database.\n");
    exit(2);
}
if (getenv('BEAUTY_DB_ALLOW_WRITE') !== '1') {
    fwrite(STDERR, "Refusing to write without BEAUTY_DB_ALLOW_WRITE=1.\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';

$pass = 0;
$fail = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; printf("  [PASS] %-58s %s\n", $label, $detail); }
    else       { $fail++; printf("  [FAIL] %-58s %s\n", $label, $detail); }
}

function section(string $t): void { echo "\n=== {$t} ===\n"; }

echo "Database under test: " . $dbName . "\n";

/* ------------------------------------------------------------------
 * 1. Seed two tenants (truncates the tenant tables first).
 * ------------------------------------------------------------------ */
ob_start();
require __DIR__ . '/test_http_seed.php';
$seedOutput = ob_get_clean();
echo $seedOutput;

$tenants = [];
foreach (db()->query('SELECT id, salon_name FROM salons ORDER BY id') as $row) {
    $tenants[(int)$row['id']] = $row['salon_name'];
}
$salon1 = 1;
$salon2 = 2;

/* Fixture ids we need for the forged-URL checks. */
function fixture(string $sql, array $p = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($p);
    return $stmt->fetch();
}

$bellaCustomer = fixture('SELECT id FROM customers WHERE salon_id = 2 LIMIT 1');
$goschaCustomer = fixture('SELECT id FROM customers WHERE salon_id = 1 LIMIT 1');

/* ------------------------------------------------------------------
 * 2. Start the built-in server.
 * ------------------------------------------------------------------ */
$docroot = realpath(__DIR__ . '/..');
$phpBin  = PHP_BINARY;

function free_port(): int
{
    for ($p = 8901; $p < 8999; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:{$p}", $errno, $errstr);
        if ($sock) { fclose($sock); return $p; }
    }
    return 0;
}

$port = free_port();
if ($port === 0) {
    fwrite(STDERR, "No free TCP port available.\n");
    exit(2);
}

$logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bsai_http_server.log';
@unlink($logFile);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $logFile, 'a'],
    2 => ['file', $logFile, 'a'],
];
$server = proc_open([$phpBin, '-S', "127.0.0.1:{$port}", '-t', $docroot], $descriptors, $pipes);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the built-in server.\n");
    exit(2);
}

$base = "http://127.0.0.1:{$port}";

/* Ensure the server is torn down no matter how we exit. */
$GLOBALS['__server'] = $server;
register_shutdown_function(static function () {
    if (!empty($GLOBALS['__server']) && is_resource($GLOBALS['__server'])) {
        proc_terminate($GLOBALS['__server']);
        @proc_close($GLOBALS['__server']);
    }
});

/* Wait until it answers. */
$ready = false;
for ($i = 0; $i < 60; $i++) {
    $ch = curl_init($base . '/login.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $body !== false) { $ready = true; break; }
    usleep(200000);
}
if (!$ready) {
    fwrite(STDERR, "The built-in server never became ready. Log:\n" . @file_get_contents($logFile) . "\n");
    exit(2);
}

/* ------------------------------------------------------------------
 * 3. Tiny HTTP client (one cookie jar per "browser").
 * ------------------------------------------------------------------ */
function jar(string $name): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "bsai_jar_{$name}.txt";
    @unlink($path);
    return $path;
}

function request(string $method, string $url, string $jar, ?array $post = null, bool $follow = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HEADER         => false,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $body = curl_exec($ch);
    $info = [
        'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'url'  => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
    ];
    curl_close($ch);

    return ['body' => (string)$body, 'code' => $info['code'], 'url' => $info['url']];
}

function request_upload(string $url, string $jar, array $fields, string $fileField, string $filePath): array
{
    $fields[$fileField] = new CURLFile($filePath);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['body' => (string)$body, 'code' => $code];
}

function csrf_from(string $html): string
{
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function login(string $base, string $email, string $password, string $jar): bool
{
    $page = request('GET', $base . '/login.php', $jar, null, false);
    $token = csrf_from($page['body']);
    $res = request('POST', $base . '/login.php', $jar, [
        'email' => $email, 'password' => $password, 'csrf_token' => $token,
    ], false);

    return $res['code'] === 302;
}

/* ------------------------------------------------------------------
 * 4. Tests
 * ------------------------------------------------------------------ */

section('A. logged-out auth page does not advertise a tenant');
$anon = jar('anon');
$loginPage = request('GET', $base . '/login.php', $anon, null, false);
ok('login page renders', $loginPage['code'] === 200);
ok('no salon-1 brand on login page', stripos($loginPage['body'], 'GOSCHA Beauty Salon') === false);
ok('no salon-2 brand on login page', stripos($loginPage['body'], 'Bella') === false);
ok('neutral product brand shown', stripos($loginPage['body'], 'Goscha Software system') !== false);

section('B. session isolation — both owners can sign in');
$j1 = jar('salon1');
$j2 = jar('salon2');
ok('salon 1 owner logs in', login($base, 'betty@goscha.test', 'BettyPass!23', $j1));
ok('salon 2 owner logs in', login($base, 'sarah@bella.test', 'SarahPass!23', $j2));

section('C. dashboard shows only the signed-in salon');
$d1 = request('GET', $base . '/admin/dashboard.php', $j1);
$d2 = request('GET', $base . '/admin/dashboard.php', $j2);
ok('salon 1 dashboard renders', $d1['code'] === 200);
ok('salon 1 dashboard shows its customer', strpos($d1['body'], 'Goscha Customer') !== false);
ok('salon 1 dashboard hides the other salon', strpos($d1['body'], 'Bella Customer') === false && stripos($d1['body'], 'Bella Manicure') === false);
ok('salon 2 dashboard shows its customer', strpos($d2['body'], 'Bella Customer') !== false);
ok('salon 2 dashboard hides the other salon', strpos($d2['body'], 'Goscha Customer') === false);

section('D. customer list is tenant-scoped');
$c1 = request('GET', $base . '/admin/customers.php?action=list', $j1);
$c2 = request('GET', $base . '/admin/customers.php?action=list', $j2);
ok('salon 1 sees its own customer', strpos($c1['body'], 'Goscha Customer') !== false);
ok('salon 1 does not see salon 2 customer', strpos($c1['body'], 'Bella Customer') === false);
ok('salon 2 sees its own customer', strpos($c2['body'], 'Bella Customer') !== false);
ok('salon 2 does not see salon 1 customer', strpos($c2['body'], 'Goscha Customer') === false);

section('E. staff list is tenant-scoped');
$s1 = request('GET', $base . '/admin/staff.php?action=list', $j1);
$s2 = request('GET', $base . '/admin/staff.php?action=list', $j2);
ok('salon 1 sees its own staff', strpos($s1['body'], 'Goscha Stylist') !== false);
ok('salon 1 does not see salon 2 staff', strpos($s1['body'], 'Bella Stylist') === false);
ok('salon 2 sees its own staff', strpos($s2['body'], 'Bella Stylist') !== false);
ok('salon 2 does not see salon 1 staff', strpos($s2['body'], 'Goscha Stylist') === false);

section('F. forged record id cannot cross tenants');
$bellaId = (int)($bellaCustomer['id'] ?? 0);
$forged = request('GET', $base . '/admin/customers.php?action=view&id=' . $bellaId, $j1);
ok('salon 1 cannot open salon 2 customer by id',
    strpos($forged['body'], 'Bella Customer') === false,
    'http ' . $forged['code']);

$goschaId = (int)($goschaCustomer['id'] ?? 0);
$forgedStaff = request('GET', $base . '/admin/staff.php?action=view&id=' . $bellaId, $j1);
ok('salon 1 cannot open a foreign staff id', strpos($forgedStaff['body'], 'Bella Stylist') === false);

section('G. writes land on the writer\'s own salon');
$add1 = request('GET', $base . '/admin/customers.php?action=add', $j1);
$tok1 = csrf_from($add1['body']);
$w1 = request('POST', $base . '/admin/customers.php?action=add', $j1, [
    'csrf_token' => $tok1, 'first_name' => 'Zeta', 'last_name' => 'Alpha',
    'phone' => '0911999001', 'email' => '', 'date_of_birth' => '', 'gender' => '',
    'address' => '', 'skin_type' => '', 'hair_type' => '', 'allergies' => '', 'notes' => '',
]);
$z1 = fixture('SELECT salon_id FROM customers WHERE first_name = "Zeta" AND last_name = "Alpha" ORDER BY id DESC LIMIT 1');
$alert = '';
if (preg_match('/alert-danger[^>]*>(.*?)<\/div>/s', $w1['body'], $mm)) {
    $alert = trim(html_entity_decode(strip_tags($mm[1])));
}
ok('salon 1 write is stamped salon 1', (int)($z1['salon_id'] ?? 0) === $salon1,
    'http ' . $w1['code'] . ' -> ' . $w1['url'] . ' alert="' . substr($alert, 0, 120) . '"');

$add2 = request('GET', $base . '/admin/customers.php?action=add', $j2);
$tok2 = csrf_from($add2['body']);
request('POST', $base . '/admin/customers.php?action=add', $j2, [
    'csrf_token' => $tok2, 'first_name' => 'Zeta', 'last_name' => 'Beta',
    'phone' => '0911999002', 'email' => '', 'date_of_birth' => '', 'gender' => '',
    'address' => '', 'skin_type' => '', 'hair_type' => '', 'allergies' => '', 'notes' => '',
]);
$z2 = fixture('SELECT salon_id FROM customers WHERE first_name = "Zeta" AND last_name = "Beta" ORDER BY id DESC LIMIT 1');
ok('salon 2 write is stamped salon 2', (int)($z2['salon_id'] ?? 0) === $salon2);

section('H. services list is tenant-scoped');
$sv1 = request('GET', $base . '/admin/services.php?action=list', $j1);
$sv2 = request('GET', $base . '/admin/services.php?action=list', $j2);
ok('salon 1 sees its own service', strpos($sv1['body'], 'GOSCHA Cut') !== false);
ok('salon 1 does not see salon 2 service', strpos($sv1['body'], 'Bella Manicure') === false);
ok('salon 2 sees its own service', strpos($sv2['body'], 'Bella Manicure') !== false);
ok('salon 2 does not see salon 1 service', strpos($sv2['body'], 'GOSCHA Cut') === false);

section('I. appointments list is tenant-scoped');
$ap1 = request('GET', $base . '/admin/appointments.php?action=list', $j1);
$ap2 = request('GET', $base . '/admin/appointments.php?action=list', $j2);
ok('salon 1 sees its own appointment', strpos($ap1['body'], 'Goscha Customer') !== false);
ok('salon 1 does not see salon 2 appointment', strpos($ap1['body'], 'Bella Customer') === false);
ok('salon 2 sees its own appointment', strpos($ap2['body'], 'Bella Customer') !== false);
ok('salon 2 does not see salon 1 appointment', strpos($ap2['body'], 'Goscha Customer') === false);

section('J. invoices list is tenant-scoped');
$iv1 = request('GET', $base . '/admin/invoices.php', $j1);
$iv2 = request('GET', $base . '/admin/invoices.php', $j2);
ok('salon 1 sees its own invoice', strpos($iv1['body'], 'INV-10001') !== false);
ok('salon 1 does not see salon 2 invoice', strpos($iv1['body'], 'INV-20001') === false);
ok('salon 2 sees its own invoice', strpos($iv2['body'], 'INV-20001') !== false);
ok('salon 2 does not see salon 1 invoice', strpos($iv2['body'], 'INV-10001') === false);

section('K. payments list is tenant-scoped');
$pm1 = request('GET', $base . '/admin/payments.php', $j1);
$pm2 = request('GET', $base . '/admin/payments.php', $j2);
ok('salon 1 sees its own payment', strpos($pm1['body'], 'Goscha Customer') !== false);
ok('salon 1 does not see salon 2 payment', strpos($pm1['body'], 'Bella Customer') === false);
ok('salon 2 sees its own payment', strpos($pm2['body'], 'Bella Customer') !== false);
ok('salon 2 does not see salon 1 payment', strpos($pm2['body'], 'Goscha Customer') === false);

section('L. reports are tenant-scoped');
$rp1 = request('GET', $base . '/admin/reports.php?view=services', $j1);
$rp2 = request('GET', $base . '/admin/reports.php?view=services', $j2);
ok('salon 1 report shows its own service', strpos($rp1['body'], 'GOSCHA Cut') !== false);
ok('salon 1 report hides salon 2 service', strpos($rp1['body'], 'Bella Manicure') === false);
ok('salon 2 report shows its own service', strpos($rp2['body'], 'Bella Manicure') !== false);
ok('salon 2 report hides salon 1 service', strpos($rp2['body'], 'GOSCHA Cut') === false);

section('M. forged record ids cannot cross tenants (services / invoices / appointments)');
$bellaSv  = fixture('SELECT id FROM services WHERE salon_id = 2 LIMIT 1');
$bellaInv = fixture('SELECT id FROM invoices WHERE salon_id = 2 LIMIT 1');
$bellaApt = fixture('SELECT id FROM appointments WHERE salon_id = 2 LIMIT 1');

$fSvc = request('GET', $base . '/admin/services.php?action=edit&id=' . (int)($bellaSv['id'] ?? 0), $j1);
ok('salon 1 cannot open salon 2 service by id', strpos($fSvc['body'], 'Bella Manicure') === false, 'http ' . $fSvc['code']);

$fInv = request('GET', $base . '/admin/invoice-view.php?id=' . (int)($bellaInv['id'] ?? 0), $j1);
ok('salon 1 cannot print salon 2 invoice by id', strpos($fInv['body'], 'INV-20001') === false, 'http ' . $fInv['code']);

$fApt = request('GET', $base . '/admin/appointments.php?action=view&id=' . (int)($bellaApt['id'] ?? 0), $j1);
ok('salon 1 cannot open salon 2 appointment by id', strpos($fApt['body'], 'Bella Customer') === false, 'http ' . $fApt['code']);

section('N. inventory / suppliers / payroll / users pages render per tenant');
foreach ([['inventory.php', $j1], ['inventory.php', $j2], ['suppliers.php', $j1],
          ['suppliers.php', $j2], ['payroll.php', $j1], ['payroll.php', $j2],
          ['settings.php?view=users', $j1], ['settings.php?view=users', $j2], ['notifications.php', $j1],
          ['audit-logs.php', $j1], ['settings.php', $j1]] as [$page, $jarRef]) {
    $res = request('GET', $base . '/admin/' . $page, $jarRef);
    ok('admin/' . $page . ' renders', $res['code'] === 200, 'http ' . $res['code']);
}

section('O. Settings > Users tab (moved out of the standalone page)');
$dash1 = request('GET', $base . '/admin/dashboard.php', $j1);
ok('sidebar no longer links to a standalone users page', strpos($dash1['body'], 'users.php') === false);

/* The legacy ?view=users deep-link folds into General > Users. */
$usersPage1 = request('GET', $base . '/admin/settings.php?view=general&sub=users', $j1);
ok('salon 1 settings users section renders', $usersPage1['code'] === 200 && strpos($usersPage1['body'], 'account(s)') !== false);
ok('legacy ?view=users deep-link still resolves', request('GET', $base . '/admin/settings.php?view=users', $j1)['code'] === 200);
ok('salon 1 sees its own owner account', strpos($usersPage1['body'], 'betty@goscha.test') !== false);
ok('salon 1 does not see salon 2 owner', strpos($usersPage1['body'], 'sarah@bella.test') === false);

$newTok  = csrf_from($usersPage1['body']);
$newUser = request('POST', $base . '/admin/settings.php?view=general&sub=users', $j1, [
    'csrf_token' => $newTok, 'form_action' => 'save_user', 'id' => '0',
    'name' => 'Goscha Staff', 'email' => 'staff1@goscha.test', 'phone' => '0911000011',
    'password' => 'StaffPass!23', 'status' => 'active',
]);
$u1row = fixture('SELECT salon_id, role FROM users WHERE email = "staff1@goscha.test" LIMIT 1');
ok('new staff created under settings is stamped salon 1', (int)($u1row['salon_id'] ?? 0) === $salon1,
    'http ' . $newUser['code']);
ok('new account created here is a receptionist, never an admin', ($u1row['role'] ?? '') === 'receptionist',
    'role=' . ($u1row['role'] ?? 'n/a'));

$usersPage2 = request('GET', $base . '/admin/settings.php?view=general&sub=users', $j2);
ok('salon 2 does not see salon 1 staff account', strpos($usersPage2['body'], 'staff1@goscha.test') === false);

/* ------------------------------------------------------------------ */
section('P. Settings module — safe saves, holidays, per-salon backups');

/* P1. Saving one form group must never blank another group's fields. */
$gPage = request('GET', $base . '/admin/settings.php?view=general&sub=info', $j1);
request('POST', $base . '/admin/settings.php?view=general&sub=info', $j1, [
    'csrf_token' => csrf_from($gPage['body']), 'form_action' => 'save_settings', 'view' => 'general',
    'salon_name' => 'GOSCHA Beauty Salon', 'salon_tagline' => 'Test tagline',
    'owner_first_name' => 'Betty', 'owner_last_name' => 'Goscha',
    'salon_phone' => '0911000000', 'salon_email' => 'betty@goscha.test',
    'salon_address' => 'Addis', 'salon_website' => 'https://goscha.test',
    'salon_description' => 'Test salon',
]);
$hPage = request('GET', $base . '/admin/settings.php?view=hours', $j1);
request('POST', $base . '/admin/settings.php?view=hours', $j1, [
    'csrf_token' => csrf_from($hPage['body']), 'form_action' => 'save_settings', 'view' => 'hours',
    'currency' => 'ETB', 'tax_rate' => '15', 'loyalty_rate' => '100',
    'slot_interval_minutes' => '15', 'buffer_minutes' => '5',
    'appointment_advance_days' => '30', 'max_advance_days' => '30',
    'session_timeout_minutes' => '60', 'cancellation_notice_hours' => '0',
    'reschedule_notice_hours' => '0', 'allow_same_day' => '1', 'allow_walkins' => '1',
]);
$nameAfter = fixture("SELECT setting_value AS v FROM settings WHERE salon_id = 1 AND setting_key = 'salon_name'");
ok('saving Hours does not wipe General Information', ($nameAfter['v'] ?? '') === 'GOSCHA Beauty Salon',
    'salon_name=' . ($nameAfter['v'] ?? 'null'));

/* P2. Holiday / special-hours range is stored and tenant-scoped. */
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$holPage  = request('GET', $base . '/admin/settings.php?view=holidays', $j1);
request('POST', $base . '/admin/settings.php?view=holidays', $j1, [
    'csrf_token' => csrf_from($holPage['body']), 'form_action' => 'save_holiday', 'id' => '0',
    'holiday_date' => $tomorrow, 'end_date' => $tomorrow, 'title' => 'Special Test Hours',
    'open_time' => '10:00', 'close_time' => '14:00', 'notes' => 'Open half day', 'is_active' => '1',
]);
$h1 = fixture('SELECT is_closed, open_time FROM holidays WHERE salon_id = 1 AND title = "Special Test Hours" ORDER BY id DESC LIMIT 1');
ok('special-hours holiday saved as open with times',
    $h1 && (int)$h1['is_closed'] === 0 && substr((string)$h1['open_time'], 0, 5) === '10:00',
    'open=' . ($h1['open_time'] ?? 'n/a'));
$h2 = fixture('SELECT COUNT(*) AS c FROM holidays WHERE salon_id = 2 AND title = "Special Test Hours"');
ok('salon 2 has no special-hours record from salon 1', (int)($h2['c'] ?? 0) === 0);
ok('special-hours holiday is listed for salon 1',
    strpos(request('GET', $base . '/admin/settings.php?view=holidays', $j1)['body'], 'Special Test Hours') !== false);

/* P3. Backup is salon-scoped and downloadable only by its owner. */
$bkPage = request('GET', $base . '/admin/settings.php?view=backup', $j1);
request('POST', $base . '/admin/settings.php?view=backup', $j1, [
    'csrf_token' => csrf_from($bkPage['body']), 'form_action' => 'backup_db',
]);
$bk = fixture('SELECT id, filename FROM salon_backups WHERE salon_id = 1 ORDER BY id DESC LIMIT 1');
ok('salon 1 backup row created', !empty($bk['filename']));
$bkFile = realpath(__DIR__ . '/../storage/backups') . DIRECTORY_SEPARATOR . basename((string)($bk['filename'] ?? ''));
$bkSql  = is_file($bkFile) ? (string)file_get_contents($bkFile) : '';
ok('backup contains salon 1 data', strpos($bkSql, 'betty@goscha.test') !== false);
ok('backup excludes salon 2 data',
    strpos($bkSql, 'sarah@bella.test') === false && strpos($bkSql, 'Bella Beauty Salon') === false);
if (!empty($bk['id'])) {
    $dl = request('GET', $base . '/admin/settings.php?form_action=download_backup&id=' . (int)$bk['id'], $j1, null, false);
    ok('owner can download the backup',
        $dl['code'] === 200 && strpos($dl['body'], 'betty@goscha.test') !== false, 'http ' . $dl['code']);
}

/* P4. Admin creation is not offered here; the owner stays an administrator. */
ok('no admin-creation button on the users screen', strpos($usersPage1['body'], 'New Admin') === false);
$owner = fixture("SELECT role FROM users WHERE salon_id = 1 AND email = 'betty@goscha.test'");
ok('salon owner keeps the administrator role', ($owner['role'] ?? '') === 'admin');

/* P5. User Profile: edit own details, keep identity, reject duplicate emails. */
$before = fixture("SELECT password, role, status FROM users WHERE email = 'betty@goscha.test'");
$profPage = request('GET', $base . '/admin/settings.php?view=general', $j1);
$profRes = request('POST', $base . '/admin/settings.php?view=general', $j1, [
    'csrf_token' => csrf_from($profPage['body']), 'form_action' => 'save_profile',
    'name' => 'Betty G. Owner', 'email' => 'betty2@goscha.test', 'phone' => '0911999888',
]);
$profRow = fixture("SELECT name, email, phone, role, status, password FROM users WHERE salon_id = 1 AND email = 'betty2@goscha.test'");
ok('profile update saves name, email and phone',
    in_array($profRes['code'], [200, 302], true) && ($profRow['name'] ?? '') === 'Betty G. Owner' && ($profRow['phone'] ?? '') === '0911999888',
    'http ' . $profRes['code']);
ok('profile update preserves role, status and password',
    ($profRow['role'] ?? '') === 'admin' && ($profRow['status'] ?? '') === 'active'
        && ($profRow['password'] ?? '') === ($before['password'] ?? 'x'));

$dupeRes = request('POST', $base . '/admin/settings.php?view=general', $j1, [
    'csrf_token' => csrf_from(request('GET', $base . '/admin/settings.php?view=general', $j1)['body']),
    'form_action' => 'save_profile', 'name' => 'Betty G. Owner', 'email' => 'staff1@goscha.test', 'phone' => '',
]);
$dupeNo = fixture("SELECT COUNT(*) AS c FROM users WHERE email = 'staff1@goscha.test' AND name = 'Betty G. Owner'");
ok('profile update rejects an email already used by another account',
    ($dupeNo['c'] ?? 0) === 0, 'http ' . $dupeRes['code']);
$crossRes = request('POST', $base . '/admin/settings.php?view=general', $j1, [
    'csrf_token' => csrf_from(request('GET', $base . '/admin/settings.php?view=general', $j1)['body']),
    'form_action' => 'save_profile', 'name' => 'Betty G. Owner', 'email' => 'sarah@bella.test', 'phone' => '',
]);
$unchanged = fixture("SELECT email FROM users WHERE salon_id = 1 AND name = 'Betty G. Owner'");
ok('profile update rejects a cross-salon email (globally unique)',
    ($unchanged['email'] ?? '') === 'betty2@goscha.test', 'email=' . ($unchanged['email'] ?? 'n/a'));

/* restore the fixture owner's original email for any later assertions */
$restorePage = request('GET', $base . '/admin/settings.php?view=general', $j1);
request('POST', $base . '/admin/settings.php?view=general', $j1, [
    'csrf_token' => csrf_from($restorePage['body']), 'form_action' => 'save_profile',
    'name' => 'Betty Owner', 'email' => 'betty@goscha.test', 'phone' => '',
]);

/* P6. Salon Information & Branding: name + secure logo, non-destructive save. */
$brandPage = request('GET', $base . '/admin/settings.php?view=general', $j1);
$tmpPng = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bsai_logo_test.png';
file_put_contents($tmpPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'));
$brandRes = request_upload($base . '/admin/settings.php?view=general', $j1, [
    'csrf_token' => csrf_from($brandPage['body']), 'form_action' => 'save_branding',
    'salon_name' => 'GOSCHA Beauty Salon',
], 'logo', $tmpPng);
$logoVal = fixture("SELECT setting_value AS v FROM settings WHERE salon_id = 1 AND setting_key = 'salon_logo'");
$logoRel = (string)($logoVal['v'] ?? '');
$logoAbs = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoRel);
ok('branding save stores the logo under assets/uploads/logos/',
    $brandRes['code'] === 302 && str_starts_with($logoRel, 'assets/uploads/logos/'),
    'http ' . $brandRes['code'] . ' -> ' . ($logoRel !== '' ? $logoRel : 'n/a'));
ok('uploaded logo file exists on disk', $logoRel !== '' && is_file($logoAbs));

$nameOnly = request('POST', $base . '/admin/settings.php?view=general', $j1, [
    'csrf_token' => csrf_from(request('GET', $base . '/admin/settings.php?view=general', $j1)['body']),
    'form_action' => 'save_branding', 'salon_name' => 'GOSCHA Beauty Salon Renamed',
]);
$logoKeep = fixture("SELECT setting_value AS v FROM settings WHERE salon_id = 1 AND setting_key = 'salon_logo'");
ok('saving only the name keeps the existing logo', ($logoKeep['v'] ?? '') === $logoRel && is_file($logoAbs));

$tmpTxt = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bsai_logo_bad.txt';
file_put_contents($tmpTxt, "<?php echo 'pwn'; ?>");
$badPage = request('GET', $base . '/admin/settings.php?view=general', $j1);
$badRes = request_upload($base . '/admin/settings.php?view=general', $j1, [
    'csrf_token' => csrf_from($badPage['body']), 'form_action' => 'save_branding',
    'salon_name' => 'GOSCHA Beauty Salon Renamed',
], 'logo', $tmpTxt);
$logoAfterBad = fixture("SELECT setting_value AS v FROM settings WHERE salon_id = 1 AND setting_key = 'salon_logo'");
ok('a non-image upload is refused and the logo is untouched',
    $badRes['code'] === 302 && ($logoAfterBad['v'] ?? '') === $logoRel);

/* P7. Changing dashboard branding must not touch the public homepage. */
$home = request('GET', $base . '/index.php', jar('anon_home'));
ok('public homepage does not leak the dashboard salon name',
    $home['code'] === 200 && strpos($home['body'], 'GOSCHA Beauty Salon') === false,
    'http ' . $home['code']);
ok('public homepage still renders its own product brand',
    strpos($home['body'], 'Goscha Software system') !== false);

/* P8. Logo removal clears the setting and deletes the file. */
$rmPage = request('GET', $base . '/admin/settings.php?view=general', $j1);
request('POST', $base . '/admin/settings.php?view=general', $j1, [
    'csrf_token' => csrf_from($rmPage['body']), 'form_action' => 'remove_logo',
]);
$logoAfter = fixture("SELECT setting_value AS v FROM settings WHERE salon_id = 1 AND setting_key = 'salon_logo'");
ok('logo remove clears the setting', ($logoAfter['v'] ?? 'x') === '');
clearstatcache(true, $logoAbs);
ok('logo remove deletes the file', !is_file($logoAbs));
@unlink($tmpPng);
@unlink($tmpTxt);

/* ------------------------------------------------------------------ */
echo "\n" . str_repeat('-', 60) . "\n";
printf("RESULT: %d passed, %d failed\n", $pass, $fail);
echo str_repeat('-', 60) . "\n";
exit($fail === 0 ? 0 : 1);
