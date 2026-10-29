<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\OfferChangeMarker;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * What offers_update names is left on the rows (Novoton, 28 Oct 2026: call
 * priceinfo only for what offers_update returned).
 */
final class OfferChangeMarkerTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $writes = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->writes = [];
        DbStub::$query = function (string $query, mixed ...$params): int {
            $this->writes[] = [$query, $params];

            return 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testAKnownPackageIsAskedForAgainByPriceinfo(): void
    {
        DbStub::$getField = static fn (string $query, mixed ...$params): ?string => $params === ['476', 'Summer 2027'] ? '1295781' : null;

        self::assertSame(OfferChangeMarker::PACKAGE, (new OfferChangeMarker())->mark('476', ' Summer 2027 ', '2026-11-10 05:00:00'));

        self::assertCount(1, $this->writes);
        [$sql, $params] = $this->writes[0];
        self::assertStringContainsString('UPDATE ?:novoton_hotel_packages SET price_changed_at = ?s', $sql);
        self::assertSame(['2026-11-10 05:00:00', '476', 'Summer 2027'], $params);
    }

    public function testAPackageWeDoNotKnowSendsItsHotelToHotelinfo(): void
    {
        DbStub::$getField = static fn (): ?string => null;

        self::assertSame(OfferChangeMarker::HOTEL, (new OfferChangeMarker())->mark('476', 'Winter 2027', '2026-11-10 05:00:00'));

        [$sql, $params] = $this->writes[0];
        self::assertStringContainsString('UPDATE ?:novoton_hotels SET hotelinfo_due_at = ?s', $sql);
        self::assertSame(['2026-11-10 05:00:00', '476'], $params);
    }

    public function testAnOfferWithoutAPackageNameMarksTheHotel(): void
    {
        DbStub::$getField = static fn (): string => 'never asked';

        self::assertSame(OfferChangeMarker::HOTEL, (new OfferChangeMarker())->mark('476', '', '2026-11-10 05:00:00'));
    }
}
