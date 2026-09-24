<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

/**
 * Providers declare their own cron row; the registry only keeps what is valid.
 */
#[CoversClass(TravelProviderRegistry::class)]
final class TravelProviderRegistryCronTest extends TestCase
{
    protected function setUp(): void
    {
        TravelProviderRegistry::reset();
    }

    protected function tearDown(): void
    {
        TravelProviderRegistry::reset();
    }

    private function registerProvider(string $name, string $label): void
    {
        TravelProviderRegistry::register($name, $label, $this->createStub(ProviderNormalizerInterface::class));
    }

    public function testARegisteredProviderSuppliesItsCronRow(): void
    {
        $this->registerProvider('sphinx', 'Sphinx / Christian Tour');
        TravelProviderRegistry::setCron('sphinx', 'sphinx_holidays', FakeCronDispatcher::class, 'sphinx_holidays.manage', 'sphinx-cron-commands');

        self::assertSame([
            'sphinx' => [
                'name' => 'sphinx',
                'label' => 'Sphinx / Christian Tour',
                'addon' => 'sphinx_holidays',
                'dispatcher' => FakeCronDispatcher::class,
                'dashboard' => 'sphinx_holidays.manage',
                'anchor' => 'sphinx-cron-commands',
            ],
        ], TravelProviderRegistry::getCronProviders());
    }

    /** No register() first means the add-on is not active: no row. */
    public function testAnUnregisteredProviderCannotAddARow(): void
    {
        TravelProviderRegistry::setCron('ghost', 'ghost_addon', FakeCronDispatcher::class, 'ghost.manage');

        self::assertSame([], TravelProviderRegistry::getCronProviders());
    }

    /** The Tools page calls getAvailableModes(): anything else must be refused, not crash the page later. */
    public function testADispatcherThatIsNotACronDispatcherIsIgnored(): void
    {
        $this->registerProvider('eurosite', 'Eurosite');
        TravelProviderRegistry::setCron('eurosite', 'eurosite', \stdClass::class, 'eurosite.manage');

        self::assertSame([], TravelProviderRegistry::getCronProviders());
    }

    public function testResetClearsCronRowsToo(): void
    {
        $this->registerProvider('eurosite', 'Eurosite');
        TravelProviderRegistry::setCron('eurosite', 'eurosite', FakeCronDispatcher::class, 'eurosite.manage');
        TravelProviderRegistry::reset();

        self::assertSame([], TravelProviderRegistry::getCronProviders());
    }
}

/** @internal */
final class FakeCronDispatcher implements CronDispatcherInterface
{
    public function hasMode(string $mode): bool
    {
        return $mode === 'one';
    }

    public function dispatch(string $mode, array $params = []): array
    {
        return ['success' => true];
    }

    public static function getAvailableModes(): array
    {
        return ['one' => 'The one job', 'two' => 'The other job'];
    }
}
