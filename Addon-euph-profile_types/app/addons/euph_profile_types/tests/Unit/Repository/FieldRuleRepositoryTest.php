<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphProfileTypes\Model\FieldRule;
use Tygh\Addons\EuphProfileTypes\Repository\FieldRuleRepository;
use Tygh\Addons\EuphProfileTypes\Tests\Support\FakeDatabase;

final class FieldRuleRepositoryTest extends TestCase
{
    private const GENERAL = ['profile_show' => 'Y', 'profile_required' => 'N', 'checkout_show' => 'Y', 'checkout_required' => 'N', 'storefront_show' => 'N'];

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{FakeDatabase, FieldRuleRepository}
     */
    private static function repository(array $rows = []): array
    {
        $db = new FakeDatabase();
        $db->rows['FROM ?:euph_profile_type_fields'] = $rows;

        return [$db, new FieldRuleRepository($db)];
    }

    public function testTickingAFieldForOneTypeKeepsItForTheOthers(): void
    {
        [$db, $repository] = self::repository();

        $repository->replaceForType(2, [30 => new FieldRule(2, 30, profileShow: true, profileRequired: true)], [1, 2, 3], [30 => self::GENERAL]);

        $seeded = $db->writes('REPLACE INTO ?:euph_profile_type_fields');
        self::assertCount(3, $seeded, 'types 1 and 3 seeded from the general checkboxes, then type 2');
        self::assertSame([1, 3, 2], array_map(static fn (array $write): int => $write['params'][0]['type_id'], $seeded));
        self::assertSame('Y', $seeded[0]['params'][0]['registration_show'], 'registration was like the profile');
        self::assertSame('Y', $seeded[2]['params'][0]['profile_required']);
    }

    public function testAGovernedFieldIsNotSeededAgain(): void
    {
        [$db, $repository] = self::repository([
            (new FieldRule(1, 30, profileShow: true))->toRow(),
        ]);

        $repository->replaceForType(2, [30 => new FieldRule(2, 30, checkoutShow: true)], [1, 2, 3], [30 => self::GENERAL]);

        self::assertCount(1, $db->writes('REPLACE INTO ?:euph_profile_type_fields'), 'only type 2: type 3 sees nothing, as before');
    }

    public function testUntickingEverythingOnlyDeletes(): void
    {
        [$db, $repository] = self::repository([(new FieldRule(2, 30, profileShow: true))->toRow()]);

        $repository->replaceForType(2, [30 => new FieldRule(2, 30)], [1, 2], [30 => self::GENERAL]);

        self::assertCount(1, $db->writes('DELETE FROM ?:euph_profile_type_fields WHERE type_id'));
        self::assertSame([], $db->writes('REPLACE INTO'));
    }

    public function testTheFieldCardReplacesEveryRuleOfTheFieldAndItsTwin(): void
    {
        [$db, $repository] = self::repository();

        $repository->replaceForFields([40, 41], [
            1 => new FieldRule(1, 40, profileShow: true),
            2 => new FieldRule(2, 40),
            3 => new FieldRule(3, 40, checkoutShow: true),
        ]);

        self::assertCount(2, $db->writes('DELETE FROM ?:euph_profile_type_fields WHERE field_id'));
        $stored = array_map(static fn (array $write): array => [$write['params'][0]['type_id'], $write['params'][0]['field_id']], $db->writes('REPLACE INTO'));
        self::assertSame([[1, 40], [3, 40], [1, 41], [3, 41]], $stored, 'the empty rule of type 2 is not stored');
    }

    public function testANewTypeStartsWithTheFormOfAnother(): void
    {
        [$db, $repository] = self::repository([
            (new FieldRule(1, 30, profileShow: true))->toRow(),
            (new FieldRule(1, 31, checkoutShow: true))->toRow(),
            (new FieldRule(2, 31, checkoutShow: true))->toRow(),
        ]);

        $repository->copyType(1, 9);

        $copied = array_map(static fn (array $write): array => [$write['params'][0]['type_id'], $write['params'][0]['field_id']], $db->writes('REPLACE INTO'));
        self::assertSame([[9, 30], [9, 31]], $copied);
    }

    public function testRulesOfDeletedFieldsAreDropped(): void
    {
        [$db, $repository] = self::repository();
        $db->columns['LEFT JOIN ?:profile_fields'] = ['55', 56];

        self::assertSame(2, $repository->deleteOrphans());
        self::assertSame([[55, 56]], array_map(static fn (array $write): mixed => $write['params'][0], $db->writes('field_id IN')));
    }

    public function testPuttingAnEmptyRuleRemovesIt(): void
    {
        [$db, $repository] = self::repository();

        $repository->put(new FieldRule(2, 30));
        $repository->put(new FieldRule(2, 31, profileShow: true));

        self::assertCount(1, $db->writes('DELETE FROM'));
        self::assertCount(1, $db->writes('REPLACE INTO'));
    }
}
