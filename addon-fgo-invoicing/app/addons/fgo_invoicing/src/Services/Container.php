<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;
use Tygh\Addons\FgoInvoicing\Api\FgoHttpClient;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;
use Tygh\Addons\FgoInvoicing\Repository\ProfileFieldCatalog;
use Tygh\Addons\FgoInvoicing\Repository\ProfileFieldRepository;

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

    public function issuer(): InvoiceIssuer
    {
        if ($this->issuer === null) {
            $this->issuer = new InvoiceIssuer(
                $this->api(),
                $this->repository(),
                $this->mapper(),
                $this->billingExtrasResolver(),
            );
        }
        return $this->issuer;
    }

    public function canceler(): InvoiceCanceler
    {
        if ($this->canceler === null) {
            $this->canceler = new InvoiceCanceler($this->api(), $this->repository());
        }
        return $this->canceler;
    }
}
