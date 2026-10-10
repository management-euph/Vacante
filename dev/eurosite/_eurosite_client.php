<?php

declare(strict_types=1);

/**
 * Standalone Eurosite API client — shared helper for the dev/eurosite probes.
 *
 * NOT part of any addon. No CS-Cart bootstrap, no autoload, no DB. It speaks
 * the Eurosite XML-over-HTTP protocol directly so each probe can show the RAW
 * request + response for one RequestType.
 *
 * Protocol (mirrors addon-eurosite's EurositeXmlBuilder/EurositeHttpClient):
 *   POST {api_url}   (ONE url for everything; Content-Type: text/xml)
 *     <Request RequestType="...">
 *       <AuditInfo>RequestId/RequestUser/RequestPass/RequestTime/RequestLang</AuditInfo>
 *       <RequestDetails>{endpoint payload}</RequestDetails>
 *     </Request>
 *
 * Credentials default to the spec's placeholders, but REAL credentials are
 * required for every service, static data included: with an invalid/placeholder
 * account EVERY request answers ErrorId -1000 "You are not authorised to
 * access this server!" (verified live 2026-08-04 — with valid credentials the
 * same requests answer fine, host IP irrelevant). Override with env vars:
 *   EUROSITE_API_URL       default https://laguna.touringit.ro/server_xml/server.php
 *   EUROSITE_API_USER      default YourUser   (spec placeholder)
 *   EUROSITE_API_PASSWORD  default YourPassword
 *   EUROSITE_API_LANG      default RO
 *   EUROSITE_API_INSECURE  1 to skip TLS verify (dev only)
 *
 * Every probe `require`s this file. Run a probe with `--help` to see its args.
 */

const EURO_DEFAULT_API_URL  = 'https://laguna.touringit.ro/server_xml/server.php';
const EURO_DEFAULT_USER     = 'YourUser';
const EURO_DEFAULT_PASSWORD = 'YourPassword';
const EURO_DEFAULT_LANG     = 'RO';

/** True when invoked from the command line (vs. served over a web SAPI). */
function euro_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

/**
 * Resolve the API config from env vars, falling back to the spec placeholders.
 *
 * @return array{url:string, user:string, password:string, lang:string, insecure:bool}
 */
function euro_config(): array
{
    return [
        'url'      => (string) (getenv('EUROSITE_API_URL') ?: EURO_DEFAULT_API_URL),
        'user'     => (string) (getenv('EUROSITE_API_USER') ?: EURO_DEFAULT_USER),
        'password' => (string) (getenv('EUROSITE_API_PASSWORD') ?: EURO_DEFAULT_PASSWORD),
        'lang'     => (string) (getenv('EUROSITE_API_LANG') ?: EURO_DEFAULT_LANG),
        'insecure' => getenv('EUROSITE_API_INSECURE') === '1',
    ];
}

/**
 * Read a probe parameter: `--key=value` on the CLI, `?key=value` in a browser.
 */
function euro_param(string $key, ?string $default = null): ?string
{
    static $cli = null;
    if ($cli === null) {
        $cli = [];
        if (euro_is_cli()) {
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
function euro_wants_help(): bool
{
    return euro_param('help') !== null;
}

/** XML-escape a text value. */
function euro_esc(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Wrap an endpoint payload in the full <Request> envelope (same shape as the
 * addon's EurositeXmlBuilder::envelope()).
 */
function euro_envelope(array $cfg, string $requestType, string $detailsXml): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<Request RequestType="' . euro_esc($requestType) . '">' . "\n"
        . '  <AuditInfo>' . "\n"
        . '    <RequestId>' . time() . '</RequestId>' . "\n"
        . '    <RequestUser>' . euro_esc($cfg['user']) . '</RequestUser>' . "\n"
        . '    <RequestPass>' . euro_esc($cfg['password']) . '</RequestPass>' . "\n"
        . '    <RequestTime>' . date('Y-m-d\TH:i:s') . '</RequestTime>' . "\n"
        . '    <RequestLang>' . euro_esc($cfg['lang']) . '</RequestLang>' . "\n"
        . '  </AuditInfo>' . "\n"
        . '  <RequestDetails>' . "\n"
        . $detailsXml . "\n"
        . '  </RequestDetails>' . "\n"
        . '</Request>';
}

/** Mask <RequestPass> before echoing a request body. */
function euro_mask(string $xml): string
{
    return (string) preg_replace('/<RequestPass>.*?<\/RequestPass>/', '<RequestPass>*****</RequestPass>', $xml);
}

/**
 * POST an envelope to the web service.
 *
 * @return array{code:int, body:string, error:string, ms:float}
 */
function euro_call(array $cfg, string $envelope): array
{
    $ch = curl_init($cfg['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $envelope,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_SSL_VERIFYPEER => !$cfg['insecure'],
        CURLOPT_SSL_VERIFYHOST => $cfg['insecure'] ? 0 : 2,
    ]);

    $start = microtime(true);
    $body  = curl_exec($ch);
    $ms    = (microtime(true) - $start) * 1000;
    $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $body === false ? curl_error($ch) : '';
    curl_close($ch);

    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'error' => $error, 'ms' => $ms];
}

/**
 * Pretty-print XML; with $limit > 0 the most-repeated element is trimmed to
 * the first $limit occurrences (same helper the novoton/sphinx probes use).
 */
function euro_format_xml(string $xml, int $limit = 0): string
{
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
        $trimNote = euro_trim_repeated($dom, $limit);
    }

    $out = $dom->saveXML();
    return ($trimNote !== '' ? $trimNote . "\n" : '') . ($out === false ? $xml : $out);
}

/**
 * Keep only the first $limit occurrences of the most-repeated element.
 * Returns a short note describing what was trimmed (or '' if nothing was).
 */
function euro_trim_repeated(DOMDocument $dom, int $limit): string
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
function euro_out_setup(): void
{
    if (!euro_is_cli() && !headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
}

/** Print a labelled section header. */
function euro_section(string $title): void
{
    echo "\n" . str_repeat('=', 72) . "\n" . $title . "\n" . str_repeat('=', 72) . "\n";
}

/**
 * Run a call and print request + response. This is what most probes end with.
 *
 * @param int $limit 0 = print the whole response; >0 = trim repeated rows.
 */
function euro_run(array $cfg, string $requestType, string $detailsXml, int $limit = 0): void
{
    euro_out_setup();

    euro_section("Eurosite API · RequestType={$requestType}");
    echo "Endpoint : {$cfg['url']}\n";
    echo "Language : {$cfg['lang']}\n";
    echo "User     : " . ($cfg['user'] === EURO_DEFAULT_USER ? 'YourUser (spec placeholder)' : $cfg['user']) . "\n";
    echo "Limit    : " . ($limit > 0 ? (string) $limit : '(none — full response)') . "\n";

    $envelope = euro_envelope($cfg, $requestType, $detailsXml);

    euro_section('REQUEST (credentials masked)');
    echo euro_format_xml(euro_mask($envelope)) . "\n";

    $res = euro_call($cfg, $envelope);

    euro_section('TRANSPORT');
    echo "HTTP code : {$res['code']}\n";
    echo "Time      : " . number_format($res['ms'], 1) . " ms\n";
    if ($res['error'] !== '') {
        echo "cURL error: {$res['error']}\n";
    }

    euro_section('RESPONSE');
    if ($res['body'] === '') {
        echo "(empty body)\n";
        return;
    }
    echo euro_format_xml($res['body'], $limit) . "\n";

    if (str_contains($res['body'], '<ErrorId>-1000</ErrorId>')) {
        echo "\nNOTE: -1000 'not authorised to access this server' means the credentials\n"
           . "were rejected — set EUROSITE_API_USER / EUROSITE_API_PASSWORD to a valid\n"
           . "XML account (the spec placeholders always get this refusal).\n";
    }
}
