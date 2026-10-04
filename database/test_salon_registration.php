<?php
/**
 * Phase 3 acceptance: public salon registration + tenant isolation.
 *
 * Runs against whatever BEAUTY_DB_NAME points at. Refuses to run against the
 * live database name, and requires the CLI write override.
 */

$dbName = getenv('BEAUTY_DB_NAME') ?: '';
if ($dbName === '') {
    fwrite(STDERR, "BEAUTY_DB_NAME is not set. Refusing to guess a database.\n");
    exit(2);
}
if ($dbName === 'beauty_php_ai') {
    fwrite(STDERR, "Refusing to run the registration suite against the live database.\n");
    exit(2);
}
if (getenv('BEAUTY_DB_ALLOW_WRITE') !== '1') {
    fwrite(STDERR, "Refusing to write without BEAUTY_DB_ALLOW_WRITE=1.\n");
    exit(2);
}

/* init.php defines APP_INIT itself; defining it here first would warn. */
require __DIR__ . '/../includes/init.php';

$pass = 0;
$fail = 0;

function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  [PASS] %-56s %s\n", $label, $detail); }
    else       { $fail++; printf("  [FAIL] %-56s %s\n", $label, $detail); }
}

function section(string $t): void { echo "\n=== {$t} ===\n"; }

/* A valid payload matching what the HTTP form posts. */
function payload(array $overrides = []): array {
    $hours = [];
    for ($d = 1; $d <= 7; $d++) {
        $hours[$d] = ['is_open' => ($d <= 6) ? 1 : 0,
                      'opening_time' => '09:00', 'closing_time' => '18:00'];
    }

    return $overrides + [
        'salon_name'       => 'Bella Beauty',
        'first_name'       => 'Sarah',
        'last_name'        => 'Bello',
        'phone'            => '0911000000',
        'email'            => 'sarah@bellabeauty.test',
        'password'         => 'Str0ngPass!23',
        'password_confirm' => 'Str0ngPass!23',
        'hours'            => $hours,
    ];
}

/* Submit a valid payload, optionally with field overrides. */
function submit(array $overrides = []): array {
    return register_salon(payload($overrides));
}

section('database under test');
$actual = (string)db()->query('SELECT DATABASE()')->fetchColumn();
ok('connected to scratch database', $actual === $dbName, "db={$actual}");

$beforeSalons  = (int)db()->query('SELECT COUNT(*) FROM salons')->fetchColumn();
$beforeUsers   = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

section('1. first registration creates salon + hours + owner');
$r = submit();
ok('registration succeeded', !empty($r['success']), $r['error'] ?? '');

$salonId = (int)($r['salon_id'] ?? 0);
$userId  = (int)($r['user_id'] ?? 0);

if ($salonId > 0) {
    $s = db()->query("SELECT * FROM salons WHERE id = {$salonId}")->fetch();
    ok('salon row exists', (bool)$s, $s['salon_name'] ?? '');
    ok('salon is active', ($s['status'] ?? '') === 'active');
    ok('salon stamped setup_completed_at', !empty($s['setup_completed_at']));
    ok('owner names stored', ($s['owner_first_name'] ?? '') === 'Sarah' && ($s['owner_last_name'] ?? '') === 'Bello');

    $h = db()->query("SELECT COUNT(*) c, SUM(is_open) o FROM business_hours WHERE salon_id = {$salonId}")->fetch();
    ok('7 weekly-hours rows', (int)$h['c'] === 7, "got {$h['c']}");
    ok('6 days open, 1 closed', (int)$h['o'] === 6, "open={$h['o']}");

    $m = db()->query("SELECT COUNT(*) c FROM salon_settings WHERE salon_id = {$salonId}")->fetchColumn();
    ok('salon_settings mirror row', (int)$m === 1);

    $u = db()->query("SELECT * FROM users WHERE id = {$userId}")->fetch();
    ok('owner user row exists', (bool)$u);
    ok('user is admin', ($u['role'] ?? '') === 'admin');
    ok('user linked to this salon', (int)($u['salon_id'] ?? 0) === $salonId);
    ok('password hashed, not plaintext', !empty($u['password']) && $u['password'] !== 'Str0ngPass!23');
    ok('password verifies', password_verify('Str0ngPass!23', (string)$u['password']));
}

section('2. duplicate registration is refused');
$dup = submit();
ok('same email rejected', empty($dup['success']));
ok('error mentions existing account', stripos((string)($dup['error'] ?? ''), 'already exists') !== false, $dup['error'] ?? '');

$afterSalons = (int)db()->query('SELECT COUNT(*) FROM salons')->fetchColumn();
ok('no orphan salon left behind', $afterSalons === $beforeSalons + 1, "salons {$beforeSalons} -> {$afterSalons}");

$diffName = submit(['salon_name' => 'Different Name', 'email' => 'sarah@bellabeauty.test']);
ok('duplicate email refused even with a new salon name', empty($diffName['success']));
ok('still no extra salon', (int)db()->query('SELECT COUNT(*) FROM salons')->fetchColumn() === $afterSalons);

section('3. second, different salon registers independently');
$r2 = submit(['salon_name' => 'Glow Studio', 'first_name' => 'Marta',
              'last_name' => 'Bekele', 'email' => 'marta@glowstudio.test']);
ok('second registration succeeded', !empty($r2['success']), $r2['error'] ?? '');
$salon2 = (int)($r2['salon_id'] ?? 0);
ok('second salon got a different id', $salon2 > 0 && $salon2 !== $salonId, "salon2={$salon2}");

section('4. each admin sees only their own salon');
$_SESSION = [];
$_SESSION['user_id']  = $userId;
$_SESSION['salon_id'] = $salonId;

$u1 = current_user();
ok('session resolves user', (bool)$u1);
ok('current_salon_id() matches the session', current_salon_id() === $salonId, 'got ' . current_salon_id());
ok('current_salon_name() is salon 1', current_salon_name() === 'Bella Beauty', current_salon_name());

/* plant rows in both tenants */
$db = db();
$db->prepare('INSERT INTO customers (salon_id, first_name, last_name, phone) VALUES (?,?,?,?)')
   ->execute([$salonId, 'Nina', 'Haddad', '0922000000']);
$c1 = (int)$db->lastInsertId();
$db->prepare('INSERT INTO customers (salon_id, first_name, last_name, phone) VALUES (?,?,?,?)')
   ->execute([$salon2, 'Omar', 'Ali', '0923000000']);
$c2 = (int)$db->lastInsertId();

$seen = current_salon_id() === $salonId
      ? (int)db()->query("SELECT COUNT(*) FROM customers WHERE salon_id = " . current_salon_id())->fetchColumn()
      : -1;
ok('salon 1 sees only its own customer', $seen === 1, "got {$seen}");

ok('owns its own customer', salon_owns('customers', $c1));
ok('does NOT own the other salon customer', !salon_owns('customers', $c2));

$fetched = tenant_fetch('customers', $c1);
ok('tenant_fetch returns own row', is_array($fetched) && (int)$fetched['id'] === $c1);
ok('tenant_fetch hides other tenant row', tenant_fetch('customers', $c2) === null);

section('5. cross-tenant access is blocked with 403');
/* require_tenant_record() exits on the CLI path, so probe the predicate it is
   built on instead, then prove the real guard terminates by running it in a
   child process and checking its exit code. */
ok('salon_owns() is false for the foreign row', salon_owns('customers', $c2) === false);
ok('salon_owns() is true for the owned row', salon_owns('customers', $c1) === true);

$GLOBALS['__owner_user_id'] = $userId;
$GLOBALS['__salon_one_id']  = $salonId;

/* owned row: the guard must return normally */
$GLOBALS['__probe_row'] = $c1;
$owned = @static function (): int {
    require_tenant_record('customers', (int)$GLOBALS['__probe_row']);
    return 0;
};
ok('guard permits an owned row', $owned() === 0);

/* foreign row: the guard must terminate the request */
$script = escapeshellarg(__DIR__ . '/_probe_403.php');
$cmd    = escapeshellarg(PHP_BINARY) . ' ' . $script . ' ' . (int)$userId . ' ' . $salonId . ' ' . $c2;
exec($cmd . ' 2>&1', $out, $code);
ok('guard blocks a foreign row (exit 5)', $code === 5, trim(implode(' ', $out)));
ok('block message names the table', str_contains(implode(' ', $out), 'customers'));

section('6. admin_user_count is per salon');
$_SESSION['user_id']  = $userId;
$_SESSION['salon_id'] = $salonId;
$n1 = admin_user_count(true);
$_SESSION['user_id']  = (int)($r2['user_id'] ?? 0);
$_SESSION['salon_id'] = $salon2;
$n2 = admin_user_count(true);
ok('salon 1 counts only its own admins', $n1 === 1, "got {$n1}");
ok('salon 2 counts only its own admins', $n2 === 1, "got {$n2}");

section('7. stale session cannot outlive a moved account');
$_SESSION['user_id']  = $userId;
$_SESSION['salon_id'] = $salon2;                 /* forged: session says salon 2 */
$real = current_salon_id();
ok('database wins over a forged session salon_id', $real === $salonId, "resolved to {$real}");

section('8. login binds the session to the salon');
$_SESSION = [];
$login = attempt_login('sarah@bellabeauty.test', 'Str0ngPass!23');
ok('login succeeded', !empty($login['success']), $login['error'] ?? '');
ok('session carries salon_id', (int)($_SESSION['salon_id'] ?? 0) === $salonId, (string)($_SESSION['salon_id'] ?? 'unset'));
ok('logged-in user resolves the same salon', current_salon_id() === $salonId);

$bad = attempt_login('sarah@bellabeauty.test', 'wrong-password');
ok('wrong password rejected', empty($bad['success']));

section('9. duplicate salon name is refused');
$dupName = submit(['salon_name' => 'Bella Beauty', 'email' => 'other@glow.test']);
ok('duplicate salon name refused', empty($dupName['success']));
ok('error explains the name clash', stripos((string)($dupName['error'] ?? ''), 'already registered') !== false, $dupName['error'] ?? '');

section('10. validation rejects bad input');
ok('blank salon name refused',  empty(submit(['salon_name' => ''])['success']));
ok('bad email refused',         empty(submit(['email' => 'not-an-email'])['success']));
ok('short password refused',    empty(submit(['password' => 'short', 'password_confirm' => 'short'])['success']));
ok('mismatched passwords refused', empty(submit(['password_confirm' => 'different'])['success']));

/* Own email, so the failure can only come from the hours: the default
   submit() email is already registered and that check runs first. */
$badHours = payload(['salon_name' => 'Bad Hours', 'email' => 'badhours@glow.test']);
$badHours['hours'][2]['closing_time'] = '08:00';   /* closes before it opens */
$res2 = register_salon($badHours);
ok('closing before opening refused', empty($res2['success']), $res2['error'] ?? '');
ok('the hours error is the reported one',
   stripos((string)($res2['error'] ?? ''), 'closing') !== false, $res2['error'] ?? '');

section('11. the signup form posts no hours, so the default week is applied');

/* This is exactly what signup.php sends now: no 'hours' key at all. The salon
   must still come out of registration with a bookable week, otherwise every new
   tenant would publish an empty availability calendar. */
$noHours = payload([
    'salon_name' => 'Sunrise Spa',
    'email'      => 'sunrise@sunnyday.test',
]);
unset($noHours['hours']);
$noHoursR = register_salon($noHours);
ok('registration without hours succeeds', !empty($noHoursR['success']), $noHoursR['error'] ?? '');

$sunriseId = (int)($noHoursR['salon_id'] ?? 0);
$h2 = db()->query("SELECT COUNT(*) c, SUM(is_open) o FROM business_hours WHERE salon_id = {$sunriseId}")->fetch();
ok('still gets 7 weekly-hours rows', (int)$h2['c'] === 7, "got {$h2['c']}");
ok('default week opens 6 days', (int)$h2['o'] === 6, "open={$h2['o']}");

$sun = db()->query("SELECT is_open, opening_time, closing_time FROM business_hours WHERE salon_id = {$sunriseId} AND day_of_week = 7")->fetch();
ok('Sunday defaults to closed', (int)$sun['is_open'] === 0);

$mon = db()->query("SELECT is_open, opening_time, closing_time FROM business_hours WHERE salon_id = {$sunriseId} AND day_of_week = 1")->fetch();
ok('Monday defaults to 09:00-18:00',
   (int)$mon['is_open'] === 1
   && substr((string)$mon['opening_time'], 0, 5) === '09:00'
   && substr((string)$mon['closing_time'], 0, 5) === '18:00',
   trim((string)$mon['opening_time'] . '-' . (string)$mon['closing_time']));

ok('default_weekly_hours() covers all seven days', count(default_weekly_hours()) === 7);
ok('effective_weekly_hours() falls back when none given',
   effective_weekly_hours([]) === default_weekly_hours());
ok('effective_weekly_hours() keeps a caller-supplied week',
   effective_weekly_hours($noHours + ['hours' => ['x' => 1]]) === ['x' => 1]);

/* Fresh name and email: $noHours was already registered above, and both the
   name and email uniqueness checks run before the hours check, so they would
   mask the result. */
$validateOnly = $noHours;
$validateOnly['email'] = 'never-registered@sunnyday.test';
$validateOnly['salon_name'] = 'Sunrise Spa Validation Only';
$why = validate_salon_registration($validateOnly);
ok('the default week is itself valid', $why === [], implode('; ', $why));

echo "\n" . ($fail === 0 ? "ALL {$pass} CHECKS PASSED\n" : "{$fail} FAILED, {$pass} passed\n");
exit($fail === 0 ? 0 : 1);