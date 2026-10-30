<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\ReservationExchange;
use Tygh\Addons\NovotonHolidays\Services\SupplierRoomLabels;

/**
 * A booking's exchange with Novoton on its admin page: the notes, the
 * hotel's rooms, the reservation request built from the stored booking
 * data, Novoton's answer and that data. (__() returns the key in tests.)
 */
#[CoversClass(ReservationExchange::class)]
final class ReservationExchangeTest extends TestCase
{
    private const string API_REQUEST = '{"hotel_id":"4658","check_in":"2026-11-02","check_out":"2026-11-08","room_id":"Doble room",'
        . '"guests":[{"name":"fff fff","age":30},{"name":"gg fff","age":30}],"order_num":"19"}';

    /** @var list<array<string, mixed>> what the request builder was given */
    private array $built = [];

    private function exchange(): ReservationExchange
    {
        return new ReservationExchange(
            function (array $data): string {
                $this->built[] = $data;

                return '<hotel_res_RQ><IdHotel>' . $data['hotel_id'] . '</IdHotel></hotel_res_RQ>';
            },
            new SupplierRoomLabels(static fn (string $hotelId): array => $hotelId === '4658'
                ? ['DBL 2+0' => 'DBL', 'SGL' => 'SGL', 'STUDIO 2+1' => 'STUDIO', 'FAM 2+2' => 'FAMILY']
                : []),
        );
    }

    /**
     * @param list<array{label: string, value: string, is_pre: bool}> $rows
     * @return array<string, array{label: string, value: string, is_pre: bool}>
     */
    private static function byLabel(array $rows): array
    {
        return array_column($rows, null, 'label');
    }

    public function testTheShortRowsComeFirstThenTheRequestTheAnswerAndTheData(): void
    {
        self::assertSame(
            [
                'novoton_holidays.bv_notes',
                'novoton_holidays.bv_hotel_rooms',
                'novoton_holidays.bv_request',
                'novoton_holidays.bv_answer',
                'novoton_holidays.bv_request_data',
            ],
            array_column($this->exchange()->rows(['hotel_id' => '4658']), 'label'),
        );
    }

    public function testTheRequestIsBuiltFromTheStoredBookingData(): void
    {
        $rows = self::byLabel($this->exchange()->rows(['hotel_id' => '4658', 'api_request' => self::API_REQUEST]));

        self::assertSame('<hotel_res_RQ><IdHotel>4658</IdHotel></hotel_res_RQ>', $rows['novoton_holidays.bv_request']['value']);
        self::assertTrue($rows['novoton_holidays.bv_request']['is_pre']);
        self::assertSame('Doble room', $this->built[0]['room_id'] ?? null);
        self::assertStringContainsString('"name": "gg fff"', $rows['novoton_holidays.bv_request_data']['value'], 'the data, pretty');
    }

    public function testWithoutStoredDataThereIsNoRequestToShow(): void
    {
        $rows = self::byLabel($this->exchange()->rows(['hotel_id' => '4658', 'api_request' => null]));

        self::assertSame('', $rows['novoton_holidays.bv_request']['value']);
        self::assertFalse($rows['novoton_holidays.bv_request']['is_pre'], 'an empty row shows as empty, not as a block');
        self::assertSame('', $rows['novoton_holidays.bv_request_data']['value']);
        self::assertSame([], $this->built);
    }

    public function testTheAnswerReadsAsPrettyJson(): void
    {
        $rows = self::byLabel($this->exchange()->rows(['api_response' => '{"IdNum":"311921","Status":"Good"}']));

        self::assertSame("{\n    \"IdNum\": \"311921\",\n    \"Status\": \"Good\"\n}", $rows['novoton_holidays.bv_answer']['value']);
        self::assertSame('', self::byLabel($this->exchange()->rows([]))['novoton_holidays.bv_answer']['value'], 'no answer yet');
    }

    public function testTheNotesAndTheHotelsRoomsSayWhatTheRequestCannot(): void
    {
        $rows = self::byLabel($this->exchange()->rows([
            'hotel_id' => '4658',
            'notes' => 'API submission disabled - booking saved locally only.',
        ]));

        self::assertSame('API submission disabled - booking saved locally only.', $rows['novoton_holidays.bv_notes']['value']);
        self::assertSame('DBL 2+0 (DBL) · SGL · STUDIO 2+1 (STUDIO) · FAM 2+2 (FAMILY)', $rows['novoton_holidays.bv_hotel_rooms']['value']);
        self::assertSame('', self::byLabel($this->exchange()->rows(['hotel_id' => '1']))['novoton_holidays.bv_hotel_rooms']['value']);
    }

    public function testARequestThatCannotBeBuiltSaysWhyInsteadOfBreakingThePage(): void
    {
        $exchange = new ReservationExchange(
            static fn (): string => throw new \InvalidArgumentException('Novoton API URL not configured'),
            new SupplierRoomLabels(static fn (): array => []),
        );

        $rows = self::byLabel($exchange->rows(['api_request' => self::API_REQUEST]));

        self::assertSame('novoton_holidays.bv_request_unavailable', $rows['novoton_holidays.bv_request']['value']);
    }
}
