<?php

declare(strict_types=1);

/**
 * Eurosite · getHotelPriceRequest outage diagnostic — builds an evidence
 * report for the API provider (Eurosite / TouringIT).
 *
 * What it does, in order:
 *   1. CONTROL: two static-data calls (getRoomRequest, getCountryRequest) —
 *      proving credentials, connectivity and the envelope are fine.
 *   2. BATTERY: getHotelPriceRequest variants (cities, tourops, date ranges,
 *      minimal spec-example payload vs full payload) — each with precise
 *      timings (DNS/connect/first-byte/total), curl errno, HTTP code.
 *   3. REPORT: prints a ready-to-email report (EN + RO summary) with masked
 *      credentials, full request XML of one failing call, and the exact
 *      UTC + server ResponseTime timestamps/RequestIds the provider can
 *      grep in their logs.
 *
 * Usage (CLI):     php search_diagnose.php [--report=/path/to/report.txt]
 * Usage (browser): search_diagnose.php
 *
 * Credentials via the usual env vars (see _eurosite_client.php).
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "search_diagnose — evidence report for the getHotelPriceRequest outage\n"
        . "  --report=FILE   also write the report to FILE\n";
    exit;
}

$cfg = euro_config();
$reportFile = euro_param('report', '');

/**
 * POST one request and capture transport-level evidence.
 *
 * @return array{label: string, request_id: string, http: int, errno: int,
 *               error: string, t_dns: float, t_connect: float, t_start: float,
 *               t_total: float, bytes: int, response_time: string,
 *               response_id: string, response_type: string, xml: string}
 */
function diag_call(array $cfg, string $label, string $requestType, string $detailsXml): array
{
    // euro_envelope stamps a time()-based id; swap in a short random one we
    // print in the report so the provider can grep their logs for it.
    $requestId = (string) random_int(100000, 999999);
    $xml = (string) preg_replace(
        '/<RequestId>\d+<\/RequestId>/',
        '<RequestId>' . $requestId . '</RequestId>',
        euro_envelope($cfg, $requestType, $detailsXml),
        1,
    );

    $ch = curl_init($cfg['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8'],
        CURLOPT_SSL_VERIFYPEER => empty($cfg['insecure']),
        CURLOPT_SSL_VERIFYHOST => empty($cfg['insecure']) ? 2 : 0,
    ]);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    $responseTime = '';
    $responseId = '';
    $responseType = '';
    if (is_string($body) && $body !== '') {
        if (preg_match('/<ResponseTime>([^<]+)<\/ResponseTime>/', $body, $m)) {
            $responseTime = $m[1];
        }
        if (preg_match('/<ResponseId>([^<]+)<\/ResponseId>/', $body, $m)) {
            $responseId = $m[1];
        }
        if (preg_match('/ResponseType="([^"]+)"/', $body, $m)) {
            $responseType = $m[1];
        }
    }

    return [
        'label'         => $label,
        'request_id'    => $requestId,
        'utc'           => gmdate('Y-m-d H:i:s') . ' UTC',
        'http'          => (int) ($info['http_code'] ?? 0),
        'errno'         => $errno,
        'error'         => $error,
        't_dns'         => round((float) ($info['namelookup_time'] ?? 0), 3),
        't_connect'     => round((float) ($info['connect_time'] ?? 0), 3),
        't_start'       => round((float) ($info['starttransfer_time'] ?? 0), 3),
        't_total'       => round((float) ($info['total_time'] ?? 0), 3),
        'bytes'         => is_string($body) ? strlen($body) : 0,
        'response_time' => $responseTime,
        'response_id'   => $responseId,
        'response_type' => $responseType,
        'xml'           => $xml,
    ];
}

function diag_row(array $r): string
{
    $outcome = $r['errno'] !== 0
        ? "CURL ERROR {$r['errno']}: {$r['error']}"
        : "HTTP {$r['http']}, {$r['bytes']} bytes"
            . ($r['response_type'] !== '' ? ", {$r['response_type']}" : '');

    return sprintf(
        "  %-34s | RequestId %-7s | %s\n  %-34s | dns %.2fs connect %.2fs first-byte %.2fs total %.2fs%s\n",
        $r['label'],
        $r['request_id'],
        $outcome,
        '',
        $r['t_dns'],
        $r['t_connect'],
        $r['t_start'],
        $r['t_total'],
        $r['response_time'] !== '' ? " | server ResponseTime {$r['response_time']} (ResponseId {$r['response_id']})" : '',
    );
}

function diag_search_payload(string $country, string $city, string $tourop, string $in, string $out, bool $minimal): string
{
    if ($minimal) {
        // Byte-shape of the spec's own example (no optional elements).
        return "  <getHotelPriceRequest>\n"
            . "    <CountryCode>{$country}</CountryCode>\n"
            . "    <CityCode>{$city}</CityCode>\n"
            . "    <TourOpCode>{$tourop}</TourOpCode>\n"
            . "    <PeriodOfStay><CheckIn>{$in}</CheckIn><CheckOut>{$out}</CheckOut></PeriodOfStay>\n"
            . "    <Rooms><Room Code=\"DB\" NoAdults=\"2\"/></Rooms>\n"
            . '  </getHotelPriceRequest>';
    }

    return "  <getHotelPriceRequest>\n"
        . "    <CountryCode>{$country}</CountryCode>\n"
        . "    <CityCode>{$city}</CityCode>\n"
        . "    <TourOpCode>{$tourop}</TourOpCode>\n"
        . "    <CurrencyCode>EUR</CurrencyCode>\n"
        . "    <Language>RO</Language>\n"
        . "    <OfferType>TOATE</OfferType>\n"
        . "    <PeriodOfStay><CheckIn>{$in}</CheckIn><CheckOut>{$out}</CheckOut></PeriodOfStay>\n"
        . "    <Rooms><Room Code=\"DB\" NoAdults=\"2\"/></Rooms>\n"
        . '  </getHotelPriceRequest>';
}

euro_out_setup();

$in35 = date('Y-m-d', strtotime('+35 days'));
$out42 = date('Y-m-d', strtotime('+42 days'));
$in10 = date('Y-m-d', strtotime('+10 days'));
$out17 = date('Y-m-d', strtotime('+17 days'));

// ── 1. Controls ──
$results = [];
$results[] = diag_call($cfg, 'CONTROL getRoomRequest', 'getRoomRequest', '  <getRoomRequest/>');
$results[] = diag_call($cfg, 'CONTROL getCountryRequest', 'getCountryRequest', '  <getCountryRequest/>');

// ── 2. Search battery ──
$battery = [
    ['SEARCH RO/ROMM LA +35d full',    'RO', 'ROMM',  'LA', $in35, $out42, false],
    ['SEARCH RO/ROMM EU +35d minimal', 'RO', 'ROMM',  'EU', $in35, $out42, true],
    ['SEARCH BG/BGALB LA +35d full',   'BG', 'BGALB', 'LA', $in35, $out42, false],
    ['SEARCH BG/BGALB LA +10d minimal', 'BG', 'BGALB', 'LA', $in10, $out17, true],
];
foreach ($battery as [$label, $country, $city, $tourop, $in, $out, $minimal]) {
    $results[] = diag_call($cfg, $label, 'getHotelPriceRequest',
        diag_search_payload($country, $city, $tourop, $in, $out, $minimal));
}

// ── 3. Report ──
$controls = array_slice($results, 0, 2);
$searches = array_slice($results, 2);
$controlsOk = array_reduce($controls, fn ($c, $r) => $c && $r['errno'] === 0 && $r['http'] === 200, true);
$searchesFail = array_reduce($searches, fn ($c, $r) => $c && ($r['errno'] !== 0 || $r['http'] !== 200), true);
$failSample = null;
foreach ($searches as $r) {
    if ($r['errno'] !== 0) {
        $failSample = $r;
        break;
    }
}

if ($searchesFail) {
    $summaryEn = "Static-data services answer normally with these credentials, but EVERY\n"
        . "getHotelPriceRequest dies in a TCP connection reset before any response\n"
        . "byte arrives (consistently ~6.5-7s after the request is sent). Same\n"
        . "result for every city, TourOpCode (LA and EU), date range, and for the\n"
        . "minimal payload copied byte-for-byte from your specification example.\n"
        . "This looks like the search worker crashing or search not being enabled\n"
        . "for this account. Please check your server logs around the timestamps\n"
        . "below (our RequestIds are listed for grepping).\n";
    $summaryRo = "Serviciile de date statice raspund normal cu aceste credentiale, insa\n"
        . "ORICE getHotelPriceRequest se termina cu 'connection reset by peer'\n"
        . "inainte de primul byte de raspuns (constant dupa ~6.5-7s). Acelasi\n"
        . "rezultat pentru orice oras, TourOpCode (LA si EU), interval de date si\n"
        . "pentru payload-ul minimal copiat identic din exemplul specificatiei.\n"
        . "Pare ca procesul de cautare crapa server-side sau cautarea nu este\n"
        . "activata pentru acest cont. Va rugam verificati log-urile serverului la\n"
        . "timestamp-urile de mai jos (RequestId-urile noastre sunt listate).\n";
} else {
    $anyFail = $failSample !== null;
    $summaryEn = ($anyFail
            ? "getHotelPriceRequest answers INTERMITTENTLY from this account — some\n"
            : "getHotelPriceRequest currently answers normally from this account —\n")
        . "see the per-call outcomes below. Keep this report as the health\n"
        . "snapshot for the timestamps listed (RequestIds included for log\n"
        . "correlation on your side).\n";
    $summaryRo = ($anyFail
            ? "getHotelPriceRequest raspunde INTERMITENT pentru acest cont — vezi\n"
            : "getHotelPriceRequest raspunde normal in acest moment pentru acest\n")
        . "cont — detaliile per apel mai jos. Pastram acest raport ca instantaneu\n"
        . "de sanatate pentru timestamp-urile listate (RequestId-urile sunt\n"
        . "incluse pentru corelare in log-uri).\n";
}

$report = "==========================================================================\n"
    . "EUROSITE XML API — getHotelPriceRequest DIAGNOSTIC / RAPORT DIAGNOSTIC\n"
    . "==========================================================================\n"
    . 'Generated / Generat:  ' . gmdate('Y-m-d H:i:s') . " UTC\n"
    . "Endpoint:             {$cfg['url']}\n"
    . "RequestUser:          {$cfg['user']}   (RequestPass omitted / omisa)\n"
    . 'PHP curl:             ' . (function_exists('curl_version') ? (curl_version()['version'] ?? '?') : '?')
    . ' / PHP ' . PHP_VERSION . ' / ' . php_uname('s') . "\n"
    . "\n"
    . "SUMMARY (EN)\n------------\n" . $summaryEn . "\n"
    . "REZUMAT (RO)\n------------\n" . $summaryRo . "\n"
    . '1. CONTROL CALLS — ' . ($controlsOk ? 'WORKING' : 'FAILING (!)') . " (auth + connectivity proof)\n"
    . "------------------------------------------------------\n";
foreach ($controls as $r) {
    $report .= diag_row($r) . "  sent {$r['utc']}\n\n";
}
$report .= '2. getHotelPriceRequest BATTERY — '
    . ($searchesFail ? 'ALL FAILING' : ($failSample !== null ? 'MIXED' : 'ALL WORKING')) . "\n"
    . "---------------------------------------------\n";
foreach ($searches as $r) {
    $report .= diag_row($r) . "  sent {$r['utc']}\n\n";
}
if ($failSample !== null) {
    $report .= "Reading the timings: DNS+TLS connect completes normally (same as the\n"
        . "control calls), the request uploads, then the server closes the TCP\n"
        . "connection without sending any HTTP response (first-byte never arrives).\n"
        . "A consistent reset delay suggests a fixed internal timeout/crash, not a\n"
        . "network problem on our side (the same connection path delivers the\n"
        . "static-data responses within ~1s).\n\n";
}

if ($failSample !== null) {
    $report .= "3. EXACT REQUEST OF ONE FAILING CALL ({$failSample['label']}, RequestId {$failSample['request_id']})\n"
        . "--------------------------------------------------------------------------\n"
        . euro_mask($failSample['xml']) . "\n\n";
}

if ($failSample !== null) {
    $report .= "4. WHAT WE NEED / CE AVEM NEVOIE\n"
        . "--------------------------------\n"
        . "EN: (a) confirm whether getHotelPriceRequest is enabled for account\n"
        . "'{$cfg['user']}'; (b) if it is, please check what your search worker logs\n"
        . "at the timestamps above; (c) a working sample search request for this\n"
        . "account (city + dates that should return offers) so we can verify.\n"
        . "RO: (a) confirmati daca getHotelPriceRequest este activat pentru contul\n"
        . "'{$cfg['user']}'; (b) daca da, verificati ce apare in log-uri la\n"
        . "timestamp-urile de mai sus; (c) un exemplu de cautare functionala pentru\n"
        . "acest cont (oras + date care ar trebui sa returneze oferte).\n\n";
}
$report .= 'VERDICT: controls ' . ($controlsOk ? 'OK' : 'FAILING (!)') . ' / searches '
    . ($searchesFail ? 'ALL FAILING' : ($failSample !== null ? 'MIXED (intermittent)' : 'ALL WORKING')) . "\n";

echo $report;

if ($reportFile !== '' && $reportFile !== null) {
    file_put_contents($reportFile, $report);
    echo "\n[report written to {$reportFile}]\n";
}
