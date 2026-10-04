<?php
/**
 * Beauty-php-ai — Receptionist: payments
 */

require_once __DIR__ . '/../includes/init.php';
require_any_role(['admin', 'receptionist']);

include __DIR__ . '/../modules/payments.php';
