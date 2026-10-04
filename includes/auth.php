<?php
/**
 * Beauty-php-ai — Authentication & session handling.
 *
 * Only one system role exists: admin.
 */

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('BSAI_SESSID');

    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * Enforce the session timeout configured in settings.
 */
function check_session_timeout(): void
{
    $timeoutMinutes = (int)get_setting('session_timeout_minutes', 60);
    $lastActive = $_SESSION['last_active'] ?? time();

    if (time() - $lastActive > $timeoutMinutes * 60) {
        $_SESSION = [];
        session_destroy();
        start_app_session();
        flash_set('warning', 'Your session expired. Please log in again.');
        redirect(base_url('login.php'));
    }

    $_SESSION['last_active'] = time();
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function current_user(): ?array
{
    /* Keyed on the session user id rather than a bare "already loaded" flag: a
       long-lived process (CLI suite, cron, a long admin action) that switches
       session between tenants must not keep serving the previous user's row. */
    static $cache = [];

    if (!is_logged_in()) {
        return null;
    }

    $key = (string)(int)$_SESSION['user_id'];

    if (array_key_exists($key, $cache)) {
        return $cache[$key] ?: null;
    }

    try {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int)$_SESSION['user_id']]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active') {
            $cache[$key] = null;
            logout_user(false);
            return null;
        }

        $cache[$key] = $row;
        return $row;
    } catch (Throwable $e) {
        $cache[$key] = null;
        return null;
    }
}

function current_user_role(): ?string
{
    $u = current_user();
    return $u ? $u['role'] : null;
}

/**
 * Attempt to log a user in.
 *
 * @return array{success:bool, error?:string}
 */
function attempt_login(string $email, string $password, bool $remember = false): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'error' => 'Invalid login credentials.'];
    }

    if ($user['status'] !== 'active') {
        return ['success' => false, 'error' => 'Your account is inactive. Contact the administrator.'];
    }

    /* Multi-tenant: refuse a login whose account has no tenant, otherwise the
       session would carry no salon_id and every module query would be
       unscoped. Re-checked here rather than trusting the column to exist. */
    $salonId = (int)($user['salon_id'] ?? 0);
    if ($salonId <= 0) {
        return [
            'success' => false,
            'error'   => 'Your account is not linked to a salon. Please contact support.',
        ];
    }

    try {
        $salonStmt = db()->prepare('SELECT status FROM salons WHERE id = :id LIMIT 1');
        $salonStmt->execute(['id' => $salonId]);
        $salonStatus = $salonStmt->fetchColumn();

        if ($salonStatus !== 'active') {
            return [
                'success' => false,
                'error'   => 'This salon is not active. Please contact support.',
            ];
        }
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Your salon could not be verified. Please try again.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['salon_id']   = $salonId;
    $_SESSION['last_active'] = time();

    if ($remember) {
        create_remember_token($user['id']);
    }

    log_activity('login', 'auth', "User {$user['name']} logged in");

    return ['success' => true];
}

function create_remember_token(int $userId): void
{
    $token = bin2hex(random_bytes(32));

    $stmt = db()->prepare(
        'INSERT INTO remember_tokens (user_id, token, expires_at)
         VALUES (:uid, :token, DATE_ADD(NOW(), INTERVAL 30 DAY))'
    );
    $stmt->execute(['uid' => $userId, 'token' => $token]);

    setcookie('BSAI_REMEMBER', $token, [
        'expires'  => time() + 60 * 60 * 24 * 30,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function try_remember_login(): void
{
    if (is_logged_in() || empty($_COOKIE['BSAI_REMEMBER'])) {
        return;
    }

    try {
        $stmt = db()->prepare(
            'SELECT u.* FROM remember_tokens rt
             JOIN users u ON u.id = rt.user_id
             WHERE rt.token = :token AND rt.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['token' => $_COOKIE['BSAI_REMEMBER']]);
        $user = $stmt->fetch();

        /* Same tenant requirement as attempt_login(): a remembered session
           without a salon would be unscoped. */
        if ($user && $user['status'] === 'active' && (int)($user['salon_id'] ?? 0) > 0) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['salon_id']  = (int)$user['salon_id'];
            $_SESSION['last_active'] = time();
        }
    } catch (Throwable $e) {
        // Ignore stale / invalid remember tokens.
    }
}

/* ------------------------------------------------------------------
 * Administrator account creation
 * ------------------------------------------------------------------ */

/**
 * Create a staff login account with the `admin` role.
 *
 * Shared by the first-time Setup Wizard and by the Settings screen, so
 * both paths validate, hash and name the row the same way. `users.name`
 * stays the composed display name; first_name / last_name are stored
 * separately so a name is never split by guesswork after the fact.
 *
 * @param array<string,mixed> $data first_name, last_name, email, phone, password, role, status, salon_id
 * @return array{success:bool, error?:string, errors?:array<int,string>, user_id?:int, salon_id?:int}
 */
function create_admin_account(array $data, string $role = 'admin'): array
{
    $first    = trim((string)($data['first_name'] ?? ''));
    $last     = trim((string)($data['last_name'] ?? ''));
    $email    = strtolower(trim((string)($data['email'] ?? '')));
    $phone    = trim((string)($data['phone'] ?? ''));
    $password = (string)($data['password'] ?? '');
    /* Resolve once, then validate. Reading $data['status'] again inside the
       true branch raised an undefined-key warning whenever the caller omitted
       it — and setup.php does omit it — so status became NULL and the very
       first Administrator row was rejected by the NOT NULL constraint. */
    $statusIn = (string)($data['status'] ?? 'active');
    $status   = in_array($statusIn, ['active', 'inactive'], true) ? $statusIn : 'active';
    $errors   = [];

    if ($first === '') {
        $errors[] = 'First name is required.';
    } elseif (mb_strlen($first) > 100) {
        $errors[] = 'First name must be 100 characters or fewer.';
    }

    if ($last === '') {
        $errors[] = 'Last name is required.';
    } elseif (mb_strlen($last) > 100) {
        $errors[] = 'Last name must be 100 characters or fewer.';
    }

    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }

    /* Multi-tenant: an account with no salon can never be scoped, so refuse
       to create one. Callers inside a salon pass salon_id explicitly; the
       onboarding flow passes the salon it just created in the same
       transaction. */
    $salonId = (int)($data['salon_id'] ?? 0);
    if ($salonId <= 0) {
        $errors[] = 'The account must be linked to a salon.';
    }

    if ($errors) {
        return ['success' => false, 'error' => implode(' ', $errors), 'errors' => $errors];
    }

    try {
        /* Email stays globally unique: it is the login identity, so two
           salons cannot share one administrator address. */
        $exists = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $exists->execute(['email' => $email]);

        if ($exists->fetch()) {
            return [
                'success' => false,
                'error'   => 'An account already uses that email address. Please choose another.',
                'errors'  => ['An account already uses that email address. Please choose another.'],
            ];
        }

        $stmt = db()->prepare(
            'INSERT INTO users (name, first_name, last_name, email, phone, password, role, status, salon_id, created_at, updated_at)
             VALUES (:name, :first, :last, :email, :phone, :password, :role, :status, :salon_id, NOW(), NOW())'
        );

        $stmt->execute([
            'name'     => $first . ' ' . $last,
            'first'    => $first,
            'last'     => $last,
            'email'    => $email,
            'phone'    => $phone !== '' ? $phone : null,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role'     => $role === 'receptionist' ? 'receptionist' : 'admin',
            'status'   => $status,
            'salon_id' => $salonId,
        ]);
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'The account could not be created. Please try again.'];
    }

    $userId = (int)db()->lastInsertId();

    log_activity('create', 'users', "Created {$role} account {$email}");

    return ['success' => true, 'user_id' => $userId, 'salon_id' => $salonId];
}

/**
 * Count the accounts that may sign in to the back office.
 */
function admin_user_count(bool $includeInactive = true, ?int $salonId = null): int
{
    try {
        /* Scoped to one salon: a tenant's Administrator count must not be
           inflated by every other business on the install. */
        if ($salonId === null) {
            $salonId = current_salon_id();
        }

        if ($salonId === null || $salonId <= 0) {
            return 0;
        }

        $sql = 'SELECT COUNT(*) AS c FROM users WHERE role = "admin" AND salon_id = :sid'
             . ($includeInactive ? '' : ' AND status = "active"');

        $stmt = db()->prepare($sql);
        $stmt->execute(['sid' => $salonId]);

        return (int)($stmt->fetch()['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/* ------------------------------------------------------------------
 * Customer authentication
 * ------------------------------------------------------------------ */

function is_customer_logged_in(): bool
{
    return isset($_SESSION['customer_id']);
}

function current_customer(): ?array
{
    static $customer = false;
    if ($customer !== false) {
        return $customer ?: null;
    }
    if (!is_customer_logged_in()) {
        $customer = null;
        return null;
    }
    try {
        $stmt = db()->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $_SESSION['customer_id']]);
        $row = $stmt->fetch();
        if (!$row) {
            $customer = null;
            unset($_SESSION['customer_id']);
            return null;
        }
        $customer = $row;
        return $row;
    } catch (Throwable $e) {
        $customer = null;
        return null;
    }
}

function attempt_customer_login(string $email, string $password): array
{
    $stmt = db()->prepare('SELECT * FROM customers WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $customer = $stmt->fetch();

    if (!$customer) {
        return ['success' => false, 'error' => 'No account found with that email.'];
    }

    if (empty($customer['password']) || !password_verify($password, $customer['password'])) {
        return ['success' => false, 'error' => 'Invalid email or password.'];
    }

    session_regenerate_id(true);
    $_SESSION['customer_id']   = $customer['id'];
    $_SESSION['last_active']   = time();

    log_activity('customer_login', 'auth', "Customer {$customer['first_name']} {$customer['last_name']} logged in");

    return ['success' => true];
}

function register_customer(string $firstName, string $lastName, string $phone, string $email, string $password): array
{
    $email = trim($email);
    if ($email !== '') {
        $stmt = db()->prepare('SELECT id FROM customers WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'An account with that email already exists.'];
        }
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);

    $stmt = db()->prepare(
        'INSERT INTO customers (first_name, last_name, phone, email, password, created_at)
         VALUES (:fn, :ln, :phone, :email, :pw, NOW())'
    );
    $stmt->execute([
        'fn'    => $firstName,
        'ln'    => $lastName,
        'phone' => $phone,
        'email' => $email ?: null,
        'pw'    => $hashed,
    ]);

    $customerId = (int)db()->lastInsertId();

    session_regenerate_id(true);
    $_SESSION['customer_id'] = $customerId;
    $_SESSION['last_active'] = time();

    log_activity('customer_register', 'auth', "New customer registered: {$firstName} {$lastName}");

    return ['success' => true, 'customer_id' => $customerId];
}

function logout_customer(): void
{
    if (is_customer_logged_in()) {
        log_activity('customer_logout', 'auth', 'Customer logged out');
    }
    unset($_SESSION['customer_id']);
}

function logout_user(bool $destroy = true): void
{
    if (is_logged_in()) {
        log_activity('logout', 'auth', 'User logged out');
    }

    if (!empty($_COOKIE['BSAI_REMEMBER'])) {
        try {
            $stmt = db()->prepare('DELETE FROM remember_tokens WHERE token = :token');
            $stmt->execute(['token' => $_COOKIE['BSAI_REMEMBER']]);
        } catch (Throwable $e) {
        }
        setcookie('BSAI_REMEMBER', '', time() - 3600, '/');
    }

    if ($destroy) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
