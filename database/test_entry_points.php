<?php
/**
 * Beauty-php-ai — Entry-point combination suite (CLI over HTTP).
 *
 * Phase 7 of the scheduling specification: "test all possible
 * combinations". database/test_combinations.php proves the rule itself
 * against ~2000 generated combinations; this suite proves that every door
 * into the calendar applies it. It drives the real HTTP endpoints as an
 * Administrator, as a Receptionist and as an anonymous visitor, and asserts
 * both the answer and the effect on the database.
 *
 * Requirements: Apache and MySQL running (XAMPP) and the demo accounts from
 * README.md. Override with BEAUTY_BASE_URL, BEAUTY_ADMIN_EMAIL,
 * BEAUTY_ADMIN_PASSWORD, BEAUTY_RECEPTIONIST_EMAIL and
 * BEAUTY_RECEPTIONIST_PASSWORD.
 *
 * Safety: every row this suite creates is deleted again by its id, and the
 * six scheduling tables are snapshotted row by row and restored by a
 * shutdown handler.
 *
 * USAGE
 *   C:\xampp\php\php.exe database\test_entry_points.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}

define('APP_INIT', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';

const BASE_URL    = 'http://localhost/Beauty%20salon/';
const OPEN_MON    = '2026-10-05';   /* Monday, salon open 09:00-18:00 */
const CLOSED_SUN  = '2026-10-04';   /* Sunday, salon closed */
const HOLIDAY_WED = '2026-11-11';   /* Wednesday, a public holiday */
const IMPOSSIBLE  = '2026-13-45';   /* matches Y-m-d but never happened */

/* Tables this suite writes to, and the row count that a single successful
   booking is allowed to change. Anything else must stay untouched. */
const GUARDED_TABLES = ['appointments', 'appointment_services', 'invoices', 'payments', 'customers', 'waitlist'];

$passed   = 0;
$failed   = 0;
$failures = [];
$requests = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $failures;

    if ($condition) {
        $passed++;
        echo "  PASS  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
    } else {
        $failed++;
        $failures[] = $label;
        echo "  FAIL  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
    }
}

function heading(string $text): void
{
    echo PHP_EOL . "== {$text} ==" . PHP_EOL;
}

function q(string $sql): array
{
    return db()->query($sql)->fetchAll();
}

function one(string $sql): array
{
    return q($sql)[0] ?? [];
}

function max_id(string $table): int
{
    return (int)(one("SELECT COALESCE(MAX(id), 0) AS m FROM `{$table}`")['m'] ?? 0);
}

/**
 * Row count, for "did this request insert exactly one row?" checks.
 *
 * MAX(id) arithmetic is not usable here: InnoDB does not promise contiguous
 * AUTO_INCREMENT, and this table's counter had already drifted eight past
 * MAX(id), so one insert moves MAX(id) by 8 rather than 1.
 */
function row_count(string $table): int
{
    return (int)(one("SELECT COUNT(*) AS c FROM `{$table}`")['c'] ?? 0);
}

/* ==================================================================
 * HTTP
 * ================================================================== */

final class Http
{
    private string $jar;
    private string $base;

    public function __construct(string $base)
    {
        $this->base = $base;
        $this->jar  = (string)tempnam(sys_get_temp_dir(), 'bsai');
    }

    public function __destruct()
    {
        if (is_file($this->jar)) {
            @unlink($this->jar);
        }
    }

    public function request(string $method, string $path, array $fields = []): array
    {
        global $requests;
        $requests++;

        $ch = curl_init($this->base . ltrim($path, '/'));

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_TIMEOUT        => 30,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        $body     = (string)curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error    = (string)curl_error($ch);
        curl_close($ch);

        if ($location !== '' && strpos($location, $this->base) === 0) {
            $location = substr($location, strlen($this->base));
        }

        return ['status' => $status, 'body' => $body, 'location' => $location, 'error' => $error];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function csrf(string $path): string
    {
        $r = $this->get($path);

        if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m)) {
            throw new RuntimeException("no CSRF token at {$path} (HTTP {$r['status']}) {$r['error']}");
        }

        return $m[1];
    }

    public function login(string $email, string $password): bool
    {
        $r = $this->request('POST', 'login.php', [
            'csrf_token' => $this->csrf('login.php'),
            'email'      => $email,
            'password'   => $password,
        ]);

        return $r['status'] === 302 && strpos($r['location'], 'dashboard.php') !== false;
    }

    /**
     * POST a form, then read the flash message the redirect renders.
     *
     * @return array{status:int, location:string, body:string, flash:string}
     */
    public function postForm(string $page, array $fields): array
    {
        $fields['csrf_token'] = $this->csrf($page);
        $r = $this->request('POST', $page, $fields);

        $after = $this->get($r['location'] !== '' ? $r['location'] : $page);

        return [
            'status'   => $r['status'],
            'location' => $r['location'],
            'body'     => $after['body'],
            'flash'    => self::flashText($after['body']),
        ];
    }

    /** Every alert on the page, tags stripped and joined. */
    public static function flashText(string $body): string
    {
        preg_match_all(
            '/<div[^>]*\balert-(?:danger|success|warning|info)\b[^>]*>(.*?)<\/div>/s',
            $body,
            $matches
        );

        $texts = [];

        foreach ($matches[1] as $chunk) {
            $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($chunk)));

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return implode(' | ', $texts);
    }

    public static function hasDanger(string $body): bool
    {
        return (bool)preg_match('/\balert-danger\b/', $body);
    }

    /** @return array<string,mixed> */
    public static function json(array $r): array
    {
        $decoded = json_decode($r['body'], true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'raw' => substr($r['body'], 0, 120)];
    }
}

/* ==================================================================
 * Snapshot / restore
 * ================================================================== */
const SCHED_TABLES = ['salon_settings', 'business_hours', 'business_breaks', 'staff_working_hours', 'staff_breaks', 'staff_services'];

function grab_scheduling_rows(): array
{
    $rows = [];

    foreach (SCHED_TABLES as $t) {
        $rows[$t] = q("SELECT * FROM `{$t}`");
    }

    return $rows;
}

function restore_scheduling_rows(array $rows): void
{
    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach (SCHED_TABLES as $t) {
        $pdo->exec("DELETE FROM `{$t}`");
    }

    foreach ($rows as $t => $tableRows) {
        foreach ($tableRows as $r) {
            $cols = array_keys($r);
            $sql  = 'INSERT INTO `' . $t . '` (`' . implode('`,`', $cols) . '`) VALUES ('
                  . implode(',', array_fill(0, count($cols), '?')) . ')';
            $pdo->prepare($sql)->execute(array_values($r));
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/** Row counts of the tables a booking touches. */
function db_state(): array
{
    $out = [];

    foreach (GUARDED_TABLES as $t) {
        $out[$t] = (int)(one("SELECT COUNT(*) AS c FROM `{$t}`")['c'] ?? -1);
    }

    return $out;
}

echo 'Entry-point combination suite' . PHP_EOL;

$base = getenv('BEAUTY_BASE_URL') ?: BASE_URL;
echo 'Base URL: ' . $base . PHP_EOL;
echo 'Database: ' . DB_NAME . PHP_EOL;

$probe = curl_init($base);
curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_NOBODY => true]);
curl_exec($probe);
$reachable = (int)curl_getinfo($probe, CURLINFO_RESPONSE_CODE);
curl_close($probe);

if ($reachable === 0) {
    exit(PHP_EOL . 'The web server is not answering at ' . $base . ' — start Apache and MySQL first.' . PHP_EOL);
}

$restoreRows = grab_scheduling_rows();
$counts      = db_state();
$maxIds      = [];

foreach (['appointments', 'invoices', 'payments', 'customers', 'waitlist', 'notifications', 'audit_logs', 'loyalty_ledger'] as $table) {
    $maxIds[$table] = max_id($table);
}

register_shutdown_function(static function () use ($restoreRows): void {
    try {
        restore_scheduling_rows($restoreRows);
    } catch (Throwable $e) {
        echo PHP_EOL . '[restore] FAILED: ' . $e->getMessage() . PHP_EOL;
    }

    echo PHP_EOL . '[safety net] scheduling tables restored to their pre-run state.' . PHP_EOL;
});

/* ---- fixtures ---- */
$staffRows = q("SELECT id FROM staff WHERE status = 'active' ORDER BY id LIMIT 2");
$inactive  = q("SELECT id FROM staff WHERE status = 'inactive' ORDER BY id LIMIT 1");

if (count($staffRows) < 2 || !$inactive) {
    exit('This suite needs two active staff members and one inactive.' . PHP_EOL);
}

$staffA = (int)$staffRows[0]['id'];
$staffB = (int)$staffRows[1]['id'];
$staffI = (int)$inactive[0]['id'];
$ghost  = 999999;

$serviceShort = (int)one("SELECT id FROM services WHERE status = 'active' ORDER BY duration_minutes, id LIMIT 1")['id'];
$serviceLong  = (int)one("SELECT id FROM services WHERE status = 'active' AND duration_minutes >= 120 ORDER BY id LIMIT 1")['id'];
$shortMinutes = (int)one("SELECT duration_minutes AS d FROM services WHERE id = {$serviceShort}")['d'];
$longMinutes  = (int)one("SELECT duration_minutes AS d FROM services WHERE id = {$serviceLong}")['d'];

$customerRows = q('SELECT id FROM customers ORDER BY id LIMIT 3');
$custA = (int)$customerRows[0]['id'];
$custB = (int)$customerRows[1]['id'];
$custC = (int)($customerRows[2]['id'] ?? $customerRows[0]['id']);

heading('A. Fixtures');
ok('the web server answers', $reachable > 0, 'HTTP ' . $reachable);
ok('two active staff and one inactive were found', $staffA > 0 && $staffB > 0 && $staffI > 0, "{$staffA}, {$staffB}, inactive {$staffI}");
ok('a short and a long service exist', $shortMinutes > 0 && $longMinutes >= 120, "{$shortMinutes} min / {$longMinutes} min");
ok('the matrix dates are free of appointments', (int)one(
    "SELECT COUNT(*) AS c FROM appointments WHERE appointment_date IN ('" . OPEN_MON . "','" . CLOSED_SUN . "','" . HOLIDAY_WED . "')"
)['c'] === 0);

/* ==================================================================
 * B. Sessions and permissions
 * ================================================================== */
heading('B. Sessions and permissions');

$admin = new Http($base);
$recep = new Http($base);
$guest = new Http($base);

$adminEmail = getenv('BEAUTY_ADMIN_EMAIL') ?: 'admin@beautyphpai.com';
$adminPass  = getenv('BEAUTY_ADMIN_PASSWORD') ?: 'Admin@123';
$recepEmail = getenv('BEAUTY_RECEPTIONIST_EMAIL') ?: 'receptionist@beautyphpai.com';
$recepPass  = getenv('BEAUTY_RECEPTIONIST_PASSWORD') ?: 'Recep@123';

ok('the Administrator can log in', $admin->login($adminEmail, $adminPass), $adminEmail);
ok('the Receptionist can log in', $recep->login($recepEmail, $recepPass), $recepEmail);

$guestPage = $guest->get('admin/appointments.php');
ok('an anonymous visitor is sent to the login page', $guestPage['status'] === 302 && strpos($guestPage['location'], 'login.php') !== false, $guestPage['location']);
ok('the staff availability endpoint needs a session', $guest->get('availability.php?date=' . OPEN_MON)['status'] === 302);

ok('the Receptionist may not open Settings', $recep->get('admin/settings.php')['status'] === 403);
ok('the Receptionist may not open Staff Management', $recep->get('admin/staff.php')['status'] === 403);
ok('the Receptionist may open the walk-in desk', $recep->get('receptionist/walkins.php')['status'] === 200);

$hoursBefore = md5((string)json_encode(q('SELECT * FROM business_hours ORDER BY id')));
$recepPost   = $recep->request('POST', 'admin/settings.php', [
    'csrf_token'             => $admin->csrf('admin/settings.php?view=hours'),
    'form_action'            => 'save_business_hours',
    'hours[1][is_open]'      => '1',
    'hours[1][opening_time]' => '01:00',
    'hours[1][closing_time]' => '02:00',
]);
$hoursAfter = md5((string)json_encode(q('SELECT * FROM business_hours ORDER BY id')));
ok('a Receptionist POST cannot change the business hours', $recepPost['status'] === 403 && $hoursBefore === $hoursAfter, 'HTTP ' . $recepPost['status']);

$rosterBefore = md5((string)json_encode(q('SELECT * FROM staff_working_hours ORDER BY id')));
$recepRoster  = $recep->request('POST', 'admin/staff.php', [
    'csrf_token'            => $admin->csrf('admin/staff.php'),
    'form_action'           => 'save_hours',
    'id'                    => $staffA,
    'hours[1][is_working]'  => '1',
    'hours[1][start_time]'  => '01:00',
    'hours[1][end_time]'    => '02:00',
]);
$rosterAfter = md5((string)json_encode(q('SELECT * FROM staff_working_hours ORDER BY id')));
ok('a Receptionist POST cannot change a staff schedule', $recepRoster['status'] === 403 && $rosterBefore === $rosterAfter, 'HTTP ' . $recepRoster['status']);

/* ==================================================================
 * C. The appointment door (Administrator)
 * ================================================================== */
heading('C. Appointments: every combination at the door');

$apptPage = 'admin/appointments.php';

$apptFields = static function (array $over = []) use ($staffA, $serviceShort, $custA): array {
    return array_merge([
        'form_action'      => 'save_appointment',
        'id'               => 0,
        'customer_id'      => $custA,
        'staff_id'         => $staffA,
        'service_ids'      => [$serviceShort],
        'appointment_date' => OPEN_MON,
        'start_time'       => '10:00',
        'status'           => 'pending',
        'notes'            => 'entry-point probe',
        'discount'         => 0,
    ], $over);
};

/**
 * Posts one case and asserts the answer AND the effect on the database.
 *
 * @param string $expect created | saved | refused
 * @return int the highest appointment id (for 'created')
 */
function expect_case(Http $http, string $page, array $fields, string $expect, string $label, string $needle = ''): int
{
    $before = db_state();
    $r      = $http->postForm($page, $fields);
    $after  = db_state();

    $delta = [];

    foreach ($after as $table => $count) {
        if ($count !== $before[$table]) {
            $delta[$table] = $count - $before[$table];
        }
    }

    if ($expect === 'created') {
        $newId = max_id('appointments');
        $made  = ($delta['appointments'] ?? 0) === 1;
        ok($label, $made && !Http::hasDanger($r['body']), $made ? 'appointment ' . $newId : 'not created; ' . json_encode($delta) . ' | ' . $r['flash']);
        return $newId;
    }

    if ($expect === 'saved') {
        $quiet = !Http::hasDanger($r['body']) && ($delta['appointments'] ?? 0) === 0;
        ok($label, $quiet, $r['flash'] !== '' ? $r['flash'] : json_encode($delta));
        return 0;
    }

    $refused = $delta === [] && Http::hasDanger($r['body']);

    if ($needle !== '') {
        $haystack = $r['flash'] !== '' ? $r['flash'] : $r['body'];
        $refused  = $refused && stripos($haystack, $needle) !== false;
    }

    ok($label, $refused, $r['flash'] !== '' ? $r['flash'] : 'no flash; deltas ' . json_encode($delta));

    return 0;
}

/* 1 — a legal booking goes through */
$firstId = expect_case($admin, $apptPage, $apptFields(), 'created', 'a legal booking is saved');

$firstRow = one("SELECT staff_id, start_time, end_time FROM appointments WHERE id = {$firstId}");
ok(
    'the saved appointment kept its staff member and duration',
    (int)($firstRow['staff_id'] ?? 0) === $staffA && ($firstRow['start_time'] ?? '') === '10:00:00' && ($firstRow['end_time'] ?? '') === sprintf('10:%02d:00', $shortMinutes),
    ($firstRow['start_time'] ?? '-') . '-' . ($firstRow['end_time'] ?? '-')
);

/* 2 — conflicts */
expect_case($admin, $apptPage, $apptFields(['customer_id' => $custB]), 'refused', 'the same staff member cannot be double-booked', 'conflicts with an existing appointment');
expect_case($admin, $apptPage, $apptFields(['staff_id' => $staffB]), 'refused', 'the same customer cannot be booked twice at once', 'This customer already has an appointment');
expect_case($admin, $apptPage, $apptFields(['staff_id' => 0, 'customer_id' => $custC]), 'refused', 'an unassigned booking may not overlap an existing one', 'already taken');

/* 3 — day kinds */
expect_case($admin, $apptPage, $apptFields(['appointment_date' => CLOSED_SUN, 'customer_id' => $custB]), 'refused', 'a closed Sunday is refused', 'closed');
expect_case($admin, $apptPage, $apptFields(['appointment_date' => HOLIDAY_WED, 'customer_id' => $custB]), 'refused', 'a public holiday is refused', 'holiday');
expect_case($admin, $apptPage, $apptFields(['appointment_date' => IMPOSSIBLE, 'customer_id' => $custB]), 'refused', 'an impossible calendar date is refused', 'valid date');

/* 4 — the operating period */
expect_case($admin, $apptPage, $apptFields(['start_time' => '07:00', 'customer_id' => $custB]), 'refused', 'a start before opening is refused', 'opens at');
expect_case($admin, $apptPage, $apptFields(['start_time' => '17:45', 'customer_id' => $custB]), 'refused', 'a booking that ends after closing is refused', 'closes at');
expect_case($admin, $apptPage, $apptFields(['start_time' => '17:00', 'service_ids' => [$serviceLong], 'customer_id' => $custB]), 'refused', 'a long service that cannot finish before closing is refused', 'closes at');
expect_case($admin, $apptPage, $apptFields(['start_time' => '25:00', 'customer_id' => $custB]), 'refused', 'an impossible clock time is refused');
expect_case($admin, $apptPage, $apptFields(['service_ids' => [], 'customer_id' => $custB]), 'refused', 'a booking without a service is refused', 'at least one service');

/* 5 — staff kinds */
expect_case($admin, $apptPage, $apptFields(['staff_id' => $staffI, 'customer_id' => $custB]), 'refused', 'an inactive staff member is refused', 'not available');
expect_case($admin, $apptPage, $apptFields(['staff_id' => $ghost, 'customer_id' => $custB]), 'refused', 'a nonexistent staff member is refused', 'not available');

/* 6 — the roster */
$fullWeek = static function (array $overrides = []): array {
    $week = [];

    for ($d = 1; $d <= 6; $d++) {
        $week[$d] = ['is_working' => 1, 'start_time' => '09:00', 'end_time' => '18:00'];
    }

    $week[7] = ['is_working' => 0, 'start_time' => '', 'end_time' => ''];

    foreach ($overrides as $d => $row) {
        $week[$d] = $row;
    }

    return $week;
};

staff_working_hours_save($staffA, $fullWeek([1 => ['is_working' => 0, 'start_time' => '', 'end_time' => '']]));
expect_case($admin, $apptPage, $apptFields(['start_time' => '11:00', 'customer_id' => $custB]), 'refused', 'a staff day off is refused', 'not working');

staff_working_hours_save($staffA, $fullWeek([1 => ['is_working' => 1, 'start_time' => '13:00', 'end_time' => '15:00']]));
expect_case($admin, $apptPage, $apptFields(['start_time' => '11:00', 'customer_id' => $custB]), 'refused', 'a start before the shift is refused', 'works from');
expect_case($admin, $apptPage, $apptFields(['start_time' => '14:00', 'service_ids' => [$serviceLong], 'customer_id' => $custB]), 'refused', 'a service longer than the rest of the shift is refused', 'works until');
expect_case($admin, $apptPage, $apptFields(['start_time' => '13:30', 'customer_id' => $custB]), 'created', 'a booking inside the narrow shift is saved');
staff_working_hours_save($staffA, $fullWeek());

/* 7 — breaks */
business_breaks_save(1, [['start_time' => '16:00', 'end_time' => '17:00']]);
expect_case($admin, $apptPage, $apptFields(['start_time' => '16:15', 'customer_id' => $custB]), 'refused', 'a booking inside a salon break is refused', 'on a break');
expect_case($admin, $apptPage, $apptFields(['start_time' => '15:45', 'customer_id' => $custB]), 'refused', 'a booking spanning a salon break is refused', 'on a break');
expect_case($admin, $apptPage, $apptFields(['start_time' => '17:00', 'customer_id' => $custB]), 'created', 'a booking starting when the break ends is saved');
business_breaks_save(1, []);

staff_breaks_save($staffB, 1, [['start_time' => '09:30', 'end_time' => '10:00']]);
expect_case($admin, $apptPage, $apptFields(['staff_id' => $staffB, 'start_time' => '09:30', 'customer_id' => $custB]), 'refused', 'a booking inside a staff break is refused', 'on a break');
expect_case($admin, $apptPage, $apptFields(['start_time' => '09:30', 'customer_id' => $custB]), 'created', 'the same time is free for a colleague');
staff_breaks_save($staffB, 1, []);

/* 8 — skills */
staff_services_save($staffA, [$serviceShort]);
expect_case($admin, $apptPage, $apptFields(['service_ids' => [$serviceLong], 'start_time' => '11:00', 'customer_id' => $custB]), 'refused', 'a service the staff member does not perform is refused', 'does not perform');
expect_case($admin, $apptPage, $apptFields(['start_time' => '11:00', 'customer_id' => $custB]), 'created', 'a service on the allow-list is saved');
staff_services_save($staffA, []);

/* 9 — editing keeps its own slot but may not steal one */
expect_case($admin, $apptPage, $apptFields(['id' => $firstId, 'notes' => 'entry-point probe, edited']), 'saved', 'an appointment may be re-saved into its own slot');

$takenStart = (string)one("SELECT start_time AS s FROM appointments WHERE id <> {$firstId} AND appointment_date = '" . OPEN_MON . "' AND status NOT IN ('cancelled','no_show') ORDER BY id DESC LIMIT 1")['s'];

if ($takenStart !== '') {
    expect_case(
        $admin,
        $apptPage,
        $apptFields(['id' => $firstId, 'start_time' => substr($takenStart, 0, 5), 'notes' => 'entry-point probe']),
        'refused',
        'an appointment may not be moved onto a taken slot'
    );
}

/* 10 — reinstating a cancelled appointment */
db()->prepare('UPDATE appointments SET status = "cancelled" WHERE id = :id')->execute(['id' => $firstId]);
$rebookedId = expect_case($admin, $apptPage, $apptFields(['customer_id' => $custB]), 'created', 'the released slot can be booked by somebody else');

expect_case(
    $admin,
    $apptPage,
    ['form_action' => 'change_status', 'id' => $firstId, 'status' => 'confirmed'],
    'refused',
    'a cancelled appointment cannot be reinstated onto a rebooked slot',
    'cannot be reinstated'
);
ok('the cancelled appointment stayed cancelled', (string)one("SELECT status AS s FROM appointments WHERE id = {$firstId}")['s'] === 'cancelled');

db()->prepare('DELETE FROM appointment_services WHERE appointment_id = :id')->execute(['id' => $rebookedId]);
db()->prepare('DELETE FROM invoices WHERE appointment_id = :id')->execute(['id' => $rebookedId]);
db()->prepare('DELETE FROM payments WHERE appointment_id = :id')->execute(['id' => $rebookedId]);
db()->prepare('DELETE FROM appointments WHERE id = :id')->execute(['id' => $rebookedId]);

expect_case(
    $admin,
    $apptPage,
    ['form_action' => 'change_status', 'id' => $firstId, 'status' => 'confirmed'],
    'saved',
    'the same appointment is reinstated once the slot is free'
);
ok('the reinstated appointment is confirmed again', (string)one("SELECT status AS s FROM appointments WHERE id = {$firstId}")['s'] === 'confirmed');

/* ==================================================================
 * D. The walk-in door (Receptionist)
 * ================================================================== */
heading('D. Walk-ins: the same rule at the desk');

$walkinPage = 'receptionist/walkins.php';

$walkinFields = static function (array $over = []) use ($staffB, $serviceShort, $custB): array {
    return array_merge([
        'form_action'      => 'save_walkin',
        'customer_id'      => $custB,
        'staff_id'         => $staffB,
        'service_ids'      => [$serviceShort],
        'appointment_date' => OPEN_MON,
        'start_time'       => '12:00',
        'payment_method'   => 'cash',
        'notes'            => 'entry-point walk-in probe',
        'discount'         => 0,
    ], $over);
};

expect_case($recep, $walkinPage, $walkinFields(), 'created', 'a legal walk-in is saved by the Receptionist');
expect_case($recep, $walkinPage, $walkinFields(['staff_id' => 0]), 'refused', 'a walk-in without a staff member is refused', 'choose the staff member');
expect_case($recep, $walkinPage, $walkinFields(['appointment_date' => CLOSED_SUN]), 'refused', 'a walk-in on a closed day is refused', 'closed');
expect_case($recep, $walkinPage, $walkinFields(['appointment_date' => HOLIDAY_WED]), 'refused', 'a walk-in on a holiday is refused', 'holiday');
expect_case($recep, $walkinPage, $walkinFields(['appointment_date' => IMPOSSIBLE]), 'refused', 'a walk-in on an impossible date is refused', 'valid date');
expect_case($recep, $walkinPage, $walkinFields(['start_time' => '10:00', 'staff_id' => $staffA]), 'refused', 'a walk-in onto a booked slot is refused', 'conflicts');
expect_case($recep, $walkinPage, $walkinFields(['start_time' => '12:00', 'staff_id' => $staffA]), 'refused', 'a walk-in may not double-book the customer', 'This customer');
expect_case($recep, $walkinPage, $walkinFields(['start_time' => '07:00']), 'refused', 'a walk-in before opening is refused', 'opens at');
expect_case($recep, $walkinPage, $walkinFields(['start_time' => '17:45']), 'refused', 'a walk-in that ends after closing is refused', 'closes at');
expect_case($recep, $walkinPage, $walkinFields(['staff_id' => $staffI]), 'refused', 'a walk-in with an inactive staff member is refused', 'not available');
expect_case($recep, $walkinPage, $walkinFields(['service_ids' => []]), 'refused', 'a walk-in without a service is refused', 'at least one service');

expect_case(
    $recep,
    $walkinPage,
    $walkinFields([
        'customer_id' => 0,
        'first_name'  => 'Entry',
        'last_name'   => 'Point Probe',
        'phone'       => '0000000011',
        'start_time'  => '13:00',
    ]),
    'created',
    'a walk-in may create its own customer'
);

expect_case(
    $recep,
    $walkinPage,
    $walkinFields([
        'customer_id'      => 0,
        'first_name'       => 'Rejected',
        'last_name'        => 'Probe',
        'phone'            => '0000000012',
        'appointment_date' => CLOSED_SUN,
    ]),
    'refused',
    'a walk-in for a new customer on a closed day is refused',
    'closed'
);
ok(
    'a refused walk-in leaves no customer behind',
    (int)one("SELECT COUNT(*) AS c FROM customers WHERE phone = '0000000012'")['c'] === 0
);

/* ==================================================================
 * E. The public door (anonymous visitor)
 * ================================================================== */
heading('E. Public booking page');

$bookFields = static function (array $over = []): array {
    return array_merge([
        'name'       => 'Public Probe',
        'phone'      => '0000000013',
        'service_id' => $GLOBALS['serviceShort'],
        'date'       => OPEN_MON,
        'time'       => '11:00:00',
        'notes'      => 'entry-point public probe',
    ], $over);
};

$postBook = static function (Http $guest, array $fields): array {
    $fields['csrf_token'] = $guest->csrf('book.php');
    return $guest->request('POST', 'book.php', $fields);
};

$waitlistBefore = row_count('waitlist');

$r = $postBook($guest, $bookFields(['date' => CLOSED_SUN]));
ok('the public form refuses a closed Sunday', stripos($r['body'], 'not open on the date') !== false && row_count('waitlist') === $waitlistBefore, Http::flashText($r['body']));

$r = $postBook($guest, $bookFields(['date' => IMPOSSIBLE]));
ok('the public form refuses an impossible date', row_count('waitlist') === $waitlistBefore, Http::flashText($r['body']));

$r = $postBook($guest, $bookFields(['date' => HOLIDAY_WED]));
ok('the public form refuses a holiday', row_count('waitlist') === $waitlistBefore, Http::flashText($r['body']));

$r = $postBook($guest, $bookFields(['name' => '', 'phone' => '']));
ok('the public form needs a name and a phone number', stripos($r['body'], 'name and phone') !== false && row_count('waitlist') === $waitlistBefore, Http::flashText($r['body']));

$r = $postBook($guest, $bookFields(['time' => '']));
ok('the public form needs a time', stripos($r['body'], 'choose an available time') !== false && row_count('waitlist') === $waitlistBefore, Http::flashText($r['body']));

$r = $postBook($guest, $bookFields());
ok('a legal public request reaches the waitlist', row_count('waitlist') === $waitlistBefore + 1 && stripos($r['body'], 'Thank you') !== false, Http::flashText($r['body']));

/* ==================================================================
 * F. The availability endpoints
 * ================================================================== */
heading('F. Availability endpoints');

$a = Http::json($admin->get('availability.php?date=' . OPEN_MON . '&duration=' . $shortMinutes));
ok('an open day offers slots', ($a['ok'] ?? false) === true && count($a['slots'] ?? []) > 0, count($a['slots'] ?? []) . ' slots');
ok('an open day lists the rostered staff', count($a['staff'] ?? []) >= 2, count($a['staff'] ?? []) . ' staff');

$a = Http::json($admin->get('availability.php?date=' . CLOSED_SUN));
ok('a closed day offers nothing and says why', ($a['slots'] ?? ['x']) === [] && stripos((string)($a['reason'] ?? ''), 'closed') !== false, (string)($a['reason'] ?? '-'));

$a = Http::json($admin->get('availability.php?date=' . HOLIDAY_WED));
ok('a holiday offers nothing and says why', ($a['slots'] ?? ['x']) === [] && stripos((string)($a['reason'] ?? ''), 'holiday') !== false, (string)($a['reason'] ?? '-'));

$a = Http::json($admin->get('availability.php?date=' . OPEN_MON . '&staff_id=' . $staffI));
ok('an inactive staff member has no availability', ($a['slots'] ?? ['x']) === [] && stripos((string)($a['reason'] ?? ''), 'not available') !== false, (string)($a['reason'] ?? '-'));

$a = Http::json($admin->get('availability.php?date=' . OPEN_MON . '&duration=9999'));
ok('an impossible duration explains itself', ($a['slots'] ?? ['x']) === [] && stripos((string)($a['reason'] ?? ''), 'does not fit') !== false, (string)($a['reason'] ?? '-'));

$a = Http::json($admin->get('availability.php?date=' . IMPOSSIBLE));
ok('the staff endpoint offers nothing for an impossible date', ($a['slots'] ?? ['x']) === [], (string)($a['reason'] ?? json_encode($a)));

$p = Http::json($guest->get('public-availability.php?date=' . OPEN_MON));
ok('the public endpoint answers without a session', ($p['ok'] ?? false) === true && count($p['slots'] ?? []) > 0, count($p['slots'] ?? []) . ' slots');

$p = Http::json($guest->get('public-availability.php?date=' . CLOSED_SUN));
ok('the public endpoint reports a closed day', ($p['slots'] ?? ['x']) === [] && !empty($p['reason']), (string)($p['reason'] ?? '-'));

$p = Http::json($guest->get('public-availability.php?date=' . IMPOSSIBLE));
ok('the public endpoint refuses an impossible date', ($p['ok'] ?? true) === false, json_encode($p));

$allSlots = Http::json($admin->get('availability.php?date=' . OPEN_MON . '&duration=' . $shortMinutes));
$oneSlots = Http::json($admin->get('availability.php?date=' . OPEN_MON . '&duration=' . $shortMinutes . '&staff_id=' . $staffA));
ok(
    'filtering by staff never offers more slots',
    count($oneSlots['slots'] ?? []) <= count($allSlots['slots'] ?? []),
    count($oneSlots['slots'] ?? []) . ' vs ' . count($allSlots['slots'] ?? [])
);

/* ==================================================================
 * G. Cleanup — every row this suite created
 * ================================================================== */
heading('G. Cleanup');

foreach (q('SELECT id FROM appointments WHERE id > ' . $maxIds['appointments']) as $row) {
    $id = (int)$row['id'];
    db()->prepare('DELETE FROM appointment_services WHERE appointment_id = :id')->execute(['id' => $id]);
    db()->prepare('DELETE FROM invoices WHERE appointment_id = :id')->execute(['id' => $id]);
    db()->prepare('DELETE FROM payments WHERE appointment_id = :id')->execute(['id' => $id]);
    db()->prepare('DELETE FROM appointments WHERE id = :id')->execute(['id' => $id]);
}

db()->exec('DELETE FROM invoices WHERE id > ' . $maxIds['invoices']);
db()->exec('DELETE FROM payments WHERE id > ' . $maxIds['payments']);
db()->exec('DELETE FROM loyalty_ledger WHERE id > ' . $maxIds['loyalty_ledger']);
db()->exec('DELETE FROM waitlist WHERE id > ' . $maxIds['waitlist']);
db()->exec('DELETE FROM customers WHERE id > ' . $maxIds['customers']);
db()->exec('DELETE FROM notifications WHERE id > ' . $maxIds['notifications']);
db()->exec('DELETE FROM audit_logs WHERE id > ' . $maxIds['audit_logs']);

restore_scheduling_rows($restoreRows);

$drift = [];

foreach (db_state() as $table => $count) {
    if ($count !== $counts[$table]) {
        $drift[$table] = $count - $counts[$table];
    }
}

ok('every guarded table is back to its starting row count', $drift === [], $drift === [] ? 'no drift' : json_encode($drift));

foreach (SCHED_TABLES as $table) {
    $now = md5((string)json_encode(q("SELECT * FROM `{$table}` ORDER BY 1")));
    $was = md5((string)json_encode($restoreRows[$table]));
    ok("{$table} restored", $now === $was);
}

echo PHP_EOL . str_repeat('-', 62) . PHP_EOL;
echo "http requests: {$requests}" . PHP_EOL;
echo "passed: {$passed}   failed: {$failed}" . PHP_EOL;

if ($failed) {
    echo PHP_EOL . 'failed assertions:' . PHP_EOL;
    foreach ($failures as $f) {
        echo "  - {$f}" . PHP_EOL;
    }
    exit(1);
}

echo 'OK' . PHP_EOL;
exit(0);
