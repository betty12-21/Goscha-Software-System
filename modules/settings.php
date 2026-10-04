<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Settings (Administrator only).
 * Salon info, business hours, holidays, rules, notifications, social links, backup/restore.
 */

require_permission('settings');

$tab = $_GET['view'] ?? 'general';

/* ============================================================
 * Save settings (bulk)
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_settings') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php'); }

    $keys = [
        'salon_name', 'salon_tagline', 'salon_phone', 'salon_email', 'salon_address',
        'currency', 'tax_rate', 'loyalty_rate',
        'appointment_advance_days', 'max_advance_days', 'session_timeout_minutes',
        'notify_email', 'notify_sms',
        'facebook_url', 'instagram_url', 'tiktok_url',
    ];

    /* Each of these has a minimum declared by its input in the form below.
       A zero is not harmless: book.php derives the last bookable date from
       appointment_advance_days, so 0 would silently close public booking,
       and a 0-minute session timeout expires every session at once. */
    $minimums = [
        'tax_rate'                 => 0,
        'loyalty_rate'             => 0,
        'appointment_advance_days' => 1,
        'max_advance_days'         => 1,
        'session_timeout_minutes'  => 5,
    ];

    foreach ($keys as $key) {
        $value = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
        if (in_array($key, ['notify_email', 'notify_sms'], true)) {
            $value = isset($_POST[$key]) ? '1' : '0';
        }
        if (isset($minimums[$key])) {
            $value = max($minimums[$key], (int)$value);
        }
        save_setting($key, $value);
    }

    log_activity('settings', 'settings', 'Updated system settings');
    flash_set('success', 'Settings saved successfully.');
    redirect('settings.php?view=' . urlencode($tab));
}

/* ============================================================
 * Save business hours
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_business_hours') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=hours'); }

    $posted = $_POST['hours'] ?? [];
    $week = [];

    foreach (scheduling_days() as $day => $label) {
        $row = $posted[$day] ?? [];
        $week[$day] = [
            'is_open' => !empty($row['is_open']),
            'opening_time' => !empty($row['is_open']) ? ($row['opening_time'] ?? '') : null,
            'closing_time' => !empty($row['is_open']) ? ($row['closing_time'] ?? '') : null,
        ];
    }

    $result = business_hours_save($week);

    if (!$result['ok']) {
        foreach ($result['errors'] as $err) {
            flash_set('danger', $err);
        }
        redirect('settings.php?view=hours');
    }

    if ($result['conflicts']) {
        $conflictMsg = 'Business hours updated. The following appointments now fall outside the new hours: ';
        $conflictList = [];
        foreach ($result['conflicts'] as $conflict) {
            $conflictList[] = "#{$conflict['id']} on {$conflict['date']} ({$conflict['customer']}) - {$conflict['reason']}";
        }
        flash_set('warning', $conflictMsg . implode('; ', $conflictList));
    } else {
        flash_set('success', 'Business hours updated successfully.');
    }

    $breakErrors = [];
    foreach (scheduling_days() as $day => $label) {
        $breaks = $_POST['hours_breaks'][$day] ?? [];
        if (!is_array($breaks)) { $breaks = []; }
        /* Always called, even when empty: an empty list is what removes the
           day's breaks, so skipping it would silently keep the old ones. */
        $breakResult = business_breaks_save((int)$day, $breaks);
        if (!$breakResult['ok']) {
            $breakErrors = array_merge($breakErrors, $breakResult['errors']);
        }
    }

    if ($breakErrors) {
        foreach ($breakErrors as $err) {
            flash_set('danger', $err);
        }
    }

    redirect('settings.php?view=hours');
}

/* ============================================================
 * Logo upload
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'upload_logo') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php'); }
    if (!empty($_FILES['logo']['name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
            flash_set('danger', 'Logo must be a PNG, JPG, WEBP or SVG image.');
        } else {
            $name = 'logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__ . '/../uploads/' . $name)) {
                save_setting('salon_logo', 'uploads/' . $name);
                flash_set('success', 'Logo updated.');
            } else {
                flash_set('danger', 'Upload failed. Check the uploads folder is writable.');
            }
        }
    }
    redirect('settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'remove_logo') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php'); }
    save_setting('salon_logo', '');
    flash_set('success', 'Logo removed.');
    redirect('settings.php');
}

/* ============================================================
 * Holidays
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_holiday') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=holidays'); }
    $date  = $_POST['holiday_date'] ?? '';
    $title = trim($_POST['title'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $title === '') {
        flash_set('danger', 'A valid date and title are required.');
        redirect('settings.php?view=holidays');
    }
    db()->prepare('INSERT INTO holidays (holiday_date, title) VALUES (:d, :t)')->execute(['d' => $date, 't' => $title]);
    flash_set('success', 'Holiday added.');
    redirect('settings.php?view=holidays');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'delete_holiday') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=holidays'); }
    db()->prepare('DELETE FROM holidays WHERE id = :id')->execute(['id' => (int)($_POST['id'] ?? $_GET['id'] ?? 0)]);
    flash_set('success', 'Holiday removed.');
    redirect('settings.php?view=holidays');
}

/* ============================================================
 * Backup / restore
 * ============================================================ */
if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'backup_db') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=backup'); }

    $dsn = str_replace('charset=utf8mb4', '', DB_DSN);
    try {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        $out  = "-- Beauty-php-ai backup " . date('Y-m-d H:i:s') . "\n";
        $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC);
            $out .= $create['Create Table'] . ";\n\n";
            $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
                $out .= 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $vals) . ");\n";
            }
            $out .= "\n";
        }
        $out .= "SET FOREIGN_KEY_CHECKS=1;\n";

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="beauty_php_ai_backup_' . date('Ymd_His') . '.sql"');
        header('Content-Length: ' . strlen($out));
        echo $out;
        exit;
    } catch (Throwable $e) {
        flash_set('danger', 'Backup failed: ' . e($e->getMessage()));
        redirect('settings.php?view=backup');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'restore_db') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=backup'); }
    if (empty($_FILES['backup_file']['tmp_name']) || empty($_FILES['backup_file']['name'])) {
        flash_set('danger', 'Please choose a .sql file to restore.');
        redirect('settings.php?view=backup');
    }
    $ext = strtolower(pathinfo($_FILES['backup_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'sql') {
        flash_set('danger', 'Only .sql files can be restored.');
        redirect('settings.php?view=backup');
    }
    $sql = file_get_contents($_FILES['backup_file']['tmp_name']);
    $dsn = str_replace('charset=utf8mb4', '', DB_DSN);
    try {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $pdo->exec($sql);
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        /* Reload settings cache */
        foreach (['salon_name', 'salon_tagline', 'salon_logo'] as $k) { unset($_SESSION['settings_cache'][$k]); }
        log_activity('settings', 'settings', 'Restored database from backup');
        flash_set('success', 'Database restored successfully.');
    } catch (Throwable $e) {
        flash_set('danger', 'Restore failed: ' . e($e->getMessage()));
    }
    redirect('settings.php?view=backup');
}

$settings  = get_settings_map();
$holidays  = db()->query('SELECT * FROM holidays ORDER BY holiday_date DESC')->fetchAll();
$days      = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

$page_title = 'Settings';
$active     = 'settings';
include __DIR__ . '/../includes/header.php';
?>

<ul class="nav nav-pills mb-3 gap-1 flex-wrap">
    <?php foreach (['general' => 'General', 'hours' => 'Hours & Rules', 'holidays' => 'Holidays', 'notifications' => 'Notifications', 'backup' => 'Backup'] as $key => $label): ?>
        <li class="nav-item"><a class="nav-link <?php echo $tab === $key ? 'active' : ''; ?>" href="settings.php?view=<?php echo $key; ?>" style="<?php echo $tab === $key ? 'background:var(--bsai-rose);' : 'color:var(--bsai-charcoal-2);'; ?>"><?php echo $label; ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'general'): ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Salon Information</div>
            <div class="card-body">
                <form method="post" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_settings">
                    <input type="hidden" name="view" value="general">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="salon_name">Salon Name</label>
                            <input type="text" class="form-control" id="salon_name" name="salon_name" value="<?php echo e($settings['salon_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="salon_tagline">Tagline</label>
                            <input type="text" class="form-control" id="salon_tagline" name="salon_tagline" value="<?php echo e($settings['salon_tagline'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="salon_phone">Phone</label>
                            <input type="text" class="form-control" id="salon_phone" name="salon_phone" value="<?php echo e($settings['salon_phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="salon_email">Email</label>
                            <input type="email" class="form-control" id="salon_email" name="salon_email" value="<?php echo e($settings['salon_email'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="salon_address">Address</label>
                            <input type="text" class="form-control" id="salon_address" name="salon_address" value="<?php echo e($settings['salon_address'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">Logo</div>
            <div class="card-body">
                <?php if (!empty($settings['salon_logo'])): ?>
                    <div class="mb-3">
                        <img src="<?php echo e(base_url($settings['salon_logo'])); ?>" alt="Logo" class="img-thumbnail" style="max-height:120px;">
                        <form method="post" action="settings.php" class="mt-2">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="form_action" value="remove_logo">
                            <button class="btn btn-sm btn-outline-danger rounded-pill" type="submit">Remove logo</button>
                        </form>
                    </div>
                <?php endif; ?>
                <form method="post" action="settings.php" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="upload_logo">
                    <div class="mb-3">
                        <input type="file" class="form-control" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg">
                        <div class="form-text">PNG, JPG, WEBP or SVG. Stored in /uploads.</div>
                    </div>
                    <button class="btn btn-charcoal rounded-pill px-4" type="submit"><i class="bi bi-upload me-1"></i>Upload Logo</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'hours'): ?>

<div class="card" style="max-width:900px;">
    <div class="card-header">Business Hours</div>
    <div class="card-body">
        <div class="alert alert-light border small text-muted mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Configure the salon's weekly operating hours. These hours are used for appointment validation,
            staff scheduling, and calendar availability. Changes immediately affect new appointments.
        </div>

        <form method="post" action="settings.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="form_action" value="save_business_hours">

            <div class="hours-grid-host">
                <?php
                $weekView = business_hours_all();
                $breakView = business_breaks_all();
                render_weekly_hours_grid($weekView, $breakView, 'hours');
                ?>
            </div>

            <div class="mt-4">
                <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i>Save Business Hours</button>
            </div>
        </form>
    </div>
</div>

<div class="card" style="max-width:900px;">
    <div class="card-header">Appointment Rules</div>
    <div class="card-body">
        <form method="post" action="settings.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="form_action" value="save_settings">
            <input type="hidden" name="view" value="hours">
            <h6 class="text-uppercase small text-muted mb-3">Pricing & Loyalty</h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="currency">Currency Code</label>
                    <input type="text" class="form-control" id="currency" name="currency" value="<?php echo e($settings['currency'] ?? 'ETB'); ?>" maxlength="5">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="tax_rate">Tax Rate (%)</label>
                    <input type="number" class="form-control" id="tax_rate" name="tax_rate" min="0" step="0.01" value="<?php echo e($settings['tax_rate'] ?? '15'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="loyalty_rate">Loyalty — ETB per 1 point</label>
                    <input type="number" class="form-control" id="loyalty_rate" name="loyalty_rate" min="1" step="1" value="<?php echo e($settings['loyalty_rate'] ?? '100'); ?>">
                </div>
            </div>

            <h6 class="text-uppercase small text-muted mt-4 mb-3">Appointment Rules</h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="appointment_advance_days">Booking Window (days)</label>
                    <input type="number" class="form-control" id="appointment_advance_days" name="appointment_advance_days" min="1" max="365" value="<?php echo e($settings['appointment_advance_days'] ?? '30'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="max_advance_days">Max Advance (days)</label>
                    <input type="number" class="form-control" id="max_advance_days" name="max_advance_days" min="1" max="365" value="<?php echo e($settings['max_advance_days'] ?? '30'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="session_timeout_minutes">Session Timeout (minutes)</label>
                    <input type="number" class="form-control" id="session_timeout_minutes" name="session_timeout_minutes" min="5" max="1440" value="<?php echo e($settings['session_timeout_minutes'] ?? '60'); ?>">
                </div>
            </div>
            <div class="mt-4">
                <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i>Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php elseif ($tab === 'holidays'): ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Add Holiday</div>
            <div class="card-body">
                <form method="post" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_holiday">
                    <div class="mb-3">
                        <label class="form-label" for="holiday_date">Date <span class="required">*</span></label>
                        <input type="date" class="form-control" id="holiday_date" name="holiday_date" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="title">Title <span class="required">*</span></label>
                        <input type="text" class="form-control" id="title" name="title" required placeholder="e.g. Ethiopian Christmas">
                    </div>
                    <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-plus-lg me-1"></i>Add Holiday</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card table-card">
            <div class="card-header">Holidays (salon closed)</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Date</th><th>Title</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php if (!$holidays): ?>
                        <tr><td colspan="3"><div class="empty-state"><div class="icon"><i class="bi bi-calendar-x"></i></div><p class="text-muted mb-0">No holidays configured.</p></div></td></tr>
                    <?php else: foreach ($holidays as $h): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo fmt_date($h['holiday_date']); ?></td>
                            <td><?php echo e($h['title']); ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                        data-confirm="Remove this holiday?"
                                        data-confirm-action="settings.php?form_action=delete_holiday&id=<?php echo $h['id']; ?>"
                                        data-confirm-label="Remove"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'notifications'): ?>

<div class="card" style="max-width:640px;">
    <div class="card-header">Notification Channels</div>
    <div class="card-body">
        <form method="post" action="settings.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="form_action" value="save_settings">
            <input type="hidden" name="view" value="notifications">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="notify_email" name="notify_email" value="1" <?php echo ($settings['notify_email'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <label class="form-check-label" for="notify_email">Email notifications</label>
                <div class="form-text">Send email alerts for bookings and payments (requires a working mail server).</div>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="notify_sms" name="notify_sms" value="1" <?php echo ($settings['notify_sms'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <label class="form-check-label" for="notify_sms">SMS notifications</label>
                <div class="form-text">Send SMS alerts for bookings and payments (requires an SMS provider).</div>
            </div>
            <p class="small text-muted mb-3"><i class="bi bi-bell me-1"></i>In-app notifications are always enabled inside the dashboard.</p>
            <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i>Save Changes</button>
        </form>
    </div>
</div>

<?php elseif ($tab === 'backup'): ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Download Backup</div>
            <div class="card-body">
                <p class="small text-muted">Export the entire database (all tables and data) as a downloadable <code>.sql</code> file.</p>
                <form method="post" action="settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="backup_db">
                    <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-download me-1"></i>Download Backup</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Restore from Backup</div>
            <div class="card-body">
                <p class="small text-muted">Upload a <code>.sql</code> backup to restore. This will overwrite the current database.</p>
                <form method="post" action="settings.php" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="restore_db">
                    <div class="mb-3">
                        <input type="file" class="form-control" name="backup_file" accept=".sql" required>
                    </div>
                    <button class="btn btn-charcoal rounded-pill px-4" type="submit" data-confirm="Restoring will overwrite the current database. Continue?"><i class="bi bi-cloud-upload me-1"></i>Restore Database</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
