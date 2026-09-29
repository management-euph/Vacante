<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Status;

use Netopia\CsCart\Status\StatusTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusTable::class)]
final class StatusTableTest extends TestCase
{
    public function testCommonRowsComeFirstInOrderAndEveryStatusIsCovered(): void
    {
        $table = StatusTable::build([]);

        self::assertSame(StatusTable::COMMON, array_column($table['common'], 'key'));
        // 23 statuses, refund (8) split in two: 24 rows.
        self::assertCount(24, [...$table['common'], ...$table['rare']]);
        self::assertNotContains('8', array_column($table['rare'], 'key'));
        self::assertSame(0, $table['changed']);
    }

    public function testDefaultsMatchWhatTheMapperApplies(): void
    {
        $rows = self::byKey(StatusTable::build([]));

        self::assertSame('P', $rows['3']['default']);
        self::assertSame('O', $rows['6']['default']);
        self::assertSame('B', $rows['8_full']['default']);
        self::assertSame('P', $rows['8_partial']['default']);
        self::assertSame('I', $rows['4']['default']);
        self::assertSame('F', $rows['12']['default']);
        self::assertSame('netopia_ntp_status_8_full', $rows['8_full']['lang_key']);
        self::assertSame('netopia_customer_msg_declined', $rows['12']['customer_msg_key']);
    }

    public function testSavedValuesAreMarkedChanged(): void
    {
        $table = StatusTable::build(['status_map_5' => 'C', 'status_map_3' => 'P', 'status_map_19' => 'I']);
        $rows = self::byKey($table);

        self::assertTrue($rows['5']['changed']);
        self::assertSame('C', $rows['5']['value']);
        self::assertFalse($rows['3']['changed']);
        self::assertTrue($rows['19']['changed']);
        self::assertSame(2, $table['changed']);
    }

    /**
     * @param array{common: list<array<string, mixed>>, rare: list<array<string, mixed>>, changed: int} $table
     * @return array<string, array<string, mixed>>
     */
    private static function byKey(array $table): array
    {
        $rows = [];
        foreach ([...$table['common'], ...$table['rare']] as $row) {
            $rows[(string) $row['key']] = $row;
        }

        return $rows;
    }
}
