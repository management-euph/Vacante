<?php

declare(strict_types=1);

/**
 * Standalone Novoton API client — shared helper for the dev/novoton probes.
 *
 * NOT part of any addon. No CS-Cart bootstrap, no autoload, no DB. It just
 * speaks the Novoton XML-over-HTTP protocol directly so each probe can show
 * the RAW request + response for one API function.
 *
 * Protocol (mirrors addon-novoton-holidays NovotonHttpClient):
 *   POST {api_url}/index.php
 *     fn   = <function>          e.g. hotel_list, room_price, hotel_res_RQ
 *     key  = <api_key>
 *     id   = <api_id>
 *     xml  = <request body>      windows-1251, may embed <usr>/<psw>
 *     lang = <language>          e.g. UK, EN, RO
 *
 * Credentials default to the Novoton DEV/staging values already committed in
 * the novoton_holidays addon.xml (non-production test creds). Override with
 * env vars:
 *   NOVOTON_API_URL       host or full base URL (default b2b.allinclusivebg.com)
 *   NOVOTON_API_KEY       API key   (default TEST-TEST-TEST-TEST-TEST)
 *   NOVOTON_API_ID        API id    (default 713)
 *   NOVOTON_API_USER      <usr>     (required, no default)
 *   NOVOTON_API_PASSWORD  <psw>     (required, no default)
 *   NOVOTON_API_LANG      default language for calls (default UK)
 *   NOVOTON_API_INSECURE  1 to skip TLS verify (dev only)
 *
 * Every probe `require`s this file. Run a probe with `--help` to see its args.
 */

const NVT_DEFAULT_API_URL      = 'b2b.allinclusivebg.com';
const NVT_DEFAULT_API_KEY      = 'TEST-TEST-TEST-TEST-TEST';
const NVT_DEFAULT_API_ID       = '713';
const NVT_DEFAULT_API_USER     = ''; // set NOVOTON_API_USER; never commit credentials
const NVT_DEFAULT_API_PASSWORD = ''; // set NOVOTON_API_PASSWORD
const NVT_DEFAULT_LANG         = 'UK';

/** True when invoked from the command line (vs. served over a web SAPI). */
function nvt_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

/**
 * Resolve the API config from env vars, falling back to the committed dev creds.
 *
 * @return array{url:string, key:string, id:string, user:string, password:string, lang:string, insecure:bool}
 */
function nvt_config(): array
{
    $url = (string) (getenv('NOVOTON_API_URL') ?: NVT_DEFAULT_API_URL);
    // Preserve an explicit scheme; default to http:// (the provider is http-only).
    if (preg_match('#^https?://#', $url) !== 1) {
        $url = 'http://' . $url;
    }

    return [
        'url'      => rtrim($url, '/'),
        'key'      => (string) (getenv('NOVOTON_API_KEY') ?: NVT_DEFAULT_API_KEY),
        'id'       => (string) (getenv('NOVOTON_API_ID') ?: NVT_DEFAULT_API_ID),
        'user'     => (string) (getenv('NOVOTON_API_USER') ?: NVT_DEFAULT_API_USER),
        'password' => (string) (getenv('NOVOTON_API_PASSWORD') ?: NVT_DEFAULT_API_PASSWORD),
        'lang'     => (string) (getenv('NOVOTON_API_LANG') ?: NVT_DEFAULT_LANG),
        'insecure' => in_array((string) getenv('NOVOTON_API_INSECURE'), ['1', 'true', 'yes'], true),
    ];
}

/**
 * Read a named parameter from CLI (`--key=value`) or the query string (`?key=value`).
 * CLI bare positionals are also collected under numeric keys 0,1,2,… for probes
 * that accept a simple positional (e.g. a hotel id).
 */
function nvt_param(string $key, ?string $default = null): ?string
{
    static $cli = null;
    if ($cli === null) {
        $cli = [];
        if (nvt_is_cli()) {
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

/** True when `--help` / `?help` was passed. */
function nvt_wants_help(): bool
{
    return nvt_param('help') !== null;
}

/** XML declaration the Novoton API expects (windows-1251). */
function nvt_xml_header(): string
{
    return '<?xml version="1.0" encoding="windows-1251"?>';
}

/** <usr>/<psw> credential block for functions that require auth in the body. */
function nvt_credentials(array $cfg): string
{
    return '<usr>' . htmlspecialchars($cfg['user']) . '</usr>'
         . '<psw>' . htmlspecialchars($cfg['password']) . '</psw>';
}

/** Wrap a value in CDATA (resort/hotel names the API matches literally). */
function nvt_cdata(string $value): string
{
    $safe = str_replace(']]>', ']]]]><![CDATA[>', $value);
    return '<![CDATA[' . $safe . ']]>';
}

/** Mask credentials before echoing a request body. */
function nvt_mask(string $xml): string
{
    $xml = (string) preg_replace('/<usr>.*?<\/usr>/', '<usr>*****</usr>', $xml);
    $xml = (string) preg_replace('/<psw>.*?<\/psw>/', '<psw>*****</psw>', $xml);
    return $xml;
}

/**
 * POST one API function to Novoton and return the raw transport result.
 *
 * @return array{code:int, body:string, error:string, ms:float}
 */
function nvt_call(array $cfg, string $function, string $xml, ?string $lang = null): array
{
    $url  = $cfg['url'] . '/index.php';
    $post = [
        'fn'   => $function,
        'key'  => $cfg['key'],
        'id'   => $cfg['id'],
        'xml'  => $xml,
        'lang' => $lang ?? $cfg['lang'],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => !$cfg['insecure'],
        CURLOPT_SSL_VERIFYHOST => $cfg['insecure'] ? 0 : 2,
    ]);
    $start = microtime(true);
    $body  = curl_exec($ch);
    $ms    = (microtime(true) - $start) * 1000;
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    return [
        'code'  => $code,
        'body'  => is_string($body) ? $body : '',
        'error' => $err,
        'ms'    => $ms,
    ];
}

/**
 * Decode a raw Novoton response body (windows-1251) to UTF-8 and tidy it for
 * display: strip any junk before the XML, convert encoding, pretty-print.
 */
function nvt_decode_body(string $body): string
{
    if ($body === '') {
        return '';
    }
    // Trim anything before the XML declaration / first tag.
    $pos = stripos($body, '<?xml');
    if ($pos === false) {
        $pos = strpos($body, '<');
    }
    if ($pos !== false && $pos > 0) {
        $body = substr($body, $pos);
    }
    // The API declares windows-1251; convert to UTF-8 for terminals/browsers.
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($body, 'UTF-8', 'Windows-1251');
        if (is_string($converted) && $converted !== '') {
            $body = $converted;
        }
    }
    // Rewrite the encoding declaration so a re-parse doesn't re-decode.
    $body = (string) preg_replace('/encoding="[^"]*"/i', 'encoding="UTF-8"', $body);

    return $body;
}

/** Escape bare ampersands that aren't entity refs (mirrors the addon cleaner). */
function nvt_escape_amp(string $xml): string
{
    if (!str_contains($xml, '&')) {
        return $xml;
    }
    $parts = preg_split('/(<!\[CDATA\[.*?]]>)/s', $xml, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $out = '';
    foreach ($parts as $part) {
        $out .= str_starts_with($part, '<![CDATA[')
            ? $part
            : (string) preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;|#)/', '&amp;', $part);
    }
    return $out;
}

/**
 * Pretty-print an XML string. When $limit > 0, only the first $limit
 * occurrences of the most-repeated child element are kept and the rest are
 * summarised — the "limit" knob for list endpoints.
 */
function nvt_format_xml(string $xml, int $limit = 0): string
{
    $xml = nvt_escape_amp($xml);
    $prev = libxml_use_internal_errors(true);
    $dom  = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $loaded = $dom->loadXML($xml, LIBXML_NOCDATA | LIBXML_NONET);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$loaded || $dom->documentElement === null) {
        $note = $errors !== [] ? '  (XML parse failed: ' . trim($errors[0]->message) . ')' : '  (XML parse failed)';
        return $note . "\n" . $xml;
    }

    $trimNote = '';
    if ($limit > 0) {
        $trimNote = nvt_trim_repeated($dom, $limit);
    }

    $out = $dom->saveXML();
    return ($trimNote !== '' ? $trimNote . "\n" : '') . ($out === false ? $xml : $out);
}

/**
 * Find the element name that repeats most under a single parent and keep only
 * the first $limit of them, replacing the rest with a comment. Returns a short
 * note describing what was trimmed (or '' if nothing was).
 */
function nvt_trim_repeated(DOMDocument $dom, int $limit): string
{
    $counts = [];
    foreach ($dom->getElementsByTagName('*') as $el) {
        $counts[$el->nodeName] = ($counts[$el->nodeName] ?? 0) + 1;
    }
    if ($counts === []) {
        return '';
    }
    arsort($counts);
    $name = (string) array_key_first($counts);
    $total = $counts[$name];
    if ($total <= $limit) {
        return '';
    }

    // Group the repeated nodes by parent, then trim each group to $limit.
    $nodes = iterator_to_array($dom->getElementsByTagName($name));
    $byParent = [];
    foreach ($nodes as $node) {
        $byParent[spl_object_id($node->parentNode)][] = $node;
    }
    $removed = 0;
    foreach ($byParent as $group) {
        $i = 0;
        foreach ($group as $node) {
            if ($i++ < $limit) {
                continue;
            }
            $parent = $node->parentNode;
            if ($parent !== null) {
                $parent->removeChild($node);
                $removed++;
            }
        }
    }
    if ($removed === 0) {
        return '';
    }

    return "// limit={$limit}: showing first {$limit} <{$name}> of {$total} ({$removed} hidden)";
}

/** Emit a text/plain header for the browser SAPI. */
function nvt_out_setup(): void
{
    if (!nvt_is_cli() && !headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
}

/**
 * Print a labelled section header.
 */
function nvt_section(string $title): void
{
    echo "\n" . str_repeat('=', 72) . "\n" . $title . "\n" . str_repeat('=', 72) . "\n";
}

/**
 * Run a call and print request + response. This is what most probes end with.
 *
 * @param int $limit 0 = print the whole response; >0 = trim repeated rows.
 */
function nvt_run(array $cfg, string $function, string $requestXml, int $limit = 0, ?string $lang = null): void
{
    nvt_out_setup();

    nvt_section("Novoton API · fn={$function}");
    echo "Endpoint : {$cfg['url']}/index.php\n";
    echo "Language : " . ($lang ?? $cfg['lang']) . "\n";
    echo "Limit    : " . ($limit > 0 ? (string) $limit : '(none — full response)') . "\n";

    nvt_section('REQUEST (credentials masked)');
    echo nvt_format_xml(nvt_mask($requestXml)) . "\n";

    $res = nvt_call($cfg, $function, $requestXml, $lang);

    nvt_section('TRANSPORT');
    echo "HTTP code : {$res['code']}\n";
    echo "Time      : " . number_format($res['ms'], 1) . " ms\n";
    if ($res['error'] !== '') {
        echo "cURL error: {$res['error']}\n";
    }

    nvt_section('RESPONSE');
    if ($res['body'] === '') {
        echo "(empty body)\n";
        return;
    }
    echo nvt_format_xml(nvt_decode_body($res['body']), $limit) . "\n";
}
