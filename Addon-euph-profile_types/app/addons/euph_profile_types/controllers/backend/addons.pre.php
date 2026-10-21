<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// The add-on's settings page shows only the settings this version has: the
// ones an earlier version had are removed first.
if ($mode === 'update' && ($_REQUEST['addon'] ?? '') === 'euph_profile_types') {
    fn_euph_profile_types_remove_obsolete_settings();
}
