<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Controller;

/**
 * Runs a backend controller file the way CS-Cart's dispatcher does: the
 * file is included with `$mode` in scope and reads $_REQUEST / $_SERVER.
 *
 * Callers MUST run in a separate process (#[RunTestsInSeparateProcesses]):
 * the CS-Cart stubs are global functions and constants.
 */
final class ControllerRunner
{
    private const string CONTROLLERS = __DIR__ . '/../../app/addons/netopia_payments/controllers/backend/';

    public static function boot(): void
    {
        require_once __DIR__ . '/cscart_stubs.php';
        CsCartStubState::$calls = [];
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function run(string $controller, string $mode, string $method, array $request = []): mixed
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_REQUEST = $request;

        $file = self::CONTROLLERS . $controller;

        // $mode is a parameter so it is in the controller's scope, as
        // CS-Cart's dispatcher provides it.
        return (static fn (string $controllerFile, string $mode): mixed => require $controllerFile)($file, $mode);
    }
}
