<?php

/**
 * Global CS-Cart stubs for the backend controller tests. Loaded only by
 * ControllerRunner inside a separate PHP process, so the functions, the
 * constants and Tygh\Tygh never leak into the rest of the suite.
 */

declare(strict_types=1);

namespace Tygh {
    final class Tygh
    {
        /** @var array<string, mixed> */
        public static array $app = [];
    }
}

namespace {
    use Netopia\CsCart\Tests\Controller\CsCartStubState;

    define('BOOTSTRAP', true);
    define('CONTROLLER_STATUS_NO_PAGE', '__status_no_page');
    define('CONTROLLER_STATUS_REDIRECT', '__status_redirect');

    /**
     * @param array<string, mixed> $params
     */
    function __(string $key, array $params = []): string
    {
        return $params === [] ? $key : $key . ' ' . json_encode($params, JSON_UNESCAPED_UNICODE);
    }

    function fn_url(string $url = ''): string
    {
        return 'admin.php?dispatch=' . $url;
    }

    function fn_set_notification(string $type, string $title, string $message): void
    {
        CsCartStubState::record(__FUNCTION__, [$type, $title, $message]);
    }

    /**
     * @return array<string, mixed>
     */
    function fn_get_order_info(int $orderId): array
    {
        CsCartStubState::record(__FUNCTION__, [$orderId]);
        return CsCartStubState::$orderInfo;
    }

    /**
     * @return array<string, mixed>
     */
    function db_get_row(string $query, mixed ...$args): array
    {
        CsCartStubState::record(__FUNCTION__, [$query, ...$args]);
        return ['processor_script' => CsCartStubState::$processorScript];
    }

    /**
     * The refund-attempt claim's INSERT. Recorded, then stops the run: no
     * test gets past the claim to NETOPIA or the order update.
     */
    function db_query(string $query, mixed ...$args): never
    {
        CsCartStubState::record(__FUNCTION__, [$query, ...$args]);
        throw new \RuntimeException('stub: stopped at db_query');
    }

    /**
     * @return array<string, mixed>
     */
    function fn_netopia_get_payment_method_data(int $paymentId): array
    {
        CsCartStubState::record(__FUNCTION__, [$paymentId]);
        return ['processor_params' => CsCartStubState::$processorParams];
    }

    /**
     * @return list<array<string, string>>
     */
    function fn_netopia_parse_refund_log(string $log): array
    {
        CsCartStubState::record(__FUNCTION__, [$log]);
        return [];
    }

    /**
     * @return array{success: bool, payment_url?: string, error?: string}
     */
    function fn_netopia_generate_payment_link(int $orderId): array
    {
        CsCartStubState::record(__FUNCTION__, [$orderId]);
        return CsCartStubState::$paymentLinkResult;
    }

    function fn_netopia_send_payment_link_email(int $orderId, string $url): bool
    {
        CsCartStubState::record(__FUNCTION__, [$orderId, $url]);
        return true;
    }
}
