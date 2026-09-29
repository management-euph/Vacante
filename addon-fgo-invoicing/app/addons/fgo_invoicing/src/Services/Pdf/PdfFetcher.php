<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Pdf;

/**
 * Downloads an invoice PDF from FGO, server-side, for the ZIP download.
 *
 * The URL comes from the database (FGO's `Link`, stored when the invoice was
 * issued), and the server fetches it, so it is treated as untrusted: a row
 * that ever held another URL must not turn the admin panel into a proxy into
 * the store's own network (SSRF). Hence:
 *
 *   - https only, port 443, no user:password@, no IP literal, and the host is
 *     fgo.ro or one of its subdomains, compared on the parsed host (so
 *     fgo.ro.evil.com, evilfgo.ro and fgo.ro@evil.com are all refused);
 *   - redirects are NOT left to cURL: each Location is resolved and passes
 *     the same check before it is requested, at most MAX_REDIRECTS times;
 *   - 20 s per request, 15 MB per file, and the body must start with %PDF:
 *     an HTML error page saved as invoice.pdf helps nobody.
 *
 * The transport is injectable so every rule above is unit-tested without a
 * network; production uses cURL (curlTransport()).
 *
 * @phpstan-type TransportResult array{status: int, body: string, location: string, error: string}
 */
final class PdfFetcher
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    public const TIMEOUT_SECONDS = 20;
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const MAX_REDIRECTS = 3;
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /** @var \Closure(string): TransportResult */
    private readonly \Closure $transport;

    /**
     * @param (\Closure(string): TransportResult)|null $transport
     */
    public function __construct(?\Closure $transport = null)
    {
        $this->transport = $transport ?? self::curlTransport();
    }

    /**
     * Whether the server may request $url at all (see the class docblock).
     */
    public static function isAllowedUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || stripos($url, 'https://') !== 0) {
            return false;
        }
        // Whitespace, control characters and backslashes are where
        // parse_url(), cURL and browsers disagree about the authority.
        if (preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        // Plain DNS labels only: no brackets (IPv6), no %-escapes, no '_'.
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1) {
            return false;
        }

        return $host === 'fgo.ro' || str_ends_with($host, '.fgo.ro');
    }

    /**
     * The PDF's bytes.
     *
     * @throws PdfFetchException
     */
    public function fetch(string $url): string
    {
        $current = trim($url);
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!self::isAllowedUrl($current)) {
                throw new PdfFetchException(
                    $hop === 0
                        ? 'The PDF link is not an https link on fgo.ro (' . self::describe($current) . ')'
                        : 'FGO redirected the PDF outside fgo.ro (' . self::describe($current) . ')',
                );
            }

            $response = ($this->transport)($current);
            if ($response['error'] !== '') {
                throw new PdfFetchException('Download from ' . self::describe($current) . ' failed: ' . $response['error']);
            }
            if (in_array($response['status'], self::REDIRECT_STATUSES, true)) {
                $current = self::resolveLocation($current, $response['location']);
                continue;
            }
            if ($response['status'] !== 200) {
                throw new PdfFetchException('FGO answered HTTP ' . $response['status'] . ' for the PDF');
            }
            if (strlen($response['body']) > self::MAX_BYTES) {
                throw new PdfFetchException('The PDF is larger than ' . intdiv(self::MAX_BYTES, 1024 * 1024) . ' MB');
            }
            if (!str_starts_with($response['body'], '%PDF')) {
                throw new PdfFetchException('FGO did not return a PDF (the body does not start with %PDF)');
            }

            return $response['body'];
        }

        throw new PdfFetchException('Too many redirects for the PDF');
    }

    /**
     * Production transport: one GET, redirects not followed, size-capped
     * while it streams (a hostile server cannot fill the memory first).
     *
     * @return \Closure(string): TransportResult
     */
    public static function curlTransport(): \Closure
    {
        return static function (string $url): array {
            if (!function_exists('curl_init')) {
                return ['status' => 0, 'body' => '', 'location' => '', 'error' => 'cURL is not available on this server'];
            }
            $handle = curl_init($url);
            if ($handle === false) {
                return ['status' => 0, 'body' => '', 'location' => '', 'error' => 'cURL could not start'];
            }

            $body = '';
            $location = '';
            $tooLarge = false;
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_MAXFILESIZE => self::MAX_BYTES,
                CURLOPT_HTTPHEADER => ['Accept: application/pdf'],
                CURLOPT_HEADERFUNCTION => static function (\CurlHandle $ch, string $line) use (&$location): int {
                    if (stripos($line, 'location:') === 0) {
                        $location = trim(substr($line, 9));
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $ch, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        $tooLarge = true;

                        return 0;
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);

            $ok = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = $ok === false ? curl_error($handle) : '';
            unset($handle);

            if ($tooLarge) {
                return ['status' => 0, 'body' => '', 'location' => '', 'error' => 'the PDF is larger than ' . intdiv(self::MAX_BYTES, 1024 * 1024) . ' MB'];
            }

            return [
                'status' => $status,
                'body' => $body,
                'location' => $location,
                'error' => $error,
            ];
        };
    }

    /**
     * Absolute and root-relative Locations are followed; anything else is
     * returned as '' and refused by isAllowedUrl() on the next hop.
     */
    private static function resolveLocation(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return '';
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location) === 1) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        if (str_starts_with($location, '/')) {
            $host = parse_url($base, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? 'https://' . $host . $location : '';
        }

        return '';
    }

    /** The host only: FGO's PDF links are signed, their query is a credential. */
    private static function describe(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'invalid link';
    }
}
