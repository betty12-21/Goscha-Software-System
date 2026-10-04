<?php
/**
 * Beauty-php-ai — App shell header (head + navbar + sidebar + main open).
 * Expects: $page_title, $active
 */

$bsaiTitle  = $page_title ?? 'Dashboard';
$bsaiActive = $active ?? 'dashboard';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($bsaiTitle); ?> · <?php echo e(salon_name()); ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💇‍♀️</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/style.css'); ?>" rel="stylesheet">
</head>
<body class="app-page">
<div class="bsai-app">
    <?php include __DIR__ . '/navbar.php'; ?>

    <div class="app-body">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="app-main">
            <div class="container-fluid px-3 px-lg-4 py-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                    <h1 class="page-title h3 mb-0"><?php echo e($bsaiTitle); ?></h1>
                </div>
                <?php render_flash(); ?>
                <div class="page-content">
