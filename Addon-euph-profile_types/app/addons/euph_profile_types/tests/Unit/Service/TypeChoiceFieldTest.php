<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Enum\ChoiceView;
use Tygh\Addons\EuphProfileTypes\Service\TypeChoiceField;

final class TypeChoiceFieldTest extends TestCase
{
    public function testItIsADefaultFieldPostedUnderTheTypeColumn(): void
    {
        $field = TypeChoiceField::build(['P' => 'Private person', 'B' => 'Company'], ChoiceView::Radio, 'Profile type', 'U');

        self::assertSame('euph_profile_type', $field['field_name']);
        self::assertSame('Y', $field['is_default'], 'CS-Cart posts a default field as user_data[field_name]');
        self::assertSame('R', $field['field_type']);
        self::assertSame(['P' => 'Private person', 'B' => 'Company'], $field['values']);
        self::assertSame('Y', $field['required']);
        self::assertSame('C', $field['section']);
    }

    public function testTheDropDownView(): void
    {
        self::assertSame('S', TypeChoiceField::build(['B' => 'Company'], ChoiceView::Select, 'Profile type', 'U')['field_type']);
    }

    public function testTabsAreRadioButtonsTheStorefrontScriptDrawsAsPills(): void
    {
        self::assertSame('R', TypeChoiceField::build(['P' => 'Private person', 'B' => 'Company'], ChoiceView::Pills, 'Profile type', 'U')['field_type']);
        self::assertSame(ChoiceView::Pills, ChoiceView::from('pills'));
    }

    public function testItGoesFirstInTheContactInformationOnce(): void
    {
        $field = TypeChoiceField::build(['B' => 'Company'], ChoiceView::Radio, 'Profile type', 'U');
        $fields = ['C' => [30 => ['field_id' => 30]], 'B' => [40 => ['field_id' => 40]]];

        $once = TypeChoiceField::inject($fields, $field);
        $twice = TypeChoiceField::inject($once, $field);

        self::assertSame(['euph_profile_type', 30], array_keys($twice['C']));
        self::assertSame($fields['B'], $twice['B']);
    }

    public function testItCreatesTheContactInformationWhenThereIsNone(): void
    {
        $field = TypeChoiceField::build(['B' => 'Company'], ChoiceView::Radio, 'Profile type', 'U');

        self::assertSame(['C' => ['euph_profile_type' => $field]], TypeChoiceField::inject([], $field));
    }
}
