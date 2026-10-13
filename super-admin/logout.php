<?php
/**
 * Beauty-php-ai — Super Admin sign out.
 */

require_once __DIR__ . '/../includes/init.php';

logout_super_admin(true);

flash_set('success', 'You have been signed out of the control panel.');
redirect(super_admin_login_url());
