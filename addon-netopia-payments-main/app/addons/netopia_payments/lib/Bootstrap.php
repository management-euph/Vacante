<?php

declare(strict_types=1);

namespace Netopia\CsCart;

use Closure;
use Netopia\CsCart\Http\ApiClient;
use Netopia\CsCart\Ipn\IpnHandler;
use Netopia\CsCart\Ipn\IpnVerifier;
use Netopia\CsCart\Key\KeyStorage;
use Netopia\CsCart\Log\CompositeLogger;
use Netopia\CsCart\Log\CsCartLogger;
use Netopia\CsCart\Log\FileLogger;
use Netopia\CsCart\Payment\PayloadBuilder;
use Netopia\CsCart\Payment\PaymentLinkEmailSender;
use Netopia\CsCart\Payment\PaymentLinkService;
use Netopia\CsCart\Payment\RefundAttemptStore;
use Netopia\CsCart\Payment\RefundEmailSender;
use Netopia\CsCart\Payment\RefundFinalizer;
use Netopia\CsCart\Payment\RefundService;
use Netopia\CsCart\Session\ThreeDsSessionStore;
use Netopia\CsCart\Status\StatusMapper;
use Netopia\CsCart\Status\StatusMessage;
use Netopia\CsCart\Support\Arr;
use Netopia\CsCart\Support\ClockInterface;
use Netopia\CsCart\Support\SystemClock;
use Netopia\CsCart\ThreeDs\ThreeDsDataFactory;
use Netopia\CsCart\ThreeDs\ThreeDsReturnHandler;
use Netopia\Payment2\Enum\PaymentMode;
use Psr\Log\LoggerInterface;

/**
 * Lightweight service container for the NETOPIA CS-Cart addon.
 *
 * Instantiates the object graph that the procedural hook wrappers in
 * func.php delegate to. Depends on CS-Cart globals only through the
 * bootstrap method — individual services stay pure.
 */
final class Bootstrap
{
    private static ?self $instance = null;

    public readonly PayloadBuilder $payloadBuilder;
    public readonly KeyStorage $keyStorage;
    public readonly IpnVerifier $ipnVerifier;
    public readonly StatusMapper $statusMapper;
    public readonly StatusMessage $statusMessage;
    public readonly ThreeDsDataFactory $threeDsFactory;
    public readonly ClockInterface $clock;
    public readonly LoggerInterface $logger;

    private function __construct(
        string $keysBaseDir,
        string $notifyUrl,
        string $redirectUrl,
        string $primaryCurrency,
        string $language,
    ) {
        $this->clock = new SystemClock();
        // Two-logger fan-out: CsCartLogger lands in CS-Cart's admin
        // Logs page (Administration → Logs); FileLogger appends one
        // JSON line per call to <store>/var/log/netopia_payments-<date>.log
        // so operators can `tail -f` without depending on the admin UI.
        // The file logger is added only when CS-Cart's `config.dir.var`
        // resolves to a real path — in test/CLI bootstraps it doesn't,
        // and we fall back to CsCartLogger alone.
        $varDir = \class_exists(\Tygh\Registry::class)
            ? (is_scalar(\Tygh\Registry::get('config.dir.var'))
                ? (string) \Tygh\Registry::get('config.dir.var')
                : '')
            : '';
        $logDir = $varDir !== '' ? rtrim($varDir, '/') . '/log' : '';

        $loggers = [new CsCartLogger()];
        if ($logDir !== '') {
            $loggers[] = new FileLogger($logDir);
        }
        $this->logger = count($loggers) === 1 ? $loggers[0] : new CompositeLogger($loggers);
        $siteUrl = \class_exists(\Tygh\Registry::class)
            ? (is_scalar(\Tygh\Registry::get('config.http_host')) ? (string) \Tygh\Registry::get('config.http_host') : '')
            : '';

        $this->payloadBuilder = new PayloadBuilder(
            clock:           $this->clock,
            notifyUrl:       $notifyUrl,
            redirectUrl:     $redirectUrl,
            primaryCurrency: $primaryCurrency,
            language:        $language,
            siteUrl:         $siteUrl,
        );
        $this->keyStorage = new KeyStorage($keysBaseDir);
        $this->ipnVerifier = new IpnVerifier();
        $this->statusMapper = new StatusMapper();
        $this->statusMessage = new StatusMessage(
            translator: static function (string $key): string {
                return function_exists('__') ? (string) __($key) : $key;
            },
        );
        $this->threeDsFactory = new ThreeDsDataFactory();
    }

    public static function instance(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $addonsDir = \function_exists('fn_get_files_dir_path') && \class_exists(\Tygh\Registry::class)
            ? (is_scalar(\Tygh\Registry::get('config.dir.addons')) ? (string) \Tygh\Registry::get('config.dir.addons') : '')
            : '';

        $keysBaseDir = $addonsDir . 'netopia_payments/keys';

        // Always build URLs in the customer area — the customer returns to storefront,
        // not admin, even when links are generated from the admin panel.
        $notifyUrl = \function_exists('fn_url')
            ? (string) fn_url('payment_notification.notify?payment=netopia_payments', 'C', 'current')
            : '';
        $redirectUrl = \function_exists('fn_url')
            ? (string) fn_url('payment_notification.return?payment=netopia_payments', 'C', 'current')
            : '';

        $primaryCurrency = \defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : 'RON';

        return self::$instance = new self(
            keysBaseDir:     $keysBaseDir,
            notifyUrl:       $notifyUrl,
            redirectUrl:     $redirectUrl,
            primaryCurrency: $primaryCurrency,
            language:        'RO',
        );
    }

    /**
     * Construct an API client for the given processor params.
     *
     * @param array<string, mixed> $processorParams
     */
    public function apiClient(array $processorParams): ApiClient
    {
        return new ApiClient(
            apiKey: Arr::string($processorParams, 'api_key'),
            mode:   PaymentMode::fromMixed($processorParams['mode'] ?? null),
            logger: $this->logger,
        );
    }

    public function apiClientFor(string $apiKey, PaymentMode $mode): ApiClient
    {
        return new ApiClient($apiKey, $mode, $this->logger);
    }

    public function ipnHandler(
        Closure $orderLookup,
        Closure $processorDataLookup,
        Closure $paymentInfoUpdater,
        Closure $paymentFinalizer,
        Closure $orderStatusChanger,
        Closure $responder,
    ): IpnHandler {
        return new IpnHandler(
            verifier:             $this->ipnVerifier,
            keyStorage:           $this->keyStorage,
            statusMapper:         $this->statusMapper,
            statusMessage:        $this->statusMessage,
            orderLookup:          $orderLookup,
            processorDataLookup:  $processorDataLookup,
            paymentInfoUpdater:   $paymentInfoUpdater,
            paymentFinalizer:     $paymentFinalizer,
            orderStatusChanger:   $orderStatusChanger,
            responder:            $responder,
            logger:               $this->logger,
        );
    }

    /**
     * Construct a RefundFinalizer for the admin-triggered refund flow. The
     * IPN handler does NOT use this — refund IPNs are acknowledged with no
     * DB writes (see `IpnHandler` for rationale).
     */
    public function refundFinalizer(
        Closure $paymentInfoUpdater,
        Closure $orderStatusChanger,
        Closure $refundEmailSender,
    ): RefundFinalizer {
        return new RefundFinalizer(
            statusMapper:       $this->statusMapper,
            paymentInfoUpdater: $paymentInfoUpdater,
            orderStatusChanger: $orderStatusChanger,
            refundEmailSender:  $refundEmailSender,
            clock:              $this->clock,
            logger:             $this->logger,
        );
    }

    /**
     * Construct a RefundService for the given processor params.
     *
     * @param array<string, mixed> $processorParams
     */
    public function refundService(array $processorParams): RefundService
    {
        return new RefundService(
            apiClient: $this->apiClient($processorParams),
            logger:    $this->logger,
        );
    }

    /**
     * DB-backed claim store for refund attempts. Exists to serialise
     * concurrent admin clicks against the same `(order, amount, sequence)`
     * intent so duplicate refunds are physically impossible (the table's
     * PRIMARY KEY on `request_id` is the synchronization primitive).
     *
     * The closures bind to CS-Cart's globals at call time so the underlying
     * `db_query` / `db_get_row` resolutions match the environment that owns
     * the request. Tests substitute an in-memory fake.
     */
    public function refundAttemptStore(): RefundAttemptStore
    {
        return new RefundAttemptStore(
            dbQuery:  static fn (string $sql, mixed ...$params): mixed => db_query($sql, ...$params),
            dbGetRow: static function (string $sql, mixed ...$params): array|false {
                $row = db_get_row($sql, ...$params);
                return is_array($row) ? $row : false;
            },
            clock:    $this->clock,
            logger:   $this->logger,
        );
    }

    public function threeDsReturnHandler(
        ThreeDsSessionStore $session,
        Closure $orderLookup,
        Closure $processorDataLookup,
        Closure $paymentInfoUpdater,
        Closure $paymentFinalizer,
        Closure $placementRouter,
        Closure $checkoutRedirect,
    ): ThreeDsReturnHandler {
        return new ThreeDsReturnHandler(
            session:             $session,
            payloadBuilder:      $this->payloadBuilder,
            statusMapper:        $this->statusMapper,
            statusMessage:       $this->statusMessage,
            orderLookup:         $orderLookup,
            processorDataLookup: $processorDataLookup,
            paymentInfoUpdater:  $paymentInfoUpdater,
            paymentFinalizer:    $paymentFinalizer,
            placementRouter:     $placementRouter,
            checkoutRedirect:    $checkoutRedirect,
            apiClientFactory:    Closure::fromCallable([$this, 'apiClientFor']),
            logger:              $this->logger,
        );
    }

    public function paymentLinkService(
        Closure $paymentInfoUpdater,
    ): PaymentLinkService {
        return new PaymentLinkService(
            payloadBuilder:      $this->payloadBuilder,
            threeDsFactory:      $this->threeDsFactory,
            apiClientFactory:    Closure::fromCallable([$this, 'apiClientFor']),
            paymentInfoUpdater:  $paymentInfoUpdater,
            logger:              $this->logger,
        );
    }

    public function paymentLinkEmailSender(
        Closure $mailSender,
    ): PaymentLinkEmailSender {
        [$companyName, , $fallbackLangCode] = $this->resolveEmailContext();

        return new PaymentLinkEmailSender(
            mailSender:        $mailSender,
            companyName:       $companyName,
            fallbackLangCode:  $fallbackLangCode,
            logger:            $this->logger,
        );
    }

    public function refundEmailSender(
        Closure $mailSender,
    ): RefundEmailSender {
        [$companyName, $primaryCurrency, $fallbackLangCode] = $this->resolveEmailContext();

        return new RefundEmailSender(
            mailSender:        $mailSender,
            companyName:       $companyName,
            primaryCurrency:   $primaryCurrency,
            fallbackLangCode:  $fallbackLangCode,
            logger:            $this->logger,
        );
    }

    /**
     * @return array{string, string, string} [companyName, primaryCurrency, fallbackLangCode]
     */
    private function resolveEmailContext(): array
    {
        $companyNameRaw = \class_exists(\Tygh\Registry::class)
            ? \Tygh\Registry::get('settings.Company.company_name')
            : null;
        $companyName = is_scalar($companyNameRaw) && (string) $companyNameRaw !== ''
            ? (string) $companyNameRaw
            : 'Our Store';

        $primaryCurrency = \defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : 'RON';

        $fallbackLangCodeRaw = \class_exists(\Tygh\Registry::class)
            ? \Tygh\Registry::get('settings.Appearance.frontend_default_language')
            : null;
        $fallbackLangCode = is_scalar($fallbackLangCodeRaw) && (string) $fallbackLangCodeRaw !== ''
            ? (string) $fallbackLangCodeRaw
            : 'en';

        return [$companyName, $primaryCurrency, $fallbackLangCode];
    }

    /** Reset the singleton (for tests). */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
