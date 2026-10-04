<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Scheduling service (single source of truth).
 *
 * THE RULE
 *   AVAILABLE APPOINTMENT = SALON IS OPEN
 *                         + STAFF IS WORKING
 *                         + SERVICE DURATION FITS
 *                         + NO CONFLICT
 *                         + NO BREAK
 *
 * Every read and every write path (appointments, walk-ins, calendar,
 * public booking, staff schedules) goes through the functions below, so
 * the rule is enforced once, in PHP, on the server.
 *
 * Day-of-week convention (ISO-8601, identical to PHP date('N')):
 *   1 = Monday .. 7 = Sunday
 *
 * Times are stored and compared as TIME 'HH:MM:SS' and converted to an
 * integer number of minutes-since-midnight for all arithmetic. The UI may
 * render 12-hour AM/PM, the database never does.
 *
 * Extending later (holidays, staff leave, special opening hours) only
 * needs a new predicate in scheduling_window() / scheduling_slots() —
 * no change to the appointment write path.
 */

/* ==================================================================
 * 1. Day-of-week helpers
 * ================================================================== */

/** @return array<int,string> 1 => 'Monday' .. 7 => 'Sunday */
function scheduling_days(): array
{
    return [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];
}

function scheduling_day_short(int $day): string
{
    return substr(scheduling_days()[$day] ?? '', 0, 3);
}

/** 1 (Monday) .. 7 (Sunday) for a Y-m-d date string. */
function scheduling_day_index(string $date): int
{
    return (int)date('N', strtotime($date . ' 12:00:00'));
}

/** The Y-m-d date of the next occurrence of $day (1..7), from $from. */
function scheduling_next_weekday(int $day, string $from = null): string
{
    $from = $from ?: date('Y-m-d');
    $diff = ($day - scheduling_day_index($from) + 7) % 7;
    return date('Y-m-d', strtotime($from . " +{$diff} days"));
}

/* ==================================================================
 * 2. Time helpers
 * ================================================================== */

/**
 * Normalise any accepted time input to 'HH:MM:SS'.
 * Returns NULL for empty / unparseable input so callers can treat a
 * missing time and a broken time the same way.
 */
function scheduling_normalize_time($time): ?string
{
    $time = trim((string)$time);

    if ($time === '') {
        return null;
    }

    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) {
        $h = (int)$m[1];
        $i = (int)$m[2];
        $s = (int)($m[3] ?? 0);

        if ($h > 23 || $i > 59 || $s > 59) {
            return null;
        }

        return sprintf('%02d:%02d:%02d', $h, $i, $s);
    }

    return null;
}

/** Minutes since midnight for 'HH:MM' / 'HH:MM:SS'. */
function scheduling_minutes($time): int
{
    $norm = scheduling_normalize_time($time);

    if ($norm === null) {
        return 0;
    }

    $parts = explode(':', $norm);

    return ((int)$parts[0] * 60) + (int)$parts[1];
}

/** Minutes since midnight -> 'HH:MM:SS'. */
function scheduling_clock(int $minutes): string
{
    $minutes = max(0, min((24 * 60) - 1, $minutes));

    return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
}

/** 'HH:MM:SS' -> 'g:i A'. */
function scheduling_human_time($time): string
{
    $norm = scheduling_normalize_time($time);

    return $norm === null ? '—' : date('g:i A', strtotime($norm));
}

/* ==================================================================
 * 3. Salon profile (name + owner first / last)
 * ================================================================== */

/**
 * The salon row for the tenant being served, or NULL when there is none.
 *
 * `salons` is the single source of truth for the profile. `salon_settings` is
 * a legacy mirror kept only so older get_setting() call sites keep working;
 * reading it here (as this function used to, via `WHERE id = 1`) would hand
 * every tenant the first salon's name.
 *
 * @return array<string,mixed>|null
 */
function salon_profile(bool $refresh = false): ?array
{
    static $cache = [];
    static $loaded = [];

    if ($refresh) {
        $cache  = [];
        $loaded = [];
    }

    $salonId = default_salon_id();

    /* Fresh install: no salon has been created yet, so there is no profile. */
    if ($salonId <= 0) {
        return null;
    }

    if (isset($loaded[$salonId])) {
        return $cache[$salonId];
    }

    $loaded[$salonId] = true;

    try {
        $stmt = db()->prepare('SELECT * FROM salons WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $salonId]);
        $cache[$salonId] = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $cache[$salonId] = null;
    }

    return $cache[$salonId];
}

/**
 * Has the first-time setup wizard been finished?
 *
 * The marker is `salons.setup_completed_at`, NOT the mere existence of the
 * row: an upgraded database already carries a row from
 * database/migration_scheduling.sql, so testing for the row would skip setup
 * forever and would also treat a fresh install (no row at all) as already
 * configured.
 */
function salon_profile_is_configured(): bool
{
    $profile = salon_profile();

    return is_array($profile) && !empty($profile['setup_completed_at']);
}

/** Stamp the setup marker. Called only after a successful wizard POST. */
function salon_setup_mark_complete(): void
{
    $salonId = default_salon_id();

    if ($salonId > 0) {
        try {
            db()->prepare('UPDATE salons SET setup_completed_at = NOW(), updated_at = NOW() WHERE id = :id AND setup_completed_at IS NULL')
               ->execute(['id' => $salonId]);

            /* Keep the legacy mirror in step so id=1 readers stay truthful. */
            db()->prepare('UPDATE salon_settings SET setup_completed_at = NOW() WHERE salon_id = :id AND setup_completed_at IS NULL')
               ->execute(['id' => $salonId]);
        } catch (Throwable $e) {
            /* Never block the wizard on the marker itself. */
        }
    }

    salon_profile(true);
}

/**
 * Do the scheduling tables exist? False means the install is running on a
 * pre-scheduling schema and database/migration_scheduling.sql has not been
 * applied yet — the wizard cannot run either, because it writes to these
 * tables. Kept separate from salon_profile(), which returns NULL both for a
 * missing table and for a fresh install that simply has no row yet.
 */
function scheduling_schema_ready(): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN (\'salon_settings\', \'business_hours\',
                                   \'business_breaks\', \'staff_working_hours\',
                                   \'staff_breaks\', \'staff_services\')'
        );
        $stmt->execute();
        $ready = ((int)$stmt->fetchColumn()) === 6;
    } catch (Throwable $e) {
        $ready = false;
    }

    return $ready;
}

/**
 * Gate every web request behind the first-time Setup Wizard.
 *
 * Without a configured week nothing in the app can be scheduled, so an
 * unconfigured install is sent to setup.php instead of being allowed to
 * half-work. Skipped for the CLI (test suite, cron) and for setup.php
 * itself, which would otherwise redirect to itself forever.
 */
function require_setup_completed(): void
{
    if (PHP_SAPI === 'cli' || salon_profile_is_configured()) {
        return;
    }

    if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'setup.php') {
        return;
    }

    if (!scheduling_schema_ready()) {
        http_response_code(500);
        exit('This installation needs its scheduling tables. '
           . 'Run database/migration_scheduling.sql against the database, then reload this page.');
    }

    redirect(base_url('setup.php'));
}

/**
 * Validate + persist the salon profile.
 *
 * @param array<string,mixed> $data salon_name, owner_first_name, owner_last_name
 * @return array{ok:bool, errors:array<int,string>}
 */
function salon_profile_save(array $data): array
{
    $salonName = trim((string)($data['salon_name'] ?? ''));
    $first     = trim((string)($data['owner_first_name'] ?? ''));
    $last      = trim((string)($data['owner_last_name'] ?? ''));

    $errors = [];

    if ($salonName === '') {
        $errors[] = 'Salon name is required.';
    } elseif (mb_strlen($salonName) > 150) {
        $errors[] = 'Salon name must be 150 characters or fewer.';
    }

    if ($first === '') {
        $errors[] = 'Owner first name is required.';
    } elseif (mb_strlen($first) > 100) {
        $errors[] = 'Owner first name must be 100 characters or fewer.';
    }

    if ($last === '') {
        $errors[] = 'Owner last name is required.';
    } elseif (mb_strlen($last) > 100) {
        $errors[] = 'Owner last name must be 100 characters or fewer.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

$salonId = default_salon_id();

    try {
        if ($salonId > 0) {
            db()->prepare(
                'UPDATE salons
                    SET salon_name = :name, owner_first_name = :first, owner_last_name = :last, updated_at = NOW()
                  WHERE id = :id'
            )->execute([
                'name'  => $salonName,
                'first' => $first,
                'last'  => $last,
                'id'    => $salonId,
            ]);
        } else {
            /* Fresh install: the wizard creates the very first salon. Without
               this the profile rows below had no tenant to belong to. */
            db()->prepare(
                'INSERT INTO salons (salon_name, owner_first_name, owner_last_name, status)
                 VALUES (:name, :first, :last, \'active\')'
            )->execute([
                'name'  => $salonName,
                'first' => $first,
                'last'  => $last,
            ]);

            $salonId = (int)db()->lastInsertId();

            if ($salonId <= 0) {
                return ['ok' => false, 'errors' => ['Could not save the salon information. Please try again.']];
            }
        }

        /* Legacy mirror row. `salons` is authoritative; this exists so the
           ~20 get_setting('salon_name') call sites keep working untouched. */
        db()->prepare(
            'INSERT INTO salon_settings (salon_id, salon_name, owner_first_name, owner_last_name)
             VALUES (:sid, :name, :first, :last)
             ON DUPLICATE KEY UPDATE
                 salon_name       = :name2,
                 owner_first_name = :first2,
                 owner_last_name  = :last2'
        )->execute([
            'sid'   => $salonId,
            'name'  => $salonName, 'name2'  => $salonName,
            'first' => $first,     'first2' => $first,
            'last'  => $last,      'last2' => $last,
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => ['Could not save the salon information. Please try again.']];
    }

    /* Mirror into the key/value settings table so the ~20 existing
       get_setting('salon_name') call sites keep working untouched. */
    save_setting('salon_name', $salonName);
    save_setting('owner_first_name', $first);
    save_setting('owner_last_name', $last);

    salon_profile(true);

    return ['ok' => true, 'errors' => []];
}

function owner_first_name(): string
{
    $profile = salon_profile();

    return (string)($profile['owner_first_name'] ?? get_setting('owner_first_name', '') ?? '');
}

function owner_last_name(): string
{
    $profile = salon_profile();

    return (string)($profile['owner_last_name'] ?? get_setting('owner_last_name', '') ?? '');
}

/** "Sara Tesfaye" — owner_first_name + owner_last_name. */
function owner_full_name(): string
{
    return trim(owner_first_name() . ' ' . owner_last_name());
}

/* ==================================================================
 * 4. Business hours
 * ================================================================== */

/**
 * All seven weekday rows keyed 1..7, always complete (missing rows are
 * reported as closed) so callers never need a null check.
 *
 * @return array<int,array<string,mixed>>
 */
function business_hours_all(bool $refresh = false): array
{
    /* Cached per salon: a long-lived process that serves two tenants must not
       hand back the first tenant's week. */
    static $cache = [];

    if ($refresh) {
        $cache = [];
    }

    $salonId = default_salon_id();

    if (isset($cache[$salonId])) {
        return $cache[$salonId];
    }

    $rows = [];

    try {
        $stmt = db()->prepare(
            'SELECT * FROM business_hours WHERE salon_id = :sid ORDER BY day_of_week'
        );
        $stmt->execute(['sid' => $salonId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }

    $week = [];

    foreach (scheduling_days() as $day => $label) {
        $found = null;

        foreach ($rows as $row) {
            if ((int)$row['day_of_week'] === $day) {
                $found = $row;
                break;
            }
        }

        $week[$day] = [
            'day_of_week'  => $day,
            'day_name'     => $label,
            'is_open'      => $found ? (int)$found['is_open'] === 1 : false,
            'opening_time' => $found ? $found['opening_time'] : null,
            'closing_time' => $found ? $found['closing_time'] : null,
        ];
    }

    return $cache[$salonId] = $week;
}

function business_hours_for(int $day): array
{
    return business_hours_all()[$day] ?? [
        'day_of_week'  => $day,
        'day_name'     => scheduling_days()[$day] ?? '',
        'is_open'      => false,
        'opening_time' => null,
        'closing_time' => null,
    ];
}

/** The first weekday the salon is open — used for empty-state defaults. */
function business_hours_first_open_day(): ?int
{
    foreach (business_hours_all() as $day => $row) {
        if ($row['is_open'] && $row['opening_time'] && $row['closing_time']) {
            return $day;
        }
    }

    return null;
}

/**
 * Validate a single weekday.
 *
 * @return array<int,string> error messages (empty when valid)
 */
function business_hours_validate_day(int $day, bool $isOpen, ?string $opening, ?string $closing): array
{
    $errors = [];
    $label  = scheduling_days()[$day] ?? ('Day ' . $day);

    if (!$isOpen) {
        /* A closed day must not carry times. */
        return $errors;
    }

    if ($opening === null) {
        $errors[] = "{$label}: an opening time is required.";
    }

    if ($closing === null) {
        $errors[] = "{$label}: a closing time is required.";
    }

    if ($opening !== null && $closing !== null && scheduling_minutes($closing) <= scheduling_minutes($opening)) {
        $errors[] = "{$label}: the closing time must be later than the opening time.";
    }

    return $errors;
}

/**
 * Validate a whole week.
 *
 * @param array<int,array{is_open:bool,opening_time:?string,closing_time:?string}> $input keyed 1..7
 * @return array<int,string>
 */
function business_hours_validate_week(array $input): array
{
    $errors    = [];
    $openCount = 0;

    foreach (scheduling_days() as $day => $label) {
        $row     = $input[$day] ?? ['is_open' => false, 'opening_time' => null, 'closing_time' => null];
        $isOpen  = !empty($row['is_open']);
        $opening = scheduling_normalize_time($row['opening_time'] ?? '');
        $closing = scheduling_normalize_time($row['closing_time'] ?? '');

        if ($isOpen) {
            $openCount++;
        }

        foreach (business_hours_validate_day($day, $isOpen, $opening, $closing) as $err) {
            $errors[] = $err;
        }
    }

    if ($openCount === 0) {
        $errors[] = 'At least one day must be marked as an Open / Working day.';
    }

    return $errors;
}

/**
 * Existing appointments that the proposed hours would push outside the
 * operating period. Nothing is deleted — the Administrator resolves them
 * manually (§16).
 *
 * @param array<int,array<string,mixed>> $input keyed 1..7
 * @return array<int,array<string,mixed>>
 */
function business_hours_conflicts(array $input): array
{
    $conflicts = [];

    try {
        /* Scoped on both sides of the join: a customer row from another salon
           must never be joined onto this salon's appointments. */
        $stmt = db()->prepare(
            'SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.status,
                    c.first_name AS customer_first, c.last_name AS customer_last
               FROM appointments a
               JOIN customers c ON c.id = a.customer_id AND c.salon_id = a.salon_id
              WHERE a.salon_id = :sid
                AND a.status NOT IN ("cancelled", "no_show")
                AND a.appointment_date >= CURDATE()
              ORDER BY a.appointment_date, a.start_time'
        );
        $stmt->execute(['sid' => default_salon_id()]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    /* A rejected entry is cached so the query is not repeated per day. */
    $breaks = business_breaks_all();

    foreach ($rows as $row) {
        $day  = scheduling_day_index($row['appointment_date']);
        $spec = $input[$day] ?? ['is_open' => false, 'opening_time' => null, 'closing_time' => null];

        $customer = trim($row['customer_first'] . ' ' . $row['customer_last']);
        $start    = scheduling_minutes($row['start_time']);
        $end      = scheduling_minutes($row['end_time']);

        $reason = null;

        if (empty($spec['is_open'])) {
            $reason = sprintf('%s is now a closed day.', scheduling_days()[$day] ?? '');
        } else {
            $open  = scheduling_minutes($spec['opening_time'] ?? '');
            $close = scheduling_minutes($spec['closing_time'] ?? '');

            if ($start < $open) {
                $reason = 'starts before the new opening time.';
            } elseif ($end > $close) {
                $reason = 'ends after the new closing time.';
            } else {
                foreach (($breaks[$day] ?? []) as $break) {
                    if ($start < scheduling_minutes($break['end_time'])
                        && $end > scheduling_minutes($break['start_time'])) {
                        $reason = 'overlaps a configured break.';
                        break;
                    }
                }
            }
        }

        if ($reason !== null) {
            $conflicts[] = [
                'id'          => (int)$row['id'],
                'date'        => $row['appointment_date'],
                'day_name'    => scheduling_days()[$day] ?? '',
                'start_time'  => $row['start_time'],
                'end_time'    => $row['end_time'],
                'customer'    => $customer,
                'reason'      => $reason,
            ];
        }
    }

    return $conflicts;
}

/**
 * Persist a whole week. Existing rows are updated in place, so no
 * appointment that points at a schedule is disturbed.
 *
 * @return array{ok:bool, errors:array<int,string>, conflicts:array<int,array<string,mixed>>}
 */
function business_hours_save(array $input): array
{
    $week = [];

    foreach (scheduling_days() as $day => $label) {
        $row     = $input[$day] ?? [];
        $isOpen  = !empty($row['is_open']);
        $opening = $isOpen ? scheduling_normalize_time($row['opening_time'] ?? '') : null;
        $closing = $isOpen ? scheduling_normalize_time($row['closing_time'] ?? '') : null;

        $week[$day] = ['is_open' => $isOpen, 'opening_time' => $opening, 'closing_time' => $closing];
    }

    $errors = business_hours_validate_week($week);

    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'conflicts' => []];
    }

    $conflicts = business_hours_conflicts($week);

    try {
        /* salon_id is part of the unique key since migration 002, so the
           upsert targets this salon's row for that weekday only. */
        $stmt = db()->prepare(
            'INSERT INTO business_hours (salon_id, day_of_week, is_open, opening_time, closing_time)
             VALUES (:sid, :day, :is_open, :opening, :closing)
             ON DUPLICATE KEY UPDATE
                 is_open      = :is_open2,
                 opening_time = :opening2,
                 closing_time = :closing2'
        );

        $salonId = default_salon_id();

        foreach ($week as $day => $row) {
            $stmt->execute([
                'sid'      => $salonId,
                'day'      => $day,
                'is_open'  => $row['is_open'] ? 1 : 0,
                'opening'  => $row['opening_time'],
                'closing'  => $row['closing_time'],
                'is_open2' => $row['is_open'] ? 1 : 0,
                'opening2' => $row['opening_time'],
                'closing2' => $row['closing_time'],
            ]);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => ['Could not save the business hours. Please try again.'], 'conflicts' => []];
    }

    business_hours_all(true);

    /* Keep the legacy single-value settings in step so any older view
       or report that still reads business_open / business_close shows
       the most common operating day rather than a stale value. */
    $reference = $week[1]['is_open'] ? 1 : business_hours_first_open_day();

    if ($reference !== null) {
        save_setting('business_open', substr((string)$week[$reference]['opening_time'], 0, 5));
        save_setting('business_close', substr((string)$week[$reference]['closing_time'], 0, 5));
    }

    return ['ok' => true, 'errors' => [], 'conflicts' => $conflicts];
}

/**
 * One-line summary of the weekly schedule, e.g.
 * "Mon–Sat: 9:00 AM – 6:00 PM · Sun: Closed".
 */
function business_hours_summary(): string
{
    $all    = business_hours_all();
    $groups = [];

    foreach ($all as $day => $row) {
        $key = $row['is_open']
            ? scheduling_human_time($row['opening_time']) . ' – ' . scheduling_human_time($row['closing_time'])
            : 'Closed';

        $groups[$key][] = scheduling_day_short($day);
    }

    $parts = [];

    foreach ($groups as $label => $days) {
        $parts[] = implode('–', $days) . ': ' . $label;
    }

    return implode(' · ', $parts);
}

/* ==================================================================
 * 5. Salon breaks
 * ================================================================== */

/**
 * @return array<int,array<int,array<string,mixed>>> keyed by day_of_week
 */
function business_breaks_all(bool $refresh = false): array
{
    static $cache = [];

    if ($refresh) {
        $cache = [];
    }

    $salonId = default_salon_id();

    if (isset($cache[$salonId])) {
        return $cache[$salonId];
    }

    $breaks = array_fill_keys(array_keys(scheduling_days()), []);

    try {
        $stmt = db()->prepare(
            'SELECT * FROM business_breaks WHERE salon_id = :sid ORDER BY day_of_week, start_time'
        );
        $stmt->execute(['sid' => $salonId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }

    foreach ($rows as $row) {
        $day = (int)$row['day_of_week'];
        if (isset($breaks[$day])) {
            $breaks[$day][] = $row;
        }
    }

    return $cache[$salonId] = $breaks;
}

function business_breaks_for(int $day): array
{
    return business_breaks_all()[$day] ?? [];
}

/**
 * Replace every break on one weekday.
 *
 * @param array<int,array{start_time:string,end_time:string}> $breaks
 * @return array{ok:bool, errors:array<int,string>}
 */
function business_breaks_save(int $day, array $breaks): array
{
    $label = scheduling_days()[$day] ?? ('Day ' . $day);
    $hours = business_hours_for($day);
    $errors = [];
    $clean  = [];

    if (empty($hours['is_open'])) {
        /* An empty list on a closed day is how the caller clears the breaks
           left over from when the day was still open — not an error. */
        if ($breaks) {
            return ['ok' => false, 'errors' => ["{$label} is a closed day — a break cannot be added to it."]];
        }

        try {
            db()->prepare('DELETE FROM business_breaks WHERE salon_id = :sid AND day_of_week = :day')
               ->execute(['sid' => default_salon_id(), 'day' => $day]);
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => ['Could not save the breaks. Please try again.']];
        }

        business_breaks_all(true);

        return ['ok' => true, 'errors' => []];
    }

    $openMin  = scheduling_minutes($hours['opening_time']);
    $closeMin = scheduling_minutes($hours['closing_time']);

    foreach ($breaks as $break) {
        $start = scheduling_normalize_time($break['start_time'] ?? '');
        $end   = scheduling_normalize_time($break['end_time'] ?? '');

        if ($start === null || $end === null) {
            $errors[] = "{$label}: every break needs a start and an end time.";
            continue;
        }

        $s = scheduling_minutes($start);
        $e = scheduling_minutes($end);

        if ($e <= $s) {
            $errors[] = "{$label}: a break must end after it starts.";
            continue;
        }

        if ($s < $openMin || $e > $closeMin) {
            $errors[] = "{$label}: a break must sit inside the opening hours for that day.";
            continue;
        }

        $clashes = false;

        foreach ($clean as $kept) {
            if ($s < scheduling_minutes($kept['end_time']) && $e > scheduling_minutes($kept['start_time'])) {
                $clashes = true;
                break;
            }
        }

        if ($clashes) {
            $errors[] = "{$label}: two breaks on the same day may not overlap.";
            continue;
        }

        $clean[] = ['start_time' => $start, 'end_time' => $end];
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    try {
        $salonId = default_salon_id();
        db()->prepare('DELETE FROM business_breaks WHERE salon_id = :sid AND day_of_week = :day')
           ->execute(['sid' => $salonId, 'day' => $day]);

        if ($clean) {
            $insert = db()->prepare(
                'INSERT INTO business_breaks (salon_id, day_of_week, start_time, end_time)
                 VALUES (:sid, :day, :start, :end)'
            );
            foreach ($clean as $break) {
                $insert->execute([
                    'sid'   => $salonId,
                    'day'   => $day,
                    'start' => $break['start_time'],
                    'end'   => $break['end_time'],
                ]);
            }
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => ['Could not save the breaks. Please try again.']];
    }

    business_breaks_all(true);

    return ['ok' => true, 'errors' => []];
}

/* ==================================================================
 * 6. Staff working hours
 * ================================================================== */

/**
 * A staff member's whole week, keyed 1..7. Days with no row are treated
 * as NOT working, so a staff member is never bookable by accident.
 *
 * @return array<int,array<string,mixed>>
 */
function staff_working_hours(int $staffId, bool $refresh = false): array
{
    static $cache = [];

    if ($refresh) {
        $cache = [];
    }

    $salonId = default_salon_id();
    $cacheKey = $salonId . ':' . $staffId;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $rows = [];

    try {
        /* Both conditions: the staff member must belong to this salon AND the
           schedule rows must belong to this salon. */
        $stmt = db()->prepare(
            'SELECT * FROM staff_working_hours WHERE staff_id = :id AND salon_id = :sid'
        );
        $stmt->execute(['id' => $staffId, 'sid' => $salonId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }

    $out = [];

    foreach (scheduling_days() as $day => $label) {
        $found = null;

        foreach ($rows as $row) {
            if ((int)$row['day_of_week'] === $day) {
                $found = $row;
                break;
            }
        }

        $out[$day] = [
            'day_of_week'  => $day,
            'day_name'     => $label,
            'is_working'   => $found ? (int)$found['is_working'] === 1 : false,
            'start_time'   => $found ? $found['start_time'] : null,
            'end_time'     => $found ? $found['end_time'] : null,
        ];
    }

    $cache[$cacheKey] = $out;

    return $out;
}

function staff_working_hours_for(int $staffId, int $day): array
{
    return staff_working_hours($staffId)[$day] ?? [
        'day_of_week' => $day,
        'day_name'    => scheduling_days()[$day] ?? '',
        'is_working'  => false,
        'start_time'  => null,
        'end_time'    => null,
    ];
}

/**
 * Save a staff member's week, enforcing rule §6: a staff shift must sit
 * strictly inside the salon's operating window for the same day, and a day
 * the salon is closed can never be a normal working day.
 *
 * @param array<int,array{is_working:bool,start_time:?string,end_time:?string}> $input keyed 1..7
 * @return array{ok:bool, errors:array<int,string>}
 */
function staff_working_hours_save(int $staffId, array $input): array
{
    $salon  = business_hours_all();
    $errors = [];
    $clean  = [];

    foreach (scheduling_days() as $day => $label) {
        $row       = $input[$day] ?? [];
        $isWorking = !empty($row['is_working']);
        $start     = $isWorking ? scheduling_normalize_time($row['start_time'] ?? '') : null;
        $end       = $isWorking ? scheduling_normalize_time($row['end_time'] ?? '') : null;

        if (!$isWorking) {
            $clean[$day] = ['is_working' => false, 'start_time' => null, 'end_time' => null];
            continue;
        }

        if (!$salon[$day]['is_open']) {
            $errors[] = "{$label}: the salon is closed on {$label}, so it cannot be a normal staff working day.";
            continue;
        }

        if ($start === null) {
            $errors[] = "{$label}: a start time is required for a working day.";
            continue;
        }

        if ($end === null) {
            $errors[] = "{$label}: an end time is required for a working day.";
            continue;
        }

        $s = scheduling_minutes($start);
        $e = scheduling_minutes($end);

        if ($e <= $s) {
            $errors[] = "{$label}: the end time must be later than the start time.";
            continue;
        }

        $openMin  = scheduling_minutes($salon[$day]['opening_time']);
        $closeMin = scheduling_minutes($salon[$day]['closing_time']);

        if ($s < $openMin || $e > $closeMin) {
            $errors[] = sprintf(
                '%s: staff hours (%s – %s) must sit inside the salon hours (%s – %s) for that day.',
                $label,
                scheduling_human_time($start),
                scheduling_human_time($end),
                scheduling_human_time($salon[$day]['opening_time']),
                scheduling_human_time($salon[$day]['closing_time'])
            );
            continue;
        }

        $clean[$day] = ['is_working' => true, 'start_time' => $start, 'end_time' => $end];
    }

    $workingCount = count(array_filter($clean, static fn($d) => $d['is_working']));

    if ($workingCount === 0) {
        $errors[] = 'A staff member must be rostered on at least one day.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    try {
        /* Refuse to write a schedule for a staff member owned by another
           salon, rather than silently creating rows here. */
        if (!salon_owns('staff', $staffId, default_salon_id())) {
            return ['ok' => false, 'errors' => ['That staff member does not belong to your salon.']];
        }

        $stmt = db()->prepare(
            'INSERT INTO staff_working_hours (salon_id, staff_id, day_of_week, is_working, start_time, end_time)
             VALUES (:sid, :staff, :day, :is_working, :start, :end)
             ON DUPLICATE KEY UPDATE
                 is_working = :is_working2,
                 start_time = :start2,
                 end_time   = :end2'
        );

        $salonId = default_salon_id();

        foreach ($clean as $day => $row) {
            $stmt->execute([
                'sid'         => $salonId,
                'staff'       => $staffId,
                'day'         => $day,
                'is_working'  => $row['is_working'] ? 1 : 0,
                'start'       => $row['start_time'],
                'end'         => $row['end_time'],
                'is_working2' => $row['is_working'] ? 1 : 0,
                'start2'      => $row['start_time'],
                'end2'        => $row['end_time'],
            ]);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => ['Could not save the staff schedule. Please try again.']];
    }

    staff_working_hours($staffId, true);

    return ['ok' => true, 'errors' => []];
}

/** Staff whose schedule is entirely "not working" (display helper). */
function staff_roster_summary(int $staffId): string
{
    $week  = staff_working_hours($staffId);
    $parts = [];

    foreach ($week as $day => $row) {
        $parts[] = $row['is_working']
            ? scheduling_day_short($day) . ' ' . scheduling_human_time($row['start_time']) . '–' . scheduling_human_time($row['end_time'])
            : scheduling_day_short($day) . ' off';
    }

    return implode(' · ', $parts);
}

/* ==================================================================
 * 7. Staff breaks
 * ================================================================== */

/**
 * @return array<int,array<int,array<string,mixed>>> keyed by day_of_week
 */
function staff_breaks_all(int $staffId, bool $refresh = false): array
{
    static $cache = [];

    if ($refresh) {
        $cache = [];
    }

    $salonId  = default_salon_id();
    $cacheKey = $salonId . ':' . $staffId;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $out = array_fill_keys(array_keys(scheduling_days()), []);

    try {
        $stmt = db()->prepare(
            'SELECT * FROM staff_breaks WHERE staff_id = :id AND salon_id = :sid ORDER BY day_of_week, start_time'
        );
        $stmt->execute(['id' => $staffId, 'sid' => $salonId]);

        foreach ($stmt->fetchAll() as $row) {
            $day = (int)$row['day_of_week'];
            if (isset($out[$day])) {
                $out[$day][] = $row;
            }
        }
    } catch (Throwable $e) {
        /* no breaks */
    }

    $cache[$cacheKey] = $out;

    return $out;
}

function staff_breaks_for(int $staffId, int $day): array
{
    return staff_breaks_all($staffId)[$day] ?? [];
}

/**
 * Replace every break on one weekday for one staff member.
 *
 * @return array{ok:bool, errors:array<int,string>}
 */
function staff_breaks_save(int $staffId, int $day, array $breaks): array
{
    $name   = staff_display_name($staffId);
    $label  = scheduling_days()[$day] ?? ('Day ' . $day);
    $errors = [];
    $clean  = [];

    $shift = staff_working_hours_for($staffId, $day);

    if (!$shift['is_working']) {
        /* An empty list on a day off clears the breaks left over from when
           the staff member was still rostered — not an error. */
        if ($breaks) {
            return ['ok' => false, 'errors' => ["{$name} is not rostered on {$label}, so no break can be added for that day."]];
        }

        try {
            db()->prepare('DELETE FROM staff_breaks WHERE salon_id = :sid AND staff_id = :staff AND day_of_week = :day')
               ->execute(['sid' => default_salon_id(), 'staff' => $staffId, 'day' => $day]);
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => ['Could not save the staff breaks. Please try again.']];
        }

        staff_breaks_all($staffId, true);

        return ['ok' => true, 'errors' => []];
    }

    $workMin = scheduling_minutes($shift['start_time']);
    $lastMin = scheduling_minutes($shift['end_time']);

    foreach ($breaks as $break) {
        $start = scheduling_normalize_time($break['start_time'] ?? '');
        $end   = scheduling_normalize_time($break['end_time'] ?? '');

        if ($start === null || $end === null) {
            $errors[] = "{$label}: every break needs a start and an end time.";
            continue;
        }

        $s = scheduling_minutes($start);
        $e = scheduling_minutes($end);

        if ($e <= $s) {
            $errors[] = "{$label}: a break must end after it starts.";
            continue;
        }

        if ($s < $workMin || $e > $lastMin) {
            $errors[] = "{$label}: a break must sit inside the staff member's working hours.";
            continue;
        }

        $clashes = false;

        foreach ($clean as $kept) {
            if ($s < scheduling_minutes($kept['end_time']) && $e > scheduling_minutes($kept['start_time'])) {
                $clashes = true;
                break;
            }
        }

        if ($clashes) {
            $errors[] = "{$label}: two breaks on the same day may not overlap.";
            continue;
        }

        $clean[] = ['start_time' => $start, 'end_time' => $end];
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    try {
        $salonId = default_salon_id();
        db()->prepare('DELETE FROM staff_breaks WHERE salon_id = :sid AND staff_id = :staff AND day_of_week = :day')
            ->execute(['sid' => $salonId, 'staff' => $staffId, 'day' => $day]);

        if ($clean) {
            $insert = db()->prepare(
                'INSERT INTO staff_breaks (salon_id, staff_id, day_of_week, start_time, end_time)
                 VALUES (:sid, :staff, :day, :start, :end)'
            );
            foreach ($clean as $break) {
                $insert->execute([
                    'sid'   => $salonId,
                    'staff' => $staffId,
                    'day'   => $day,
                    'start' => $break['start_time'],
                    'end'   => $break['end_time'],
                ]);
            }
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => ['Could not save the staff breaks. Please try again.']];
    }

    staff_breaks_all($staffId, true);

    return ['ok' => true, 'errors' => []];
}

/* ==================================================================
 * 8. Staff <-> service skills
 * ================================================================== */

/**
 * Service ids a staff member may perform.
 * An EMPTY list means "unrestricted" — the staff member can perform any
 * active service. As soon as the list is non-empty it becomes the
 * allow-list.
 *
 * @return array<int,int>
 */
function staff_service_ids(int $staffId): array
{
    try {
        $stmt = db()->prepare(
            'SELECT service_id FROM staff_services WHERE staff_id = :id AND salon_id = :sid ORDER BY service_id'
        );
        $stmt->execute(['id' => $staffId, 'sid' => default_salon_id()]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

function staff_can_perform(int $staffId, int $serviceId): bool
{
    $allowed = staff_service_ids($staffId);

    return $allowed === [] || in_array($serviceId, $allowed, true);
}

function staff_services_save(int $staffId, array $serviceIds): void
{
    $salonId = default_salon_id();

    db()->prepare('DELETE FROM staff_services WHERE staff_id = :id AND salon_id = :sid')
       ->execute(['id' => $staffId, 'sid' => $salonId]);

    $serviceIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds), static fn($id) => $id > 0)));

    if (!$serviceIds) {
        return;
    }

    $insert = db()->prepare(
        'INSERT INTO staff_services (salon_id, staff_id, service_id) VALUES (:sid, :staff, :svc)'
    );

    foreach ($serviceIds as $serviceId) {
        try {
            /* Also confirm the service belongs to this salon, so a forged
               service id cannot attach another tenant's service to a roster. */
            $insert->execute(['sid' => $salonId, 'staff' => $staffId, 'svc' => $serviceId]);
        } catch (Throwable $e) {
            /* Unknown or foreign service id — skip it. */
        }
    }
}

/** "Jane Doe" for a staff id, used inside validation messages. */
function staff_display_name(int $staffId): string
{
    try {
        $stmt = db()->prepare(
            "SELECT CONCAT(first_name, ' ', last_name) AS name FROM staff WHERE id = :id AND salon_id = :sid"
        );
        $stmt->execute(['id' => $staffId, 'sid' => default_salon_id()]);
        $name = $stmt->fetchColumn();

        return $name !== false ? (string)$name : ('Staff #' . $staffId);
    } catch (Throwable $e) {
        return 'Staff #' . $staffId;
    }
}

/**
 * May this staff member hold a booking at all? An inactive member keeps
 * their roster rows for history, so the roster alone is not enough.
 */
function staff_is_active(int $staffId): bool
{
    try {
        $stmt = db()->prepare("SELECT status FROM staff WHERE id = :id AND salon_id = :sid");
        $stmt->execute(['id' => $staffId, 'sid' => default_salon_id()]);
        $status = $stmt->fetchColumn();

        return $status !== false && (string)$status === 'active';
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Active staff who are rostered on $day and may perform $serviceId.
 * A NULL $serviceId skips the skill filter.
 *
 * @return array<int,array<string,mixed>>
 */
function staff_working_on(int $day, ?int $serviceId = null): array
{
    $salonId = default_salon_id();

    $stmt = db()->prepare(
        "SELECT s.id, s.first_name, s.last_name,
                CONCAT(s.first_name, ' ', s.last_name) AS name
           FROM staff s
           JOIN staff_working_hours w
             ON w.staff_id = s.id AND w.day_of_week = :day AND w.salon_id = s.salon_id
          WHERE s.status = 'active'
            AND w.is_working = 1
            AND s.salon_id = :sid
          ORDER BY s.first_name, s.last_name"
    );
    $stmt->execute(['day' => $day, 'sid' => $salonId]);
    $rows = $stmt->fetchAll();

    if ($serviceId === null || $serviceId <= 0) {
        return $rows;
    }

    return array_values(array_filter($rows, static fn($r) => staff_can_perform((int)$r['id'], $serviceId)));
}

/* ==================================================================
 * 9. THE ENGINE
 * ================================================================== */

/**
 * Is the salon trading on $date, and for how long?
 *
 * @return array{is_open:bool, day_of_week:int, day_name:string,
 *               opening_time:?string, closing_time:?string,
 *               open_min:int, close_min:int, reason:?string}
 */
function scheduling_day_info(string $date): array
{
    /* A hand-made request can carry a date that matches Y-m-d but never
       happened — 2026-13-45. strtotime() silently rolls such a date into
       another month, so the weekday lookup would happily invent a
       schedule for it. */
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return [
            'is_open'      => false,
            'day_of_week'  => 0,
            'day_name'     => 'That date',
            'opening_time' => null,
            'closing_time' => null,
            'open_min'     => 0,
            'close_min'    => 0,
            'reason'       => 'Please select a valid date.',
        ];
    }

    $day   = scheduling_day_index($date);
    $hours = business_hours_for($day);

    $info = [
        'is_open'      => (bool)$hours['is_open'],
        'day_of_week'  => $day,
        'day_name'     => $hours['day_name'],
        'opening_time' => $hours['opening_time'],
        'closing_time' => $hours['closing_time'],
        'open_min'     => $hours['is_open'] ? scheduling_minutes($hours['opening_time']) : 0,
        'close_min'    => $hours['is_open'] ? scheduling_minutes($hours['closing_time']) : 0,
        'reason'       => null,
    ];

    if (!$hours['is_open']) {
        $info['reason'] = sprintf('%s is a closed day — the salon is not open.', $hours['day_name']);
        return $info;
    }

    if ($hours['opening_time'] === null || $hours['closing_time'] === null) {
        $info['is_open'] = false;
        $info['reason']  = sprintf('%s has no opening hours configured yet.', $hours['day_name']);
        return $info;
    }

    if (is_holiday($date)) {
        $info['is_open'] = false;
        $info['reason']  = 'The salon is closed for a holiday on this date.';
    }

    return $info;
}

/**
 * The bookable window for $date, narrowed to $staffId when given, with
 * salon and staff breaks already subtracted.
 *
 * @return array{ok:bool, reason:?string, open_min:int, close_min:int,
 *               free:array<int,array{0:int,1:int}>}
 */
function scheduling_window(string $date, ?int $staffId = null): array
{
    $info = scheduling_day_info($date);

    if (!$info['is_open']) {
        return ['ok' => false, 'reason' => $info['reason'], 'open_min' => 0, 'close_min' => 0, 'free' => []];
    }

    $openMin  = $info['open_min'];
    $closeMin = $info['close_min'];
    $day      = $info['day_of_week'];
    $breaks   = [];

    /* Salon breaks apply to everyone. */
    foreach (business_breaks_for($day) as $break) {
        $breaks[] = [scheduling_minutes($break['start_time']), scheduling_minutes($break['end_time'])];
    }

    if ($staffId !== null && $staffId > 0) {
        if (!staff_is_active($staffId)) {
            return [
                'ok'        => false,
                'reason'    => sprintf('%s is not available for bookings.', staff_display_name($staffId)),
                'open_min'  => 0,
                'close_min' => 0,
                'free'      => [],
            ];
        }

        $shift = staff_working_hours_for($staffId, $day);

        if (!$shift['is_working']) {
            return [
                'ok'        => false,
                'reason'    => sprintf('%s is not working on %s.', staff_display_name($staffId), $info['day_name']),
                'open_min'  => 0,
                'close_min' => 0,
                'free'      => [],
            ];
        }

        $staffStart = scheduling_minutes($shift['start_time']);
        $staffEnd   = scheduling_minutes($shift['end_time']);

        /* A staff shift can never be wider than the salon, but if the
           Administrator narrows the salon hours later this clamps rather
           than breaks — existing appointments are never removed. */
        $openMin  = max($openMin, $staffStart);
        $closeMin = min($closeMin, $staffEnd);

        foreach (staff_breaks_for($staffId, $day) as $break) {
            $breaks[] = [scheduling_minutes($break['start_time']), scheduling_minutes($break['end_time'])];
        }
    }

    $reason = null;

    if ($closeMin <= $openMin) {
        $reason = 'There is no bookable time on this date for the selected staff member.';
        return ['ok' => false, 'reason' => $reason, 'open_min' => 0, 'close_min' => 0, 'free' => []];
    }

    $free = [[$openMin, $closeMin]];

    foreach ($breaks as [$bs, $be]) {
        $free = scheduling_subtract($free, [$bs, $be]);
    }

    return ['ok' => true, 'reason' => null, 'open_min' => $openMin, 'close_min' => $closeMin, 'free' => $free];
}

/** Remove one interval from a list of intervals. */
function scheduling_subtract(array $intervals, array $cut): array
{
    [$cs, $ce] = $cut;
    $out = [];

    foreach ($intervals as [$s, $e]) {
        if ($ce <= $s || $cs >= $e) {
            $out[] = [$s, $e];
            continue;
        }

        if ($cs > $s) {
            $out[] = [$s, $cs];
        }

        if ($ce < $e) {
            $out[] = [$ce, $e];
        }
    }

    return $out;
}

/**
 * Busy intervals for $date.
 *
 * Conflict scope (§8 rules 8 and 9):
 *   - a staff member cannot be in two places at once      -> same staff_id
 *   - a customer cannot be in two places at once          -> same customer_id
 *   - an UNASSIGNED booking has no staff member to scope against, so it
 *     conflicts with any appointment on that day. This is the behaviour
 *     the system had before staff schedules existed, so it is preserved.
 *
 * @return array<int,array{0:int,1:int,2:string}> start, end and why it is busy
 */
function scheduling_busy_intervals(string $date, ?int $staffId, ?int $customerId = null, ?int $excludeId = null): array
{
    $busy = [];

    try {
        $sql = 'SELECT id, staff_id, customer_id, start_time, end_time
                  FROM appointments
                 WHERE salon_id = :sid
                   AND appointment_date = :date
                   AND status NOT IN ("cancelled", "no_show")';

        $params = ['date' => $date, 'sid' => default_salon_id()];

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $assigned = $staffId !== null && $staffId > 0;

    foreach ($rows as $row) {
        $rowStaff = $row['staff_id'] !== null ? (int)$row['staff_id'] : null;

        /* Why this row blocks the new booking — it also drives the wording
           of the rejection message, so the receptionist is told the truth. */
        if ($assigned && $rowStaff !== null && $rowStaff === $staffId) {
            $why = 'staff';
        } elseif ($customerId !== null && $customerId > 0 && (int)$row['customer_id'] === $customerId) {
            $why = 'customer';
        } elseif ($assigned) {
            continue;
        } else {
            $why = 'salon';
        }

        $busy[] = [scheduling_minutes($row['start_time']), scheduling_minutes($row['end_time']), $why];
    }

    return $busy;
}

/**
 * Free intervals inside the working window, after breaks and conflicts.
 *
 * @return array<int,array{0:int,1:int}>
 */
function scheduling_free_intervals(
    string $date,
    ?int $staffId = null,
    int $durationMinutes = 0,
    ?int $excludeId = null,
    ?int $customerId = null
): array {
    $window = scheduling_window($date, $staffId);

    if (!$window['ok']) {
        return [];
    }

    $free = $window['free'];

    foreach (scheduling_busy_intervals($date, $staffId, $customerId, $excludeId) as $busy) {
        $free = scheduling_subtract($free, $busy);
    }

    /* For today's date, never offer a slot that has already passed. */
    if ($date === date('Y-m-d')) {
        $now = (int)date('G') * 60 + (int)date('i');
        $free = array_values(array_filter(
            $free,
            static fn($iv) => $iv[1] > $now
        ));
    }

    return $free;
}

/**
 * Bookable start times for $date.
 *
 * A slot is emitted only when the WHOLE service duration fits inside one
 * free interval — that is rule §11.
 *
 * @return array<int,string> list of 'HH:MM:SS'
 */
/**
 * Merge a list of minute-ranges into one sorted, non-overlapping list.
 * Touching ranges are joined too, so two adjacent free stretches become one.
 *
 * @param array<int,array{0:int,1:int}> $intervals
 * @return array<int,array{0:int,1:int}>
 */
function scheduling_merge(array $intervals): array
{
    $clean = [];

    foreach ($intervals as $iv) {
        if (!isset($iv[0], $iv[1])) {
            continue;
        }

        $s = (int)$iv[0];
        $e = (int)$iv[1];

        if ($e > $s) {
            $clean[] = [$s, $e];
        }
    }

    usort($clean, static fn($a, $b) => $a[0] <=> $b[0]);

    $merged = [];

    foreach ($clean as [$s, $e]) {
        $last = count($merged) - 1;

        if ($last >= 0 && $s <= $merged[$last][1]) {
            $merged[$last][1] = max($merged[$last][1], $e);
            continue;
        }

        $merged[] = [$s, $e];
    }

    return $merged;
}

/**
 * Turn free minute-ranges into bookable start times.
 *
 * A slot is emitted only when the WHOLE service duration fits inside one
 * free interval — that is rule §11.
 *
 * @param array<int,array{0:int,1:int}> $free
 * @return array<int,string> list of 'HH:MM:SS'
 */
function scheduling_slots_from_free(array $free, int $durationMinutes, int $step): array
{
    $slots = [];

    foreach (scheduling_merge($free) as [$s, $e]) {
        $first = (int)(ceil($s / $step) * $step);

        for ($t = $first; $t < $e; $t += $step) {
            if ($durationMinutes > 0 && ($t + $durationMinutes) > $e) {
                break;
            }

            if ($durationMinutes <= 0 && $t >= $e) {
                break;
            }

            $slots[] = scheduling_clock($t);
        }
    }

    return array_values(array_unique($slots));
}

/**
 * Bookable start times for $date.
 *
 * @return array<int,string> list of 'HH:MM:SS'
 */
function scheduling_slots(
    string $date,
    ?int $staffId = null,
    int $durationMinutes = 0,
    ?int $excludeId = null,
    ?int $customerId = null
): array {
    $step = max(5, (int)get_setting('slot_interval_minutes', 15));

    $free = scheduling_free_intervals($date, $staffId, $durationMinutes, $excludeId, $customerId);

    $slots = scheduling_slots_from_free($free, $durationMinutes, $step);

    /* When editing an appointment its own start time is always offered,
       otherwise the Receptionist could not keep the current slot after
       narrowing the hours. It is excluded from every conflict check. */
    if ($excludeId !== null && $excludeId > 0) {
        try {
            $stmt = db()->prepare('SELECT start_time FROM appointments WHERE id = :id AND salon_id = :sid');
            $stmt->execute(['id' => $excludeId, 'sid' => default_salon_id()]);
            $current = $stmt->fetchColumn();

            if ($current !== false && !in_array((string)$current, $slots, true)) {
                array_unshift($slots, (string)$current);
                sort($slots);
            }
        } catch (Throwable $e) {
            /* keep going without it */
        }
    }

    return array_values(array_unique($slots));
}

/**
 * The union of every eligible staff member's free time on $date.
 *
 * A stretch counts as free when AT LEAST ONE of them is free for all of it.
 * That is the union, never the intersection — otherwise the unfiltered list
 * would offer FEWER slots than filtering by one person.
 *
 * @param array<int,int> $staffIds eligible staff (already rostered + skilled)
 * @return array<int,array{0:int,1:int}>
 */
function scheduling_free_intervals_for_roster(
    string $date,
    array $staffIds,
    ?int $excludeId = null,
    ?int $customerId = null
): array {
    $free = [];

    foreach (array_unique(array_map('intval', $staffIds)) as $memberId) {
        if ($memberId <= 0) {
            continue;
        }

        foreach (scheduling_free_intervals($date, $memberId, 0, $excludeId, $customerId) as $iv) {
            $free[] = $iv;
        }
    }

    return scheduling_merge($free);
}

/**
 * Bookable start times when NO single staff member is picked yet.
 *
 * @param array<int,int> $staffIds eligible staff (already rostered + skilled)
 * @return array<int,string> list of 'HH:MM:SS'
 */
function scheduling_slots_for_roster(
    string $date,
    array $staffIds,
    int $durationMinutes = 0,
    ?int $excludeId = null,
    ?int $customerId = null
): array {
    $free = scheduling_free_intervals_for_roster($date, $staffIds, $excludeId, $customerId);

    if (!$free) {
        return [];
    }

    $step = max(5, (int)get_setting('slot_interval_minutes', 15));

    $slots = scheduling_slots_from_free($free, $durationMinutes, $step);

    if ($excludeId !== null && $excludeId > 0) {
        try {
            $stmt = db()->prepare('SELECT start_time FROM appointments WHERE id = :id AND salon_id = :sid');
            $stmt->execute(['id' => $excludeId, 'sid' => default_salon_id()]);
            $current = $stmt->fetchColumn();

            if ($current !== false && !in_array((string)$current, $slots, true)) {
                array_unshift($slots, (string)$current);
                sort($slots);
            }
        } catch (Throwable $e) {
            /* keep going without it */
        }
    }

    return array_values(array_unique($slots));
}

/**
 * THE server-side gate. Every appointment and walk-in write calls this
 * immediately before saving. Returns NULL when the booking is legal, or
 * a human-readable reason when it is not.
 *
 * @param array<string,mixed> $args
 *   date              string  Y-m-d                              (required)
 *   start             string  'HH:MM' or 'HH:MM:SS'              (required)
 *   duration_minutes  int     combined service duration          (required)
 *   staff_id          int     0 / null = unassigned              (optional)
 *   customer_id       int     used for the customer conflict rule (optional)
 *   service_ids       array   used for the skill rule            (optional)
 *   exclude_id        int     appointment being edited           (optional)
 *
 * @return string|null
 */
function scheduling_validate(array $args): ?string
{
    $date     = (string)($args['date'] ?? '');
    $duration = (int)($args['duration_minutes'] ?? 0);
    $staffId  = isset($args['staff_id']) ? (int)$args['staff_id'] : 0;
    $customerId = isset($args['customer_id']) ? (int)$args['customer_id'] : 0;
    $excludeId  = isset($args['exclude_id']) ? (int)$args['exclude_id'] : 0;
    $serviceIds = array_values(array_filter(array_map('intval', (array)($args['service_ids'] ?? []))));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 'Please select a valid date.';
    }

    $start = scheduling_normalize_time($args['start'] ?? '');

    if ($start === null) {
        return 'Please select a valid start time.';
    }

    if ($duration <= 0) {
        return 'The total service duration must be greater than zero.';
    }

    $startMin = scheduling_minutes($start);
    $endMin   = $startMin + $duration;

    if ($endMin > 24 * 60) {
        return 'This appointment would run past midnight. Please choose an earlier time.';
    }

    $end = scheduling_clock($endMin);

    /* 1 + 10 — the salon must be trading that weekday, and the whole
       appointment must sit inside the operating period. */
    $info = scheduling_day_info($date);

    if (!$info['is_open']) {
        return $info['reason'] ?? 'The salon is closed on the selected date.';
    }

    if ($startMin < $info['open_min']) {
        return sprintf(
            'The salon opens at %s on %s, so the appointment cannot start at %s.',
            scheduling_human_time($info['opening_time']),
            $info['day_name'],
            scheduling_human_time($start)
        );
    }

    if ($endMin > $info['close_min']) {
        return sprintf(
            'This appointment ends at %s but %s closes at %s on %s. Please choose an earlier time.',
            scheduling_human_time($end),
            salon_name(),
            scheduling_human_time($info['closing_time']),
            $info['day_name']
        );
    }

    /* 4 + 5 + 6 — the staff member must be rostered and the whole
       appointment must sit inside their shift. */
    if ($staffId > 0) {
        try {
            $stmt = db()->prepare("SELECT status FROM staff WHERE id = :id AND salon_id = :sid");
            $stmt->execute(['id' => $staffId, 'sid' => default_salon_id()]);
            $staffRow = $stmt->fetch();

            if (!$staffRow) {
                return 'The selected staff member no longer exists.';
            }
        } catch (Throwable $e) {
            return 'The selected staff member could not be verified.';
        }

        /* The picker only ever lists active staff, but a stale form or a
           hand-made request must not get past the gate. */
        if ((string)($staffRow['status'] ?? '') !== 'active') {
            return sprintf(
                '%s is no longer active, so they cannot take appointments. Please choose another staff member.',
                staff_display_name($staffId)
            );
        }

        $shift = staff_working_hours_for($staffId, $info['day_of_week']);

        if (!$shift['is_working']) {
            return sprintf(
                '%s is not working on %s. Please choose another staff member or day.',
                staff_display_name($staffId),
                $info['day_name']
            );
        }

        $shiftStart = scheduling_minutes($shift['start_time']);
        $shiftEnd   = scheduling_minutes($shift['end_time']);

        if ($startMin < $shiftStart) {
            return sprintf(
                '%s works from %s on %s, so the appointment cannot start at %s.',
                staff_display_name($staffId),
                scheduling_human_time($shift['start_time']),
                $info['day_name'],
                scheduling_human_time($start)
            );
        }

        if ($endMin > $shiftEnd) {
            return sprintf(
                'This appointment ends at %s but %s works until %s on %s. Please choose an earlier time.',
                scheduling_human_time($end),
                staff_display_name($staffId),
                scheduling_human_time($shift['end_time']),
                $info['day_name']
            );
        }

        /* 7 + step 7 of the booking flow — skill match. */
        foreach ($serviceIds as $serviceId) {
            if (!staff_can_perform($staffId, $serviceId)) {
                return sprintf(
                    '%s does not perform the selected service. Please choose another staff member.',
                    staff_display_name($staffId)
                );
            }
        }
    }

    /* 11 — breaks are never bookable. */
    foreach (business_breaks_for($info['day_of_week']) as $break) {
        $bs = scheduling_minutes($break['start_time']);
        $be = scheduling_minutes($break['end_time']);

        if ($startMin < $be && $endMin > $bs) {
            return sprintf(
                'The salon is on a break from %s to %s on %s. Please choose another time.',
                scheduling_human_time($break['start_time']),
                scheduling_human_time($break['end_time']),
                $info['day_name']
            );
        }
    }

    if ($staffId > 0) {
        foreach (staff_breaks_for($staffId, $info['day_of_week']) as $break) {
            $bs = scheduling_minutes($break['start_time']);
            $be = scheduling_minutes($break['end_time']);

            if ($startMin < $be && $endMin > $bs) {
                return sprintf(
                    '%s is on a break from %s to %s on %s. Please choose another time.',
                    staff_display_name($staffId),
                    scheduling_human_time($break['start_time']),
                    scheduling_human_time($break['end_time']),
                    $info['day_name']
                );
            }
        }
    }

    /* 8 + 9 — conflicts. */
    foreach (scheduling_busy_intervals($date, $staffId, $customerId ?: null, $excludeId ?: null) as $busy) {
        if ($startMin < $busy[1] && $endMin > $busy[0]) {
            if (($busy[2] ?? '') === 'staff') {
                return sprintf(
                    'This time conflicts with an existing appointment for %s. Please choose another time.',
                    staff_display_name($staffId)
                );
            }

            if (($busy[2] ?? '') === 'customer') {
                return 'This customer already has an appointment that overlaps this time.';
            }

            return 'That time slot is already taken. Please choose another time.';
        }
    }

    return null;
}

/**
 * Is the whole appointment legal? Convenience wrapper around
 * scheduling_validate() for callers holding start + end instead of a
 * duration.
 *
 * @return string|null NULL when available
 */
function scheduling_check_range(string $date, string $start, string $end, ?int $staffId = null, ?int $customerId = null, ?int $excludeId = null): ?string
{
    $startNorm = scheduling_normalize_time($start);
    $endNorm   = scheduling_normalize_time($end);

    if ($startNorm === null || $endNorm === null) {
        return 'Please select a valid time.';
    }

    $duration = scheduling_minutes($endNorm) - scheduling_minutes($startNorm);

    if ($duration <= 0) {
        return 'The appointment must end after it starts.';
    }

    return scheduling_validate([
        'date'             => $date,
        'start'            => $startNorm,
        'duration_minutes' => $duration,
        'staff_id'         => (int)$staffId,
        'customer_id'      => (int)$customerId,
        'exclude_id'       => (int)$excludeId,
    ]);
}

/**
 * Is this date bookable at all? Used by the calendar and the public
 * booking page to explain *why* a day is unavailable.
 *
 * @return array{bookable:bool, reason:?string, opening_time:?string, closing_time:?string, day_of_week:int, day_name:string}
 */
function scheduling_date_status(string $date): array
{
    $info    = scheduling_day_info($date);
    $holiday = false;

    try {
        $holiday = is_holiday($date);
    } catch (Throwable $e) {
        /* holidays table unavailable */
    }

    $breaks = [];

    foreach (business_breaks_for($info['day_of_week']) as $break) {
        $breaks[] = [
            'start_time' => scheduling_human_time($break['start_time']),
            'end_time'   => scheduling_human_time($break['end_time']),
        ];
    }

    return [
        'bookable'     => $info['is_open'] && !$holiday,
        'reason'       => $info['reason'],
        'holiday'      => $holiday,
        'opening_time' => $info['opening_time'],
        'closing_time' => $info['closing_time'],
        'day_of_week'  => $info['day_of_week'],
        'day_name'     => $info['day_name'],
        'breaks'       => $breaks,
    ];
}

/* ==================================================================
 * 10. Shared weekly-hours grid renderer
 *
 * One markup for the first-time setup wizard AND for
 * Settings -> Business Hours, so the two can never drift apart.
 * ================================================================== */

/**
 * @param array<int,array<string,mixed>> $hours  keyed 1..7 (business_hours_all())
 * @param array<int,array<int,array<string,mixed>>> $breaks keyed 1..7
 * @param string $prefix input name prefix, e.g. 'hours' or 'setup_hours'
 */
function render_weekly_hours_grid(array $hours, array $breaks = [], string $prefix = 'hours'): void
{
    ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0 hours-grid">
            <thead>
                <tr>
                    <th style="width:130px;">Day</th>
                    <th style="width:150px;">Status</th>
                    <th>Opening Time</th>
                    <th>Closing Time</th>
                    <th style="width:190px;">Breaks</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (scheduling_days() as $day => $label):
                $row    = $hours[$day] ?? [];
                $isOpen = !empty($row['is_open']);
                $dayBreaks = $breaks[$day] ?? [];
                ?>
                <tr data-day-row="<?php echo $day; ?>">
                    <td class="fw-semibold"><?php echo e($label); ?></td>
                    <td>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input day-toggle" type="checkbox"
                                   role="switch" name="<?php echo e($prefix); ?>[<?php echo $day; ?>][is_open]"
                                   value="1" id="<?php echo e($prefix); ?>Day<?php echo $day; ?>"
                                   <?php echo $isOpen ? 'checked' : ''; ?>
                                   data-row="<?php echo e($prefix); ?>Row<?php echo $day; ?>">
                            <label class="form-check-label small" for="<?php echo e($prefix); ?>Day<?php echo $day; ?>">
                                <span class="day-status-text"><?php echo $isOpen ? 'Open' : 'Closed'; ?></span>
                            </label>
                        </div>
                    </td>
                    <td>
                        <input type="time" step="900"
                               class="form-control form-control-sm day-time"
                               name="<?php echo e($prefix); ?>[<?php echo $day; ?>][opening_time]"
                               id="<?php echo e($prefix); ?>Open<?php echo $day; ?>"
                               value="<?php echo e($row['opening_time'] ? substr((string)$row['opening_time'], 0, 5) : ''); ?>"
                               <?php echo $isOpen ? '' : 'disabled'; ?>
                               placeholder="08:00">
                    </td>
                    <td>
                        <input type="time" step="900"
                               class="form-control form-control-sm day-time"
                               name="<?php echo e($prefix); ?>[<?php echo $day; ?>][closing_time]"
                               id="<?php echo e($prefix); ?>Close<?php echo $day; ?>"
                               value="<?php echo e($row['closing_time'] ? substr((string)$row['closing_time'], 0, 5) : ''); ?>"
                               <?php echo $isOpen ? '' : 'disabled'; ?>
                               placeholder="18:00">
                    </td>
                    <td>
                        <?php if (!$isOpen): ?>
                            <span class="small text-muted">—</span>
                        <?php else: ?>
                            <div class="break-list" data-break-list="<?php echo $day; ?>">
                                <?php foreach ($dayBreaks as $break): ?>
                                    <div class="d-flex gap-1 align-items-center mb-1">
                                        <input type="time" step="900" class="form-control form-control-sm break-time"
                                               name="<?php echo e($prefix); ?>_breaks[<?php echo $day; ?>][][start_time]"
                                               value="<?php echo e(substr((string)$break['start_time'], 0, 5)); ?>" aria-label="Break start">
                                        <span class="small text-muted">to</span>
                                        <input type="time" step="900" class="form-control form-control-sm break-time"
                                               name="<?php echo e($prefix); ?>_breaks[<?php echo $day; ?>][][end_time]"
                                               value="<?php echo e(substr((string)$break['end_time'], 0, 5)); ?>" aria-label="Break end">
                                        <button type="button" class="btn btn-sm btn-light text-danger break-remove" aria-label="Remove break">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-soft rounded-pill break-add" data-day="<?php echo $day; ?>">
                                <i class="bi bi-plus-lg me-1"></i>Break
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <template id="<?php echo e($prefix); ?>BreakRowTemplate">
        <div class="d-flex gap-1 align-items-center mb-1">
            <input type="time" step="900" class="form-control form-control-sm break-time" aria-label="Break start">
            <span class="small text-muted">to</span>
            <input type="time" step="900" class="form-control form-control-sm break-time" aria-label="Break end">
            <button type="button" class="btn btn-sm btn-light text-danger break-remove" aria-label="Remove break">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </template>
    <script>
    (function () {
        'use strict';
        var root = document.currentScript.closest('.hours-grid-host') || document;
        var prefix = <?php echo json_encode($prefix); ?>;

        function paintRow(row) {
            var toggle = row.querySelector('.day-toggle');
            var label  = row.querySelector('.day-status-text');
            var times  = row.querySelectorAll('.day-time');
            var open   = toggle && toggle.checked;

            if (label) { label.textContent = open ? 'Open' : 'Closed'; }
            times.forEach(function (t) {
                t.disabled = !open;
                if (!open) { t.value = ''; }
            });
            row.classList.toggle('day-closed', !open);

            var breakCell = row.querySelector('[data-break-list]');
            var addBtn    = row.querySelector('.break-add');
            if (breakCell) { breakCell.classList.toggle('opacity-50', !open); }
            if (addBtn)    { addBtn.disabled = !open; }
        }

        root.querySelectorAll('[data-day-row]').forEach(paintRow);

        root.addEventListener('change', function (ev) {
            if (ev.target.classList.contains('day-toggle')) {
                paintRow(ev.target.closest('[data-day-row]'));
            }
        });

        var template = document.getElementById(prefix + 'BreakRowTemplate');

        root.addEventListener('click', function (ev) {
            var addBtn = ev.target.closest('.break-add');
            if (addBtn && template) {
                var list = addBtn.parentElement.querySelector('[data-break-list]');
                var day  = addBtn.dataset.day;
                var holder = document.createElement('div');
                holder.innerHTML = template.innerHTML;
                var inputs = holder.querySelectorAll('.break-time');
                if (inputs.length >= 2) {
                    inputs[0].setAttribute('name', prefix + '_breaks[' + day + '][][start_time]');
                    inputs[1].setAttribute('name', prefix + '_breaks[' + day + '][][end_time]');
                }
                list.appendChild(holder.firstElementChild);
                return;
            }

            var removeBtn = ev.target.closest('.break-remove');
            if (removeBtn) {
                removeBtn.closest('.d-flex').remove();
            }
        });
    })();
    </script>
    <?php
}

/**
 * The same grid, rendered for one staff member. Staff shifts must sit
 * inside the salon hours, so the salon window is shown next to each row.
 *
 * @param int $staffId
 * @param string $prefix
 */
function render_staff_hours_grid(int $staffId, string $prefix = 'staff_hours'): void
{
    $week    = staff_working_hours($staffId);
    $salon   = business_hours_all();
    $breaks  = staff_breaks_all($staffId);
    ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0 hours-grid staff-hours-grid">
            <thead>
                <tr>
                    <th style="width:130px;">Day</th>
                    <th style="width:150px;">Status</th>
                    <th>Salon Hours</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th style="width:180px;">Breaks</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (scheduling_days() as $day => $label):
                $row      = $week[$day] ?? [];
                $salonRow = $salon[$day] ?? [];
                $isWorking = !empty($row['is_working']);
                $salonOpen = !empty($salonRow['is_open']);
                $dayBreaks = $breaks[$day] ?? [];
                ?>
                <tr data-day-row="<?php echo $day; ?>" class="<?php echo $salonOpen ? '' : 'day-closed'; ?>">
                    <td class="fw-semibold">
                        <?php echo e($label); ?>
                        <?php if (!$salonOpen): ?>
                            <div class="small text-muted">Salon closed</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input day-toggle" type="checkbox" role="switch"
                                   name="<?php echo e($prefix); ?>[<?php echo $day; ?>][is_working]"
                                   value="1" id="<?php echo e($prefix); ?>Day<?php echo $day; ?>"
                                   <?php echo $isWorking ? 'checked' : ''; ?>
                                   <?php echo $salonOpen ? '' : 'disabled'; ?>
                                   data-row="<?php echo e($prefix); ?>Row<?php echo $day; ?>">
                            <label class="form-check-label small" for="<?php echo e($prefix); ?>Day<?php echo $day; ?>">
                                <span class="day-status-text"><?php echo $isWorking ? 'Working' : 'Not working'; ?></span>
                            </label>
                        </div>
                    </td>
                    <td class="small text-muted">
                        <?php echo $salonOpen
                            ? e(scheduling_human_time($salonRow['opening_time']) . ' – ' . scheduling_human_time($salonRow['closing_time']))
                            : 'Closed'; ?>
                    </td>
                    <td>
                        <input type="time" step="900" class="form-control form-control-sm day-time"
                               name="<?php echo e($prefix); ?>[<?php echo $day; ?>][start_time]"
                               value="<?php echo e($row['start_time'] ? substr((string)$row['start_time'], 0, 5) : ''); ?>"
                               <?php echo $isWorking && $salonOpen ? '' : 'disabled'; ?>>
                    </td>
                    <td>
                        <input type="time" step="900" class="form-control form-control-sm day-time"
                               name="<?php echo e($prefix); ?>[<?php echo $day; ?>][end_time]"
                               value="<?php echo e($row['end_time'] ? substr((string)$row['end_time'], 0, 5) : ''); ?>"
                               <?php echo $isWorking && $salonOpen ? '' : 'disabled'; ?>>
                    </td>
                    <td>
                        <?php if (!$isWorking || !$salonOpen): ?>
                            <span class="small text-muted">—</span>
                        <?php else: ?>
                            <div class="break-list" data-break-list="<?php echo $day; ?>">
                                <?php foreach ($dayBreaks as $break): ?>
                                    <div class="d-flex gap-1 align-items-center mb-1">
                                        <input type="time" step="900" class="form-control form-control-sm break-time"
                                               name="<?php echo e($prefix); ?>_breaks[<?php echo $day; ?>][][start_time]"
                                               value="<?php echo e(substr((string)$break['start_time'], 0, 5)); ?>" aria-label="Break start">
                                        <span class="small text-muted">to</span>
                                        <input type="time" step="900" class="form-control form-control-sm break-time"
                                               name="<?php echo e($prefix); ?>_breaks[<?php echo $day; ?>][][end_time]"
                                               value="<?php echo e(substr((string)$break['end_time'], 0, 5)); ?>" aria-label="Break end">
                                        <button type="button" class="btn btn-sm btn-light text-danger break-remove" aria-label="Remove break">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-soft rounded-pill break-add" data-day="<?php echo $day; ?>">
                                <i class="bi bi-plus-lg me-1"></i>Break
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <template id="<?php echo e($prefix); ?>BreakRowTemplate">
        <div class="d-flex gap-1 align-items-center mb-1">
            <input type="time" step="900" class="form-control form-control-sm break-time" aria-label="Break start">
            <span class="small text-muted">to</span>
            <input type="time" step="900" class="form-control form-control-sm break-time" aria-label="Break end">
            <button type="button" class="btn btn-sm btn-light text-danger break-remove" aria-label="Remove break">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </template>
    <script>
    (function () {
        'use strict';
        document.querySelectorAll('.staff-hours-grid').forEach(function (grid) {
            if (grid.dataset.hoursBound) { return; }
            grid.dataset.hoursBound = '1';

            var prefix   = <?php echo json_encode($prefix); ?>;
            var template = document.getElementById(prefix + 'BreakRowTemplate');

            function paintRow(row) {
                var toggle = row.querySelector('.day-toggle');
                var label  = row.querySelector('.day-status-text');
                var times  = row.querySelectorAll('.day-time');
                var on     = toggle && toggle.checked;

                if (label) { label.textContent = on ? 'Working' : 'Not working'; }
                times.forEach(function (t) { t.disabled = !on; if (!on) { t.value = ''; } });
                row.classList.toggle('day-closed', !on);

                var addBtn = row.querySelector('.break-add');
                if (addBtn) { addBtn.disabled = !on; }
            }

            grid.querySelectorAll('[data-day-row]').forEach(paintRow);
            grid.addEventListener('change', function (ev) {
                if (ev.target.classList.contains('day-toggle')) {
                    paintRow(ev.target.closest('[data-day-row]'));
                }
            });

            grid.addEventListener('click', function (ev) {
                var addBtn = ev.target.closest('.break-add');
                if (addBtn && template) {
                    var list = addBtn.parentElement.querySelector('[data-break-list]');
                    var day  = addBtn.dataset.day;
                    var holder = document.createElement('div');
                    holder.innerHTML = template.innerHTML;
                    var inputs = holder.querySelectorAll('.break-time');
                    if (inputs.length >= 2) {
                        inputs[0].setAttribute('name', prefix + '_breaks[' + day + '][][start_time]');
                        inputs[1].setAttribute('name', prefix + '_breaks[' + day + '][][end_time]');
                    }
                    list.appendChild(holder.firstElementChild);
                    return;
                }
                var removeBtn = ev.target.closest('.break-remove');
                if (removeBtn) { removeBtn.closest('.d-flex').remove(); }
            });
        });
    })();
    </script>
    <?php
}
