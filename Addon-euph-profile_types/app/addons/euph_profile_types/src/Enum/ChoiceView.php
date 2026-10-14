<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Enum;

/**
 * Setting "Profile type choice view". The choice is drawn by CS-Cart's own
 * profile field templates, so the view maps to a profile field type. Tabs
 * (pills) are radio buttons that the storefront script draws as a
 * segmented control: without the script they stay radio buttons.
 */
enum ChoiceView: string
{
    case Radio = 'radio';
    case Select = 'select';
    case Pills = 'pills';

    /** ProfileFieldTypes::RADIO or ProfileFieldTypes::SELECT_BOX. */
    public function fieldType(): string
    {
        return match ($this) {
            self::Radio, self::Pills => 'R',
            self::Select => 'S',
        };
    }
}
