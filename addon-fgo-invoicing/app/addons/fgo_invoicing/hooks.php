<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

$__fgo_hooks_dir = __DIR__ . '/hooks/';

foreach (['order_hooks.php', 'profile_hooks.php', 'settings_hooks.php'] as $__fgo_file) {
    $__fgo_path = $__fgo_hooks_dir . $__fgo_file;
    if (file_exists($__fgo_path)) {
        require_once $__fgo_path;
    }
}

unset($__fgo_hooks_dir, $__fgo_file, $__fgo_path);
