<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\ReservationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\AlternativeRequestService;
use Tygh\Addons\NovotonHolidays\Services\SecurityServiceInterface;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * The hotel name of an availability request went from a hidden form field into
 * the request sent to Novoton, the stored record and the e-mail sent to the
 * address typed in: anyone could put their own text in the shop's e-mail. The
 * name now comes from novoton_hotels.
 */
final class AlternativeRequestHotelNameTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sent = [];

    /** @var array<string, mixed> */
    private array $stored = [];

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** @param array<string, mixed>|null $hotelRow */
    private function submit(?array $hotelRow, bool $apiCalled = true): array
    {
        DbStub::reset();
        DbStub::$getRow = static fn (): ?array => $hotelRow;

        $api = $this->createMock(ReservationApiClientInterface::class);
        $api->expects($apiCalled ? self::once() : self::never())->method('createHotelRequest')
            ->willReturnCallback(function (array $requestData): never {
                $this->sent = $requestData;
                // Stop before the e-mail: the request is then stored for staff
                throw ApiException::busy('hotel_request', 1.0);
            });
        $repo = $this->createMock(AlternativeRequestRepositoryInterface::class);
        $repo->method('create')->willReturnCallback(function (array $record): int {
            $this->stored = $record;
            return 7;
        });
        $service = new AlternativeRequestService($this->createMock(SecurityServiceInterface::class), $api, $repo);

        return $service->submitAlternativeBookingRequest([
            'hotel_id' => '4535',
            'hotel_name' => 'Your account is locked: call +00 000',
            'check_in' => '2027-07-10',
            'nights' => 7,
            'contact_email' => 'guest@example.com',
        ]);
    }

    public function testTheNameIsTheHotelsOwnNotTheForms(): void
    {
        $result = $this->submit(['hotel_id' => '4535', 'hotel_name' => 'Edart']);

        self::assertSame('alternatives_request_saved', $result['message']);
        self::assertSame('Edart', $this->sent['package_name']);
        self::assertSame('Edart', $this->stored['hotel_name']);
    }

    public function testAHotelWithoutANameIsNamedByItsCode(): void
    {
        $this->submit(['hotel_id' => '4535', 'hotel_name' => null]);

        self::assertSame('4535', $this->sent['package_name']);
    }

    public function testAnUnknownHotelIsRefusedBeforeNovotonIsAsked(): void
    {
        $result = $this->submit(null, false);

        self::assertSame('invalid_hotel', $result['error']);
        self::assertSame([], $this->stored);
    }
}
