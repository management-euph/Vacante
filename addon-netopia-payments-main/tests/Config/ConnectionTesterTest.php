<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Config;

use Netopia\CsCart\Config\ConnectionTester;
use Netopia\CsCart\Dto\ApiResponse;
use Netopia\CsCart\Http\ApiPoster;
use Netopia\CsCart\Tests\Support\RsaTestFixtures;
use Netopia\Payment2\Enum\PaymentMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionTester::class)]
final class ConnectionTesterTest extends TestCase
{
    /** @var list<array{key: string, mode: PaymentMode, endpoint: string, body: string}> */
    private array $calls = [];

    public function testAllGoodIsReady(): void
    {
        [$public] = RsaTestFixtures::generateKeyPair();

        $result = $this->tester(ApiResponse::fromHttp(404, ['message' => 'not found']))
            ->run('ApiKey_1', 'AB12-CD34-EF56-GH78-IJ90', PaymentMode::Sandbox, $public, time());

        self::assertTrue($result['ready']);
        self::assertSame(['ok', 'ok', 'ok'], array_column($result['checks'], 'state'));
        self::assertSame('netopia_test_api_key_ok', $result['checks'][0]['lang_key']);

        // One read-only status call, with the typed key, for the chosen mode.
        self::assertCount(1, $this->calls);
        self::assertSame(ConnectionTester::STATUS_ENDPOINT, $this->calls[0]['endpoint']);
        self::assertSame('ApiKey_1', $this->calls[0]['key']);
        self::assertSame(PaymentMode::Sandbox, $this->calls[0]['mode']);
        self::assertStringContainsString('AB12-CD34-EF56-GH78-IJ90', $this->calls[0]['body']);
    }

    public function testRefusedKeyIsReported(): void
    {
        $result = $this->tester(ApiResponse::fromHttp(401, null))
            ->run('bad', 'AB12-CD34-EF56-GH78-IJ90', PaymentMode::Live, '', time());

        self::assertFalse($result['ready']);
        self::assertSame('netopia_test_api_key_rejected', $result['checks'][0]['lang_key']);
        self::assertSame(['[mode]' => 'live'], $result['checks'][0]['params']);
        self::assertSame('netopia_test_public_key_missing', $result['checks'][2]['lang_key']);
    }

    public function testUnreachableAndServerError(): void
    {
        $down = $this->tester(ApiResponse::failure('Connection error occurred.'))
            ->run('k', 'AB12-CD34-EF56-GH78-IJ90', PaymentMode::Sandbox, '', time());
        self::assertSame('netopia_test_unreachable', $down['checks'][0]['lang_key']);

        $err = $this->tester(ApiResponse::fromHttp(502, null))
            ->run('k', 'AB12-CD34-EF56-GH78-IJ90', PaymentMode::Sandbox, '', time());
        self::assertSame('warn', $err['checks'][0]['state']);
        self::assertSame('502', $err['checks'][0]['params']['[code]']);
    }

    public function testEmptyKeySkipsTheCallAndPosFormatIsOnlyAWarning(): void
    {
        [$public] = RsaTestFixtures::generateKeyPair();
        $result = $this->tester(ApiResponse::fromHttp(200, []))
            ->run('', 'not-a-signature', PaymentMode::Sandbox, $public, time());

        self::assertSame([], $this->calls);
        self::assertSame('netopia_test_api_key_missing', $result['checks'][0]['lang_key']);
        self::assertSame('warn', $result['checks'][1]['state']);
        self::assertSame('netopia_test_pos_format', $result['checks'][1]['lang_key']);
        self::assertFalse($result['ready']);
    }

    private function tester(ApiResponse $response): ConnectionTester
    {
        $this->calls = [];
        $calls = &$this->calls;

        return new ConnectionTester(
            apiClientFactory: static function (string $key, PaymentMode $mode) use ($response, &$calls): ApiPoster {
                return new class ($response, $key, $mode, $calls) implements ApiPoster {
                    /** @param list<array<string, mixed>> $calls */
                    public function __construct(
                        private readonly ApiResponse $response,
                        private readonly string $key,
                        private readonly PaymentMode $mode,
                        private array &$calls,
                    ) {
                    }

                    public function post(string $endpoint, string $jsonBody): ApiResponse
                    {
                        $this->calls[] = ['key' => $this->key, 'mode' => $this->mode, 'endpoint' => $endpoint, 'body' => $jsonBody];

                        return $this->response;
                    }
                };
            },
        );
    }
}
