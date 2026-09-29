<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;
use Tygh\Addons\FgoInvoicing\Repository\ProfileFieldRepository;
use Tygh\Addons\FgoInvoicing\Services\BillingExtrasResolver;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkAction;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkRunResult;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckVerdict;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Addons\FgoInvoicing\Services\Container;
use Tygh\Addons\FgoInvoicing\Services\InvoiceMailer;
use Tygh\Addons\FgoInvoicing\Tests\Support\DbStub;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryInvoiceRepository;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryProfileFieldCatalog;

/**
 * The hooks reach every service through the Container, so its wiring IS the
 * production configuration: the resolver must carry the settings' field ids
 * and a catalog swap must reach it (and the issuer holding it).
 */
#[CoversClass(Container::class)]
final class ContainerTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        Container::reset();
        ConfigProvider::reset();
        DbStub::reset();
    }

    public function testServicesAreBuiltLazilyAndCached(): void
    {
        ConfigProvider::seed([]);
        $container = Container::getInstance();

        self::assertSame($container, Container::getInstance());
        self::assertInstanceOf(ProfileFieldRepository::class, $container->profileFieldCatalog());
        self::assertSame($container->profileFieldCatalog(), $container->profileFieldCatalog());
        self::assertSame($container->billingExtrasResolver(), $container->billingExtrasResolver());
        self::assertSame($container->mapper(), $container->mapper());
        self::assertSame([], DbStub::calls(), 'building services queries nothing');
    }

    public function testTheResolverCarriesTheConfiguredFieldIds(): void
    {
        ConfigProvider::seed(['cif_field' => '12', 'reg_com_field' => '13', 'cnp_field' => '14']);
        $catalog = new InMemoryProfileFieldCatalog();
        $resolver = Container::getInstance()->withProfileFieldCatalog($catalog)->billingExtrasResolver();

        $out = $resolver->resolve(['fields' => [12 => 'RO1', 13 => 'J1', 14 => '1960101123456']]);

        self::assertSame('RO1', $out['fgo_billing_cui']);
        self::assertSame('J1', $out['fgo_billing_reg']);
        self::assertSame('1960101123456', $out['fgo_billing_cnp']);
        self::assertSame(0, $catalog->reads, 'configured fields need no auto-detection');
    }

    public function testSwappingTheCatalogRebuildsTheResolver(): void
    {
        ConfigProvider::seed([]);
        $container = Container::getInstance();
        $before = $container->billingExtrasResolver();

        $container->withProfileFieldCatalog(InMemoryProfileFieldCatalog::withDescriptions([5 => 'CUI']));
        $after = $container->billingExtrasResolver();

        self::assertNotSame($before, $after);
        self::assertSame('RO5', $after->resolve(['fields' => [5 => 'RO5']])['fgo_billing_cui']);
    }

    public function testAnInjectedResolverIsKept(): void
    {
        $resolver = new BillingExtrasResolver(new InMemoryProfileFieldCatalog());

        self::assertSame($resolver, Container::getInstance()->withResolver($resolver)->billingExtrasResolver());
    }

    public function testTheIssuerAndTheBulkPagesShareOneMailer(): void
    {
        ConfigProvider::seed([]);
        $container = Container::getInstance();

        self::assertSame($container->mailer(), $container->mailer());
        self::assertSame($container->issuer(), $container->issuer());

        $mailer = new InvoiceMailer(new InvoiceRepository(), static fn (array $p): bool => true, static fn (int $id): ?array => null);
        $issuerBefore = $container->issuer();
        $container->withMailer($mailer);

        self::assertSame($mailer, $container->mailer());
        self::assertNotSame($issuerBefore, $container->issuer(), 'the issuer is rebuilt around the new mailer');
    }

    public function testSwappingTheRepositoryRebuildsTheMailer(): void
    {
        ConfigProvider::seed([]);
        $container = Container::getInstance();
        $before = $container->mailer();

        $container->withRepository(new InvoiceRepository());

        self::assertNotSame($before, $container->mailer());
    }

    /**
     * The pre-check blocks on a missing CIF only when the setting requires
     * it AND the store has a field for it: both facts must come from the
     * same places InvoiceIssuer reads them.
     */
    public function testTheBulkPrecheckCarriesTheStoreFacts(): void
    {
        $order = [
            'order_id' => 3,
            'status' => 'P',
            'total' => 10,
            'company' => 'SC ACME SRL',
            'b_firstname' => 'Ion',
            'b_country' => 'RO',
        ];

        ConfigProvider::seed(['client_vat_required' => 'Y']);
        $container = Container::getInstance()->withProfileFieldCatalog(InMemoryProfileFieldCatalog::withDescriptions([5 => 'CIF']));
        $withField = $container->bulkPrecheck(['P' => 'Processed'])->check(BulkAction::Issue, $order, null);
        self::assertSame(PrecheckVerdict::Block, $withField->verdict);

        $container->withProfileFieldCatalog(new InMemoryProfileFieldCatalog());
        $withoutField = $container->bulkPrecheck()->check(BulkAction::Issue, $order, null);
        self::assertSame(PrecheckVerdict::Warn, $withoutField->verdict);

        ConfigProvider::seed([]);
        $container->withProfileFieldCatalog(InMemoryProfileFieldCatalog::withDescriptions([5 => 'CIF']));
        self::assertSame(PrecheckVerdict::Warn, $container->bulkPrecheck()->check(BulkAction::Issue, $order, null)->verdict);
    }

    public function testTheBulkRunnerIsWiredToTheContainersServices(): void
    {
        ConfigProvider::seed([]);
        $repo = new InMemoryInvoiceRepository();
        $container = Container::getInstance()->withRepository($repo)->withProfileFieldCatalog(new InMemoryProfileFieldCatalog());

        // No fn_get_order_info() in the unit bootstrap: the order is "gone".
        $result = $container->bulkRunner()->run(BulkAction::Issue, 7);

        self::assertSame(BulkRunResult::OUTCOME_FAILED, $result->outcome);
        self::assertSame('order_not_found', $result->reasons[0]->code);
        self::assertSame([], $repo->rows, 'nothing was attempted');
    }

    /**
     * The mapper must be wired with the STORE'S primary currency. Pinned with
     * a primary currency other than the 'RON' fallback and a browsing currency
     * other than both, so wiring `new BillingMapper('RON')` (EUR amounts
     * invoiced as RON) or reading secondary_currency again both fail here.
     * Separate process: CART_PRIMARY_CURRENCY is process-global and permanent.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheMapperInvoicesInTheStorePrimaryCurrency(): void
    {
        define('CART_PRIMARY_CURRENCY', 'EUR');
        ConfigProvider::seed([]);

        $request = Container::getInstance()->mapper()->mapOrderInfo([
            'order_id' => 1,
            'b_firstname' => 'Ion',
            'secondary_currency' => 'USD',
        ]);

        self::assertSame('EUR', $request->valuta);
    }
}
