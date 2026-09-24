<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Hotels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\EurositeProductFactory;

/**
 * Eurosite → Hotels is five files that must agree: the controller, the
 * template, hotels.js, the menu schemas and the labels. A rename in one
 * leaves the page rendering, just emptier; this pins the joins.
 */
final class HotelsPageContractTest extends TestCase
{
    private const ADDON = __DIR__ . '/../../..';

    private static function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    private static function template(): string
    {
        return self::read(self::ADDON . '/../../../design/backend/templates/addons/eurosite/views/eurosite/hotels.tpl');
    }

    private static function controller(): string
    {
        return self::read(self::ADDON . '/controllers/backend/eurosite.php');
    }

    public function testTheControllerHandsThePageItsListAndSearch(): void
    {
        $c = self::controller();

        self::assertStringContainsString("if (\$mode === 'hotels') {", $c);
        self::assertStringContainsString('[$rows, $search] = $listing->getListing($_REQUEST);', $c);
        // common/pagination.tpl reads $search.total_items — it has to be there.
        self::assertStringContainsString("\$view->assign('search', \$search);", $c);
        self::assertStringContainsString("\$search['total_items'] = TypeCoerce::toInt(db_get_field(", self::read(self::ADDON . '/src/Repository/HotelListingRepository.php'));
        foreach (['eurosite_hotels', 'eurosite_hotel_summary', 'eurosite_hotel_chips', 'eurosite_hotels_url', 'eurosite_hotels_query', 'eurosite_root_category_set'] as $var) {
            self::assertStringContainsString("\$view->assign('{$var}',", $c, $var);
            self::assertStringContainsString('$' . $var, self::template(), "{$var} is assigned but the page never reads it");
        }
    }

    public function testTheTwoActionsArePostOnlyAndComeBackToTheList(): void
    {
        $c = self::controller();
        $post = substr($c, 0, (int) strpos($c, '// ─── GET modes ───'));

        self::assertStringContainsString("if (\$mode === 'create_products' || \$mode === 'check_availability') {", $post);
        self::assertStringContainsString("HotelListView::returnUrl(RequestCoerce::string(\$_REQUEST, 'return_query'))", $post);
        self::assertStringContainsString("\$dispatcher->dispatch('availability',", $post, 'the check goes through the dispatcher, so it takes the lock and the run log');

        $tpl = self::template();
        self::assertStringContainsString('method="post" id="es-hotels-form"', $tpl);
        self::assertStringContainsString('name="security_hash"', $tpl);
        self::assertStringContainsString('name="dispatch[eurosite.create_products]"', $tpl);
        self::assertStringContainsString('name="dispatch[eurosite.check_availability]"', $tpl);
        self::assertStringContainsString('name="hotel_keys[]"', $tpl);
        self::assertStringContainsString('name="return_query"', $tpl);
    }

    public function testEveryElementTheScriptNeedsIsOnThePage(): void
    {
        $js = self::read(self::ADDON . '/../../../js/addons/eurosite/hotels.js');
        $tpl = self::template();

        preg_match_all("/getElementById\\('([a-z0-9-]+)'\\)/", $js, $m);
        self::assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $id) {
            self::assertStringContainsString('id="' . $id . '"', $tpl, "hotels.js looks for #{$id}");
        }
        foreach (['data-txt-confirm-create', 'data-txt-confirm-check', 'data-txt-confirm-check-all', 'data-eligible', 'es-row-check'] as $hook) {
            self::assertStringContainsString($hook, $js);
            self::assertStringContainsString($hook, $tpl);
        }
        self::assertStringContainsString('{script src="js/addons/eurosite/hotels.js"}', $tpl);
        self::assertLessThan(strpos($tpl, '{/capture}'), strpos($tpl, '{script src="js/addons/eurosite/hotels.js"}'), 'inside the mainbox capture');
    }

    public function testThePageIsReachableFromTheMenuTheTabsAndTheDashboard(): void
    {
        self::assertStringContainsString("'href' => 'eurosite.hotels'", self::read(self::ADDON . '/schemas/menu/menu.post.php'));
        $actions = self::read(self::ADDON . '/schemas/menu/actions.post.php');
        self::assertStringContainsString("'href'     => 'eurosite.hotels'", $actions);
        self::assertStringContainsString("foreach (['eurosite.manage', 'eurosite.hotels', 'eurosite.whitelist'] as \$page)", $actions);
        self::assertStringContainsString('{"eurosite.hotels"|fn_url}', self::read(self::ADDON . '/../../../design/backend/templates/addons/eurosite/views/eurosite/manage.tpl'));
    }

    public function testEveryLabelThePageUsesExists(): void
    {
        /** @var array<string, array<string, string>> $lang */
        $lang = require self::ADDON . '/lang_keys.php';
        $tpl = self::template();

        preg_match_all('/__\("(eurosite\.[a-z_]+)"/', $tpl, $m);
        foreach (array_unique($m[1]) as $key) {
            self::assertArrayHasKey($key, $lang, "hotels.tpl uses {$key}");
        }
        // Built at runtime: "eurosite.avail_`$h.availability_key`" and "eurosite.skip_`$h.skip_reason`".
        foreach (['im', 'or', 'st', 'none', 'unchecked'] as $k) {
            self::assertArrayHasKey('eurosite.avail_' . $k, $lang);
        }
        foreach (EurositeProductFactory::SKIP_REASONS as $reason) {
            self::assertArrayHasKey('eurosite.skip_' . $reason, $lang, "skip reason {$reason} has no label");
        }
        preg_match_all("/'label_key' => '([^']+)'|\\['[^']*', '(eurosite\\.[a-z_]+)'/", self::read(self::ADDON . '/src/Services/HotelListView.php'), $chips);
        foreach (array_filter($chips[2]) as $key) {
            self::assertArrayHasKey($key, $lang, "chip label {$key}");
        }
    }

    public function testThePageStylesShip(): void
    {
        $hook = self::read(self::ADDON . '/../../../design/backend/templates/addons/eurosite/hooks/index/styles.post.tpl');
        self::assertStringContainsString('{style src="addons/eurosite/styles.less"}', $hook);
        $less = self::read(self::ADDON . '/../../../design/backend/css/addons/eurosite/styles.less');
        foreach (['.es-tiles', '.es-chip', '.es-selection', '.es-thumb', '.es-pill'] as $class) {
            self::assertStringContainsString($class, $less);
        }
        foreach (['im', 'or', 'st', 'none', 'unchecked'] as $k) {
            self::assertStringContainsString('&--' . $k, $less, "no pill style for {$k}");
        }
    }
}
