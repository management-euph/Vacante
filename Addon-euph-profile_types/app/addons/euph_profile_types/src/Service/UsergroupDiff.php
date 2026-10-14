<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

/**
 * The user groups to leave and to join when a user moves from one type to
 * another. A group both types grant is left as it is, so an approved
 * membership is not put back to "pending".
 */
final class UsergroupDiff
{
    /**
     * @param list<int> $from
     * @param list<int> $to
     *
     * @return array{remove: list<int>, add: list<int>}
     */
    public static function between(array $from, array $to): array
    {
        return [
            'remove' => array_values(array_diff($from, $to)),
            'add' => array_values(array_diff($to, $from)),
        ];
    }
}
