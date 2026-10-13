<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// Class autoloader of the add-on (Tygh\Addons\EuphProfileTypes\ -> src/).
// Required by both init.php and func.php: CS-Cart loads them in an order
// that differs between code paths (an install runs the install functions
// of func.php), and either may come first.
if (!defined('EUPH_PROFILE_TYPES_AUTOLOAD')) {
    define('EUPH_PROFILE_TYPES_AUTOLOAD', true);
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Tygh\\Addons\\EuphProfileTypes\\';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
