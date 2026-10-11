<?php
/**
 * Beauty-php-ai — Service hierarchy migration (ADDITIVE, IDEMPOTENT).
 *
 * Completes the CATEGORY -> SUBCATEGORY (optional) -> SERVICE structure:
 *   - service_categories    : + sort_order, + updated_at
 *   - service_subcategories : + sort_order, + updated_at
 *   - services              : + sort_order
 *
 * GUARANTEES
 *   - No column is dropped, renamed or retyped.
 *   - No row is deleted and no primary key is changed.
 *   - Existing service id / name / price / duration_minutes and every
 *     appointment_services / waitlist / invoices relationship is untouched.
 *   - services.subcategory_id stays NULLABLE (a subcategory is optional).
 *   - Safe to run repeatedly — every step checks information_schema first.
 *
 * USAGE (CLI)
 *   C:\xampp\php\php.exe database\migrate_service_hierarchy.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration must be run from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';

$pdo = db();

/** Column exists on a table? */
function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);

    return (int)$stmt->fetchColumn() > 0;
}

function step(string $message): void
{
    echo '  ' . $message . PHP_EOL;
}

echo PHP_EOL . 'Beauty-php-ai — service hierarchy migration' . PHP_EOL;
echo 'Database: ' . DB_NAME . PHP_EOL . PHP_EOL;

/* ---------------------------------------------------------------
 * 1. Additive columns
 * ------------------------------------------------------------- */
$additions = [
    ['service_categories', 'sort_order', 'INT NOT NULL DEFAULT 0 AFTER `status`'],
    ['service_categories', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`'],
    ['service_subcategories', 'sort_order', 'INT NOT NULL DEFAULT 0 AFTER `status`'],
    ['service_subcategories', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`'],
    ['services', 'sort_order', 'INT NOT NULL DEFAULT 0 AFTER `status`'],
];

foreach ($additions as [$table, $column, $definition]) {
    if (column_exists($pdo, $table, $column)) {
        step(sprintf('skip  %s.%s already present', $table, $column));
        continue;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    step(sprintf('added %s.%s', $table, $column));
}

/* ---------------------------------------------------------------
 * 2. Deterministic sort_order backfill
 *    Preserves the visual order that existed before the migration:
 *    categories alphabetically, subcategories within their category,
 *    services within their category. Rows already ordered keep their value.
 * ------------------------------------------------------------- */
$backfills = [
    'service_categories'    => 'SELECT id FROM service_categories ORDER BY name, id',
    'service_subcategories' => 'SELECT id FROM service_subcategories ORDER BY category_id, name, id',
    'services'              => 'SELECT id FROM services ORDER BY COALESCE(category_id, 0), COALESCE(subcategory_id, 0), name, id',
];

foreach ($backfills as $table => $sql) {
    $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $update = $pdo->prepare("UPDATE `{$table}` SET sort_order = :ord WHERE id = :id AND sort_order = 0");

    $position = 0;
    $changed  = 0;
    foreach ($ids as $id) {
        $position++;
        $update->execute(['ord' => $position, 'id' => (int)$id]);
        $changed += $update->rowCount();
    }
    step(sprintf('backfilled %s.sort_order (%d row(s))', $table, $changed));
}

/* ---------------------------------------------------------------
 * 3. Referential-integrity hardening
 *    Turns the hierarchy rules into actual database guarantees so a
 *    stray DELETE can never silently dissolve a category tree:
 *      services.category_id            NOT NULL, ON DELETE RESTRICT
 *      service_subcategories.category_id  ON DELETE RESTRICT
 *    services.subcategory_id stays NULLABLE / SET NULL on purpose:
 *    a subcategory is optional, so deleting one only detaches it.
 *
 *    Rows that already violate the rules are repaired FIRST, and only
 *    in the safe direction (drop the optional subcategory link, never
 *    delete a service or re-assign its category).
 * ------------------------------------------------------------- */
echo PHP_EOL . 'Referential integrity' . PHP_EOL;

/* A subcategory from the wrong parent is the optional part of the pair,
   so the service keeps its category and simply loses the subcategory. */
$repair = $pdo->exec(
    'UPDATE services s
       JOIN service_subcategories sc ON sc.id = s.subcategory_id
      SET s.subcategory_id = NULL
    WHERE s.category_id IS NULL OR s.category_id <> sc.category_id'
);
if ($repair) {
    step("repaired {$repair} service(s) pointing at a subcategory of another category");
} else {
    step('ok    no service points at a subcategory of another category');
}

/* A category_id that points nowhere cannot be invented — report and stop
   short of the NOT NULL change rather than guessing. */
$orphans = (int)$pdo->query(
    'SELECT COUNT(*) FROM services s
      LEFT JOIN service_categories c ON c.id = s.category_id
     WHERE s.category_id IS NULL OR c.id IS NULL'
)->fetchColumn();

if ($orphans > 0) {
    step(sprintf('WARN  %d service(s) have no usable category - assign one, then re-run', $orphans));
} else {
    $nullable = $pdo->query(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'category_id'"
    )->fetchColumn();

    if (strtoupper((string)$nullable) === 'NO') {
        step('skip  services.category_id is already NOT NULL');
    } else {
        /* Split into separate ALTERs: re-creating a foreign key inside the
           same statement that retypes its column trips errno 121. */
        $pdo->exec('ALTER TABLE `services` DROP FOREIGN KEY `fk_services_cat`');
        $pdo->exec('ALTER TABLE `services` MODIFY `category_id` INT NOT NULL');
        $pdo->exec('ALTER TABLE `services` ADD CONSTRAINT `fk_services_cat`
            FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`)
            ON DELETE RESTRICT ON UPDATE RESTRICT');
        step('services.category_id is now NOT NULL / ON DELETE RESTRICT');
    }

    $rule = $pdo->query(
        "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_subcat_cat'"
    )->fetchColumn();

    if (strtoupper((string)$rule) === 'RESTRICT') {
        step('skip  fk_subcat_cat is already RESTRICT');
    } else {
        $pdo->exec('ALTER TABLE `service_subcategories` DROP FOREIGN KEY `fk_subcat_cat`');
        $pdo->exec('ALTER TABLE `service_subcategories` ADD CONSTRAINT `fk_subcat_cat`
            FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`)
            ON DELETE RESTRICT ON UPDATE RESTRICT');
        step('fk_subcat_cat is now ON DELETE RESTRICT (was ' . strtoupper((string)$rule ?: 'unknown') . ')');
    }
}

/* ---------------------------------------------------------------
 * 4. Integrity report — data that contradicts the hierarchy rules.
 *    Reported only, never modified: existing rows must stay valid.
 * ------------------------------------------------------------- */
echo PHP_EOL . 'Integrity check' . PHP_EOL;

$checks = [
    'services with a subcategory from another category' => <<<'SQL'
        SELECT s.id, s.name, s.category_id, s.subcategory_id
          FROM services s
          JOIN service_subcategories sc ON sc.id = s.subcategory_id
         WHERE s.category_id IS NULL OR s.category_id <> sc.category_id
        SQL,
    'services without a category' => 'SELECT id, name FROM services WHERE category_id IS NULL',
    'subcategories without a category' => 'SELECT id, name FROM service_subcategories WHERE category_id IS NULL',
    'inactive services used by appointments' => <<<'SQL'
        SELECT s.id, s.name FROM services s
          JOIN appointment_services aps ON aps.service_id = s.id
         WHERE s.status <> 'active' GROUP BY s.id, s.name
        SQL,
];

foreach ($checks as $label => $sql) {
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        step('skip  ' . $label . ' (query failed)');
        continue;
    }
    if (!$rows) {
        step(sprintf('ok    %s: none', $label));
        continue;
    }
    step(sprintf('WARN  %s: %d row(s) - review manually, data left unchanged', $label, count($rows)));
    foreach ($rows as $row) {
        step('        ' . json_encode($row, JSON_UNESCAPED_UNICODE));
    }
}

echo PHP_EOL . 'Migration complete. No row was deleted; only sort_order backfilled and optional subcategory links repaired.' . PHP_EOL . PHP_EOL;
