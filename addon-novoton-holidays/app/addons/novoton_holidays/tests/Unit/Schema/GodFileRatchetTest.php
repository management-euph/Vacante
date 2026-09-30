<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Line-count ratchet on this addon's god files: they may SHRINK but never
 * grow past their ceiling. When a change trips this, extract the region you
 * are touching into src/ (service/repository/data class) instead of raising
 * the ceiling — and LOWER the ceiling after every extraction so the gains
 * lock in.
 */
final class GodFileRatchetTest extends TestCase
{
    /** @var array<string, int> repo-relative path (from addon root) => max lines */
    private const CEILINGS = [
        'controllers/backend/novoton_price_compare.php' => 1104,
        'functions/hotels.php' => 800,
        'functions/formatting.php' => 340,
        'functions/install.php' => 720,
        'controllers/backend/novoton_prices.php' => 753,
        'controllers/frontend/novoton_booking/add_to_cart.php' => 698,
        'functions/email.php' => 684,
        'controllers/backend/novoton_tools.php' => 669,
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
