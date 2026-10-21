<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Install\SchemaMigrator;

/**
 * ProductGate's rules, as the SQL it runs. An admin who re-activated a
 * product the gate hid used to see it hidden again two runs later: the
 * release cleared gate_hidden, and the next run took the product for a
 * fresh Active one without an Immediate offer.
 */
final class ProductGateTest extends TestCase
{
    private static function gate(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/src/Services/ProductGate.php');
    }

    private static function pos(string $needle): int
    {
        $pos = strpos(self::gate(), $needle);
        self::assertIsInt($pos, "missing: {$needle}");

        return $pos;
    }

    public function testAReleasedProductIsLeftAsTheAdminSetItUntilAnImmediateOfferReturns(): void
    {
        // Released first, so the hide below already sees it.
        $release = self::pos("SET h.gate_hidden = 'N', h.gate_released = 'Y'\n             WHERE h.gate_hidden = 'Y' AND p.status <> 'H'");
        $hide = self::pos("AND h.availability IN ('OR', 'ST', 'NONE') AND h.gate_hidden = 'N' AND h.gate_released = 'N'");
        self::assertLessThan($hide, $release);

        // The release ends with an Immediate offer: the next loss hides it again.
        self::pos("SET gate_released = 'N'\n             WHERE city_code IN (?a) AND availability = 'IM' AND gate_released = 'Y'");
    }

    public function testAHiddenProductNeverLosesItsFlag(): void
    {
        // Hide: flag, then product. Show: product, then flag. A run that stops
        // half way leaves a flagged Active product (released next run), never
        // a Hidden one nothing would show again.
        self::assertLessThan(
            self::pos("db_query(\"UPDATE ?:products SET status = 'H' WHERE product_id IN (?n) AND status = 'A'\", \$toHide);"),
            self::pos("db_query(\"UPDATE ?:eurosite_hotels SET gate_hidden = 'Y' WHERE product_id IN (?n)\", \$toHide);"),
        );
        self::assertLessThan(
            self::pos("db_query(\"UPDATE ?:eurosite_hotels SET gate_hidden = 'N' WHERE product_id IN (?n)\", \$toShow);"),
            self::pos("db_query(\"UPDATE ?:products SET status = 'A' WHERE product_id IN (?n) AND status = 'H'\", \$toShow);"),
        );
    }

    public function testPartlyAnsweredDestinationsCanOnlyHaveProductsShown(): void
    {
        self::pos('$toHide = $cityCodes === [] ? [] :');
        self::pos('$answered = [...$cityCodes, ...$partlyAnsweredCityCodes];');
    }

    public function testTheColumnIsHealedOnStoresInstalledBeforeIt(): void
    {
        self::assertSame(
            "ADD COLUMN `gate_released` ENUM('Y','N') NOT NULL DEFAULT 'N' AFTER `gate_hidden`",
            SchemaMigrator::HOTEL_COLUMNS['gate_released'] ?? null,
        );
    }
}
