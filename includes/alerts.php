<?php
/**
 * Beauty-php-ai — Alert / toast rendering.
 */

function render_flash(): void
{
    foreach (flash_get() as $flash) {
        $type    = $flash['type'];
        $message = $flash['message'];

        if (in_array($type, ['success', 'danger', 'warning', 'info'], true)) {
            ?>
            <div class="alert alert-<?php echo $type; ?> alert-dismissible fade show rounded-3 shadow-sm" role="alert">
                <?php echo e($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php
        }
    }
}

function render_toasts(): void
{
    foreach (flash_get() as $flash) {
        $type = $flash['type'];
        if (!in_array($type, ['success', 'danger', 'warning', 'info'], true)) {
            continue;
        }
        ?>
        <div class="bsai-toast toast align-items-center text-bg-<?php echo $type; ?> border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body"><?php echo e($flash['message']); ?></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
        <?php
    }
}
