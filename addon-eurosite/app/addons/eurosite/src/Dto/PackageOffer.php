<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Dto;

/**
 * One Eurosite package offer, from a getPackageNVPriceResponse <Offer>: the
 * hotel offer (room, board, period, price) and what makes it a package — the
 * PackageId it is booked with, its tour operator, and the services its price
 * is made of: the journeys (Type 7, a flight or a coach leg), the transfer
 * (6), the airport tax (7s), the package's commission (8).
 *
 * The price is HotelOffer::$price, the offer's ProductPrice: the net Eurosite
 * invoices, which is the room, the board and every service here added up. A
 * booking sends PackageId and PackageVariantId (HotelOffer::$variantId) over
 * the offer's own period (HotelOffer::$checkIn / $checkOut), which may differ
 * slightly from the period searched.
 *
 * @phpstan-import-type OfferArray from HotelOffer
 *
 * @phpstan-type PackageService array{
 *   type: string, code: string, name: string, transport: string,
 *   availability_code: string, start: string, end: string,
 *   seats: int|null, price: float
 * }
 */
final class PackageOffer
{
    /** Eurosite's service types, as strings: one of them is "7s". */
    public const string TYPE_OTHER = '6';

    public const string TYPE_TRANSPORT = '7';

    public const string TYPE_AIRPORT_TAX = '7s';

    public const string TYPE_COMMISSION = '8';

    /**
     * @param list<PackageService> $services in Eurosite's order. start/end are
     *                                       dates, or "YYYY-MM-DD HH:MM:SS" on a
     *                                       journey; seats is null when not sent
     */
    public function __construct(
        public readonly HotelOffer $hotel,
        public readonly string $packageId,
        public readonly string $tourOpCode,
        public readonly array $services = [],
        public readonly bool $acceptRemarks = false,
        public readonly bool $visible = true,
    ) {
    }

    public function variantId(): string
    {
        return $this->hotel->variantId;
    }

    /**
     * Whether a booking may be attempted: not a stop sale, and both ids the
     * booking sends are there.
     */
    public function isBookable(): bool
    {
        return $this->hotel->isBookable() && $this->packageId !== '' && $this->hotel->variantId !== '';
    }

    /**
     * The services of one type (the TYPE_* constants).
     *
     * @return list<PackageService>
     */
    public function servicesOfType(string $type): array
    {
        return array_values(array_filter(
            $this->services,
            static fn (array $service): bool => $service['type'] === $type,
        ));
    }

    /**
     * The journeys, earliest first: the way there, then the way back.
     *
     * @return list<PackageService>
     */
    public function legs(): array
    {
        $legs = $this->servicesOfType(self::TYPE_TRANSPORT);
        usort($legs, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        return $legs;
    }

    /**
     * The way there: the first journey.
     *
     * @return PackageService|null
     */
    public function outbound(): ?array
    {
        return $this->legs()[0] ?? null;
    }

    /**
     * The way back: the last journey, when there are two or more.
     *
     * @return PackageService|null
     */
    public function inbound(): ?array
    {
        $legs = $this->legs();

        return count($legs) > 1 ? $legs[count($legs) - 1] : null;
    }

    /**
     * @return array{hotel: OfferArray, package_id: string, variant_id: string, tourop_code: string, accept_remarks: bool, visible: bool, services: list<PackageService>}
     */
    public function toArray(): array
    {
        return [
            'hotel' => $this->hotel->toArray(),
            'package_id' => $this->packageId,
            'variant_id' => $this->hotel->variantId,
            'tourop_code' => $this->tourOpCode,
            'accept_remarks' => $this->acceptRemarks,
            'visible' => $this->visible,
            'services' => $this->services,
        ];
    }
}
