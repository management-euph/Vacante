<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\FieldContext;
use Tygh\Addons\EuphProfileTypes\Model\ProfileType;
use Tygh\Addons\EuphProfileTypes\Tests\Support\Types;

final class FieldContextTest extends TestCase
{
    public function testTheParamsCarryWhatThePostHookNeeds(): void
    {
        $type = ProfileType::fromRow(Types::row(6, 'S2', 'V'), ['P' => [1, 3]]);
        $context = new FieldContext(UserKind::Vendor, $type, FieldArea::Registration, ['S2' => 'Shop', 'S' => 'Vendor']);

        self::assertSame([
            'kind' => 'V',
            'type_id' => 6,
            'code' => 'S2',
            'area' => 'registration',
            'choice' => ['S2' => 'Shop', 'S' => 'Vendor'],
            'vendor_plan_ids' => [1, 3],
        ], $context->toParams());
    }

    public function testTheChoiceIsReadBackAsCodeToName(): void
    {
        self::assertSame(['B' => 'Company', '12' => 'Twelve'], FieldContext::choiceFromParams(['choice' => ['B' => 'Company', 12 => 'Twelve']]));
        self::assertNull(FieldContext::choiceFromParams(['choice' => []]));
        self::assertNull(FieldContext::choiceFromParams(['choice' => null]));
        self::assertNull(FieldContext::choiceFromParams([]));
    }
}
