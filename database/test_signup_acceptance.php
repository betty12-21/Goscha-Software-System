<?php
/**
 * §43 SIGN-UP ACCEPTANCE TEST
 *
 * Runs the specification's exact scenario against a scratch database:
 *   Betty / GOSCHA Beauty Salon
 *   Mon-Fri 09:00-18:00, Sat 10:00-16:00, Sun closed
 * then asserts the whole chain (§31-§42) end to end.
 *
 * Live database is never referenced.
 */

$root    = 'C:/xampp/htdocs/Beauty salon';
$tmp     = __DIR__;
$scratch = 'beauty_php_ai_scratch';

/* ---------- fresh scratch DB ---------- */
$sql = file_get_contents($root . '/database/beauty_php_ai.sql');
$sql = preg_replace('/^\s*CREATE DATABASE.*?;\s*$/mi', '', $sql);
$sql = preg_replace('/^\s*USE\s+`?beauty_php_ai`?\s*;/mi', "USE `{$scratch}`;", $sql);
if (stripos($sql, 'USE `beauty_php_ai`;') !== false) {
    exit("ABORTED: live USE present\n");
}
$pdo = new PDO('mysql:host=localhost;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
$pdo->exec("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4");
$pdo->exec($sql);
/* guarantee the wizard is reachable */
$pdo->exec("UPDATE salon_settings SET setup_completed_at = NULL");

/* The wizard runs in its own process because setup.php ends in redirect()+exit.
   It must target the SAME scratch database, so the name is put in the
   environment of this process too -- otherwise db() below falls back to the
   live installation and the whole test silently reads production rows. */
putenv('BEAUTY_DB_NAME=' . $scratch);
$_ENV['BEAUTY_DB_NAME']    = $scratch;
$_SERVER['BEAUTY_DB_NAME'] = $scratch;

/* ---------- run the real wizard in its own process ---------- */
/* The launcher + .bat wrapper carry BEAUTY_DB_NAME into the child, because
   an inline "set X=Y && ..." line does not survive exec()'s quoting. */
exec('"' . PHP_BINARY . '" "' . $tmp . '/_wizard_launcher.php" ' . $scratch . ' 2>&1', $wizOut, $wizCode);

/* A silent wizard failure would otherwise surface as a dozen confusing
   "not saved" assertion failures, so report it here. */
$probe = (new PDO('mysql:host=localhost;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
    ->query("SELECT COUNT(*) FROM `{$scratch}`.users WHERE email='betty@goscha.test'")
    ->fetchColumn();
if (!$probe) {
    fwrite(STDERR, "ABORTED: the wizard subprocess created no Administrator (exit={$wizCode}).\n");
    fwrite(STDERR, implode("\n", array_slice($wizOut, 0, 20)) . "\n");
    exit(1);
}

if (!defined('APP_INIT')) {
    define('APP_INIT', true);
}
require_once $root . '/includes/init.php';

$live = (string)db()->query('SELECT DATABASE()')->fetchColumn();
if ($live !== $scratch) {
    exit("ABORTED: test process is connected to '{$live}', not the scratch database.\n");
}

$R = [];
$ok = static function (string $l, bool $p, string $d = '') use (&$R): void {
    $R[] = $p;
    printf("  %s  %s%s\n", $p ? 'PASS' : 'FAIL', $l, $d !== '' ? "  ({$d})" : '');
};
$head = static function (string $t): void { echo "\n-- {$t} --\n"; };

/* ================= §31 / §32 / §33 — SIGN-UP ================= */
$head('§31/32/33  Sign-up collects name + independent weekly schedule');

$p       = db();
$profile = $p->query('SELECT * FROM salon_settings WHERE id=1')->fetch();
$admin   = $p->query("SELECT * FROM users WHERE email='betty@goscha.test'")->fetch();
$byDay   = [];
foreach ($p->query('SELECT day_of_week,is_open,opening_time,closing_time FROM business_hours ORDER BY day_of_week') as $h) {
    $byDay[(int)$h['day_of_week']] = $h;
}

$hh = static fn($v): string => $v ? substr((string)$v, 0, 5) : '-';

$ok('Administrator account created', (bool)$admin,
    $admin ? "{$admin['first_name']} {$admin['last_name']} role={$admin['role']}" : 'missing');
$ok('salon name saved (not hard-coded)', ($profile['salon_name'] ?? '') === 'GOSCHA Beauty Salon',
    (string)($profile['salon_name'] ?? 'null'));
$ok('owner first/last stored separately',
    ($profile['owner_first_name'] ?? '') === 'Betty' && ($profile['owner_last_name'] ?? '') === 'Owner',
    ($profile['owner_first_name'] ?? '') . ' ' . ($profile['owner_last_name'] ?? ''));
$ok('schedule NOT stored on the users row',
    $admin && !array_intersect(array_keys($admin), ['opening_time', 'closing_time', 'day_of_week', 'is_open']));

$open = array_keys(array_filter($byDay, static fn($h) => (int)$h['is_open'] === 1));
sort($open);
$ok('Mon-Sat saved as operating days', $open === [1, 2, 3, 4, 5, 6], 'open: ' . implode(',', $open));
$ok('Sunday saved as closed', isset($byDay[7]) && (int)$byDay[7]['is_open'] === 0);
$ok('Saturday keeps its OWN hours 10:00-16:00',
    (int)$byDay[6]['is_open'] === 1 && $hh($byDay[6]['opening_time']) === '10:00' && $hh($byDay[6]['closing_time']) === '16:00',
    $hh($byDay[6]['opening_time']) . '-' . $hh($byDay[6]['closing_time']));
$ok('Monday 09:00-18:00 preserved',
    $hh($byDay[1]['opening_time']) === '09:00' && $hh($byDay[1]['closing_time']) === '18:00',
    $hh($byDay[1]['opening_time']) . '-' . $hh($byDay[1]['closing_time']));
$ok('Tuesday..Friday each saved independently (9-18)',
    $hh($byDay[2]['opening_time']) === '09:00' && $hh($byDay[3]['opening_time']) === '09:00'
    && $hh($byDay[4]['opening_time']) === '09:00' && $hh($byDay[5]['opening_time']) === '09:00');

/* ================= §38 — VALIDATION ================= */
$head('§38  Invalid schedules are rejected');
foreach ([['19:00', '09:00', 'closes before it opens'], ['09:00', '09:00', 'opens == closes']] as $c) {
    $e = business_hours_validate_week([1 => ['is_open' => 1, 'opening_time' => $c[0] . ':00', 'closing_time' => $c[1] . ':00']]);
    $ok("rejected: {$c[2]}", !empty($e), implode('; ', (array)$e));
}
$e = business_hours_validate_week([7 => ['is_open' => 0, 'opening_time' => null, 'closing_time' => null]]);
$ok('rejected: a fully closed week (no open day)', !empty($e), implode('; ', (array)$e));

/* ================= §35 — HOURS CONTROL APPOINTMENTS ================= */
$head('§35  Business hours control appointment validation');

$p->prepare("INSERT INTO service_categories (name,created_at,updated_at) VALUES ('General',NOW(),NOW())")->execute();
$catId = (int)$p->lastInsertId();
$p->prepare("INSERT INTO services (category_id,name,duration_minutes,price,status,created_at,updated_at)
             VALUES (?,?,30,100.00,'active',NOW(),NOW())")->execute([$catId, 'Cut']);
$svc = (int)$p->lastInsertId();
$p->prepare("INSERT INTO staff (first_name,last_name,phone,email,status,created_at) VALUES ('Jo','Stylist','09','jo@t.test','active',NOW())")->execute();
$staffId = (int)$p->lastInsertId();

$week = [];
for ($d = 1; $d <= 6; $d++) {
    $week[$d] = $d === 6
        ? ['is_open' => 1, 'opening_time' => '10:00:00', 'closing_time' => '16:00:00']
        : ['is_open' => 1, 'opening_time' => '09:00:00', 'closing_time' => '18:00:00'];
}
$week[7] = ['is_open' => 0, 'opening_time' => null, 'closing_time' => null];

/* Staff roster: works the same days, but inside the salon hours, so the two
   can be told apart. §42 says staff may never exceed salon hours. */
$roster = static function (array $salonWeek): array {
    $out = [];
    foreach ($salonWeek as $day => $row) {
        $out[$day] = empty($row['is_open'])
            ? ['is_working' => 0, 'start_time' => null, 'end_time' => null]
            : ['is_working' => 1, 'start_time' => $row['opening_time'], 'end_time' => $row['closing_time']];
    }
    return $out;
};
$res = staff_working_hours_save($staffId, $roster($week));
$ok('staff rostered inside the salon week', ($res['ok'] ?? false) === true, implode('; ', (array)($res['errors'] ?? [])));

/* Anchor on the Monday of NEXT week and count forward, so every probe date is
   in the future and each one lands on the weekday it is meant to test. */
$next = static function (int $dow): string {
    $monday = strtotime('next monday', strtotime('today'));
    return date('Y-m-d', strtotime('+' . ($dow - 1) . ' day', $monday));
};
$MON = $next(1);
$SAT = $next(6);
$SUN = $next(7);

$v = static fn(string $d, string $s, int $m, ?int $st = null): ?string
    => scheduling_validate(['date' => $d, 'start' => $s, 'duration_minutes' => $m,
        'staff_id' => $st, 'service_ids' => [$svc]]);

$ok('inside hours ALLOWED',            $v($MON, '10:00', 30, $staffId) === null, (string)$v($MON, '10:00', 30, $staffId));
$ok('before opening 08:00 REJECTED',   $v($MON, '08:00', 30, $staffId) !== null);
$ok('after closing 18:30 REJECTED',    $v($MON, '18:30', 30, $staffId) !== null);
$ok('18:00 + 30min REJECTED (overruns close)', $v($MON, '18:00', 30, $staffId) !== null);
$ok('exactly at opening 09:00 ALLOWED', $v($MON, '09:00', 30, $staffId) === null);
$ok('salon CLOSED Sunday REJECTED',    $v($SUN, '10:00', 30, $staffId) !== null);

/* Saturday must use 10:00-16:00, not the weekday 09:00-18:00 */
$ok('Saturday 09:00 REJECTED (opens 10:00)', $v($SAT, '09:00', 30, $staffId) !== null);
$ok('Saturday 10:00 ALLOWED',                 $v($SAT, '10:00', 30, $staffId) === null);
$ok('Saturday 15:45+30min REJECTED (overruns 16:00)', $v($SAT, '15:45', 30, $staffId) !== null);
$ok('Saturday 17:00 REJECTED (salon closed by 16:00)', $v($SAT, '17:00', 30, $staffId) !== null);

$ok('service overruns remaining day REJECTED', $v($MON, '17:45', 60, $staffId) !== null);
$ok('service that exactly fits ALLOWED',       $v($MON, '17:30', 30, $staffId) === null);

/* ================= §42 — STAFF RULES ================= */
$head('§42  Salon closed / staff availability rules');

/* the salon stays open on Monday, but this staff member is not rostered */
$offMonday               = $roster($week);
$offMonday[1]['is_working'] = 0;
$offMonday[1]['start_time'] = null;
$offMonday[1]['end_time']   = null;
staff_working_hours_save($staffId, $offMonday);
$ok('staff off on an open salon day REJECTED', $v($MON, '10:00', 30, $staffId) !== null, (string)$v($MON, '10:00', 30, $staffId));
staff_working_hours_save($staffId, $roster($week));

staff_breaks_save($staffId, 1, [['start_time' => '12:00:00', 'end_time' => '13:00:00']]);
$ok('a time inside the staff break REJECTED', $v($MON, '12:00', 30, $staffId) !== null);
$ok('a time outside the break ALLOWED',      $v($MON, '13:00', 30, $staffId) === null);
staff_breaks_save($staffId, 1, []);

$p->prepare("INSERT INTO customers (first_name,last_name,phone,email,created_at,updated_at) VALUES ('Ann','Booked','09','ann@t.test',NOW(),NOW())")->execute();
$cid = (int)$p->lastInsertId();
$p->prepare("INSERT INTO appointments (customer_id,staff_id,appointment_date,start_time,end_time,status,created_at,updated_at) VALUES (?,?,?,'14:00:00','14:30:00','confirmed',NOW(),NOW())")->execute([$cid, $staffId, $MON]);
$mine = (int)$p->lastInsertId();
$ok('already-booked staff REJECTED (no double booking)', $v($MON, '14:00', 30, $staffId) !== null, (string)$v($MON, '14:00', 30, $staffId));
/* With no staff assigned the booking consumes the whole capacity window, so
   it must still be refused while Jo is busy. This is the designed rule, not
   the staff-conflict rule -- the wording differs. */
$r = scheduling_validate(['date' => $MON, 'start' => '14:00', 'duration_minutes' => 30,
    'staff_id' => null, 'service_ids' => [$svc]]);
$ok('unassigned booking at a taken time REJECTED (capacity)', $r !== null, (string)$r);
$ok('and it is reported as capacity, not a staff clash', $r === null || strpos((string)$r, 'existing appointment for') === false, (string)$r);
$ok('a different time with no staff is ALLOWED',
    scheduling_validate(['date' => $MON, 'start' => '16:00', 'duration_minutes' => 30,
        'staff_id' => null, 'service_ids' => [$svc]]) === null);

$bad = staff_working_hours_save($staffId, $roster(array_merge($week, [
    2 => ['is_open' => 1, 'opening_time' => '07:00:00', 'closing_time' => '22:00:00'],
])));
$ok('staff hours OUTSIDE salon hours REJECTED', ($bad['ok'] ?? false) === false, implode('; ', (array)($bad['errors'] ?? [])));
staff_working_hours_save($staffId, $roster($week));

/* ================= §36 — IMMEDIATE EFFECT ================= */
$head('§36  Changing hours immediately changes NEW availability');

$before = scheduling_slots($MON, $staffId, 30);
$ok('09:00 offered before the change', in_array('09:00:00', $before, true),
    count($before) . ' slots, first=' . ($before[0] ?? 'none') . ' last=' . (end($before) ?: 'none'));

$newWeek = $week;
$newWeek[1] = ['is_open' => 1, 'opening_time' => '10:00:00', 'closing_time' => '17:00:00'];
$saved = business_hours_save($newWeek);
$ok('narrowed week saves cleanly', ($saved['ok'] ?? false) === true, implode('; ', (array)($saved['errors'] ?? [])));

$after = scheduling_slots($MON, $staffId, 30);
$ok('08:00 NO LONGER offered', !in_array('08:00:00', $after, true));
$ok('09:00 NO LONGER offered', !in_array('09:00:00', $after, true));
$ok('10:00 now offered',        in_array('10:00:00', $after, true));
$ok('16:45+30 NO LONGER offered (past 17:00 close)', !in_array('16:45:00', $after, true));
$ok('booking at 09:00 now REJECTED', $v($MON, '09:00', 30, $staffId) !== null);
$ok('booking at 10:00 now ALLOWED',  $v($MON, '10:00', 30, $staffId) === null);
$ok('calendar slots (no staff filter) reflect it too', !in_array('08:00:00', scheduling_slots($MON, null, 30), true));

/* ================= §37 — NO DELETION ================= */
$head('§37  Changing hours never deletes existing appointments');

$before1 = (int)$p->query("SELECT COUNT(*) FROM appointments WHERE id = {$mine}")->fetchColumn();
$total1  = (int)$p->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
$ok('pre-existing appointment still present', $before1 === 1, "id={$mine}, {$total1} row(s) total");

$conf = business_hours_conflicts($newWeek);
$ok('conflicts reported to the Administrator', is_array($conf) && $conf !== [], count((array)$conf) . ' conflict(s)');

$tighter = $newWeek;
$tighter[1] = ['is_open' => 1, 'opening_time' => '10:00:00', 'closing_time' => '13:00:00'];
$conf2 = business_hours_conflicts($tighter);
$ok('a genuinely orphaned booking IS flagged', is_array($conf2) && $conf2 !== [], (string)($conf2[0]['reason'] ?? 'none'));
$total2 = (int)$p->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
$ok('and it was NOT deleted', (int)$p->query("SELECT COUNT(*) FROM appointments WHERE id = {$mine}")->fetchColumn() === 1
    && $total2 === $total1, "{$total2} row(s) total");
$ok('its customer row survived too',
    (int)$p->query("SELECT COUNT(*) FROM customers WHERE id = {$cid}")->fetchColumn() === 1);

/* ================= §34 — CONFIG LOCATION ================= */
$head('§34  Business config lives in its own tables');
$names = array_column($p->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$scratch}'")->fetchAll(), 'TABLE_NAME');
$ok('salon_settings table exists',  in_array('salon_settings', $names, true));
$ok('business_hours table exists',  in_array('business_hours', $names, true));
$uCols = array_column($p->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
$ok('users table has NO hours/name columns',
    !array_intersect($uCols, ['opening_time', 'closing_time', 'day_of_week', 'is_open', 'salon_name']));

/* ================= §40 — NO HARDCODING ================= */
$head('§40  Saved name is used everywhere');
$ok('salon_name() returns the saved value', salon_name() === 'GOSCHA Beauty Salon', salon_name());

/* ================= CLEANUP ================= */
$pdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");

$failed = count(array_filter($R, static fn($x) => $x === false));
echo "\n" . str_repeat('=', 62) . "\n";
echo $failed === 0
    ? "§43 ACCEPTANCE: ALL " . count($R) . " CHECKS PASSED\n"
    : "§43 ACCEPTANCE: {$failed} of " . count($R) . " CHECKS FAILED\n";
if ($failed && $wizOut) { echo "\nwizard output:\n" . implode("\n", array_slice($wizOut, -15)) . "\n"; }