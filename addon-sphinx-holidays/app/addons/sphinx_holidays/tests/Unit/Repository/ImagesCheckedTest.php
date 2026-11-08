<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\HotelLinkingRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * enrich_hotel_data asked the hotel API again, every run, for the same
 * first 100 hotels without images (sandbox: 100 scanned, "100 failed",
 * twice): Sphinx has none for them, so it never got to the others. A hotel
 * the API has no images for is now remembered and asked again after 30
 * days, and it is not counted as a failure.
 */
final class ImagesCheckedTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testAHotelWithoutImagesWaitsThirtyDays(): void
    {
        $asked = '';
        DbStub::$getArray = static function (string $query) use (&$asked): array {
            $asked = $query;

            return [];
        };
        (new HotelLinkingRepository())->findMissingImages();

        self::assertStringContainsString('AND (h.images_checked_at IS NULL OR h.images_checked_at < ', $asked);
        self::assertStringContainsString(date('Y-m-d', time() - 30 * 86400), $asked, '30 days ago');

        $marked = [];
        DbStub::$query = static function (string $query, mixed ...$params) use (&$marked): int {
            $marked = [$query, $params];

            return 1;
        };
        (new HotelLinkingRepository())->markImagesChecked('3359727');
        self::assertSame('UPDATE ?:sphinx_hotels SET images_checked_at = ?s WHERE hotel_id = ?s', $marked[0]);
        self::assertSame('3359727', $marked[1][1]);
    }

    public function testTheCommandRemembersAndDoesNotCountItAsAFailure(): void
    {
        $command = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Cron/Commands/EnrichHotelDataCommand.php');
        $noImages = substr($command, (int) strpos($command, 'if (empty($rawImages) || !is_array($rawImages)) {'), 400);

        self::assertStringContainsString('Container::getHotelRepository()->markImagesChecked($hotelId);', $noImages);
        self::assertStringContainsString('$none++;', $noImages);
        self::assertStringNotContainsString('$failed++;', $noImages);
    }

    /** The column reaches fresh installs, upgrades and the installs SchemaMigrator converges. */
    public function testTheColumnReachesEveryInstall(): void
    {
        $root = dirname(__DIR__, 3);
        $xml = (string) file_get_contents($root . '/addon.xml');
        $comment = "COMMENT 'Last time the hotel API had no images for it (enrich_hotel_data asks again after 30 days)'";

        self::assertStringContainsString("`images_checked_at` DATETIME DEFAULT NULL {$comment},", $xml, 'the CREATE TABLE');
        self::assertStringContainsString("ALTER TABLE `?:sphinx_hotels`\n                ADD COLUMN `images_checked_at` DATETIME DEFAULT NULL", $xml, 'the upgrade item');
        self::assertStringContainsString("'images_checked_at' => \"ADD COLUMN `images_checked_at` DATETIME DEFAULT NULL {$comment} AFTER `images_json`\"", (string) file_get_contents($root . '/src/Install/SchemaMigrator.php'));
    }
}
