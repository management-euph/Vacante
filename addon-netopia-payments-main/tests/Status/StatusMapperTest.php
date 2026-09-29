<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Status;

use Netopia\CsCart\Status\StatusMapper;
use Netopia\Payment2\Enum\PaymentStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusMapper::class)]
final class StatusMapperTest extends TestCase
{
    #[DataProvider('defaultMappings')]
    public function testDefaultMappingForEachStatusGroup(int $netopiaStatus, string $expected): void
    {
        self::assertSame($expected, (new StatusMapper())->map($netopiaStatus));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function defaultMappings(): iterable
    {
        yield 'Paid -> P' => [PaymentStatus::Paid->value, 'P'];
        yield 'Confirmed -> P' => [PaymentStatus::Confirmed->value, 'P'];
        yield 'Canceled -> I' => [PaymentStatus::Canceled->value, 'I'];
        yield 'Reversed -> I' => [PaymentStatus::Reversed->value, 'I'];
        yield 'Credit (refund) -> B' => [PaymentStatus::Credit->value, 'B'];
        yield 'Declined -> F' => [PaymentStatus::Declined->value, 'F'];
        yield 'Error -> F' => [PaymentStatus::Error->value, 'F'];
        yield 'PendingAuth -> O' => [PaymentStatus::PendingAuth->value, 'O'];
        yield 'ThreeDAuth -> O' => [PaymentStatus::ThreeDAuth->value, 'O'];
    }

    public function testCustomProcessorMappingOverridesDefault(): void
    {
        $mapped = (new StatusMapper())->map(PaymentStatus::Paid->value, ['status_map_3' => 'C']);

        self::assertSame('C', $mapped);
    }

    public function testUnknownStatusDefaultsToOpen(): void
    {
        self::assertSame('O', (new StatusMapper())->map(9999));
    }

    public function testMapRefundDefaultsFullToBackorderedAndPartialToProcessed(): void
    {
        $mapper = new StatusMapper();

        self::assertSame('B', $mapper->mapRefund([], false));
        self::assertSame('P', $mapper->mapRefund([], true));
    }

    public function testMapRefundHonoursPartialOverride(): void
    {
        $mapped = (new StatusMapper())->mapRefund(['status_map_8_partial' => 'Pa'], true);

        self::assertSame('Pa', $mapped);
    }

    public function testMapRefundHonoursFullOverride(): void
    {
        $mapped = (new StatusMapper())->mapRefund(['status_map_8_full' => 'Re'], false);

        self::assertSame('Re', $mapped);
    }


    public function testDefinitionsIncludeEveryKnownStatus(): void
    {
        $definitions = (new StatusMapper())->definitions();

        foreach (PaymentStatus::cases() as $case) {
            self::assertArrayHasKey($case->value, $definitions);
            self::assertSame($case->label(), $definitions[$case->value]['label']);
            self::assertSame($case->group(), $definitions[$case->value]['group']);
        }
    }
}
