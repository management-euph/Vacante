<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Controller;

/**
 * What the CS-Cart function stubs in cscart_stubs.php return, and every
 * call they receive. The stubs are global functions, so tests that load
 * them run in a separate process (see ControllerRunner).
 */
final class CsCartStubState
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    public static array $calls = [];

    /** @var array<string, mixed> */
    public static array $orderInfo = [];

    public static string $processorScript = 'netopia_payments.php';

    /** @var array<string, mixed> */
    public static array $processorParams = ['mode' => 'sandbox'];

    /** @var array{success: bool, payment_url?: string, error?: string} */
    public static array $paymentLinkResult = ['success' => true, 'payment_url' => 'https://pay.example/abc'];

    /**
     * @param list<mixed> $args
     */
    public static function record(string $function, array $args): void
    {
        self::$calls[] = [$function, $args];
    }

    /**
     * @return list<string>
     */
    public static function calledFunctions(): array
    {
        return array_map(static fn (array $call): string => $call[0], self::$calls);
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    public static function notifications(): array
    {
        $out = [];
        foreach (self::$calls as [$function, $args]) {
            if ($function === 'fn_set_notification') {
                $out[] = ['type' => (string) $args[0], 'message' => (string) $args[2]];
            }
        }
        return $out;
    }
}
