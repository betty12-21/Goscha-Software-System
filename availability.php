<?php
/**
 * Beauty-php-ai — AJAX availability endpoint.
 *
 * Returns, for one date: the staff who are rostered and may perform the
 * requested services, and the bookable start times for the chosen staff
 * member (or for the salon as a whole when none is chosen) once the whole
 * service duration, breaks and existing appointments are taken into account.
 */

require_once __DIR__ . '/includes/init.php';
require_login();

header('Content-Type: application/json');

$date       = (string)($_GET['date'] ?? '');
$exclude    = isset($_GET['exclude']) && ctype_digit($_GET['exclude']) ? (int)$_GET['exclude'] : null;
$staffId    = isset($_GET['staff_id']) && ctype_digit($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
$customerId = isset($_GET['customer_id']) && ctype_digit($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$duration   = isset($_GET['duration']) && ctype_digit($_GET['duration']) ? (int)$_GET['duration'] : 0;
$serviceIds = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['services'] ?? '')))));

try {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('Invalid date format.');
    }

    $info = scheduling_day_info($date);

    if (!$info['is_open']) {
        echo json_encode([
            'ok'      => true,
            'date'    => $date,
            'slots'   => [],
            'staff'   => [],
            'open'    => null,
            'close'   => null,
            'holiday' => is_holiday($date),
            'reason'  => $info['reason'],
        ]);
        exit;
    }

    /* Rostered that day AND able to perform every selected service. */
    $staff = [];

    foreach (staff_working_on($info['day_of_week']) as $member) {
        $memberId = (int)$member['id'];

        foreach ($serviceIds as $serviceId) {
            if (!staff_can_perform($memberId, $serviceId)) {
                continue 2;
            }
        }

        $shift   = staff_working_hours_for($memberId, $info['day_of_week']);
        $staff[] = [
            'id'    => $memberId,
            'name'  => (string)$member['name'],
            'start' => (string)$shift['start_time'],
            'end'   => (string)$shift['end_time'],
        ];
    }

    $rosterIds = array_map(static fn($member) => (int)$member['id'], $staff);

    if ($staffId > 0) {
        $slots = scheduling_slots(
            $date,
            $staffId,
            $duration,
            $exclude,
            $customerId > 0 ? $customerId : null
        );
    } else {
        /* Nobody picked yet: offer every time at least ONE eligible staff
           member is free. Passing null here would make each booking block
           the whole salon, which offers fewer slots than picking one person. */
        $slots = scheduling_slots_for_roster(
            $date,
            $rosterIds,
            $duration,
            $exclude,
            $customerId > 0 ? $customerId : null
        );
    }

    /* An empty list is only useful if the receptionist learns why. */
    $reason = null;

    if (!$slots) {
        if ($staffId > 0) {
            $window = scheduling_window($date, $staffId);
        } else {
            $window = $staff
                ? ['ok' => true, 'reason' => null]
                : scheduling_window($date, null);
        }

        if (!$window['ok']) {
            $reason = $window['reason'];
        } else {
            /* The free intervals, not the raw window: existing appointments
               and today's already-passed hours have to count too, or the
               message would promise a stretch that is in fact booked.
               Measure the very same set that produced the empty slot list. */
            $free = $staffId > 0
                ? scheduling_free_intervals(
                    $date,
                    $staffId,
                    0,
                    $exclude,
                    $customerId > 0 ? $customerId : null
                )
                : scheduling_free_intervals_for_roster(
                    $date,
                    $rosterIds,
                    $exclude,
                    $customerId > 0 ? $customerId : null
                );

            $longest = 0;

            foreach ($free as [$gapStart, $gapEnd]) {
                $longest = max($longest, $gapEnd - $gapStart);
            }

            $reason = $duration > $longest
                ? sprintf(
                    'A %d-minute service does not fit in any gap left on this date — the longest is %s.',
                    $duration,
                    minutes_to_duration($longest)
                )
                : 'Every start time on this date is already taken by an appointment.';
        }
    }

    echo json_encode([
        'ok'      => true,
        'date'    => $date,
        'slots'   => $slots,
        'staff'   => $staff,
        'open'    => $info['opening_time'],
        'close'   => $info['closing_time'],
        'holiday' => false,
        'reason'  => $reason,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Something went wrong.']);
}
exit;
