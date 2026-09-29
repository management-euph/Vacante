<?php

declare(strict_types=1);

// Use the addon's own PSR-4 autoloader to prove the production classes load
// exactly the way they do inside CS-Cart (where Composer is not guaranteed).
require __DIR__ . '/../app/addons/netopia_payments/autoload.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}
