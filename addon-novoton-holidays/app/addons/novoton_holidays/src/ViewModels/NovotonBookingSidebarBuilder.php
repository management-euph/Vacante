<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\ViewModels;

use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarViewModel;
use Tygh\Addons\TravelCore\ViewModels\HotelHeaderFactory;
use Tygh\Addons\TravelCore\ViewModels\HotelHeaderViewModel;

/**
 * Builds the booking page's hotel header + summary sidebar for novoton.
 *
 * Lives here rather than inside booking_form.php because TWO controller modes
 * render the same page: `booking_form` (create) and `edit_booking` (change the
 * guest names on a cart line). Only the first one ever built the sidebar, and
 * because components/booking_sidebar.tpl is wrapped in `{if $tbs}`, the whole
 * left column of the edit page silently rendered as nothing — an isolated bare
 * form with no hotel, no dates, no price, and an empty "What are my booking
 * conditions?" modal. One builder, called by both modes, is what stops those
 * two pages drifting apart again.
 *
 * Edit mode shows the BOOKED figures and never re-quotes: a guest correcting a
 * misspelled name must not have the price move under them.
 */
final class NovotonBookingSidebarBuilder
{
    /**
     * The shared hotel identity (name, stars, sanitized location line,
     * always-present map URL) both modes assign.
     *
     * @param array<string, mixed> $hotelInfo a ?:novoton_hotels row, or [] when unknown
     * @param string $starsGlyphs '★★★★' from star_rating, or legacy '*'s parsed
     *                            out of the hotel name — either form is counted
     */
    public static function header(
        string $hotelId,
        array $hotelInfo,
        string $starsGlyphs,
        int $productId,
        string $fallbackName = '',
    ): HotelHeaderViewModel {
        $seo = new HotelSeoData(
            hotelId: $hotelId,
            providerName: 'novoton',
            name: TypeCoerce::toString($hotelInfo['hotel_name'] ?? $fallbackName),
            city: TypeCoerce::toString($hotelInfo['city'] ?? ''),
            region: TypeCoerce::toString($hotelInfo['region'] ?? ''),
            country: TypeCoerce::toString($hotelInfo['country'] ?? ''),
            latitude: TypeCoerce::toFloat($hotelInfo['latitude'] ?? 0),
            longitude: TypeCoerce::toFloat($hotelInfo['longitude'] ?? 0),
            address: TypeCoerce::toString($hotelInfo['street_address'] ?? ''),
        );

        return HotelHeaderFactory::fromSeo(
            $seo,
            mb_substr_count($starsGlyphs, '★') + substr_count($starsGlyphs, '*'),
            $productId,
            implode(', ', array_filter([
                TypeCoerce::toString($hotelInfo['city'] ?? ''),
                TypeCoerce::toString($hotelInfo['region'] ?? ''),
                TypeCoerce::toString($hotelInfo['country'] ?? ''),
            ], static fn (string $part): bool => $part !== '')),
        );
    }

    /**
     * The summary sidebar, from the same `$booking` array both modes assemble.
     *
     * Every value is formatted here, because the shared template carries no
     * provider branches — novoton derives its summary from URL params + the
     * hotels table, sphinx from a verified live offer.
     *
     * Cancellation terms are deliberately absent: novoton only returns them
     * with a price quote, and the page already re-verifies the price on load
     * (booking-form.js). The card ships empty and is filled by that existing
     * round-trip — no second API call just to render a policy.
     *
     * @param array<string, mixed> $booking the `booking_data` view variable
     */
    public static function sidebar(
        array $booking,
        HotelHeaderViewModel $header,
        int $productId,
        string $packageName,
        float $displayCoefficient,
        string $displaySymbol,
        bool $available = true,
        string $lang = 'en',
        ?MoneyFormatter $money = null,
        float $primaryTotal = 0.0,
        string $availabilityStatus = '',
        string $availabilityNote = '',
        string $dateFormat = '',
        int $featuresMax = 6,
        float $primaryOld = 0.0,
        string $discountLabel = '',
    ): BookingSidebarViewModel {
        $roomsData = TypeCoerce::toRowList($booking['rooms_data'] ?? []);
        $occupancy = BookingSidebarFactory::occupancy($roomsData);
        $checkIn = TypeCoerce::toString($booking['check_in'] ?? '');
        $checkOut = TypeCoerce::toString($booking['check_out'] ?? '');
        $checkInTs = (int) strtotime($checkIn);
        $checkOutTs = (int) strtotime($checkOut);

        $package = $packageName !== '' ? $packageName : TypeCoerce::toString($booking['package_name'] ?? '');
        $nights = TypeCoerce::toInt($booking['nights'] ?? 0);
        $total = $money !== null && $primaryTotal > 0
            ? $money->format($primaryTotal)
            : fn_novoton_holidays_format_price(
                PriceInfoFormatter::toFloat($booking['total_price'] ?? 0),
                $displayCoefficient,
                $displaySymbol,
            );
        $perNight = null;
        if ($money !== null && $primaryTotal > 0) {
            $perNight = BookingSidebarFactory::perNight($money->toDisplay($primaryTotal), $nights);
        }
        // discount() already priced the offer; shown only above the total.
        $hasOld = $money !== null && $primaryTotal > 0 && $primaryOld > $primaryTotal;

        return new BookingSidebarViewModel(
            imagePair: function_exists('fn_travel_core_product_main_pair')
                ? fn_travel_core_product_main_pair($productId)
                : [],
            name: $header->name,
            stars: $header->stars,
            available: $available,
            availabilityStatus: $availabilityStatus,
            availabilityNote: $availabilityNote,
            locationLine: $header->locationLine,
            mapUrl: $header->mapUrl,
            // The full list: the template shows featuresMax chips + "+N more".
            features: Container::getInstance()->facilityRepository()->getLabelsForHotel(
                TypeCoerce::toString($booking['hotel_id'] ?? ''),
                $lang,
                100,
            ),
            packageName: $package !== $header->name ? $package : '',
            // Store-configured format (Settings -> Appearance), NOT a hardcoded
            // one: the cancellation lines below the summary already follow it,
            // and a card showing "Check-in 07.09.2026" beside "free until
            // 08/28/2026" is the bug this replaced.
            checkIn: $checkInTs > 0 ? DateHelper::formatStoreDate($checkInTs) : $checkIn,
            checkInWeekday: $checkInTs > 0 ? DateHelper::formatStoreWeekday($checkInTs) : '',
            checkOut: $checkOutTs > 0 ? DateHelper::formatStoreDate($checkOutTs) : $checkOut,
            checkOutWeekday: $checkOutTs > 0 ? DateHelper::formatStoreWeekday($checkOutTs) : '',
            nights: $nights,
            rooms: max(1, TypeCoerce::toInt($booking['num_rooms'] ?? 1)),
            adults: $occupancy['adults'] > 0 ? $occupancy['adults'] : TypeCoerce::toInt($booking['adults'] ?? 0),
            children: $occupancy['children'] > 0 ? $occupancy['children'] : TypeCoerce::toInt($booking['children'] ?? 0),
            roomLines: BookingSidebarFactory::roomLines($roomsData),
            boardName: TypeCoerce::toString($roomsData[0]['board_name'] ?? ''),
            changeUrl: BookingSidebarFactory::changeSelectionUrl($productId, [
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => TypeCoerce::toInt($booking['adults'] ?? 0),
                'children' => TypeCoerce::toInt($booking['children'] ?? 0),
                'children_ages' => TypeCoerce::toString($booking['children_ages'] ?? ''),
                'rooms' => TypeCoerce::toInt($booking['num_rooms'] ?? 1),
            ]),
            productId: $productId,
            total: $total,
            oldTotal: $hasOld && $money !== null ? $money->format($primaryOld) : '',
            discountLabel: $hasOld ? $discountLabel : '',
            perNight: $perNight !== null && $money !== null ? $money->formatDisplay($perNight) : '',
            featuresMax: $featuresMax,
            showWeekday: $dateFormat === '' || !DateHelper::formatHasWeekday($dateFormat),
        );
    }

    /**
     * The struck-through "was" price and its offer label, by the SAME rules
     * the novoton search card uses (search.tpl), from novoton's own fields.
     * All amounts are API EUR with commission.
     *
     *  - an extras promotion ("7 = 6") priced below the standard row: was =
     *    the standard price, label "Book 7 nights, pay for 6";
     *  - otherwise an early-booking reduction E %: was = charged / (1 − E/100),
     *    label "-E% Early Booking";
     *  - otherwise nothing. "Was" is only ever shown ABOVE the charged price.
     *
     * @param string $bookPayTemplate "Book [book] nights, pay for [pay]"
     * @param string $earlyBookingWord "Early Booking"
     * @return array{old: float, label: string} old = 0.0 when there is none
     */
    public static function discount(
        float $charged,
        float $standard,
        string $extras,
        float $earlyBookingPercent,
        string $bookPayTemplate,
        string $earlyBookingWord,
    ): array {
        $none = ['old' => 0.0, 'label' => ''];
        if ($charged <= 0) {
            return $none;
        }
        $extras = trim($extras);
        if ($extras !== '' && $standard > $charged + 0.005) {
            $parts = array_map('trim', explode('=', $extras, 2));
            $label = count($parts) === 2 && $parts[0] !== '' && $parts[1] !== ''
                ? strtr($bookPayTemplate, ['[book]' => $parts[0], '[pay]' => $parts[1]])
                : $extras;

            return ['old' => round($standard, 2), 'label' => $label];
        }
        if ($earlyBookingPercent > 0 && $earlyBookingPercent < 100) {
            $old = round($charged / (1 - $earlyBookingPercent / 100), 2);
            if ($old > $charged + 0.005) {
                return ['old' => $old, 'label' => sprintf('-%.0f%% %s', $earlyBookingPercent, $earlyBookingWord)];
            }
        }

        return $none;
    }

    /**
     * Booking-level "was" total for several rooms: each room's own was price,
     * or its charged price when that room has no offer. Labels are the
     * distinct offer texts, in room order.
     *
     * @param list<array{price: float, old: float, label: string}> $rooms
     * @return array{old: float, label: string} old = 0.0 when no room has an offer
     */
    public static function combinedDiscount(array $rooms): array
    {
        $total = 0.0;
        $old = 0.0;
        $labels = [];
        foreach ($rooms as $room) {
            $total += $room['price'];
            $old += $room['old'] > $room['price'] ? $room['old'] : $room['price'];
            if ($room['label'] !== '' && !in_array($room['label'], $labels, true)) {
                $labels[] = $room['label'];
            }
        }

        return $old > $total + 0.005
            ? ['old' => round($old, 2), 'label' => implode(' · ', $labels)]
            : ['old' => 0.0, 'label' => ''];
    }

    /**
     * Novoton's API availability (the quota on the search result) → badge
     * status + note: a number of rooms is "available" (with "only N left"
     * at 5 or fewer, as the search card says), RQ / 0 / blank is on request.
     *
     * @return array{0: string, 1: int} [status, rooms left to mention (0 = none)]
     */
    public static function availability(bool $isOnRequest, int $roomsAvailable): array
    {
        if ($isOnRequest) {
            return [BookingSidebarViewModel::STATUS_ON_REQUEST, 0];
        }

        return [BookingSidebarViewModel::STATUS_AVAILABLE, $roomsAvailable > 0 && $roomsAvailable <= 5 ? $roomsAvailable : 0];
    }

    /**
     * Novoton price-quote terms → the shared timeline's windows and
     * installments.
     *
     * Cancellation: <Penalty tillDate Type="Percent|Over Nights">value</Penalty>,
     * only the END of each window is known (the factory fills the starts);
     * FREE = 0%; a row without a date is the no-show rule. Payment:
     * <Percent tillDate>n</Percent>, no date = on booking.
     *
     * @param list<array<string, mixed>> $cancellation TermsFormatter::parseCancellationTerms()
     * @param list<array<string, mixed>> $payment TermsFormatter::parsePaymentTerms()
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public static function terms(array $cancellation, array $payment): array
    {
        $windows = [];
        foreach ($cancellation as $row) {
            $till = TypeCoerce::toString($row['till_date'] ?? '');
            $value = $row['value'] ?? 0;
            $isNights = stripos(TypeCoerce::toString($row['type'] ?? ''), 'night') !== false;
            $n = $value === 'FREE' ? 0.0 : TypeCoerce::toFloat($value);
            $windows[] = [
                'to' => $till !== '' ? $till : null,
                'percent' => $isNights ? null : $n,
                'nights' => $isNights ? (int) $n : null,
                'no_show' => $till === '',
            ];
        }
        $installments = [];
        foreach ($payment as $row) {
            $installments[] = [
                'due' => !empty($row['is_on_booking']) ? null : TypeCoerce::toString($row['date'] ?? ''),
                'percent' => TypeCoerce::toFloat($row['percent'] ?? 0),
            ];
        }

        return [$windows, $installments];
    }
}
