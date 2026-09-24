<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CS-Cart's common/pagination.tpl is included as a PAIR.
 *
 * The first include only opens `<div class="cm-pagination-container">`; the
 * second draws « ‹ [from - to / total ▾] › » and closes the div. It builds
 * everything from `$search|fn_generate_pagination`, so `$search` itself must
 * carry `total_items`.
 *
 * Three admin lists got both wrong — one include after the table, the total
 * in a separate variable — so they never showed page controls and a long
 * list was stuck on its first page: Travel Core bookings, Novoton bookings,
 * Novoton alternative requests. This scans every add-on's backend templates.
 */
final class PaginationPairTest extends TestCase
{
    private const INCLUDE = '/\{include\s+file="common\/pagination\.tpl"[^}]*\}/';

    /**
     * @return array<string, array{string}>
     */
    public static function templatesUsingPagination(): array
    {
        $root = dirname(__DIR__, 7);
        $out = [];
        foreach (glob($root . '/*/design/backend/templates/addons/*/views/*/*.tpl') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match(self::INCLUDE, $src) === 1) {
                $out[substr($file, strlen($root) + 1)] = [$file];
            }
        }

        return $out;
    }

    public function testTheScanFindsTheKnownLists(): void
    {
        $found = array_keys(self::templatesUsingPagination());

        foreach ([
            'addon-travel-core/design/backend/templates/addons/travel_core/views/travel_bookings/manage.tpl',
            'addon-novoton-holidays/design/backend/templates/addons/novoton_holidays/views/novoton_alternatives/manage.tpl',
            'addon-sphinx-holidays/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/hotels.tpl',
        ] as $expected) {
            self::assertContains($expected, $found);
        }
    }

    #[DataProvider('templatesUsingPagination')]
    public function testPaginationIsAnOpenAndCloseIncludeAroundTheList(string $file): void
    {
        $src = (string) preg_replace('/\{\*.*?\*\}/s', '', (string) file_get_contents($file));
        preg_match_all(self::INCLUDE, $src, $m, PREG_OFFSET_CAPTURE);
        $includes = $m[0];

        self::assertCount(2, $includes, 'common/pagination.tpl must be included exactly twice: open, then close');

        // Around the list: the opener before the first table, the closer after the last.
        $firstTable = strpos($src, '<table');
        $lastTable = strrpos($src, '</table>');
        if ($firstTable !== false && $lastTable !== false) {
            self::assertLessThan($firstTable, $includes[0][1], 'the opening include comes after the table');
            self::assertGreaterThan($lastTable, $includes[1][1], 'the closing include comes before the table ends');
        }
    }

    /** The two fixed controllers hand the total to the template inside $search. */
    public function testTheFixedControllersPutTheTotalInSearch(): void
    {
        $root = dirname(__DIR__, 7);

        $bookings = (string) file_get_contents($root . '/addon-travel-core/app/addons/travel_core/controllers/backend/travel_bookings.php');
        $totalAt = strpos($bookings, "\$params['total_items'] = \$total;");
        $assignAt = strpos($bookings, "\$view->assign('search', \$params);");
        self::assertIsInt($totalAt);
        self::assertIsInt($assignAt);
        self::assertLessThan($assignAt, $totalAt, 'total_items is set after $search was assigned');
        // After the auto-link re-query, so it counts the rows actually shown.
        self::assertGreaterThan(strpos($bookings, '_travel_bookings_autolink_if_needed($bookings)'), $totalAt);

        $alternatives = (string) file_get_contents($root . '/addon-novoton-holidays/app/addons/novoton_holidays/controllers/backend/novoton_alternatives.php');
        self::assertMatchesRegularExpression(
            "/\\\$view->assign\\('search', \\[[^\\]]*'total_items' => \\\$total_items,/s",
            $alternatives,
        );
        // The per-page menu sends items_per_page; the controller must honour it.
        self::assertStringContainsString("RequestCoerce::int(\n        \$_REQUEST,\n        'items_per_page',", $alternatives);
    }
}
