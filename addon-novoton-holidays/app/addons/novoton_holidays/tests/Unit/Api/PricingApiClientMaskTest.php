<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\PricingApiClient;
use Tygh\Addons\NovotonHolidays\Api\RequestGate;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;
use Tygh\Registry;

/**
 * The request a price call keeps for debugging (lastRequest) went, with the
 * API login in it, into the storefront's debug answer and the store's log.
 * What is kept is now masked; only what is sent carries the login.
 */
final class PricingApiClientMaskTest extends TestCase
{
    protected function tearDown(): void
    {
        Registry::set('addons.novoton_holidays', []);
        ConfigProvider::resetSettingsCache();
    }

    public function testTheKeptRoomPriceRequestHasNoLogin(): void
    {
        $http = new NovotonHttpClient(
            ['api_url' => 'http://127.0.0.1:9', 'api_key' => 'k', 'api_user' => 'agent-7', 'api_password' => 's3cr&t'],
            null,
            new RequestGate('/nonexistent-dir/novoton_gate.json'),
        );
        $client = new class ($http, new NovotonXmlParser(), null, false, new CommissionCalculator(0.0)) extends PricingApiClient {
            public string $sent = '';

            #[\Override]
            protected function callApi(string $function, string $xml, string $lang = 'UK'): string
            {
                $this->sent = $xml;

                return '<room_price_RS></room_price_RS>';
            }
        };

        $client->getRoomPrice(['hotel_id' => '476', 'check_in' => '2027-07-10', 'check_out' => '2027-07-17', 'adults' => 2, 'nocache' => true]);

        self::assertStringContainsString('<usr>agent-7</usr>', $client->sent, 'what goes to Novoton carries the login');
        self::assertStringContainsString('<usr>*****</usr><psw>*****</psw>', $client->lastRequest);
        self::assertStringNotContainsString('agent-7', $client->lastRequest);
        self::assertStringNotContainsString('s3cr', $client->lastRequest);
    }
}
