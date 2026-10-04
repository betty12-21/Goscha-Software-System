<?php
/**
 * Beauty-php-ai — Logout.
 */

require_once __DIR__ . '/includes/init.php';

if (!empty($_GET['customer'])) {
    logout_customer();
    flash_set('success', 'You have been logged out.');
    redirect('index.php');
}

logout_user();
flash_set('success', 'You have been logged out successfully.');
redirect('login.php');
