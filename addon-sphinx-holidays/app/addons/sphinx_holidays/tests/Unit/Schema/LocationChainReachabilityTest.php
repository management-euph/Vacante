<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Cron\CronPlan;

/**
 * Pins the City/Region chain end to end: resolve → store → PRODUCT FEATURE,
 * and the admin's ability to run and verify it.
 *
 * Three gaps this locks shut, all found by auditing the shipped ladder:
 *
 *  1. The repair jobs wrote the hotel row but never flagged the product, so a
 *     corrected city never reached the Oraș/Regiune features. No new cron was
 *     added — `update_products` already re-assigns features for hotels
 *     flagged `product_needs_update='Y'`, and `upsertBatch` already flags that
 *     same event during sync; the two direct-UPDATE commands were simply
 *     bypassing it.
 *  2. The repair chain had no Run buttons: `geocode_hotels` was absent from
 *     `$cron_urls`, and `reassign_features` / `backfill_hotel_locations` had
 *     URLs but no dashboard rows. Production remedies must be reachable from
 *     the addon: each is now a row of the dashboard's scheduled jobs
 *     (Cron\CronPlan), with Run.
 *  3. The grid's City column showed the RAW API address city — precisely the
 *     value the ladder demotes — so the fix could not be verified there.
 */
final class LocationChainReachabilityTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    private static function design(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 6) . '/design/' . $rel);
    }

    public function testBothLocationRepairsFlagTheProductForFeatureRefresh(): void
    {
        foreach ([
            'src/Cron/Commands/BackfillHotelLocationsCommand.php',
            'src/Cron/Commands/GeocodeHotelsCommand.php',
        ] as $rel) {
            $src = self::src($rel);
            self::assertStringContainsString(
                "isset(\$set['destination_name']) || isset(\$set['region_name'])",
                $src,
                "{$rel} must notice a location change",
            );
            self::assertStringContainsString(
                "\$set['product_needs_update'] = 'Y';",
                $src,
                "{$rel} must flag the linked product so update_products re-assigns features",
            );
            // Only linked products — flagging an unlinked hotel is noise.
            self::assertStringContainsString("\$row['product_id'] ?? 0) > 0", $src, $rel);
            // The row must actually carry product_id for that guard to work.
            self::assertStringContainsString('product_id', $src, $rel);
        }
    }

    public function testFeatureReassignmentStaysInUpdateProductsRatherThanBeingDuplicated(): void
    {
        // Deliberate architecture: the repair jobs never call assignAll
        // themselves (that would duplicate the update path and run heavy
        // per-product writes inside a rate-limited geocode loop).
        foreach ([
            'src/Cron/Commands/BackfillHotelLocationsCommand.php',
            'src/Cron/Commands/GeocodeHotelsCommand.php',
        ] as $rel) {
            self::assertStringNotContainsString('assignAll(', self::src($rel), $rel);
        }

        // …and the consumer of the flag still does the assignment.
        $update = self::src('src/Cron/Commands/UpdateProductsCommand.php');
        self::assertStringContainsString("product_needs_update = 'Y'", $update);
        self::assertStringContainsString('assignAll(', $update);
    }

    /**
     * Field failure: `backfill_hotel_locations` and `geocode_hotels` both died
     * with "Unknown column 'geo_city'" / "'geocoded_at'" on a store that had
     * pulled the code but not yet opened an admin page.
     *
     * init.php only self-heals the schema when AREA === 'A'; the cron runs in
     * the storefront area, so the ALTERs never ran. The cron is a first-class
     * entry point for schema-dependent work and must apply them itself — the
     * same gap the SEO-defaults seeding already works around in this file.
     */
    public function testTheCronAppliesSchemaDeltasItselfBecauseInitIsAdminOnly(): void
    {
        $cron = self::src('controllers/frontend/sphinx_cron.php');
        self::assertStringContainsString('fn_sphinx_holidays_ensure_schema()', $cron);

        // The admin-only gate that made this necessary is still the reason —
        // if init.php ever stops being AREA-gated this pin can be revisited.
        self::assertStringContainsString(
            "AREA === 'A' && function_exists('fn_sphinx_holidays_ensure_schema')",
            self::src('init.php'),
        );

        // The columns the crons depend on must be in the migrator's delta list,
        // not only in addon.xml (whose for="upgrade" ALTERs never auto-apply).
        $migrator = self::src('src/Install/SchemaMigrator.php');
        foreach (['geo_city', 'geo_region', 'street_address', 'geocoded_at', 'location_source'] as $column) {
            self::assertStringContainsString("'{$column}' =>", $migrator, "{$column} missing from SchemaMigrator");
        }
    }

    public function testTheRepairChainHasRunButtons(): void
    {
        // Every job of the plan is a row of the dashboard's jobs card, with Run:
        // the two repairs, then update_products, which carries them onto the
        // products; reassign_features on demand.
        self::assertSame(['backfill_hotel_locations', 'geocode_hotels', 'update_products'], array_slice(CronPlan::STAGES['locations'], 0, 3));
        self::assertContains('reassign_features', CronPlan::ON_DEMAND);

        $tpl = self::design('backend/templates/addons/sphinx_holidays/views/sphinx_holidays/manage.tpl');
        self::assertStringContainsString('stages=$sphinx_job_stages', $tpl);
        self::assertStringContainsString('on_demand=$sphinx_on_demand_jobs', $tpl);

        // The geocode row runs a batch of 100, and its menu offers the
        // backlog check and the redo-all variant.
        self::assertSame(['limit' => '100'], CronPlan::jobParams('geocode_hotels'));
        self::assertSame(['status', 'force'], CronPlan::offeredActions('geocode_hotels'));
    }

    public function testGridShowsTheResolvedLocationNotTheRawAddress(): void
    {
        $tpl = self::design('backend/templates/addons/sphinx_holidays/views/sphinx_holidays/hotels.tpl');

        // City column reads the resolved value first, address only as fallback.
        self::assertStringContainsString('{if $hotel.destination_name}', $tpl);
        self::assertStringContainsString('{elseif $hotel.address_city}', $tpl);
        // Region column exists at all.
        self::assertStringContainsString('{$hotel.region_name|default:"-"|escape:html}', $tpl);
        // Provenance badge for every rung.
        foreach ([
            'sphinx_holidays.location_source_tree',
            'sphinx_holidays.location_source_address',
            'sphinx_holidays.location_source_geocode',
        ] as $key) {
            self::assertStringContainsString($key, $tpl);
        }
        // The bulk bar no longer lives in the table (it is the sticky block
        // above it, as CS-Cart builds it), so it spans no row and cannot
        // cover these columns; HotelsGridBulkBarTest pins that layout.
        self::assertStringNotContainsString('<th colspan=', $tpl);

        // Both new columns are sortable, which requires the allowlist entries.
        $repo = self::src('src/Repository/HotelAdminListingRepository.php');
        self::assertStringContainsString("'destination_name' => 'h.destination_name'", $repo);
        self::assertStringContainsString("'region_name' => 'h.region_name'", $repo);
    }
}
