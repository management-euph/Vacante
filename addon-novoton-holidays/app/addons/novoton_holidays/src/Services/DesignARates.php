<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\ViewModels\NovotonBookingSidebarBuilder;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Results\DesignA;
use Tygh\Addons\TravelCore\Results\RateOffer;
use Tygh\Addons\TravelCore\Services\DepositPlan;
use Tygh\Addons\TravelCore\Services\DepositPolicy;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;
use Tygh\Addons\TravelCore\ValueObjects\RoomType;
use Tygh\Addons\TravelCore\ViewModels\TermsTimelineFactory;

/**
 * Novoton's search rows → the rates of the Design A room cards (travel_core
 * Results\RateOffer; docs/hotel-page-design-a-plan.md phase 4).
 *
 * Each row is one room type + meal plan + package at its price for the
 * guests searched. What the card shows comes from the same fields and rules
 * as the current results (search.tpl) and the booking sidebar:
 *
 *  - the room's type (RoomType: "Cameră Dublă") and its title, the type
 *    with what tells it from the hotel's others, read from the rest of its
 *    code ("DBL 2+0 DELUXE NO BALCONY" → "Cameră Dublă Deluxe, fără
 *    balcon"); the code itself small under the title (IdRoom, the owner's
 *    call) and how many it sleeps from its occupancy ("2+1" → 3);
 *  - the meal plan by Novoton's own texts, ranked lightest first, and the
 *    package as Novoton names it;
 *  - the price charged (an extras promotion's, else the row's), the "was"
 *    price by NovotonBookingSidebarBuilder::discount(); the discount's name
 *    without its percent (the card puts the percent on the struck price)
 *    and, for Early Booking, its last day (EBToDate);
 *  - the hotel's room facilities (hotelinfo), the same for every room;
 *  - the terms as the shared timeline (TermsTimelineFactory): the last free
 *    cancellation day while ahead, full charge from today = non-refundable,
 *    the deposit DepositPlan allows;
 *  - quota 0 / RQ = on request; the booking link exactly as today's card.
 *
 * Amounts: rows are in the API currency with commission; the rates are in
 * the store's primary currency ($toPrimary).
 */
final class DesignARates
{
    /** Code words → [ro, en], longest phrase first. */
    private const array WORDS = [
        'SUPERIOR DELUXE' => ['Superior Deluxe', 'Superior Deluxe'],
        'PARTIAL SEA VIEW' => ['vedere parțială la mare', 'partial sea view'],
        'SIDE SEA VIEW' => ['vedere laterală la mare', 'side sea view'],
        'FRONT SEA VIEW' => ['vedere frontală la mare', 'sea front view'],
        'SEA VIEW' => ['vedere la mare', 'sea view'],
        'SEA SIDE' => ['spre mare', 'sea side'],
        'SEA FRONT' => ['la malul mării', 'sea front'],
        'POOL VIEW' => ['vedere la piscină', 'pool view'],
        'GARDEN VIEW' => ['vedere la grădină', 'garden view'],
        'PARK VIEW' => ['vedere la parc', 'park view'],
        'MOUNTAIN VIEW' => ['vedere la munte', 'mountain view'],
        'CITY VIEW' => ['vedere la oraș', 'city view'],
        'LAND VIEW' => ['vedere spre uscat', 'land view'],
        'NO BALCONY' => ['fără balcon', 'no balcony'],
        'WITHOUT BALCONY' => ['fără balcon', 'no balcony'],
        'MAIN BUILDING' => ['clădirea principală', 'main building'],
        'NON SMOKING' => ['nefumători', 'non-smoking'],
        'BALCONY' => ['cu balcon', 'balcony'],
        'TERRACE' => ['cu terasă', 'terrace'],
        'CONNECTING' => ['comunicante', 'connecting'],
        'ANNEX' => ['anexă', 'annex'],
        'ANNEXE' => ['anexă', 'annex'],
        'SV' => ['vedere la mare', 'sea view'],
        'PV' => ['vedere la piscină', 'pool view'],
        'GV' => ['vedere la grădină', 'garden view'],
        'ECO' => ['Economy', 'Economy'],
    ];

    /** Meal plan code (spaces removed, upper case) → [Novoton lang var, rank]. */
    private const array MEALS = [
        'AI' => ['novoton_holidays.all_inclusive', RateOffer::MEAL_ALL_INCLUSIVE],
        'ALLINCL' => ['novoton_holidays.all_inclusive', RateOffer::MEAL_ALL_INCLUSIVE],
        'ALLINCLUSIVE' => ['novoton_holidays.all_inclusive', RateOffer::MEAL_ALL_INCLUSIVE],
        'UAI' => ['novoton_holidays.ultra_all_inclusive', RateOffer::MEAL_ULTRA_ALL_INCLUSIVE],
        'FB' => ['novoton_holidays.full_board', RateOffer::MEAL_FULL_BOARD],
        'FB+' => ['novoton_holidays.full_board', RateOffer::MEAL_FULL_BOARD],
        'HB' => ['novoton_holidays.half_board', RateOffer::MEAL_HALF_BOARD],
        'HB+' => ['novoton_holidays.half_board', RateOffer::MEAL_HALF_BOARD],
        'BB' => ['novoton_holidays.bed_breakfast', RateOffer::MEAL_BREAKFAST],
        'B&B' => ['novoton_holidays.bed_breakfast', RateOffer::MEAL_BREAKFAST],
        'RO' => ['novoton_holidays.room_only', RateOffer::MEAL_ROOM_ONLY],
        'ROOMONLY' => ['novoton_holidays.room_only', RateOffer::MEAL_ROOM_ONLY],
        'SC' => ['novoton_holidays.self_catering', RateOffer::MEAL_ROOM_ONLY],
    ];

    /**
     * @param \Closure(float): float $toPrimary an API amount in the store's primary currency
     * @param \Closure(string, array<string, string|int|float>): string $translate a lang var's text
     * @param \Closure(string): string $url a storefront URL (fn_url)
     */
    public function __construct(
        private readonly TermsTimelineFactory $timeline,
        private readonly \Closure $toPrimary,
        private readonly \Closure $translate,
        private readonly \Closure $url,
        private readonly string $lang,
        private readonly string $today,
        private readonly bool $depositOffered,
    ) {
    }

    /**
     * The store's: API amounts in the primary currency, the shopper's
     * language and currency, today, Settings → Travel Core → deposits.
     */
    public static function forStore(): self
    {
        $today = date('Y-m-d');
        $primary = defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : 'EUR';
        $currency = Container::getInstance()->currencyService();

        return new self(
            new TermsTimelineFactory(MoneyFormatter::forStore(), $today, TravelCoreConfig::getDateFormat()),
            static fn (float $amount): float => $currency->convertFromApiCurrency($amount, $primary),
            static fn (string $key, array $replace): string => TypeCoerce::toString(__($key, $replace)),
            static fn (string $url): string => TypeCoerce::toString(fn_url($url)),
            defined('CART_LANGUAGE') ? strtolower(TypeCoerce::toString(CART_LANGUAGE)) : 'ro',
            $today,
            DepositPolicy::current()->offers('novoton_holidays'),
        );
    }

    /**
     * The Design A results view of a one-room search (design_a_results.tpl).
     *
     * @param list<array<string, mixed>> $rows novoton_results
     * @param array<string, mixed> $params novoton_params
     * @return array<string, mixed>
     */
    public static function view(array $rows, array $params): array
    {
        return DesignA::builder()->build(
            self::forStore()->rates($rows, $params, self::facilities(TypeCoerce::toString($params['hotel_id'] ?? ''))),
            max(1, TypeCoerce::toInt($params['nights'] ?? 1)),
            max(1, TypeCoerce::toInt($params['adults'] ?? 2)),
            max(0, TypeCoerce::toInt($params['children_count'] ?? 0)),
        );
    }

    /**
     * The hotel's room facilities (hotelinfo, saved with the hotel), in the
     * shopper's language: Novoton has none per room.
     *
     * @return list<string>
     */
    public static function facilities(string $hotelId): array
    {
        $lang = defined('CART_LANGUAGE') ? strtolower(TypeCoerce::toString(CART_LANGUAGE)) : 'ro';
        $names = [];
        foreach (fn_novoton_holidays_get_hotel_facilities_by_type($hotelId, Constants::FEATURE_TYPE_ROOM_FACILITY, $lang) as $facility) {
            $name = trim(TypeCoerce::toString($facility['facility_name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param list<array<string, mixed>> $rows novoton_results of a one-room search
     * @param array<string, mixed> $params novoton_params of that search
     * @param list<string> $facilities the hotel's room facilities
     * @return list<RateOffer>
     */
    public function rates(array $rows, array $params, array $facilities = []): array
    {
        $rates = [];
        foreach ($rows as $row) {
            $rate = $this->rate($row, $params, $facilities);
            if ($rate !== null) {
                $rates[] = $rate;
            }
        }

        return $rates;
    }

    /**
     * The room type's name, as the store names it: "Double Room (DBL 2+1)"
     * (RoomType::formatRoomLabel) without its code.
     */
    public static function roomName(string $label, string $roomId, string $lang): string
    {
        $code = RoomType::normalizeRoomCode($roomId);
        $suffix = ' (' . $code . ')';
        if ($label !== '' && str_ends_with($label, $suffix)) {
            return substr($label, 0, -strlen($suffix));
        }
        [$type] = self::split($code);
        foreach ([implode(' ', $type), $type[0] ?? ''] as $candidate) {
            $name = RoomType::toDisplayName($candidate, $lang);
            if ($candidate !== '' && $name !== $candidate) {
                return $name;
            }
        }

        return self::words($type);
    }

    /**
     * What sets the room apart, from the words after its type and
     * occupancy: "DBL 2+1 SUPERIOR DELUXE SEA SIDE" → "Superior Deluxe ·
     * spre mare". Unknown words read as words ("PREMIUM" → "Premium").
     */
    public static function roomDetail(string $roomId, string $lang): string
    {
        $detail = implode(' · ', array_column(self::detailParts($roomId, $lang), 0));

        return $detail === '' ? '' : mb_strtoupper(mb_substr($detail, 0, 1)) . mb_substr($detail, 1);
    }

    /**
     * The card's title: the room's type, its tier, then its view or
     * balcony — "Cameră Dublă" + "DBL 2+0 DELUXE NO BALCONY" → "Cameră
     * Dublă Deluxe, fără balcon"; "DBL 2+1 SEA VIEW" → "Cameră Dublă,
     * vedere la mare".
     */
    public static function roomTitle(string $name, string $roomId, string $lang): string
    {
        $tiers = [];
        $features = [];
        foreach (self::detailParts($roomId, $lang) as [$text, $tier]) {
            if (!$tier) {
                $features[] = $text;
            } elseif (mb_stripos($name, $text) === false) {
                $tiers[] = $text;
            }
        }
        $title = trim($name . ' ' . implode(' ', $tiers));

        return $features === [] ? $title : $title . ', ' . implode(', ', $features);
    }

    /** How many the room sleeps, from its occupancy ("DBL 2+1 DELUXE" → 3); 0 = not in the code. */
    public static function capacity(string $roomId): int
    {
        if (preg_match('/(?:^|\s)(\d+)\+(\d+)(?:\s|$)/', RoomType::normalizeRoomCode($roomId), $m) !== 1) {
            return 0;
        }

        return (int) $m[1] + (int) $m[2];
    }

    /**
     * The words after the room's type and occupancy, as the card says them:
     * each with whether it names the room's tier ("Superior Deluxe",
     * "Premium", any word the map doesn't know) or a feature (a view, a
     * balcony, the building: lower case).
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function detailParts(string $roomId, string $lang): array
    {
        [, $rest] = self::split(RoomType::normalizeRoomCode($roomId));
        $index = $lang === 'en' ? 1 : 0;
        $parts = [];
        $unknown = [];
        $count = count($rest);
        for ($i = 0; $i < $count;) {
            $found = null;
            foreach (self::WORDS as $phrase => $texts) {
                $n = substr_count($phrase, ' ') + 1;
                if (implode(' ', array_slice($rest, $i, $n)) === $phrase) {
                    $found = [$texts[$index], $n];
                    break;
                }
            }
            if ($found === null) {
                $unknown[] = $rest[$i];
                $i++;
                continue;
            }
            if ($unknown !== []) {
                $parts[] = [self::words($unknown), true];
                $unknown = [];
            }
            // The map writes tiers capitalised ("Superior Deluxe", "Economy"), features in lower case.
            $parts[] = [$found[0], mb_strtolower($found[0]) !== $found[0]];
            $i += $found[1];
        }
        if ($unknown !== []) {
            $parts[] = [self::words($unknown), true];
        }
        $unique = [];
        foreach ($parts as $part) {
            $unique[$part[0]] ??= $part;
        }

        return array_values($unique);
    }

    /** @return array{0: string, 1: int} the meal plan's lang var ('' = none known) and rank */
    public static function meal(string $boardId): array
    {
        $code = strtoupper(str_replace(' ', '', trim($boardId)));
        if (isset(self::MEALS[$code])) {
            return self::MEALS[$code];
        }
        if (str_contains($code, 'ULTRA')) {
            return self::MEALS['UAI'];
        }

        return ['', RateOffer::MEAL_OTHER];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $params
     * @param list<string> $facilities
     */
    private function rate(array $row, array $params, array $facilities): ?RateOffer
    {
        $roomId = TypeCoerce::toString($row['room_id'] ?? '');
        $total = TypeCoerce::toFloat($row['total_price'] ?? 0);
        $extrasPrice = TypeCoerce::toFloat($row['extras_price'] ?? 0);
        $charged = $extrasPrice > 0 ? $extrasPrice : $total;
        if ($roomId === '' || $charged <= 0) {
            return null;
        }
        $nights = max(1, TypeCoerce::toInt($params['nights'] ?? 1));
        $checkIn = TypeCoerce::toString($params['check_in'] ?? '');

        // The board, or the supplement upgrading it: "BB" + "HB" is half
        // board, an option of its own next to "BB" alone (OfferBoard)
        $mealBoard = OfferBoard::meal(TypeCoerce::toString($row['board_id'] ?? ''), TypeCoerce::toString($row['ext_board_id'] ?? ''));
        [$mealKey, $mealRank] = self::meal($mealBoard);
        $meal = $mealKey !== '' ? ($this->translate)($mealKey, []) : '';
        if ($meal === '' || $meal === $mealKey) {
            $meal = TypeCoerce::toString($row['board_name'] ?? '') ?: $mealBoard;
        }

        $label = TypeCoerce::toString($row['room_type_display'] ?? '');
        $extras = trim(TypeCoerce::toString($row['extras_label'] ?? '') ?: TypeCoerce::toString($row['extras'] ?? ''));
        $earlyBooking = TypeCoerce::toFloat($row['early_booking_discount'] ?? 0);
        $discount = NovotonBookingSidebarBuilder::discount(
            $charged,
            $total,
            $extras,
            $earlyBooking,
            ($this->translate)('novoton_holidays.book_x_pay_y', []),
            ($this->translate)('novoton_holidays.early_booking', []),
        );
        // The discount's name; its percent goes on the struck price.
        $discountLabel = '';
        $discountUntil = '';
        if ($discount['old'] > 0) {
            if ($extras !== '' && $total > $charged + 0.005) {
                $discountLabel = $discount['label'];
            } else {
                $discountLabel = ($this->translate)('novoton_holidays.early_booking', []);
                $until = TypeCoerce::toString($row['early_booking_until'] ?? '');
                $discountUntil = preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) === 1 && $until >= $this->today ? $until : '';
            }
        }

        $price = ($this->toPrimary)($charged);
        [$windows, $installments] = NovotonBookingSidebarBuilder::terms(
            TermsFormatter::parseCancellationTerms(TypeCoerce::toString($row['terms_of_cancellation'] ?? ''), $checkIn),
            TermsFormatter::parsePaymentTerms(TypeCoerce::toString($row['terms_of_payment'] ?? '')),
        );
        $cancellation = $this->timeline->cancellation($windows, $price, $nights);
        $freeUntil = '';
        foreach ($cancellation['steps'] as $step) {
            if (($step['is_current'] ?? false) === true && ($step['kind'] ?? '') === TermsTimelineFactory::KIND_FREE) {
                $freeUntil = TypeCoerce::toString($step['to_iso'] ?? '');
            }
        }
        $paymentSteps = $this->timeline->payment($installments, $price);

        $available = $row['rooms_available'] ?? null;
        $onRequest = TypeCoerce::toBool($row['is_on_request'] ?? false) || $available === 0 || $available === '0';
        $plan = $this->depositOffered && !$onRequest ? DepositPlan::fromInstallments($installments, $this->today, $price) : null;

        $perks = [];
        $moreInfo = self::plain(TypeCoerce::toString($row['more_info'] ?? ''));
        if ($moreInfo !== '') {
            $perks[] = $moreInfo;
        }
        $terms = [];
        $important = self::plain(TypeCoerce::toString($row['important'] ?? ''));
        if ($important !== '') {
            $terms[] = ['title' => ($this->translate)('novoton_holidays.important', []), 'lines' => [$important]];
        }

        $roomName = self::roomName($label, $roomId, $this->lang);

        return new RateOffer(
            roomKey: $roomId,
            roomName: $roomName,
            mealPlan: $meal,
            price: $price,
            bookUrl: $this->bookUrl($row, $params, $label !== '' ? $label : $roomId, $meal),
            roomTitle: self::roomTitle($roomName, $roomId, $this->lang),
            mealRank: $mealRank,
            standardPrice: $discount['old'] > 0 ? ($this->toPrimary)($discount['old']) : 0.0,
            discountLabel: $discountLabel,
            perks: $perks,
            freeCancellationUntil: $freeUntil,
            nonRefundable: $cancellation['full_charge_now'],
            onRequest: $onRequest,
            roomsLeft: is_numeric($available) ? (int) $available : -1,
            depositRatio: $plan !== null ? $plan->ratio : 0.0,
            balanceDue: $plan !== null ? $plan->balanceDue : '',
            terms: $terms,
            facilities: $facilities,
            timeline: $cancellation['steps'] !== [] || $paymentSteps !== []
                ? ['cancel_steps' => $cancellation['steps'], 'payment_steps' => $paymentSteps]
                : [],
            roomCode: trim(RoomType::normalizeRoomCode($roomId)),
            capacity: self::capacity($roomId),
            packageName: self::plain(TypeCoerce::toString($row['package_name'] ?? '')),
            discountUntil: $discountUntil,
        );
    }

    /**
     * The booking form link of today's result card (search.tpl), field for
     * field: the booking form reads these.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $params
     */
    private function bookUrl(array $row, array $params, string $roomName, string $boardName): string
    {
        $total = $row['total_price'] ?? '';
        $extrasPrice = $row['extras_price'] ?? '';

        return ($this->url)('novoton_booking.booking_form?' . http_build_query([
            'hotel_id' => $params['hotel_id'] ?? '',
            'room_id' => $row['room_id'] ?? '',
            'board_id' => $row['board_id'] ?? '',
            'ext_board_id' => $row['ext_board_id'] ?? '',
            'check_in' => $params['check_in'] ?? '',
            'check_out' => $params['check_out'] ?? '',
            'nights' => $params['nights'] ?? '',
            'adults' => $params['adults'] ?? '',
            'children' => $params['children_count'] ?? 0,
            'children_ages' => $params['children_ages'] ?? '',
            'price' => !empty($extrasPrice) ? $extrasPrice : $total,
            'package_name' => TypeCoerce::toString($row['package_name'] ?? '') ?: TypeCoerce::toString($params['package_name'] ?? ''),
            'room_name' => $roomName,
            'board_name' => $boardName,
            'rooms_data' => json_encode([[
                'adults' => $params['adults'] ?? 2,
                'children' => $params['children_count'] ?? 0,
                'childrenAges' => $params['children_ages_array'] ?? [],
            ]]),
            'extras' => TypeCoerce::toString($row['extras_label'] ?? ''),
            'extras_price' => $extrasPrice,
            'standard_price' => $total,
            'early_booking' => $row['early_booking_discount'] ?? 0,
            'is_on_request' => TypeCoerce::toBool($row['is_on_request'] ?? false) ? 1 : 0,
            'rooms_available' => $row['rooms_available'] ?? 0,
        ]));
    }

    /**
     * A code split at its occupancy ("2+1"): the type's words and the rest.
     * Without one, the first word is the type.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function split(string $code): array
    {
        $tokens = preg_split('/\s+/', strtoupper(trim($code))) ?: [];
        foreach ($tokens as $i => $token) {
            if (preg_match('/^\d+\+\d+$/', $token) === 1) {
                return [array_slice($tokens, 0, $i), array_slice($tokens, $i + 1)];
            }
        }

        return [array_slice($tokens, 0, 1), array_slice($tokens, 1)];
    }

    /** @param list<string> $words */
    private static function words(array $words): string
    {
        return implode(' ', array_map(
            static fn (string $w): string => mb_convert_case(mb_strtolower($w), MB_CASE_TITLE),
            $words,
        ));
    }

    /**
     * Novoton's text fields carry HTML as half-escaped tags ("lt;pgt;",
     * "lt;br /gt;"): plain text, the tags dropped. Only tag-shaped runs are
     * read as tags, so "adult;" stays a word.
     */
    private static function plain(string $text): string
    {
        $text = (string) preg_replace('~&?lt;(/?[a-z][a-z0-9]*(?:\s[^<>]*?)?/?)\s*&?gt;~i', '<$1>', $text);
        $text = html_entity_decode(strip_tags(str_replace(['&amp;', 'amp;'], '&', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
