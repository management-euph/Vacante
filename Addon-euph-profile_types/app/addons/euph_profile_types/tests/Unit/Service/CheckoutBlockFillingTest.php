<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Service\CheckoutBlockFilling;

final class CheckoutBlockFillingTest extends TestCase
{
    public function testSwitchingOnKeepsTheBlocksOwnFilling(): void
    {
        $content = ['items' => ['filling' => 'profile_fields', 'item_ids' => '12,13']];
        $on = CheckoutBlockFilling::switchOn($content);

        self::assertSame(['filling' => 'euph_profile_type', 'item_ids' => '12,13', 'euph_previous_filling' => 'profile_fields'], $on['items'] ?? null);
        self::assertNull(CheckoutBlockFilling::switchOn((array) $on), 'switched once');
    }

    public function testSwitchingOffGivesItBack(): void
    {
        $on = CheckoutBlockFilling::switchOn(['items' => ['filling' => 'profile_fields', 'item_ids' => '12']]);
        $off = CheckoutBlockFilling::switchOff((array) $on);

        self::assertSame(['items' => ['filling' => 'profile_fields', 'item_ids' => '12']], $off);
        self::assertNull(CheckoutBlockFilling::switchOff((array) $off), 'a block on its own filling is left alone');
    }

    public function testABlockWithoutAFillingLosesNothing(): void
    {
        $on = CheckoutBlockFilling::switchOn(['items' => ['item_ids' => '5']]);

        self::assertSame(['items' => ['item_ids' => '5']], CheckoutBlockFilling::switchOff((array) $on));
        self::assertNull(CheckoutBlockFilling::switchOn(['other' => 1]), 'no items: nothing to switch');
    }

    public function testContentIsReadAndWrittenInItsOwnFormat(): void
    {
        $content = ['items' => ['filling' => 'profile_fields']];
        $serialized = serialize($content);
        $json = json_encode($content);

        self::assertSame($content, CheckoutBlockFilling::decode($serialized));
        self::assertSame($content, CheckoutBlockFilling::decode((string) $json));
        self::assertSame($serialized, CheckoutBlockFilling::encode($content, $serialized));
        self::assertSame($json, CheckoutBlockFilling::encode($content, (string) $json));
        self::assertNull(CheckoutBlockFilling::decode(''));
        self::assertNull(CheckoutBlockFilling::decode('a:1:{broken'));
    }

    public function testObjectsInStoredContentAreNotInstantiated(): void
    {
        $decoded = CheckoutBlockFilling::decode(serialize(['items' => new \ArrayObject([1])]));

        self::assertIsArray($decoded);
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded['items']);
    }

    public function testTheThreeCustomerBlocksAndTheirSections(): void
    {
        self::assertSame(
            ['lite_checkout_customer_information' => 'C', 'lite_checkout_customer_address' => 'S', 'lite_checkout_customer_billing' => 'B'],
            CheckoutBlockFilling::BLOCKS,
        );
    }
}
