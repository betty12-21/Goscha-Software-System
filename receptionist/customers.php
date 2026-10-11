<?php
/**
 * Beauty-php-ai — Receptionist: customers
 */

require_once __DIR__ . '/../includes/init.php';
require_any_role(['admin', 'receptionist']);

include __DIR__ . '/../modules/customers.php';
