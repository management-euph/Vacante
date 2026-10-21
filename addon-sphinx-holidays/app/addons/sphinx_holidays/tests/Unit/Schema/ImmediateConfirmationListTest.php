<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the "Hotels with immediate confirmation" switch end-to-end.
 *
 * The confirmation field exists only on live search offers
 * (/api/v1/hotels/results), never in the static hotel catalog the sync
 * imports — so "only immediate hotels" is implemented as: the sync's
 * availability gate probes searches. A hotel with no confirmation=immediate
 * offer and no product waits (product_skip_reason no_availability) and never
 * becomes a product meanwhile; a linked one has its CS-Cart product set to
 * Hidden (gate_hidden). Both come back when an immediate offer appears. The
 * admin grid leaves the waiting hotels out by default and shows them
 * through its Availability filter. One setting drives all of it, default ON.
 */
final class ImmediateConfirmationListTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testSettingIsACheckboxDefaultOnWithTheRequestedLabel(): void
    {
        $xml = self::src('addon.xml');

        $pos = strpos($xml, '<item id="require_immediate_availability">');
        self::assertIsInt($pos, 'setting must stay declared in addon.xml');
        $item = substr($xml, $pos, 220);
        self::assertStringContainsString('<type>checkbox</type>', $item);
        self::assertStringContainsString('<default_value>Y</default_value>', $item, 'default is checked');

        self::assertStringContainsString('<![CDATA[Hotels with immediate confirmation]]>', $xml);
        self::assertStringContainsString('<![CDATA[Hoteluri cu confirmare imediată]]>', $xml);
    }

    public function testGridLeavesOutWaitingHotelsUnlessTheAvailabilityFilterAsks(): void
    {
        // Default view = what the grid showed when waiting hotels were
        // deleted; the Availability filter (waiting / all) brings them back.
        self::assertStringContainsString(
            'HotelAdminListingRepository($perPage > 0 ? $perPage : 50)',
            self::src('func.php'),
        );

        $repo = self::src('src/Repository/HotelAdminListingRepository.php');
        self::assertStringContainsString("'availability' => ''", $repo);
        self::assertStringContainsString(
            '(h.product_id IS NULL OR h.product_id = 0) AND h.product_skip_reason <=> ?s',
            $repo,
        );
        self::assertStringNotContainsString('onlyImmediateConfirmation', $repo);
        self::assertStringNotContainsString('hidden_no_availability', $repo);

        $tpl = (string) file_get_contents(
            dirname(__DIR__, 6)
            . '/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/hotels.tpl',
        );
        self::assertStringContainsString('<select name="availability">', $tpl);
        self::assertStringContainsString('<option value="waiting"', $tpl);
        self::assertStringContainsString('<option value="all"', $tpl);
        // Sorting a filtered grid keeps the filter, and the dashboard counts
        // the waiting hotels apart from "Skipped" and links to them.
        self::assertStringContainsString("'availability' => \$search['availability'],", self::src('controllers/backend/sphinx_holidays.php'));
        $manage = (string) file_get_contents(
            dirname(__DIR__, 6) . '/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/manage.tpl',
        );
        self::assertStringContainsString('{"sphinx_holidays.hotels?availability=waiting"|fn_url}', $manage);

        // …and the sync gate reads the ONE setting, so a single checkbox
        // governs list + product catalog + storefront search.
        self::assertStringContainsString(
            'ConfigProvider::shouldRequireImmediateAvailability()',
            self::src('src/Services/HotelSyncService.php'),
        );
    }

    public function testGateKeepsUnlinkedHotelsWaitingAndHidesLinkedProducts(): void
    {
        // Third iteration (2026-10-21): an unbookable UNLINKED hotel is kept,
        // flagged no_availability, instead of deleted. Incremental hotel
        // syncs never re-import a deleted row, so a seasonal hotel deleted
        // out of season could never become a product again. A LINKED hotel
        // keeps row + product as before — the product is set to Hidden
        // (reachable by direct link, absent from listings and searches) and
        // reactivated when immediate availability returns. Deleting a
        // seasonal hotel's product would burn its URL and SEO.
        $gate = self::src('src/Services/HotelAvailabilityGate.php');
        self::assertStringContainsString('markSkippedBatch($toWait, HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY)', $gate);
        self::assertStringNotContainsString('deleteUnlinkedBatch', $gate);
        self::assertStringContainsString('hideProductsBatch($toHide)', $gate);
        self::assertStringContainsString('showProductsBatch($toShow)', $gate);
        self::assertStringContainsString('releaseProductsBatch($toRelease)', $gate);
        // The delete-the-product experiment is over.
        self::assertStringNotContainsString('fn_delete_product', $gate);

        // Both status flips are guarded so an admin's manual Hidden/Disabled
        // choice is never overridden in either direction.
        $repo = self::src('src/Repository/HotelSkipRepository.php');
        self::assertStringNotContainsString('DELETE FROM ?:sphinx_hotels', $repo);
        self::assertStringContainsString("SET status = 'H' WHERE product_id IN (?n) AND status = 'A'", $repo);
        self::assertStringContainsString("SET status = 'A' WHERE product_id IN (?n) AND status = 'H'", $repo);

        // Candidates include LINKED hotels — the old unlinked-only predicate
        // is exactly what kept unbookable hotels visible in CS-Cart searches.
        self::assertStringContainsString(
            'SELECT hotel_id, destination_id, product_id, product_skip_reason',
            $repo,
        );

        // The setting tooltip documents the wait and hide/restore behaviour.
        $xml = self::src('addon.xml');
        self::assertStringNotContainsString('are never deleted', $xml);
        self::assertStringNotContainsString('deletes hotels without such an offer', $xml);
        self::assertStringContainsString('is kept, marked as waiting', $xml);
        self::assertStringContainsString('set to Hidden', $xml);
        self::assertStringContainsString('reactivated when immediate availability returns', $xml);
        self::assertStringContainsString('If you activate such a product by hand, it stays active', $xml);
    }

    public function testTheGateKeepsItsProductFlagsInItsOwnColumns(): void
    {
        // In product_skip_reason the mark was cleared by "Retry skipped" and
        // by the hotel upsert, and the product stayed Hidden for good.
        $xml = self::src('addon.xml');
        $migrator = self::src('src/Install/SchemaMigrator.php');
        foreach (['gate_hidden', 'gate_released'] as $column) {
            self::assertMatchesRegularExpression("/`{$column}` +ENUM\\('Y','N'\\) NOT NULL DEFAULT 'N' COMMENT/", $xml, "{$column} in the base CREATE");
            self::assertStringContainsString("ADD COLUMN `{$column}` ENUM('Y','N') NOT NULL DEFAULT 'N'", $xml, "{$column} upgrade item");
            self::assertStringContainsString("'{$column}' => \"ADD COLUMN `{$column}`", $migrator, "{$column} in SchemaMigrator");
        }

        // "Retry skipped" and the rename-driven reset leave the gate's
        // waiting hotels alone.
        self::assertStringContainsString(
            "db_quote('AND product_skip_reason <> ?s', self::SKIP_REASON_NO_AVAILABILITY)",
            self::src('src/Repository/HotelSkipRepository.php'),
        );
        self::assertStringContainsString(
            "AND NOT (?:sphinx_hotels.product_skip_reason <=> 'no_availability')",
            self::src('src/Repository/HotelRepository.php'),
        );

        // Unlinking (product deleted) drops the product's flags.
        self::assertStringContainsString(
            "h.gate_hidden = 'N', h.gate_released = 'N'",
            self::src('src/Cron/Commands/CleanupCommand.php'),
        );
    }

    public function testHotelsGridHasNoHiddenRowsNotice(): void
    {
        // The old notice ("[count] hotel(s) hidden…") is gone, and with it the
        // raw "_sphinx_holidays.hotels_hidden_no_availability" label that
        // stores with an unseeded lang table rendered above the grid. The
        // Availability filter replaced it.
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 6)
            . '/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/hotels.tpl',
        );
        self::assertStringNotContainsString('hidden_no_availability', $tpl);

        self::assertStringNotContainsString('hotels_hidden_no_availability', self::src('lang_keys.php'));
        foreach (['en', 'ro'] as $lang) {
            $po = (string) file_get_contents(
                dirname(__DIR__, 6) . '/var/langs/' . $lang . '/addons/sphinx_holidays.po',
            );
            self::assertStringNotContainsString('hotels_hidden_no_availability', $po);
        }
    }
}
