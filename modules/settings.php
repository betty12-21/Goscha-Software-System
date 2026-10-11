<?php
if (!defined('APP_INIT')) { http_response_code(403); exit('Direct access is not allowed.'); }
/**
 * Beauty-php-ai — Settings (Administrator only).
 *
 * Five sections:
 *   1. Profile & Branding (own user profile, salon name & logo, users)
 *   2. Hours & Rules
 *   3. Holidays
 *   4. Notifications
 *   5. Backup
 *
 * Every read and write is scoped to the authenticated salon.
 */

require_permission('settings');

/* require_permission() has already established a valid, active tenant. */
$salonId = (int)current_salon_id();
$me      = (int)($_SESSION['user_id'] ?? 0);

/* Normalise the requested section. The legacy ?view=users deep-link is folded
   into General so old bookmarks keep working. */
$tab = (string)($_GET['view'] ?? 'general');
if ($tab === 'users') { $tab = 'general'; }

/* ============================================================
 * Save settings (group-aware, partial-safe)
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_settings') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php'); }

    $view = (string)($_POST['view'] ?? 'general');
    if ($view === 'users') { $view = 'general'; }

    /* Per-event notification toggles, derived from the one source of truth. */
    $perEvent = [];
    foreach (array_keys(notification_events()) as $event) {
        $perEvent[] = 'notify_inapp_' . $event;
        $perEvent[] = 'notify_email_' . $event;
    }

    $groups = [
        'general' => [
            'salon_name', 'salon_tagline', 'salon_phone', 'salon_email', 'salon_address',
            'owner_first_name', 'owner_last_name', 'salon_description', 'salon_website',
        ],
        'hours' => [
            'currency', 'tax_rate', 'loyalty_rate',
            'appointment_advance_days', 'max_advance_days', 'session_timeout_minutes',
            'slot_interval_minutes', 'buffer_minutes',
            'cancellation_notice_hours', 'reschedule_notice_hours',
            'allow_same_day', 'allow_walkins', 'allow_after_hours',
        ],
        'notifications' => array_merge(['notify_email', 'notify_sms'], $perEvent),
    ];

    $checkboxes = array_merge(
        ['notify_email', 'notify_sms', 'allow_same_day', 'allow_walkins', 'allow_after_hours'],
        $perEvent
    );

    $minimums = [
        'tax_rate'                  => 0,
        'loyalty_rate'              => 0,
        'appointment_advance_days'  => 1,
        'max_advance_days'          => 1,
        'session_timeout_minutes'   => 5,
        'slot_interval_minutes'     => 5,
        'buffer_minutes'            => 0,
        'cancellation_notice_hours' => 0,
        'reschedule_notice_hours'   => 0,
    ];

    /* Only the keys belonging to the submitted form are written. A missing
       key means "this form does not own that field", NOT "blank it" — the
       old behaviour wiped the salon profile whenever the hours form was
       saved. */
    $keys = $groups[$view] ?? $groups['general'];

    foreach ($keys as $key) {
        if (in_array($key, $checkboxes, true)) {
            $value = !empty($_POST[$key]) ? '1' : '0';
        } else {
            $value = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
        }
        if (isset($minimums[$key])) {
            $value = max($minimums[$key], (int)$value);
        }
        save_setting($key, $value);
    }

    if ($view === 'general') {
        try {
            salon_profile_update([
                'salon_name'       => $_POST['salon_name'] ?? '',
                'owner_first_name' => $_POST['owner_first_name'] ?? '',
                'owner_last_name'  => $_POST['owner_last_name'] ?? '',
                'email'            => $_POST['salon_email'] ?? '',
                'phone'            => $_POST['salon_phone'] ?? '',
                'address'          => $_POST['salon_address'] ?? '',
            ]);
        } catch (Throwable $e) {
            flash_set('danger', 'The salon profile could not be updated (the salon name may already be in use).');
            redirect('settings.php?view=general');
        }

        log_activity('settings', 'settings', 'Updated salon information');
        flash_set('success', 'Settings saved successfully.');
        redirect('settings.php?view=general');
    }

    log_activity('settings', 'settings', 'Updated settings (' . $view . ')');
    flash_set('success', 'Settings saved successfully.');
    redirect('settings.php?view=' . urlencode($view));
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
 * Save the signed-in administrator's own profile
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_profile') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=general'); }

    $name  = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $phone = trim((string)($_POST['phone'] ?? ''));

    $errors = [];
    if ($name === '') {
        $errors[] = 'Full name is required.';
    } elseif (mb_strlen($name) > 100) {
        $errors[] = 'Full name must be 100 characters or fewer.';
    }
    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (mb_strlen($email) > 255) {
        $errors[] = 'Email address is too long.';
    }
    if ($phone !== '' && mb_strlen($phone) > 20) {
        $errors[] = 'Phone number must be 20 characters or fewer.';
    }

    if ($errors) {
        flash_set('danger', implode(' ', $errors));
        redirect('settings.php?view=general');
    }

    /* Email is the login identity and globally unique: refuse another account's. */
    $dupe = db()->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
    $dupe->execute(['email' => $email, 'id' => $me]);
    if ($dupe->fetch()) {
        flash_set('danger', 'That email address is already used by another account.');
        redirect('settings.php?view=general');
    }

    /* Keep the first/last mirrors truthful without letting a submitted id pick
       another user: the record is always the signed-in administrator. */
    $parts = preg_split('/\s+/', $name, 2) ?: [$name];
    $first = (string)($parts[0] ?? '');
    $last  = (string)($parts[1] ?? '');

    try {
        /* Only name/email/phone change — id, role, password and status are
           deliberately untouched. */
        db()->prepare(
            'UPDATE users SET name = :name, first_name = :fn, last_name = :ln, email = :email, phone = :phone
             WHERE id = :id AND salon_id = :sid'
        )->execute([
            'name'  => $name,
            'fn'    => $first,
            'ln'    => $last,
            'email' => $email,
            'phone' => ($phone === '' ? null : $phone),
            'id'    => $me,
            'sid'   => $salonId,
        ]);
    } catch (Throwable $e) {
        flash_set('danger', 'Your profile could not be saved. That email address may already be in use.');
        redirect('settings.php?view=general');
    }

    log_activity('update', 'profile', 'Updated own administrator profile');
    flash_set('success', 'Profile updated successfully.');
    redirect('settings.php?view=general');
}

/* ============================================================
 * Salon information & branding (name + logo)
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_branding') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=general'); }

    $name = trim((string)($_POST['salon_name'] ?? ''));
    if ($name === '') {
        flash_set('danger', 'Salon name is required.');
        redirect('settings.php?view=general');
    }
    if (mb_strlen($name) > 150) {
        flash_set('danger', 'Salon name must be 150 characters or fewer.');
        redirect('settings.php?view=general');
    }

    /* The logo is optional: only touched when the admin actually picks a file,
       so a plain name change preserves the existing logo. */
    $newLogo = null;
    $err     = $_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        flash_set('danger', 'The logo is too large. Please choose an image under 2 MB.');
        redirect('settings.php?view=general');
    }
    if ($err !== UPLOAD_ERR_NO_FILE) {
        $newLogo = store_salon_logo($_FILES['logo'], $salonId);
        if ($newLogo === null) {
            flash_set('danger', 'The logo could not be uploaded. Use a PNG, JPG or WebP image under 2 MB.');
            redirect('settings.php?view=general');
        }
    }

    try {
        salon_profile_update(['salon_name' => $name]);
    } catch (Throwable $e) {
        if ($newLogo !== null) { delete_salon_logo_file($newLogo); }
        flash_set('danger', 'The salon name could not be saved — it may already be used by another salon.');
        redirect('settings.php?view=general');
    }
    save_setting('salon_name', $name);

    if ($newLogo !== null) {
        $previous = salon_logo_path();
        save_setting('salon_logo', $newLogo);
        delete_salon_logo_file($previous, $newLogo);
    }

    log_activity('settings', 'settings', 'Updated salon information and branding');
    flash_set('success', 'Salon information and branding saved.');
    redirect('settings.php?view=general');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'remove_logo') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=general'); }
    delete_salon_logo_file(salon_logo_path());
    save_setting('salon_logo', '');
    log_activity('settings', 'settings', 'Removed salon logo');
    flash_set('success', 'Logo removed.');
    redirect('settings.php?view=general');
}

/* ============================================================
 * Holidays (create / update / delete)
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_holiday') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=holidays'); }

    $id       = (int)($_POST['id'] ?? 0);
    $date     = trim($_POST['holiday_date'] ?? '');
    $endDate  = trim($_POST['end_date'] ?? '');
    $title    = trim($_POST['title'] ?? '');
    $isClosed = !empty($_POST['is_closed']) ? 1 : 0;
    $openTime = trim($_POST['open_time'] ?? '');
    $closeTime = trim($_POST['close_time'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');
    $isActive = !empty($_POST['is_active']) ? 1 : 0;

    $validDate = static fn(string $d): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

    if (!$validDate($date) || $title === '') {
        flash_set('danger', 'A valid start date and title are required.');
        redirect('settings.php?view=holidays' . ($id ? '&action=edit_holiday&id=' . $id : ''));
    }

    if ($endDate === '') {
        $endDate = null;
    } elseif (!$validDate($endDate) || $endDate < $date) {
        flash_set('danger', 'The end date must be on or after the start date.');
        redirect('settings.php?view=holidays' . ($id ? '&action=edit_holiday&id=' . $id : ''));
    }

    if (!$isClosed) {
        if ($openTime === '' || $closeTime === '' || $closeTime <= $openTime) {
            flash_set('danger', 'Special (open) hours need an opening and closing time, with closing after opening.');
            redirect('settings.php?view=holidays' . ($id ? '&action=edit_holiday&id=' . $id : ''));
        }
    } else {
        $openTime = $closeTime = null;
    }

    $args = [
        'sid'        => $salonId,
        'd'          => $date,
        'end_date'   => $endDate,
        'title'      => $title,
        'is_closed'  => $isClosed,
        'open_time'  => $openTime ?: null,
        'close_time' => $closeTime ?: null,
        'notes'      => $notes !== '' ? $notes : null,
        'is_active'  => $isActive,
    ];

    try {
        if ($id > 0) {
            require_tenant_record('holidays', $id);
            $args['id'] = $id;
            db()->prepare(
                'UPDATE holidays SET holiday_date=:d, end_date=:end_date, title=:title,
                        is_closed=:is_closed, open_time=:open_time, close_time=:close_time,
                        notes=:notes, is_active=:is_active
                  WHERE id=:id AND salon_id=:sid'
            )->execute($args);
            log_activity('update', 'holidays', "Updated holiday {$title}");
            flash_set('success', 'Holiday updated.');
        } else {
            db()->prepare(
                'INSERT INTO holidays (salon_id, holiday_date, end_date, title, is_closed, open_time, close_time, notes, is_active)
                 VALUES (:sid, :d, :end_date, :title, :is_closed, :open_time, :close_time, :notes, :is_active)'
            )->execute($args);
            log_activity('create', 'holidays', "Added holiday {$title}");
            flash_set('success', 'Holiday added.');
        }
    } catch (Throwable $e) {
        flash_set('danger', 'The holiday could not be saved.');
    }

    /* Flag any live appointment that now falls on a closed day. */
    if ($isClosed) {
        try {
            $conflict = db()->prepare(
                'SELECT COUNT(*) FROM appointments
                  WHERE salon_id = :sid AND status NOT IN ("cancelled", "no_show")
                    AND appointment_date >= :a AND appointment_date <= :b'
            );
            $conflict->execute(['sid' => $salonId, 'a' => $date, 'b' => $endDate ?? $date]);
            $count = (int)$conflict->fetchColumn();
            if ($count > 0) {
                flash_set('warning', $count . ' existing appointment(s) fall on this closure and now sit outside the salon hours.');
            }
        } catch (Throwable $e) {
            /* conflict flagging is best-effort */
        }
    }

    redirect('settings.php?view=holidays');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'delete_holiday') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=holidays'); }
    $holidayId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    require_tenant_record('holidays', $holidayId);
    db()->prepare('DELETE FROM holidays WHERE id = :id AND salon_id = :sid')->execute(['id' => $holidayId, 'sid' => $salonId]);
    log_activity('delete', 'holidays', "Deleted holiday #{$holidayId}");
    flash_set('success', 'Holiday removed.');
    redirect('settings.php?view=holidays');
}

/* ============================================================
 * Backup — per-salon export, history and protected download
 * ============================================================ */
if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'backup_db') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=backup'); }

    try {
        $built = salon_backup_build($salonId);
        $dir   = salon_backup_dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the backup directory.');
        }

        $path = $dir . DIRECTORY_SEPARATOR . $built['filename'];
        if (file_put_contents($path, $built['sql']) === false) {
            throw new RuntimeException('Could not write the backup file.');
        }

        db()->prepare(
            'INSERT INTO salon_backups (salon_id, filename, file_size, status, created_by)
             VALUES (:sid, :f, :s, "completed", :u)'
        )->execute([
            'sid' => $salonId,
            'f'   => $built['filename'],
            's'   => strlen($built['sql']),
            'u'   => $me ?: null,
        ]);

        log_activity('backup', 'settings', "Created salon backup {$built['filename']}");
        flash_set('success', 'Backup created successfully. It is listed below for download.');
    } catch (Throwable $e) {
        flash_set('danger', 'Backup failed: ' . e($e->getMessage()));
    }

    redirect('settings.php?view=backup');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'download_backup') {
    require_permission('settings');
    $backupId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    require_tenant_record('salon_backups', $backupId);
    $row = tenant_fetch('salon_backups', $backupId);

    $path = $row ? salon_backup_dir() . DIRECTORY_SEPARATOR . basename((string)$row['filename']) : '';

    if (!$row || !is_file($path)) {
        flash_set('danger', 'That backup file is no longer available.');
        redirect('settings.php?view=backup');
    }

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . basename((string)$row['filename']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'delete_backup') {
    require_permission('settings');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=backup'); }
    $backupId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    require_tenant_record('salon_backups', $backupId);
    $row = tenant_fetch('salon_backups', $backupId);
    if ($row) {
        $path = salon_backup_dir() . DIRECTORY_SEPARATOR . basename((string)$row['filename']);
        if (is_file($path)) { @unlink($path); }
        db()->prepare('DELETE FROM salon_backups WHERE id = :id AND salon_id = :sid')->execute(['id' => $backupId, 'sid' => $salonId]);
        log_activity('delete', 'settings', "Deleted backup #{$backupId}");
        flash_set('success', 'Backup deleted.');
    }
    redirect('settings.php?view=backup');
}

/* ============================================================
 * Users — staff accounts under Settings > General > Users
 *
 * Admins are only ever created by the registration wizard. This screen can
 * create receptionists, edit details, reset passwords, and toggle/delete
 * accounts — but it can never turn a staff member into an admin, and it can
 * never remove the salon's last active admin.
 * ============================================================ */
$userAction = (string)($_GET['action'] ?? 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_user') {
    require_permission('users');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=general'); }

    $id       = (int)($_POST['id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $status   = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('danger', 'A valid name and email address are required.');
        redirect($id ? "settings.php?view=general&action=edit&id={$id}" : 'settings.php?view=general&action=add');
    }
    if (!$id && strlen($password) < 8) {
        flash_set('danger', 'Password must be at least 8 characters.');
        redirect('settings.php?view=general&action=add');
    }

    try {
        if ($id) {
            require_tenant_record('users', $id);
            $sql  = 'UPDATE users SET name=:name, email=:email, phone=:phone, status=:status';
            $args = ['name' => $name, 'email' => $email, 'phone' => $phone ?: null, 'status' => $status, 'id' => $id];
            if ($password !== '') {
                if (strlen($password) < 8) {
                    flash_set('danger', 'Password must be at least 8 characters.');
                    redirect("settings.php?view=general&action=edit&id={$id}");
                }
                $sql .= ', password=:password';
                $args['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id=:id AND salon_id=:sid';
            $args['sid'] = $salonId;
            db()->prepare($sql)->execute($args);
            log_activity('update', 'users', "Updated user {$email}");
            flash_set('success', 'User updated.');
        } else {
            /* Creation is receptionist-only — no admin escalation here. */
            db()->prepare('INSERT INTO users (salon_id, name, email, phone, password, role, status) VALUES (:sid,:name,:email,:phone,:password,"receptionist",:status)')
                ->execute([
                    'sid' => $salonId, 'name' => $name, 'email' => $email, 'phone' => $phone ?: null,
                    'password' => password_hash($password, PASSWORD_DEFAULT), 'status' => $status,
                ]);
            log_activity('create', 'users', "Created receptionist account {$email}");
            flash_set('success', 'Staff account created.');
        }
    } catch (Throwable $e) {
        flash_set('danger', 'Save failed. That email address may already be in use.');
    }
    redirect('settings.php?view=general');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'toggle_user') {
    require_permission('users');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=general'); }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id === $me) { flash_set('warning', 'You cannot change your own status.'); redirect('settings.php?view=general'); }
    require_tenant_record('users', $id);
    $u = tenant_fetch('users', $id, 'id, role, status, email');
    if ($u) {
        $newStatus = $u['status'] === 'active' ? 'inactive' : 'active';

        /* Never strand the salon without an active administrator. */
        if ($newStatus === 'inactive' && $u['role'] === 'admin') {
            $admins = db()->prepare("SELECT COUNT(*) FROM users WHERE salon_id = :sid AND role = 'admin' AND status = 'active'");
            $admins->execute(['sid' => $salonId]);
            if ((int)$admins->fetchColumn() <= 1) {
                flash_set('danger', 'You cannot deactivate the last active administrator.');
                redirect('settings.php?view=general');
            }
        }

        db()->prepare('UPDATE users SET status = :status WHERE id = :id AND salon_id = :sid')->execute(['status' => $newStatus, 'id' => $id, 'sid' => $salonId]);
        log_activity('update', 'users', ($newStatus === 'active' ? 'Activated' : 'Deactivated') . " user {$u['email']}");
        flash_set('success', ($newStatus === 'active' ? 'Activated' : 'Deactivated') . ' successfully.');
    }
    redirect('settings.php?view=general');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'delete_user') {
    require_permission('users');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=general'); }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id === $me) { flash_set('warning', 'You cannot delete your own account.'); redirect('settings.php?view=general'); }
    require_tenant_record('users', $id);

    $u = tenant_fetch('users', $id, 'id, role, status, email');
    if ($u && $u['role'] === 'admin' && $u['status'] === 'active') {
        $admins = db()->prepare("SELECT COUNT(*) FROM users WHERE salon_id = :sid AND role = 'admin' AND status = 'active'");
        $admins->execute(['sid' => $salonId]);
        if ((int)$admins->fetchColumn() <= 1) {
            flash_set('danger', 'You cannot delete the last active administrator.');
            redirect('settings.php?view=general');
        }
    }

    try {
        db()->prepare('DELETE FROM users WHERE id = :id AND salon_id = :sid')->execute(['id' => $id, 'sid' => $salonId]);
        log_activity('delete', 'users', "Deleted user #{$id}");
        flash_set('success', 'User deleted.');
    } catch (Throwable $e) {
        flash_set('danger', 'Could not delete this user.');
    }
    redirect('settings.php?view=general');
}

if (($_POST['form_action'] ?? $_GET['form_action'] ?? '') === 'reset_user_password') {
    require_permission('users');
    if (!csrf_check()) { flash_set('danger', 'Invalid security token.'); redirect('settings.php?view=general'); }
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    require_tenant_record('users', $id);
    $u = tenant_fetch('users', $id, 'id, email, name');

    if ($u) {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        db()->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (:uid, :t, :e)')
            ->execute(['uid' => $id, 't' => $token, 'e' => $expires]);

        /* No mail provider is configured, so surface the link once via the
           session rather than pretending an email was sent. */
        $_SESSION['generated_reset_link'] = [
            'email' => $u['email'],
            'link'  => base_url('reset-password.php?token=' . urlencode($token)),
        ];

        log_activity('password_reset_request', 'users', "Generated reset link for {$u['email']}");
        flash_set('success', 'Password reset link generated for ' . $u['email'] . '.');
    }
    redirect('settings.php?view=general');
}

/* ============================================================
 * View data
 * ============================================================ */
$settings  = get_settings_map();
$salonRow  = current_salon() ?: [];

$holidaysStmt = db()->prepare('SELECT * FROM holidays WHERE salon_id = :sid ORDER BY holiday_date DESC, id DESC');
$holidaysStmt->execute(['sid' => $salonId]);
$holidays = $holidaysStmt->fetchAll();

$backupsStmt = db()->prepare('SELECT * FROM salon_backups WHERE salon_id = :sid ORDER BY created_at DESC, id DESC');
$backupsStmt->execute(['sid' => $salonId]);
$backups = $backupsStmt->fetchAll();

$page_title = 'Settings';
$active     = 'settings';
include __DIR__ . '/../includes/header.php';

$settingsTabs = [
    'general'       => 'Profile & Branding',
    'hours'         => 'Hours & Rules',
    'holidays'      => 'Holidays',
    'notifications' => 'Notifications',
    'backup'        => 'Backup',
];
?>

<ul class="nav nav-pills mb-3 gap-1 flex-wrap">
    <?php foreach ($settingsTabs as $key => $label): ?>
        <li class="nav-item"><a class="nav-link <?php echo $tab === $key ? 'active' : ''; ?>" href="settings.php?view=<?php echo $key; ?>" style="<?php echo $tab === $key ? 'background:var(--bsai-rose);' : 'color:var(--bsai-charcoal-2);'; ?>"><?php echo e($label); ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'general'): ?>

    <?php
    $canUsers   = can('users');
    $self       = current_user() ?: ['name' => '', 'email' => '', 'phone' => ''];
    $currentLogo = salon_logo_url();
    ?>

    <div class="card mb-4" style="max-width:680px;">
        <div class="card-header"><i class="bi bi-person-circle me-1"></i>User Profile</div>
        <div class="card-body">
            <p class="text-muted small mb-3">Your own account details. Your role, password and account status are managed elsewhere and are not changed here.</p>
            <form method="post" action="settings.php?view=general">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="save_profile">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="profile_name">Full Name <span class="required">*</span></label>
                        <input type="text" class="form-control" id="profile_name" name="name" required maxlength="100" value="<?php echo e($self['name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="profile_phone">Phone Number</label>
                        <input type="text" class="form-control" id="profile_phone" name="phone" maxlength="20" value="<?php echo e($self['phone'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="profile_email">Email Address <span class="required">*</span></label>
                        <input type="email" class="form-control" id="profile_email" name="email" required maxlength="255" value="<?php echo e($self['email'] ?? ''); ?>">
                        <div class="form-text">Used to sign in to the dashboard.</div>
                    </div>
                </div>
                <div class="mt-4">
                    <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i>Save Profile</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-4" style="max-width:680px;">
        <div class="card-header"><i class="bi bi-shop me-1"></i>Salon Information &amp; Branding</div>
        <div class="card-body">
            <p class="text-muted small mb-3">Set the salon name and logo shown across the dashboard. These do not change your public booking page.</p>
            <form method="post" action="settings.php?view=general" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="save_branding">
                <div class="mb-3">
                    <label class="form-label" for="salon_name_field">Salon Name <span class="required">*</span></label>
                    <input type="text" class="form-control" id="salon_name_field" name="salon_name" required maxlength="150" value="<?php echo e(salon_name()); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="logo">Salon Logo</label>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <img id="logoPreview"
                             src="<?php echo e($currentLogo); ?>"
                             alt="Current logo"
                             class="img-thumbnail <?php echo $currentLogo === '' ? 'd-none' : ''; ?>"
                             style="max-height:96px; max-width:200px;">
                        <?php if ($currentLogo === ''): ?>
                            <span class="text-muted small">No logo uploaded yet.</span>
                        <?php endif; ?>
                    </div>
                    <input type="file" class="form-control" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp" data-preview="#logoPreview">
                    <div class="form-text">PNG, JPG or WebP, up to 2 MB. Leave empty to keep the current logo.</div>
                </div>
                <div class="mt-4">
                    <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i>Save Branding</button>
                </div>
            </form>

            <?php if ($currentLogo !== ''): ?>
                <form method="post" action="settings.php?view=general" class="mt-2"
                      onsubmit="return confirm('Remove the salon logo?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="remove_logo">
                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">
                        <i class="bi bi-trash me-1"></i>Remove logo
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canUsers): ?>

        <h2 class="h5 text-charcoal mt-4 mb-3"><i class="bi bi-people me-1"></i>Users</h2>

        <?php
        /* Defence in depth: the users section is only rendered when can('users'),
           but a forged request must still be refused server-side. */
        require_permission('users');

        $isEdit = $userAction === 'edit';
        $user   = ['id' => 0, 'name' => '', 'email' => '', 'phone' => '', 'status' => 'active', 'role' => 'receptionist'];
        if ($isEdit) {
            $found = tenant_fetch('users', (int)($_GET['id'] ?? 0));
            if (!$found) { flash_set('warning', 'User not found.'); redirect('settings.php?view=general'); }
            $user = $found;
        }

        $usersStmt = db()->prepare('SELECT u.*, (SELECT COUNT(*) FROM notifications n WHERE n.user_id = u.id AND n.salon_id = u.salon_id) AS notif_count FROM users u WHERE u.salon_id = :sid ORDER BY u.role, u.name');
        $usersStmt->execute(['sid' => $salonId]);
        $users = $usersStmt->fetchAll();

        $resetLink = $_SESSION['generated_reset_link'] ?? null;
        unset($_SESSION['generated_reset_link']);
        $roleLabel = static fn(string $r): string => $r === 'admin' ? 'Administrator' : 'Receptionist';
        ?>
        <style>
            .settings-tabs .nav-link { color: var(--bsai-charcoal-2); }
            .settings-tabs .nav-link.active { color: #fff; background: var(--bsai-rose); border-color: transparent; }
        </style>

        <?php if ($resetLink): ?>
            <div class="alert alert-warning rounded-3">
                <strong>Password reset link for <?php echo e($resetLink['email']); ?>:</strong>
                <div class="mt-1 p-2 bg-white rounded small text-break">
                    Email delivery is not configured — copy or open this one-time link (valid 1 hour):
                    <a href="<?php echo e($resetLink['link']); ?>"><?php echo e($resetLink['link']); ?></a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($userAction === 'add' || $userAction === 'edit'): ?>

            <div class="card" style="max-width:680px;">
                <div class="card-header"><?php echo $isEdit ? 'Edit ' . e($roleLabel($user['role'])) : 'New Staff Account'; ?></div>
                <div class="card-body">
                    <form method="post" action="settings.php?view=general">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="form_action" value="save_user">
                        <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="name">Full Name <span class="required">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" required value="<?php echo e($user['name']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="email">Email Address <span class="required">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" required value="<?php echo e($user['email']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="phone">Phone</label>
                                <input type="text" class="form-control" id="phone" name="phone" value="<?php echo e($user['phone']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="status">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $user['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="password"><?php echo $isEdit ? 'New Password (leave blank to keep current)' : 'Password <span class="required">*</span>'; ?></label>
                                <input type="password" class="form-control" id="password" name="password" <?php echo $isEdit ? '' : 'required'; ?> autocomplete="new-password" minlength="8">
                                <div class="form-text">Minimum 8 characters.<?php echo $isEdit ? ' The account role cannot be changed here.' : ' New accounts are always Receptionists — administrators are created only during salon registration.'; ?></div>
                            </div>
                        </div>
                        <div class="mt-4 d-flex gap-2">
                            <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i><?php echo $isEdit ? 'Save Changes' : 'Create Account'; ?></button>
                            <a href="settings.php?view=general" class="btn btn-light rounded-pill px-4">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

        <?php else: ?>

            <div class="card table-card">
                <div class="card-body p-3">
                    <span class="small text-muted"><?php echo count($users); ?> account(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$users): ?>
                            <tr><td colspan="7"><div class="empty-state"><div class="icon"><i class="bi bi-people"></i></div><p class="text-muted mb-0">No users found.</p></div></td></tr>
                        <?php else: foreach ($users as $u): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo e($u['name']); ?> <?php echo (int)$u['id'] === $me ? '<span class="badge bg-rose-soft text-rose">You</span>' : ''; ?></td>
                                <td><?php echo e($u['email']); ?></td>
                                <td class="small"><?php echo e($u['phone'] ?: '—'); ?></td>
                                <td><span class="badge rounded-pill <?php echo $u['role'] === 'admin' ? 'bg-charcoal text-white' : 'bg-light text-dark'; ?>"><?php echo e($roleLabel($u['role'])); ?></span></td>
                                <td><?php echo status_badge($u['status']); ?></td>
                                <td class="small"><?php echo fmt_date($u['created_at']); ?></td>
                                <td class="text-end">
                                    <a href="settings.php?view=general&action=edit&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-light rounded-pill" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <?php if ((int)$u['id'] !== $me): ?>
                                        <form method="post" action="settings.php?view=general" class="d-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="form_action" value="reset_user_password">
                                            <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-light rounded-pill text-primary" title="Generate password reset link"><i class="bi bi-key"></i></button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-light rounded-pill <?php echo $u['status'] === 'active' ? 'text-warning' : 'text-success'; ?>"
                                                data-confirm="<?php echo $u['status'] === 'active' ? 'Deactivate this account?' : 'Activate this account?'; ?>"
                                                data-confirm-action="settings.php?form_action=toggle_user&id=<?php echo $u['id']; ?>"
                                                data-confirm-label="<?php echo $u['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                            <i class="bi <?php echo $u['status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle'; ?>"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                                data-confirm="Delete this user permanently?"
                                                data-confirm-action="settings.php?form_action=delete_user&id=<?php echo $u['id']; ?>"
                                                data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted">You</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php endif; ?>

    <?php endif; ?>

<?php elseif ($tab === 'hours'): ?>

    <div class="card mb-3" style="max-width:900px;">
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

    <div class="card mb-3" style="max-width:900px;">
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

                <h6 class="text-uppercase small text-muted mt-4 mb-3">Scheduling & Booking Window</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="slot_interval_minutes">Slot Interval (minutes)</label>
                        <input type="number" class="form-control" id="slot_interval_minutes" name="slot_interval_minutes" min="5" max="120" value="<?php echo e($settings['slot_interval_minutes'] ?? '15'); ?>">
                        <div class="form-text">Spacing of offered start times.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="buffer_minutes">Buffer Between Appointments (minutes)</label>
                        <input type="number" class="form-control" id="buffer_minutes" name="buffer_minutes" min="0" max="120" value="<?php echo e($settings['buffer_minutes'] ?? '0'); ?>">
                        <div class="form-text">Clean-up / travel gap enforced around bookings.</div>
                    </div>
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

                <h6 class="text-uppercase small text-muted mt-4 mb-3">Cancellation & Rescheduling</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="cancellation_notice_hours">Cancellation Notice (hours)</label>
                        <input type="number" class="form-control" id="cancellation_notice_hours" name="cancellation_notice_hours" min="0" max="168" value="<?php echo e($settings['cancellation_notice_hours'] ?? '0'); ?>">
                        <div class="form-text">Minimum notice before an appointment may be cancelled (0 = anytime).</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="reschedule_notice_hours">Reschedule Notice (hours)</label>
                        <input type="number" class="form-control" id="reschedule_notice_hours" name="reschedule_notice_hours" min="0" max="168" value="<?php echo e($settings['reschedule_notice_hours'] ?? '0'); ?>">
                        <div class="form-text">Minimum notice before an appointment may be moved (0 = anytime).</div>
                    </div>
                </div>

                <h6 class="text-uppercase small text-muted mt-4 mb-3">Booking Options</h6>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="allow_same_day" name="allow_same_day" value="1" <?php echo ($settings['allow_same_day'] ?? '1') === '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="allow_same_day">Allow same-day bookings</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="allow_walkins" name="allow_walkins" value="1" <?php echo ($settings['allow_walkins'] ?? '1') === '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="allow_walkins">Allow walk-in registration</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="allow_after_hours" name="allow_after_hours" value="1" <?php echo ($settings['allow_after_hours'] ?? '0') === '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="allow_after_hours">Allow appointments outside opening hours</label>
                </div>

                <div class="mt-4">
                    <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i>Save Rules</button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($tab === 'holidays'): ?>

    <?php
    $editHoliday = null;
    if ($userAction === 'edit_holiday') {
        $editHoliday = tenant_fetch('holidays', (int)($_GET['id'] ?? 0));
    }
    $hOpen = ['is_closed' => true, 'open_time' => '', 'close_time' => '', 'is_active' => true];
    if ($editHoliday) {
        $hOpen = [
            'is_closed' => (int)$editHoliday['is_closed'] === 1,
            'open_time' => substr((string)($editHoliday['open_time'] ?? ''), 0, 5),
            'close_time' => substr((string)($editHoliday['close_time'] ?? ''), 0, 5),
            'is_active' => (int)$editHoliday['is_active'] === 1,
        ];
    }
    ?>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><?php echo $editHoliday ? 'Edit Holiday / Hours' : 'Add Holiday / Special Hours'; ?></div>
                <div class="card-body">
                    <form method="post" action="settings.php?view=holidays">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="form_action" value="save_holiday">
                        <input type="hidden" name="id" value="<?php echo (int)($editHoliday['id'] ?? 0); ?>">
                        <div class="mb-3">
                            <label class="form-label" for="holiday_date">Start Date <span class="required">*</span></label>
                            <input type="date" class="form-control" id="holiday_date" name="holiday_date" required value="<?php echo e($editHoliday['holiday_date'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="end_date">End Date <span class="text-muted small">(optional range)</span></label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo e($editHoliday['end_date'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="title">Title <span class="required">*</span></label>
                            <input type="text" class="form-control" id="title" name="title" required placeholder="e.g. Ethiopian Christmas" value="<?php echo e($editHoliday['title'] ?? ''); ?>">
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="is_closed" name="is_closed" value="1" <?php echo $hOpen['is_closed'] ? 'checked' : ''; ?> onchange="document.getElementById('specialHours').classList.toggle('d-none', this.checked);">
                            <label class="form-check-label" for="is_closed">Closed all day</label>
                        </div>
                        <div id="specialHours" class="<?php echo $hOpen['is_closed'] ? 'd-none' : ''; ?>">
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label" for="open_time">Opens</label>
                                    <input type="time" class="form-control" id="open_time" name="open_time" value="<?php echo e($hOpen['open_time']); ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="close_time">Closes</label>
                                    <input type="time" class="form-control" id="close_time" name="close_time" value="<?php echo e($hOpen['close_time']); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3 mt-2">
                            <label class="form-label" for="notes">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2"><?php echo e($editHoliday['notes'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo $hOpen['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i><?php echo $editHoliday ? 'Save Changes' : 'Add'; ?></button>
                            <?php if ($editHoliday): ?><a href="settings.php?view=holidays" class="btn btn-light rounded-pill px-4">Cancel</a><?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card table-card">
                <div class="card-header">Holidays & Special Hours</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Date</th><th>Title</th><th>Type</th><th>Status</th><th class="text-end"></th></tr></thead>
                        <tbody>
                        <?php if (!$holidays): ?>
                            <tr><td colspan="5"><div class="empty-state"><div class="icon"><i class="bi bi-calendar-x"></i></div><p class="text-muted mb-0">No holidays configured.</p></div></td></tr>
                        <?php else: foreach ($holidays as $h): ?>
                            <?php
                            $range = fmt_date($h['holiday_date']);
                            if (!empty($h['end_date']) && $h['end_date'] !== $h['holiday_date']) {
                                $range .= ' → ' . fmt_date($h['end_date']);
                            }
                            ?>
                            <tr>
                                <td class="fw-semibold"><?php echo e($range); ?></td>
                                <td><?php echo e($h['title']); ?><?php if (!empty($h['notes'])): ?><div class="small text-muted"><?php echo e($h['notes']); ?></div><?php endif; ?></td>
                                <td>
                                    <?php if ((int)$h['is_closed'] === 1): ?>
                                        <span class="badge rounded-pill bg-light text-dark">Closed</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill bg-rose-soft text-rose"><?php echo e(substr((string)$h['open_time'], 0, 5) . '–' . substr((string)$h['close_time'], 0, 5)); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$h['is_active'] === 1 ? '<span class="badge rounded-pill bg-success-subtle text-success">Active</span>' : '<span class="badge rounded-pill bg-light text-muted">Inactive</span>'; ?></td>
                                <td class="text-end">
                                    <a href="settings.php?view=holidays&action=edit_holiday&id=<?php echo $h['id']; ?>" class="btn btn-sm btn-light rounded-pill"><i class="bi bi-pencil"></i></a>
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

    <div class="card" style="max-width:820px;">
        <div class="card-header">Notification Preferences</div>
        <div class="card-body">
            <form method="post" action="settings.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="form_action" value="save_settings">
                <input type="hidden" name="view" value="notifications">

                <h6 class="text-uppercase small text-muted mb-3">Channels</h6>
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

                <h6 class="text-uppercase small text-muted mt-4 mb-3">Events</h6>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Event</th><th class="text-center">In-app</th><th class="text-center">Email</th></tr></thead>
                        <tbody>
                        <?php foreach (notification_events() as $eventKey => $eventLabel): ?>
                            <tr>
                                <td><?php echo e($eventLabel); ?></td>
                                <td class="text-center">
                                    <input class="form-check-input" type="checkbox" name="notify_inapp_<?php echo e($eventKey); ?>" value="1" <?php echo notification_event_enabled($eventKey, 'inapp') ? 'checked' : ''; ?>>
                                </td>
                                <td class="text-center">
                                    <input class="form-check-input" type="checkbox" name="notify_email_<?php echo e($eventKey); ?>" value="1" <?php echo notification_event_enabled($eventKey, 'email') ? 'checked' : ''; ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-check2 me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($tab === 'backup'): ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Backup History</span>
                    <form method="post" action="settings.php?view=backup" class="mb-0">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="form_action" value="backup_db">
                        <button class="btn btn-rose rounded-pill px-4" type="submit"><i class="bi bi-download me-1"></i>Create New Backup</button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>File</th><th>Size</th><th>Created</th><th>Status</th><th class="text-end"></th></tr></thead>
                        <tbody>
                        <?php if (!$backups): ?>
                            <tr><td colspan="5"><div class="empty-state"><div class="icon"><i class="bi bi-hdd-stack"></i></div><p class="text-muted mb-0">No backups yet.</p></div></td></tr>
                        <?php else: foreach ($backups as $b): ?>
                            <tr>
                                <td class="small fw-semibold text-break"><?php echo e($b['filename']); ?></td>
                                <td class="small"><?php echo e(number_format(((int)$b['file_size']) / 1024, 1)); ?> KB</td>
                                <td class="small"><?php echo e(fmt_date($b['created_at'])); ?></td>
                                <td><?php echo $b['status'] === 'completed' ? '<span class="badge rounded-pill bg-success-subtle text-success">Completed</span>' : '<span class="badge rounded-pill bg-danger-subtle text-danger">Failed</span>'; ?></td>
                                <td class="text-end">
                                    <a href="settings.php?form_action=download_backup&id=<?php echo $b['id']; ?>" class="btn btn-sm btn-light rounded-pill" title="Download"><i class="bi bi-download"></i></a>
                                    <button type="button" class="btn btn-sm btn-light rounded-pill text-danger"
                                            data-confirm="Delete this backup file?"
                                            data-confirm-action="settings.php?form_action=delete_backup&id=<?php echo $b['id']; ?>"
                                            data-confirm-label="Delete"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">About Backups</div>
                <div class="card-body small text-muted">
                    <p class="mb-2"><i class="bi bi-shield-check me-1 text-success"></i>Each backup contains only <strong>your salon's</strong> data — customers, appointments, services, staff, payments and settings. No other salon's records are ever included.</p>
                    <p class="mb-0">Files are stored outside the web root and can only be downloaded by an administrator from this page.</p>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
