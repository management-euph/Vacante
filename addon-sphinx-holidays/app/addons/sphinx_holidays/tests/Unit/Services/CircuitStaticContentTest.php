<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\CircuitStaticContent;

/**
 * What the circuits sync keeps of a circuit of static/circuits for its page
 * (docs/circuit-page-plan.md §4). It kept the inclusions, the notes, the
 * group and the age; the difficulty, the hotel, the optional excursions, the
 * documents and the photos were dropped, and the list's departure cities
 * ({date, destination_id}) were not read.
 */
final class CircuitStaticContentTest extends TestCase
{
    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private static function details(array $raw): array
    {
        $json = CircuitStaticContent::details($raw);
        self::assertNotNull($json);

        /** @var array<string, mixed> $details */
        $details = json_decode($json, true);

        return $details;
    }

    public function testThePagesDetailsAreKept(): void
    {
        $details = self::details([
            'included' => [['title' => 'Zbor dus-întors', 'description' => '', 'category' => 'transport'], ['title' => '', 'description' => '']],
            'not_included' => [['title' => 'Taxele locale', 'description' => 'Se achită la hotel.', 'category' => 'other']],
            'additional_info' => [['title' => 'Documente', 'description' => 'Carte de identitate valabilă.', 'category' => 'other']],
            'age_requirments' => ['min' => 18, 'max' => 60, 'description' => null, 'is_required' => true],
            'group_size' => ['min' => 18, 'max' => 40, 'description' => null],
            'tags' => [['id' => 1, 'name' => 'tag']],
        ]);

        self::assertSame([
            'included' => [['title' => 'Zbor dus-întors', 'description' => '', 'category' => 'transport']],
            'not_included' => [['title' => 'Taxele locale', 'description' => 'Se achită la hotel.']],
            'additional_info' => [['title' => 'Documente', 'description' => 'Carte de identitate valabilă.']],
            'group_size' => ['min' => 18, 'max' => 40],
            'age' => ['min' => 18, 'max' => 60],
        ], $details, 'an empty item is left out; a category is kept only when it says more than "other"');
    }

    public function testAnAgeTheCircuitDoesNotAskIsNotKept(): void
    {
        self::assertNull(CircuitStaticContent::details(['age_requirments' => ['min' => 18, 'max' => 60, 'is_required' => false]]));
        self::assertNull(CircuitStaticContent::details(['group_size' => ['min' => 0, 'max' => null], 'included' => []]), 'nothing to keep');
        self::assertNull(CircuitStaticContent::details([
            'difficulty' => ['level' => null, 'description' => null],
            'accommodation' => ['hotel' => '', 'category' => 0, 'description' => null],
            'activities' => [],
            'documents' => [],
            'images' => [],
        ]), 'every block empty');
    }

    /** As the API sends them for "Thailanda Malaezia Singapore" and "Malta". */
    public function testTheGroupTheDifficultyAndTheHotelAreKeptWithTheirNotes(): void
    {
        $details = self::details([
            'group_size' => ['min' => 10, 'max' => 25, 'description' => ' Grup mic. '],
            'difficulty' => ['level' => 1, 'description' => ''],
            'age_requirements' => ['min' => null, 'max' => null, 'description' => 'Recomandat adulților.', 'is_required' => false],
            'accommodation' => ['hotel' => 'Hotel 4* Thailanda Malaezia Singapore', 'category' => 4, 'description' => null],
        ]);

        self::assertSame(['min' => 10, 'max' => 25, 'description' => 'Grup mic.'], $details['group_size']);
        self::assertSame(['level' => 1], $details['difficulty']);
        self::assertSame(['description' => 'Recomandat adulților.'], $details['age'], 'no limit when the circuit does not require one; its note stays');
        self::assertSame(['hotel' => 'Hotel 4* Thailanda Malaezia Singapore', 'category' => '4'], $details['accommodation'], 'the category as sent: the page reads the stars from it');

        self::assertSame(['hotel' => 'Hotel Topaz', 'category' => '3', 'description' => "# Facilitati camere\nAer conditionat."], self::details([
            'accommodation' => ['hotel' => 'Hotel Topaz', 'category' => 3, 'description' => "# Facilitati camere\nAer conditionat."],
        ])['accommodation']);
        self::assertArrayNotHasKey('difficulty', self::details(['difficulty' => ['level' => 9], 'included' => [['title' => 'x']]]), '1 to 5 only');
    }

    public function testTheOptionalExcursionsKeepAPriceOnlyWhenSphinxKnowsIt(): void
    {
        $details = self::details(['activities' => [
            ['title' => 'Insula Gozo', 'description' => 'O zi pe insula.', 'duration' => '8', 'price' => ['amount' => 45, 'currency' => 'EUR', 'is_unknown' => false], 'category' => 'favorite', 'is_highlighted' => true],
            ['title' => 'Test activitate variabila', 'description' => 'Test activitate variabila', 'duration' => '0', 'price' => ['amount' => 0, 'currency' => 'RON', 'is_unknown' => true], 'category' => 'other', 'is_highlighted' => false],
            ['title' => 'Cu pret necunoscut', 'description' => '', 'price' => ['amount' => 120, 'currency' => 'EUR', 'is_unknown' => true]],
            ['title' => '', 'description' => ''],
        ]]);

        self::assertSame([
            ['title' => 'Insula Gozo', 'description' => 'O zi pe insula.', 'duration' => '8', 'price' => 45, 'currency' => 'EUR', 'category' => 'favorite', 'highlighted' => true],
            ['title' => 'Test activitate variabila', 'description' => 'Test activitate variabila'],
            ['title' => 'Cu pret necunoscut', 'description' => ''],
        ], $details['activities'], 'a duration of "0", an unknown price and an empty excursion say nothing');
    }

    public function testTheDocumentsAndThePhotosAreKeptByTheirWebLinks(): void
    {
        $details = self::details([
            'documents' => [
                ['title' => 'Malta, maretia unei tari mici', 'description' => '', 'url' => 'https://cdn.example/uploads/documents/1263-malta.pdf'],
                ['title' => 'Fara link', 'description' => '', 'url' => ''],
                ['title' => 'Alt protocol', 'url' => 'javascript:alert(1)'],
            ],
            'images' => [
                ['url' => 'https://cdn.example/circuit-images/1.jpg'],
                ['url' => 'https://cdn.example/circuit-images/2.jpg'],
                ['url' => 'https://cdn.example/circuit-images/1.jpg'],
                ['url' => 'ftp://cdn.example/3.jpg'],
                'https://cdn.example/circuit-images/4.jpg',
                ['url' => null],
            ],
        ]);

        self::assertSame([['title' => 'Malta, maretia unei tari mici', 'description' => '', 'url' => 'https://cdn.example/uploads/documents/1263-malta.pdf']], $details['documents'], 'only a file with an http(s) link');
        self::assertSame(
            ['https://cdn.example/circuit-images/1.jpg', 'https://cdn.example/circuit-images/2.jpg', 'https://cdn.example/circuit-images/4.jpg'],
            $details['images'],
            'each photo once, by its link',
        );
        self::assertStringContainsString('"https://cdn.example/circuit-images/1.jpg"', (string) CircuitStaticContent::details(['images' => [['url' => 'https://cdn.example/circuit-images/1.jpg']]]), 'links are stored as they are, not with escaped slashes');
    }

    public function testNoMoreThanFortyPhotosAreKept(): void
    {
        $images = [];
        for ($i = 1; $i <= 55; $i++) {
            $images[] = ['url' => "https://cdn.example/{$i}.jpg"];
        }

        $kept = self::details(['images' => $images])['images'];

        self::assertIsArray($kept);
        self::assertCount(40, $kept);
        self::assertSame('https://cdn.example/1.jpg', $kept[0]);
    }

    public function testTheCircuitsImageIsTheOneNamedElseItsFirstPhoto(): void
    {
        self::assertSame('https://cdn.example/main.jpg', CircuitStaticContent::imageUrl(['image' => 'https://cdn.example/main.jpg', 'images' => [['url' => 'https://cdn.example/1.jpg']]]));
        self::assertSame('https://cdn.example/1.jpg', CircuitStaticContent::imageUrl(['images' => [['url' => 'https://cdn.example/1.jpg'], ['url' => 'https://cdn.example/2.jpg']]]));
        self::assertSame('', CircuitStaticContent::imageUrl(['images' => []]));
        self::assertSame('', CircuitStaticContent::imageUrl(['images' => [['url' => 'https://cdn.example/' . str_repeat('a', 500) . '.jpg']]]), 'longer than the column: none, never a cut link');
    }

    /** The static list's departures are {date, time, destination_id}, and it keeps the past ones. */
    public function testTheDepartureCitiesAreThoseOfDeparturesFromTodayOn(): void
    {
        $raw = ['departures' => [
            ['date' => '2024-05-07', 'time' => null, 'destination_id' => 555],
            ['date' => '2027-05-04', 'time' => null, 'destination_id' => 202405],
            ['date' => '2027-05-04', 'time' => null, 'destination_id' => 197128],
            ['date' => '2027-05-11T00:00:00+03:00', 'time' => null, 'destination_id' => 202405],
            ['date' => '2026-11-16', 'time' => null, 'destination_id' => 300],
            ['date' => null, 'time' => null, 'destination_id' => 400],
            ['date' => '2027-06-01', 'time' => null, 'destination_id' => 0],
            ['id' => 111, 'name' => 'Cluj-Napoca'],
        ]];

        self::assertSame([202405, 197128, 300, 400], CircuitStaticContent::departureCities($raw, '2026-11-16'), 'each city once, today counts, a past departure does not; one without a date does');
        self::assertSame(
            ['2027-05-04' => 202405, '2027-05-11' => 202405, '2026-11-16' => 300],
            CircuitStaticContent::departureCityByDate($raw, '2026-11-16'),
            'the first city the list has for a day; only dated departures',
        );
        self::assertSame([], CircuitStaticContent::departureCities([], '2026-11-16'));
        self::assertSame([], CircuitStaticContent::departureCityByDate(['departures' => 'x'], '2026-11-16'));
    }
}
