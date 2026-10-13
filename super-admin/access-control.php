<?php
/**
 * Beauty-php-ai — Super Admin: module access control.
 *
 * Grants or revokes individual modules per salon. Writes through
 * save_salon_modules()/apply_access_profile(), which keep the salon_modules
 * rows authoritative; the panel enforces the change server-side on the
 * salon's very next request because require_login() re-reads the grants.
 */

require_once __DIR__ . '/../includes/init.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        flash_set('danger', 'Invalid security token. Please try again.');
        redirect(super_admin_url('access-control.php'));
    }

    $salonId = (int)($_POST['salon_id'] ?? 0);
    $action  = (string)($_POST['action'] ?? 'save');

    if ($salonId <= 0) {
        flash_set('danger', 'Unknown salon.');
        redirect(super_admin_url('access-control.php'));
    }

    $stmt = db()->prepare('SELECT salon_name FROM salons WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $salonId]);
    $salonName = (string)($stmt->fetchColumn() ?: '');

    if ($salonName === '') {
        flash_set('danger', 'Salon not found.');
        redirect(super_admin_url('access-control.php'));
    }

    $admin = current_super_admin();

    if ($action === 'apply_profile') {
        $profile = (string)($_POST['profile'] ?? '');

        if (!access_profile_exists($profile)) {
            flash_set('danger', 'Unknown plan.');
            redirect(super_admin_url('access-control.php?salon_id=' . $salonId));
        }

        apply_access_profile($salonId, $profile, $admin['id'] ?? null);
        super_admin_audit('modules_profile', 'Applied plan "' . access_profiles()[$profile]['label'] . '" to "' . $salonName . '".', $salonId, $salonName);
        flash_set('success', 'Applied the ' . access_profiles()[$profile]['label'] . ' plan.');
        redirect(super_admin_url('access-control.php?salon_id=' . $salonId));
    }

    $modules = (array)($_POST['modules'] ?? []);
    save_salon_modules($salonId, $modules, $admin['id'] ?? null);

    super_admin_audit(
        'modules_update',
        sprintf('Updated module access for "%s" (%d/%d enabled).', $salonName, count(array_intersect($modules, module_keys())), count(module_keys())),
        $salonId,
        $salonName
    );

    flash_set('success', 'Module permissions saved.');
    redirect(super_admin_url('access-control.php?salon_id=' . $salonId));
}

$salonId = (int)($_GET['salon_id'] ?? 0);

$page_title = 'Access Control';
$active     = 'access-control';
include __DIR__ . '/../includes/super-admin/header.php';
?>

<?php if ($salonId <= 0): ?>
    <?php
    $salons = [];
    try {
        $salons = db()->query(
            "SELECT id, salon_name, status, access_profile FROM salons
              ORDER BY (status = 'active') DESC, salon_name ASC LIMIT 200"
        )->fetchAll();
    } catch (Throwable $e) {
        $salons = [];
    }
    ?>
    <div class="sa-card">
        <div class="sa-card-body">
            <h2 class="h6 fw-bold mb-3"><i class="bi bi-sliders me-2"></i>Choose a salon</h2>
            <?php if (!$salons): ?>
                <div class="sa-empty"><i class="bi bi-shop"></i>No salons yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="sa-table">
                        <thead><tr><th>Salon</th><th>Status</th><th>Plan</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($salons as $s): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo e($s['salon_name']); ?></td>
                                <td><?php echo sa_status_badge((string)$s['status']); ?></td>
                                <td class="small text-muted"><?php echo e(ucfirst((string)$s['access_profile'])); ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-charcoal rounded-pill px-3"
                                       href="<?php echo e(super_admin_url('access-control.php?salon_id=' . (int)$s['id'])); ?>">
                                        Manage modules
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <?php
    $stmt = db()->prepare('SELECT * FROM salons WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $salonId]);
    $salon = $stmt->fetch();

    if (!$salon) {
        echo '<div class="alert alert-danger">Salon not found.</div>';
        include __DIR__ . '/../includes/super-admin/footer.php';
        exit;
    }

    $enabled  = enabled_modules($salonId);
    $profiles = access_profiles();
    $tierNames = [1 => 'Core', 2 => 'Growth', 3 => 'Advanced'];

    $byTier = [];
    foreach (module_keys() as $key) {
        $byTier[module_tier($key)][] = $key;
    }
    ?>

    <a href="<?php echo e(super_admin_url('access-control.php')); ?>" class="small text-decoration-none d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i>All salons
    </a>

    <div class="sa-card mb-3">
        <div class="sa-card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h2 class="h5 fw-bold mb-0"><?php echo e($salon['salon_name']); ?></h2>
                <div class="small text-muted">
                    <?php echo sa_status_badge((string)$salon['status']); ?>
                    <span class="ms-2"><?php echo count($enabled); ?>/<?php echo count(module_keys()); ?> modules enabled</span>
                </div>
            </div>
            <a class="btn btn-sm btn-light rounded-pill px-3"
               href="<?php echo e(super_admin_url('account-details.php?id=' . $salonId)); ?>">Account details</a>
        </div>
    </div>

    <div class="sa-card mb-3">
        <div class="sa-card-body">
            <h3 class="h6 fw-bold mb-3"><i class="bi bi-award me-2"></i>Plan presets</h3>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($profiles as $key => $p): ?>
                    <form method="post" action="access-control.php" class="d-inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="salon_id" value="<?php echo $salonId; ?>">
                        <input type="hidden" name="action" value="apply_profile">
                        <input type="hidden" name="profile" value="<?php echo e($key); ?>">
                        <button type="submit" class="btn btn-sm rounded-pill px-3 <?php echo $salon['access_profile'] === $key ? 'btn-rose' : 'btn-outline-secondary'; ?>"
                                title="Apply the <?php echo e($p['label']); ?> plan (replaces the current module selection)">
                            <?php echo e($p['label']); ?> · <?php echo count(profile_modules($key)); ?> modules
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
            <p class="small text-muted mb-0 mt-2">
                A plan is a starting point: applying one overwrites the checkboxes below, which you can then fine-tune.
            </p>
        </div>
    </div>

    <form method="post" action="access-control.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="salon_id" value="<?php echo $salonId; ?>">
        <input type="hidden" name="action" value="save">

        <div class="sa-card mb-3">
            <div class="sa-card-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <h3 class="h6 fw-bold mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>Modules</h3>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-light rounded-pill px-3" id="saModulesAll">Select all</button>
                        <button type="button" class="btn btn-sm btn-light rounded-pill px-3" id="saModulesNone">Clear</button>
                    </div>
                </div>

                <?php foreach ($byTier as $tier => $keys): ?>
                    <div class="text-muted small text-uppercase fw-semibold mb-2 mt-3"><?php echo e($tierNames[$tier] ?? ('Tier ' . $tier)); ?></div>
                    <div class="sa-module-grid mb-2">
                        <?php foreach ($keys as $key): ?>
                            <?php $on = in_array($key, $enabled, true); ?>
                            <label class="sa-module">
                                <input class="form-check-input sa-module-check" type="checkbox"
                                       name="modules[]" value="<?php echo e($key); ?>" <?php echo $on ? 'checked' : ''; ?>>
                                <i class="bi <?php echo e(module_icon($key)); ?>"></i>
                                <span class="sa-module-label"><?php echo e(module_label($key)); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="<?php echo e(super_admin_url('account-details.php?id=' . $salonId)); ?>" class="btn btn-light rounded-pill px-4">Cancel</a>
            <button type="submit" class="btn btn-rose rounded-pill px-4"><i class="bi bi-check2 me-1"></i>Save permissions</button>
        </div>
    </form>

    <script>
        (function () {
            'use strict';
            var boxes = function () { return document.querySelectorAll('.sa-module-check'); };
            var all = document.getElementById('saModulesAll');
            var none = document.getElementById('saModulesNone');
            if (all) { all.addEventListener('click', function () { boxes().forEach(function (b) { b.checked = true; }); }); }
            if (none) { none.addEventListener('click', function () { boxes().forEach(function (b) { b.checked = false; }); }); }
        })();
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/super-admin/footer.php'; ?>
