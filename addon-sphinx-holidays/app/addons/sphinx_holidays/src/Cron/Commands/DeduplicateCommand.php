<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: find and merge duplicate hotels in sphinx_hotels.
 *
 * Duplicates are identified by matching (name, property_type, classification,
 * region_id, country_code). The lowest hotel_id with a linked product is kept
 * as canonical; remaining duplicates are re-linked and marked as skipped.
 * Orphaned duplicate products are deleted.
 *
 * Usage: index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=deduplicate
 *        DRY RUN BY DEFAULT — reports what it would do and changes nothing.
 *        Optional: &dry_run=0  (actually merge and DELETE the orphan products)
 *                  &limit=N    (max duplicate groups to process)
 *
 * The default used to be the destructive one: a bare `cron_mode=deduplicate`
 * deleted products via fn_delete_product() with no confirmation. That is the
 * wrong way round for the only command here that removes catalog rows —
 * especially as the grouping key includes property_type, so a sync that
 * blanks that column collapses unrelated hotels into one group.
 */
class DeduplicateCommand extends AbstractSyncCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['deduplicate'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Find and merge duplicate hotels (same name + property_type + classification + region)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $startMs = (int)(microtime(true) * 1000);

        // Opt IN to deletion, never out of it: an absent parameter reports.
        $dryRun = (bool) ($params['dry_run'] ?? true);
        $limit = TypeCoerce::toInt($params['limit'] ?? 0);

        $stats = [
            'groups_processed' => 0,
            'hotels_deduplicated' => 0,
            'products_removed' => 0,
            'products_linked' => 0,
        ];

        $repo = new HotelRepository();
        $skipRepo = new HotelSkipRepository();

        if ($dryRun) {
            $this->output('DRY RUN (the default) — nothing will be changed. Add &dry_run=0 to apply.');
        }

        $this->output('Finding duplicate hotel groups...');

        $groups = $this->findDuplicateGroups($limit);

        if (empty($groups)) {
            $this->output('No duplicate groups found.');
            $durationMs = (int)(microtime(true) * 1000) - $startMs;
            return [
                'success' => true,
                'dry_run' => $dryRun,
                'stats' => array_merge($stats, ['duration_ms' => $durationMs]),
            ];
        }

        $this->output('Found ' . count($groups) . ' duplicate group(s).');

        foreach ($groups as $group) {
            $hotelIds = explode(',', TypeCoerce::toString($group['hotel_ids'] ?? ''));
            $name = TypeCoerce::toString($group['name'] ?? '');
            $cnt = TypeCoerce::toInt($group['cnt'] ?? 0);
            $propertyType = TypeCoerce::toString($group['property_type'] ?? '');
            $classification = TypeCoerce::toString($group['classification'] ?? '');
            $regionId = TypeCoerce::toString($group['region_id'] ?? '');
            $countryCode = TypeCoerce::toString($group['country_code'] ?? '');

            $this->output("--- Group: \"{$name}\" (property_type={$propertyType}, "
                . "class={$classification}, region={$regionId}, "
                . "country={$countryCode}) — {$cnt} hotels: " . implode(', ', $hotelIds));

            // Load full hotel rows to check product_id
            $hotels = [];
            foreach ($hotelIds as $hid) {
                $row = $repo->findById(trim($hid));
                if ($row !== null) {
                    $hotels[] = $row;
                }
            }

            if (count($hotels) < 2) {
                $this->output('  Skipping — fewer than 2 hotels resolved.');
                continue;
            }

            // Pick canonical: prefer lowest hotel_id with a product_id > 0
            $canonical = null;
            foreach ($hotels as $h) {
                $pid = TypeCoerce::toInt($h['product_id'] ?? 0);
                if ($pid > 0) {
                    $canonical = $h;
                    break;
                }
            }
            if ($canonical === null) {
                $canonical = $hotels[0];
            }

            $canonicalId = TypeCoerce::toString($canonical['hotel_id'] ?? '');
            $canonicalProductId = TypeCoerce::toInt($canonical['product_id'] ?? 0);

            $this->output("  Canonical: hotel_id={$canonicalId}, product_id={$canonicalProductId}");

            foreach ($hotels as $h) {
                $dupHotelId = TypeCoerce::toString($h['hotel_id'] ?? '');
                if ($dupHotelId === $canonicalId) {
                    continue;
                }

                $dupProductId = TypeCoerce::toInt($h['product_id'] ?? 0);

                // If duplicate has a different product_id and canonical has one — delete orphan product
                if ($dupProductId > 0 && $dupProductId !== $canonicalProductId && $canonicalProductId > 0) {
                    $this->output("  [{$dupHotelId}] Removing orphan product #{$dupProductId}");
                    if (!$dryRun) {
                        if (function_exists('fn_delete_product')) {
                            fn_delete_product($dupProductId);
                        }
                    }
                    $stats['products_removed']++;
                }

                // Link duplicate to canonical's product (if canonical has one)
                if ($canonicalProductId > 0) {
                    $this->output("  [{$dupHotelId}] Linking to canonical product #{$canonicalProductId}");
                    if (!$dryRun) {
                        $repo->linkToProduct($dupHotelId, $canonicalProductId);
                    }
                    $stats['products_linked']++;
                }

                // Mark as duplicate
                if (!$dryRun) {
                    $skipRepo->markSkipped($dupHotelId, 'duplicate');
                }

                $stats['hotels_deduplicated']++;
            }

            $stats['groups_processed']++;
        }

        $durationMs = (int)(microtime(true) * 1000) - $startMs;
        $stats['duration_ms'] = $durationMs;

        $this->output("Done: {$stats['groups_processed']} groups, "
            . "{$stats['hotels_deduplicated']} hotels deduplicated, "
            . "{$stats['products_removed']} products removed, "
            . "{$stats['products_linked']} products linked ("
            . round($durationMs / 1000, 1) . 's)'
            . ($dryRun ? ' [DRY RUN]' : ''));

        return [
            'success' => true,
            'dry_run' => $dryRun,
            'stats' => $stats,
        ];
    }

    /**
     * Find groups of duplicate hotels sharing (name, property_type, classification, region_id, country_code).
     *
     * @param int $limit Max groups to return (0 = unlimited)
     * @return list<array<string, mixed>> Each row has: name, property_type, classification, region_id, country_code, cnt, hotel_ids
     */
    private function findDuplicateGroups(int $limit): array
    {
        $limitClause = $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '';

        return TypeCoerce::toRowList(db_get_array(
            "SELECT name, property_type, classification, region_id, country_code,
                    COUNT(*) as cnt, GROUP_CONCAT(hotel_id ORDER BY hotel_id) as hotel_ids
             FROM ?:sphinx_hotels
             WHERE sync_status = 'active' AND name != ''
             GROUP BY name, property_type, classification, region_id, country_code
             HAVING cnt > 1
             ORDER BY cnt DESC ?p",
            $limitClause,
        ));
    }
}
