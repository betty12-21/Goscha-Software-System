<?php
/**
 * Beauty-php-ai — Application bootstrap.
 * Include this at the top of every page that needs the framework.
 */

define('APP_INIT', true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

/* Tenant context. Loaded after auth.php because current_salon_id() verifies
   the session's salon_id against users.salon_id, and before permissions and
   the modules so every downstream query can be scoped to one salon. */
require_once __DIR__ . '/tenant.php';

/* Public self-service salon registration. Loaded after tenant.php because
   register_salon() writes the salon row and then the account that owns it. */
require_once __DIR__ . '/salon_registration.php';

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/service-picker.php';

/* Scheduling engine: salon profile, weekly business hours, breaks,
   staff working hours, service skills and the availability validator.
   Loaded AFTER functions.php because it builds on get_setting(),
   is_holiday() and db(). */
require_once __DIR__ . '/scheduling.php';

start_app_session();

/* Every web page needs a configured weekly schedule before it can do
   anything meaningful, so an unfinished install goes to the wizard.
   No-op on the CLI and once setup_completed_at is stamped. */
require_setup_completed();
