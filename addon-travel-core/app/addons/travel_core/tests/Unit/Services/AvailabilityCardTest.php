<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\Services\AvailabilityCard;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * The Hotel availability card on the Novoton, Eurosite and Sphinx
 * dashboards: that supplier's hotels per state, and nothing that could stop
 * the dashboard from showing.
 */
final class AvailabilityCardTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
        TravelProviderRegistry::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        TravelProviderRegistry::reset();
    }

    public function testTheCardCountsOnlyItsSuppliersStatesWithHotels(): void
    {
        TravelProviderRegistry::register('eurosite', 'Eurosite', $this->createStub(ProviderNormalizerInterface::class));
        DbStub::$getArray = static fn (): array => [
            ['provider' => 'eurosite', 'state' => 'between_seasons', 'hotels' => '40'],
            ['provider' => 'eurosite', 'state' => 'bookable', 'hotels' => '12'],
            ['provider' => 'novoton', 'state' => 'bookable', 'hotels' => '300'],
        ];

        $card = AvailabilityCard::build('eurosite');

        self::assertNotNull($card);
        self::assertSame('Eurosite', $card['label']);
        self::assertSame('report', $card['mode'], 'Report only until the admin says otherwise');
        self::assertSame(52, $card['total']);
        self::assertSame([
            ['state' => 'bookable', 'hotels' => 12, 'pill' => 'ok'],
            ['state' => 'between_seasons', 'hotels' => 40, 'pill' => 'info'],
        ], $card['counts'], 'in the states\' order, empty states left out');
        self::assertSame('', $card['reviewed_at'], 'not reviewed yet');
    }

    public function testAFailureHidesTheCardNotTheDashboard(): void
    {
        DbStub::$getArray = static fn (): never => throw new \RuntimeException('database down');

        self::assertNull(AvailabilityCard::build('sphinx'));
    }

    public function testEverySupplierDashboardShowsIt(): void
    {
        $root = dirname(__DIR__, 7);
        foreach ([
            'novoton' => ['addon-novoton-holidays/app/addons/novoton_holidays/controllers/backend/novoton_holidays.php', 'addon-novoton-holidays/design/backend/templates/addons/novoton_holidays/views/novoton_holidays/manage.tpl'],
            'eurosite' => ['addon-eurosite/app/addons/eurosite/controllers/backend/eurosite.php', 'addon-eurosite/design/backend/templates/addons/eurosite/views/eurosite/manage.tpl'],
            'sphinx' => ['addon-sphinx-holidays/app/addons/sphinx_holidays/controllers/backend/sphinx_holidays.php', 'addon-sphinx-holidays/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/manage.tpl'],
        ] as $provider => [$controller, $template]) {
            self::assertStringContainsString(
                "\$view->assign('{$provider}_availability_card', \\Tygh\\Addons\\TravelCore\\Services\\AvailabilityCard::build('{$provider}'));",
                (string) file_get_contents($root . '/' . $controller),
                $controller,
            );
            self::assertStringContainsString(
                "{include file=\"addons/travel_core/components/availability_card.tpl\" card=\${$provider}_availability_card}",
                (string) file_get_contents($root . '/' . $template),
                $template,
            );
        }
    }
}
