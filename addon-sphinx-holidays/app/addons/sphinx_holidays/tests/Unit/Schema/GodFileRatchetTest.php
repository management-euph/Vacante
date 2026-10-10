<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Line-count ratchet on this addon's god files: they may SHRINK but never
 * grow past their ceiling. When a change trips this, extract the region you
 * are touching into src/ (service/repository/data class) instead of raising
 * the ceiling — and LOWER the ceiling after every extraction so the gains
 * lock in (seed-alias data -> Install\SphinxAliasSeedData was the first).
 */
final class GodFileRatchetTest extends TestCase
{
    /** @var array<string, int> repo-relative path (from addon root) => max lines */
    private const CEILINGS = [
        'func.php' => 541,
        'controllers/backend/sphinx_holidays.php' => 830,
        'src/Cron/Commands/DiagnoseSearchCommand.php' => 665,
    ];

    public function testGodFilesNeverGrowPastTheirCeiling(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (self::CEILINGS as $rel => $ceiling) {
            $path = $root . '/' . $rel;
            self::assertFileExists($path, $rel);
            $lines = substr_count((string) file_get_contents($path), "\n") + 1;
            self::assertLessThanOrEqual(
                $ceiling,
                $lines,
                "{$rel} grew to {$lines} lines (ceiling {$ceiling}) — extract the region you touched into src/ instead of growing the god file",
            );
        }
    }
}
