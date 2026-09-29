<?php

declare(strict_types=1);

namespace Netopia\CsCart\Session;

use ArrayAccess;

/**
 * Encapsulates session storage for the 3DS redirect flow.
 *
 * Wraps an \ArrayAccess session container (typically Tygh::$app['session'])
 * so orchestration classes can be unit-tested against plain containers.
 */
final class ThreeDsSessionStore
{
    private const string KEY_ORDER_ID = 'netopia_order_id';
    private const string KEY_PAYMENT_ID = 'netopia_payment_id';

    /**
     * @param ArrayAccess<string, mixed> $session
     */
    public function __construct(
        private readonly ArrayAccess $session,
    ) {
    }

    public function rememberOrder(int $orderId, int $paymentId): void
    {
        $session = $this->session;
        $session->offsetSet(self::KEY_ORDER_ID, $orderId);
        $session->offsetSet(self::KEY_PAYMENT_ID, $paymentId);
    }

    public function orderId(): int
    {
        if (!$this->session->offsetExists(self::KEY_ORDER_ID)) {
            return 0;
        }
        $val = $this->session->offsetGet(self::KEY_ORDER_ID);
        return is_numeric($val) ? (int) $val : 0;
    }

    public function clear(): void
    {
        $session = $this->session;
        if ($session->offsetExists(self::KEY_ORDER_ID)) {
            $session->offsetUnset(self::KEY_ORDER_ID);
        }
        if ($session->offsetExists(self::KEY_PAYMENT_ID)) {
            $session->offsetUnset(self::KEY_PAYMENT_ID);
        }
    }
}
