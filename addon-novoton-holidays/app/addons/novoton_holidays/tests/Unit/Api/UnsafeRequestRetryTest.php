<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;

/**
 * A reservation was sent again after a timeout, an empty reply or a 5xx: up
 * to three reservations at the hotel when Novoton had made the first. A
 * request that creates something is now sent again only when it never left.
 */
final class UnsafeRequestRetryTest extends TestCase
{
    public function testAReservationIsSentAgainOnlyWhenItNeverLeft(): void
    {
        foreach ([5, 6, 7, 35] as $notSent) {
            self::assertSame([true, false], NovotonHttpClient::afterFailure(Constants::API_FUNCTION_RESERVATION, $notSent, 'Could not connect', 0), "errno {$notSent}");
        }

        // It may have reached Novoton: not sent again.
        self::assertSame([false, true], NovotonHttpClient::afterFailure(Constants::API_FUNCTION_RESERVATION, 28, 'Operation timed out after 120000 milliseconds', 0));
        self::assertSame([false, true], NovotonHttpClient::afterFailure(Constants::API_FUNCTION_RESERVATION, 52, 'Empty reply from server', 0));
        self::assertSame([false, true], NovotonHttpClient::afterFailure(Constants::API_FUNCTION_RESERVATION, 0, '', 502));
    }

    public function testRequestsForAlternativesAndCancellationsAreTreatedTheSame(): void
    {
        self::assertSame([false, true], NovotonHttpClient::afterFailure(Constants::API_FUNCTION_HOTEL_REQUEST, 28, 'Operation timed out', 0));
        self::assertSame([false, true], NovotonHttpClient::afterFailure(Constants::API_FUNCTION_CANCEL, 0, '', 503));
    }

    public function testReadingRequestsKeepTheSharedRetryRule(): void
    {
        self::assertSame([true, true], NovotonHttpClient::afterFailure(Constants::API_FUNCTION_ROOM_PRICE, 28, 'Operation timed out', 0));
        self::assertSame([true, true], NovotonHttpClient::afterFailure(Constants::API_FUNCTION_RES_INFO, 0, '', 503));
        self::assertSame([false, true], NovotonHttpClient::afterFailure(Constants::API_FUNCTION_ROOM_PRICE, 0, '', 404));
    }

    public function testTheFailureSaysNovotonMayHaveIt(): void
    {
        $maybe = ApiException::maybeProcessed(Constants::API_FUNCTION_RESERVATION, 'Operation timed out', 0, 1);
        self::assertTrue($maybe->mayHaveReachedNovoton());
        self::assertStringContainsString('Novoton may have received it', $maybe->getMessage());

        self::assertFalse(ApiException::requestFailed(Constants::API_FUNCTION_RESERVATION, 'Could not connect', 0, 3)->mayHaveReachedNovoton());
        self::assertFalse(ApiException::refused(Constants::API_FUNCTION_RESERVATION, 'Invalid username or password!')->mayHaveReachedNovoton());
    }
}
