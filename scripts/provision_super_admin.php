<?php
/**
 * Beauty-php-ai — Provision a Super Admin account (CLI).
 *
 * There is no public super admin registration, by design: the first
 * platform operator is created here, on the server, by someone with access
 * to the database. Re-running with an existing email resets that account's
 * name/password and reactivates it.
 *
 * USAGE (CLI)
 *   C:\xampp\php\php.exe scripts\provision_super_admin.php --email=you@example.com --name="Your Name"
 *        (a strong password is generated and printed once)
 *
 *   C:\xampp\php\php.exe scripts\provision_super_admin.php --email=you@example.com --name="Your Name" --password="S3cret-and-long"
 *
 * The database write guard applies: pass BEAUTY_DB_ALLOW_WRITE=1 (the same
 * token the test suite uses) so this deliberate write is explicitly allowed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';

/**
 * Read a --key=value argument.
 */
function arg(string $key): ?string
{
    foreach ($_SERVER['argv'] ?? [] as $item) {
        if (str_starts_with($item, '--' . $key . '=')) {
            return substr($item, strlen($key) + 3);
        }
    }

    return null;
}

echo PHP_EOL . 'Beauty-php-ai — provision super admin' . PHP_EOL;
echo 'Database: ' . DB_NAME . PHP_EOL . PHP_EOL;

if (!db_writes_allowed()) {
    fwrite(STDERR, "This script writes to the database. Re-run with BEAUTY_DB_ALLOW_WRITE=1.\n");
    exit(3);
}

$email = strtolower(trim((string)arg('email')));
$name  = trim((string)arg('name'));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid --email= address is required.\n");
    exit(2);
}

if ($name === '') {
    /* Derive a display name from the local part so the script is usable
       with just an email. */
    $name = ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $email)[0]));
}

$password = (string)arg('password');
$generated = false;

if ($password === '') {
    $password  = bin2hex(random_bytes(9)); // 18 hex chars
    $generated = true;
} elseif (strlen($password) < 10) {
    fwrite(STDERR, "The password must be at least 10 characters long.\n");
    exit(2);
}

try {
    $pdo = db();

    $exists = $pdo->prepare('SELECT id FROM super_admins WHERE email = :email LIMIT 1');
    $exists->execute(['email' => $email]);
    $id = (int)$exists->fetchColumn();

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($id > 0) {
        $pdo->prepare('UPDATE super_admins SET name = :name, password = :pw, status = "active" WHERE id = :id')
            ->execute(['name' => $name, 'pw' => $hash, 'id' => $id]);
        echo "Updated existing super admin #{$id} ({$email}).\n";
    } else {
        $pdo->prepare('INSERT INTO super_admins (name, email, password, status) VALUES (:name, :email, :pw, "active")')
            ->execute(['name' => $name, 'email' => $email, 'pw' => $hash]);
        $id = (int)$pdo->lastInsertId();
        echo "Created super admin #{$id} ({$email}).\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . PHP_EOL);

    if (str_contains($e->getMessage(), 'super_admins')) {
        fwrite(STDERR, "Have you applied database/migrations/004_super_admin.sql yet?\n");
    }

    exit(1);
}

echo PHP_EOL;

if ($generated) {
    echo "Generated password (store it now, it is not shown again):\n";
    echo '  ' . $password . PHP_EOL . PHP_EOL;
}

echo "Sign in at " . rtrim((string)(getenv('APP_URL') ?: ''), '/') . "/super-admin/login.php\n";
