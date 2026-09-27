<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Cron\Commands\BackfillDescriptionsCommand;
use Tygh\Addons\NovotonHolidays\Services\CronPlanBuilder;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * backfill_descriptions: linked products whose description is empty get the
 * hotel's text; only full_description is written; a written description is
 * kept unless force=1.
 */
#[CoversClass(BackfillDescriptionsCommand::class)]
final class BackfillDescriptionsCommandTest extends TestCase
{
    /** @var list<string> */
    private array $out = [];

    /** @var list<array{string, list<mixed>}> */
    private array $updates = [];

    /** @var list<string> */
    private array $selects = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->out = [];
        $this->updates = [];
        $this->selects = [];
        DbStub::$query = function (string $query, ...$params): int {
            if (str_contains($query, '?:product_descriptions')) {
                $this->updates[] = [trim((string) preg_replace('/\s+/', ' ', $query)), $params];

                return 2; // two language rows
            }

            return 1;
        };
        DbStub::$getField = static fn (string $query, ...$params): int => 0;
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $descriptions hotel_id => <Description> inner XML
     */
    private function backfill(array $params, array $descriptions, array $rows): void
    {
        DbStub::$getArray = function (string $query, ...$args) use ($rows): array {
            $this->selects[] = $query;

            return $rows;
        };

        $cmd = (new \ReflectionClass(BackfillDescriptionsCommand::class))->newInstanceWithoutConstructor();
        $p = new \ReflectionProperty(\Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand::class, 'params');
        $p->setValue($cmd, $params);
        $s = new \ReflectionProperty(\Tygh\Addons\TravelCore\Cron\AbstractCronCommand::class, 'startTime');
        $s->setValue($cmd, microtime(true));
        $cmd->setOutputCallback(function (string $msg, bool $nl = true): void {
            $this->out[] = $msg;
        });
        $cmd->setSleeper(static function (int $us): void {
        });
        $cmd->setDescriptionFetcher(static function (string $hid) use ($descriptions): \SimpleXMLElement {
            if ($hid === 'BOOM') {
                throw new \RuntimeException('API down');
            }

            return new \SimpleXMLElement('<hotel_description><Description>' . ($descriptions[$hid] ?? '') . '</Description></hotel_description>');
        });

        $cmd->execute();
    }

    public function testEmptyDescriptionsAreFilledFromNovoton(): void
    {
        $this->backfill([], ['4535' => '<p style="x">Sea view</p>'], [
            ['hotel_id' => '4535', 'hotel_name' => 'Monaco', 'product_id' => 14],
        ]);

        self::assertCount(1, $this->updates);
        [$sql, $params] = $this->updates[0];
        self::assertSame(
            "UPDATE ?:product_descriptions SET full_description = ?s WHERE product_id = ?i AND (full_description IS NULL OR TRIM(full_description) = '')",
            $sql,
        );
        self::assertSame(['<p>Sea view</p>', 14], $params);
        self::assertStringContainsString('EXISTS', $this->selects[0], 'only products missing a description');
        self::assertStringContainsString('description written (2 language(s))', implode("\n", $this->out));
    }

    public function testForceRewritesEvenAWrittenDescription(): void
    {
        $this->backfill(['force' => 1], ['4535' => '<p>Sea view</p>'], [
            ['hotel_id' => '4535', 'hotel_name' => 'Monaco', 'product_id' => 14],
        ]);

        self::assertSame('UPDATE ?:product_descriptions SET full_description = ?s WHERE product_id = ?i', $this->updates[0][0]);
        self::assertStringNotContainsString('EXISTS', $this->selects[0]);
    }

    public function testNothingIsWrittenWhenNovotonHasNoTextOrTheCallFails(): void
    {
        $this->backfill([], [], [
            ['hotel_id' => '7', 'hotel_name' => 'Empty', 'product_id' => 20],
            ['hotel_id' => 'BOOM', 'hotel_name' => 'Down', 'product_id' => 21],
        ]);

        self::assertSame([], $this->updates);
        $text = implode("\n", $this->out);
        self::assertStringContainsString('Novoton has no description', $text);
        self::assertStringContainsString('description fetch failed', $text);
    }

    public function testOnlyTheDescriptionIsTouched(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Cron/Commands/BackfillDescriptionsCommand.php');

        self::assertStringNotContainsString('fn_travel_core_apply_seo_fields', $src);
        self::assertStringNotContainsString('fn_update_product', $src);
        self::assertDoesNotMatchRegularExpression('/SET[^;]*\b(product|page_title|meta_description|meta_keywords)\s*=/', $src);
    }

    public function testItIsOnTheDashboardAsAnOnDemandJob(): void
    {
        self::assertContains('backfill_descriptions', CronPlanBuilder::ON_DEMAND);
        self::assertContains('backfill_descriptions', CronPlanBuilder::HAS_STATUS);
    }
}
