<?php
/**
 * Beauty-php-ai — Scheduling foundation test suite (CLI).
 *
 * Exercises the weekly-hours engine in includes/scheduling.php against a
 * real database: the migrated live install, and (via BEAUTY_DB_NAME) an
 * empty fresh-install copy.
 *
 * Safety: on the live database the six scheduling tables and the
 * `users` mirror keys are snapshotted first and restored by a shutdown
 * handler, so a fatal error halfway through the run can never leave the
 * salon with narrowed hours. The transactional tables are counted
 * before/after so "no existing data was touched" is verified, not assumed.
 *
 * USAGE
 *   C:\xampp\php\php.exe database\test_scheduling.php
 *   set BEAUTY_DB_NAME=beauty_php_ai_freshtest && C:\xampp\php\php.exe database\test_scheduling.php
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

$dbName = DB_NAME;

/* "Fresh" must mean this install has never been set up - not merely that
   BEAUTY_DB_NAME is set. Overriding the database name is how the suite is
   pointed at a scratch copy of the live install, and that copy is fully
   configured, so the env var alone wrongly sent it down the first-run wizard
   branch (which rewrites the salon profile) instead of the configured branch. */
$isFresh = !salon_profile_is_configured();

$passed  = 0;
$failed  = 0;
$failures = [];

/**
 * First Monday on or after $from that has no appointments booked on it,
 * looking at most $horizonDays days ahead.
 *
 * Deliberately NOT filtered by salon: this runs at file scope, before the
 * setup wizard has created the first salon on a fresh install, so
 * default_salon_id() is still 0 and a tenant filter would match nothing and
 * hand back a Monday that is in fact booked. A date with no bookings at all is
 * necessarily free for whichever tenant the suite ends up acting as.
 *
 * Deleting the interfering rows is not an option here because this suite also
 * runs against live data.
 */
function first_free_monday(string $from, int $horizonDays = 90): string
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM appointments WHERE appointment_date = :d');
    } catch (Throwable $e) {
        return $from;
    }

    $day = new DateTimeImmutable($from);

    for ($i = 0; $i <= $horizonDays; $i++) {
        $candidate = $day->modify('+' . $i . ' day');

        if ((int)$candidate->format('N') !== 1) {
            continue;
        }

        $iso = $candidate->format('Y-m-d');

        $stmt->execute(['d' => $iso]);

        if ((int)$stmt->fetchColumn() === 0) {
            return $iso;
        }
    }

    /* Fall back to the starting date rather than inventing one, so a failure
       surfaces as a readable assertion mismatch. */
    return $from;
}

/* Dates used by the suite. 2026-09-28 is the Monday the live salon is
   already booked on; 2026-11-11 is a Wednesday flagged as a holiday. */
const MON_BUSY  = '2026-09-28';
const TUE_FREE  = '2026-10-06';
const SUN_CLOSED = '2026-09-27';
const WED_HOLIDAY = '2026-11-11';

/**
 * The Monday whose slot arithmetic must come out exact, so it has to be a
 * Monday with nothing booked on it.
 *
 * This used to be the hard-coded 2026-10-05 on the assumption that no
 * appointments existed then. That holds on the live install but NOT on a
 * fresh one: database/beauty_php_ai.sql ships demo bookings, two of which
 * land on 2026-10-05 (09:30-10:15 and 11:00-11:45). The engine correctly
 * removed those slots, so the "24 slots / first slot 10:00" assertions
 * failed against seeded data rather than exposing a real defect.
 *
 * The date is searched for instead of assumed. Deleting the interfering rows
 * is not an option here because this suite also runs against live data.
 *
 * @var string
 */
$MON_FREE = first_free_monday(date('Y-m-d'), 90);

/* An always-future Monday for the conflict checks. The suite used to lean on
   the demo bookings for those, but those rows age into the past and
   business_hours_conflicts() deliberately ignores past appointments — so the
   checks silently saw an empty salon. This date cannot expire. */
define('MON_CONFLICT', date('Y-m-d', strtotime('next monday +7 days')));

$probeAppointments = [];

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

/** Everything that must survive the run untouched. */
function snapshot(): array
{
    $out = [];

    foreach (['appointments', 'customers', 'staff', 'services', 'payments', 'invoices', 'users', 'settings'] as $t) {
        $out[$t] = count_of($t);
    }

    foreach (SCHED_TABLES as $t) {
        $out[$t] = md5((string)json_encode(q("SELECT * FROM `{$t}` ORDER BY 1")));
    }

    return $out;
}

/** Row-level copy of the scheduling tables, used by the restore handler. */
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

echo "Scheduling foundation test suite" . PHP_EOL;
echo "Database: {$dbName}" . ($isFresh ? '  (override)' : '  (live)') . PHP_EOL;

$before = snapshot();
$restoreRows = $isFresh ? null : grab_scheduling_rows();

/* Safety net: even a fatal error restores the live schedule. */
$restoreNow = static function () use ($restoreRows, &$probeAppointments, &$probeCustomers): void {
    if ($restoreRows === null) {
        return;
    }

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

if (!$isFresh) {
    register_shutdown_function(static function () use ($restoreNow): void {
        $restoreNow();
        echo PHP_EOL . '[safety net] scheduling tables restored to their pre-run state.' . PHP_EOL;
    });
}

/* ==================================================================
 * A. Migration outcome
 * ================================================================== */
heading('A. Migration outcome');

$cols     = q('SHOW COLUMNS FROM `salon_settings`');
$colNames = array_column($cols, 'Field');
$profile  = salon_profile(true);
$hours    = (int)(one('SELECT COUNT(*) AS c FROM `business_hours`')['c'] ?? 0);

ok('salon_settings.setup_completed_at exists', in_array('setup_completed_at', $colNames, true));

if ($isFresh) {
    ok('fresh install leaves salon_settings empty (setup must run)', $profile === null);
    ok('fresh install leaves business_hours empty (the wizard sets the week)', $hours === 0);
    ok('fresh install reports setup as NOT configured', salon_profile_is_configured() === false);
} else {
    ok('live install has a salon profile', is_array($profile));
    ok('live install is stamped as configured', salon_profile_is_configured() === true);
    ok('salon_name() reads salon_settings', is_array($profile) && salon_name() === $profile['salon_name'], salon_name());
    ok('owner_full_name() joins both names', owner_full_name() !== '', owner_full_name());
    ok('business_hours holds exactly 7 weekday rows', $hours === 7, "rows={$hours}");
}

/* ==================================================================
 * B. Weekday mapping + pure helpers
 * ================================================================== */
heading('B. Weekday mapping and time helpers');

ok('Monday maps to 1', scheduling_day_index($MON_FREE) === 1, $MON_FREE);
ok('Sunday maps to 7', scheduling_day_index(SUN_CLOSED) === 7, SUN_CLOSED);
ok('scheduling_days() is Mon..Sun', array_keys(scheduling_days()) === [1, 2, 3, 4, 5, 6, 7]);
ok('scheduling_day_short(1) is Mon', scheduling_day_short(1) === 'Mon', scheduling_day_short(1));
ok('"09:00" normalises to 09:00:00', scheduling_normalize_time('09:00') === '09:00:00');
ok('"18:00:00" is left alone', scheduling_normalize_time('18:00:00') === '18:00:00');
ok('a malformed time is rejected', scheduling_normalize_time('99:99') === null);
ok('minutes("09:30") === 570', scheduling_minutes('09:30') === 570);
ok('clock(570) === "09:30:00"', scheduling_clock(570) === '09:30:00');
ok('human_time("09:00:00") === "9:00 AM"', scheduling_human_time('09:00:00') === '9:00 AM', scheduling_human_time('09:00:00'));

$sub = scheduling_subtract([[540, 1080]], [720, 780]);
ok('subtracting a break splits the window', $sub === [[540, 720], [780, 1080]], json_encode($sub));
ok('subtracting outside the window changes nothing', scheduling_subtract([[540, 600]], [700, 800]) === [[540, 600]]);
ok('subtracting the whole window empties it', scheduling_subtract([[540, 600]], [500, 700]) === []);

/* ==================================================================
 * C. Fresh install: the wizard path end to end
 * ================================================================== */
if ($isFresh) {
    heading('C. First-time setup path (empty database)');

    $res = salon_profile_save([
        'salon_name'        => 'Test Salon',
        'owner_first_name' => 'Test',
        'owner_last_name'  => 'Owner',
    ]);
    ok('salon_profile_save() accepts a valid profile', $res['ok'] === true, implode('; ', $res['errors']));
    ok('setup still reads as NOT configured before the marker', salon_profile_is_configured() === false);

    salon_setup_mark_complete();
    ok('the marker flips setup to configured', salon_profile_is_configured() === true);
    ok('salon_name() reports the wizard value', salon_name() === 'Test Salon', salon_name());
    ok('owner_full_name() joins both names', owner_full_name() === 'Test Owner', owner_full_name());

    $bad = salon_profile_save(['salon_name' => '', 'owner_first_name' => '', 'owner_last_name' => '']);
    ok('salon_profile_save() rejects a blank profile', $bad['ok'] === false, count($bad['errors']) . ' errors');
    ok('a rejected save leaves the profile alone', salon_name() === 'Test Salon');

    $week = [];
    for ($d = 1; $d <= 5; $d++) {
        $week[$d] = ['is_open' => 1, 'opening_time' => '10:00:00', 'closing_time' => '16:00:00'];
    }
    $week[6] = ['is_open' => 0, 'opening_time' => null, 'closing_time' => null];
    $week[7] = ['is_open' => 0, 'opening_time' => null, 'closing_time' => null];

    $badDay = business_hours_validate_week($week);
    ok('a Mon-Fri 10:00-16:00 week validates', $badDay === [], implode('; ', $badDay));

    $saved = business_hours_save($week);
    ok('business_hours_save() stores the week', $saved['ok'] === true, implode('; ', $saved['errors']));

    $badWeek = $week;
    $badWeek[1] = ['is_open' => 1, 'opening_time' => '16:00:00', 'closing_time' => '10:00:00'];
    $errs = business_hours_validate_week($badWeek);
    ok('closing before opening is rejected', $errs !== [], implode('; ', $errs));

    $errs = business_hours_validate_day(1, true, '09:00', 'not-a-time');
    ok('an unparsable time is rejected', $errs !== [], implode('; ', $errs));

    $info = scheduling_day_info($MON_FREE);
    ok('Monday is open 10:00-16:00 after the wizard', $info['is_open'] && $info['open_min'] === 600 && $info['close_min'] === 960);

    $slots = scheduling_slots($MON_FREE, null, 0);
    ok('a 6-hour Monday yields 24 slots', count($slots) === 24, count($slots) . ' slots');
    ok('the first slot is 10:00:00', ($slots[0] ?? '') === '10:00:00', $slots[0] ?? '-');
    ok('the last slot is 15:45:00', (end($slots) ?: '') === '15:45:00', end($slots) ?: '-');

    $fits = scheduling_slots($MON_FREE, null, 60);
    ok('a 60-minute service drops the last three slots', count($fits) === 21, count($fits) . ' slots');
    ok('a 60-minute service never starts at 15:45', !in_array('15:45:00', $fits, true));

    ok('Sunday is closed', scheduling_day_info(SUN_CLOSED)['is_open'] === false);
    ok('a closed day yields no slots', scheduling_slots(SUN_CLOSED, null, 0) === []);
    ok('date_status() explains a closed day', scheduling_date_status(SUN_CLOSED)['bookable'] === false);

    $brk = business_breaks_save(1, [['start_time' => '12:00', 'end_time' => '13:00']]);
    ok('business_breaks_save() stores a break', $brk['ok'] === true, implode('; ', $brk['errors']));
    ok('the break removes its slots', !in_array('12:00:00', scheduling_slots($MON_FREE, null, 0), true));
    $span = scheduling_validate(['date' => $MON_FREE, 'start' => '11:30', 'duration_minutes' => 120]);
    ok('a booking spanning the break is refused', $span !== null, (string)$span);
    $overlap = business_breaks_save(1, [['start_time' => '13:00', 'end_time' => '12:00']]);
    ok('a break ending before it starts is rejected', $overlap['ok'] === false, implode('; ', $overlap['errors']));
    $outside = business_breaks_save(1, [['start_time' => '18:00', 'end_time' => '19:00']]);
    ok('a break outside opening hours is rejected', $outside['ok'] === false, implode('; ', $outside['errors']));
    business_breaks_save(1, []);
}

/* ==================================================================
 * D. Engine against live data
 * ================================================================== */
if (!$isFresh) {
    heading('D. Weekly engine against live data');

    $all = business_hours_all(true);
    ok('business_hours_all() returns all 7 days', count($all) === 7);
    ok('day 1 is Monday and day 7 is Sunday', $all[1]['day_name'] === 'Monday' && $all[7]['day_name'] === 'Sunday');

    $monday = business_hours_for(1);
    ok(
        'Monday matches the migrated business_open/close',
        (bool)$monday['is_open'] && $monday['opening_time'] === '09:00:00' && $monday['closing_time'] === '18:00:00',
        ($monday['opening_time'] ?? '-') . '-' . ($monday['closing_time'] ?? '-')
    );
    ok('Sunday is closed', business_hours_for(7)['is_open'] === false);
    ok('business_hours_first_open_day() is Monday', business_hours_first_open_day() === 1);
    ok('business_open_time() reports the first open day', business_open_time() === '09:00', business_open_time());
    ok('business_close_time() reports the first open day', business_close_time() === '18:00', business_close_time());
    ok('business_hours_summary() is non-empty', business_hours_summary() !== '', business_hours_summary());

    /* ---- capacity arithmetic on a free Monday ---- */
    $all36 = scheduling_slots($MON_FREE, null, 0);
    ok('a free Monday yields 36 fifteen-minute slots', count($all36) === 36, count($all36) . ' slots');
    ok('the first slot is 09:00:00', ($all36[0] ?? '') === '09:00:00', $all36[0] ?? '-');
    ok('the last slot is 17:45:00', (end($all36) ?: '') === '17:45:00', end($all36) ?: '-');

    $fits60 = scheduling_slots($MON_FREE, null, 60);
    ok('a 60-minute service drops the last three slots', count($fits60) === 33, count($fits60) . ' slots');
    ok('a 60-minute service never starts at 17:45', !in_array('17:45:00', $fits60, true));

    /* ---- existing bookings really do block ---- */
    $busy = scheduling_slots(MON_BUSY, null, 0);
    ok('the already-booked Monday yields fewer slots', count($busy) < 36, count($busy) . ' slots');
    ok('a booked start time is not offered', !in_array('10:00:00', $busy, true));

    /* ---- the status helpers ---- */
    $closedStatus = scheduling_date_status(SUN_CLOSED);
    ok('a closed day is not bookable and explains why', $closedStatus['bookable'] === false && $closedStatus['reason'] !== null, (string)$closedStatus['reason']);
    ok('an open Monday is bookable', scheduling_date_status($MON_FREE)['bookable'] === true);
    $holidayStatus = scheduling_date_status(WED_HOLIDAY);
    ok('a holiday is not bookable', $holidayStatus['bookable'] === false && $holidayStatus['holiday'] === true, (string)$holidayStatus['reason']);
    $holidayBook = scheduling_validate(['date' => WED_HOLIDAY, 'start' => '10:00', 'duration_minutes' => 30]);
    ok('booking on a holiday is refused', $holidayBook !== null, (string)$holidayBook);

    /* ---- staff roster ---- */
    $staff = q("SELECT id FROM staff WHERE status = 'active' AND salon_id = " . (int)default_salon_id() . " ORDER BY id");
    ok('active staff exist', count($staff) > 0, count($staff) . ' active');
    $staffId = (int)$staff[0]['id'];

    $rostered = staff_working_on(1);
    ok('staff_working_on(Monday) returns the roster', count($rostered) > 0, count($rostered) . ' rostered');
    $rosterIds = array_map('intval', array_column($rostered, 'id'));
    $inactive  = array_map('intval', array_column(q("SELECT id FROM staff WHERE status = 'inactive' AND salon_id = " . (int)default_salon_id()), 'id'));
    ok('inactive staff are never rostered', array_intersect($rosterIds, $inactive) === []);
    ok('staff_working_on(Sunday) is empty', staff_working_on(7) === []);

    $shift = staff_working_hours_for($staffId, 1);
    ok(
        'the migrated staff shift equals the salon hours',
        (bool)$shift['is_working'] && $shift['start_time'] === '09:00:00' && $shift['end_time'] === '18:00:00',
        ($shift['start_time'] ?? '-') . '-' . ($shift['end_time'] ?? '-')
    );
    ok('staff_display_name() joins both names', staff_display_name($staffId) !== '' && staff_display_name($staffId) !== ('Staff #' . $staffId), staff_display_name($staffId));
    ok('staff_roster_summary() is non-empty', staff_roster_summary($staffId) !== '');

    /* ---- the server-side gate ---- */
    $cases = [
        'a closed day'                => ['date' => SUN_CLOSED, 'start' => '10:00', 'duration_minutes' => 30],
        'a malformed time'            => ['date' => $MON_FREE, 'start' => 'nonsense', 'duration_minutes' => 30],
        'a zero duration'             => ['date' => $MON_FREE, 'start' => '10:00', 'duration_minutes' => 0],
        'a time before opening'       => ['date' => $MON_FREE, 'start' => '07:00', 'duration_minutes' => 30],
        'a booking past closing'      => ['date' => $MON_FREE, 'start' => '17:45', 'duration_minutes' => 60],
        'a malformed date'            => ['date' => '28-09-2026', 'start' => '10:00', 'duration_minutes' => 30],
        'an unknown staff member'     => ['date' => $MON_FREE, 'start' => '10:00', 'duration_minutes' => 30, 'staff_id' => 999999],
    ];

    foreach ($cases as $label => $args) {
        $reason = scheduling_validate($args);
        ok("{$label} is refused", $reason !== null, (string)$reason);
    }

    $legal = scheduling_validate([
        'date' => $MON_FREE, 'start' => '10:00', 'duration_minutes' => 30, 'staff_id' => $staffId,
    ]);
    ok('a legal booking passes with no reason', $legal === null, (string)$legal);

    /* ---- legacy wrappers ---- */
    ok('available_slots() delegates to the engine', available_slots($MON_FREE) === $all36, count($all36) . ' slots');
    ok('slot_is_available() accepts a free slot', slot_is_available($MON_FREE, '10:00:00', '10:30:00') === true);
    ok('slot_is_available() refuses a closed day', slot_is_available(SUN_CLOSED, '10:00:00', '10:30:00') === false);
    ok('a slot ending exactly at closing is allowed', slot_is_available($MON_FREE, '17:30:00', '18:00:00') === true);
    ok('slot_is_available() refuses a slot past closing', slot_is_available($MON_FREE, '17:45:00', '18:15:00') === false);
    ok('slot_is_available() refuses a booked slot', slot_is_available(MON_BUSY, '10:00:00', '10:30:00') === false);

    /* ---- salon breaks ---- */
    $brk = business_breaks_save(1, [['start_time' => '12:00', 'end_time' => '13:00']]);
    ok('business_breaks_save() stores a break', $brk['ok'] === true, implode('; ', $brk['errors']));
    ok('business_breaks_for() returns it', count(business_breaks_for(1)) === 1);

    $duringBreak = scheduling_validate(['date' => $MON_FREE, 'start' => '12:30', 'duration_minutes' => 30, 'staff_id' => $staffId]);
    ok('a booking inside a salon break is refused', $duringBreak !== null, (string)$duringBreak);

    $span = scheduling_validate(['date' => $MON_FREE, 'start' => '11:30', 'duration_minutes' => 120, 'staff_id' => $staffId]);
    ok('a booking spanning the break is refused', $span !== null, (string)$span);

    $withBreak = scheduling_slots($MON_FREE, null, 0);
    ok('the break removes exactly its four slots', count($withBreak) === 32, count($withBreak) . ' slots');
    ok('no slot starts inside the break', !in_array('12:00:00', $withBreak, true) && !in_array('12:45:00', $withBreak, true));
    ok('the slots resume after the break', in_array('13:00:00', $withBreak, true));

    $errs = business_breaks_save(1, [['start_time' => '13:00', 'end_time' => '12:00']]);
    ok('a break ending before it starts is rejected', $errs['ok'] === false, implode('; ', $errs['errors']));
    $errs = business_breaks_save(1, [['start_time' => '18:00', 'end_time' => '19:00']]);
    ok('a break outside opening hours is rejected', $errs['ok'] === false, implode('; ', $errs['errors']));
    business_breaks_save(1, []);

    /* ---- staff shift narrower than the salon ---- */
    /* staff_working_hours_save() replaces the WHOLE week, exactly like
       business_hours_save(), so the suite always posts all seven days. */
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

    $narrow = staff_working_hours_save($staffId, $fullWeek([1 => ['is_working' => 1, 'start_time' => '13:00', 'end_time' => '15:00']]));
    ok('staff_working_hours_save() accepts a narrower shift', $narrow['ok'] === true, implode('; ', $narrow['errors']));

    $window = scheduling_window($MON_FREE, $staffId);
    ok('the bookable window clamps to the staff shift', $window['ok'] && $window['open_min'] === 780 && $window['close_min'] === 900, $window['open_min'] . '-' . $window['close_min']);

    $rosterSlots = scheduling_slots($MON_FREE, $staffId, 0);
    ok('slots narrow with the staff shift', ($rosterSlots[0] ?? '') === '13:00:00' && (end($rosterSlots) ?: '') === '14:45:00', count($rosterSlots) . ' slots');

    $outside = scheduling_validate(['date' => $MON_FREE, 'start' => '10:00', 'duration_minutes' => 30, 'staff_id' => $staffId]);
    ok('a booking outside the staff shift is refused', $outside !== null, (string)$outside);

    $tooWide = staff_working_hours_save($staffId, $fullWeek([1 => ['is_working' => 1, 'start_time' => '08:00', 'end_time' => '20:00']]));
    ok('a staff shift wider than the salon is rejected', $tooWide['ok'] === false, implode('; ', $tooWide['errors']));

    $onClosedDay = staff_working_hours_save($staffId, $fullWeek([7 => ['is_working' => 1, 'start_time' => '09:00', 'end_time' => '18:00']]));
    ok('working on a day the salon is closed is rejected', $onClosedDay['ok'] === false, implode('; ', $onClosedDay['errors']));

    $emptyWeek = staff_working_hours_save($staffId, array_map(static fn() => ['is_working' => 0, 'start_time' => '', 'end_time' => ''], range(1, 7)));
    ok('an entirely empty week is rejected', $emptyWeek['ok'] === false, implode('; ', $emptyWeek['errors']));

    /* a closed day must not keep stale times */
    $withTimes = business_hours_all();
    $withTimes[7] = ['is_open' => 0, 'opening_time' => '09:00:00', 'closing_time' => '18:00:00'];
    business_hours_save($withTimes);
    $sunday = business_hours_for(7);
    ok('times posted for a closed day are discarded', (int)$sunday['is_open'] === 0 && $sunday['opening_time'] === null && $sunday['closing_time'] === null);

    /* A staff member who is off on a day the salon is OPEN: the message
       must be about the staff member, not about the salon. */
    $dayOff = staff_working_hours_save($staffId, $fullWeek([2 => ['is_working' => 0, 'start_time' => '', 'end_time' => '']]));
    ok('a day off is accepted', $dayOff['ok'] === true, implode('; ', $dayOff['errors']));
    ok('the day off is stored', staff_working_hours_for($staffId, 2)['is_working'] === false);

    $notWorking = scheduling_validate(['date' => TUE_FREE, 'start' => '10:00', 'duration_minutes' => 30, 'staff_id' => $staffId]);
    ok('a day off is reported as "not working"', $notWorking !== null && strpos((string)$notWorking, 'not working') !== false, (string)$notWorking);

    $dayOffWindow = scheduling_window(TUE_FREE, $staffId);
    ok('no window is offered on a staff day off', $dayOffWindow['ok'] === false && $dayOffWindow['reason'] !== null, (string)$dayOffWindow['reason']);
    ok('the salon itself is still open that day', scheduling_day_info(TUE_FREE)['is_open'] === true);
    ok('the staff member is absent from the roster that day', !in_array($staffId, array_map('intval', array_column(staff_working_on(2), 'id')), true));

    /* ---- staff breaks ---- */
    staff_working_hours_save($staffId, $fullWeek());
    $sbrk = staff_breaks_save($staffId, 1, [['start_time' => '15:00', 'end_time' => '15:30']]);
    ok('staff_breaks_save() stores a break', $sbrk['ok'] === true, implode('; ', $sbrk['errors']));
    $staffBrk = scheduling_validate(['date' => $MON_FREE, 'start' => '15:15', 'duration_minutes' => 30, 'staff_id' => $staffId]);
    ok('a booking inside a staff break is refused', $staffBrk !== null, (string)$staffBrk);
    $other = q("SELECT id FROM staff WHERE status = 'active' AND salon_id = " . (int)default_salon_id() . " AND id <> {$staffId} ORDER BY id LIMIT 1");
    if ($other) {
        $otherId = (int)$other[0]['id'];
        $otherOk = scheduling_validate(['date' => $MON_FREE, 'start' => '15:15', 'duration_minutes' => 30, 'staff_id' => $otherId]);
        ok('the same time is free for a colleague', $otherOk === null, (string)$otherOk);
    }
    staff_breaks_save($staffId, 1, []);

    /* ---- conflicts ---- */
    /* appointments.customer_id is NOT NULL with a foreign key, so the
       suite creates its own customer and drops it again (the FK is
       ON DELETE CASCADE, which also removes the probe appointment).
       salon_id is set explicitly on every fixture row: since migration 002
       the column is NOT NULL with a foreign key to `salons`, so an insert
       that omits it is either rejected or silently bound to salon 0. */
    db()->prepare(
        'INSERT INTO customers (salon_id, first_name, last_name, phone, email, created_at, updated_at)
         VALUES (:sid, "Schedule", "Probe", "0000000000", "schedule-probe@example.test", NOW(), NOW())'
    )->execute(['sid' => default_salon_id()]);
    $probeCustomerId = (int)db()->lastInsertId();
    $probeCustomers[] = $probeCustomerId;

    db()->prepare(
        'INSERT INTO appointments (salon_id, customer_id, staff_id, appointment_date, start_time, end_time, status, created_at, updated_at)
         VALUES (:sid, :cust, :staff, :date, :start, :end, "confirmed", NOW(), NOW())'
    )->execute([
        'sid'   => default_salon_id(),
        'cust'  => $probeCustomerId,
        'staff' => $staffId,
        'date'  => $MON_FREE,
        'start' => '16:00',
        'end'   => '16:30',
    ]);
    $probeAppointments[] = (int)db()->lastInsertId();
    $probeId = $probeAppointments[0];

    $clash = scheduling_validate(['date' => $MON_FREE, 'start' => '16:15', 'duration_minutes' => 30, 'staff_id' => $staffId]);
    ok('an overlapping booking for the same staff is refused', $clash !== null, (string)$clash);

    $adjacent = scheduling_validate(['date' => $MON_FREE, 'start' => '16:30', 'duration_minutes' => 30, 'staff_id' => $staffId]);
    ok('a booking starting exactly when the other ends is allowed', $adjacent === null, (string)$adjacent);

    $globalClash = scheduling_validate(['date' => $MON_FREE, 'start' => '16:15', 'duration_minutes' => 30, 'staff_id' => 0]);
    ok('an unassigned booking still conflicts globally', $globalClash !== null, (string)$globalClash);

    $selfEdit = scheduling_validate([
        'date' => $MON_FREE, 'start' => '16:00', 'duration_minutes' => 30, 'staff_id' => $staffId, 'exclude_id' => $probeId,
    ]);
    ok('an appointment may keep its own slot when edited', $selfEdit === null, (string)$selfEdit);

    $customerClash = scheduling_validate([
        'date' => $MON_FREE, 'start' => '16:00', 'duration_minutes' => 30, 'staff_id' => 0, 'customer_id' => $probeCustomerId,
    ]);
    ok('a customer may not be double-booked', $customerClash !== null, (string)$customerClash);

    $otherStaffRow = q("SELECT id FROM staff WHERE status = 'active' AND salon_id = " . (int)default_salon_id() . " AND id <> {$staffId} ORDER BY id LIMIT 1");

    if ($otherStaffRow) {
        $otherId = (int)$otherStaffRow[0]['id'];

        /* A different staff member, no customer in the request: free. */
        $otherFree = scheduling_validate([
            'date' => $MON_FREE, 'start' => '16:15', 'duration_minutes' => 30, 'staff_id' => $otherId,
        ]);
        ok('the same time is free for a different staff member', $otherFree === null, (string)$otherFree);

        /* The same customer at the same time with a different staff
           member is still a double booking. */
        $sameCustomer = scheduling_validate([
            'date' => $MON_FREE, 'start' => '16:15', 'duration_minutes' => 30,
            'staff_id' => $otherId, 'customer_id' => $probeCustomerId,
        ]);
        ok('the same customer cannot book two staff at once', $sameCustomer !== null, (string)$sameCustomer);

        /* The same customer later the same day is fine. */
        $laterSameCustomer = scheduling_validate([
            'date' => $MON_FREE, 'start' => '17:00', 'duration_minutes' => 30,
            'staff_id' => $otherId, 'customer_id' => $probeCustomerId,
        ]);
        ok('the customer may book again later the same day', $laterSameCustomer === null, (string)$laterSameCustomer);
    }

    db()->prepare('UPDATE appointments SET status = "cancelled" WHERE id = :id')->execute(['id' => $probeId]);
    $afterCancel = scheduling_validate(['date' => $MON_FREE, 'start' => '16:15', 'duration_minutes' => 30, 'staff_id' => $staffId]);
    ok('a cancelled appointment no longer blocks', $afterCancel === null, (string)$afterCancel);
    db()->prepare('UPDATE appointments SET status = "no_show" WHERE id = :id')->execute(['id' => $probeId]);
    $afterNoShow = scheduling_validate(['date' => $MON_FREE, 'start' => '16:15', 'duration_minutes' => 30, 'staff_id' => $staffId]);
    ok('a no-show appointment no longer blocks either', $afterNoShow === null, (string)$afterNoShow);

    /* ---- each refusal is blamed on its real cause ---- */
    db()->prepare('UPDATE appointments SET status = "confirmed" WHERE id = :id')->execute(['id' => $probeId]);

    $staffWord = scheduling_validate(['date' => $MON_FREE, 'start' => '16:15', 'duration_minutes' => 30, 'staff_id' => $staffId]);
    ok(
        'a staff clash names the staff member',
        is_string($staffWord) && strpos($staffWord, staff_display_name($staffId)) !== false,
        (string)$staffWord
    );

    if (isset($otherId) && $otherId > 0) {
        /* A colleague is free at 16:15, so the only thing standing in the
           way is the customer themselves. */
        $customerWord = scheduling_validate([
            'date' => $MON_FREE, 'start' => '16:15', 'duration_minutes' => 30,
            'staff_id' => $otherId, 'customer_id' => $probeCustomerId,
        ]);
        ok(
            'a customer clash is blamed on the customer, not the colleague',
            is_string($customerWord) && strpos($customerWord, 'This customer') === 0,
            (string)$customerWord
        );
    }

    $salonWord = scheduling_validate(['date' => $MON_FREE, 'start' => '16:15', 'duration_minutes' => 30, 'staff_id' => 0]);
    ok(
        'an unassigned clash is reported as a taken slot',
        is_string($salonWord) && strpos($salonWord, 'already taken') !== false,
        (string)$salonWord
    );

    /* ---- reinstating a cancelled appointment ---- */
    /* Cancelling releases the slot, so somebody else may take it. Putting
       the old appointment back is then a NEW occupancy and must be refused
       (§14) — the appointments module re-validates before it flips a
       cancelled / no-show row back onto the calendar. */
    db()->prepare('UPDATE appointments SET status = "cancelled" WHERE id = :id')->execute(['id' => $probeId]);

    db()->prepare(
        'INSERT INTO appointments (salon_id, customer_id, staff_id, appointment_date, start_time, end_time, status, created_at, updated_at)
         VALUES (:sid, :cust, :staff, :date, :start, :end, "confirmed", NOW(), NOW())'
    )->execute([
        'sid'   => default_salon_id(),
        'cust'  => $probeCustomerId,
        'staff' => $staffId,
        'date'  => $MON_FREE,
        'start' => '16:00',
        'end'   => '16:30',
    ]);
    $rebookedId = (int)db()->lastInsertId();
    $probeAppointments[] = $rebookedId;

    $reinstate = scheduling_validate([
        'date' => $MON_FREE, 'start' => '16:00', 'duration_minutes' => 30,
        'staff_id' => $staffId, 'customer_id' => $probeCustomerId, 'exclude_id' => $probeId,
    ]);
    ok('a cancelled appointment cannot reclaim a rebooked slot', $reinstate !== null, (string)$reinstate);

    $keeper = scheduling_validate([
        'date' => $MON_FREE, 'start' => '16:00', 'duration_minutes' => 30,
        'staff_id' => $staffId, 'exclude_id' => $rebookedId,
    ]);
    ok('the appointment that took the slot may keep it', $keeper === null, (string)$keeper);

    db()->prepare('DELETE FROM appointments WHERE id = :id')->execute(['id' => $rebookedId]);
    array_pop($probeAppointments);
    db()->prepare('UPDATE appointments SET status = "confirmed" WHERE id = :id')->execute(['id' => $probeId]);

    /* ---- walk-ins go through the same gate ---- */
    /* modules/walkins.php refuses to save without a staff member and then
       runs exactly this validation, so the two cases a walk-in desk is most
       likely to hit must be refused here too (§11: no walk-in exemption). */
    $walkinLate = scheduling_validate(['date' => $MON_FREE, 'start' => '17:30', 'duration_minutes' => 45, 'staff_id' => $staffId]);
    ok(
        'a walk-in overrunning closing is refused',
        is_string($walkinLate) && strpos($walkinLate, 'closes at') !== false,
        (string)$walkinLate
    );

    $walkinClash = scheduling_validate(['date' => $MON_FREE, 'start' => '16:00', 'duration_minutes' => 30, 'staff_id' => $staffId]);
    ok('a walk-in clashing with a booking is refused', $walkinClash !== null, (string)$walkinClash);

    /* ---- why an empty slot list is empty ---- */
    /* availability.php must explain itself, and the two branches it uses
       are scheduling_window() and the longest remaining free stretch. */
    $closedWindow = scheduling_window(SUN_CLOSED, null);
    ok(
        'a closed day has no window and says why',
        $closedWindow['ok'] === false && (string)($closedWindow['reason'] ?? '') !== '',
        (string)($closedWindow['reason'] ?? '-')
    );

    $mondayFree = scheduling_free_intervals($MON_FREE, null, 0);
    $longest = 0;
    foreach ($mondayFree as [$gapStart, $gapEnd]) {
        $longest = max($longest, $gapEnd - $gapStart);
    }
    ok('the booked 16:00 splits the day into two gaps', count($mondayFree) === 2, json_encode($mondayFree));
    ok('the longest gap ends when the booking starts', $longest === 420, minutes_to_duration($longest));
    ok('a service longer than that gap yields no slots', scheduling_slots($MON_FREE, null, $longest + 1) === [], minutes_to_duration($longest + 1));
    ok(
        'a service that exactly fills it is still offered once',
        count(scheduling_slots($MON_FREE, null, $longest)) === 1,
        count(scheduling_slots($MON_FREE, null, $longest)) . ' slot(s)'
    );

    /* Leave the probe exactly as the sections below expect it. */
    db()->prepare('UPDATE appointments SET status = "no_show" WHERE id = :id')->execute(['id' => $probeId]);

    /* ---- service skills ---- */
    $svc = q('SELECT id FROM services ORDER BY id LIMIT 2');
    if (count($svc) >= 2) {
        $svcA = (int)$svc[0]['id'];
        $svcB = (int)$svc[1]['id'];

        ok('no staff_services rows means unrestricted', staff_can_perform($staffId, $svcA) === true);

        staff_services_save($staffId, [$svcA]);
        ok('a listed service is allowed', staff_can_perform($staffId, $svcA) === true);
        ok('an unlisted service is refused', staff_can_perform($staffId, $svcB) === false);
        ok('staff_service_ids() returns the allow-list', staff_service_ids($staffId) === [$svcA]);

        $skillClash = scheduling_validate([
            'date' => $MON_FREE, 'start' => '10:00', 'duration_minutes' => 30,
            'staff_id' => $staffId, 'service_ids' => [$svcB],
        ]);
        ok('a service the staff member cannot perform is refused', $skillClash !== null, (string)$skillClash);

        $skillOk = scheduling_validate([
            'date' => $MON_FREE, 'start' => '10:00', 'duration_minutes' => 30,
            'staff_id' => $staffId, 'service_ids' => [$svcA],
        ]);
        ok('a listed service is allowed', $skillOk === null, (string)$skillOk);

        ok('staff_working_on() filters by skill', count(staff_working_on(1, $svcB)) < count(staff_working_on(1, $svcA)));
    }

    staff_services_save($staffId, []);

    /* ---- narrowing the week must never delete an appointment ---- */
    /* A confirmed booking that sits OUTSIDE the 12:00-13:00 window the week is
       about to be narrowed to, so narrowing it genuinely orphans a booking —
       which is exactly what §16 asks the Administrator to be warned about. */
    db()->prepare(
'INSERT INTO appointments (salon_id, customer_id, staff_id, appointment_date, start_time, end_time, status, created_at, updated_at)
                 VALUES (:sid, :cust, :staff, :date, "14:00:00", "14:30:00", "confirmed", NOW(), NOW())'
            )->execute(['sid' => default_salon_id(), 'cust' => $probeCustomerId, 'staff' => $staffId, 'date' => MON_CONFLICT]);
    $probeAppointments[] = (int)db()->lastInsertId();
    $outsideProbeId = (int)end($probeAppointments);

    $apptsBefore = count_of('appointments');
    $wideWeek    = business_hours_all();
    $narrowWeek  = [];

    foreach ($wideWeek as $d => $row) {
        $narrowWeek[$d] = !empty($row['is_open'])
            ? ['is_open' => 1, 'opening_time' => '12:00:00', 'closing_time' => '13:00:00']
            : ['is_open' => 0, 'opening_time' => null, 'closing_time' => null];
    }

    $narrowRes = business_hours_save($narrowWeek);
    ok('narrowing the week to 12:00-13:00 is accepted', $narrowRes['ok'] === true, implode('; ', $narrowRes['errors']));
    ok('narrowing the week deletes no appointment', count_of('appointments') === $apptsBefore, $apptsBefore . ' appointments');
    ok('the save reports the conflicts inline', count($narrowRes['conflicts'] ?? []) > 0, count($narrowRes['conflicts'] ?? []) . ' reported');

    $affected = business_hours_conflicts($narrowWeek);
    ok('narrowing the week reports the affected appointments', is_array($affected) && $affected !== [], count($affected) . ' affected');
    ok(
        'the booking left outside the narrowed window is the one reported',
        in_array($outsideProbeId, array_map('intval', array_column((array)$affected, 'id')), true),
        'probe ' . $outsideProbeId . ' at 14:00 on ' . MON_CONFLICT
    );
    ok('a no_show appointment is not reported as a conflict', is_array($affected) && !in_array($probeId, array_map('intval', array_column($affected, 'id')), true));

    $insideNarrow = scheduling_validate(['date' => $MON_FREE, 'start' => '10:00', 'duration_minutes' => 30, 'staff_id' => $staffId]);
    ok('new bookings are refused once hours are narrowed', $insideNarrow !== null, (string)$insideNarrow);

    business_hours_save($wideWeek);
    ok('restoring the week restores the slot count', count(scheduling_slots($MON_FREE, null, 0)) === 36, count(scheduling_slots($MON_FREE, null, 0)) . ' slots');

    /* Put the live schedule back exactly as it was, so the comparison in
       section E is exact. The shutdown handler repeats this harmlessly. */
    $restoreNow();
}

/* ==================================================================
 * E. Nothing historical was touched
 * ================================================================== */
heading('E. No historical data was touched');

$after = snapshot();

if ($isFresh) {
    /* the wizard legitimately wrote the profile, the week, the marker
       and the three mirrored settings keys */
    unset($after['salon_settings'], $after['business_hours'], $after['settings']);
}

foreach ($after as $what => $value) {
    ok("{$what} unchanged", $value === $before[$what], is_int($value) ? "{$value} row(s)" : 'hash');
}

echo PHP_EOL . str_repeat('-', 62) . PHP_EOL;
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
