<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Api\EurositeApiClient;
use Tygh\Addons\Eurosite\EurositeTransportInterface;
use Tygh\Addons\Eurosite\EurositeXmlBuilder;
use Tygh\Addons\Eurosite\EurositeXmlParser;
use Tygh\Addons\Eurosite\Services\BookingSubmissionService;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Tests\Support\DbStub;

/**
 * AddBookingResponse carries no status: a booking on request (CP,
 * "Confirmation pending") was saved as confirmed. It is saved pending, with
 * the time Eurosite took it, and the booking_status job asks from there.
 */
final class BookingSubmissionPendingTest extends TestCase
{
    /** The spec's AddBookingResponse example: references, no ItemStatus. */
    private const string TAKEN = '<?xml version="1.0" encoding="utf-8"?><Response ResponseType="AddBookingResponse"><ResponseDetails><AddBookingResponse>'
        . '<BookingReferences><BookingReference Source="api">EU_XML_12898</BookingReference><BookingReference Source="client">int1234</BookingReference></BookingReferences>'
        . '<BookingItems><BookingItem><ItemClientId>1</ItemClientId><ItemPrice CurrencyCode="EUR">212.4</ItemPrice></BookingItem></BookingItems>'
        . '</AddBookingResponse></ResponseDetails></Response>';

    /** @var list<array<string, mixed>> */
    private array $updates = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->updates = [];
        DbStub::$getRow = static fn (string $query): array => str_contains($query, 'eurosite_bookings')
            ? ['booking_id' => 7, 'order_id' => 501, 'client_ref' => 'int1234', 'api_ref' => null, 'product_code' => 'H1', 'currency' => 'EUR',
                'check_in' => '2027-07-10', 'check_out' => '2027-07-17', 'num_rooms' => 1, 'adults' => 2, 'guests_json' => '[]', 'guest_email' => 'a@b.c', 'guest_phone' => '1', 'guest_name' => 'A B']
            : [];
        DbStub::$query = function (string $query, mixed ...$params): int {
            if (str_contains($query, 'UPDATE ?:eurosite_bookings') && is_array($params[0] ?? null)) {
                $this->updates[] = $params[0];
            }

            return 1;
        };
        $transport = new class (self::TAKEN) implements EurositeTransportInterface {
            public function __construct(private readonly string $answer)
            {
            }

            #[\Override]
            public function post(string $xml): string
            {
                return $this->answer;
            }
        };
        (new \ReflectionProperty(Container::class, 'api'))->setValue(null, new EurositeApiClient($transport, new EurositeXmlBuilder('u', 'p', 'RO'), new EurositeXmlParser(), 'EU'));
    }

    protected function tearDown(): void
    {
        Container::reset();
        DbStub::reset();
    }

    public function testATakenBookingIsPendingUntilEurositeConfirmsIt(): void
    {
        $result = (new BookingSubmissionService())->submitOrder(501, ['user_id' => 0, 'products' => [['extra' => ['eurosite_booking_id' => 7]]]]);

        self::assertSame(['submitted' => 1, 'failed' => 0], $result);
        $taken = array_values(array_filter($this->updates, static fn (array $u): bool => ($u['api_ref'] ?? '') === 'EU_XML_12898'));
        self::assertCount(1, $taken);
        self::assertSame('pending', $taken[0]['status']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $taken[0]['submitted_at']);
    }
}
