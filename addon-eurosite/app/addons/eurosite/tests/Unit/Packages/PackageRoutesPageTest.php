<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Packages;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Repository\PackageRouteListing;
use Tygh\Addons\Eurosite\Services\PackageRoutesView;
use Tygh\Addons\Eurosite\Tests\Support\DbStub;

/**
 * Eurosite → Package routes: the listing's filters, sorts and pages, what a
 * row shows, and the files that must agree (controller, template, menus,
 * labels).
 */
final class PackageRoutesPageTest extends TestCase
{
    private const string ADDON = __DIR__ . '/../../..';

    #[\Override]
    protected function tearDown(): void
    {
        DbStub::reset();
    }

    // ── The listing ─────────────────────────────────────────────────────

    public function testTheSearchIsNormalized(): void
    {
        $listing = new PackageRouteListing(25);

        self::assertSame([
            'page' => 1,
            'items_per_page' => 25,
            'sort_by' => 'arrival',
            'sort_order' => 'asc',
            'sort_order_rev' => 'desc',
            'transport' => '',
            'departure' => '',
            'arrival' => '',
        ], $listing->normalize([]));

        $search = $listing->normalize(['transport' => 'flight', 'sort_by' => 'DROP', 'sort_order' => 'DESC', 'page' => '-3', 'departure' => ' Cluj ', 'arrival' => 'Antalya']);
        self::assertSame(['', 'arrival', 'desc', 'asc', 1, 'Cluj', 'Antalya'], [
            $search['transport'], $search['sort_by'], $search['sort_order'], $search['sort_order_rev'], $search['page'], $search['departure'], $search['arrival'],
        ]);
        self::assertSame('bus', $listing->normalize(['transport' => 'bus'])['transport']);
    }

    public function testTheFiltersMatchNamesAndCodes(): void
    {
        self::assertSame('', PackageRouteListing::condition(['transport' => '', 'departure' => '', 'arrival' => '']));
        self::assertSame(" AND r.transport = 'bus'", PackageRouteListing::condition(['transport' => 'bus']));
        self::assertSame(
            " AND (r.dep_city_name LIKE '%Cluj%' OR r.dep_city_code LIKE '%Cluj%')",
            PackageRouteListing::condition(['departure' => 'Cluj']),
        );
        // The arrival also finds a city by its zone or country.
        self::assertSame(
            " AND (r.city_name LIKE '%Antalya%' OR r.city_code LIKE '%Antalya%' OR r.zone_name LIKE '%Antalya%' OR r.country_name LIKE '%Antalya%')",
            PackageRouteListing::condition(['arrival' => 'Antalya']),
        );
    }

    public function testEachSortHasItsOrder(): void
    {
        self::assertSame('r.country_name ASC, r.city_name ASC, r.dep_city_name ASC, r.transport ASC', PackageRouteListing::orderBy('arrival', 'asc'));
        self::assertSame('r.first_date DESC, r.country_name DESC, r.city_name DESC', PackageRouteListing::orderBy('dates', 'desc'));
        self::assertSame(PackageRouteListing::orderBy('arrival', 'asc'), PackageRouteListing::orderBy('nonsense', 'asc'));
    }

    public function testAPageBeyondTheLastShowsTheLast(): void
    {
        $asked = [];
        DbStub::$getField = static fn (string $sql): string => '120';
        DbStub::$getArray = static function (string $sql, mixed ...$params) use (&$asked): array {
            $asked = $params;

            return [['transport' => 'plane', 'city_code' => 'TRALN']];
        };

        [$rows, $search] = (new PackageRouteListing(50))->getListing(['page' => 9, 'transport' => 'plane']);

        self::assertSame([['transport' => 'plane', 'city_code' => 'TRALN']], $rows);
        self::assertSame([120, 3], [$search['total_items'], $search['page']]);
        self::assertSame([" AND r.transport = 'plane'", 100, 50], $asked, 'the filter, then LIMIT 100, 50');
    }

    // ── What a row shows ────────────────────────────────────────────────

    public function testARowShowsTheRouteTheStaysAndTheDatesAhead(): void
    {
        $rows = PackageRoutesView::rows([[
            'transport' => 'plane',
            'city_code' => 'TRALN', 'city_name' => 'Alanya', 'zone_name' => 'Antalya', 'country_name' => 'Turkey',
            'dep_city_code' => 'ROBCH1', 'dep_city_name' => 'Bucharest',
            'dates_json' => '["2026-11-01","2026-11-09","2026-11-16","2026-12-07","2026-11-23","2026-11-30","2027-01-04","2027-02-01"]',
            'last_synced_at' => '2026-11-08 03:34:12',
        ]], [7, 10, 14], '2026-11-09');

        self::assertSame([
            'transport' => 'plane',
            'departure' => 'Bucharest',
            'departure_code' => 'ROBCH1',
            'arrival' => 'Alanya',
            'arrival_code' => 'TRALN',
            'arrival_place' => 'Antalya · Turkey',
            'nights' => '7, 10, 14',
            // From today on, in order; the next year's with its year; six, then "+1 more".
            'dates' => '9 Nov, 16 Nov, 23 Nov, 30 Nov, 7 Dec, 4 Jan 2027',
            'more_dates' => 1,
            'last_synced_at' => '8 Nov 2026, 03:34',
        ], $rows[0]);
    }

    public function testACoachRouteWhoseZoneIsItsCityAndWhoseDatesAreGone(): void
    {
        $row = PackageRoutesView::rows([[
            'transport' => 'bus', 'city_code' => 'HRDBR', 'city_name' => 'Dubrovnik', 'zone_name' => 'DUBROVNIK', 'country_name' => 'Croatia',
            'dates_json' => '["2026-09-13","2026-09-20"]', 'last_synced_at' => '',
        ]], [7], '2026-11-09')[0];

        self::assertSame(['bus', 'Croatia', '', 0, ''], [$row['transport'], $row['arrival_place'], $row['dates'], $row['more_dates'], $row['last_synced_at']]);
        self::assertSame('', PackageRoutesView::rows([['dates_json' => 'not json']], [7], '2026-11-09')[0]['dates']);
        self::assertSame('plane', PackageRoutesView::rows([['transport' => '']], [7], '2026-11-09')[0]['transport']);
    }

    // ── The files that must agree ───────────────────────────────────────

    public function testThePageIsWiredFromMenuToTemplate(): void
    {
        $controller = (string) file_get_contents(self::ADDON . '/controllers/backend/eurosite.php');
        $template = (string) file_get_contents(self::ADDON . '/../../../design/backend/templates/addons/eurosite/views/eurosite/package_routes.tpl');
        $menu = (string) file_get_contents(self::ADDON . '/schemas/menu/menu.post.php');
        $tabs = (string) file_get_contents(self::ADDON . '/schemas/menu/actions.post.php');

        self::assertStringContainsString("if (\$mode === 'package_routes') {", $controller);
        self::assertStringContainsString('[$rows, $search] = $listing->getListing($_REQUEST);', $controller);
        foreach (['eurosite_package_routes', 'eurosite_routes_total', 'eurosite_routes_last_synced', 'eurosite_routes_url'] as $var) {
            self::assertStringContainsString("\$view->assign('{$var}'", $controller, $var);
            self::assertStringContainsString('$' . $var, $template, $var);
        }
        // Sync now runs the job and comes back to the page.
        self::assertStringContainsString('<input type="hidden" name="sync_type" value="package_routes" />', $template);
        self::assertStringContainsString('<input type="hidden" name="return_to" value="package_routes" />', $template);
        self::assertStringContainsString("'package_routes' => 'eurosite.package_routes',", $controller);
        self::assertStringContainsString('{include file="common/pagination.tpl"', $template);

        self::assertStringContainsString("'href' => 'eurosite.package_routes'", $menu);
        self::assertStringContainsString("'href'     => 'eurosite.package_routes'", $tabs);
        self::assertStringContainsString("'eurosite.package_routes', 'eurosite.whitelist'", $tabs, 'the tab strip shows on the page too');
    }

    public function testEveryLabelThePageUsesExists(): void
    {
        $lang = require self::ADDON . '/lang_keys.php';
        $template = (string) file_get_contents(self::ADDON . '/../../../design/backend/templates/addons/eurosite/views/eurosite/package_routes.tpl');
        preg_match_all('/__\("(eurosite\.[a-z0-9_]+)"/', $template, $m);

        self::assertNotEmpty($m[1]);
        foreach (array_unique([...$m[1], 'eurosite.package_routes']) as $key) {
            self::assertArrayHasKey($key, $lang, $key);
        }
    }
}
