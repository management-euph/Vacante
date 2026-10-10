<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\EurositeHttpClient;
use Tygh\Addons\TravelCore\Http\ResiliencePolicy;

/**
 * Transport security (audit H13). Every request carries RequestUser /
 * RequestPass in the XML body, so: https:// is always certificate-verified
 * whatever allow_insecure_api says, that flag only unlocks a plain http://
 * URL, a scheme-less URL is https://, and a bad certificate surfaces as a
 * TLS failure naming the host. The curl seam captures the options the client
 * would hand to curl; nothing touches the network.
 */
final class EurositeHttpClientTest extends TestCase
{
    /** @var list<array{url: string, options: array<int, mixed>}> */
    private array $calls = [];

    /**
     * @param array<string, mixed> $settings
     * @param array{string|false, int, string, int} $result
     */
    private function client(array $settings, array $result = ['<ok/>', 200, '', 0]): EurositeHttpClient
    {
        return new EurositeHttpClient(
            $settings + ['api_max_retries' => 3],
            new ResiliencePolicy(5, 60, 0, 1.0, true),
            function (string $url, array $options) use ($result): array {
                $this->calls[] = ['url' => $url, 'options' => $options];

                return $result;
            },
        );
    }

    public function testHttpsVerifiesTheCertificateEvenWithTheInsecureFlagOn(): void
    {
        $client = $this->client(['api_url' => 'https://api.example.test/server.php', 'allow_insecure_api' => true]);

        self::assertSame('<ok/>', $client->post('<Request/>'));
        self::assertCount(1, $this->calls);
        self::assertSame('https://api.example.test/server.php', $this->calls[0]['url']);
        self::assertTrue($this->calls[0]['options'][CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $this->calls[0]['options'][CURLOPT_SSL_VERIFYHOST]);
    }

    public function testHttpsVerifiesTheCertificateWithTheFlagOff(): void
    {
        $this->client(['api_url' => 'https://api.example.test/server.php', 'allow_insecure_api' => 'N'])->post('<Request/>');

        self::assertTrue($this->calls[0]['options'][CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $this->calls[0]['options'][CURLOPT_SSL_VERIFYHOST]);
    }

    public function testASchemeLessUrlBecomesHttps(): void
    {
        // The flag is on: even then a bare host must not be guessed as http://.
        $this->client(['api_url' => 'api.example.test/server.php', 'allow_insecure_api' => true])->post('<Request/>');

        self::assertSame('https://api.example.test/server.php', $this->calls[0]['url']);
        self::assertTrue($this->calls[0]['options'][CURLOPT_SSL_VERIFYPEER]);
    }

    public function testASchemeLessUrlIsAcceptedWithTheFlagOff(): void
    {
        $this->client(['api_url' => 'api.example.test/server.php'])->post('<Request/>');

        self::assertSame('https://api.example.test/server.php', $this->calls[0]['url']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function flagOffValues(): array
    {
        return [
            'missing' => [null],
            'false'   => [false],
            'N'       => ['N'],
            ''        => [''],
        ];
    }

    #[DataProvider('flagOffValues')]
    public function testHttpIsRefusedWhenTheFlagIsOff(mixed $flag): void
    {
        $settings = ['api_url' => 'http://api.example.test/server.php'];
        if ($flag !== null) {
            $settings['allow_insecure_api'] = $flag;
        }

        try {
            $this->client($settings);
            self::fail('a plain http:// URL must be refused while allow_insecure_api is off');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('api.example.test', $e->getMessage());
            self::assertStringContainsString('http://', $e->getMessage());
        }
        self::assertSame([], $this->calls, 'nothing may be sent');
    }

    public function testUppercaseHttpSchemeIsRefusedToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(['api_url' => 'HTTP://api.example.test/server.php', 'allow_insecure_api' => false]);
    }

    public function testHttpIsAllowedWhenTheFlagIsOn(): void
    {
        $client = $this->client(['api_url' => 'http://api.example.test/server.php', 'allow_insecure_api' => 'Y']);

        self::assertSame('<ok/>', $client->post('<Request/>'));
        self::assertSame('http://api.example.test/server.php', $this->calls[0]['url']);
    }

    public function testOtherSchemesAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(['api_url' => 'file:///etc/passwd', 'allow_insecure_api' => true]);
    }

    public function testACertificateFailureNamesTheHostAndIsNotRetried(): void
    {
        $client = $this->client(
            ['api_url' => 'https://api.example.test/server.php'],
            [false, 0, 'SSL certificate problem: self-signed certificate', 60],
        );
        $GLOBALS['eurosite_logged_events'] = [];

        try {
            $client->post('<Request/>');
            self::fail('a certificate failure must throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('TLS certificate verification failed for host api.example.test', $e->getMessage());
            self::assertStringContainsString('self-signed certificate', $e->getMessage());
        }
        self::assertStringContainsString('TLS certificate verification failed for host api.example.test', $client->lastError);
        self::assertCount(1, $this->calls, 'a bad certificate is not retried');

        $tlsEvents = array_values(array_filter(
            $GLOBALS['eurosite_logged_events'],
            static fn (array $event): bool => str_contains((string) json_encode($event[2]), 'TLS certificate verification failed for host api.example.test'),
        ));
        self::assertCount(1, $tlsEvents, 'the certificate failure is logged once, naming the host');
        self::assertSame(['general', 'runtime'], [$tlsEvents[0][0], $tlsEvents[0][1]]);
    }

    public function testAHostNameMismatchIsReportedAsATlsFailure(): void
    {
        $client = $this->client(
            ['api_url' => 'https://api.example.test/server.php'],
            [false, 0, "SSL: no alternative certificate subject name matches target host name 'api.example.test'", 60],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TLS certificate verification failed for host api.example.test');
        $client->post('<Request/>');
    }

    public function testOrdinaryTransportErrorsAreNotLabelledAsTls(): void
    {
        $client = $this->client(
            ['api_url' => 'https://api.example.test/server.php', 'api_max_retries' => 1],
            [false, 0, 'Could not resolve host: api.example.test', 6],
        );

        try {
            $client->post('<Request/>');
            self::fail('a transport error must throw');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('TLS', $e->getMessage());
            self::assertStringContainsString('Could not resolve host', $e->getMessage());
        }
    }
}
