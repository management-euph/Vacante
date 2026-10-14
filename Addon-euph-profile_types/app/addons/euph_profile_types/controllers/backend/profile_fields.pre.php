<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// A new field gets its id only when CS-Cart saves it: the highest id before
// tells profile_fields.post.php which fields this request created.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'update' && Cast::int($_REQUEST['field_id'] ?? 0) <= 0) {
    Registry::set(
        'runtime.euph_profile_types.max_field_id',
        Cast::int(fn_euph_profile_types_container()->db()->value('SELECT MAX(field_id) FROM ?:profile_fields')),
    );
}
