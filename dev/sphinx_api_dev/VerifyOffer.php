<?php

declare(strict_types=1);

/**
 * Standalone Sphinx API VERIFY probe — is the verify endpoint actually down?
 *
 * NOT part of any addon. No CS-Cart bootstrap, no addon autoload, no DB. Same
 * shape as the sibling probes: curl + a Bearer token.
 *
 * WHY THIS EXISTS: search and verify are separate endpoints, and the storefront
 * fails asymmetrically when only verify is broken — results render fine, but
 * BOTH the "Condiții de Plată și Anulare" modal and the "Rezervă acum" button
 * fail, because each of them re-verifies the offer (the search payload carries
 * no terms and no booking guarantee: must_verify=true). From inside the store
 * those two look like separate bugs. This proves in one run whether verify is
 * returning 5xx while search is healthy — and prints the provider's raw error
 * body, which is what has to be forwarded to them.
 *
 * Offer ids expire, so by default this SEARCHES first to obtain a live one and
 * then verifies it. Pass offer_id=... to verify a specific id instead.
 *
 * Endpoints:
 *   POST /api/v1/hotels/search             → { "cursor": "..." }
 *   GET  /api/v1/hotels/results?cursor=..  → { "data": [offers], "cursor": ...|null }
 *   GET  /api/v1/hotels/verify?offer_id=.. → the offer, or an error
 *   (Authorization: Bearer <token>)
 *
 * Usage (CLI):
 *   php VerifyOffer.php                                  # search, then verify the first offer
 *   php VerifyOffer.php offer_id=abc123                  # verify one specific id
 *   php VerifyOffer.php destination_id=3713 check_in=2026-09-07 check_out=2026-09-13
 *   php VerifyOffer.php count=5                          # verify the first 5 offers found
 *   SPHINX_API_TOKEN=... SPHINX_API_URL=... php VerifyOffer.php
 *   SPHINX_API_INSECURE=1 php VerifyOffer.php            # skip TLS verify (dev only)
 *
 * Usage (browser — the folder is auto-served in the docker sandbox):
 *   VerifyOffer.php
 *   VerifyOffer.php?offer_id=abc123
 *
 * Exit code is 1 when any verify call fails, so it can be used as a smoke check.
 */

// ── Config ─────────────────────────────────────────────────────────────────
const DEFAULT_API_URL   = 'https://api.sphinx2.christiantour.dev.ploi.imementohub.com';
const DEFAULT_API_TOKEN = ''; // set SPHINX_API_TOKEN; never commit a key

const DEFAULT_DESTINATION_ID = 3713;
const DEFAULT_CHECK_IN       = '2026-09-07';
const DEFAULT_CHECK_OUT      = '2026-09-13';
const DEFAULT_CURRENCY       = 'EUR';
const MAX_RESULT_POLLS       = 10;

$baseUrl  = rtrim((string) (getenv('SPHINX_API_URL') ?: DEFAULT_API_URL), '/');
$token    = (string) (getenv('SPHINX_API_TOKEN') ?: DEFAULT_API_TOKEN);
$insecure = in_array((string) getenv('SPHINX_API_INSECURE'), ['1', 'true', 'yes'], true);
$isCli    = PHP_SAPI === 'cli';

$in = $_GET;
if ($isCli) {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $in[$k] = $v;
        }
    }
}
$offerId       = (string) ($in['offer_id'] ?? '');
$destinationId = (int) ($in['destination_id'] ?? DEFAULT_DESTINATION_ID);
$checkIn       = (string) ($in['check_in'] ?? DEFAULT_CHECK_IN);
$checkOut      = (string) ($in['check_out'] ?? DEFAULT_CHECK_OUT);
$currency      = (string) ($in['currency'] ?? DEFAULT_CURRENCY);
$howMany       = max(1, (int) ($in['count'] ?? 1));

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

/**
 * One Sphinx API call with Bearer auth. Returns raw body + HTTP code + error.
 *
 * @param array<string, mixed>|null $jsonBody null = GET, array = POST with JSON
 * @return array{code:int, body:string, error:string, seconds:float}
 */
function sphinx_call(string $url, string $token, bool $insecure, ?array $jsonBody = null): array
{
    $started = microtime(true);
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => !$insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
    ];
    if ($jsonBody !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = (string) json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $code,
        'body' => is_string($body) ? $body : '',
        'error' => $err,
        'seconds' => round(microtime(true) - $started, 2),
    ];
}

/** How the storefront classifies the same response — keep these in step. */
function classify(int $code, string $transportError): string
{
    if ($code === 0) {
        return 'OUTAGE (transport failure — ' . ($transportError !== '' ? $transportError : 'no response') . ')';
    }
    if ($code >= 500) {
        return 'OUTAGE (server error)';
    }
    if ($code >= 400) {
        return 'REJECTED (offer expired / bad request — NOT an outage)';
    }

    return 'OK';
}

$divider = str_repeat('=', 74);
$offerIds = [];

// ── Step 0: get live offer ids, unless one was supplied ────────────────────
if ($offerId !== '') {
    $offerIds = [$offerId];
    echo "{$divider}\nUsing the supplied offer_id (skipping search)\n{$divider}\n\n";
} else {
    echo "{$divider}\n";
    echo "STEP 1 — search, to obtain a LIVE offer id\n";
    echo "POST {$baseUrl}/api/v1/hotels/search   ({$checkIn} → {$checkOut}, destination {$destinationId})\n";
    echo "{$divider}\n";

    $search = sphinx_call($baseUrl . '/api/v1/hotels/search', $token, $insecure, [
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'destination_id' => $destinationId,
        'occupancy' => [['adults' => 2, 'children_ages' => []]],
        'currency' => $currency,
    ]);
    echo "HTTP {$search['code']}  ({$search['seconds']}s)  " . classify($search['code'], $search['error']) . "\n";
    if ($search['code'] !== 200) {
        echo "body: " . substr($search['body'], 0, 800) . "\n";
        echo "\nSearch itself failed — this is NOT the verify-only pattern.\n";
        exit(1);
    }

    $decoded = json_decode($search['body'], true);
    $cursor = is_array($decoded) ? ($decoded['cursor'] ?? ($decoded['data']['cursor'] ?? null)) : null;

    for ($poll = 0; $poll < MAX_RESULT_POLLS && is_string($cursor) && $cursor !== '' && count($offerIds) < $howMany; $poll++) {
        sleep(1);
        $results = sphinx_call($baseUrl . '/api/v1/hotels/results?cursor=' . urlencode($cursor), $token, $insecure);
        if ($results['code'] !== 200) {
            echo "results poll HTTP {$results['code']} — stopping\n";
            break;
        }
        $page = json_decode($results['body'], true);
        $rows = is_array($page) ? ($page['data'] ?? []) : [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && !empty($row['offer_id'])) {
                $offerIds[] = (string) $row['offer_id'];
                if (count($offerIds) >= $howMany) {
                    break;
                }
            }
        }
        $cursor = is_array($page) ? ($page['cursor'] ?? null) : null;
    }

    echo 'search returned ' . count($offerIds) . " usable offer id(s)\n\n";
    if ($offerIds === []) {
        echo "No offers to verify — try other dates or destination_id.\n";
        exit(1);
    }
}

// ── Step 2: verify each ────────────────────────────────────────────────────
echo "{$divider}\n";
echo "STEP 2 — verify\n";
echo "GET {$baseUrl}/api/v1/hotels/verify?offer_id=…\n";
echo "{$divider}\n";

$failures = 0;
foreach ($offerIds as $i => $id) {
    $verify = sphinx_call($baseUrl . '/api/v1/hotels/verify?offer_id=' . urlencode($id), $token, $insecure);
    $verdict = classify($verify['code'], $verify['error']);
    if ($verify['code'] !== 200) {
        $failures++;
    }

    echo "\n[" . ($i + 1) . '/' . count($offerIds) . "] offer_id={$id}\n";
    echo "    HTTP {$verify['code']}  ({$verify['seconds']}s)  {$verdict}\n";
    if ($verify['error'] !== '') {
        echo "    transport error: {$verify['error']}\n";
    }
    echo "    raw body: " . (trim($verify['body']) === '' ? '(empty)' : substr($verify['body'], 0, 1200)) . "\n";
}

echo "\n{$divider}\n";
if ($failures === 0) {
    echo "VERDICT: verify is HEALTHY (" . count($offerIds) . "/" . count($offerIds) . " ok).\n";
    echo "If the storefront still shows the outage messages, the fault is on OUR side —\n";
    echo "check Administration > Logs for 'Sphinx booking_form: verify' / 'Sphinx offer_terms: verify'.\n";
    exit(0);
}

echo "VERDICT: verify is FAILING ({$failures}/" . count($offerIds) . ").\n";
echo "Search works, verify does not — this is exactly the pattern that makes the terms\n";
echo "modal and the Rezervă acum button both fail while the results list renders fine.\n";
echo "Send the raw body above to the Sphinx developers; nothing on our side can fix a 5xx.\n";
exit(1);
