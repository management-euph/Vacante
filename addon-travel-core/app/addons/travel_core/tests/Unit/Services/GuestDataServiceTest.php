<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\GuestDataService;

/**
 * Characterization coverage for GuestDataService's pure methods, added
 * alongside the boundary-typing paydown (mixed reads now coerced through
 * TypeCoerce). Pins api-name building, room grouping, validation, the
 * fill-empty merge, DOB parsing, holder resolution and the guest list.
 */
#[CoversClass(GuestDataService::class)]
class GuestDataServiceTest extends TestCase
{
    private GuestDataService $service;

    protected function setUp(): void
    {
        $this->service = new GuestDataService();
    }

    public function testFormatApiName(): void
    {
        $this->assertSame('John Doe', $this->service->formatApiName(['api_name' => 'John Doe']));
        $this->assertSame('John Doe', $this->service->formatApiName(['first_name' => 'John', 'last_name' => 'Doe']));
        $this->assertSame('Solo', $this->service->formatApiName(['name' => 'Solo']));
        $this->assertSame('Guest', $this->service->formatApiName([]));
    }

    public function testBuildGuestList(): void
    {
        $list = $this->service->buildGuestList([
            'g1' => ['name' => 'Alpha'],
            'g2' => ['api_name' => 'Beta'],
            'g3' => 'not-an-array',
        ]);

        $this->assertSame('Alpha, Beta', $list);
    }

    public function testGetHolderNamePrefersHolderFlag(): void
    {
        $name = $this->service->getHolderName([
            'g1' => ['name' => 'Other'],
            'g2' => ['name' => 'Holder', 'is_holder' => 1],
        ]);

        $this->assertSame('Holder', $name);
    }

    public function testGetHolderNameFallsBackToBookingData(): void
    {
        $this->assertSame('Booking Holder', $this->service->getHolderName([], ['holder_name' => 'Booking Holder']));
    }

    public function testGetGuestsByRoomGroupsByRoomNumber(): void
    {
        $byRoom = $this->service->getGuestsByRoom([
            'room1_adult_1' => ['name' => 'A'],
            'room1_adult_2' => ['name' => 'B'],
            'room2_adult_1' => ['name' => 'C'],
        ]);

        $this->assertSame([1, 2], array_keys($byRoom));
        $this->assertCount(2, $byRoom[1]);
        $this->assertCount(1, $byRoom[2]);
    }

    public function testValidateCountsAndFlagsShortNames(): void
    {
        $ok = $this->service->validate(['room1_adult_1' => ['name' => 'John', 'type' => 'adult']]);
        $this->assertTrue($ok['valid']);
        $this->assertSame(1, $ok['adults']);
        $this->assertSame(0, $ok['children']);

        $bad = $this->service->validate(['g' => ['name' => 'X', 'type' => 'adult']]);
        $this->assertFalse($bad['valid']);
        $this->assertNotEmpty($bad['errors']);
    }

    public function testMergeFillsEmptyFieldsFromLaterSources(): void
    {
        $merged = $this->service->merge(
            ['g' => ['name' => 'John', 'first_name' => '']],
            ['g' => ['name' => 'John', 'first_name' => 'Johnny']],
        );

        $this->assertSame('Johnny', $merged['g']['first_name']);
    }

    public function testParseDob(): void
    {
        $this->assertSame('1990-05-15', GuestDataService::parseDob(['dob' => '15/05/1990']));
        $this->assertSame('1990-05-15', GuestDataService::parseDob(['dob' => '1990-05-15']));
        $this->assertSame('2000-01-02', GuestDataService::parseDob(['dob_day' => '2', 'dob_month' => '1', 'dob_year' => '2000']));
        $this->assertSame('', GuestDataService::parseDob(['dob' => 'not-a-date']));
    }

    public function testParseGuestsDataEmpty(): void
    {
        $this->assertSame([], $this->service->parseGuestsData([]));
    }

    public function testParseAndValidateGuestsRecordsDeclaredAndCheckinAges(): void
    {
        // Child born 2019-07-25: 6 at a 2026-07-24 check-in (birthday the day
        // after), while the form declared 7 — a real mismatch case.
        $parsed = GuestDataService::parseAndValidateGuests([
            'room1_adult_1' => ['first_name' => 'Ana', 'last_name' => 'Pop', 'type' => 'adult', 'is_holder' => '1'],
            'room1_child_1' => ['first_name' => 'Mia', 'last_name' => 'Pop', 'type' => 'child', 'age' => '7', 'dob' => '25/07/2019'],
        ], '2026-07-24', 'sphinx');

        $this->assertIsArray($parsed);
        $child = $parsed['guests_data']['room1_child_1'];
        $this->assertSame(7, $child['declared_age']);
        $this->assertSame(6, $child['age_at_checkin']);

        // Adults carry the keys too, but both stay null when nothing applies.
        $adult = $parsed['guests_data']['room1_adult_1'];
        $this->assertNull($adult['age_at_checkin']);
        $this->assertNull($adult['declared_age']);
    }

    public function testFindChildAgeMismatchFlagsDivergentChild(): void
    {
        $parsed = GuestDataService::parseAndValidateGuests([
            'room1_child_1' => ['first_name' => 'Mia', 'last_name' => 'Pop', 'type' => 'child', 'age' => '7', 'dob' => '25/07/2019'],
        ], '2026-07-24', 'sphinx');
        $this->assertIsArray($parsed);

        $mismatch = GuestDataService::findChildAgeMismatch($parsed['guests_data']);

        $this->assertNotNull($mismatch);
        $this->assertSame('room1_child_1', $mismatch['key']);
        $this->assertSame(7, $mismatch['declared_age']);
        $this->assertSame(6, $mismatch['age_at_checkin']);
    }

    public function testFindChildAgeMismatchAcceptsMatchingAndUndeclared(): void
    {
        // Born ON check-in day 2019 → exactly 7 at check-in; declared 7 → OK.
        $matching = GuestDataService::parseAndValidateGuests([
            'room1_child_1' => ['first_name' => 'Mia', 'last_name' => 'Pop', 'type' => 'child', 'age' => '7', 'dob' => '24/07/2019'],
        ], '2026-07-24', 'sphinx');
        $this->assertIsArray($matching);
        $this->assertNull(GuestDataService::findChildAgeMismatch($matching['guests_data']));

        // No declared age posted → nothing to validate against (must NOT be
        // treated as declared age 0).
        $undeclared = GuestDataService::parseAndValidateGuests([
            'room1_child_1' => ['first_name' => 'Mia', 'last_name' => 'Pop', 'type' => 'child', 'dob' => '24/07/2019'],
        ], '2026-07-24', 'sphinx');
        $this->assertIsArray($undeclared);
        $this->assertNull(GuestDataService::findChildAgeMismatch($undeclared['guests_data']));

        // No DOB → age_at_checkin unknown → skipped.
        $noDob = GuestDataService::parseAndValidateGuests([
            'room1_child_1' => ['first_name' => 'Mia', 'last_name' => 'Pop', 'type' => 'child', 'age' => '7'],
        ], '2026-07-24', 'sphinx');
        $this->assertIsArray($noDob);
        $this->assertNull(GuestDataService::findChildAgeMismatch($noDob['guests_data']));

        // Adults never flag, even with DOB + age posted.
        $adult = GuestDataService::parseAndValidateGuests([
            'room1_adult_1' => ['first_name' => 'Ana', 'last_name' => 'Pop', 'type' => 'adult', 'age' => '30', 'dob' => '01/01/1990'],
        ], '2026-07-24', 'sphinx');
        $this->assertIsArray($adult);
        $this->assertNull(GuestDataService::findChildAgeMismatch($adult['guests_data']));
    }

    /** The gender a form asks (Sphinx packages) is kept; a form that does not ask it adds none. */
    public function testParseAndValidateGuestsKeepsAPostedGender(): void
    {
        $parsed = GuestDataService::parseAndValidateGuests([
            'room1_adult_1' => ['first_name' => 'Ana', 'last_name' => 'Pop', 'type' => 'adult', 'gender' => 'f'],
            'room1_adult_2' => ['name' => 'Ion Pop', 'type' => 'adult', 'gender' => ' m '],
            'room1_child_1' => ['first_name' => 'Mia', 'last_name' => 'Pop', 'type' => 'child', 'dob' => '24/07/2019'],
        ], '2026-07-24', 'sphinx');

        $this->assertIsArray($parsed);
        $this->assertSame('f', $parsed['guests_data']['room1_adult_1']['gender']);
        $this->assertSame('m', $parsed['guests_data']['room1_adult_2']['gender']);
        $this->assertArrayNotHasKey('gender', $parsed['guests_data']['room1_child_1']);
    }
}
