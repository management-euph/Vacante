<?php

/**
 * with-store-config.php — run any dev/sphinx probe with THIS STORE's Sphinx
 * settings (API URL + key from the add-on's settings), so no token has to be
 * typed or committed. The key is passed in the environment and never printed.
 *
 * USE (fullstore sandbox, CLI only):
 *   docker compose exec app php /var/www/html/dev/sphinx/with-store-config.php circuits.php --limit=1
 *   docker compose exec app php /var/www/html/dev/sphinx/with-store-config.php hotels.php --limit=2
 * The first argument is the probe file in this folder; the rest are its own.
 */

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("with-store-config is CLI only\n");
}

$probe = basename((string) ($argv[1] ?? ''));
if ($probe === '' || $probe[0] === '_' || !is_file(__DIR__ . '/' . $probe) || $probe === basename(__FILE__)) {
    exit("Usage: php with-store-config.php <probe.php> [probe args]  (e.g. circuits.php --limit=1)\n");
}

$docroot = dirname(__DIR__, 2);
if (!is_file($docroot . '/init.php') && is_file('/var/www/html/init.php')) {
    $docroot = '/var/www/html';
}
if (!is_file($docroot . '/init.php')) {
    exit("No CS-Cart init.php at {$docroot} — run this from the fullstore container.\n");
}

const AREA = 'A';
const ACCOUNT_TYPE = 'admin';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
chdir($docroot);
require $docroot . '/init.php';

if (!class_exists(ConfigProvider::class)) {
    exit("The sphinx_holidays add-on is not installed/active in this store.\n");
}

$key = ConfigProvider::getApiKey();
$url = ConfigProvider::getApiBaseUrl();
if ($key === '') {
    exit("The store has no Sphinx API key set (Add-ons → Sphinx Holidays → API Key).\n");
}
putenv('SPHINX_API_TOKEN=' . $key);
if ($url !== '') {
    putenv('SPHINX_API_URL=' . $url);
}

// The probe sees its own arguments only.
$argv = array_merge([$probe], array_slice($argv, 2));
$_SERVER['argv'] = $argv;
$argc = count($argv);

require __DIR__ . '/' . $probe;
