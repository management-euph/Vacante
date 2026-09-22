<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Cron\Commands\DeduplicateCommand;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;
use Tygh\Addons\SphinxHolidays\Tests\Support\ProductFnStub;

/**
 * Deleting catalog products must be opted INTO, never out of.
 *
 * `deduplicate` is the only cron mode in this addon that removes CS-Cart
 * products (`fn_delete_product`), and its dry-run flag used to default to
 * false — so a bare `cron_mode=deduplicate`, with no parameters, deleted for
 * real. Combined with a cron access key that shipped as `1234`, that put
 * product deletion one guessable GET away.
 *
 * The grouping key makes the blast radius worse than it first looks: hotels
 * are grouped by (name, property_type, classification, region_id,
 * country_code), so a sync that blanks `property_type` collapses unrelated
 * hotels into one "duplicate" group and deletes their products.
 *
 * Fixture: one group of two hotels, each with its own product. H1 is
 * canonical (lowest id with a product); H2's product #200 is the orphan the
 * command wants to delete.
 */
#[CoversClass(DeduplicateCommand::class)]
final class DeduplicateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
        ProductFnStub::reset();
        Container::reset();
        $this->stubOneDuplicateGroup();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        ProductFnStub::reset();
        Container::reset();
    }

    private function stubOneDuplicateGroup(): void
    {
        DbStub::$getArray = static fn (string $query): array => str_contains($query, 'HAVING cnt > 1')
            ? [[
                'name' => 'Hotel Rodos',
                'property_type' => 'hotel',
                'classification' => '4',
                'region_id' => '77',
                'country_code' => 'GR',
                'cnt' => 2,
                'hotel_ids' => 'H1,H2',
            ]]
            : [];

        $hotels = [
            'H1' => ['hotel_id' => 'H1', 'product_id' => 100, 'name' => 'Hotel Rodos'],
            'H2' => ['hotel_id' => 'H2', 'product_id' => 200, 'name' => 'Hotel Rodos'],
        ];

        DbStub::$getRow = static function (string $query, ...$params) use ($hotels): array {
            if (!str_contains($query, '?:sphinx_hotels WHERE hotel_id')) {
                return [];
            }

            return $hotels[(string) ($params[0] ?? '')] ?? [];
        };

        DbStub::$query = static fn (): int => 1;
    }

    /**
     * The regression: no parameters must mean no deletion.
     */
    public function testRunningWithNoParametersDeletesNothing(): void
    {
        $result = (new DeduplicateCommand())->execute([]);

        self::assertSame([], ProductFnStub::$deleted, 'a bare deduplicate run deleted products');
        self::assertTrue($result['dry_run'], 'dry_run must default to true');

        // It still REPORTS what it would have done — a dry run that found
        // nothing would be indistinguishable from one that was never wired up.
        self::assertSame(1, $result['stats']['products_removed']);
        self::assertSame(1, $result['stats']['groups_processed']);
    }

    /** Deletion happens only on an explicit opt-out. */
    public function testDeletionRequiresAnExplicitDryRunZero(): void
    {
        $result = (new DeduplicateCommand())->execute(['dry_run' => '0']);

        self::assertFalse($result['dry_run']);
        self::assertSame([200], ProductFnStub::$deleted, 'the orphan product should be deleted');
        self::assertSame(1, $result['stats']['products_removed']);
    }

    /**
     * `dry_run=1` must keep working — the cron docs and any existing crontab
     * line use it, and it would be a poor trade to fix the default by
     * breaking the flag people were told to pass.
     */
    public function testExplicitDryRunOneStillReportsOnly(): void
    {
        $result = (new DeduplicateCommand())->execute(['dry_run' => '1']);

        self::assertTrue($result['dry_run']);
        self::assertSame([], ProductFnStub::$deleted);
    }
}
