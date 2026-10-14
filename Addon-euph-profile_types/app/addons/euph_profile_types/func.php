<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

require_once __DIR__ . '/functions/bootstrap.php';
require_once __DIR__ . '/functions/runtime.php';
require_once __DIR__ . '/functions/context.php';
require_once __DIR__ . '/functions/types.php';
require_once __DIR__ . '/functions/fields.php';
require_once __DIR__ . '/functions/users.php';
require_once __DIR__ . '/functions/vendors.php';
require_once __DIR__ . '/functions/orders.php';
require_once __DIR__ . '/functions/install.php';
require_once __DIR__ . '/functions/admin.php';
require_once __DIR__ . '/hooks/field_hooks.php';
require_once __DIR__ . '/hooks/user_hooks.php';
require_once __DIR__ . '/hooks/vendor_hooks.php';
require_once __DIR__ . '/hooks/order_hooks.php';
require_once __DIR__ . '/hooks/addon_hooks.php';
