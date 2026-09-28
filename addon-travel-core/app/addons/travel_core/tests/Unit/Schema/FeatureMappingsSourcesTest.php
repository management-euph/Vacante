<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The Sources column once rendered one "," pill per row: Smarty 5 passes
 * the piped value as the modifier's FIRST argument, so {$x|explode:","}
 * ran explode($x, ","). The pages now get the providers pre-grouped
 * (FeatureMappingsView) instead of exploding a GROUP_CONCAT.
 */
final class FeatureMappingsSourcesTest extends TestCase
{
    private static function template(): string
    {
        $tpl = file_get_contents(dirname(__DIR__, 3)
            . '/../../../design/backend/templates/addons/travel_core/views/travel_feature_mappings/manage.tpl');
        self::assertIsString($tpl);

        return $tpl;
    }

    public function testNoModifierTakesTheDelimiterAsItsArgument(): void
    {
        self::assertStringNotContainsString('|explode:","', self::template());
    }

    public function testSourcesAreListedPerProvider(): void
    {
        $tpl = self::template();

        // Dashboard: one coverage chip per provider; list: each provider's values.
        self::assertStringContainsString('from=$stat.coverage', $tpl);
        self::assertStringContainsString('$m.provider_values[$chip.provider]', $tpl);
    }

    public function testDashboardListsAliasGaps(): void
    {
        self::assertStringContainsString('$alias_gaps', self::template());

        $controller = file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/travel_feature_mappings.php');
        self::assertIsString($controller);
        self::assertStringContainsString("'alias_gaps'", $controller);
    }
}
