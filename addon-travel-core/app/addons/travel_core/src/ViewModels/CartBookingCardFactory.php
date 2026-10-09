<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\Services\DepositCartLine;
use Tygh\Addons\TravelCore\Services\HotelLocationLine;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * The booking card on cart and checkout lines (components/cart_booking_details.tpl),
 * for every provider.
 *
 * The template used to derive everything itself: it summed room occupancy,
 * decoded guests_data, picked the edit dispatch and formatted dates with a
 * raw date_format — and still printed "Double Room (DBL 2+0 DELUXE NO
 * BALCONY)", "1 Room for 2 Adults" next to "Occupancy: 2 Adults", and a
 * US-format date with no weekday. Here every value is prepared once, so the
 * markup carries no provider branch and no arithmetic:
 *
 *  - hotel identity: the booked name, stars and "city, region, country" from
 *    the provider that owns the product (HotelSeoData), the product image;
 *  - dates in the store's format with the weekday as its own label (the
 *    booking sidebar's rule), nights and the per-night share;
 *  - room and board names split from their supplier code, so the code can
 *    sit muted under the name instead of shouting next to it;
 *  - the guest list, lead guest first-class, rooms of a multi-room booking;
 *  - the cancellation headline and the full timeline, from the provider's
 *    own terms (TermsTimelineFactory — "free until" only while still ahead);
 *  - the deposit split and a supplier price correction, when present.
 *
 * Pure: the formatter, today, the date format, the owner record, the image
 * pair and the provider terms are all passed in (func.php gathers them).
 */
final class CartBookingCardFactory
{
    public const CANCEL_FREE = 'free';
    public const CANCEL_PARTIAL = 'partial';
    public const CANCEL_FULL = 'full';

    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly string $today,
        private readonly string $dateFormat = '%d.%m.%Y',
    ) {
    }

    /**
     * @param array<string, mixed> $line cart line: product_id, product, price, extra
     * @param string $cartId the line's key in cart.products (edit link)
     * @param HotelSeoData|null $hotel the owning provider's hotel record
     * @param array<string, mixed> $imagePair CS-Cart image pair of the product
     * @param array<string, mixed> $terms provider terms (TravelProviderRegistry::cartTerms()):
     *                                    cancel_windows / payment_rows (TermsTimelineFactory input),
     *                                    cancel_lines / payment_lines (prose fallback)
     * @return array<string, mixed> empty when the line is not a travel booking
     */
    public function build(
        array $line,
        string $cartId = '',
        ?HotelSeoData $hotel = null,
        array $imagePair = [],
        array $terms = [],
    ): array {
        $extra = TypeCoerce::toStringMap($line['extra'] ?? null);
        if (empty($extra['travel_booking'])) {
            return [];
        }

        $deposit = DepositCartLine::amounts(['extra' => $extra]);
        // What the stay costs: a deposit line charges the deposit, the full
        // price rides on the plan. Both are primary-currency amounts.
        $stayTotal = $deposit !== [] ? $deposit['full'] : TypeCoerce::toFloat($line['price'] ?? 0);
        $nights = self::nights($extra);
        $perNight = BookingSidebarFactory::perNight($this->money->toDisplay($stayTotal), $nights);

        $roomsData = self::rows($extra['rooms_data'] ?? null);
        $guests = self::guests($extra['guests_data'] ?? null);
        [$adults, $children] = self::occupancy($extra, $roomsData);
        $childrenAges = self::childrenAges($extra['children_ages'] ?? null);
        if ($children === 0 && $childrenAges !== '') {
            // eurosite lines carry the ages but no count.
            $children = count(explode(', ', $childrenAges));
        }
        $numRooms = max(1, TypeCoerce::toInt($extra['num_rooms'] ?? 1), count($roomsData));
        $room = self::splitCode(self::firstString($extra, ['room_type_display', 'room_name', 'room_id']));
        $roomNames = array_map(
            static fn (array $r): array => ['room_name' => self::splitCode(self::firstString($r, ['room_type_display', 'room_name', 'room_id']))['name']],
            $roomsData,
        );

        $timelineInput = self::termsInput($terms);
        $timeline = new TermsTimelineFactory($this->money, $this->today, $this->dateFormat);
        $cancellation = $timeline->cancellation($timelineInput['cancel_windows'], $stayTotal, $nights);
        $paymentSteps = $timeline->payment($timelineInput['payment_rows'], $stayTotal);

        $leadGuest = '';
        foreach ($guests as $guest) {
            if ($guest['is_holder']) {
                $leadGuest = $guest['name'];
                break;
            }
        }
        if ($leadGuest === '') {
            $leadGuest = $guests[0]['name'] ?? trim(TypeCoerce::toString($extra['holder_name'] ?? ''));
        }

        return [
            'hotel' => $this->hotel($line, $extra, $hotel, $imagePair),
            'check_in' => $this->date($extra['check_in'] ?? null),
            'check_out' => $this->date($extra['check_out'] ?? null),
            'show_weekday' => !DateHelper::formatHasWeekday($this->dateFormat),
            'nights' => $nights,
            // What the stay costs (the full price on a deposit line).
            'price' => $stayTotal > 0 ? $this->money->format($stayTotal) : '',
            'per_night' => $perNight !== null ? $this->money->formatDisplay($perNight) : '',
            'rooms' => $numRooms,
            'adults' => $adults,
            'children' => $children,
            'children_ages' => $childrenAges,
            'room' => $room,
            'room_lines' => BookingSidebarFactory::roomLines($roomNames, $room['name']),
            'board' => self::splitCode(self::firstString($extra, ['board_name', 'board_id']))['name'],
            'room_list' => $numRooms > 1 ? $this->roomList($roomsData, $guests) : [],
            'guests' => $guests,
            'guest_count' => count($guests),
            'lead_guest' => $leadGuest,
            'cancel' => self::cancelSummary($cancellation),
            // booking_terms_timeline.tpl's input, every key it reads present.
            'terms' => [
                'cancel_steps' => $cancellation['steps'],
                'payment_steps' => $paymentSteps,
                // Prose only when the provider's terms produced no timeline.
                'cancel_lines' => $cancellation['steps'] === [] ? $timelineInput['cancel_lines'] : [],
                'cancel_free_until' => '',
                'cancel_full_amount' => '',
                'payment_lines' => $paymentSteps === [] ? $timelineInput['payment_lines'] : [],
                'payment_lines_html' => $paymentSteps === []
                    ? array_map(BookingSidebarFactory::emphasizePercentages(...), $timelineInput['payment_lines'])
                    : [],
            ],
            'has_terms' => $cancellation['steps'] !== [] || $paymentSteps !== []
                || $timelineInput['cancel_lines'] !== [] || $timelineInput['payment_lines'] !== [],
            'deposit' => $deposit !== [] ? $this->deposit($deposit) : [],
            'price_change' => $this->priceChange($extra),
            'edit_url' => self::editUrl($extra, $cartId),
        ];
    }

    /**
     * "Double Room (DBL 2+0 DELUXE NO BALCONY)" → name "Double Room", code
     * "DBL 2+0 DELUXE NO BALCONY". A value without a trailing parenthesis,
     * or whose parenthesis only repeats the name, is all name.
     *
     * @return array{name: string, code: string}
     */
    public static function splitCode(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^(.*\S)\s*\(([^()]+)\)$/u', $value, $m) !== 1) {
            return ['name' => $value, 'code' => ''];
        }
        $name = trim($m[1]);
        $code = trim($m[2]);
        if (mb_strtolower($code, 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
            $code = '';
        }

        return ['name' => $name, 'code' => $code];
    }

    /**
     * The edit-guests link as `dispatch?query` (the template applies fn_url).
     * sphinx lines say so in travel_provider; every other line with a booking
     * id is novoton's (eurosite lines carry none, so they get no link).
     *
     * @param array<string, mixed> $extra
     */
    public static function editUrl(array $extra, string $cartId): string
    {
        $bookingId = TypeCoerce::toInt($extra['travel_booking_id'] ?? 0);
        if ($bookingId <= 0) {
            $bookingId = TypeCoerce::toInt($extra['novoton_booking_id'] ?? 0);
        }
        if ($bookingId <= 0) {
            return '';
        }
        $dispatch = TypeCoerce::toString($extra['travel_provider'] ?? '') === 'sphinx'
            ? 'sphinx_booking.edit_booking'
            : 'novoton_booking.edit_booking';

        return $dispatch . '?' . http_build_query(['booking_id' => $bookingId, 'cart_id' => $cartId]);
    }

    /**
     * The cancellation headline of the card, from the timeline: free until a
     * date (and what applies after it), or what cancelling costs today.
     *
     * @param array{steps: list<array<string, mixed>>, free_until: string, full_charge_now: bool} $cancellation
     * @return array{state: string, free_until: string, now: array<string, mixed>, then: array<string, mixed>}
     */
    public static function cancelSummary(array $cancellation): array
    {
        $steps = $cancellation['steps'];
        $currentIndex = null;
        foreach ($steps as $i => $step) {
            if (($step['is_current'] ?? false) === true) {
                $currentIndex = $i;
                break;
            }
        }
        $summary = ['state' => '', 'free_until' => '', 'now' => [], 'then' => []];
        if ($currentIndex === null) {
            return $summary;
        }
        $current = $steps[$currentIndex];
        $next = $steps[$currentIndex + 1] ?? [];
        if ($cancellation['free_until'] !== '') {
            $summary['state'] = self::CANCEL_FREE;
            $summary['free_until'] = $cancellation['free_until'];
            $summary['then'] = $next !== [] && ($next['is_no_show'] ?? false) !== true ? $next : [];

            return $summary;
        }
        $kind = TypeCoerce::toString($current['kind'] ?? '');
        if ($kind === TermsTimelineFactory::KIND_FULL || $cancellation['full_charge_now']) {
            $summary['state'] = self::CANCEL_FULL;
            $summary['now'] = $current;
        } elseif ($kind === TermsTimelineFactory::KIND_PARTIAL) {
            $summary['state'] = self::CANCEL_PARTIAL;
            $summary['now'] = $current;
        }

        return $summary;
    }

    /**
     * Guests from guests_data (JSON or array; keyed "room1_adult_1" or a
     * list), in the order entered. The name is the provider's own display
     * name, else "last first", else empty (the template numbers it).
     *
     * @return list<array{name: string, is_holder: bool, is_child: bool, age: int, room: int}>
     */
    public static function guests(mixed $guestsData): array
    {
        if (is_string($guestsData)) {
            $guestsData = json_decode($guestsData, true);
        }
        if (!is_array($guestsData)) {
            return [];
        }
        $out = [];
        foreach ($guestsData as $guest) {
            if (!is_array($guest)) {
                continue;
            }
            $g = TypeCoerce::toStringMap($guest);
            // display_name: the order's formatted "Last, First" (travel_core
            // get_order_info); name: what the provider stored.
            $name = trim(TypeCoerce::toString($g['display_name'] ?? ''));
            if ($name === '') {
                $name = trim(TypeCoerce::toString($g['name'] ?? ''));
            }
            if ($name === '') {
                $name = trim(TypeCoerce::toString($g['last_name'] ?? '') . ' ' . TypeCoerce::toString($g['first_name'] ?? ''));
            }
            $age = TypeCoerce::toInt($g['age'] ?? 0);
            $out[] = [
                'name' => $name,
                'is_holder' => !empty($g['is_holder']),
                'is_child' => strtolower(TypeCoerce::toString($g['type'] ?? '')) === 'child',
                'age' => $age > 0 && $age < 18 ? $age : 0,
                'room' => max(0, TypeCoerce::toInt($g['room'] ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $imagePair
     * @return array{name: string, stars: int, location: string, image_pair: array<string, mixed>, product_id: int}
     */
    private function hotel(array $line, array $extra, ?HotelSeoData $hotel, array $imagePair): array
    {
        $name = trim(TypeCoerce::toString($extra['hotel_name'] ?? ''));
        if ($name === '' && $hotel !== null) {
            $name = trim($hotel->name);
        }
        if ($name === '') {
            $name = trim(strip_tags(TypeCoerce::toString($line['product'] ?? '')));
        }

        // Destination style only ("city, region, country"): a street
        // address is too long for a sidebar card.
        $location = $hotel !== null
            ? HotelLocationLine::build(new HotelSeoData(
                hotelId: $hotel->hotelId,
                providerName: $hotel->providerName,
                name: $hotel->name,
                city: $hotel->city,
                region: $hotel->region,
                country: $hotel->country,
            ))
            : '';
        if ($location === '') {
            // novoton's cart lines carry the destination themselves.
            $location = HotelLocationLine::build(new HotelSeoData(
                hotelId: '',
                providerName: '',
                name: $name,
                city: TypeCoerce::toString($extra['hotel_city'] ?? ''),
                region: TypeCoerce::toString($extra['hotel_region'] ?? ''),
                country: TypeCoerce::toString($extra['hotel_country'] ?? ''),
            ));
        }

        return [
            'name' => $name,
            'stars' => $hotel !== null ? min(5, max(0, $hotel->classification ?? 0)) : 0,
            'location' => $location,
            'image_pair' => $imagePair,
            'product_id' => max(0, TypeCoerce::toInt($line['product_id'] ?? 0)),
        ];
    }

    /** @return array{date: string, weekday: string} */
    private function date(mixed $value): array
    {
        $raw = TypeCoerce::toString($value);
        $ts = DateHelper::toTimestamp($raw);
        if ($ts === null) {
            return ['date' => $raw, 'weekday' => ''];
        }

        return [
            'date' => DateHelper::formatWith($ts, $this->dateFormat),
            'weekday' => DateHelper::formatWith($ts, '%A'),
        ];
    }

    /**
     * Rooms of a multi-room booking, each with its own guests.
     *
     * @param list<array<string, mixed>> $roomsData
     * @param list<array{name: string, is_holder: bool, is_child: bool, age: int, room: int}> $guests
     * @return list<array<string, mixed>>
     */
    private function roomList(array $roomsData, array $guests): array
    {
        // Guests saved without a room number belong to the first room
        // rather than to none.
        if ($guests !== [] && max(array_column($guests, 'room')) === 0) {
            $guests = array_map(static fn (array $g): array => ['room' => 1] + $g, $guests);
        }
        $out = [];
        foreach ($roomsData as $i => $room) {
            $number = $i + 1;
            $split = self::splitCode(self::firstString($room, ['room_type_display', 'room_name', 'room_id']));
            $price = TypeCoerce::toFloat($room['price'] ?? 0);
            // sphinx hotels: children_ages; novoton: children_ages_str;
            // eurosite and sphinx circuits: childrenAges (a list).
            $ages = self::childrenAges(match (true) {
                is_array($room['children_ages'] ?? null) => $room['children_ages'],
                is_array($room['childrenAges'] ?? null) => $room['childrenAges'],
                default => self::firstString($room, ['children_ages', 'children_ages_str']),
            });
            $children = TypeCoerce::toInt($room['children'] ?? 0);
            if ($children === 0 && $ages !== '') {
                $children = count(explode(', ', $ages));
            }
            $out[] = [
                'number' => $number,
                'name' => $split['name'],
                'code' => $split['code'],
                'price' => $price > 0 ? $this->money->format($price) : '',
                'adults' => TypeCoerce::toInt($room['adults'] ?? 0),
                'children' => $children,
                'children_ages' => $ages,
                'board' => self::splitCode(self::firstString($room, ['board_name', 'board_id']))['name'],
                'guests' => array_values(array_filter(
                    $guests,
                    static fn (array $g): bool => $g['room'] === $number,
                )),
            ];
        }

        return $out;
    }

    /**
     * @param array{ratio: float, balance_due: string, full: float, deposit: float, balance: float} $amounts
     * @return array{full: string, deposit: string, balance: string, balance_due: string, percent: int}
     */
    private function deposit(array $amounts): array
    {
        $due = DateHelper::toTimestamp($amounts['balance_due']);

        return [
            'full' => $this->money->format($amounts['full']),
            'deposit' => $this->money->format($amounts['deposit']),
            'balance' => $this->money->format($amounts['balance']),
            'balance_due' => $due !== null ? DateHelper::formatWith($due, $this->dateFormat) : '',
            'percent' => $amounts['full'] > 0
                ? (int) round(min(100.0, max(0.0, $amounts['deposit'] / $amounts['full'] * 100)))
                : 0,
        ];
    }

    /**
     * The pre-order verifier's correction: both providers write
     * extra.price_before_correction next to the new extra.total_price.
     *
     * @param array<string, mixed> $extra
     * @return array{old: string, new: string, up: bool}|array{}
     */
    private function priceChange(array $extra): array
    {
        $old = TypeCoerce::toFloat($extra['price_before_correction'] ?? 0);
        $new = TypeCoerce::toFloat($extra['total_price'] ?? 0);
        if ($old <= 0 || abs($old - $new) < 0.005) {
            return [];
        }

        return [
            'old' => $this->money->format($old),
            'new' => $this->money->format($new),
            'up' => $new > $old,
        ];
    }

    /**
     * @param array<string, mixed> $terms
     * @return array{cancel_windows: list<array<string, mixed>>, payment_rows: list<array<string, mixed>>, cancel_lines: list<string>, payment_lines: list<string>}
     */
    private static function termsInput(array $terms): array
    {
        $lines = static fn (mixed $v): array => array_values(array_filter(
            array_map('trim', TypeCoerce::toStringList($v)),
            static fn (string $l): bool => $l !== '',
        ));

        return [
            'cancel_windows' => TypeCoerce::toRowList($terms['cancel_windows'] ?? null),
            'payment_rows' => TypeCoerce::toRowList($terms['payment_rows'] ?? null),
            'cancel_lines' => $lines($terms['cancel_lines'] ?? null),
            'payment_lines' => $lines($terms['payment_lines'] ?? null),
        ];
    }

    /** @param array<string, mixed> $extra */
    private static function nights(array $extra): int
    {
        $nights = TypeCoerce::toInt($extra['nights'] ?? 0);
        if ($nights > 0) {
            return $nights;
        }

        return DateHelper::calculateNights(
            TypeCoerce::toString($extra['check_in'] ?? ''),
            TypeCoerce::toString($extra['check_out'] ?? ''),
        );
    }

    /**
     * Adults / children across every room, else the line's own counts.
     *
     * @param array<string, mixed> $extra
     * @param list<array<string, mixed>> $roomsData
     * @return array{0: int, 1: int}
     */
    private static function occupancy(array $extra, array $roomsData): array
    {
        if ($roomsData !== []) {
            $o = BookingSidebarFactory::occupancy($roomsData);
            if ($o['adults'] > 0) {
                return [$o['adults'], $o['children']];
            }
        }

        return [TypeCoerce::toInt($extra['adults'] ?? 0), TypeCoerce::toInt($extra['children'] ?? 0)];
    }

    /** "7,5" / [7, 5] / "7, 5 years old" → "7, 5" (ages only). */
    private static function childrenAges(mixed $value): string
    {
        $parts = is_array($value) ? TypeCoerce::toStringList($value) : explode(',', TypeCoerce::toString($value));
        $ages = [];
        foreach ($parts as $part) {
            if (preg_match('/\d+/', $part, $m) === 1) {
                $ages[] = $m[0];
            }
        }

        return implode(', ', $ages);
    }

    /**
     * rooms_data arrives as a JSON string (sphinx, eurosite) or an array.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return TypeCoerce::toRowList($value);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private static function firstString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim(TypeCoerce::toString($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
