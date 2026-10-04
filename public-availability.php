<?php
/**
 * Beauty-php-ai — Public availability endpoint (used by the public booking page).
 * Returns available time slots (JSON) for the given date. No login required.
 */

require_once __DIR__ . '/includes/init.php';

header('Content-Type: application/json');

$date = $_GET['date'] ?? '';

try {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('Invalid date format.');
    }

    $info = scheduling_day_info($date);
    
    if (!$info['is_open'] && $info['reason'] === 'Please select a valid date.') {
        echo json_encode(['ok' => false, 'error' => $info['reason']]);
        exit;
    }

    $slots = available_slots($date);

    echo json_encode([
        'ok'      => true,
        'date'    => $date,
        'slots'   => $slots,
        'open'    => $info['is_open'] ? $info['opening_time'] : null,
        'close'   => $info['is_open'] ? $info['closing_time'] : null,
        'holiday' => is_holiday($date),
        'reason'  => $info['reason'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Something went wrong.']);
}
exit;
