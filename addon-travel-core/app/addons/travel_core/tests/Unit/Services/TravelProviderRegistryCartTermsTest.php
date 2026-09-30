<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

/**
 * The cart booking card asks the registry for a line's terms; the provider
 * that owns the line answers, every other one returns [].
 */
#[CoversClass(TravelProviderRegistry::class)]
final class TravelProviderRegistryCartTermsTest extends TestCase
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

    public function testTheOwningProviderAnswers(): void
    {
        $this->registerProvider('novoton');
        $this->registerProvider('sphinx');
        TravelProviderRegistry::setCartTermsResolver(
            'novoton',
            static fn (array $extra): array => !empty($extra['novoton_booking']) ? ['cancel_lines' => ['novoton']] : [],
        );
        TravelProviderRegistry::setCartTermsResolver(
            'sphinx',
            static fn (array $extra): array => ($extra['travel_provider'] ?? null) === 'sphinx' ? ['cancel_lines' => ['sphinx']] : [],
        );

        self::assertSame(['cancel_lines' => ['sphinx']], TravelProviderRegistry::cartTerms(['travel_provider' => 'sphinx']));
        self::assertSame(['cancel_lines' => ['novoton']], TravelProviderRegistry::cartTerms(['novoton_booking' => true]));
        self::assertSame([], TravelProviderRegistry::cartTerms(['eurosite_booking_id' => 3]), 'nobody owns it');
    }

    public function testAnUnregisteredProviderCannotAddAResolver(): void
    {
        TravelProviderRegistry::setCartTermsResolver('sphinx', static fn (array $extra): array => ['cancel_lines' => ['x']]);

        self::assertSame([], TravelProviderRegistry::cartTerms(['travel_provider' => 'sphinx']));
    }
}
