<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Enum;

/**
 * Setting "Allow user to change type after registration". The administrator
 * changes the type in the panel in any case.
 */
enum TypeChangePolicy: string
{
    case Always = 'always';
    case Once = 'once';
    case Never = 'never';
}
