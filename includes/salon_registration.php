<?php
/**
 * Beauty-php-ai — Salon onboarding.
 *
 * Public self-service registration: one submission creates a `salons` row, its
 * default weekly hours, and the Administrator account that owns it, inside a
 * single transaction. Either all three exist afterwards or none do.
 *
 * The "already registered" rule is enforced here rather than by hiding links
 * in the UI: an Administrator is bound to exactly one salon for the life of the
 * account, so a second registration with the same email is rejected by the
 * global unique index on users.email and reported as a clear message.
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

/**
 * Is this email already an Administrator or receptionist account?
 *
 * Used to show the "you already have an account" guidance before the user
 * finishes typing, and to fail fast in the transaction.
 */
function email_taken_by_staff(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    try {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Is this salon name already in use?
 *
 * Names are not forced unique across tenants, but flagging a duplicate before
 * submission avoids two businesses confusing each other over login and email.
 */
function salon_name_taken(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }

    try {
        $stmt = db()->prepare('SELECT id FROM salons WHERE salon_name = :n LIMIT 1');
        $stmt->execute(['n' => $name]);

        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * The trading week a brand-new salon starts with.
 *
 * The signup form deliberately does not ask for a schedule: it is a lot of
 * input for someone whose business does not exist yet, and it used to be the
 * only thing standing between a new salon and an empty availability calendar.
 * A new salon instead starts Monday-Saturday 09:00-18:00 with Sunday closed,
 * which is bookable immediately, and the owner refines it from
 * Settings -> Opening Hours.
 *
 * @return array<int,array{is_open:int, opening_time:string, closing_time:string}>
 */
function default_weekly_hours(): array
{
    $hours = [];

    for ($day = 1; $day <= 7; $day++) {
        $isOpen = $day !== 7;   /* Monday-Saturday */
        $hours[$day] = [
            'is_open'      => $isOpen ? 1 : 0,
            'opening_time' => $isOpen ? '09:00' : '',
            'closing_time' => $isOpen ? '18:00' : '',
        ];
    }

    return $hours;
}

/**
 * The hours a submission should be held to: the caller's own week when it
 * supplied one, otherwise the default week.
 *
 * Resolving this in one place keeps validate_salon_registration() and
 * register_salon() from ever disagreeing about what the salon will be given.
 *
 * @param array<string,mixed> $in
 * @return array<int,array<string,mixed>>
 */
function effective_weekly_hours(array $in): array
{
    $hours = (array)($in['hours'] ?? []);

    return $hours ? $hours : default_weekly_hours();
}

/**
 * Validate an onboarding submission without writing anything.
 *
 * @param array<string,mixed> $in
 * @return array<int,string>
 */
function validate_salon_registration(array $in): array
{
    $errors = [];

    $name = trim((string)($in['salon_name'] ?? ''));
    if ($name === '') {
        $errors[] = 'Salon name is required.';
    } elseif (mb_strlen($name) > 150) {
        $errors[] = 'Salon name must be 150 characters or fewer.';
    }

    $first = trim((string)($in['first_name'] ?? ''));
    if ($first === '') {
        $errors[] = 'First name is required.';
    } elseif (mb_strlen($first) > 100) {
        $errors[] = 'First name must be 100 characters or fewer.';
    }

    $last = trim((string)($in['last_name'] ?? ''));
    if ($last === '') {
        $errors[] = 'Last name is required.';
    } elseif (mb_strlen($last) > 100) {
        $errors[] = 'Last name must be 100 characters or fewer.';
    }

    $email = strtolower(trim((string)($in['email'] ?? '')));
    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (email_taken_by_staff($email)) {
        /* The explicit "no duplicate registration" rule. Checked before the
           salon-name clash so a user re-submitting their own details is told
           the account already exists rather than being sent off to rename
           their business. */
        $errors[] = 'An account already exists with that email address. '
                  . 'Please sign in instead of registering again.';
    } elseif (salon_name_taken($name)) {
        $errors[] = 'A salon with that name is already registered. Please choose another.';
    }

    $password = (string)($in['password'] ?? '');
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif ($password !== (string)($in['password_confirm'] ?? '')) {
        $errors[] = 'The two passwords do not match.';
    }

    /* Weekly hours: the signup form no longer collects a schedule, so a caller
       that supplies none gets default_weekly_hours(). When one is supplied,
       every one of the seven days must be a valid choice, and an open day needs
       a start earlier than its end. */
    $hours = effective_weekly_hours($in);
    if (count($hours) !== 7) {
        $errors[] = 'Please provide opening hours for all seven days.';
    } else {
        for ($day = 1; $day <= 7; $day++) {
            $row = (array)($hours[$day] ?? []);
            $isOpen = !empty($row['is_open']);

            if (!$isOpen) {
                continue;
            }

            $open  = trim((string)($row['opening_time'] ?? ''));
            $close = trim((string)($row['closing_time'] ?? ''));

            if ($open === '' || $close === '') {
                $errors[] = 'An open day needs both an opening and a closing time.';
                continue;
            }

            if (!preg_match('/^\d{2}:\d{2}$/', $open) || !preg_match('/^\d{2}:\d{2}$/', $close)) {
                $errors[] = 'Opening hours must use the 24-hour HH:MM format.';
                continue;
            }

            if ($open >= $close) {
                $errors[] = 'Closing time must be later than opening time on every open day.';
                break;
            }
        }
    }

    return $errors;
}

/**
 * Create the salon, its hours and its Administrator in one transaction.
 *
 * The transaction matters most for the duplicate-email race: two simultaneous
 * submissions with the same address would otherwise leave one orphaned salon
 * with no owner. The unique index rejects the second insert and the rollback
 * removes its salon row.
 *
 * @param array<string,mixed> $in
 * @return array{success:bool, error?:string, errors?:array<int,string>, salon_id?:int, user_id?:int}
 */
function register_salon(array $in): array
{
    $errors = validate_salon_registration($in);
    if ($errors) {
        return ['success' => false, 'error' => $errors[0], 'errors' => $errors];
    }

    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();

    if ($ownTransaction) {
        $pdo->beginTransaction();
    }

    try {
        /* ---- salon ---- */
        $salonName = trim((string)$in['salon_name']);
        $first     = trim((string)$in['first_name']);
        $last      = trim((string)$in['last_name']);
        $email     = strtolower(trim((string)$in['email']));
        $phone     = trim((string)($in['phone'] ?? ''));

        $salonStmt = $pdo->prepare(
            'INSERT INTO salons
                (salon_name, owner_first_name, owner_last_name, email, phone, status, setup_completed_at)
             VALUES (:name, :of, :ol, :email, :phone, \'active\', NOW())'
        );
        $salonStmt->execute([
            'name'  => $salonName,
            'of'    => $first,
            'ol'    => $last,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
        ]);

        $salonId = (int)$pdo->lastInsertId();

        /* ---- default weekly hours ---- */
        $hours = effective_weekly_hours($in);
        $hoursStmt = $pdo->prepare(
            'INSERT INTO business_hours (salon_id, day_of_week, is_open, opening_time, closing_time)
             VALUES (:sid, :day, :open, :from, :to)'
        );

        for ($day = 1; $day <= 7; $day++) {
            $row = (array)($hours[$day] ?? []);
            $isOpen = !empty($row['is_open']);

            $hoursStmt->execute([
                'sid'  => $salonId,
                'day'  => $day,
                'open' => $isOpen ? 1 : 0,
                'from' => $isOpen ? $row['opening_time'] . ':00' : null,
                'to'   => $isOpen ? $row['closing_time'] . ':00' : null,
            ]);
        }

        /* ---- salon_settings mirror row ---- */
        $settingsStmt = $pdo->prepare(
            'INSERT INTO salon_settings (salon_id, salon_name, owner_first_name, owner_last_name, setup_completed_at)
             VALUES (:sid, :name, :of, :ol, NOW())'
        );
        $settingsStmt->execute([
            'sid'  => $salonId,
            'name' => $salonName,
            'of'   => $first,
            'ol'   => $last,
        ]);

        /* ---- the owning Administrator ---- */
        $account = create_admin_account([
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $email,
            'phone'      => $phone,
            'password'   => (string)$in['password'],
            'status'     => 'active',
            'salon_id'   => $salonId,
        ], 'admin');

        if (!$account['success']) {
            throw new RuntimeException((string)($account['error'] ?? 'The account could not be created.'));
        }

        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        /* Duplicate email is the expected failure here. Report it as the
           registration rule rather than as a server error. */
        if (str_contains($e->getMessage(), 'uq_users_email')) {
            return [
                'success' => false,
                'error'   => 'An account already exists with that email address. '
                           . 'Please sign in instead of registering again.',
                'errors'  => ['An account already exists with that email address. '
                            . 'Please sign in instead of registering again.'],
            ];
        }

        return [
            'success' => false,
            'error'   => 'The salon could not be registered. Please try again.',
        ];
    }

    log_activity('create', 'salons', "Registered salon '{$salonName}'");

    return [
        'success'  => true,
        'salon_id' => $salonId,
        'user_id'  => (int)$account['user_id'],
    ];
}