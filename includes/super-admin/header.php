<?php
/**
 * Beauty-php-ai — Super Admin shell header (head + sidebar + topbar).
 * Expects: $page_title, $active
 *
 * Deliberately independent of the salon shell (includes/header.php): a super
 * admin is not a tenant user, so this chrome never calls salon_name() or the
 * tenant-scoped navigation.
 */

if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

$saTitle  = $page_title ?? 'Control Panel';
$saActive = $active ?? 'dashboard';
$saAdmin  = current_super_admin();

$saNav = [
    'dashboard'      => ['Dashboard', 'bi-speedometer2'],
    'accounts'       => ['Salon Accounts', 'bi-shop'],
    'access-control' => ['Access Control', 'bi-sliders'],
    'audit-logs'     => ['Audit Logs', 'bi-journal-text'],
    'profile'        => ['My Profile', 'bi-person-circle'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo e($saTitle); ?> · <?php echo e(app_name()); ?> Control Panel</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🛡️</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/super-admin.css'); ?>" rel="stylesheet">
</head>
<body class="sa-page">
<div class="sa-app">
    <aside class="sa-sidebar" id="saSidebar">
        <div class="sa-brand">
            <span class="sa-brand-mark"><i class="bi bi-shield-lock-fill"></i></span>
            <span class="sa-brand-text">
                <strong>Control Panel</strong>
                <small><?php echo e(app_name()); ?></small>
            </span>
        </div>

        <nav class="sa-nav">
            <?php foreach ($saNav as $key => [$label, $icon]): ?>
                <a href="<?php echo e(super_admin_url($key . '.php')); ?>"
                   class="sa-nav-link<?php echo $saActive === $key ? ' active' : ''; ?>">
                    <i class="bi <?php echo e($icon); ?>"></i><span><?php echo e($label); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sa-sidebar-footer">
            <a href="<?php echo e(super_admin_url('logout.php')); ?>" class="sa-logout">
                <i class="bi bi-box-arrow-right"></i><span>Sign out</span>
            </a>
        </div>
    </aside>

    <div class="sa-main">
        <header class="sa-topbar">
            <button class="sa-menu-btn" type="button" id="saMenuBtn" aria-label="Toggle menu">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="sa-page-title"><?php echo e($saTitle); ?></h1>
            <div class="sa-user">
                <i class="bi bi-person-circle"></i>
                <span><?php echo e($saAdmin['name'] ?? 'Super Admin'); ?></span>
            </div>
        </header>

        <main class="sa-content">
            <?php render_flash(); ?>
