<?php

declare(strict_types=1);

/**
 * Standalone Sphinx API hotel fetcher — location/coordinate investigation tool.
 *
 * NOT part of any addon. No CS-Cart bootstrap, no addon autoload, no DB. It
 * just calls the Sphinx REST API directly so we can inspect the RAW hotel
 * payload — above all the latitude/longitude and the address object — to judge
 * how precise Sphinx's own location data is before deciding how to improve the
 * storefront "arată pe hartă / show on map" pin.
 *
 * Endpoint:  GET /api/v1/static/hotels/{id}   (Authorization: Bearer <token>)
 *
 * Usage (CLI):
 *   php GetHotelbyId.php                 # defaults to hotel 3612
 *   php GetHotelbyId.php 3612            # one hotel
 *   php GetHotelbyId.php 3612 234 999    # several — handy for comparing precision
 *   SPHINX_API_TOKEN=... SPHINX_API_URL=... php GetHotelbyId.php 3612
 *   SPHINX_API_INSECURE=1 php GetHotelbyId.php 3612   # skip TLS verify (dev only)
 *
 * Usage (browser — drop the folder anywhere PHP is served):
 *   GetHotelbyId.php?id=3612
 *
 * Credentials default to the Sphinx DEV/staging values already committed in
 * the sphinx_holidays addon.xml (these are non-production dev creds). Override
 * with the env vars above when you get production keys.
 */

// ── Config ─────────────────────────────────────────────────────────────────
const DEFAULT_HOTEL_ID = 3612;
const DEFAULT_API_URL  = 'https://api.sphinx2.christiantour.dev.ploi.imementohub.com';
const DEFAULT_API_TOKEN = ''; // set SPHINX_API_TOKEN; never commit a key

$baseUrl  = rtrim((string) (getenv('SPHINX_API_URL') ?: DEFAULT_API_URL), '/');
$token    = (string) (getenv('SPHINX_API_TOKEN') ?: DEFAULT_API_TOKEN);
$insecure = in_array((string) getenv('SPHINX_API_INSECURE'), ['1', 'true', 'yes'], true);
$isCli    = PHP_SAPI === 'cli';

// ── Resolve which hotel id(s) to fetch ─────────────────────────────────────
$ids = [];
if ($isCli) {
    $ids = array_slice($argv, 1);
} elseif (isset($_GET['id'])) {
    $ids = preg_split('/[,\s]+/', (string) $_GET['id'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
$ids = array_values(array_filter(array_map('intval', $ids), static fn (int $n): bool => $n > 0));
if ($ids === []) {
    $ids = [DEFAULT_HOTEL_ID];
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

/**
 * GET {baseUrl}{endpoint} with Bearer auth. Returns the raw body, HTTP code and
 * any transport error — no decoding, so we can print exactly what came back.
 *
 * @return array{code:int, body:string, error:string}
 */
function sphinx_get(string $baseUrl, string $endpoint, string $token, bool $insecure): array
{
    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => !$insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $code,
        'body' => is_string($body) ? $body : '',
        'error' => $err,
    ];
}

/** Coalesce a possibly-missing/empty scalar to a printable string. */
function s(mixed $v): string
{
    if ($v === null || $v === '') {
        return '(empty)';
    }

    return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$divider = str_repeat('=', 74);

foreach ($ids as $id) {
    $endpoint = "/api/v1/static/hotels/{$id}";
    echo "{$divider}\n";
    echo "GET {$baseUrl}{$endpoint}\n";
    echo "{$divider}\n";

    $res = sphinx_get($baseUrl, $endpoint, $token, $insecure);

    if ($res['error'] !== '') {
        echo "TRANSPORT ERROR: {$res['error']}\n";
        echo "(If this is a TLS/certificate error on a dev box, retry with SPHINX_API_INSECURE=1)\n\n";
        continue;
    }

    echo "HTTP {$res['code']}\n\n";

    $decoded = json_decode($res['body'], true);
    if (!is_array($decoded)) {
        echo "Response was not JSON. Raw body:\n{$res['body']}\n\n";
        continue;
    }

    // Pretty-print the full payload.
    echo "── RAW PAYLOAD ──────────────────────────────────────────────────────────\n";
    echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

    // The hotel object lives under `data`.
    $hotel = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
    $address = is_array($hotel['address'] ?? null) ? $hotel['address'] : [];
    $lat = $hotel['latitude'] ?? null;
    $lng = $hotel['longitude'] ?? null;
    $hasCoords = is_numeric($lat) && is_numeric($lng) && ((float) $lat !== 0.0 || (float) $lng !== 0.0);

    echo "── LOCATION SUMMARY ─────────────────────────────────────────────────────\n";
    echo '  id            : ' . s($hotel['id'] ?? $id) . "\n";
    echo '  name          : ' . s($hotel['name'] ?? null) . "\n";
    echo '  destination_id: ' . s($hotel['destination_id'] ?? null) . "\n";
    echo '  latitude      : ' . s($lat) . "\n";
    echo '  longitude     : ' . s($lng) . "\n";
    echo '  address.street: ' . s($address['street'] ?? null) . "\n";
    echo '  address.city  : ' . s($address['city'] ?? null) . "\n";
    echo '  address.country: ' . s($address['country'] ?? null) . "\n";
    echo '  giata_id      : ' . s(($hotel['external_ids'] ?? [])['giata_id'] ?? null) . "\n\n";

    echo "── MAP LINKS (open both, compare which lands on the actual building) ─────\n";
    if ($hasCoords) {
        // (a) What the storefront renders TODAY: a bare coordinate pin.
        echo "  by coordinates (current pin):\n";
        echo "    https://www.google.com/maps?q={$lat},{$lng}\n";
        echo "  same point on OpenStreetMap:\n";
        echo "    https://www.openstreetmap.org/?mlat={$lat}&mlon={$lng}#map=18/{$lat}/{$lng}\n";
    } else {
        echo "  by coordinates: NONE — API latitude/longitude is null/zero.\n";
        echo "  (API doc: coordinates 'can be null if it cannot be accurately established')\n";
    }

    // (b) The proposed improvement: let Google resolve the hotel by name+city,
    //     which usually snaps to its own rooftop-accurate listing.
    $queryParts = array_filter([
        s($hotel['name'] ?? '') !== '(empty)' ? (string) $hotel['name'] : '',
        is_string($address['city'] ?? null) ? $address['city'] : '',
        is_string($address['country'] ?? null) ? $address['country'] : '',
    ], static fn (string $p): bool => $p !== '');
    if ($queryParts !== []) {
        $q = rawurlencode(implode(', ', $queryParts));
        echo "  by name+city (proposed — Google resolves the hotel listing):\n";
        echo "    https://www.google.com/maps/search/?api=1&query={$q}\n";
    }
    echo "\n";
}
