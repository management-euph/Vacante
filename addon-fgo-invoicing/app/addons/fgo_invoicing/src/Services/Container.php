<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;
use Tygh\Addons\FgoInvoicing\Api\FgoHttpClient;
use Tygh\Addons\FgoInvoicing\Repository\DiagnosticLogRepository;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;
use Tygh\Addons\FgoInvoicing\Repository\ProfileFieldCatalog;
use Tygh\Addons\FgoInvoicing\Repository\ProfileFieldRepository;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkPrecheck;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkRunner;

/**
 * Tiny static-singleton DI container.
 *
 * Mirrors the pattern used by NovotonHolidays\Services\Container — every
 * service is lazily instantiated, cached, and resettable for tests.
 *
 * Hook code (which is procedural) reaches services via
 * `Container::getInstance()->issuer()`, etc.
 */
final class Container
{
    private static ?self $instance = null;

    private ?FgoHttpClient $http = null;
    private ?FgoApiClient $api = null;
    private ?InvoiceRepository $repo = null;
    private ?BillingMapper $mapper = null;
    private ?ProfileFieldCatalog $profileFields = null;
    private ?BillingExtrasResolver $resolver = null;
    private ?InvoiceIssuer $issuer = null;
    private ?InvoiceCanceler $canceler = null;
    private ?InvoiceMailer $mailer = null;
    private ?DiagnosticLogRepository $diagnostics = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Test seam: replace the singleton. Pass null to reset. */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    // ── Setters used by tests / advanced wiring ──────────────────────────

    public function withHttp(FgoHttpClient $http): self
    {
        $this->http = $http;
        // invalidate downstream caches so they pick up the new http client
        $this->api = null;
        $this->issuer = null;
        $this->canceler = null;
        return $this;
    }

    public function withApi(FgoApiClient $api): self
    {
        $this->api = $api;
        $this->issuer = null;
        $this->canceler = null;
        return $this;
    }

    public function withRepository(InvoiceRepository $repo): self
    {
        $this->repo = $repo;
        $this->issuer = null;
        $this->canceler = null;
        $this->mailer = null;
        return $this;
    }

    public function withMailer(InvoiceMailer $mailer): self
    {
        $this->mailer = $mailer;
        $this->issuer = null;
        return $this;
    }

    public function withDiagnostics(DiagnosticLogRepository $diagnostics): self
    {
        $this->diagnostics = $diagnostics;
        $this->issuer = null;
        return $this;
    }

    public function withMapper(BillingMapper $mapper): self
    {
        $this->mapper = $mapper;
        $this->issuer = null;
        return $this;
    }

    public function withProfileFieldCatalog(ProfileFieldCatalog $catalog): self
    {
        $this->profileFields = $catalog;
        // the resolver (and the issuer holding it) must see the new catalog
        $this->resolver = null;
        $this->issuer = null;
        return $this;
    }

    public function withResolver(BillingExtrasResolver $resolver): self
    {
        $this->resolver = $resolver;
        $this->issuer = null;
        return $this;
    }

    // ── Service accessors ────────────────────────────────────────────────

    public function http(): FgoHttpClient
    {
        if ($this->http === null) {
            $this->http = new FgoHttpClient(
                baseUrl:         ConfigProvider::apiBaseUrl(),
                maxRetries:      ConfigProvider::maxRetries(),
                retryDelayMs:    ConfigProvider::retryDelayMs(),
                retryMultiplier: 2.0,
                cbThreshold:     ConfigProvider::cbThreshold(),
                cbTimeout:       ConfigProvider::cbTimeout(),
                minIntervalMs:   ConfigProvider::minCallIntervalMs(),
                debugLogging:    ConfigProvider::debugLogging(),
            );
        }
        return $this->http;
    }

    public function api(): FgoApiClient
    {
        if ($this->api === null) {
            $this->api = new FgoApiClient(
                http:            $this->http(),
                clientCode:      ConfigProvider::clientCode(),
                privateKey:      ConfigProvider::privateKey(),
                platformUrl:     ConfigProvider::platformUrl(),
                platformVersion: ConfigProvider::platformVersion(),
                addonVersion:    ConfigProvider::addonVersion(),
            );
        }
        return $this->api;
    }

    public function repository(): InvoiceRepository
    {
        if ($this->repo === null) {
            $this->repo = new InvoiceRepository();
        }
        return $this->repo;
    }

    public function diagnostics(): DiagnosticLogRepository
    {
        if ($this->diagnostics === null) {
            $this->diagnostics = new DiagnosticLogRepository();
        }
        return $this->diagnostics;
    }

    public function mapper(): BillingMapper
    {
        if ($this->mapper === null) {
            $this->mapper = new BillingMapper(ConfigProvider::primaryCurrency());
        }
        return $this->mapper;
    }

    public function profileFieldCatalog(): ProfileFieldCatalog
    {
        if ($this->profileFields === null) {
            $this->profileFields = new ProfileFieldRepository();
        }
        return $this->profileFields;
    }

    public function billingExtrasResolver(): BillingExtrasResolver
    {
        if ($this->resolver === null) {
            $this->resolver = new BillingExtrasResolver(
                catalog:       $this->profileFieldCatalog(),
                legacyLookup:  BillingExtrasResolver::userProfileLookup(),
                cifFieldId:    ConfigProvider::cifFieldId(),
                regComFieldId: ConfigProvider::regComFieldId(),
                cnpFieldId:    ConfigProvider::cnpFieldId(),
            );
        }
        return $this->resolver;
    }

    public function mailer(): InvoiceMailer
    {
        if ($this->mailer === null) {
            $this->mailer = new InvoiceMailer(
                $this->repository(),
                InvoiceMailer::productionSender(),
                OrderInfoSource::core(),
            );
        }
        return $this->mailer;
    }

    public function issuer(): InvoiceIssuer
    {
        if ($this->issuer === null) {
            $this->issuer = new InvoiceIssuer(
                $this->api(),
                $this->repository(),
                $this->mapper(),
                $this->billingExtrasResolver(),
                $this->mailer(),
                $this->diagnostics(),
            );
        }
        return $this->issuer;
    }

    /**
     * The bulk pre-check with this store's facts: the identity settings,
     * whether the store has a profile field a CIF / CNP could be typed into
     * (the same condition InvoiceIssuer blocks on), and whether an invoice
     * series is set (FGO requires one). Not cached: it is cheap,
     * and the settings are read when it is built.
     *
     * @param array<string, string> $statusNames order status code => name
     */
    public function bulkPrecheck(array $statusNames = []): BulkPrecheck
    {
        $resolver = $this->billingExtrasResolver();

        return new BulkPrecheck(
            mapper:            $this->mapper(),
            clientVatRequired: ConfigProvider::clientVatRequired(),
            clientCnpRequired: ConfigProvider::clientCnpRequired(),
            hasCifSource:      $resolver->hasSourceFor(BillingExtrasResolver::KEY_CIF),
            hasCnpSource:      $resolver->hasSourceFor(BillingExtrasResolver::KEY_CNP),
            statusNames:       $statusNames,
            seriesConfigured:  ConfigProvider::invoiceSeries() !== '',
        );
    }

    /**
     * @param array<string, string> $statusNames order status code => name
     */
    public function bulkRunner(array $statusNames = []): BulkRunner
    {
        return new BulkRunner(
            precheck: $this->bulkPrecheck($statusNames),
            resolver: $this->billingExtrasResolver(),
            repo:     $this->repository(),
            issuer:   $this->issuer(),
            canceler: $this->canceler(),
            mailer:   $this->mailer(),
        );
    }

    public function canceler(): InvoiceCanceler
    {
        if ($this->canceler === null) {
            $this->canceler = new InvoiceCanceler($this->api(), $this->repository());
        }
        return $this->canceler;
    }
}
