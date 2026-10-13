<?php
/**
 * Beauty-php-ai — Super Admin Control Panel core.
 *
 * The platform operator layer. Super admins are stored in `super_admins`,
 * completely separate from any salon's `users` rows, and use their own
 * session keys (`super_admin_id`) so a salon login and a platform login can
 * coexist without one impersonating the other.
 *
 * Nothing here trusts the browser: every lifecycle action is validated
 * server-side, every write is audited, and the login endpoint is rate
 * limited.
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

const SUPER_ADMIN_LOGIN_MAX_ATTEMPTS   = 5;
const SUPER_ADMIN_LOGIN_WINDOW_MINUTES = 15;
const SUPER_ADMIN_IDLE_TIMEOUT_MINUTES = 120;

/**
 * Canonical statuses a salon may hold.
 *
 * @return array<int,string>
 */
function salon_statuses(): array
{
    return ['pending', 'active', 'inactive', 'suspended', 'rejected', 'cancelled'];
}

/**
 * Allowed status transitions. A transition not listed here is refused.
 *
 * `active` is reachable from every other state (approve or restore);
 * a cancelled salon can be restored, but a cancelled salon is otherwise
 * terminal until an operator explicitly revives it.
 */
function salon_status_transitions(): array
{
    return [
        'pending'   => ['active', 'rejected', 'cancelled'],
        'active'    => ['suspended', 'inactive', 'cancelled'],
        'inactive'  => ['active', 'suspended', 'cancelled'],
        'suspended' => ['active', 'cancelled'],
        'rejected'  => ['active', 'cancelled'],
        'cancelled' => ['active'],
    ];
}

function super_admin_can_transition(string $from, string $to): bool
{
    if ($from === $to) {
        return false;
    }

    $allowed = salon_status_transitions()[$from] ?? [];
    return in_array($to, $allowed, true);
}

/* ------------------------------------------------------------------
 * URLs
 * ------------------------------------------------------------------ */

function super_admin_url(string $path = ''): string
{
    return base_url('super-admin/' . ltrim($path, '/'));
}

function super_admin_login_url(): string
{
    return super_admin_url('login.php');
}

/* ------------------------------------------------------------------
 * Session / identity
 * ------------------------------------------------------------------ */

function is_super_admin_logged_in(): bool
{
    return !empty($_SESSION['super_admin_id']);
}

/**
 * The signed-in super admin row, or null.
 *
 * Re-read on every call and re-checked for `active`, so deactivating a
 * super admin takes effect on their next request rather than at session end.
 */
function current_super_admin(): ?array
{
    static $cache = false;

    if ($cache !== false) {
        return $cache ?: null;
    }

    if (!is_super_admin_logged_in()) {
        $cache = null;
        return null;
    }

    try {
        $stmt = db()->prepare('SELECT * FROM super_admins WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int)$_SESSION['super_admin_id']]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active') {
            $cache = null;
            logout_super_admin(false);
            return null;
        }

        $cache = $row;
        return $row;
    } catch (Throwable $e) {
        $cache = null;
        return null;
    }
}

/**
 * Guard every Super Admin page.
 *
 * Deliberately does NOT call require_login()/require_salon_context(): a
 * super admin is not a salon user and has no tenant. It enforces its own
 * session and idle timeout and redirects to the SA login.
 */
function require_super_admin(): void
{
    start_app_session();

    if (!is_super_admin_logged_in()) {
        flash_set('warning', 'Please sign in to the control panel.');
        redirect(super_admin_login_url());
    }

    $timeoutMinutes = SUPER_ADMIN_IDLE_TIMEOUT_MINUTES;
    $lastActive     = (int)($_SESSION['super_admin_last_active'] ?? time());

    if (time() - $lastActive > $timeoutMinutes * 60) {
        logout_super_admin(false);
        flash_set('warning', 'Your session expired. Please sign in again.');
        redirect(super_admin_login_url());
    }

    if (current_super_admin() === null) {
        flash_set('danger', 'Your account is no longer active.');
        redirect(super_admin_login_url());
    }

    $_SESSION['super_admin_last_active'] = time();
}

/* ------------------------------------------------------------------
 * Login / logout
 * ------------------------------------------------------------------ */

/**
 * How many recent failed attempts this (email, ip) pair has made.
 */
function super_admin_login_attempt_count(string $email, string $ip): int
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM super_admin_login_attempts
              WHERE email = :email AND ip_address = :ip AND successful = 0
                AND attempted_at > DATE_SUB(NOW(), INTERVAL ' . SUPER_ADMIN_LOGIN_WINDOW_MINUTES . ' MINUTE)'
        );
        $stmt->execute(['email' => strtolower(trim($email)), 'ip' => $ip]);

        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function super_admin_rate_limited(string $email, ?string $ip = null): bool
{
    return super_admin_login_attempt_count($email, $ip ?? client_ip()) >= SUPER_ADMIN_LOGIN_MAX_ATTEMPTS;
}

function record_super_admin_attempt(string $email, bool $successful, ?string $ip = null): void
{
    try {
        db()->prepare(
            'INSERT INTO super_admin_login_attempts (email, ip_address, successful) VALUES (:email, :ip, :ok)'
        )->execute([
            'email' => strtolower(trim($email)),
            'ip'    => $ip ?? client_ip(),
            'ok'    => $successful ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        /* Rate limiting is best-effort; never block on the log table. */
    }
}

/**
 * Verify credentials and start a super admin session.
 *
 * @return array{success:bool, error?:string}
 */
function attempt_super_admin_login(string $email, string $password): array
{
    $email = strtolower(trim($email));
    $ip    = client_ip();

    if ($email === '' || $password === '') {
        return ['success' => false, 'error' => 'Email and password are required.'];
    }

    if (super_admin_rate_limited($email, $ip)) {
        return [
            'success' => false,
            'error'   => 'Too many failed attempts. Please wait '
                       . SUPER_ADMIN_LOGIN_WINDOW_MINUTES . ' minutes and try again.',
        ];
    }

    try {
        $stmt = db()->prepare('SELECT * FROM super_admins WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch();
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Sign-in is unavailable right now.'];
    }

    /* Verify against a dummy hash when the account is missing so a wrong
       email and a wrong password take the same time. */
    $hash = $admin['password'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $ok   = password_verify($password, $hash);

    if (!$admin || !$ok || ($admin['status'] ?? '') !== 'active') {
        record_super_admin_attempt($email, false, $ip);
        return ['success' => false, 'error' => 'Invalid email or password.'];
    }

    record_super_admin_attempt($email, true, $ip);

    session_regenerate_id(true);
    $_SESSION['super_admin_id']          = (int)$admin['id'];
    $_SESSION['super_admin_last_active'] = time();

    try {
        db()->prepare('UPDATE super_admins SET last_login_at = NOW() WHERE id = :id')
           ->execute(['id' => (int)$admin['id']]);
    } catch (Throwable $e) {
        /* Non-fatal. */
    }

    super_admin_audit('login', 'Super admin "' . $admin['email'] . '" signed in.');

    return ['success' => true];
}

/**
 * End the super admin session.
 *
 * @param bool $audit Record a logout entry (false when the session is being
 *                    torn down because the account was deactivated).
 */
function logout_super_admin(bool $audit = true): void
{
    if ($audit && is_super_admin_logged_in()) {
        super_admin_audit('logout', 'Super admin signed out.');
    }

    unset(
        $_SESSION['super_admin_id'],
        $_SESSION['super_admin_last_active']
    );
}

/* ------------------------------------------------------------------
 * Audit trail
 * ------------------------------------------------------------------ */

/**
 * Record a platform-level action.
 *
 * Survives a missing audit table (pre-migration) so it can never break the
 * action it is describing.
 */
function super_admin_audit(string $action, string $description = '', ?int $targetSalonId = null, ?string $targetSalonName = null): void
{
    try {
        $admin = current_super_admin();

        db()->prepare(
            'INSERT INTO super_admin_audit_logs
                (super_admin_id, super_admin_name, action, target_salon_id, target_salon_name, description, ip_address)
             VALUES (:aid, :aname, :action, :sid, :sname, :description, :ip)'
        )->execute([
            'aid'         => $admin['id'] ?? null,
            'aname'       => $admin['name'] ?? null,
            'action'      => $action,
            'sid'         => $targetSalonId,
            'sname'       => $targetSalonName,
            'description' => $description !== '' ? mb_substr($description, 0, 500) : null,
            'ip'          => client_ip(),
        ]);
    } catch (Throwable $e) {
        /* Never let auditing break the action. */
    }
}

/* ------------------------------------------------------------------
 * Salon lifecycle
 * ------------------------------------------------------------------ */

/**
 * Deliver an in-app notification to a salon's active administrators.
 *
 * There is no outbound email in this codebase, so this only writes
 * notification records the salon sees in its bell menu.
 */
function sa_notify_salon(int $salonId, string $title, string $message, string $type = 'info'): void
{
    if ($salonId <= 0) {
        return;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO notifications (salon_id, user_id, title, message, type)
             SELECT :sid, id, :title, :message, :type FROM users
              WHERE status = "active" AND salon_id = :sid2'
        );
        $stmt->execute([
            'sid'     => $salonId,
            'sid2'    => $salonId,
            'title'   => $title,
            'message' => $message,
            'type'    => $type,
        ]);
    } catch (Throwable $e) {
        /* Notifications must never crash a request. */
    }
}

/**
 * Move a salon to a new lifecycle status.
 *
 * @param array{reason?:string, notes?:string} $opts
 * @return array{success:bool, error?:string}
 */
function super_admin_set_status(int $salonId, string $status, array $opts = []): array
{
    if (!in_array($status, salon_statuses(), true)) {
        return ['success' => false, 'error' => 'Unknown status.'];
    }

    try {
        $stmt = db()->prepare('SELECT id, salon_name, status FROM salons WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $salonId]);
        $salon = $stmt->fetch();
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'The salon could not be loaded.'];
    }

    if (!$salon) {
        return ['success' => false, 'error' => 'Salon not found.'];
    }

    $from = (string)$salon['status'];
    if ($from === $status) {
        return ['success' => false, 'error' => 'The salon is already ' . $status . '.'];
    }

    if (!super_admin_can_transition($from, $status)) {
        return ['success' => false, 'error' => "Cannot move a salon from {$from} to {$status}."];
    }

    $admin = current_super_admin();
    $by    = $admin['id'] ?? null;

    /* The timestamp column and reason semantics depend on the target. */
    $sets   = ['status = :status', 'updated_at = NOW()'];
    $params = ['status' => $status, 'id' => $salonId];

    switch ($status) {
        case 'active':
            if ($from === 'pending' || $from === 'rejected') {
                $sets[]           = 'approved_at = NOW()';
                $sets[]           = 'approved_by = :by';
                $params['by']     = $by;
            } else {
                $sets[]           = 'restored_at = NOW()';
            }
            break;
        case 'rejected':
            $sets[]           = 'rejected_at = NOW()';
            $sets[]           = 'rejected_by = :by';
            $params['by']     = $by;
            break;
        case 'suspended':
            $sets[]           = 'suspended_at = NOW()';
            $sets[]           = 'suspended_by = :by';
            $params['by']     = $by;
            break;
        case 'cancelled':
            $sets[]           = 'cancelled_at = NOW()';
            $sets[]           = 'cancelled_by = :by';
            $params['by']     = $by;
            break;
        /* pending / inactive carry no extra marker. */
    }

    $reason = trim((string)($opts['reason'] ?? ''));
    if ($reason !== '') {
        $sets[]           = 'status_reason = :reason';
        $params['reason'] = mb_substr($reason, 0, 500);
    }

    $notes = trim((string)($opts['notes'] ?? ''));
    if ($notes !== '') {
        $sets[]          = 'admin_notes = :notes';
        $params['notes'] = mb_substr($notes, 0, 5000);
    }

    try {
        db()->prepare('UPDATE salons SET ' . implode(', ', $sets) . ' WHERE id = :id')
           ->execute($params);
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'The status change could not be saved.'];
    }

    /* Keep the salon's last-activity marker meaningful for the panel. */
    if ($status === 'active') {
        try {
            db()->prepare('UPDATE salons SET last_activity_at = NOW() WHERE id = :id')
               ->execute(['id' => $salonId]);
        } catch (Throwable $e) {
            /* Non-fatal. */
        }
    }

    $action = [
        'active'    => ($from === 'pending' || $from === 'rejected') ? 'salon_approve' : 'salon_restore',
        'rejected'  => 'salon_reject',
        'suspended' => 'salon_suspend',
        'cancelled' => 'salon_cancel',
        'pending'   => 'salon_pending',
        'inactive'  => 'salon_deactivate',
    ][$status] ?? 'salon_status';

    $phrase = [
        'active'    => ($from === 'pending' || $from === 'rejected') ? 'approved' : 'restored to active',
        'rejected'  => 'rejected',
        'suspended' => 'suspended',
        'cancelled' => 'cancelled',
        'pending'   => 'set to pending',
        'inactive'  => 'set to inactive',
    ][$status] ?? ('set to ' . $status);

    super_admin_audit(
        $action,
        sprintf('Salon #%d "%s" %s%s', $salonId, $salon['salon_name'], $phrase, $reason !== '' ? ' (' . $reason . ')' : ''),
        $salonId,
        (string)$salon['salon_name']
    );

    /* Tell the salon why its access changed. */
    $messages = [
        'active'    => 'Your salon has been approved and is now active. You can sign in.',
        'rejected'  => 'Your salon registration was not approved.' . ($reason !== '' ? ' Reason: ' . $reason : ''),
        'suspended' => 'Your salon has been suspended.' . ($reason !== '' ? ' Reason: ' . $reason : ''),
        'cancelled' => 'Your salon account has been cancelled.' . ($reason !== '' ? ' Reason: ' . $reason : ''),
        'inactive'  => 'Your salon has been set to inactive.',
        'pending'   => 'Your salon is pending review.',
    ];
    $typeMap = ['active' => 'success', 'rejected' => 'danger', 'suspended' => 'warning', 'cancelled' => 'danger'];

    sa_notify_salon(
        $salonId,
        'Salon status changed: ' . ucfirst($status),
        $messages[$status] ?? ('Status changed to ' . $status . '.'),
        $typeMap[$status] ?? 'info'
    );

    return ['success' => true];
}

/**
 * Status pill for the control panel (its own palette, independent of the
 * salon-side status_badge()).
 */
function sa_status_badge(string $status): string
{
    $status = strtolower($status);
    $valid  = in_array($status, salon_statuses(), true) ? $status : 'inactive';

    return '<span class="sa-status sa-status-' . $valid . '">' . e(ucwords($status)) . '</span>';
}

/**
 * Dashboard counters, grouped by lifecycle status.
 *
 * @return array{total:int, by_status:array<string,int>, pending:int, active:int, suspended:int}
 */
function super_admin_salon_stats(): array
{
    $byStatus = array_fill_keys(salon_statuses(), 0);
    $total    = 0;

    try {
        $stmt = db()->query('SELECT status, COUNT(*) AS c FROM salons GROUP BY status');
        foreach ($stmt->fetchAll() as $row) {
            $key = (string)$row['status'];
            if (array_key_exists($key, $byStatus)) {
                $byStatus[$key] = (int)$row['c'];
            }
            $total += (int)$row['c'];
        }
    } catch (Throwable $e) {
        /* Leave the zeroed defaults. */
    }

    return [
        'total'     => $total,
        'by_status' => $byStatus,
        'pending'   => $byStatus['pending'] ?? 0,
        'active'    => $byStatus['active'] ?? 0,
        'suspended' => $byStatus['suspended'] ?? 0,
    ];
}
