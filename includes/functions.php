<?php
/**
 * Beauty-php-ai — Global helpers.
 */

/* ------------------------------------------------------------------
 * Output escaping
 * ------------------------------------------------------------------ */

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------------
 * Asset / base URL helper
 * ------------------------------------------------------------------ */

/**
 * Returns a URL-relative prefix so assets resolve correctly from both
 * the project root and from sub-folders (admin/, receptionist/).
 */
function base_url(string $path = ''): string
{
    static $base = null;

    if ($base === null) {
        $rootDir   = realpath(dirname(__DIR__));
        $scriptDir = realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__));

        $depth = 0;
        $dir   = $scriptDir;
        while ($dir && $rootDir && realpath($dir) !== $rootDir && strpos(realpath($dir), $rootDir) === 0) {
            $depth++;
            $dir = dirname($dir);
        }

        $base = str_repeat('../', $depth);
    }

    return $base . $path;
}

/* ------------------------------------------------------------------
 * Redirects & flash messages
 * ------------------------------------------------------------------ */

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/* ------------------------------------------------------------------
 * Form input preservation
 *
 * A rejected submission redirects back to the form. Without this the
 * receptionist / administrator would have to retype everything, so the
 * submitted values are parked in the session for exactly one render.
 * ------------------------------------------------------------------ */

/**
 * Remember submitted input so the next render can repopulate the form.
 * Nested keys are flattened to "a[b]" so they map back onto $_POST.
 */
function form_remember(array $input): void
{
    $_SESSION['form_old'] = ['at' => time(), 'input' => $input];
}

/**
 * Read (and clear) remembered input.
 *
 * @return array<string,mixed>
 */
function form_old_take(): array
{
    $stored = $_SESSION['form_old'] ?? null;
    unset($_SESSION['form_old']);

    if (!is_array($stored) || !isset($stored['input']) || !is_array($stored['input'])) {
        return [];
    }

    /* Anything older than 10 minutes is stale — drop it. */
    if ((time() - (int)($stored['at'] ?? 0)) > 600) {
        return [];
    }

    $flat = [];
    array_walk_recursive($stored['input'], static function ($value, $key) use (&$flat) {
        $flat[$key] = $value;
    });

    return $flat;
}

/**
 * One remembered value, or $default when absent/empty.
 *
 * @param array<string,mixed> $flat
 * @return mixed
 */
function form_old(array $flat, string $key, $default = null)
{
    return isset($flat[$key]) && $flat[$key] !== '' ? $flat[$key] : $default;
}

/* ------------------------------------------------------------------
 * CSRF protection
 * ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && hash_equals(csrf_token(), $sent);
}

/* ------------------------------------------------------------------
 * Formatting helpers
 * ------------------------------------------------------------------ */

function money($amount): string
{
    return number_format((float)$amount, 2);
}

function currency(): string
{
    return get_setting('currency', 'ETB');
}

function fmt_money($amount): string
{
    return money($amount) . ' ' . currency();
}

function fmt_time($time): string
{
    if (!$time) {
        return '—';
    }
    return date('g:i A', strtotime((string)$time));
}

function fmt_date($date): string
{
    if (!$date) {
        return '—';
    }
    return date('M j, Y', strtotime((string)$date));
}

function fmt_datetime($datetime): string
{
    if (!$datetime) {
        return '—';
    }
    return date('M j, Y g:i A', strtotime((string)$datetime));
}

function time_ago($datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $diff = time() - (int)strtotime((string)$datetime);
    if ($diff < 60) {
        return 'just now';
    }
    $mins = (int)floor($diff / 60);
    if ($mins < 60) {
        return $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
    }
    $hours = (int)floor($mins / 60);
    if ($hours < 24) {
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }
    $days = (int)floor($hours / 24);
    if ($days < 7) {
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }
    return date('M j, Y', (int)strtotime((string)$datetime));
}

function time_seconds($time): int
{
    return (int)strtotime((string)$time) ?: 0;
}

function minutes_to_duration(int $minutes): string
{
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $m > 0 ? "{$h}h {$m}m" : "{$h} hr";
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '') {
            $out .= mb_substr($p, 0, 1);
        }
    }
    return strtoupper($out ?: '?');
}

/* ------------------------------------------------------------------
 * Settings
 * ------------------------------------------------------------------ */

function &_settings_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Which salon's settings are being read or written?
 *
 * Settings are per-salon since migration 002 (uq_setting_key became
 * (salon_id, setting_key)). Falls back to the lowest-id salon for the public
 * pages and the CLI harness, which legitimately have no session.
 */
function settings_salon_id(): int
{
    if (function_exists('default_salon_id')) {
        $id = default_salon_id();
        if ($id > 0) {
            return $id;
        }
    }

    try {
        return (int)(db()->query('SELECT id FROM salons ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function get_setting(string $key, $default = null)
{
    /* Cache lives in a global keyed by salon id, because a process may serve
       two tenants in turn (CLI suite, long-running job) and a function-static
       could not be invalidated from save_setting(). */
    $salonId = settings_salon_id();

    if ($salonId <= 0) {
        return $default;
    }

    $cache =& _settings_cache();

    if (($cache['_generation'] ?? -1) !== settings_cache_generation()
        || !isset($cache['_salons'][$salonId])) {
        /* Generation moved (a write happened) or this salon is not cached yet. */
        $loaded = [];
        try {
            $stmt = db()->prepare(
                'SELECT setting_key, setting_value FROM settings WHERE salon_id = :sid'
            );
            $stmt->execute(['sid' => $salonId]);
            foreach ($stmt->fetchAll() as $row) {
                $loaded[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            // Database not available yet — fall back to defaults.
        }

        if (!isset($cache['_salons']) || !is_array($cache['_salons'])) {
            $cache['_salons'] = [];
        }
        $cache['_salons'][$salonId] = $loaded;
        $cache['_generation'] = settings_cache_generation();
    }

    $value = $cache['_salons'][$salonId][$key] ?? null;

    return ($value !== null && $value !== '') ? $value : $default;
}

/**
 * Returns every setting as an associative array (key => value).
 */
function get_settings_map(): array
{
    $salonId = settings_salon_id();
    if ($salonId <= 0) {
        return [];
    }

    try {
        $stmt = db()->prepare('SELECT setting_key, setting_value FROM settings WHERE salon_id = :sid');
        $stmt->execute(['sid' => $salonId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Saves (inserts or updates) a single setting for the active salon.
 */
function save_setting(string $key, $value): void
{
    $salonId = settings_salon_id();

    if ($salonId <= 0) {
        throw new RuntimeException('save_setting(): no salon context. Refusing to write unscoped settings.');
    }

    db()->prepare(
        'INSERT INTO settings (salon_id, setting_key, setting_value) VALUES (:sid, :key, :value)
         ON DUPLICATE KEY UPDATE setting_value = :value2'
    )->execute([
        'sid'   => $salonId,
        'key'   => $key,
        'value' => (string)$value,
        'value2' => (string)$value,
    ]);

    /* Invalidate the cache for this salon so the next get_setting() re-reads. */
    forget_settings_cache($salonId);
}

/**
 * Drop cached settings for one salon, or for all when called with no id.
 */
function forget_settings_cache(?int $salonId = null): void
{
    /* get_setting() keeps its cache in a function-static, which cannot be
       reached from here, so this records the invalidation and get_setting()
       consults it. Implemented via a global generation counter. */
    $GLOBALS['__settings_cache_generation'] = settings_cache_generation() + 1;
}

/**
 * Monotonic counter bumped by forget_settings_cache().
 */
function settings_cache_generation(): int
{
    return (int)($GLOBALS['__settings_cache_generation'] ?? 0);
}

function salon_name(): string
{
    /* salon_settings is the source of truth; the key/value mirror is
       the fallback for a database the migration has not touched yet. */
    if (function_exists('salon_profile')) {
        $profile = salon_profile();

        if (is_array($profile) && trim((string)($profile['salon_name'] ?? '')) !== '') {
            return (string)$profile['salon_name'];
        }
    }

    /* No salon name is baked in anywhere: §40 requires the saved value, and a
       neutral fallback keeps a not-yet-configured install from advertising
       some other business's name. */
    $mirrored = trim((string)get_setting('salon_name', ''));

    return $mirrored !== '' ? $mirrored : 'Your Salon';
}

function salon_address(): string
{
    return get_setting('salon_address', 'Bole Road, Addis Ababa, Ethiopia');
}

/* ------------------------------------------------------------------
 * Audit logging
 * ------------------------------------------------------------------ */

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function log_activity(string $action, string $module, string $description = ''): void
{
    try {
        $user = current_user();
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (user_id, action, module, description, ip_address)
             VALUES (:user_id, :action, :module, :description, :ip)'
        );
        $stmt->execute([
            'user_id'     => $user ? $user['id'] : null,
            'action'      => $action,
            'module'      => $module,
            'description' => mb_substr($description, 0, 255),
            'ip'          => client_ip(),
        ]);
    } catch (Throwable $e) {
        // Never let audit logging break the main flow.
    }
}

/* ------------------------------------------------------------------
 * Notifications
 * ------------------------------------------------------------------ */

function notify(string $title, string $message, string $type = 'info', ?array $userIds = null): void
{
    try {
        if ($userIds === null) {
            $stmt = db()->prepare(
                'INSERT INTO notifications (user_id, title, message, type)
                 SELECT id, :title, :message, :type FROM users WHERE status = "active"'
            );
            $stmt->execute(['title' => $title, 'message' => $message, 'type' => $type]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO notifications (user_id, title, message, type) VALUES (:uid, :title, :message, :type)'
            );
            foreach ($userIds as $uid) {
                $stmt->execute(['uid' => $uid, 'title' => $title, 'message' => $message, 'type' => $type]);
            }
        }
    } catch (Throwable $e) {
        // Notifications must never crash a request.
    }
}

/* ------------------------------------------------------------------
 * Status badges
 * ------------------------------------------------------------------ */

function status_badge(string $status): string
{
    $map = [
        'active'      => 'success',
        'inactive'    => 'secondary',
        'paid'        => 'success',
        'pending'     => 'warning',
        'partial'     => 'info',
        'refunded'    => 'danger',
        'unpaid'      => 'danger',
        'void'        => 'secondary',
        'confirmed'   => 'primary',
        'in_progress' => 'info',
        'completed'   => 'success',
        'cancelled'   => 'danger',
        'no_show'     => 'secondary',
        'waiting'     => 'warning',
        'converted'   => 'success',
    ];

    $class = $map[strtolower($status)] ?? 'secondary';
    return '<span class="badge badge-status bg-' . $class . '">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

/* ------------------------------------------------------------------
 * Business hours / availability
 * ------------------------------------------------------------------ */

/**
 * Display helpers. The weekly schedule now lives in `business_hours`
 * (one row per weekday); these keep the old flat "HH:MM" contract for
 * headers and the calendar by reporting the FIRST open weekday.
 */
function business_open_time(): string
{
    if (function_exists('business_hours_first_open_day')) {
        $day = business_hours_first_open_day();

        if ($day !== null) {
            $row = business_hours_for((int)$day);

            if (!empty($row['opening_time'])) {
                return substr((string)$row['opening_time'], 0, 5);
            }
        }
    }

    return get_setting('business_open', '09:00');
}

function business_close_time(): string
{
    if (function_exists('business_hours_first_open_day')) {
        $day = business_hours_first_open_day();

        if ($day !== null) {
            $row = business_hours_for((int)$day);

            if (!empty($row['closing_time'])) {
                return substr((string)$row['closing_time'], 0, 5);
            }
        }
    }

    return get_setting('business_close', '18:00');
}

function is_holiday(string $date): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) AS c FROM holidays WHERE holiday_date = :d');
    $stmt->execute(['d' => $date]);
    return (int)$stmt->fetch()['c'] > 0;
}

/**
 * Returns TRUE when no other (non-cancelled) appointment overlaps the given slot.
 *
 * Thin wrapper kept for the existing call sites; the real check now
 * lives in scheduling_check_range(), which also enforces the weekly
 * business hours, the salon/staff breaks and the staff shift.
 *
 * @param string     $date  Y-m-d
 * @param string     $start HH:MM:SS
 * @param string     $end   HH:MM:SS
 * @param int|null   $excludeId Appointment id to ignore (when editing)
 */
function slot_is_available(string $date, string $start, string $end, ?int $excludeId = null): bool
{
    return scheduling_check_range($date, $start, $end, null, null, $excludeId) === null;
}

/**
 * Generate available time slots for a date.
 *
 * No staff member is picked here, so a start time counts as bookable when at
 * least ONE rostered staff member is free for it — the union of their free
 * time. Letting a single booking block the whole salon would offer the
 * customer times nobody can actually take.
 *
 * The skills check stays in scheduling_validate(), which the booking write
 * always runs, so this superset can never hide a genuinely bookable time.
 *
 * @param string     $date   Y-m-d
 * @param int|null   $excludeId Appointment id to ignore (when editing)
 *
 * @return array<int, string> list of "HH:MM:SS"
 */
function available_slots(string $date, ?int $excludeId = null): array
{
    $info = scheduling_day_info($date);

    if (!$info['is_open']) {
        return [];
    }

    $roster = array_map(
        static fn($member) => (int)$member['id'],
        staff_working_on($info['day_of_week'])
    );

    if (!$roster) {
        return [];
    }

    return scheduling_slots_for_roster($date, $roster, 0, $excludeId);
}

/* ------------------------------------------------------------------
 * Pagination helper
 * ------------------------------------------------------------------ */

function paginate(int $total, int $perPage, int $page): array
{
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page       = max(1, min($page, $totalPages));
    return [
        'total'       => $total,
        'perPage'     => $perPage,
        'page'        => $page,
        'totalPages'  => $totalPages,
        'offset'      => ($page - 1) * $perPage,
        'hasPrev'     => $page > 1,
        'hasNext'     => $page < $totalPages,
        'prevPage'    => $page - 1,
        'nextPage'    => $page + 1,
    ];
}

function query_string(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return http_build_query($params);
}

/**
 * Generate the next invoice number, e.g. INV-2026-0009.
 */
function next_invoice_number(): string
{
    $year = date('Y');
    $rows = db()->query("SELECT invoice_number FROM invoices WHERE invoice_number LIKE 'INV-{$year}-%'")->fetchAll(PDO::FETCH_COLUMN);
    $max  = 0;
    foreach ($rows as $r) {
        if (preg_match('/INV-\d{4}-(\d+)$/', (string)$r, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return 'INV-' . $year . '-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------
 * Service hierarchy  (CATEGORY -> SUBCATEGORY (optional) -> SERVICE)
 *
 * Every read path in the system goes through these helpers so the
 * hierarchy is described in exactly one place: service management,
 * appointment booking, walk-ins, invoices and reports.
 * ------------------------------------------------------------------ */

/** Label for the bucket of services that sit straight under their category. */
const SERVICE_NO_SUBCATEGORY = 'No subcategory';

/**
 * All categories, ordered for display (manual order, then name).
 *
 * @return array<int,array<string,mixed>>
 */
function service_categories_all(bool $onlyActive = false): array
{
    static $cache = [];
    $key = $onlyActive ? 'active' : 'all';

    if (!isset($cache[$key])) {
        $where = $onlyActive ? "WHERE status = 'active'" : '';
        $cache[$key] = db()->query(
            "SELECT * FROM service_categories $where ORDER BY sort_order, name, id"
        )->fetchAll();
    }

    return $cache[$key];
}

/**
 * All subcategories joined to their parent category name, ordered for display.
 *
 * @return array<int,array<string,mixed>>
 */
function service_subcategories_all(bool $onlyActive = false): array
{
    $where = $onlyActive ? "AND sc.status = 'active'" : '';

    return db()->query(
        "SELECT sc.*, c.name AS category_name
           FROM service_subcategories sc
           LEFT JOIN service_categories c ON c.id = sc.category_id
          WHERE 1 = 1 $where
       ORDER BY COALESCE(c.sort_order, 999999), c.name, sc.sort_order, sc.name, sc.id"
    )->fetchAll();
}

/**
 * Subcategories belonging to one category — used by the dependent
 * category -> subcategory selector and by the server-side validator.
 *
 * @return array<int,array<string,mixed>>
 */
function service_subcategories_of(int $categoryId, bool $onlyActive = false): array
{
    if ($categoryId <= 0) {
        return [];
    }

    $where = $onlyActive ? "AND status = 'active'" : '';

    $stmt = db()->prepare(
        "SELECT * FROM service_subcategories WHERE category_id = :cat $where
       ORDER BY sort_order, name, id"
    );
    $stmt->execute(['cat' => $categoryId]);

    return $stmt->fetchAll();
}

/**
 * Services for the operational pickers (booking, walk-ins, public booking).
 * Returns rows carrying their category / subcategory names so the
 * hierarchy can be rendered without extra round trips.
 *
 * @return array<int,array<string,mixed>>
 */
function services_for_picker(bool $onlyActive = true): array
{
    $where = $onlyActive ? "WHERE s.status = 'active'" : '';

    return db()->query(
        "SELECT s.id, s.name, s.description, s.price, s.duration_minutes, s.status,
                s.category_id, s.subcategory_id, s.sort_order,
                c.name AS category_name, c.sort_order AS category_order,
                sc.name AS subcategory_name, sc.sort_order AS subcategory_order
           FROM services s
           LEFT JOIN service_categories c    ON c.id  = s.category_id
           LEFT JOIN service_subcategories sc ON sc.id = s.subcategory_id
           $where
       ORDER BY COALESCE(c.sort_order, 999999), c.name,
                COALESCE(sc.sort_order, 999999), sc.name,
                s.sort_order, s.name, s.id"
    )->fetchAll();
}

/**
 * Group flat service rows into the three-level structure:
 *
 *   [ ['category' => [...], 'direct' => [...], 'subcategories' =>
 *        [ ['subcategory' => [...], 'services' => [...]] ] ], ... ]
 *
 * `direct` holds services that sit directly under the category because
 * they have no subcategory. Services whose category was removed are
 * collected under a trailing "Uncategorised" bucket so nothing is hidden.
 *
 * @param array<int,array<string,mixed>> $services
 * @return array<int,array<string,mixed>>
 */
function group_services_by_hierarchy(array $services): array
{
    $tree = [];

    foreach ($services as $svc) {
        $catId = (int)($svc['category_id'] ?? 0);

        if ($catId <= 0) {
            $tree[0]['category'] = [
                'id'          => 0,
                'name'        => 'Uncategorised',
                'status'      => '',
                'sort_order'  => 999999,
            ];
            $catId = 0;
        } elseif (!isset($tree[$catId])) {
            $tree[$catId]['category'] = [
                'id'         => $catId,
                'name'       => (string)($svc['category_name'] ?? ''),
                'status'     => '',
                'sort_order' => (int)($svc['category_order'] ?? 999999),
            ];
        }

        $subId = (int)($svc['subcategory_id'] ?? 0);

        if ($subId <= 0) {
            $tree[$catId]['direct'][] = $svc;
            continue;
        }

        $tree[$catId]['subcategories'][$subId]['subcategory'] = [
            'id'         => $subId,
            'name'       => (string)($svc['subcategory_name'] ?? ''),
            'status'     => '',
            'sort_order' => (int)($svc['subcategory_order'] ?? 999999),
        ];
        $tree[$catId]['subcategories'][$subId]['services'][] = $svc;
    }

    /* Order the buckets the same way the picker query does. */
    uasort($tree, static function ($a, $b) {
        return [$a['category']['sort_order'], $a['category']['name']]
            <=> [$b['category']['sort_order'], $b['category']['name']];
    });

    foreach ($tree as &$node) {
        $node['direct'] = $node['direct'] ?? [];

        if (!empty($node['subcategories'])) {
            uasort($node['subcategories'], static function ($a, $b) {
                return [$a['subcategory']['sort_order'], $a['subcategory']['name']]
                    <=> [$b['subcategory']['sort_order'], $b['subcategory']['name']];
            });
        }
        $node['subcategories'] = $node['subcategories'] ?? [];
    }
    unset($node);

    return array_values($tree);
}

/**
 * Human-readable breadcrumb for a service, e.g. "Hair › Hair Styling › Blow Dry".
 * The subcategory segment is omitted when the service has none.
 */
function service_hierarchy_path(array $service, string $separator = ' › '): string
{
    $parts = array_values(array_filter([
        $service['category_name'] ?? null,
        $service['subcategory_name'] ?? null,
        $service['name'] ?? null,
    ], static function ($v) {
        return $v !== null && $v !== '';
    }));

    return implode($separator, $parts);
}

/**
 * Service counts per category and per subcategory, in two queries
 * (avoids the N+1 pattern the service list used to run).
 *
 * @return array{categories:array<int,int>,subcategories:array<int,int>,services:int}
 */
function service_hierarchy_counts(): array
{
    return [
        'categories'    => service_count_map('category_id'),
        'subcategories' => service_count_map('subcategory_id'),
        'services'      => (int)db()->query('SELECT COUNT(*) FROM services')->fetchColumn(),
    ];
}

/**
 * service_id => number of services, for one of the two hierarchy columns.
 *
 * @return array<int,int>
 */
function service_count_map(string $column): array
{
    $allowed = ['category_id', 'subcategory_id'];

    if (!in_array($column, $allowed, true)) {
        throw new InvalidArgumentException("Unsupported service grouping column: {$column}");
    }

    $rows = db()->query("SELECT `{$column}` AS grp, COUNT(*) AS c FROM services GROUP BY `{$column}`")
        ->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        if ($row['grp'] === null) {
            continue;
        }
        $map[(int)$row['grp']] = (int)$row['c'];
    }

    return $map;
}

/**
 * Does this subcategory exist, and does it belong to the given category?
 *
 * This is the server-side guard for hierarchy rule 7 — it is applied on
 * every write path, so a tampered POST cannot cross-link a subcategory
 * from another category. JavaScript filtering is a convenience only.
 *
 * @return string|null NULL when the pair is valid, otherwise the error message.
 */
function validate_service_hierarchy(int $categoryId, int $subcategoryId): ?string
{
    /* A subcategory is optional. */
    if ($subcategoryId <= 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, category_id FROM service_subcategories WHERE id = :id');
    $stmt->execute(['id' => $subcategoryId]);
    $sub = $stmt->fetch();

    if (!$sub) {
        return 'The selected subcategory no longer exists. Please choose another one.';
    }

    if ($categoryId <= 0) {
        return 'A subcategory must belong to a category. Please select the parent category.';
    }

    if ((int)$sub['category_id'] !== $categoryId) {
        return 'The selected subcategory does not belong to the selected category.';
    }

    return null;
}

/**
 * Toggle a status column between 'active' and 'inactive'.
 * Used instead of hard deletion so existing appointments, invoices and
 * payments keep pointing at a real service row.
 */
function toggle_status(string $table, int $id, string $column = 'status'): bool
{
    $allowed = ['service_categories', 'service_subcategories', 'services'];

    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException("Refusing to toggle status on table: {$table}");
    }

    $stmt = db()->prepare("SELECT `{$column}` FROM `{$table}` WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $current = $stmt->fetchColumn();

    if ($current === false) {
        return false;
    }

    $next = $current === 'active' ? 'inactive' : 'active';

    db()->prepare("UPDATE `{$table}` SET `{$column}` = :status WHERE id = :id")
        ->execute(['status' => $next, 'id' => $id]);

    return true;
}

/**
 * Move an item one slot up (-1) or down (+1) among its siblings and
 * renumber that sibling group so sort_order stays dense (1..n).
 */
function reorder_sibling(string $table, string $parentColumn, int $id, int $direction): bool
{
    $siblings = [
        'service_categories'    => null,
        'service_subcategories' => 'category_id',
        'services'              => 'category_id',
    ];

    if (!isset($siblings[$table]) || !in_array($direction, [-1, 1], true)) {
        return false;
    }

    $parentColumn = $siblings[$table] ?: $parentColumn;
    $stmt = db()->prepare("SELECT id, `{$parentColumn}` AS parent FROM `{$table}` WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    $isNullParent = $row['parent'] === null;
    $parentValue  = $row['parent'];

    $listSql = $isNullParent
        ? "SELECT id FROM `{$table}` WHERE `{$parentColumn}` IS NULL ORDER BY sort_order, name, id"
        : "SELECT id FROM `{$table}` WHERE `{$parentColumn}` = :parent ORDER BY sort_order, name, id";

    $listStmt = db()->prepare($listSql);
    $isNullParent ? $listStmt->execute() : $listStmt->execute(['parent' => $parentValue]);

    $ids = array_map('intval', $listStmt->fetchAll(PDO::FETCH_COLUMN));
    $position = array_search($id, $ids, true);

    if ($position === false) {
        return false;
    }

    $target = $position + $direction;
    if ($target < 0 || $target >= count($ids)) {
        return false;
    }

    [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];

    $update = db()->prepare("UPDATE `{$table}` SET sort_order = :ord WHERE id = :id");
    foreach ($ids as $index => $siblingId) {
        $update->execute(['ord' => $index + 1, 'id' => $siblingId]);
    }

    return true;
}

/**
 * Next free sort_order for a new row, scoped to its parent when the table
 * is nested (subcategories and services order within their category).
 * Centralised so "append at the end" behaves identically everywhere.
 */
function next_sort_order(string $table, ?int $parentId = null): int
{
    $nested = [
        'service_subcategories' => 'category_id',
        'services'              => 'category_id',
        'service_categories'    => null,
    ];

    if (!array_key_exists($table, $nested)) {
        throw new InvalidArgumentException("Unknown service hierarchy table: {$table}");
    }

    $parentColumn = $nested[$table];

    if ($parentColumn === null || $parentId === null) {
        $sql = "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM `{$table}`";
        return (int)db()->query($sql)->fetchColumn();
    }

    $stmt = db()->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM `{$table}` WHERE `{$parentColumn}` = :p");
    $stmt->execute(['p' => $parentId]);

    return (int)$stmt->fetchColumn();
}

/**
 * Award loyalty points for a completed payment.
 * Rate: 1 point per {loyalty_rate} currency units spent (configurable in settings).
 */
function award_loyalty(int $customerId, float $total): void
{
    $rate = (float)get_setting('loyalty_rate', 100);
    if ($rate <= 0 || $total <= 0) {
        return;
    }

    $points = (int)floor($total / $rate);
    if ($points <= 0) {
        return;
    }

    db()->prepare('UPDATE customers SET loyalty_points = loyalty_points + :p WHERE id = :id')
        ->execute(['p' => $points, 'id' => $customerId]);

    db()->prepare('INSERT INTO loyalty_ledger (customer_id, points_change, reason) VALUES (:id, :p, :reason)')
        ->execute(['id' => $customerId, 'p' => $points, 'reason' => 'Loyalty points earned from appointment payment']);
}
