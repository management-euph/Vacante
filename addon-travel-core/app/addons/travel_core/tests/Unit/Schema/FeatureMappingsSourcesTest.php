<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Smarty 5 passes the piped value as the modifier's FIRST argument, so
 * {$x|explode:","} runs explode($x, ",") and renders one "," pill instead
 * of the providers. The value must be the second argument.
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

    public function testSourcesAreExplodedWithTheDelimiterFirst(): void
    {
        $tpl = self::template();

        self::assertStringContainsString('","|explode:$stat.providers', $tpl);
        self::assertStringContainsString('","|explode:$m.api_sources', $tpl);
        self::assertStringNotContainsString('|explode:","', $tpl);
    }

    public function testDashboardListsAliasGaps(): void
    {
        self::assertStringContainsString('$alias_gaps', self::template());

        $controller = file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/travel_feature_mappings.php');
        self::assertIsString($controller);
        self::assertStringContainsString("'alias_gaps'", $controller);
    }
}
