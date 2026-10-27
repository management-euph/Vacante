<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Enums\AvailabilityMode;
use Tygh\Addons\TravelCore\Repository\NotifyRequestRepository;
use Tygh\Addons\TravelCore\Services\AvailabilityPanel;
use Tygh\Addons\TravelCore\Services\NotifyRequestForm;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;
use Tygh\Registry;

/**
 * What the hotel page says about a hotel that cannot be booked now
 * (docs/novoton-availability-gaps-plan.md §7–8), and its "notify me" form.
 * Off for customers until the admin switches the panels on.
 */
final class AvailabilityPanelTest extends TestCase
{
    private const string TODAY = '2026-10-27';

    protected function setUp(): void
    {
        DbStub::reset();
        Registry::set('addons.travel_core.availability_panels', 'Y');
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        Registry::set('addons.travel_core.availability_panels', null);
    }

    /**
     * @param array<string, mixed> $record the hotel's availability record ([] = none)
     * @param list<array<string, mixed>> $bookable its destination's bookable hotels with an active product
     * @param array<int, int|null> $stars product id => stars
     */
    private static function panel(array $record, array $bookable = [], array $stars = [], AvailabilityMode $mode = AvailabilityMode::Report): AvailabilityPanel
    {
        DbStub::$getRow = static fn (): array => $record === [] ? [] : $record + [
            'provider' => 'novoton', 'hotel_id' => '476', 'product_id' => '15', 'destination_key' => 'BULGARIA|Sunny Beach',
            'hotel_name' => 'Sol Nessebar', 'season_published' => 'N', 'contract_from' => null, 'offer_months' => null,
        ];
        DbStub::$getArray = static fn (): array => $bookable;
        DbStub::$getField = static fn (): string => 'NVT476';

        return new AvailabilityPanel(
            static fn (int $productId): ?int => $stars[$productId] ?? null,
            static fn (): AvailabilityMode => $mode,
        );
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY . ' 10:00:00');
    }

    public function testBetweenSeasonsSaysPricesAreNotOutYetAndOffersToNotify(): void
    {
        $panel = self::panel(['state' => 'between_seasons', 'offer_months' => '{"2026-06":"2026-06-01","2026-09":"2026-08-30","2026-07":"2026-07-01","2027-07":"2026-10-20","2025-05":"2025-05-01"}'])
            ->forProduct(15, self::today());

        self::assertNotNull($panel);
        self::assertSame('travel_core.availability_panel_between_seasons', $panel['message']);
        self::assertSame('prices', $panel['notify']);
        self::assertSame(['from' => '2026-06', 'to' => '2026-09'], $panel['last_season'], 'past months of the last 13 only');
        self::assertSame(['provider' => 'novoton', 'hotel_id' => '476', 'hotel_name' => 'Sol Nessebar'], array_intersect_key($panel, array_flip(['provider', 'hotel_id', 'hotel_name'])));
    }

    public function testEachStateSaysItsOwnThing(): void
    {
        $say = static fn (array $record): ?array => self::panel($record)->forProduct(15, self::today());

        self::assertSame(['travel_core.availability_panel_not_offered', 'booking'], array_values(array_intersect_key((array) $say(['state' => 'not_offered']), ['message' => 1, 'notify' => 1])));
        self::assertSame(['travel_core.availability_panel_dormant', 'return'], array_values(array_intersect_key((array) $say(['state' => 'dormant']), ['message' => 1, 'notify' => 1])));
        self::assertSame(['travel_core.availability_panel_unavailable', ''], array_values(array_intersect_key((array) $say(['state' => 'left_feed']), ['message' => 1, 'notify' => 1])));
        self::assertSame(['travel_core.availability_panel_unavailable', ''], array_values(array_intersect_key((array) $say(['state' => 'closed']), ['message' => 1, 'notify' => 1])));

        $published = $say(['state' => 'between_seasons', 'season_published' => 'Y', 'contract_from' => '2027-05-01']);
        self::assertSame(['travel_core.availability_panel_published_from', '', '2027-05-01', []], [$published['message'] ?? '', $published['notify'] ?? '', $published['published_from'] ?? '', $published['similar'] ?? null]);
        self::assertNull($say(['state' => 'between_seasons', 'season_published' => 'Y', 'contract_from' => '2026-10-27']), 'published from today: the search says it');

        self::assertNull($say(['state' => 'bookable']));
        self::assertNull($say(['state' => 'no_data']));
        self::assertNull($say([]), 'no records yet');
    }

    public function testNoPanelWhileSwitchedOffOrForASupplierSetToOff(): void
    {
        Registry::set('addons.travel_core.availability_panels', 'N');
        self::assertNull(self::panel(['state' => 'not_offered'])->forProduct(15, self::today()));

        Registry::set('addons.travel_core.availability_panels', 'Y');
        self::assertNull(self::panel(['state' => 'not_offered'], [], [], AvailabilityMode::Off)->forProduct(15, self::today()));
        self::assertNotNull(self::panel(['state' => 'not_offered'], [], [], AvailabilityMode::Automatic)->forProduct(15, self::today()));
    }

    public function testSimilarHotelsAreBookableNearbyAndAtMostAStarApart(): void
    {
        $bookable = [
            ['product_id' => '20', 'hotel_name' => 'Two Stars', 'product_code' => 'NVT20'],
            ['product_id' => '21', 'hotel_name' => 'Four Stars', 'product_code' => 'NVT21'],
            ['product_id' => '22', 'hotel_name' => 'Unrated', 'product_code' => 'NVT22'],
            ['product_id' => '23', 'hotel_name' => 'Five Stars', 'product_code' => 'NVT23'],
            ['product_id' => '24', 'hotel_name' => 'Three Stars', 'product_code' => 'NVT24'],
        ];
        $panel = self::panel(['state' => 'not_offered'], $bookable, [15 => 4, 20 => 2, 21 => 4, 23 => 5, 24 => 3])->forProduct(15, self::today());

        self::assertSame([
            ['product_id' => 21, 'hotel_name' => 'Four Stars', 'stars' => 4],
            ['product_id' => 23, 'hotel_name' => 'Five Stars', 'stars' => 5],
            ['product_id' => 24, 'hotel_name' => 'Three Stars', 'stars' => 3],
        ], $panel['similar'] ?? null, 'two stars is too far; an unrated hotel comes only after those');
    }

    public function testLastSeasonIsTheRangeOfLastYearsOfferMonths(): void
    {
        $today = self::today();
        self::assertNull(AvailabilityPanel::lastSeason('', $today));
        self::assertNull(AvailabilityPanel::lastSeason('{"2027-07":"2026-10-20"}', $today), 'only next season');
        self::assertSame(['from' => '2025-12', 'to' => '2026-03'], AvailabilityPanel::lastSeason('{"2026-03":"x","2025-12":"x","2026-01":"x","2025-09":"x"}', $today), 'one month without offers stays in the season, two end it');
        self::assertSame(['from' => '2025-10', 'to' => '2026-09'], AvailabilityPanel::lastSeason((string) json_encode(array_fill_keys(['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'], 'x')), $today), 'open all year');
    }

    public function testTheFormStoresOneRequestWithConsentForWhatThePanelOffers(): void
    {
        $created = [];
        DbStub::$query = static function (string $query, mixed ...$params) use (&$created): int {
            if (str_starts_with($query, 'INSERT')) {
                $created[] = $params[0];
            }

            return 31;
        };
        $form = new NotifyRequestForm(self::panel(['state' => 'not_offered']), new NotifyRequestRepository());
        DbStub::$getField = static fn (string $query): string => str_contains($query, 'SHOW COLUMNS') ? 'present' : '';
        $session = ['auth' => ['user_id' => '7']];
        $input = ['product_id' => '15', 'email' => 'guest@example.com', 'consent' => 'Y'];
        $now = self::today();

        self::assertSame('no_consent', $form->submit(['consent' => ''] + $input, $session, $now, 'ro'));
        self::assertSame('invalid_email', $form->submit(['email' => 'not-an-address'] + $input, $session, $now, 'ro'));
        self::assertSame('saved', $form->submit(['website' => 'http://spam'] + $input, $session, $now, 'ro'), 'the honeypot pretends');
        self::assertSame([], $created);

        self::assertSame('saved', $form->submit($input, $session, $now, 'ro'));
        self::assertCount(1, $created);
        self::assertSame(['novoton', '476', 'Sol Nessebar', 'guest@example.com', 'notify', 'booking', 'ro', '2026-10-17', 15, 7], [
            $created[0]['provider'], $created[0]['hotel_id'], $created[0]['hotel_name'], $created[0]['contact_email'], $created[0]['request_type'],
            $created[0]['watch_for'], $created[0]['lang_code'], $created[0]['consent_version'], $created[0]['product_id'], $created[0]['user_id'],
        ]);

        self::assertSame('too_soon', $form->submit($input, $session, $now->modify('+30 seconds'), 'ro'), 'a pause between two requests');
        for ($i = 1; $i < NotifyRequestForm::PER_SESSION; $i++) {
            self::assertSame('saved', $form->submit($input, $session, $now->modify('+' . ($i * 2) . ' minutes'), 'ro'));
        }
        self::assertSame('too_soon', $form->submit($input, $session, $now->modify('+1 hour'), 'ro'), 'a few requests per visit');
    }

    public function testTheFormTakesNoRequestWhenThePanelOffersNone(): void
    {
        $session = [];
        $input = ['product_id' => '15', 'email' => 'guest@example.com', 'consent' => 'Y'];

        self::assertSame('unavailable', (new NotifyRequestForm(self::panel(['state' => 'left_feed'])))->submit($input, $session, self::today(), 'en'));
        self::assertSame('unavailable', (new NotifyRequestForm(self::panel(['state' => 'bookable'])))->submit($input, $session, self::today(), 'en'));
        Registry::set('addons.travel_core.availability_panels', 'N');
        self::assertSame('unavailable', (new NotifyRequestForm(self::panel(['state' => 'not_offered'])))->submit($input, $session, self::today(), 'en'));
    }
}
