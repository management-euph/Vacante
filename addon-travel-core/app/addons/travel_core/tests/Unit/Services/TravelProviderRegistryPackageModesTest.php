<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\PackageModesProviderInterface;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

/**
 * A hotel's page gets "Cu autocarul" / "Cu avionul" (board 16.2) when the
 * provider that sells the hotel has packages to it; otherwise it stays
 * accommodation only, as before, whatever went wrong.
 */
#[CoversClass(TravelProviderRegistry::class)]
final class TravelProviderRegistryPackageModesTest extends TestCase
{
    private const array MODES = [['transport' => 'flight', 'departures' => [['id' => 10, 'name' => 'Bucuresti']]]];

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

    /**
     * @param list<array{transport: string, departures: list<array{id: int, name: string}>}> $modes
     * @param list<string> $asked filled with each hotel asked about
     */
    private static function provider(array $modes, array &$asked, bool $fails = false): PackageModesProviderInterface
    {
        return new class ($modes, $asked, $fails) implements PackageModesProviderInterface {
            /**
             * @param list<array{transport: string, departures: list<array{id: int, name: string}>}> $modes
             * @param list<string> $asked
             */
            public function __construct(private readonly array $modes, private array &$asked, private readonly bool $fails)
            {
            }

            #[\Override]
            public function packageModes(string $hotelId): array
            {
                $this->asked[] = $hotelId;
                if ($this->fails) {
                    throw new \RuntimeException('routes table missing');
                }

                return $this->modes;
            }
        };
    }

    public function testTheHotelsProviderAnswers(): void
    {
        $asked = [];
        $this->registerProvider('sphinx');
        TravelProviderRegistry::setPackageModesProvider('sphinx', self::provider(self::MODES, $asked));

        self::assertSame(self::MODES, TravelProviderRegistry::packageModes('sphinx', '3612'));
        self::assertSame(['3612'], $asked);
        self::assertSame([], TravelProviderRegistry::packageModes('novoton', '3612'), 'a provider without packages: accommodation only');
        self::assertSame([], TravelProviderRegistry::packageModes('sphinx', ''), 'no hotel');
    }

    public function testAFailureLeavesAccommodationOnly(): void
    {
        $asked = [];
        $this->registerProvider('sphinx');
        TravelProviderRegistry::setPackageModesProvider('sphinx', self::provider(self::MODES, $asked, true));

        self::assertSame([], TravelProviderRegistry::packageModes('sphinx', '3612'));
    }

    public function testOnlyARegisteredProvider(): void
    {
        $asked = [];
        TravelProviderRegistry::setPackageModesProvider('sphinx', self::provider(self::MODES, $asked));
        self::assertSame([], TravelProviderRegistry::packageModes('sphinx', '3612'), 'register() first');

        $this->registerProvider('sphinx');
        TravelProviderRegistry::setPackageModesProvider('sphinx', self::provider(self::MODES, $asked));
        TravelProviderRegistry::reset();
        $this->registerProvider('sphinx');
        self::assertSame([], TravelProviderRegistry::packageModes('sphinx', '3612'), 'reset() clears it');
        self::assertSame([], $asked);
    }
}
