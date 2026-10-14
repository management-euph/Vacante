<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * The checkout customer blocks get the "By profile type" filling: they list
 * the fields of the customer's type (the type choice first in the contact
 * information). Their own filling keeps working, served by the function the
 * block had; the add-on switches the blocks to its filling when it is
 * installed or enabled and back when it is disabled (functions/install.php).
 */

return fn_euph_profile_types_block_manager_schema($schema ?? []);
