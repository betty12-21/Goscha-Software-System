<?php
/**
 * Child-process probe for the cross-tenant guard.
 *
 * Usage: php _probe_403.php <user_id> <salon_id> <customer_id>
 * Exit 5 means require_tenant_record() blocked the row, which is the point.
 */

$userId  = (int)($argv[1] ?? 0);
$salonId = (int)($argv[2] ?? 0);
$rowId   = (int)($argv[3] ?? 0);

if ($userId <= 0 || $salonId <= 0 || $rowId <= 0) {
    fwrite(STDERR, "usage: _probe_403.php <user_id> <salon_id> <customer_id>\n");
    exit(2);
}

require __DIR__ . '/../includes/init.php';

$_SESSION['user_id']  = $userId;
$_SESSION['salon_id'] = $salonId;

/* Returns normally when the row belongs to this salon, exits 5 when it does
   not, which is exactly the behaviour the parent suite is asserting. */
require_tenant_record('customers', $rowId);

echo "allowed\n";
exit(0);