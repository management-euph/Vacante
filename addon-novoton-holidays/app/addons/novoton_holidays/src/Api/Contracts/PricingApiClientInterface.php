<?php

declare(strict_types=1);

/**
 * Contract for the Novoton Pricing API sub-client.
 *
 * Covers room_price, priceinfo and spo (special offers) endpoints, plus the
 * commission application helper.
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Api\Contracts;

interface PricingApiClientInterface
{
    /** Apply the configured commission to a raw API price. */
    public function applyCommission(float $price): float;

    /**
     * Build the room_price XML request body (extracted so it can be reused by batch calls).
     * @param array<string, mixed> $params
     */
    public function buildRoomPriceXml(array $params): string;

    /**
     * Batch room_price requests using curl_multi.
     *
     * @param array<string, array<string, mixed>> $requestParams Keyed array: key => room_price params
     * @return array<string, array{data: \SimpleXMLElement|false, rawXml: string}>
     */
    public function getRoomPriceBatch(array $requestParams, int $concurrency = 5): array;

    /**
     * 3. room_price — Accommodation prices (real-time rates).
     * @param array<string, mixed> $params
     */
    public function getRoomPrice(array $params): \SimpleXMLElement|false;

    /**
     * Get room prices for an entire resort (parsed).
     * @param array<string, mixed> $params
     */
    public function getRoomPriceByResort(array $params): \SimpleXMLElement|false;

    /**
     * Get room prices for an entire resort (raw XML, no parsing).
     * @param array<string, mixed> $params
     */
    public function getRoomPriceByResortRaw(array $params): string;

    /** 13. priceinfo — Season prices request. A refusal (Api\Refusal) throws ApiException. */
    public function getPriceInfo(string $hotelId, string $packageName, string $lang = 'UK'): \SimpleXMLElement;

    /** 10. spo — EB (early booking), extras and other discounts. */
    public function getSpecialOffers(string $hotelId, string $packageName = '', string $lang = 'UK'): \SimpleXMLElement;
}
