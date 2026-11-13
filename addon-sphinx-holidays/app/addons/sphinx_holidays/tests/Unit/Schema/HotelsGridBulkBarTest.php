<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the admin Hotels grid's adoption of CS-Cart's NATIVE bulk-action
 * pattern, as views/products/manage.tpl + common/context_menu_wrapper.tpl
 * build it: the contextual bar is a sticky block ABOVE the table, the real
 * header collapses behind it, and the counter comes from
 * common/check_items.tpl.
 *
 * CS-Cart core is not vendored in this repo — the microformats resolve
 * against the deployed store's js/tygh/backend/bulkedit.js + tap/longtap
 * driver. This scan is therefore the CI-checkable proxy: it fails if the
 * required markers are removed, if the bar is nested back INSIDE the table
 * (where .bulk-edit's position:sticky painted it over the rows), or if a
 * hand-rolled bar creeps back; and it pins the POST contract the three
 * unchanged backend handlers rely on.
 */
final class HotelsGridBulkBarTest extends TestCase
{
    private static function template(): string
    {
        $path = dirname(__DIR__, 6)
            . '/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/hotels.tpl';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testNativeBulkBarMarkersPresent(): void
    {
        $tpl = self::template();

        foreach ([
            '{script src="js/tygh/backend/bulkedit.js"}',
            'data-ca-longtap="true"',
            // The core's own component values, not a home-made "true".
            'data-ca-bulkedit-component="expandedObject"',
            'data-ca-bulkedit-component="defaultObject"',
            'data-ca-bulkedit-default-object="true"',
            'data-ca-bulkedit-expanded-object="true"',
            'class="bulk-edit clearfix hidden"',
            'class="btn-group bulk-edit__wrapper"',
            // The grid keeps its own table classes: the core's
            // table-responsive turns rows into blocks and
            // table--show-checkbox restyles a checkbox column this grid
            // does not build — together they threw the rows out of line.
            'class="table table-middle table-striped sphinx-hotels-table"',
            // The toggler is hidden and clicked by the longtap driver.
            'class="bulkedit-toggler hide"',
            'bulkedit-disabler',
            'data-ca-longtap-selected-counter="true"',
            // Check all / check none in the bar come from the core's include.
            'common/check_items.tpl',
            // The header keeps a plain check-all, scoped to this grid.
            'data-ca-container="#sphinx_hotels_cm"',
            'data-ca-longtap-action="setCheckBox"',
            'data-ca-longtap-target="input.cm-item"',
            'class="cm-item"',
        ] as $marker) {
            self::assertStringContainsString($marker, $tpl, "missing native bulk-bar marker: {$marker}");
        }
    }

    /**
     * The bug this layout fixes: .bulk-edit is position:sticky in the core's
     * css/tygh/utils/bulk_edit.less, so nested in a <thead> it floated over
     * the rows it belongs to. It must sit before the table, inside the
     * longtap container, as the core's context_menu_wrapper puts it.
     */
    public function testTheBarSitsAboveTheTableNotInsideIt(): void
    {
        $tpl = self::template();

        $bar = strpos($tpl, 'class="bulk-edit clearfix hidden"');
        $table = strpos($tpl, '<table');
        self::assertIsInt($bar);
        self::assertIsInt($table);
        self::assertLessThan($table, $bar, 'the bulk bar must be rendered before the table opens');

        // One table, one header: the second header that used to hold the bar is gone.
        self::assertSame(1, substr_count($tpl, '</thead>'), 'the expanded header bar is gone');
        self::assertSame(1, substr_count($tpl, '</table>'));
        self::assertStringNotContainsString('<thead class="hidden"', $tpl);
        // The header collapses rather than hiding, so the columns keep their widths.
        self::assertStringContainsString('data-target=".sphinx-hotels-table"', $tpl);
        // None of the core's own table layout classes: this grid does not
        // build the markup they expect.
        foreach (['table-responsive', 'table--show-checkbox', 'table--relative', 'table__check-items'] as $coreClass) {
            self::assertStringNotContainsString($coreClass, $tpl, "{$coreClass} reshapes rows this grid does not build");
        }
    }

    public function testActionsPostToTheUnchangedHandlers(): void
    {
        $tpl = self::template();

        // The bar submits the surrounding form — hotel_ids[] checkboxes plus
        // these dispatch[] buttons ARE the handlers' existing POST contract.
        self::assertStringContainsString('name="hotel_ids[]"', $tpl);
        self::assertStringContainsString('name="security_hash"', $tpl);
        self::assertStringContainsString('name="bulk_status"', $tpl);
        self::assertSame(
            2,
            substr_count($tpl, 'data-ca-dispatch="dispatch[sphinx_holidays.bulk_update_hotels]"'),
            'Activate + Deactivate both post to bulk_update_hotels (bulk_status disambiguates)',
        );
        self::assertStringContainsString('data-ca-dispatch="dispatch[sphinx_holidays.bulk_sync_images]"', $tpl);
        self::assertStringContainsString('data-ca-dispatch="dispatch[sphinx_holidays.bulk_delete_hotels]"', $tpl);
        // The status still rides in the hidden field the handler reads, set
        // by the item's own click before the core's submit handler runs.
        self::assertSame(2, substr_count($tpl, "getElementById('sphinx_bulk_action').value="));
        self::assertStringContainsString('cm-confirm', $tpl, 'delete keeps a confirmation step');
    }

    public function testOldHandRolledBarIsGone(): void
    {
        $tpl = self::template();

        self::assertStringNotContainsString('toggleAllHotels', $tpl);
        self::assertStringNotContainsString('cm-item-hotel', $tpl);
        self::assertStringNotContainsString('with_selected', $tpl, 'the static "With selected" strip was replaced');
    }

    /**
     * The bar is laid out like products.manage: the counter with check
     * all/none, then "Status" and "Actions", each a bulk-edit dropdown, and
     * NO icon glyphs.
     */
    public function testBarIsTheCounterAndTwoDropdownsWithoutIcons(): void
    {
        $bar = self::expandedBar();

        // Each button is a core bulk-edit button, and a short action list is
        // a plain dropdown menu, as the orders grid's status menu is — NOT
        // the core's big .bulk-edit__content panel (the category picker, the
        // price editor), which rendered a two-item list as a wide white box
        // over the rows.
        self::assertSame(3, substr_count($bar, 'class="btn bulk-edit__btn'), 'counter + Status + Actions');
        self::assertSame(3, substr_count($bar, 'dropdown-toggle'), 'counter + two menus');
        self::assertSame(2, substr_count($bar, '<ul class="dropdown-menu">'), 'Status and Actions are plain menus');
        self::assertStringNotContainsString('bulk-edit__content', $bar, 'the big panel is for pickers, not action lists');
        self::assertStringNotContainsString('bulk-edit-inner', $bar);

        // Every action posts the form through the core's own mechanism: the
        // dispatch rides on data-ca-dispatch, so it survives cm-confirm
        // re-submitting the form (a <button name="dispatch[…]"> does not,
        // which is why Delete did nothing).
        self::assertSame(4, substr_count($bar, 'class="cm-process-items cm-submit'), 'two statuses + two actions');
        self::assertSame(4, substr_count($bar, 'data-ca-target-form="sphinx_bulk_form"'));
        self::assertStringNotContainsString('<button type="submit"', $bar, 'actions are links, not submit buttons');
        self::assertStringContainsString('bulk-edit__btn--status', $bar);
        self::assertStringContainsString('bulk-edit__btn--actions', $bar);

        // The counter, and check all / check none from the core include.
        self::assertStringContainsString('data-ca-longtap-selected-counter="true"', $bar);
        self::assertStringContainsString('common/check_items.tpl', $bar);
        self::assertStringContainsString('is_check_all_shown=true', $bar);

        // "Status ▾" — products.manage wording.
        self::assertStringContainsString('sphinx_holidays.bulk_change_to_active', $bar);
        self::assertStringContainsString('sphinx_holidays.bulk_change_to_disabled', $bar);
        self::assertStringNotContainsString('bulk_activate', $bar, 'old label replaced by Change to Active');
        self::assertStringNotContainsString('bulk_deactivate', $bar, 'old label replaced by Change to Disabled');

        // "Actions ▾" — Sync Images + Delete Selected as menu items.
        self::assertStringContainsString('sphinx_holidays.bulk_actions', $bar);
        self::assertStringContainsString('sphinx_holidays.bulk_sync_images', $bar);
        self::assertStringContainsString('sphinx_holidays.bulk_delete', $bar);

        // No icon glyphs anywhere in the bar.
        self::assertStringNotContainsString('<i class="icon-', $bar, 'the bulk bar is plain text — no icons');
    }

    /** The contextual bar only: the .bulk-edit block above the table. */
    private static function expandedBar(): string
    {
        $tpl = self::template();

        $start = strpos($tpl, 'class="bulk-edit clearfix hidden"');
        self::assertIsInt($start);
        // Everything up to the table belongs to the bar.
        $end = strpos($tpl, '<table', $start);
        self::assertIsInt($end);

        return substr($tpl, $start, $end - $start);
    }

    // ── City/Country columns from the hotel API address (not the dest tree) ──

    /**
     * The City column now leads with the RESOLVED city — the value the
     * product's City feature uses — and only falls back to the raw API
     * address. (An earlier arc had dropped the tree-derived columns because
     * they were routinely empty; HotelLocationResolver made them reliable,
     * so they are authoritative again and the address became the fallback.)
     */
    public function testCityColumnShowsTheResolvedValueWithAddressAsFallback(): void
    {
        $tpl = self::template();

        self::assertStringContainsString('{if $hotel.destination_name}', $tpl);
        self::assertStringContainsString('{elseif $hotel.address_city}', $tpl);
        // Country still comes straight from the API address.
        self::assertStringContainsString('{$hotel.address_country|default:"-"|escape:html}', $tpl);
        // City sorts by the resolved column; Country by the address one.
        self::assertStringContainsString('sort_by=destination_name', $tpl);
        self::assertStringContainsString('sort_by=address_country', $tpl);
        self::assertStringContainsString('sphinx_holidays.city', $tpl);
        self::assertStringContainsString('sphinx_holidays.country', $tpl);
        // City text filter in the sidebar.
        self::assertStringContainsString('name="city"', $tpl);
    }

    public function testCityFilterSearchesBothTheResolvedAndTheAddressCity(): void
    {
        // Otherwise typing what the City column displays would find nothing.
        self::assertStringContainsString(
            'h.address_city LIKE ?l OR h.destination_name LIKE ?l',
            (string) file_get_contents(
                dirname(__DIR__, 3) . '/src/Repository/HotelAdminListingRepository.php',
            ),
        );
    }

    public function testCascadingTreeFiltersStayRemoved(): void
    {
        $tpl = self::template();

        // The resolved Region/City CELLS are back (see above), but the
        // cascading tree filter selects + their JS stay gone from this page.
        self::assertStringNotContainsString('sphinx_region_filter', $tpl);
        self::assertStringNotContainsString('sphinx_city_filter', $tpl);
        self::assertStringNotContainsString('get_regions', $tpl);
        self::assertStringNotContainsString('get_cities', $tpl);
    }
}
