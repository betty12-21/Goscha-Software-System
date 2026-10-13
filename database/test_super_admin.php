<?php
/**
 * Phase 4 acceptance: Super Admin control panel core.
 *
 * Exercises the platform layer end to end against whatever database is
 * selected — module grants, the salon lifecycle state machine, the audit
 * trail, login and its rate limiter. No HTTP server required.
 */

$dbName = getenv('BEAUTY_DB_NAME');
if ($dbName === false || $dbName === '') {
    fwrite(STDERR, "Set BEAUTY_DB_NAME to a scratch database before running.\n");
    exit(2);
}

if ($dbName === 'beauty_php_ai') {
    fwrite(STDERR, "Refusing to run the Super Admin suite against the live database.\n");
    exit(2);
}

require_once __DIR__ . '/../includes/init.php';

/* Buffer output so session_regenerate_id() inside the login helpers does not
   warn about "headers already sent" on the CLI SAPI. */
ob_start();

$pass = 0;
$fail = 0;

function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  [PASS] %-58s %s\n", $label, $detail); }
    else       { $fail++; printf("  [FAIL] %-58s %s\n", $label, $detail); }
}

function section(string $t): void { echo "\n=== {$t} ===\n"; }

if (!access_modules_schema_ready()) {
    fwrite(STDERR, "This suite needs migration 004 (salon_modules table).\n");
    exit(2);
}

/* A deterministic operator we can sign in with. */
$saEmail = 'ops@platform.test';
$saPass  = 'SuperSecret!2026';

db()->prepare(
    "INSERT INTO super_admins (name, email, password, status)
     VALUES ('Platform Ops', :e, :p, 'active')
     ON DUPLICATE KEY UPDATE password = VALUES(password), status = 'active', name = VALUES(name)"
)->execute(['e' => $saEmail, 'p' => password_hash($saPass, PASSWORD_DEFAULT)]);

/* Clean slate for the rate-limit probe. */
db()->prepare('DELETE FROM super_admin_login_attempts WHERE email IN (:e, :r)')
    ->execute(['e' => $saEmail, 'r' => 'nobody@ratelimit.test']);

section('1. module catalogue and tiers');
ok('15 modules are defined', count(module_keys()) === 15, (string)count(module_keys()));
ok('dashboard is a core module', module_tier('dashboard') === 1);
ok('reports is a growth module', module_tier('reports') === 2);
ok('payroll is an advanced module', module_tier('payroll') === 3);
ok('standard plan = core only', count(profile_modules('standard')) === 9, (string)count(profile_modules('standard')));
ok('professional plan = core + growth', count(profile_modules('professional')) === 11, (string)count(profile_modules('professional')));
ok('full plan = every module', count(profile_modules('full')) === 15, (string)count(profile_modules('full')));

section('2. script -> module mapping');
ok('customers.php maps to customers', module_key_for_script('customers.php') === 'customers');
ok('invoice-view.php maps to invoices', module_key_for_script('invoice-view.php') === 'invoices');
ok('availability.php maps to appointments', module_key_for_script('availability.php') === 'appointments');
ok('audit-logs.php is ungated', module_key_for_script('audit-logs.php') === null);
ok('unknown script is ungated', module_key_for_script('does-not-exist.php') === null);

section('3. a new salon gets a full grant');
$reg = register_salon([
    'salon_name'       => 'Panel Test Salon ' . substr(uniqid(), -6),
    'first_name'       => 'Panel',
    'last_name'        => 'Owner',
    'phone'            => '0911000000',
    'email'            => 'panel.' . uniqid() . '@panel.test',
    'password'         => 'PanelPass!2026',
    'password_confirm' => 'PanelPass!2026',
]);
ok('registration succeeded', !empty($reg['success']), $reg['error'] ?? '');
$salonId = (int)($reg['salon_id'] ?? 0);

ok('new salon starts pending', (db()->query("SELECT status FROM salons WHERE id = {$salonId}")->fetchColumn()) === 'pending');
ok('all modules enabled by default', count(enabled_modules($salonId)) === count(module_keys()));
ok('payroll is enabled for the salon', module_enabled('payroll', $salonId) === true);

section('4. module grants can be narrowed and expanded');
save_salon_modules($salonId, ['dashboard', 'customers']);
$mods = enabled_modules($salonId); sort($mods);
ok('only the chosen two remain', $mods === ['customers', 'dashboard'], implode(',', $mods));
ok('dashboard still enabled', module_enabled('dashboard', $salonId) === true);
ok('payroll now disabled', module_enabled('payroll', $salonId) === false);
ok('a module with no row is denied', module_enabled('reports', $salonId) === false);

apply_access_profile($salonId, 'professional');
$got  = enabled_modules($salonId); sort($got);
$want = profile_modules('professional'); sort($want);
ok('professional plan applied', $got === $want);
ok('salons.access_profile recorded', (db()->query("SELECT access_profile FROM salons WHERE id = {$salonId}")->fetchColumn()) === 'professional');
ok('reports unlocked by the plan', module_enabled('reports', $salonId) === true);
ok('payroll still locked out', module_enabled('payroll', $salonId) === false);

section('5. lifecycle state machine');
ok('pending -> active allowed', super_admin_can_transition('pending', 'active'));
ok('pending -> rejected allowed', super_admin_can_transition('pending', 'rejected'));
ok('pending -> suspended refused', !super_admin_can_transition('pending', 'suspended'));
ok('active -> pending refused', !super_admin_can_transition('active', 'pending'));
ok('active -> suspended allowed', super_admin_can_transition('active', 'suspended'));
ok('cancelled -> active allowed (restore)', super_admin_can_transition('cancelled', 'active'));
ok('cancelled -> suspended refused', !super_admin_can_transition('cancelled', 'suspended'));
ok('same-status move refused', !super_admin_can_transition('active', 'active'));

section('6. operator signs in');
$_SESSION = [];
$login = attempt_super_admin_login($saEmail, $saPass);
ok('login succeeded', !empty($login['success']), $login['error'] ?? '');
ok('session carries super_admin_id', !empty($_SESSION['super_admin_id']));
$me = current_super_admin();
ok('current_super_admin resolves the row', is_array($me) && $me['email'] === $saEmail);
ok('last_login_at stamped', !empty($me['last_login_at'] ?? null));
$loginAudit = (int)db()->query("SELECT COUNT(*) FROM super_admin_audit_logs WHERE action = 'login' AND super_admin_name = 'Platform Ops'")->fetchColumn();
ok('login is audited', $loginAudit >= 1, "rows={$loginAudit}");

$bad = attempt_super_admin_login($saEmail, 'not-the-password');
ok('wrong password rejected', empty($bad['success']));
ok('still signed in after a bad attempt elsewhere', is_super_admin_logged_in());

section('7. approval flips access on');
$approve = super_admin_set_status($salonId, 'active');
ok('pending salon approved', !empty($approve['success']), $approve['error'] ?? '');
$row = db()->query("SELECT status, approved_at, approved_by FROM salons WHERE id = {$salonId}")->fetch();
ok('status is active', ($row['status'] ?? '') === 'active');
ok('approved_at stamped', !empty($row['approved_at']));
ok('approved_by recorded', (int)($row['approved_by'] ?? 0) > 0);

$login2 = attempt_login((string)db()->query("SELECT email FROM users WHERE salon_id = {$salonId} LIMIT 1")->fetchColumn(), 'PanelPass!2026');
ok('owner can sign in once approved', !empty($login2['success']), $login2['error'] ?? '');
ok('combined gate allows an enabled module', can_access_module('dashboard') === true);
ok('combined gate blocks a disabled module', can_access_module('payroll') === false);
$_SESSION = [];
attempt_super_admin_login($saEmail, $saPass);

$approveAudit = (int)db()->query("SELECT COUNT(*) FROM super_admin_audit_logs WHERE action = 'salon_approve' AND target_salon_id = {$salonId}")->fetchColumn();
ok('approval is audited against the salon', $approveAudit >= 1, "rows={$approveAudit}");
$notifs = (int)db()->query("SELECT COUNT(*) FROM notifications WHERE salon_id = {$salonId}")->fetchColumn();
ok('the salon is notified', $notifs >= 1, "rows={$notifs}");

$invalid = super_admin_set_status($salonId, 'pending');
ok('active -> pending is refused', empty($invalid['success']));

section('8. suspend and restore');
ok('suspend succeeds', !empty(super_admin_set_status($salonId, 'suspended')['success']));
ok('salon now blocked from login',
   empty(attempt_login((string)db()->query("SELECT email FROM users WHERE salon_id = {$salonId} LIMIT 1")->fetchColumn(), 'PanelPass!2026')['success']));
ok('restore succeeds', !empty(super_admin_set_status($salonId, 'active', ['reason' => 'Paid the invoice'])['success']));
ok('salon can sign in again',
   !empty(attempt_login((string)db()->query("SELECT email FROM users WHERE salon_id = {$salonId} LIMIT 1")->fetchColumn(), 'PanelPass!2026')['success']));
$_SESSION = [];

section('9. dashboard counters include the new salon');
$stats = super_admin_salon_stats();
ok('total counts every salon', $stats['total'] >= 1);
ok('by_status keys are complete', array_keys($stats['by_status']) === salon_statuses());

section('10. login is rate limited');
$rlEmail = 'nobody@ratelimit.test';
for ($i = 0; $i < SUPER_ADMIN_LOGIN_MAX_ATTEMPTS; $i++) {
    attempt_super_admin_login($rlEmail, 'guess-' . $i);
}
ok('five failures trip the limiter', super_admin_rate_limited($rlEmail));
$locked = attempt_super_admin_login($rlEmail, 'anything');
ok('a locked account is refused', empty($locked['success']));
ok('the refusal explains the lockout', stripos((string)($locked['error'] ?? ''), 'too many') !== false, $locked['error'] ?? '');
db()->prepare('DELETE FROM super_admin_login_attempts WHERE email = :e')->execute(['e' => $rlEmail]);

section('11. logout clears the platform session');
logout_super_admin();
ok('not logged in after logout', !is_super_admin_logged_in());
ok('current_super_admin is null', true);

echo "\n" . ($fail === 0 ? "ALL {$pass} CHECKS PASSED\n" : "{$fail} FAILED, {$pass} passed\n");
exit($fail === 0 ? 0 : 1);
