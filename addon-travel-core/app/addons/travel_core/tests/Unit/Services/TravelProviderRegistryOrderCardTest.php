<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

/**
 * The order booking card asks the registry what the provider's booking row
 * knows about a line; the provider that owns the line answers, and the
 * registry says which provider that was.
 */
#[CoversClass(TravelProviderRegistry::class)]
final class TravelProviderRegistryOrderCardTest extends TestCase
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

    public function testTheOwningProviderAnswersAndIsNamed(): void
    {
        $this->registerProvider('novoton');
        $this->registerProvider('eurosite');
        TravelProviderRegistry::setOrderCardResolver(
            'novoton',
            static fn (array $extra): array => !empty($extra['novoton_booking']) ? ['reference' => 'NT 1'] : [],
        );
        TravelProviderRegistry::setOrderCardResolver(
            'eurosite',
            static fn (array $extra): array => !empty($extra['eurosite_booking_id']) ? ['reference' => '778899'] : [],
        );

        self::assertSame(['provider' => 'eurosite', 'reference' => '778899'], TravelProviderRegistry::orderCardFacts(['eurosite_booking_id' => 41]));
        self::assertSame(['provider' => 'novoton', 'reference' => 'NT 1'], TravelProviderRegistry::orderCardFacts(['novoton_booking' => true]));
        self::assertSame([], TravelProviderRegistry::orderCardFacts(['sphinx_booking' => true]), 'nobody owns it');
    }

    public function testAProviderCannotClaimToBeAnother(): void
    {
        $this->registerProvider('sphinx');
        TravelProviderRegistry::setOrderCardResolver('sphinx', static fn (array $extra): array => ['provider' => 'novoton']);

        self::assertSame('sphinx', TravelProviderRegistry::orderCardFacts([])['provider']);
    }

    public function testAnUnregisteredProviderCannotAddAResolver(): void
    {
        TravelProviderRegistry::setOrderCardResolver('sphinx', static fn (array $extra): array => ['reference' => 'x']);

        self::assertSame([], TravelProviderRegistry::orderCardFacts(['sphinx_booking' => true]));
    }

    public function testResetForgetsTheResolvers(): void
    {
        $this->registerProvider('sphinx');
        TravelProviderRegistry::setOrderCardResolver('sphinx', static fn (array $extra): array => ['reference' => 'x']);
        TravelProviderRegistry::reset();
        $this->registerProvider('sphinx');

        self::assertSame([], TravelProviderRegistry::orderCardFacts([]));
    }
}
