<?php

declare(strict_types=1);

use Tygh\Addons\EuphPagesInBlocks\Cast;
use Tygh\Addons\EuphPagesInBlocks\Container;
use Tygh\Addons\EuphPagesInBlocks\Db\CsCartDatabase;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// Class autoloader of the add-on (Tygh\Addons\EuphPagesInBlocks\ -> src/).
// Required by init.php, func.php and the block manager schema: CS-Cart
// loads them in an order that differs between code paths (an uninstall runs
// the functions of func.php), and any of them may come first.
if (!defined('EUPH_PAGES_IN_BLOCKS_AUTOLOAD')) {
    define('EUPH_PAGES_IN_BLOCKS_AUTOLOAD', true);
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Tygh\\Addons\\EuphPagesInBlocks\\';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

/**
 * The add-on's services, built once per request at the CS-Cart boundary: a
 * page is read with fn_get_page_data(), as for its own view, and addresses
 * are made with fn_url().
 */
function fn_euph_pages_in_blocks_container(): Container
{
    static $container = null;
    if (!$container instanceof Container) {
        $prefix = Registry::get('config.table_prefix');
        $container = new Container(
            new CsCartDatabase(is_string($prefix) ? $prefix : 'cscart_'),
            static fn (string $url): string => Cast::string(fn_url($url)),
            static fn (int $pageId, string $langCode): mixed => fn_get_page_data($pageId, $langCode),
        );
    }

    return $container;
}
