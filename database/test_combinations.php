<?php
/**
 * Beauty-php-ai — Combination matrix test suite (CLI).
 *
 * Phase 7 of the scheduling specification: "test all possible
 * combinations". Every combination of DAY KIND x START/DURATION x STAFF
 * KIND x CUSTOMER x EXISTING BOOKING is generated, its verdict computed by
 * an independent implementation of the FINAL BUSINESS RULE
 *
 *      SALON OPEN + STAFF WORKING + SERVICE DURATION FITS + NO CONFLICT
 *
 * and compared with scheduling_validate(). The slot generator is then held
 * to the same rule: a start time is offered if and only if the rule allows
 * it. The oracle never calls the engine — both are fed the same facts, so a
 * mismatch means one of the two is wrong.
 *
 * Safety: the six scheduling tables are snapshotted row by row and restored
 * by a shutdown handler, and the transactional tables are counted
 * before/after, so a fatal error halfway through cannot leave the salon
 * with test hours, test breaks or probe appointments.
 *
 * USAGE
 *   C:\xampp\php\php.exe database\test_combinations.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}

define('APP_INIT', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/scheduling.php';

$passed      = 0;
$failed      = 0;
$failures    = [];
$combinations = 0;

/* Dates the matrix runs on. All are in the future and free of real
   appointments, so "today has already started" never distorts a result. */
const OPEN_MON    = '2026-10-05';   /* Monday, salon open 09:00-18:00      */
const OPEN_SAT    = '2026-10-10';   /* Saturday, salon open 09:00-18:00    */
const CLOSED_SUN  = '2026-10-04';   /* Sunday, salon closed                */
const HOLIDAY_SUN = '2026-10-11';   /* Sunday AND a public holiday         */
const HOLIDAY_WED = '2026-11-11';   /* Wednesday, open hours but a holiday */

const OPEN_MIN  = 540;              /* 09:00 */
const CLOSE_MIN = 1080;             /* 18:00 */

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

function count_of(string $table): int
{
    return (int)(one("SELECT COUNT(*) AS c FROM `{$table}`")['c'] ?? -1);
}

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

echo 'Combination matrix test suite' . PHP_EOL;
echo 'Database: ' . DB_NAME . PHP_EOL;

$before      = [];
foreach (['appointments', 'customers', 'staff', 'services', 'payments', 'invoices', 'users', 'settings', 'waitlist'] as $t) {
    $before[$t] = count_of($t);
}

$restoreRows  = grab_scheduling_rows();
$probeAppointments = [];
$probeCustomers    = [];

$restoreNow = static function () use ($restoreRows, &$probeAppointments, &$probeCustomers): void {
    try {
        foreach ($probeAppointments as $id) {
            db()->prepare('DELETE FROM appointments WHERE id = :id')->execute(['id' => $id]);
        }
        $probeAppointments = [];

        foreach ($probeCustomers as $id) {
            db()->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $id]);
        }
        $probeCustomers = [];

        restore_scheduling_rows($restoreRows);
    } catch (Throwable $e) {
        echo PHP_EOL . '[restore] FAILED: ' . $e->getMessage() . PHP_EOL;
    }
};

register_shutdown_function(static function () use ($restoreNow): void {
    $restoreNow();
    echo PHP_EOL . '[safety net] scheduling tables restored to their pre-run state.' . PHP_EOL;
});

/* ==================================================================
 * The oracle — an independent reading of the business rule
 * ================================================================== */

/**
 * 'HH:MM' / 'HH:MM:SS' to minutes after midnight, or NULL when the string
 * is not a clock time at all.
 */
function rule_minutes($time): ?int
{
    if (!is_string($time) || !preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) {
        return null;
    }

    if ((int)$m[1] > 23 || (int)$m[2] > 59) {
        return null;
    }

    return ((int)$m[1]) * 60 + (int)$m[2];
}

/**
 * THE RULE, written straight from the specification and applied to a
 * scenario descriptor. Returns [allowed, category].
 *
 * @param array $ctx  trading/open/close/salon_breaks/staff/appointments
 * @param array $case start/duration/staff/customer/exclude/services
 */
function rule_verdict(array $ctx, array $case): array
{
    $start = rule_minutes($case['start'] ?? '');

    if ($start === null) {
        return [false, 'start time is not a clock time'];
    }

    $duration = (int)($case['duration'] ?? 0);

    if ($duration <= 0) {
        return [false, 'duration must be positive'];
    }

    $end = $start + $duration;

    if ($end > 24 * 60) {
        return [false, 'runs past midnight'];
    }

    /* 1 — the salon must be trading that day (closed weekday or holiday). */
    if (!$ctx['trading']) {
        return [false, 'salon is not trading that day'];
    }

    /* 2 + 3 — the whole appointment sits inside the operating period. */
    if ($start < $ctx['open'] || $end > $ctx['close']) {
        return [false, 'outside the salon hours'];
    }

    $staffId = (int)($case['staff'] ?? 0);

    if ($staffId > 0) {
        $member = $ctx['staff'][$staffId] ?? null;

        /* 4 — the staff member exists, is active and works then. */
        if ($member === null || !$member['exists']) {
            return [false, 'staff member does not exist'];
        }

        if (!$member['active']) {
            return [false, 'staff member is not active'];
        }

        if ($member['shift'] === null) {
            return [false, 'staff member does not work that day'];
        }

        /* 5 — and the whole appointment fits inside their shift. */
        [$shiftStart, $shiftEnd] = $member['shift'];

        if ($start < $shiftStart || $end > $shiftEnd) {
            return [false, 'outside the staff shift'];
        }

        /* the staff member must be able to perform every service */
        foreach ((array)($case['services'] ?? []) as $serviceId) {
            if ($member['services'] !== null && !in_array((int)$serviceId, $member['services'], true)) {
                return [false, 'staff member cannot perform the service'];
            }
        }

        /* 10 — a staff break blocks the appointment. */
        foreach ($member['breaks'] as [$bs, $be]) {
            if ($start < $be && $end > $bs) {
                return [false, 'overlaps a staff break'];
            }
        }
    }

    /* 10 — a salon break blocks everybody. */
    foreach ($ctx['salon_breaks'] as [$bs, $be]) {
        if ($start < $be && $end > $bs) {
            return [false, 'overlaps a salon break'];
        }
    }

    /* 6 + 8 + 9 — conflicts. */
    $customerId = (int)($case['customer'] ?? 0);
    $excludeId  = (int)($case['exclude'] ?? 0);

    foreach ($ctx['appointments'] as $appt) {
        if (!$appt['blocking'] || $appt['id'] === $excludeId) {
            continue;
        }

        if (!($start < $appt['end'] && $end > $appt['start'])) {
            continue;
        }

        if ($staffId > 0) {
            /* An assigned booking only clashes with the same staff member,
               or with the same customer served by somebody else. */
            if ($appt['staff'] === $staffId) {
                return [false, 'staff member is double-booked'];
            }

            if ($customerId > 0 && $appt['customer'] === $customerId) {
                return [false, 'customer is double-booked'];
            }

            continue;
        }

        /* An unassigned booking has nobody to serve it yet, so the whole
           salon is treated as busy for that period. */
        return [false, 'slot is taken'];
    }

    return [true, ''];
}

/**
 * The start times the rule allows on a 15-minute grid — what
 * scheduling_slots() ought to return.
 *
 * A zero duration is what the booking form sends before a service is
 * chosen; the engine then offers every free grid point, which is exactly
 * what a one-minute appointment would produce.
 */
function rule_slots(array $ctx, int $staffId, int $duration): array
{
    $probe = max(1, $duration);
    $out   = [];

    for ($t = 0; $t < 24 * 60; $t += 15) {
        [$allowed] = rule_verdict($ctx, [
            'start'    => sprintf('%02d:%02d', intdiv($t, 60), $t % 60),
            'duration' => $probe,
            'staff'    => $staffId,
        ]);

        if ($allowed) {
            $out[] = sprintf('%02d:%02d:00', intdiv($t, 60), $t % 60);
        }
    }

    return $out;
}

/* ==================================================================
 * The combination space
 * ================================================================== */

/**
 * Start time x duration pairs covering every boundary of the rule:
 * before opening, exactly at opening, mid-day, break edges, the booked
 * period and its neighbours, exactly at closing, overrunning closing,
 * the last grid step, and the malformed inputs.
 */
function rule_starts(): array
{
    return [
        ['07:00', 30, 'before opening'],
        ['08:45', 15, 'one step before opening'],
        ['09:00', 30, 'exactly at opening'],
        ['09:00', 540, 'the whole trading day'],
        ['09:00', 555, 'longer than the trading day'],
        ['11:30', 60, 'spanning noon'],
        ['12:00', 60, 'starting at noon'],
        ['13:00', 60, 'after noon'],
        ['15:00', 30, 'mid-afternoon'],
        ['15:30', 30, 'after the staff break'],
        ['16:00', 30, 'on the booked period'],
        ['16:15', 30, 'overlapping the booked period'],
        ['16:30', 30, 'straight after the booked period'],
        ['17:00', 60, 'ending exactly at closing'],
        ['17:15', 60, 'overrunning closing'],
        ['17:45', 30, 'the last step of the day'],
        ['10:00', 0, 'a zero-length service'],
        ['10:00', -30, 'a negative duration'],
        ['nonsense', 30, 'a malformed start time'],
        ['23:45', 60, 'running past midnight'],
    ];
}

/**
 * Runs the cross product of contexts x starts x staff x customers x
 * exclusions and returns [checked, mismatches].
 */
function run_matrix(array $contexts, array $staffIds, array $customers, array $excludes, int $showLimit = 8): array
{
    $checked    = 0;
    $mismatches = [];

    foreach ($contexts as $ctx) {
        foreach (rule_starts() as [$start, $duration, $startLabel]) {
            foreach ($staffIds as $staffId) {
                foreach ($customers as $customerId) {
                    foreach ($excludes as $excludeId) {
                        $case = [
                            'start'    => $start,
                            'duration' => $duration,
                            'staff'    => $staffId,
                            'customer' => $customerId,
                            'exclude'  => $excludeId,
                            'services' => [],
                        ];

                        [$allowed, $why] = rule_verdict($ctx, $case);
                        $engine = scheduling_validate([
                            'date'               => $ctx['date'],
                            'start'              => $start,
                            'duration_minutes'   => $duration,
                            'staff_id'           => $staffId,
                            'customer_id'        => $customerId,
                            'exclude_id'         => $excludeId,
                        ]);

                        $engineAllowed = $engine === null;
                        $checked++;

                        if ($engineAllowed === $allowed) {
                            continue;
                        }

                        $mismatches[] = sprintf(
                            '%s %s %dmin staff=%d customer=%d exclude=%d — rule says %s (%s), engine says %s (%s)',
                            $ctx['label'],
                            $start,
                            $duration,
                            $staffId,
                            $customerId,
                            $excludeId,
                            $allowed ? 'ALLOW' : 'REFUSE',
                            $allowed ? '-' : $why,
                            $engineAllowed ? 'ALLOW' : 'REFUSE',
                            $engineAllowed ? '-' : $startLabel . ' / ' . (string)$engine
                        );
                    }
                }
            }
        }
    }

    foreach (array_slice($mismatches, 0, $showLimit) as $line) {
        echo '        ! ' . $line . PHP_EOL;
    }

    if (count($mismatches) > $showLimit) {
        echo '        ! ... and ' . (count($mismatches) - $showLimit) . ' more' . PHP_EOL;
    }

    return [$checked, $mismatches];
}

/* ==================================================================
 * A. Fixtures
 * ================================================================== */
heading('A. Fixtures and calendar facts');

ok('Monday 2026-10-05 really is a Monday', (int)date('N', strtotime(OPEN_MON)) === 1);
ok('Saturday 2026-10-10 really is a Saturday', (int)date('N', strtotime(OPEN_SAT)) === 6);
ok('Sunday 2026-10-04 really is a Sunday', (int)date('N', strtotime(CLOSED_SUN)) === 7);
ok('2026-10-11 is a Sunday holiday', (int)date('N', strtotime(HOLIDAY_SUN)) === 7 && is_holiday(HOLIDAY_SUN));
ok('2026-11-11 is a Wednesday holiday', (int)date('N', strtotime(HOLIDAY_WED)) === 3 && is_holiday(HOLIDAY_WED));
ok('the matrix dates carry no real appointments', (int)one(
    "SELECT COUNT(*) AS c FROM appointments WHERE appointment_date IN ('" . OPEN_MON . "','" . OPEN_SAT . "','" . CLOSED_SUN . "','" . HOLIDAY_SUN . "','" . HOLIDAY_WED . "')"
)['c'] === 0);

$hours = business_hours_all();
ok('the salon trades Mon-Sat 09:00-18:00', $hours[1]['is_open'] && $hours[1]['opening_time'] === '09:00:00' && $hours[1]['closing_time'] === '18:00:00' && $hours[6]['is_open']);
ok('the salon is closed on Sunday', !$hours[7]['is_open']);

$activeStaff = q("SELECT id FROM staff WHERE status = 'active' ORDER BY id LIMIT 2");
$inactiveRow = q("SELECT id FROM staff WHERE status = 'inactive' ORDER BY id LIMIT 1");

if (count($activeStaff) < 2 || !$inactiveRow) {
    echo PHP_EOL . 'This suite needs at least two active staff members and one inactive.' . PHP_EOL;
    exit(1);
}

$staffA = (int)$activeStaff[0]['id'];
$staffB = (int)$activeStaff[1]['id'];
$staffI = (int)$inactiveRow[0]['id'];
$ghost  = 999999;

ok('two active staff members were picked', $staffA !== $staffB, "{$staffA}, {$staffB}");
ok('an inactive staff member was picked', !staff_is_active($staffI), "staff {$staffI}");
ok('the ghost staff id does not exist', !staff_is_active($ghost));

$services = q('SELECT id FROM services ORDER BY id LIMIT 2');
$serviceA = (int)($services[0]['id'] ?? 0);
$serviceB = (int)($services[1]['id'] ?? 0);
ok('two services exist for the skill matrix', $serviceA > 0 && $serviceB > 0 && $serviceA !== $serviceB);

$existingCustomers = q('SELECT id FROM customers ORDER BY id LIMIT 2');
$customerC1 = (int)($existingCustomers[0]['id'] ?? 0);
$customerC2 = (int)($existingCustomers[1]['id'] ?? 0);
ok('two real customers exist for the conflict matrix', $customerC1 > 0 && $customerC2 > 0);

/* salon_id is explicit on every fixture row: since migration 002 the column
   is NOT NULL with a foreign key to `salons`, so an insert that omits it is
   rejected or silently bound to a salon that does not exist. */
db()->prepare(
    'INSERT INTO customers (salon_id, first_name, last_name, phone, email, created_at, updated_at)
     VALUES (:sid, "Combination", "Probe", "0000000009", "combination-probe@example.test", NOW(), NOW())'
)->execute(['sid' => default_salon_id()]);
$probeCustomer = (int)db()->lastInsertId();
$probeCustomers[] = $probeCustomer;

/* The week every active staff member starts from: the salon hours. */
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

/* ==================================================================
 * B. Plain week — no breaks, no bookings
 * ================================================================== */
heading('B. Plain week: days x times x staff');

business_breaks_save(1, []);
business_breaks_save(3, []);
business_breaks_save(6, []);
business_breaks_save(7, []);
staff_working_hours_save($staffA, $fullWeek());
staff_working_hours_save($staffB, $fullWeek());
staff_services_save($staffA, []);
staff_services_save($staffB, []);

/** Oracle view of one staff member. */
$memberCtx = static function (bool $exists, bool $active, ?array $shift, array $breaks = [], ?array $services = null): array {
    return ['exists' => $exists, 'active' => $active, 'shift' => $shift, 'breaks' => $breaks, 'services' => $services];
};

$plainStaff = [
    $staffA => $memberCtx(true, true, [OPEN_MIN, CLOSE_MIN]),
    $staffB => $memberCtx(true, true, [OPEN_MIN, CLOSE_MIN]),
    $staffI => $memberCtx(true, false, null),
    $ghost  => $memberCtx(false, false, null),
];

$plainContexts = [
    ['label' => 'open Monday', 'date' => OPEN_MON, 'trading' => true, 'open' => OPEN_MIN, 'close' => CLOSE_MIN, 'salon_breaks' => [], 'staff' => $plainStaff, 'appointments' => []],
    ['label' => 'open Saturday', 'date' => OPEN_SAT, 'trading' => true, 'open' => OPEN_MIN, 'close' => CLOSE_MIN, 'salon_breaks' => [], 'staff' => $plainStaff, 'appointments' => []],
    ['label' => 'closed Sunday', 'date' => CLOSED_SUN, 'trading' => false, 'open' => 0, 'close' => 0, 'salon_breaks' => [], 'staff' => $plainStaff, 'appointments' => []],
    ['label' => 'Sunday holiday', 'date' => HOLIDAY_SUN, 'trading' => false, 'open' => 0, 'close' => 0, 'salon_breaks' => [], 'staff' => $plainStaff, 'appointments' => []],
    ['label' => 'Wednesday holiday', 'date' => HOLIDAY_WED, 'trading' => false, 'open' => OPEN_MIN, 'close' => CLOSE_MIN, 'salon_breaks' => [], 'staff' => $plainStaff, 'appointments' => []],
];

[$checked, $bad] = run_matrix($plainContexts, [0, $staffA, $staffB, $staffI, $ghost], [0], [0]);
$combinations += $checked;
ok("plain week: all {$checked} combinations follow the rule", $bad === [], count($bad) . ' mismatch(es)');

/* ==================================================================
 * C. Breaks — salon level and staff level
 * ================================================================== */
heading('C. Salon and staff breaks');

business_breaks_save(1, [['start_time' => '12:00', 'end_time' => '13:00']]);
business_breaks_save(6, [['start_time' => '12:00', 'end_time' => '13:00']]);
staff_breaks_save($staffA, 1, [['start_time' => '15:00', 'end_time' => '15:30']]);
staff_breaks_save($staffA, 6, []);
staff_breaks_save($staffB, 1, []);
staff_breaks_save($staffB, 6, []);

/* The staff break exists on Monday only, so each date needs its own view
   of the roster — the descriptor must mirror the database exactly. */
$mondayStaff = [
    $staffA => $memberCtx(true, true, [OPEN_MIN, CLOSE_MIN], [[900, 930]]),
    $staffB => $memberCtx(true, true, [OPEN_MIN, CLOSE_MIN]),
    $staffI => $memberCtx(true, false, null),
    $ghost  => $memberCtx(false, false, null),
];

$saturdayStaff = [
    $staffA => $memberCtx(true, true, [OPEN_MIN, CLOSE_MIN]),
    $staffB => $memberCtx(true, true, [OPEN_MIN, CLOSE_MIN]),
    $staffI => $memberCtx(true, false, null),
    $ghost  => $memberCtx(false, false, null),
];

$breakContexts = [
    ['label' => 'Monday with breaks', 'date' => OPEN_MON, 'trading' => true, 'open' => OPEN_MIN, 'close' => CLOSE_MIN, 'salon_breaks' => [[720, 780]], 'staff' => $mondayStaff, 'appointments' => []],
    ['label' => 'Saturday with a salon break', 'date' => OPEN_SAT, 'trading' => true, 'open' => OPEN_MIN, 'close' => CLOSE_MIN, 'salon_breaks' => [[720, 780]], 'staff' => $saturdayStaff, 'appointments' => []],
];

[$checked, $bad] = run_matrix($breakContexts, [0, $staffA, $staffB, $staffI, $ghost], [0], [0]);
$combinations += $checked;
ok("breaks: all {$checked} combinations follow the rule", $bad === [], count($bad) . ' mismatch(es)');

/* the break really is in the database, not only in the descriptor */
ok('the salon break is stored for Monday', count(business_breaks_for(1)) === 1);
ok('the staff break is stored for Monday', count(staff_breaks_for($staffA, 1)) === 1);

business_breaks_save(1, []);
business_breaks_save(6, []);
staff_breaks_save($staffA, 1, []);

/* ==================================================================
 * D. Conflicts — staff, customer, unassigned, cancelled, self-edit
 * ================================================================== */
heading('D. Conflicts');

$insert = db()->prepare(
    'INSERT INTO appointments (salon_id, customer_id, staff_id, appointment_date, start_time, end_time, status, notes, created_at, updated_at)
     VALUES (:sid, :cust, :staff, :date, :start, :end, :status, "combination probe", NOW(), NOW())'
);
$insertSalon = default_salon_id();

/* booked by staff A for the probe customer */
$insert->execute(['sid' => $insertSalon, 'cust' => $probeCustomer, 'staff' => $staffA, 'date' => OPEN_SAT, 'start' => '16:00', 'end' => '16:30', 'status' => 'confirmed']);
$probeStaff = (int)db()->lastInsertId();
$probeAppointments[] = $probeStaff;

/* unassigned booking held by a real customer */
$insert->execute(['sid' => $insertSalon, 'cust' => $customerC1, 'staff' => null, 'date' => OPEN_SAT, 'start' => '11:00', 'end' => '12:00', 'status' => 'pending']);
$probeUnassigned = (int)db()->lastInsertId();
$probeAppointments[] = $probeUnassigned;

/* cancelled: must not block anything */
$insert->execute(['sid' => $insertSalon, 'cust' => $probeCustomer, 'staff' => $staffA, 'date' => OPEN_SAT, 'start' => '09:30', 'end' => '10:00', 'status' => 'cancelled']);
$probeCancelled = (int)db()->lastInsertId();
$probeAppointments[] = $probeCancelled;

/* no-show: must not block anything either */
$insert->execute(['sid' => $insertSalon, 'cust' => $customerC2, 'staff' => $staffB, 'date' => OPEN_SAT, 'start' => '13:00', 'end' => '14:00', 'status' => 'no_show']);
$probeNoShow = (int)db()->lastInsertId();
$probeAppointments[] = $probeNoShow;

$conflictContexts = [[
    'label'        => 'booked Saturday',
    'date'         => OPEN_SAT,
    'trading'      => true,
    'open'         => OPEN_MIN,
    'close'        => CLOSE_MIN,
    'salon_breaks' => [],
    'staff'        => $plainStaff,
    'appointments' => [
        ['id' => $probeStaff, 'staff' => $staffA, 'customer' => $probeCustomer, 'start' => 960, 'end' => 990, 'blocking' => true],
        ['id' => $probeUnassigned, 'staff' => null, 'customer' => $customerC1, 'start' => 660, 'end' => 720, 'blocking' => true],
        ['id' => $probeCancelled, 'staff' => $staffA, 'customer' => $probeCustomer, 'start' => 570, 'end' => 600, 'blocking' => false],
        ['id' => $probeNoShow, 'staff' => $staffB, 'customer' => $customerC2, 'start' => 780, 'end' => 840, 'blocking' => false],
    ],
]];

[$checked, $bad] = run_matrix(
    $conflictContexts,
    [0, $staffA, $staffB, $staffI, $ghost],
    [0, $probeCustomer, $customerC1, $customerC2],
    [0, $probeStaff, $probeCancelled]
);
$combinations += $checked;
ok("conflicts: all {$checked} combinations follow the rule", $bad === [], count($bad) . ' mismatch(es)');

/* ==================================================================
 * E. The slot generator must agree with the rule
 * ================================================================== */
heading('E. Slot generator');

$slotChecks = 0;
$slotBad    = [];

foreach ([['plain Monday', $plainContexts[0]], ['booked Saturday', $conflictContexts[0]]] as [$ctxLabel, $ctx]) {
    foreach ([0, $staffA, $staffB, $staffI] as $staffId) {
        foreach ([0, 15, 30, 45, 60, 120, 540] as $duration) {
            $slotChecks++;

            $expected = rule_slots($ctx, $staffId, $duration);
            $actual   = scheduling_slots($ctx['date'], $staffId > 0 ? $staffId : null, $duration);

            if ($expected === $actual) {
                continue;
            }

            $missing = array_values(array_diff($expected, $actual));
            $extra   = array_values(array_diff($actual, $expected));

            $slotBad[] = sprintf(
                '%s staff=%d %dmin — %d expected / %d returned; missing [%s]; unexpected [%s]',
                $ctxLabel,
                $staffId,
                $duration,
                count($expected),
                count($actual),
                implode(', ', array_slice($missing, 0, 5)),
                implode(', ', array_slice($extra, 0, 5))
            );
        }
    }
}

foreach ($slotBad as $line) {
    echo '        ! ' . $line . PHP_EOL;
}

$combinations += $slotChecks;
ok("slot lists: all {$slotChecks} date/staff/duration combinations match the rule", $slotBad === [], count($slotBad) . ' mismatch(es)');
ok('a plain Monday still offers every quarter hour', count(scheduling_slots(OPEN_MON, null, 0)) === 36, count(scheduling_slots(OPEN_MON, null, 0)) . ' slots');
ok('the booked Saturday loses exactly its six booked starts', count(scheduling_slots(OPEN_SAT, null, 0)) === 30, count(scheduling_slots(OPEN_SAT, null, 0)) . ' slots');

/* ==================================================================
 * F. Shifts narrower than the salon, days off, and skills
 * ================================================================== */
heading('F. Narrow shifts, days off and skills');

$narrow = staff_working_hours_save($staffA, $fullWeek([1 => ['is_working' => 1, 'start_time' => '13:00', 'end_time' => '15:00']]));
ok('a 13:00-15:00 Monday shift is accepted', $narrow['ok'] === true, implode('; ', $narrow['errors']));

$dayOff = staff_working_hours_save($staffB, $fullWeek([1 => ['is_working' => 0, 'start_time' => '', 'end_time' => '']]));
ok('a Monday day off is accepted', $dayOff['ok'] === true, implode('; ', $dayOff['errors']));

/* The inactive staff member keeps a full roster, which is exactly the
   combination that must still be refused. */
$inactiveRoster = staff_working_hours_save($staffI, $fullWeek());
ok('an inactive staff member can still hold roster rows', $inactiveRoster['ok'] === true, implode('; ', $inactiveRoster['errors']));

/* One Monday carrying every staff-shaped rule at once: A only 13:00-15:00,
   B off, the inactive member still on the roster, the ghost nonexistent.
   The probe appointments stay on the Saturday, so this date is clean. */
$shiftContexts = [[
    'label'        => 'Monday, narrow shift and a day off',
    'date'         => OPEN_MON,
    'trading'      => true,
    'open'         => OPEN_MIN,
    'close'        => CLOSE_MIN,
    'salon_breaks' => [],
    'staff'        => [
        $staffA => $memberCtx(true, true, [780, 900]),
        $staffB => $memberCtx(true, true, null),
        $staffI => $memberCtx(true, false, [OPEN_MIN, CLOSE_MIN]),
        $ghost  => $memberCtx(false, false, null),
    ],
    'appointments' => [],
]];

[$checked, $bad] = run_matrix($shiftContexts, [0, $staffA, $staffB, $staffI, $ghost], [0], [0]);
$combinations += $checked;
ok("shifts and days off: all {$checked} combinations follow the rule", $bad === [], count($bad) . ' mismatch(es)');

ok('the narrow shift really is in the database', staff_working_hours_for($staffA, 1)['start_time'] === '13:00:00');
ok('the day off really is in the database', staff_working_hours_for($staffB, 1)['is_working'] === false);

/* The skill matrix assumes everybody works the full day again. */
staff_working_hours_save($staffA, $fullWeek());
staff_working_hours_save($staffB, $fullWeek());

/* ---- skills ---- */
staff_services_save($staffA, [$serviceA]);
staff_services_save($staffB, []);

$skillStaff = [
    $staffA => $memberCtx(true, true, [OPEN_MIN, CLOSE_MIN], [], [$serviceA]),
    $staffB => $memberCtx(true, true, [OPEN_MIN, CLOSE_MIN], [], null),
];

$skillCtx = ['label' => 'skills', 'date' => OPEN_MON, 'trading' => true, 'open' => OPEN_MIN, 'close' => CLOSE_MIN, 'salon_breaks' => [], 'staff' => $skillStaff, 'appointments' => []];

$skillChecked = 0;
$skillBad     = [];

foreach ([$staffA, $staffB] as $skillStaffId) {
    foreach ([$serviceA, $serviceB] as $serviceId) {
        foreach ([['10:00', 30], ['16:00', 60]] as [$start, $duration]) {
            $case = ['start' => $start, 'duration' => $duration, 'staff' => $skillStaffId, 'services' => [$serviceId]];
            [$allowed, $why] = rule_verdict($skillCtx, $case);
            $engine = scheduling_validate([
                'date'             => OPEN_MON,
                'start'            => $start,
                'duration_minutes' => $duration,
                'staff_id'         => $skillStaffId,
                'service_ids'      => [$serviceId],
            ]);
            $skillChecked++;
            $combinations++;

            if (($engine === null) !== $allowed) {
                $skillBad[] = sprintf('staff=%d service=%d %s %dmin — rule %s (%s), engine %s', $skillStaffId, $serviceId, $start, $duration, $allowed ? 'ALLOW' : 'REFUSE', $why, $engine === null ? 'ALLOW' : (string)$engine);
            }
        }
    }
}

foreach ($skillBad as $line) {
    echo '        ! ' . $line . PHP_EOL;
}
ok("skills: all {$skillChecked} combinations follow the rule", $skillBad === [], count($skillBad) . ' mismatch(es)');

ok('a listed service is allowed for the restricted staff member', scheduling_validate(['date' => OPEN_MON, 'start' => '10:00', 'duration_minutes' => 30, 'staff_id' => $staffA, 'service_ids' => [$serviceA]]) === null);
ok('an unlisted service is refused for the restricted staff member', scheduling_validate(['date' => OPEN_MON, 'start' => '10:00', 'duration_minutes' => 30, 'staff_id' => $staffA, 'service_ids' => [$serviceB]]) !== null);
ok('the unrestricted colleague may perform either', scheduling_validate(['date' => OPEN_MON, 'start' => '10:00', 'duration_minutes' => 30, 'staff_id' => $staffB, 'service_ids' => [$serviceB]]) === null);

staff_services_save($staffA, []);

/* ==================================================================
 * G. Malformed input can never reach the calendar
 * ================================================================== */
heading('G. Malformed input');

$malformed = [
    'no date at all'          => ['date' => '', 'start' => '10:00', 'duration_minutes' => 30, 'staff_id' => $staffA],
    'a date in the wrong shape' => ['date' => '05-10-2026', 'start' => '10:00', 'duration_minutes' => 30, 'staff_id' => $staffA],
    'a date that does not exist' => ['date' => '2026-13-45', 'start' => '10:00', 'duration_minutes' => 30, 'staff_id' => $staffA],
    'no start time'           => ['date' => OPEN_MON, 'start' => '', 'duration_minutes' => 30, 'staff_id' => $staffA],
    'an impossible clock time' => ['date' => OPEN_MON, 'start' => '25:00', 'duration_minutes' => 30, 'staff_id' => $staffA],
    'a missing duration'      => ['date' => OPEN_MON, 'start' => '10:00', 'staff_id' => $staffA],
    'a negative duration'     => ['date' => OPEN_MON, 'start' => '10:00', 'duration_minutes' => -60, 'staff_id' => $staffA],
];

foreach ($malformed as $label => $args) {
    $combinations++;
    $reason = scheduling_validate($args);
    ok("{$label} is refused", $reason !== null, (string)$reason);
}

/* ==================================================================
 * H. Nothing historical was touched
 * ================================================================== */
heading('H. No historical data was touched');

$restoreNow();

foreach ($before as $table => $count) {
    ok("{$table} unchanged", count_of($table) === $count, "{$count} row(s)");
}

foreach (SCHED_TABLES as $table) {
    $now = md5((string)json_encode(q("SELECT * FROM `{$table}` ORDER BY 1")));
    $was = md5((string)json_encode($restoreRows[$table]));
    ok("{$table} restored", $now === $was);
}

echo PHP_EOL . str_repeat('-', 62) . PHP_EOL;
echo "combinations checked: {$combinations}" . PHP_EOL;
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
