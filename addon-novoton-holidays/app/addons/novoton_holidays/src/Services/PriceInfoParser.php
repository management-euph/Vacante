<?php

declare(strict_types=1);

/**
 * Novoton PriceInfo Parser
 *
 * Loads and parses raw price data: database loading, index building,
 * occupancy structure, room capacity, season mapping, and age band resolution.
 *
 * Extracted from PriceInfoCalculation to support single-responsibility.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelPackageRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepositoryInterface;

class PriceInfoParser
{
    /** @var array<string, mixed>|null */
    private ?array $priceinfo;

    /** @var array<string, mixed>|null */
    private ?array $hotelinfo;

    /** @var array<string, mixed> */
    private array $codeIndex = [];

    /** @var list<array<string, mixed>> */
    private array $childAgeBands = [];

    private ?\Closure $logger;

    private HotelPackageRepositoryInterface $packageRepo;

    private HotelRepositoryInterface $hotelRepo;

    private readonly AgeBandResolver $ageBandResolver;

    private readonly OccupancyStructureBuilder $occupancyStructureBuilder;

    /**
     * What this parser already read, by hotel and package / by hotel: one
     * calculation per room of a hotel page (NightShares) reads each once.
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $loadedPriceinfo = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $loadedHotelinfo = [];

    public function __construct(
        ?callable $logger = null,
        ?HotelPackageRepositoryInterface $packageRepo = null,
        ?HotelRepositoryInterface $hotelRepo = null,
    ) {
        $this->logger = $logger !== null ? $logger(...) : null;
        $this->packageRepo = $packageRepo ?? new HotelPackageRepository();
        $this->hotelRepo = $hotelRepo ?? new HotelRepository();
        $this->ageBandResolver = new AgeBandResolver();
        $this->occupancyStructureBuilder = new OccupancyStructureBuilder($this->ageBandResolver, $logger);
    }

    // -- Getters for parsed data ------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public function getPriceinfo(): ?array
    {
        return $this->priceinfo;
    }
    /**
     * @return array<string, mixed>|null
     */
    public function getHotelinfo(): ?array
    {
        return $this->hotelinfo;
    }
    /**
     * @return array<string, mixed>
     */
    public function getCodeIndex(): array
    {
        return $this->codeIndex;
    }
    /**
     * @return list<array<string, mixed>>
     */
    public function getChildAgeBands(): array
    {
        return $this->childAgeBands;
    }

    /**
     * Set priceinfo directly (used by debug tools that bypass loadPriceInfo)
     * @param array<string, mixed> $priceinfo
     */
    public function setPriceinfo(array $priceinfo): void
    {
        $this->priceinfo = $priceinfo;
    }

    /**
     * Load priceinfo data from database
     * @return array<string, mixed>|null
     */
    public function loadPriceInfo(string $hotelId, string $packageName): ?array
    {
        $key = $hotelId . "\0" . $packageName;
        if (array_key_exists($key, $this->loadedPriceinfo)) {
            $this->priceinfo = $this->loadedPriceinfo[$key];

            return $this->priceinfo;
        }
        $row = $this->packageRepo->findByHotelAndPackageName($hotelId, $packageName);
        $json = $row['priceinfo_data'] ?? null;

        if (empty($json)) {
            $this->loadedPriceinfo[$key] = null;
            return null;
        }

        $json = PriceInfoFormatter::toScalar($json);
        $decoded = json_decode($json, true);
        /** @var array<string, mixed>|null $decoded */
        $this->priceinfo = is_array($decoded) ? $decoded : null;
        $this->loadedPriceinfo[$key] = $this->priceinfo;
        return $this->priceinfo;
    }

    /**
     * Load hotel info for room capacities
     * @return array<string, mixed>|null
     */
    public function loadHotelInfo(string $hotelId): ?array
    {
        if (!array_key_exists($hotelId, $this->loadedHotelinfo)) {
            $this->loadedHotelinfo[$hotelId] = $this->hotelRepo->findByIdAsDto($hotelId)?->rawHotelData;
        }
        $this->hotelinfo = $this->loadedHotelinfo[$hotelId];
        return $this->hotelinfo;
    }

    /**
     * Build code index for Code/Base resolution
     */
    public function buildCodeIndex(): void
    {
        $this->codeIndex = [];
        $seasonPrices = $this->priceinfo['season_price'] ?? [];
        if (!is_array($seasonPrices)) {
            return;
        }
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        foreach ($seasonPrices as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = PriceInfoFormatter::toScalar($row['Code'] ?? '');
            if ($code === '') {
                continue;
            }
            if (!isset($this->codeIndex[$code])) {
                $this->codeIndex[$code] = [];
            }
            $this->codeIndex[$code][] = $row;
        }
    }

    /**
     * Parse child age bands from hotelinfo ages data.
     */
    public function parseChildAgeBands(): void
    {
        $this->childAgeBands = $this->ageBandResolver->parseChildAgeBands($this->hotelinfo);
        $this->log('Parsed hotel child age bands', $this->childAgeBands);
    }

    /**
     * Get room capacity (RB, EB, maxADT, maxCHD, minPAX)
     * @return array<string, mixed>
     */
    public function getRoomCapacity(string $roomId): array
    {
        $default = [
            'RB' => 2,
            'EB' => 1,
            'maxADT' => 2,
            'maxCHD' => 2,
            'minPAX' => 1,
        ];

        if (empty($this->hotelinfo) || !isset($this->hotelinfo['rooms'])) {
            return $default;
        }

        $rooms = $this->hotelinfo['rooms'];
        if (!is_array($rooms)) {
            return $default;
        }
        if (isset($rooms['IdRoom'])) {
            $rooms = [$rooms];
        }

        foreach ($rooms as $room) {
            if (!is_array($room)) {
                continue;
            }
            $rid = PriceInfoFormatter::toScalar($room['IdRoom'] ?? '');
            if ($rid === $roomId || rawurldecode($rid) === $roomId) {
                return [
                    'RB' => PriceInfoFormatter::toInt($room['RegularBeds'] ?? $room['RB'] ?? 2),
                    'EB' => PriceInfoFormatter::toInt($room['ExtraBeds'] ?? $room['EB'] ?? 1),
                    'maxADT' => PriceInfoFormatter::toInt($room['maxADT'] ?? $room['MaxAdults'] ?? 2),
                    'maxCHD' => PriceInfoFormatter::toInt($room['maxCHD'] ?? $room['MaxChildren'] ?? 2),
                    'minPAX' => PriceInfoFormatter::toInt($room['minPAX'] ?? $room['MinPax'] ?? 1),
                ];
            }
        }

        return $default;
    }

    /**
     * Validate occupancy against room capacity
     * @param array<string, mixed> $capacity
     * @return array<string, mixed>
     */
    public function validateOccupancy(int $adults, int $children, array $capacity): array
    {
        $minPAX = PriceInfoFormatter::toInt($capacity['minPAX'] ?? 1);
        $maxADT = PriceInfoFormatter::toInt($capacity['maxADT'] ?? 2);
        $maxCHD = PriceInfoFormatter::toInt($capacity['maxCHD'] ?? 2);
        $rb = PriceInfoFormatter::toInt($capacity['RB'] ?? 2);
        $eb = PriceInfoFormatter::toInt($capacity['EB'] ?? 1);

        if ($adults < $minPAX) {
            return ['valid' => false, 'reason' => "Adults ({$adults}) less than minPAX ({$minPAX})"];
        }

        if ($adults > $maxADT) {
            return ['valid' => false, 'reason' => "Adults ({$adults}) exceeds maxADT ({$maxADT})"];
        }

        if ($children > $maxCHD) {
            return ['valid' => false, 'reason' => "Children ({$children}) exceeds maxCHD ({$maxCHD})"];
        }

        $totalPax = $adults + $children;
        $totalCapacity = $rb + $eb;
        if ($totalPax > $totalCapacity) {
            return ['valid' => false, 'reason' => "Total pax ({$totalPax}) exceeds capacity ({$totalCapacity})"];
        }

        return ['valid' => true];
    }

    /**
     * Build occupancy structure - who uses Regular Beds vs Extra Beds
     *
     * If a child's age band has no matching price row in this room,
     * the child is reclassified as an additional adult on EXTRA BED.
     * @param list<int> $childrenAges
     * @param array<string, mixed> $capacity
     * @return array<string, mixed>
     */
    public function buildOccupancyStructure(int $adults, array $childrenAges, array $capacity, string $roomId = '', string $boardId = ''): array
    {
        return $this->occupancyStructureBuilder->build(
            $adults,
            $childrenAges,
            $capacity,
            $this->priceinfo,
            $this->childAgeBands,
            $roomId,
            $boardId,
        );
    }

    /**
     * Get age band string for a child's age.
     */
    public function getAgeBand(float $age): string
    {
        return $this->ageBandResolver->getAgeBand($age, $this->childAgeBands);
    }

    /**
     * Get available child age bands for a specific room+board from season_price data.
     * @return list<string|null>
     */
    public function getAvailableChildAgeBands(string $roomId, string $boardId): array
    {
        return $this->ageBandResolver->getAvailableChildAgeBands($this->priceinfo, $roomId, $boardId);
    }

    /**
     * Check if a room has adult extra bed pricing (3RD+ ADULT on EXTRA BED)
     */
    public function hasAdultExtraBedPricing(string $roomId, string $boardId): bool
    {
        return $this->ageBandResolver->hasAdultExtraBedPricing($this->priceinfo, $roomId, $boardId);
    }

    /**
     * Get season number for each night of the stay
     * @return list<array<string, int|string>>
     */
    public function getSeasonsByNight(string $checkIn, int $nights): array
    {
        $seasons = $this->priceinfo['seasons'] ?? [];

        if (!is_array($seasons)) {
            $seasons = [];
        } elseif (isset($seasons['Season'])) {
            $seasons = [$seasons];
        } elseif (isset($seasons[0]) && is_array($seasons[0]) && isset($seasons[0]['Season'])) {
            // Already an array
        } elseif (isset($seasons['season'])) {
            $seasons = $seasons['season'];
            if (is_array($seasons) && isset($seasons['Season'])) {
                $seasons = [$seasons];
            }
        }

        $result = [];
        $checkInDate = new \DateTime($checkIn);

        for ($night = 0; $night < $nights; $night++) {
            $currentDate = clone $checkInDate;
            $currentDate->modify("+{$night} days");
            $dateStr = $currentDate->format('Y-m-d');

            $seasonNum = 1;
            if (is_array($seasons)) {
                foreach ($seasons as $season) {
                    if (!is_array($season)) {
                        continue;
                    }
                    $from = PriceInfoFormatter::toScalar($season['FromDate'] ?? $season['DateFrom'] ?? '');
                    $to = PriceInfoFormatter::toScalar($season['ToDate'] ?? $season['DateTo'] ?? '');
                    $id = PriceInfoFormatter::toInt($season['Season'] ?? $season['IdSeason'] ?? 1);

                    if ($dateStr >= $from && $dateStr <= $to) {
                        $seasonNum = $id;
                        break;
                    }
                }
            }

            $result[$night] = [
                'date' => $dateStr,
                'season' => $seasonNum,
            ];
        }

        return array_values($result);
    }

    private function log(string $message, mixed $data = null): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message, $data);
        }
    }
}
