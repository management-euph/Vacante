<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services\Pdf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\Pdf\PdfFetcher;
use Tygh\Addons\FgoInvoicing\Services\Pdf\PdfFetchException;

/**
 * The ZIP download makes the SERVER fetch a URL read from the database. Only
 * FGO's own https hosts may be requested, or the admin panel becomes a proxy
 * into the store's network (SSRF). No network here: the transport is faked.
 */
#[CoversClass(PdfFetcher::class)]
final class PdfFetcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function allowed(): iterable
    {
        yield 'production api' => ['https://api.fgo.ro/v1/factura/pdf?id=1&h=abc'];
        yield 'sandbox api' => ['https://api-testuat.fgo.ro/v1/x.pdf'];
        yield 'apex' => ['https://fgo.ro/f.pdf'];
        yield 'deep subdomain' => ['https://files.eu.fgo.ro/f.pdf'];
        yield 'explicit 443' => ['https://api.fgo.ro:443/f.pdf'];
        yield 'upper case' => ['HTTPS://API.FGO.RO/f.pdf'];
    }

    #[DataProvider('allowed')]
    public function testFgoHttpsLinksAreAllowed(string $url): void
    {
        self::assertTrue(PdfFetcher::isAllowedUrl($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'plain http' => ['http://api.fgo.ro/f.pdf'];
        yield 'other scheme' => ['ftp://api.fgo.ro/f.pdf'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'scheme-relative' => ['//api.fgo.ro/f.pdf'];
        yield 'no scheme' => ['api.fgo.ro/f.pdf'];
        yield 'opaque https' => ['https:api.fgo.ro/f.pdf'];
        yield 'other host' => ['https://evil.com/f.pdf'];
        yield 'fgo.ro as a prefix' => ['https://fgo.ro.evil.com/f.pdf'];
        yield 'fgo.ro as a suffix without the dot' => ['https://evilfgo.ro/f.pdf'];
        yield 'fgo.ro as user' => ['https://fgo.ro@evil.com/f.pdf'];
        yield 'user on fgo.ro' => ['https://user@api.fgo.ro/f.pdf'];
        yield 'user and password' => ['https://u:p@api.fgo.ro/f.pdf'];
        yield 'ipv4 literal' => ['https://127.0.0.1/f.pdf'];
        yield 'private ipv4' => ['https://10.0.0.5/f.pdf'];
        yield 'ipv6 literal' => ['https://[::1]/f.pdf'];
        yield 'decimal ip' => ['https://2130706433/f.pdf'];
        yield 'other port' => ['https://api.fgo.ro:8443/f.pdf'];
        yield 'backslash authority trick' => ['https://evil.com\\@api.fgo.ro/f.pdf'];
        yield 'fragment trick' => ['https://evil.com#.fgo.ro'];
        yield 'query trick' => ['https://evil.com?.fgo.ro'];
        yield 'whitespace' => ['https://api.fgo.ro /f.pdf'];
        yield 'newline' => ["https://api.fgo.ro/f.pdf\nHost: evil"];
        yield 'percent-encoded host' => ['https://api%2Efgo.ro/f.pdf'];
        yield 'trailing dot' => ['https://api.fgo.ro./f.pdf'];
        yield 'underscore label' => ['https://a_b.fgo.ro/f.pdf'];
        yield 'empty' => [''];
        yield 'too long' => ['https://api.fgo.ro/' . str_repeat('a', 2100)];
    }

    #[DataProvider('refused')]
    public function testEverythingElseIsRefused(string $url): void
    {
        self::assertFalse(PdfFetcher::isAllowedUrl($url));
    }

    /**
     * @param list<array{status: int, body: string, location: string, error: string}> $responses
     * @param list<string> $requested
     */
    private static function fetcher(array $responses, array &$requested): PdfFetcher
    {
        return new PdfFetcher(static function (string $url) use (&$responses, &$requested): array {
            $requested[] = $url;

            return array_shift($responses) ?? ['status' => 500, 'body' => '', 'location' => '', 'error' => ''];
        });
    }

    /**
     * @return array{status: int, body: string, location: string, error: string}
     */
    private static function response(int $status, string $body = '', string $location = '', string $error = ''): array
    {
        return ['status' => $status, 'body' => $body, 'location' => $location, 'error' => $error];
    }

    public function testAPdfIsReturned(): void
    {
        $requested = [];
        $pdf = self::fetcher([self::response(200, "%PDF-1.7\n...")], $requested)->fetch('https://api.fgo.ro/f.pdf');

        self::assertSame("%PDF-1.7\n...", $pdf);
        self::assertSame(['https://api.fgo.ro/f.pdf'], $requested);
    }

    public function testADisallowedUrlIsNeverRequested(): void
    {
        $requested = [];
        try {
            self::fetcher([self::response(200, '%PDF')], $requested)->fetch('http://169.254.169.254/latest/meta-data');
            self::fail('expected a refusal');
        } catch (PdfFetchException $e) {
            self::assertStringContainsString('not an https link on fgo.ro', $e->getMessage());
        }
        self::assertSame([], $requested);
    }

    public function testRedirectsWithinFgoAreFollowed(): void
    {
        $requested = [];
        $pdf = self::fetcher([
            self::response(302, '', 'https://files.fgo.ro/x.pdf'),
            self::response(301, '', '/y.pdf'),
            self::response(200, '%PDF-1.4'),
        ], $requested)->fetch('https://api.fgo.ro/f.pdf');

        self::assertSame('%PDF-1.4', $pdf);
        self::assertSame(['https://api.fgo.ro/f.pdf', 'https://files.fgo.ro/x.pdf', 'https://files.fgo.ro/y.pdf'], $requested);
    }

    public function testASchemeRelativeRedirectStaysOnHttps(): void
    {
        $requested = [];
        self::fetcher([self::response(302, '', '//files.fgo.ro/z.pdf'), self::response(200, '%PDF')], $requested)
            ->fetch('https://api.fgo.ro/f.pdf');

        self::assertSame('https://files.fgo.ro/z.pdf', $requested[1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badRedirects(): iterable
    {
        yield 'another host' => ['https://evil.com/f.pdf'];
        yield 'plain http' => ['http://api.fgo.ro/f.pdf'];
        yield 'metadata ip' => ['https://169.254.169.254/'];
        yield 'relative path' => ['x.pdf'];
        yield 'no location' => [''];
    }

    #[DataProvider('badRedirects')]
    public function testARedirectOutsideFgoIsRefusedBeforeItIsRequested(string $location): void
    {
        $requested = [];
        try {
            self::fetcher([self::response(302, '', $location), self::response(200, '%PDF')], $requested)->fetch('https://api.fgo.ro/f.pdf');
            self::fail('expected a refusal');
        } catch (PdfFetchException $e) {
            self::assertStringContainsString('redirected the PDF outside fgo.ro', $e->getMessage());
        }
        self::assertSame(['https://api.fgo.ro/f.pdf'], $requested);
    }

    public function testRedirectLoopsEnd(): void
    {
        $requested = [];
        $loop = array_fill(0, 10, self::response(302, '', 'https://api.fgo.ro/again.pdf'));

        $this->expectException(PdfFetchException::class);
        $this->expectExceptionMessage('Too many redirects');
        self::fetcher($loop, $requested)->fetch('https://api.fgo.ro/f.pdf');
    }

    public function testAnHtmlPageIsNotAPdf(): void
    {
        $requested = [];
        $this->expectException(PdfFetchException::class);
        $this->expectExceptionMessage('%PDF');
        self::fetcher([self::response(200, '<html>Login</html>')], $requested)->fetch('https://api.fgo.ro/f.pdf');
    }

    public function testAnHttpErrorFails(): void
    {
        $requested = [];
        $this->expectException(PdfFetchException::class);
        $this->expectExceptionMessage('HTTP 404');
        self::fetcher([self::response(404, '%PDF')], $requested)->fetch('https://api.fgo.ro/f.pdf');
    }

    public function testATransportErrorFailsWithoutLeakingTheSignedLink(): void
    {
        $requested = [];
        try {
            self::fetcher([self::response(0, '', '', 'Operation timed out after 20000 ms')], $requested)
                ->fetch('https://api.fgo.ro/f.pdf?signature=SECRET');
            self::fail('expected a failure');
        } catch (PdfFetchException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
            self::assertStringContainsString('api.fgo.ro', $e->getMessage());
            self::assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }

    public function testAnOversizedBodyFails(): void
    {
        $requested = [];
        $this->expectException(PdfFetchException::class);
        $this->expectExceptionMessage('15 MB');
        self::fetcher([self::response(200, '%PDF' . str_repeat('x', PdfFetcher::MAX_BYTES))], $requested)->fetch('https://api.fgo.ro/f.pdf');
    }

    public function testTheLimitsAreTheDocumentedOnes(): void
    {
        self::assertSame(15 * 1024 * 1024, PdfFetcher::MAX_BYTES);
        self::assertSame(20, PdfFetcher::TIMEOUT_SECONDS);
    }
}
