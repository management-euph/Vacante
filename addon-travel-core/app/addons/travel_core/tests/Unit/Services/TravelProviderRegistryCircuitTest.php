<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\CircuitProductProviderInterface;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

/**
 * A product page that is not a hotel's may be a circuit's (board 16.3): the
 * registry asks the providers that sell circuits, and only registered ones.
 */
#[CoversClass(TravelProviderRegistry::class)]
#[CoversClass(CircuitPage::class)]
final class TravelProviderRegistryCircuitTest extends TestCase
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

    /**
     * @param array<int, CircuitPage> $circuits product id => its circuit
     *
     * @return CircuitProductProviderInterface answering for those products only
     */
    private static function provider(array $circuits): CircuitProductProviderInterface
    {
        return new class ($circuits) implements CircuitProductProviderInterface {
            /** @param array<int, CircuitPage> $circuits product id => its circuit */
            public function __construct(private readonly array $circuits)
            {
            }

            #[\Override]
            public function resolveCircuit(int $productId, string $productCode): ?CircuitPage
            {
                return $this->circuits[$productId] ?? null;
            }
        };
    }

    /**
     * @param list<array{id: string, name: string}> $departures
     */
    private static function circuit(string $id, array $departures = []): CircuitPage
    {
        return new CircuitPage('sphinx', $id, 'Creta clasică', $departures, 5, 4);
    }

    public function testTheProviderThatSellsTheCircuitAnswers(): void
    {
        $this->registerProvider('sphinx');
        TravelProviderRegistry::setCircuitProductProvider('sphinx', self::provider([110 => self::circuit('1')]));

        self::assertSame('1', TravelProviderRegistry::resolveCircuit(110, 'SPXC1')?->circuitId);
        self::assertNull(TravelProviderRegistry::resolveCircuit(111, 'SPX7'), 'not one of its circuits');
    }

    public function testAProviderNotRegisteredIsNeverAsked(): void
    {
        TravelProviderRegistry::setCircuitProductProvider('sphinx', self::provider([110 => self::circuit('1')]));

        self::assertNull(TravelProviderRegistry::resolveCircuit(110, 'SPXC1'));
    }

    public function testTheBarStartsFromBucharestWhenTheCircuitLeavesFromThere(): void
    {
        $departures = [['id' => '7', 'name' => 'Cluj-Napoca'], ['id' => '3', 'name' => 'București'], ['id' => '9', 'name' => 'Iași']];

        self::assertSame('3', self::circuit('1', $departures)->defaultDepartureId());
        self::assertSame('7', self::circuit('1', [['id' => '7', 'name' => 'Cluj-Napoca']])->defaultDepartureId(), 'else the first');
        self::assertSame('', self::circuit('1')->defaultDepartureId(), 'no departure known');
    }
}
