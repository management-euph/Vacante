<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

/**
 * Parses package information out of a Novoton hotel-info XML response.
 *
 * The hotelinfo endpoint returns a <hotelinfo> document whose packages can
 * appear either as repeated <packages> siblings or as nested <Package>
 * elements. This pure helper centralises that shape knowledge — extracting the
 * deduplicated package list, its count, and the primary package name — so the
 * sync code (BatchedHotelInfoSyncV2) stays focused on persistence.
 *
 * Extracted from BatchedHotelInfoSyncV2; behaviour is preserved verbatim.
 */
class HotelPackageExtractor
{
    /**
     * Extract packages from a SimpleXMLElement hotel info response.
     *
     * Handles both multiple <packages> siblings and nested <Package>
     * elements; dedupes by IdCont.
     *
     * @return array<int, array{IdCont: string, PackageName: string}>
     */
    public function extractPackages(\SimpleXMLElement $hotelInfo): array
    {
        $packages = [];
        $seenIds = [];

        if (isset($hotelInfo->packages)) {
            foreach ($hotelInfo->packages as $pkg) {
                $idCont = (string) ($pkg->IdCont ?? '');
                if ($idCont !== '' && !isset($seenIds[$idCont])) {
                    $packages[] = [
                        'IdCont' => $idCont,
                        'PackageName' => (string) ($pkg->PackageName ?? $pkg->Package ?? ''),
                    ];
                    $seenIds[$idCont] = true;
                }

                // Also check for nested <Package> elements within each <packages>
                if (isset($pkg->Package)) {
                    foreach ($pkg->Package as $nestedPkg) {
                        $nestedIdCont = (string) ($nestedPkg->IdCont ?? '');
                        if ($nestedIdCont !== '' && !isset($seenIds[$nestedIdCont])) {
                            $packages[] = [
                                'IdCont' => $nestedIdCont,
                                'PackageName' => (string) ($nestedPkg->PackageName ?? ''),
                            ];
                            $seenIds[$nestedIdCont] = true;
                        }
                    }
                }
            }
        }

        return $packages;
    }

    /**
     * Count the deduplicated packages in a hotel info response.
     */
    public function countPackages(\SimpleXMLElement $hotelInfo): int
    {
        return count($this->extractPackages($hotelInfo));
    }

    /**
     * Extract the primary package name from a hotel info response.
     *
     * Prefers the direct <packages><PackageName> (or <Package>) value, falling
     * back to the first <PackageName> found anywhere via XPath. Returns '' when
     * no package name is present.
     */
    public function extractPackageName(\SimpleXMLElement $hotelInfo): string
    {
        $packageName = '';
        if (isset($hotelInfo->packages->PackageName)) {
            $packageName = (string) $hotelInfo->packages->PackageName;
        } elseif (isset($hotelInfo->packages->Package)) {
            $packageName = (string) $hotelInfo->packages->Package;
        }
        if ($packageName === '') {
            $pn = $hotelInfo->xpath('//PackageName');
            if (!empty($pn)) {
                $packageName = (string) $pn[0];
            }
        }

        return $packageName;
    }

    /**
     * The package ids (IdCont) a stored hotelinfo lists, from the JSON of its
     * "packages" (JSON_EXTRACT(hotel_data, '$.packages')): one object or a
     * list, nested <Package> elements included. NULL when that is not known —
     * no stored hotelinfo, or one without packages (a refusal is stored that
     * way too) — so a caller never drops a package on missing evidence.
     *
     * @return list<string>|null
     */
    public static function listedIdsFromJson(?string $packagesJson): ?array
    {
        if ($packagesJson === null || $packagesJson === '') {
            return null;
        }

        $ids = [];
        $collect = static function (mixed $node) use (&$collect, &$ids): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if ($key === 'IdCont' && is_scalar($value) && (string) $value !== '') {
                    $ids[(string) $value] = true;
                } else {
                    $collect($value);
                }
            }
        };
        $collect(json_decode($packagesJson, true));

        // Numeric ids became integer keys: back to the strings package_id holds.
        return $ids === [] ? null : array_map('strval', array_keys($ids));
    }
}
