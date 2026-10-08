<?php
/**
 * Sphinx API - Retrieve all supported countries
 *
 * Calls GET /api/v1/static/destinations and filters by type "country".
 * Paginates through all pages to build a complete list.
 *
 * Usage: php sphinx_get_countries.php
 */

declare(strict_types=1);

// --- Configuration ---
$apiBaseUrl = 'https://api.sphinx2.christiantour.dev.ploi.imementohub.com';
$apiKey     = (string) getenv('SPHINX_API_TOKEN'); // never commit a key

// --- Functions ---

function fetchDestinationsPage(string $baseUrl, string $token, int $page, int $perPage = 1000): ?array
{
    $url = sprintf('%s/api/v1/static/destinations?page=%d&per_page=%d', $baseUrl, $page, $perPage);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        echo "  [ERROR] cURL error: {$error}\n";
        return null;
    }

    if ($httpCode === 429) {
        echo "  [WARN] Rate limited. Waiting 60 seconds...\n";
        sleep(60);
        return fetchDestinationsPage($baseUrl, $token, $page, $perPage);
    }

    if ($httpCode !== 200) {
        echo "  [ERROR] HTTP {$httpCode}: {$response}\n";
        return null;
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "  [ERROR] JSON decode error: " . json_last_error_msg() . "\n";
        return null;
    }

    return $data;
}

// --- Main ---

echo "=== Sphinx API - Retrieve Supported Countries ===\n\n";

$countries  = [];
$page       = 1;
$lastPage   = 1;

do {
    echo "Fetching destinations page {$page}/{$lastPage}...\n";

    $result = fetchDestinationsPage($apiBaseUrl, $apiKey, $page);

    if ($result === null) {
        echo "[ERROR] Failed to fetch page {$page}. Aborting.\n";
        exit(1);
    }

    if (!isset($result['data']) || !is_array($result['data'])) {
        echo "[ERROR] Unexpected response structure on page {$page}.\n";
        exit(1);
    }

    // Update pagination info
    if (isset($result['meta']['last_page'])) {
        $lastPage = (int) $result['meta']['last_page'];
    }

    // Filter countries from this page
    foreach ($result['data'] as $destination) {
        if (isset($destination['type']) && $destination['type'] === 'country') {
            $countries[$destination['country_code']] = [
                'id'           => $destination['id'],
                'name'         => $destination['name'],
                'country_code' => $destination['country_code'],
                'parent_id'    => $destination['parent_id'] ?? null,
                'geoname_id'   => $destination['geoname_id'] ?? null,
            ];
        }
    }

    $page++;

    // Small delay to respect rate limits
    usleep(200000); // 200ms

} while ($page <= $lastPage);

// Sort countries alphabetically by name
uasort($countries, function ($a, $b) {
    return strcmp($a['name'], $b['name']);
});

// --- Output Results ---

echo "\n";
echo str_repeat('=', 70) . "\n";
echo sprintf("  TOTAL COUNTRIES FOUND: %d\n", count($countries));
echo str_repeat('=', 70) . "\n\n";

echo sprintf("%-6s | %-4s | %-30s | %-10s | %s\n", 'ID', 'Code', 'Country Name', 'Parent ID', 'GeoName ID');
echo str_repeat('-', 70) . "\n";

foreach ($countries as $country) {
    echo sprintf(
        "%-6s | %-4s | %-30s | %-10s | %s\n",
        $country['id'],
        $country['country_code'],
        $country['name'],
        $country['parent_id'] ?? 'N/A',
        $country['geoname_id'] ?? 'N/A'
    );
}

echo "\n";
echo str_repeat('=', 70) . "\n";
echo "Done.\n";
