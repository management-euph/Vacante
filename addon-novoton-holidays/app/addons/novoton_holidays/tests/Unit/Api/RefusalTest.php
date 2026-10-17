<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\ApiClientBase;
use Tygh\Addons\NovotonHolidays\Api\DestinationApiClient;
use Tygh\Addons\NovotonHolidays\Api\PricingApiClient;
use Tygh\Addons\NovotonHolidays\Api\Refusal;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

/**
 * With a wrong API password Novoton answers priceinfo, room_price and
 * offers_update with <error><err>Invalid username or password!</err></error>
 * (seen on the live API, October 2026). That parsed like an empty answer: the
 * price sync stored it as every package's prices and offers_update read "no
 * changes" and moved its baseline past them.
 */
#[CoversClass(Refusal::class)]
#[CoversClass(ApiClientBase::class)]
#[CoversClass(PricingApiClient::class)]
#[CoversClass(DestinationApiClient::class)]
final class RefusalTest extends TestCase
{
    private const string WRONG_PASSWORD = '<error><err>Invalid username or password!</err></error>';

    public function testARefusalIsAReplyWhoseRootIsError(): void
    {
        self::assertSame('Invalid username or password!', Refusal::reason(new \SimpleXMLElement(self::WRONG_PASSWORD)));
        self::assertSame('Access denied', Refusal::reason(new \SimpleXMLElement('<Error>Access denied</Error>')), 'any case, text without <err>');
        self::assertSame('no reason given', Refusal::reason(new \SimpleXMLElement('<error/>')));
    }

    public function testAnswersAreNotRefusals(): void
    {
        self::assertNull(Refusal::reason(new \SimpleXMLElement('<priceinfo><seasons><Season>1</Season></seasons></priceinfo>')));
        self::assertNull(Refusal::reason(new \SimpleXMLElement('<room_price></room_price>')), 'an empty answer is still an answer');
        self::assertNull(Refusal::reason(new \SimpleXMLElement('<room_price><error>No availability</error></room_price>')), 'an <error> inside an answer belongs to that answer');
    }

    public function testARefusedPriceinfoIsAFailedRequestNotAPriceList(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Novoton refused priceinfo: Invalid username or password!');

        $this->pricing(self::WRONG_PASSWORD)->getPriceInfo('476', 'ADMIRAL ***** +BEACH');
    }

    public function testARefusedOffersUpdateIsAFailedRequestNotNoChanges(): void
    {
        try {
            $this->destinations(self::WRONG_PASSWORD)->getOffersUpdate('2026-10-10T00:00:00', 'BULGARIA');
            self::fail('a refusal must not read as "no changes"');
        } catch (ApiException $e) {
            self::assertSame(Constants::API_FUNCTION_OFFERS_UPDATE, $e->getApiFunction());
            self::assertStringContainsString('Invalid username or password!', $e->getMessage());
        }
    }

    public function testAnswersComeBackAsBefore(): void
    {
        $priceinfo = $this->pricing('<priceinfo><seasons><Season>1</Season></seasons></priceinfo>')->getPriceInfo('476', 'ADMIRAL ***** +BEACH');
        self::assertSame('1', (string) $priceinfo->seasons->Season);

        $offers = $this->destinations('<offers_update><Offer><IdHotel>476</IdHotel></Offer></offers_update>')->getOffersUpdate('2026-10-10T00:00:00', 'BULGARIA');
        self::assertSame('476', (string) $offers->Offer->IdHotel);

        $none = $this->destinations('<offers_update></offers_update>')->getOffersUpdate('2026-10-10T00:00:00', 'BULGARIA');
        self::assertFalse(isset($none->Offer), 'an answer without offers is still "no changes"');
    }

    private function pricing(string $reply): PricingApiClient
    {
        return new PricingApiClient($this->http($reply), new NovotonXmlParser(), null, false, new CommissionCalculator(0.0));
    }

    private function destinations(string $reply): DestinationApiClient
    {
        return new DestinationApiClient($this->http($reply), new NovotonXmlParser(), null, false);
    }

    private function http(string $reply): NovotonHttpClient
    {
        $http = $this->createStub(NovotonHttpClient::class);
        $http->method('sendRequest')->willReturn($reply);

        return $http;
    }
}
