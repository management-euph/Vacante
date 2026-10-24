<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services\Geocoding;

use Tygh\Addons\TravelCore\Exceptions\GeocodeTransportException;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Minimal OSM Nominatim reverse-geocoding client, shared by every provider
 * addon.
 *
 * Turns stored hotel coordinates into an approximate street line, a city and
 * a region — the last rung of the providers' location ladders, and the only
 * source of a street for APIs that carry none. Uses the /reverse endpoint
 * (jsonv2).
 *
 * Callers MUST pace requests to max 1/second and identify themselves
 * (User-Agent with contact) per the usage policy:
 * https://operations.osmfoundation.org/policies/nominatim/ — that limit is
 * per APPLICATION, not per addon, which is exactly why this client lives in
 * travel_core: two addons pacing themselves independently would double the
 * real request rate. GeocodeBacklogRunner owns the pacing for both.
 *
 * Results are ODbL-licensed: storing them is permitted with OpenStreetMap
 * attribution ("© OpenStreetMap contributors" somewhere on the site).
 */
final class NominatimClient
{
    public const string DEFAULT_ENDPOINT = 'https://nominatim.openstreetmap.org';

    private const int TIMEOUT_SECONDS = 15;

    /** Building level: the hotel's own address when it is mapped. */
    public const int ZOOM_BUILDING = 18;

    /** Street level: snaps to the nearest named way. */
    public const int ZOOM_STREET = 17;

    /**
     * HTTP GET transport, injectable for tests.
     * Receives (url, userAgent), returns [httpCode, body]; throws
     * GeocodeTransportException on connection-level failure.
     *
     * @var callable(string, string): array{int, string}
     */
    private $transport;

    private string $endpoint;
    private string $userAgent;

    /**
     * @param callable(string, string): array{int, string}|null $transport
     * @param string $appName Application token for the User-Agent; the policy
     *                        wants the app identified, not the addon.
     */
    public function __construct(
        ?callable $transport = null,
        string $endpoint = '',
        string $contactEmail = '',
        string $appName = 'TravelCore/1.0',
    ) {
        $this->transport = $transport ?? static fn (string $url, string $userAgent): array => self::curlGet($url, $userAgent);
        $this->endpoint = rtrim($endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT, '/');
        // Nominatim policy: identify the application and provide a contact.
        $this->userAgent = $appName . ($contactEmail !== '' ? " ({$contactEmail})" : '');
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * Reverse-geocode coordinates into street + city + region.
     *
     * @param int $zoom ZOOM_BUILDING, or ZOOM_STREET for the nearest named way
     *                  when the building level names none (a hotel drawn as a
     *                  resort area, a beach or a park has no road of its own)
     *
     * @throws GeocodeTransportException on HTTP/transport failure — the
     *                                   caller should stop its run
     */
    public function reverseDetailed(float $lat, float $lng, int $zoom = self::ZOOM_BUILDING): ReverseGeocodeResult
    {
        // accept-language=ro,en: the Romanian name where OSM has one, else the
        // English one ("Kiten" rather than "Китен"); only then the local name,
        // which ReverseGeocodeResult writes in Latin letters.
        $url = $this->endpoint
            . '/reverse?format=jsonv2&zoom=' . $zoom . '&addressdetails=1'
            . '&lat=' . rawurlencode(number_format($lat, 7, '.', ''))
            . '&lon=' . rawurlencode(number_format($lng, 7, '.', ''))
            . '&accept-language=ro,en';

        [$httpCode, $body] = ($this->transport)($url, $this->userAgent);

        if ($httpCode !== 200) {
            throw new GeocodeTransportException("Nominatim reverse geocoding failed with HTTP {$httpCode}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new GeocodeTransportException('Nominatim returned an unparseable body');
        }

        $address = isset($decoded['address']) && is_array($decoded['address']) ? $decoded['address'] : [];

        return ReverseGeocodeResult::fromNominatimAddress($address);
    }

    /**
     * Street line only, or null when the nearest mapped address has nothing
     * street-like — the original novoton contract, kept so its geocode cron
     * and tests are unaffected by the move.
     *
     * @throws GeocodeTransportException
     */
    public function reverse(float $lat, float $lng): ?string
    {
        $street = $this->reverseDetailed($lat, $lng)->street;

        return $street !== '' ? $street : null;
    }

    /**
     * @return array{int, string}
     */
    private static function curlGet(string $url, string $userAgent): array
    {
        if ($url === '') {
            throw new GeocodeTransportException('Empty geocoding URL');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $userAgent,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = TypeCoerce::toInt(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new GeocodeTransportException('Nominatim request failed: ' . $error);
        }

        return [$httpCode, is_string($response) ? $response : ''];
    }
}
