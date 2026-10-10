<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/sphinx_holidays/autoload.php                     *
 *                                                                          *
 ***************************************************************************/

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// PSR-4 autoloader for the Tygh\Addons\SphinxHolidays namespace -> src/.
//
// Required (require_once) by init.php AND func.php. CS-Cart runs init.php
// only for an active add-on; while this one installs, func.php is loaded
// alone and its settings variants and post_install reach src/ classes
// (FuncSelfSufficiencyTest).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Tygh\\Addons\\SphinxHolidays\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $relative;

    if (file_exists($file)) {
        require $file;
    }
});
