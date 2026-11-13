<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays;

use Tygh\Addons\SphinxHolidays\Api\ResponseDecoder;
use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;

/**
 * Sphinx API facade.
 *
 * Provides a clean interface to all Sphinx API endpoints.
 * Mirrors the Novoton NovotonApi pattern but for REST/JSON.
 *
 * Response envelopes are interpreted ONCE here, via ResponseDecoder — the
 * verify endpoints return the offer payload itself and the search/poll
 * endpoints return the canonical {status, results, cursor} shape, so
 * consumers never re-derive API shapes (the drift class behind three
 * production incidents).
 */
class SphinxApi
{
    private SphinxHttpClient $client;

    private ResponseDecoder $decoder;

    public function __construct(SphinxHttpClient $client)
    {
        $this->client = $client;
        $this->decoder = new ResponseDecoder();
    }

    /**
     * Diagnostics: top-level keys + decode branch of the last decoded
     * response (feeds zero-results logging so shape drift shows in logs).
     *
     * @return array<string, mixed>
     */
    public function lastEnvelope(): array
    {
        return $this->decoder->lastEnvelope();
    }

    // ── Connectivity ──

    /**
     * Test API connectivity (no auth required).
     * @return array<string, mixed>|null
     */
    public function ping(): ?array
    {
        return $this->client->get('/api/v1/ping');
    }

    /**
     * Get authenticated user profile.
     * @return array<string, mixed>|null
     */
    public function me(): ?array
    {
        return $this->client->get('/api/v1/me');
    }

    // ── Static Data ──

    /**
     * Get all destinations (paginated).
     *
     * @param string|null $updatedSince Only return destinations updated since this ISO 8601 datetime
     * @return array<string, mixed>|null
     */
    public function getDestinations(int $page = 1, int $perPage = 1000, ?string $updatedSince = null): ?array
    {
        $query = ['page' => $page, 'per_page' => $perPage];
        if ($updatedSince !== null) {
            $query['updated_since'] = $updatedSince;
        }
        return $this->client->get('/api/v1/static/destinations', $query);
    }

    /**
     * Get a single destination by ID.
     * @return array<string, mixed>|null
     */
    public function getDestination(int $id): ?array
    {
        return $this->decoder->single($this->client->get("/api/v1/static/destinations/{$id}"));
    }

    /**
     * Get all hotels (paginated).
     *
     * @param string|null $updatedSince Only return hotels updated since this ISO 8601 datetime
     * @param int[] $destinationIds Only return hotels belonging to these destination IDs
     * @return array<string, mixed>|null
     */
    public function getHotels(int $page = 1, int $perPage = 1000, ?string $updatedSince = null, array $destinationIds = []): ?array
    {
        $query = ['page' => $page, 'per_page' => $perPage];
        if ($updatedSince !== null) {
            $query['updated_since'] = $updatedSince;
        }
        if (!empty($destinationIds)) {
            $query['destination_ids'] = $destinationIds;
        }
        return $this->client->get('/api/v1/static/hotels', $query);
    }

    /**
     * Get a single hotel by ID.
     * @return array<string, mixed>|null
     */
    public function getHotel(string $id): ?array
    {
        return $this->decoder->single($this->client->get("/api/v1/static/hotels/{$id}"));
    }

    /**
     * Get all package routes.
     * @return array<string, mixed>|null
     */
    public function getPackageRoutes(int $page = 1, int $perPage = 1000): ?array
    {
        return $this->client->get('/api/v1/static/package-routes', [
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Get all circuits (paginated).
     *
     * @param string|null $updatedSince Only return circuits updated since this ISO 8601 datetime
     * @return array<string, mixed>|null
     */
    public function getCircuits(int $page = 1, int $perPage = 1000, ?string $updatedSince = null): ?array
    {
        $query = ['page' => $page, 'per_page' => $perPage];
        if ($updatedSince !== null) {
            $query['updated_since'] = $updatedSince;
        }
        return $this->client->get('/api/v1/static/circuits', $query);
    }

    /**
     * One circuit of the static catalog, as Sphinx sends it
     * (GET /api/v1/static/circuits/:id; the circuit is under "data").
     *
     * @return array<string, mixed>|null
     */
    public function getCircuit(int $circuitId): ?array
    {
        return $this->client->get('/api/v1/static/circuits/' . $circuitId);
    }

    /**
     * Get all experiences (paginated).
     * @return array<string, mixed>|null
     */
    public function getExperiences(int $page = 1, int $perPage = 1000): ?array
    {
        return $this->client->get('/api/v1/static/experiences', [
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Get circuit rates (paginated, filterable by destination/transport/month).
     *
     * @param array<string, mixed> $params {destinatons, transport_types, months, pagination}
     * @return array<string, mixed>|null
     */
    public function getCircuitRates(array $params): ?array
    {
        return $this->decoder->search($this->client->post('/api/v1/circuits/rates', $params));
    }

    /**
     * Get a priced quote for a specific circuit departure.
     *
     * @param array<string, mixed> $params {circuit_id, departure_date, occupancy, departure_id?}
     * @return list<array<string, mixed>> The decoded list of quotes
     */
    public function getCircuitQuote(array $params): array
    {
        return $this->decoder->list($this->client->post('/api/v1/circuits/quote', $params));
    }

    /**
     * The quotes for a circuit departure, or null when Sphinx gave no answer:
     * a failure, which getCircuitQuote() cannot tell from "no rooms left".
     *
     * @param array<string, mixed> $params {circuit_id, departure_date, occupancy, departure_id?}
     * @return list<array<string, mixed>>|null
     */
    public function findCircuitQuotes(array $params): ?array
    {
        $response = $this->client->post('/api/v1/circuits/quote', $params);

        return $response === null ? null : $this->decoder->list($response);
    }

    /**
     * Customize a circuit (add optional services before booking).
     *
     * @param array<string, mixed> $data {offer_id, service_codes}
     * @return array<string, mixed>|null
     */
    public function customizeCircuit(array $data): ?array
    {
        return $this->decoder->single($this->client->post('/api/v1/circuits/customize', $data));
    }

    /**
     * Book a circuit.
     *
     * @param array<string, mixed> $bookingData {offer_id, price, currency, occupancy, reference_code?}
     * @return array<string, mixed>|null
     */
    public function bookCircuit(array $bookingData): ?array
    {
        return $this->client->postOnce('/api/v1/circuits/book', $bookingData);
    }

    /**
     * Get experience rates (paginated, filterable by destination/month/date range).
     *
     * @param array<string, mixed> $params {destinatons, months, from, to, pagination}
     * @return array<string, mixed>|null
     */
    public function getExperienceRates(array $params): ?array
    {
        return $this->decoder->search($this->client->post('/api/v1/experiences/rates', $params));
    }

    /**
     * Get a priced quote for a specific experience departure.
     *
     * @param array<string, mixed> $params {experience_id, departure_date, occupancy}
     * @return list<array<string, mixed>> The decoded list of quotes
     */
    public function getExperienceQuote(array $params): array
    {
        return $this->decoder->list($this->client->post('/api/v1/experiences/quote', $params));
    }

    /**
     * Book an experience.
     *
     * @param array<string, mixed> $bookingData {offer_id, price, currency, occupancy, reference_code?}
     * @return array<string, mixed>|null
     */
    public function bookExperience(array $bookingData): ?array
    {
        return $this->client->postOnce('/api/v1/experiences/book', $bookingData);
    }

    // ── Hotel Search & Booking ──

    /**
     * Initiate a hotel search.
     *
     * The API returns an opaque polling token under the `cursor` key (a JWT
     * whose payload embeds the search_id). Older API versions returned a
     * top-level `search_id`; callers should accept either.
     *
     * @param array<string, mixed> $params {destination_id, check_in, check_out, occupancy, currency, ...}
     * @return array<string, mixed>|null {cursor, ...} (legacy: {search_id, ...})
     */
    public function searchHotels(array $params): ?array
    {
        return $this->decoder->search($this->client->post('/api/v1/hotels/search', $params));
    }

    /**
     * Extract the search_id embedded in a `cursor` JWT returned by the search API.
     *
     * The cursor is a JWT whose payload (the middle, base64url-encoded segment)
     * is a JSON object carrying the search_id, e.g.
     * {"search_id":"<uuid>","cursor":123.45,"iat":...,"limit":...}.
     * Returns '' when the cursor is malformed or carries no search_id.
     */
    public static function extractSearchIdFromCursor(string $cursor): string
    {
        if ($cursor === '') {
            return '';
        }

        $parts = explode('.', $cursor);
        if (count($parts) < 2) {
            return '';
        }

        $payload = strtr($parts[1], '-_', '+/');
        $remainder = strlen($payload) % 4;
        if ($remainder > 0) {
            $payload .= str_repeat('=', 4 - $remainder);
        }

        $json = base64_decode($payload, true);
        if ($json === false) {
            return '';
        }

        $claims = json_decode($json, true);
        if (!is_array($claims)) {
            return '';
        }

        $searchId = $claims['search_id'] ?? '';

        return is_string($searchId) ? $searchId : '';
    }

    /**
     * Get hotel search results (cursor-based polling).
     *
     * @param string $searchId Search ID from searchHotels()
     * @param string|null $cursor Cursor for pagination
     * @return array<string, mixed>|null
     */
    public function getHotelResults(string $searchId, ?string $cursor = null): ?array
    {
        $query = [];
        if ($searchId !== '') {
            $query['search_id'] = $searchId;
        }
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        return $this->decoder->search($this->client->get('/api/v1/hotels/results', $query));
    }

    /**
     * Verify a hotel offer before booking.
     *
     * @param string $offerId Offer ID from search results
     * @return array<string, mixed>|null The offer PAYLOAD (envelope already
     *                                   unwrapped by ResponseDecoder::offer)
     */
    public function verifyHotelOffer(string $offerId): ?array
    {
        return $this->decoder->offer($this->client->get('/api/v1/hotels/verify', ['offer_id' => $offerId]));
    }

    /**
     * Book a hotel offer.
     *
     * NOTE: When building cart integration, set both 'travel_booking' => true
     * and 'sphinx_booking' => true in the cart product extras. The travel_booking
     * flag enables travel_core shared hooks (rooms_data decode, display formatting).
     *
     * @param array<string, mixed> $bookingData {offer_id, guests, contact, ...}
     * @return array<string, mixed>|null
     */
    public function bookHotel(array $bookingData): ?array
    {
        return $this->client->postOnce('/api/v1/hotels/book', $bookingData);
    }

    // ── Package Search & Booking ──

    /**
     * Initiate a package search.
     *
     * @param array<string, mixed> $params {departure_id, destination_id, date, nights, occupancy, ...}
     * @return array<string, mixed>|null
     */
    public function searchPackages(array $params): ?array
    {
        return $this->decoder->search($this->client->post('/api/v1/packages/search', $params));
    }

    /**
     * Get package search results (cursor-based polling).
     * @return array<string, mixed>|null
     */
    public function getPackageResults(string $searchId, ?string $cursor = null): ?array
    {
        $query = [];
        if ($searchId !== '') {
            $query['search_id'] = $searchId;
        }
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        return $this->decoder->search($this->client->get('/api/v1/packages/results', $query));
    }

    /**
     * Verify a package offer.
     * @return array<string, mixed>|null The offer PAYLOAD (envelope already
     *                                   unwrapped by ResponseDecoder::offer)
     */
    public function verifyPackageOffer(string $offerId): ?array
    {
        return $this->decoder->offer($this->client->get('/api/v1/packages/verify', ['offer_id' => $offerId]));
    }

    /**
     * Customize a package (add optional services).
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function customizePackage(array $data): ?array
    {
        return $this->decoder->single($this->client->post('/api/v1/packages/customize', $data));
    }

    /**
     * Book a package.
     * @param array<string, mixed> $bookingData
     * @return array<string, mixed>|null
     */
    public function bookPackage(array $bookingData): ?array
    {
        return $this->client->postOnce('/api/v1/packages/book', $bookingData);
    }

    // ── Orders ──

    /**
     * Get all orders (paginated).
     *
     * @param array<string, mixed> $filters Optional filters (e.g. ['reference_code' => '123'])
     * @return array<string, mixed>|null
     */
    public function getOrders(int $page = 1, int $perPage = 50, array $filters = []): ?array
    {
        return $this->decoder->search($this->client->get('/api/v1/orders', array_merge([
            'page' => $page,
            'per_page' => $perPage,
        ], $filters)));
    }

    /**
     * Get a single order by ID.
     * @return array<string, mixed>|null
     */
    public function getOrder(string $orderId): ?array
    {
        return $this->decoder->single($this->client->get("/api/v1/orders/{$orderId}"));
    }

    // ── Cache ──

    /**
     * Pre-cache hotel data.
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function cacheHotels(array $params): ?array
    {
        return $this->decoder->search($this->client->post('/api/v1/cache/hotels', $params));
    }

    /**
     * Pre-cache package data.
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function cachePackages(array $params): ?array
    {
        return $this->decoder->search($this->client->post('/api/v1/cache/packages', $params));
    }

    /**
     * Get the underlying HTTP client (for diagnostics).
     */
    public function getHttpClient(): SphinxHttpClient
    {
        return $this->client;
    }

    /** Why the last call answered null: the client's error, '' when none. */
    public function lastError(): string
    {
        return $this->client->getLastError();
    }

    /**
     * Whether the last call, which answered null, may still have been
     * processed (an HTTP 5xx, a timeout): a booking may then exist at Sphinx.
     */
    public function lastMaybeProcessed(): bool
    {
        return $this->client->maybeProcessed();
    }
}
