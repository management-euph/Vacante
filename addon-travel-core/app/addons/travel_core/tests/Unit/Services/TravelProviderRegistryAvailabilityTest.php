<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\HotelAvailabilitySourceInterface;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\Enums\HotelListCoverage;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;
use Tygh\Addons\TravelCore\ValueObjects\AvailabilityCapabilities;

/**
 * Each provider registers the source of its availability records; the
 * records are keyed by provider, so a source may only speak for its own.
 */
#[CoversClass(TravelProviderRegistry::class)]
final class TravelProviderRegistryAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        TravelProviderRegistry::reset();
    }

    protected function tearDown(): void
    {
        TravelProviderRegistry::reset();
    }

    private function registerProvider(string $name): void
    {
        TravelProviderRegistry::register($name, ucfirst($name), $this->createStub(ProviderNormalizerInterface::class));
    }

    private static function source(string $provider): HotelAvailabilitySourceInterface
    {
        return new class ($provider) implements HotelAvailabilitySourceInterface {
            public function __construct(private readonly string $name)
            {
            }

            #[\Override]
            public function provider(): string
            {
                return $this->name;
            }

            #[\Override]
            public function capabilities(): AvailabilityCapabilities
            {
                return new AvailabilityCapabilities(HotelListCoverage::PerCountry, true, 'resort', false, true, true);
            }

            #[\Override]
            public function recordingModes(): array
            {
                return ['hotel_list', 'room_price'];
            }
        };
    }

    public function testRegisteredProvidersSupplyTheirSourcesInRegistrationOrder(): void
    {
        $this->registerProvider('novoton');
        $this->registerProvider('eurosite');
        TravelProviderRegistry::setAvailabilitySource('eurosite', self::source('eurosite'));
        TravelProviderRegistry::setAvailabilitySource('novoton', self::source('novoton'));

        $sources = TravelProviderRegistry::getAvailabilitySources();

        self::assertSame(['novoton', 'eurosite'], array_keys($sources));
        self::assertSame('eurosite', $sources['eurosite']->provider());
    }

    public function testASourceIsIgnoredUntilItsProviderIsRegistered(): void
    {
        TravelProviderRegistry::setAvailabilitySource('sphinx', self::source('sphinx'));

        self::assertSame([], TravelProviderRegistry::getAvailabilitySources());
    }

    public function testASourceCannotSpeakForAnotherProvider(): void
    {
        $this->registerProvider('sphinx');
        TravelProviderRegistry::setAvailabilitySource('sphinx', self::source('novoton'));

        self::assertSame([], TravelProviderRegistry::getAvailabilitySources());
    }

    public function testResetForgetsTheSources(): void
    {
        $this->registerProvider('novoton');
        TravelProviderRegistry::setAvailabilitySource('novoton', self::source('novoton'));
        TravelProviderRegistry::reset();
        $this->registerProvider('novoton');

        self::assertSame([], TravelProviderRegistry::getAvailabilitySources());
    }
}
