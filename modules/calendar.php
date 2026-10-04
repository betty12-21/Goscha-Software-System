<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Calendar module (day / week / month).
 */

require_permission('calendar');

$view = $_GET['view'] ?? 'month';
if (!in_array($view, ['day', 'week', 'month'], true)) {
    $view = 'month';
}

$current = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $current)) {
    $current = date('Y-m-d');
}

$ts = strtotime($current);

/* Navigation dates */
switch ($view) {
    case 'day':
        $prev = date('Y-m-d', strtotime($current . ' -1 day'));
        $next = date('Y-m-d', strtotime($current . ' +1 day'));
        break;
    case 'week':
        $weekStart = date('Y-m-d', strtotime('last sunday', strtotime($current . ' +1 day')));
        $prev = date('Y-m-d', strtotime($weekStart . ' -7 days'));
        $next = date('Y-m-d', strtotime($weekStart . ' +7 days'));
        $current = $weekStart;
        break;
    default:
        $prev = date('Y-m-d', strtotime($current . ' -1 month'));
        $next = date('Y-m-d', strtotime($current . ' +1 month'));
        break;
}

$weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/* A staff calendar shows only that person's appointments and marks the days
   they are not rostered, so it can never look bookable when it is not (§12). */
$staffFilter = isset($_GET['staff_id']) && ctype_digit((string)$_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
$staffNames  = [];

try {
    $staffNames = db()->query(
        "SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM staff WHERE status = 'active' ORDER BY first_name, last_name"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {
    $staffNames = [];
}

if ($staffFilter > 0 && !isset($staffNames[$staffFilter])) {
    $staffFilter = 0;
}

/* Load appointments for the visible range */
$appointments = [];
try {
    if ($view === 'month') {
        $from = date('Y-m-01', $ts);
        $to   = date('Y-m-t', $ts);
    } elseif ($view === 'week') {
        $from = $current;
        $to   = date('Y-m-d', strtotime($current . ' +6 days'));
    } else {
        $from = $current;
        $to   = $current;
    }

    $stmt = db()->prepare(
        'SELECT a.*, CONCAT(c.first_name, " ", c.last_name) AS customer_name
         FROM appointments a JOIN customers c ON c.id = a.customer_id
         WHERE a.appointment_date BETWEEN :from AND :to
           AND (:staff_a = 0 OR a.staff_id = :staff_b)
         ORDER BY a.appointment_date, a.start_time'
    );
    $stmt->execute(['from' => $from, 'to' => $to, 'staff_a' => $staffFilter, 'staff_b' => $staffFilter]);
    foreach ($stmt->fetchAll() as $row) {
        $appointments[$row['appointment_date']][] = $row;
    }
} catch (Throwable $e) {
}

/**
 * Trading status of one calendar date, memoised for this request.
 *
 * @return array{open:bool, reason:?string, opening_time:?string, closing_time:?string,
 *               breaks:array<int,string>, rostered:bool, shift:?array{0:string,1:string}}
 */
function calendar_day_status(string $date, int $staffId = 0): array
{
    static $cache = [];

    $key = $date . '|' . $staffId;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $info   = scheduling_day_info($date);
    $status = [
        'open'         => (bool)$info['is_open'],
        'reason'       => $info['reason'],
        'opening_time' => $info['is_open'] ? $info['opening_time'] : null,
        'closing_time' => $info['is_open'] ? $info['closing_time'] : null,
        'breaks'       => [],
        'rostered'     => true,
        'shift'        => null,
    ];

    foreach (business_breaks_for($info['day_of_week']) as $break) {
        $status['breaks'][] = scheduling_human_time($break['start_time']) . '–' . scheduling_human_time($break['end_time']);
    }

    if ($staffId > 0) {
        $shift = staff_working_hours_for($staffId, $info['day_of_week']);

        $status['rostered'] = (bool)$shift['is_working'];
        $status['shift']    = $shift['is_working']
            ? [scheduling_human_time($shift['start_time']), scheduling_human_time($shift['end_time'])]
            : null;

        if ($status['open'] && !$status['rostered']) {
            $status['reason'] = sprintf('%s does not work on %s.', staff_display_name($staffId), $info['day_name']);
        }
    }

    return $cache[$key] = $status;
}

$page_title = 'Calendar';
$active     = 'calendar';
$staffQs    = $staffFilter > 0 ? '&staff_id=' . $staffFilter : '';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div class="cal-toolbar">
            <a href="calendar.php?view=<?php echo $view; ?>&date=<?php echo $prev . $staffQs; ?>" class="btn btn-light rounded-pill"><i class="bi bi-chevron-left"></i></a>
            <a href="calendar.php?view=<?php echo $view; ?>&date=<?php echo $next . $staffQs; ?>" class="btn btn-light rounded-pill"><i class="bi bi-chevron-right"></i></a>
            <a href="calendar.php<?php echo $staffQs ? '?' . ltrim($staffQs, '&') : ''; ?>" class="btn btn-soft rounded-pill">Today</a>
            <span class="fw-bold ms-1">
                <?php
                if ($view === 'month') {
                    echo date('F Y', $ts);
                } elseif ($view === 'week') {
                    echo date('M j', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));
                } else {
                    echo date('D, M j, Y', strtotime($current));
                }
                ?>
            </span>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if ($staffNames): ?>
                <form method="get" action="calendar.php" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="view" value="<?php echo e($view); ?>">
                    <input type="hidden" name="date" value="<?php echo e($current); ?>">
                    <label class="small text-muted mb-0" for="staff_id">Staff</label>
                    <select class="form-select form-select-sm" id="staff_id" name="staff_id" style="width:auto;" onchange="this.form.submit()">
                        <option value="0">Everyone</option>
                        <?php foreach ($staffNames as $sid => $sname): ?>
                            <option value="<?php echo (int)$sid; ?>" <?php echo $staffFilter === (int)$sid ? 'selected' : ''; ?>><?php echo e($sname); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <div class="btn-group" role="group">
                <a href="calendar.php?view=day&date=<?php echo $current . $staffQs; ?>" class="btn <?php echo $view === 'day' ? 'btn-rose' : 'btn-light'; ?> rounded-pill px-3">Day</a>
                <a href="calendar.php?view=week&date=<?php echo $current . $staffQs; ?>" class="btn <?php echo $view === 'week' ? 'btn-rose' : 'btn-light'; ?> rounded-pill px-3">Week</a>
                <a href="calendar.php?view=month&date=<?php echo $current . $staffQs; ?>" class="btn <?php echo $view === 'month' ? 'btn-rose' : 'btn-light'; ?> rounded-pill px-3">Month</a>
            </div>
            <a href="appointments.php?action=create" class="btn btn-rose rounded-pill"><i class="bi bi-calendar2-plus me-1"></i>New</a>
        </div>
    </div>

    <div class="card-body border-top">
        <?php foreach (['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'] as $st): ?>
            <span class="cal-event est-<?php echo $st; ?>" style="display:inline-block;margin:0 1rem .4rem 0;padding:.2rem .7rem;"><?php echo e(ucwords(str_replace('_', ' ', $st))); ?></span>
        <?php endforeach; ?>
    </div>

    <div class="card-body pt-0">
    <?php if ($view === 'month'): ?>

        <table class="cal-table">
            <thead>
                <tr><?php foreach ($weekDays as $d): ?><th><?php echo $d; ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php
            $monthStart = date('Y-m-01', $ts);
            $monthEnd   = date('Y-m-t', $ts);
            $monthLabel = date('Y-m', $ts);
            $firstDow   = (int)date('w', strtotime($monthStart));
            $daysIn     = (int)date('t', strtotime($monthStart));

            $cells = [];
            for ($i = $firstDow; $i >= 1; $i--) {
                $cells[] = ['dim', date('Y-m-d', strtotime("{$monthStart} -{$i} days"))];
            }
            for ($d = 1; $d <= $daysIn; $d++) {
                $cells[] = ['cur', $monthLabel . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT)];
            }
            $trail = 7 - (count($cells) % 7);
            if ($trail < 7) {
                for ($i = 1; $i <= $trail; $i++) {
                    $cells[] = ['dim', date('Y-m-d', strtotime("{$monthEnd} +{$i} days"))];
                }
            }

            foreach (array_chunk($cells, 7) as $chunk): ?>
                <tr>
                <?php foreach ($chunk as [$type, $dateStr]):
                    $st = calendar_day_status($dateStr, $staffFilter);
                    /* "Closed" = the salon does not trade; "Off" = it trades but
                       the filtered staff member is not rostered. */
                    $blocked = !$st['open'] || !$st['rostered'];
                    ?>
                    <td class="cal-cell <?php echo $type === 'dim' ? 'dim' : ''; ?> <?php echo $dateStr === date('Y-m-d') ? 'today' : ''; ?> <?php echo $blocked ? 'closed' : ''; ?>">
                        <div class="day-num"><?php echo (int)date('j', strtotime($dateStr)); ?></div>
                        <?php if ($blocked): ?>
                            <div class="cal-flag" title="<?php echo e($st['reason']); ?>"><?php echo $st['open'] ? 'Off' : 'Closed'; ?></div>
                        <?php endif; ?>
                        <?php foreach ($appointments[$dateStr] ?? [] as $ev):
                            $cls = 'est-' . str_replace(['-', ' '], '_', $ev['status']);
                            ?>
                            <a href="appointments.php?action=view&id=<?php echo $ev['id']; ?>" class="cal-event <?php echo $cls; ?>" title="<?php echo e($ev['customer_name']); ?> · <?php echo fmt_time($ev['start_time']); ?>">
                                <?php echo fmt_time($ev['start_time']); ?> <?php echo e(mb_substr($ev['customer_name'], 0, 14)); ?>
                            </a>
                        <?php endforeach; ?>
                    </td>
                <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php elseif ($view === 'week'): ?>

        <table class="cal-table">
            <thead>
                <tr>
                <?php for ($i = 0; $i < 7; $i++):
                    $d = date('Y-m-d', strtotime($current . " +{$i} days"));
                    ?>
                    <th>
                        <div><?php echo $weekDays[$i]; ?></div>
                        <div class="fw-bold <?php echo $d === date('Y-m-d') ? 'text-rose' : ''; ?>"><?php echo date('j', strtotime($d)); ?></div>
                    </th>
                <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                <?php for ($i = 0; $i < 7; $i++):
                    $d       = date('Y-m-d', strtotime($current . " +{$i} days"));
                    $st      = calendar_day_status($d, $staffFilter);
                    $blocked = !$st['open'] || !$st['rostered'];
                    ?>
                    <td class="cal-cell <?php echo $d === date('Y-m-d') ? 'today' : ''; ?> <?php echo $blocked ? 'closed' : ''; ?>" style="min-height:320px;">
                        <div class="day-num d-none"><?php echo (int)date('j', strtotime($d)); ?></div>
                        <div class="small text-muted mb-2">
                            <?php if ($st['open']): ?>
                                <?php echo fmt_time($st['opening_time']) . ' – ' . fmt_time($st['closing_time']); ?>
                                <?php if ($st['shift']): ?>
                                    <br><?php echo e($staffNames[$staffFilter] ?? 'Staff'); ?>: <?php echo e($st['shift'][0] . ' – ' . $st['shift'][1]); ?>
                                <?php elseif ($staffFilter > 0): ?>
                                    <span class="cal-flag" title="<?php echo e($st['reason']); ?>">Off</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="cal-flag" title="<?php echo e($st['reason']); ?>">Closed</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($blocked): ?>
                            <div class="text-muted small"><?php echo e($st['reason']); ?></div>
                        <?php elseif (empty($appointments[$d])): ?>
                            <div class="text-muted small">No appointments</div>
                        <?php else: foreach ($appointments[$d] as $ev): ?>
                            <a href="appointments.php?action=view&id=<?php echo $ev['id']; ?>" class="cal-event <?php echo 'est-' . str_replace(['-', ' '], '_', $ev['status']); ?>" title="<?php echo e($ev['customer_name']); ?>">
                                <?php echo fmt_time($ev['start_time']); ?> – <?php echo fmt_time($ev['end_time']); ?><br><?php echo e(mb_substr($ev['customer_name'], 0, 16)); ?>
                            </a>
                        <?php endforeach; endif; ?>
                    </td>
                <?php endfor; ?>
                </tr>
            </tbody>
        </table>

    <?php else:
        $st      = calendar_day_status($current, $staffFilter);
        $blocked = !$st['open'] || !$st['rostered'];
        ?>

        <?php if (!$st['open']): ?>
            <div class="alert alert-warning rounded-3 py-2 small">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo e($st['reason']); ?>
            </div>
        <?php else: ?>
            <div class="d-flex flex-wrap gap-3 small text-muted mb-3">
                <span><i class="bi bi-clock me-1"></i>Salon open <?php echo fmt_time($st['opening_time']) . ' – ' . fmt_time($st['closing_time']); ?></span>
                <?php if ($st['breaks']): ?>
                    <span><i class="bi bi-pause-circle me-1"></i>Breaks: <?php echo e(implode(', ', $st['breaks'])); ?></span>
                <?php endif; ?>
                <?php if ($staffFilter > 0): ?>
                    <span>
                        <i class="bi bi-person me-1"></i><?php echo e($staffNames[$staffFilter] ?? 'Staff'); ?>:
                        <?php echo $st['rostered'] ? e($st['shift'][0] . ' – ' . $st['shift'][1]) : 'not working this day'; ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <table class="cal-table">
            <thead><tr><th style="width:120px;">Time</th><th>Customer</th><th>Services</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php
            $dayAppts = $appointments[$current] ?? [];
            if (!$dayAppts): ?>
                <tr><td colspan="5"><div class="empty-state">
                    <div class="icon"><i class="bi bi-calendar-day"></i></div>
                    <p class="text-muted mb-2"><?php echo $blocked ? 'Nothing can be booked on this day.' : 'No appointments for this day.'; ?></p>
                    <?php if (!$blocked): ?>
                        <a href="appointments.php?action=create" class="btn btn-sm btn-rose rounded-pill">Book an appointment</a>
                    <?php endif; ?>
                </div></td></tr>
            <?php else: foreach ($dayAppts as $ev): ?>
                <tr>
                    <td class="fw-semibold"><?php echo fmt_time($ev['start_time']); ?> – <?php echo fmt_time($ev['end_time']); ?></td>
                    <td>
                        <a href="customers.php?action=view&id=<?php echo $ev['customer_id']; ?>" class="fw-semibold text-body text-decoration-none"><?php echo e($ev['customer_name']); ?></a>
                    </td>
                    <td class="small text-muted">
                        <?php
                        $stmt = db()->prepare('SELECT s.name FROM appointment_services aps JOIN services s ON s.id = aps.service_id WHERE aps.appointment_id = :id');
                        $stmt->execute(['id' => $ev['id']]);
                        echo e(implode(', ', array_column($stmt->fetchAll(), 'name') ?: ['—']));
                        ?>
                    </td>
                    <td><?php echo status_badge($ev['status']); ?></td>
                    <td class="text-end">
                        <a href="appointments.php?action=view&id=<?php echo $ev['id']; ?>" class="btn btn-sm btn-light rounded-pill"><i class="bi bi-eye"></i></a>
                        <a href="appointments.php?action=edit&id=<?php echo $ev['id']; ?>" class="btn btn-sm btn-light rounded-pill" title="Reschedule"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

    <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
