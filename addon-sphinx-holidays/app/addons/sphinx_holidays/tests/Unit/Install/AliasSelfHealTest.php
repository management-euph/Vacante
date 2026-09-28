<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * Sphinx's aliases are re-seeded as an admin self-heal whenever its seed
 * data changes, so a lost or new alias reaches the shared table without a
 * reinstall (the same heal Novoton runs for its lost star aliases).
 */
final class AliasSelfHealTest extends TestCase
{
    public function testInitSelfHealsTheAliases(): void
    {
        $init = (string) file_get_contents(dirname(__DIR__, 3) . '/init.php');

        self::assertStringContainsString("fn_travel_core_self_heal_due('sphinx_aliases'", $init);
        self::assertStringContainsString('FeatureAliasSeeder::seedAliases(', $init);
        self::assertStringContainsString("AREA === 'A'", $init);
    }
}
