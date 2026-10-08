<?php

declare(strict_types=1);

/**
 * Standalone Sphinx API client — shared helper for the dev/sphinx probes.
 *
 * NOT part of any addon. No CS-Cart bootstrap, no autoload, no DB. It speaks
 * the Sphinx REST/JSON protocol directly (Bearer auth) so each probe can show
 * the request + decoded response for one endpoint.
 *
 * Protocol (mirrors addon-sphinx-holidays SphinxHttpClient):
 *   {method} {base_url}{/api/v1/...}
 *     Authorization: Bearer <token>
 *     Accept: application/json
 *     Content-Type: application/json   (POST body is JSON)
 *
 * Credentials default to the Sphinx DEV/staging values already committed in
 * the sphinx_holidays addon.xml and dev/sphinx_api_dev/ (non-production). Override:
 *   SPHINX_API_URL       base URL (default the committed dev host)
 *   SPHINX_API_TOKEN     bearer token (default the committed dev token)
 *   SPHINX_API_INSECURE  1 to skip TLS verify (dev only)
 *
 * Every probe `require`s this file. Run a probe with --help for its args.
 */

const SPX_DEFAULT_API_URL   = 'https://api.sphinx2.christiantour.dev.ploi.imementohub.com';
const SPX_DEFAULT_API_TOKEN = ''; // set SPHINX_API_TOKEN; never commit a key

function spx_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

/**
 * @return array{url:string, token:string, insecure:bool}
 */
function spx_config(): array
{
    return [
        'url'      => rtrim((string) (getenv('SPHINX_API_URL') ?: SPX_DEFAULT_API_URL), '/'),
        'token'    => (string) (getenv('SPHINX_API_TOKEN') ?: SPX_DEFAULT_API_TOKEN),
        'insecure' => in_array((string) getenv('SPHINX_API_INSECURE'), ['1', 'true', 'yes'], true),
    ];
}

/** Read a param from CLI (`--key=value`) or the query string, with positionals. */
function spx_param(string $key, ?string $default = null): ?string
{
    static $cli = null;
    if ($cli === null) {
        $cli = [];
        if (spx_is_cli()) {
            $pos = 0;
            foreach (array_slice($GLOBALS['argv'] ?? [], 1) as $arg) {
                if (preg_match('/^--([a-zA-Z0-9_]+)=(.*)$/s', $arg, $m)) {
                    $cli[$m[1]] = $m[2];
                } elseif (preg_match('/^--([a-zA-Z0-9_]+)$/', $arg, $m)) {
                    $cli[$m[1]] = '1';
                } else {
                    $cli[(string) $pos++] = $arg;
                }
            }
        }
    }
    if (array_key_exists($key, $cli)) {
        return $cli[$key];
    }
    if (isset($_GET[$key])) {
        return is_string($_GET[$key]) ? $_GET[$key] : null;
    }
    return $default;
}

function spx_wants_help(): bool
{
    return spx_param('help') !== null;
}

function spx_out_setup(): void
{
    if (!spx_is_cli() && !headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
}

function spx_section(string $title): void
{
    echo "\n" . str_repeat('=', 72) . "\n" . $title . "\n" . str_repeat('=', 72) . "\n";
}

/**
 * Perform one HTTP request. Returns the raw body + transport metadata.
 *
 * RESPONSE headers are captured too. They are what makes a "these are the
 * bytes the API returned" claim provable rather than merely asserted:
 * Content-Length against strlen($body), and the absence of Content-Encoding,
 * confirm nothing was transparently decoded. They also carry X-Request-ID,
 * which the API docs tell you to quote when reporting a problem. Only
 * RESPONSE headers are captured — the request's Bearer token is never
 * echoed by any probe.
 *
 * NOTE: no CURLOPT_ENCODING is set anywhere here, so curl sends no
 * Accept-Encoding and the server replies identity. dev/sphinx/hotel_availability.php
 * depends on that for its --raw_response guarantee; adding it would silently
 * turn those "verbatim" bytes into decoded ones.
 *
 * @param array<string, mixed>|null $body JSON body for POST (null for GET)
 * @return array{code:int, body:string, error:string, ms:float, headers:string}
 */
function spx_request(array $cfg, string $method, string $url, ?array $body = null): array
{
    $headers = [
        'Authorization: Bearer ' . $cfg['token'],
        'Accept: application/json',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => !$cfg['insecure'],
        CURLOPT_SSL_VERIFYHOST => $cfg['insecure'] ? 0 : 2,
    ];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;

    $responseHeaders = '';
    $opts[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$responseHeaders): int {
        $responseHeaders .= $line;

        return strlen($line);
    };

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $start = microtime(true);
    $raw   = curl_exec($ch);
    $ms    = (microtime(true) - $start) * 1000;
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    return [
        'code'    => $code,
        'body'    => is_string($raw) ? $raw : '',
        'error'   => $err,
        'ms'      => $ms,
        'headers' => trim($responseHeaders),
    ];
}

/** GET with a query array. */
function spx_get(array $cfg, string $endpoint, array $query = []): array
{
    $url = $cfg['url'] . $endpoint;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    return spx_request($cfg, 'GET', $url, null);
}

/** POST with a JSON body. */
function spx_post(array $cfg, string $endpoint, array $body = []): array
{
    return spx_request($cfg, 'POST', $cfg['url'] . $endpoint, $body);
}

/**
 * Pretty-print a JSON body. When $limit > 0, the longest top-level list found
 * (data/results/hotels/…) is trimmed to the first $limit entries — the "limit"
 * knob for list endpoints. Returns the formatted string.
 */
function spx_format_json(string $body, int $limit = 0): string
{
    if ($body === '') {
        return '(empty body)';
    }
    $decoded = json_decode($body, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return "(not JSON — raw body follows)\n" . $body;
    }

    $note = '';
    if ($limit > 0 && is_array($decoded)) {
        $note = spx_trim_list($decoded, $limit);
    }

    $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return ($note !== '' ? $note . "\n" : '') . ($pretty === false ? $body : $pretty);
}

/**
 * Find the largest list in the decoded payload and trim it in place to $limit
 * entries. Checks the common envelope keys first, else the biggest list value.
 * Returns a short note describing what was trimmed (or '').
 *
 * @param array<mixed> $decoded
 */
function spx_trim_list(array &$decoded, int $limit): string
{
    foreach (['data', 'results', 'hotels', 'items', 'destinations', 'circuits', 'experiences', 'orders'] as $key) {
        if (isset($decoded[$key]) && is_array($decoded[$key]) && array_is_list($decoded[$key])) {
            return spx_trim_at($decoded[$key], $limit, $key);
        }
    }
    // Top-level list payload.
    if (array_is_list($decoded)) {
        return spx_trim_at($decoded, $limit, '(root)');
    }
    // Otherwise, pick the biggest list-valued property.
    $bestKey = null;
    $bestLen = 0;
    foreach ($decoded as $k => $v) {
        if (is_array($v) && array_is_list($v) && count($v) > $bestLen) {
            $bestKey = $k;
            $bestLen = count($v);
        }
    }
    if ($bestKey !== null) {
        return spx_trim_at($decoded[$bestKey], $limit, (string) $bestKey);
    }
    return '';
}

/**
 * @param array<mixed> $list
 */
function spx_trim_at(array &$list, int $limit, string $label): string
{
    $total = count($list);
    if ($total <= $limit) {
        return '';
    }
    $list = array_slice($list, 0, $limit);
    $hidden = $total - $limit;
    return "// limit={$limit}: showing first {$limit} of {$total} in \"{$label}\" ({$hidden} hidden)";
}

/**
 * Run a request and print request + response. Most probes end with this.
 *
 * @param array<string, mixed>|null $body POST body (null = GET)
 * @param array<string, mixed> $query GET query (informational for the header)
 */
function spx_run(array $cfg, string $method, string $endpoint, ?array $body, array $query, int $limit = 0): void
{
    spx_out_setup();

    spx_section("Sphinx API · {$method} {$endpoint}");
    $shownUrl = $cfg['url'] . $endpoint . ($query !== [] ? '?' . http_build_query($query) : '');
    echo "URL   : {$shownUrl}\n";
    echo "Limit : " . ($limit > 0 ? (string) $limit : '(none — full response)') . "\n";

    if ($body !== null) {
        spx_section('REQUEST BODY (JSON)');
        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    $res = $method === 'POST'
        ? spx_post($cfg, $endpoint, $body ?? [])
        : spx_get($cfg, $endpoint, $query);

    spx_section('TRANSPORT');
    echo "HTTP code : {$res['code']}\n";
    echo "Time      : " . number_format($res['ms'], 1) . " ms\n";
    if ($res['error'] !== '') {
        echo "cURL error: {$res['error']}\n";
    }

    spx_section('RESPONSE');
    echo spx_format_json($res['body'], $limit) . "\n";
}
