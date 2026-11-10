<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\ViewModels;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\TermsFormatter;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarViewModel;
use Tygh\Addons\TravelCore\ViewModels\HotelHeaderFactory;
use Tygh\Addons\TravelCore\ViewModels\HotelHeaderViewModel;
use Tygh\Addons\TravelCore\ViewModels\TermsTimelineFactory;

/**
 * Builds the booking page's summary sidebar for sphinx.
 *
 * Two controller modes render the same page — `booking_form` (create) and
 * `edit_booking` (change the guest names on a cart line) — but only the first
 * ever built the sidebar. Because components/booking_sidebar.tpl is wrapped in
 * `{if $tbs}`, the edit page's entire left column rendered as nothing: an
 * isolated bare form with no hotel, no dates, no price, and an empty "What are
 * my booking conditions?" modal (which reads the same variable). One builder
 * for both modes is what stops them drifting apart again.
 *
 * Edit mode reads the STORED cart line, never a live offer: SphinxEditBooking
 * pins that edit_booking must not call verifyHotelOffer, and re-pricing while
 * a guest fixes a misspelled name would be wrong regardless.
 */
final class SphinxBookingSidebarBuilder
{
    /**
     * Every value arrives at the shared template pre-formatted, because that
     * template carries no provider branches.
     *
     * @param array<string, mixed> $bookingData the `sphinx_booking_data` view
     *                                          variable both modes already assemble
     * @param array<string, mixed>|null $hotelRow a ?:sphinx_hotels row, for the
     *                                            fallback image and the facility labels
     * @param mixed $cancellationFees the raw cancellation payload — the free-until
     *                                date can only be derived from the rules, not from the
     *                                already-formatted lines
     * @param MoneyFormatter|null $money storefront formatter (shopper's currency);
     *                                   null keeps $formattedTotal as given
     * @param float $primaryTotal the commissioned price on the cart-line scale
     *                            (store primary currency)
     * @param float $primaryOld marketing (pre-discount) price, same scale; 0 = none
     * @param string $discountLabel the offer's own label ("Early Booking")
     * @param string $confirmation API `confirmation`: immediate | on_request
     * @param mixed $paymentTerms the raw payment_terms payload
     */
    public static function build(
        array $bookingData,
        HotelHeaderViewModel $header,
        ?array $hotelRow,
        string $formattedTotal,
        mixed $cancellationFees = null,
        bool $available = true,
        string $lang = 'en',
        ?MoneyFormatter $money = null,
        float $primaryTotal = 0.0,
        float $primaryOld = 0.0,
        string $discountLabel = '',
        string $confirmation = '',
        mixed $paymentTerms = null,
        string $today = '',
        string $dateFormat = '',
        int $featuresMax = 6,
    ): BookingSidebarViewModel {
        $productId = TypeCoerce::toInt($bookingData['product_id'] ?? 0);
        $roomsData = TypeCoerce::toRowList($bookingData['rooms_data'] ?? []);
        $roomName = TypeCoerce::toString($bookingData['room_name'] ?? '');
        $checkIn = TypeCoerce::toString($bookingData['check_in'] ?? '');
        $checkOut = TypeCoerce::toString($bookingData['check_out'] ?? '');
        $checkInTs = (int) strtotime($checkIn);
        $checkOutTs = (int) strtotime($checkOut);

        // Already-formatted display lines, as the cart line stores them.
        $cancelLines = TypeCoerce::toStringList($bookingData['cancellation_fees'] ?? []);
        $paymentLines = TypeCoerce::toStringList($bookingData['payment_terms'] ?? []);

        // '' = the store's own format (Settings -> Appearance), never a fixed one.
        $dateFormat = $dateFormat !== '' ? $dateFormat : TravelCoreConfig::getDateFormat();
        $total = $formattedTotal;
        $perNight = '';
        $timeline = ['steps' => [], 'free_until' => '', 'full_charge_now' => false];
        $paymentSteps = [];
        $paymentSplit = [];
        $nights = TypeCoerce::toInt($bookingData['nights'] ?? 0);
        if ($money !== null && $primaryTotal > 0) {
            $total = $money->format($primaryTotal);
            $pn = BookingSidebarFactory::perNight($money->toDisplay($primaryTotal), $nights);
            $perNight = $pn !== null ? $money->formatDisplay($pn) : '';
            [$windows, $installments] = self::terms($cancellationFees, $paymentTerms);
            $factory = new TermsTimelineFactory($money, $today !== '' ? $today : date('Y-m-d'), $dateFormat);
            $timeline = $factory->cancellation($windows, $primaryTotal, $nights);
            $paymentSteps = $factory->payment($installments, $primaryTotal);
            $paymentSplit = $factory->split($installments, $primaryTotal);
        }

        return new BookingSidebarViewModel(
            imagePair: function_exists('fn_travel_core_product_main_pair')
                ? fn_travel_core_product_main_pair($productId)
                : [],
            imageUrl: $hotelRow !== null ? TypeCoerce::toString($hotelRow['image_url'] ?? '') : '',
            name: $header->name,
            stars: $header->stars,
            available: $available,
            locationLine: $header->locationLine,
            mapUrl: $header->mapUrl,
            features: $hotelRow !== null
                ? Container::getFeatureAssigner()->getHotelFacilityLabels($hotelRow, $lang, 100)
                : [],
            // Store-configured format (Settings -> Appearance), NOT a hardcoded
            // one — the cancellation lines in the same sidebar follow it too.
            checkIn: $checkInTs > 0 ? DateHelper::formatStoreDate($checkInTs) : $checkIn,
            checkInWeekday: $checkInTs > 0 ? DateHelper::formatStoreWeekday($checkInTs) : '',
            checkOut: $checkOutTs > 0 ? DateHelper::formatStoreDate($checkOutTs) : $checkOut,
            checkOutWeekday: $checkOutTs > 0 ? DateHelper::formatStoreWeekday($checkOutTs) : '',
            nights: TypeCoerce::toInt($bookingData['nights'] ?? 0),
            rooms: max(1, TypeCoerce::toInt($bookingData['num_rooms'] ?? 1)),
            adults: TypeCoerce::toInt($bookingData['adults'] ?? 0),
            children: TypeCoerce::toInt($bookingData['children'] ?? 0),
            roomLines: BookingSidebarFactory::roomLines($roomsData, $roomName),
            boardName: TypeCoerce::toString($bookingData['board_name'] ?? ''),
            changeUrl: BookingSidebarFactory::changeSelectionUrl($productId, [
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => TypeCoerce::toInt($bookingData['adults'] ?? 0),
                'children' => TypeCoerce::toInt($bookingData['children'] ?? 0),
                'children_ages' => TypeCoerce::toString($bookingData['children_ages'] ?? ''),
                'rooms' => max(1, TypeCoerce::toInt($bookingData['num_rooms'] ?? 1)),
            ]),
            productId: $productId,
            total: $total,
            oldTotal: $money !== null && $primaryOld > $primaryTotal ? $money->format($primaryOld) : '',
            cancelLines: $cancelLines,
            // From the timeline: never "free until" while a penalty already
            // applies (TermsFormatter::freeCancellationUntil returned the
            // FIRST PENALTY day and never compared it with today).
            cancelFullAmount: $timeline['steps'] !== []
                ? ($timeline['full_charge_now'] ? $total : '')
                : BookingSidebarFactory::fullChargeAmount($cancelLines, $total),
            cancelFreeUntil: $timeline['free_until'],
            paymentLines: $paymentLines,
            roomLabel: $roomName,
            availabilityStatus: self::status($confirmation, $available),
            discountLabel: $money !== null && $primaryOld > $primaryTotal ? $discountLabel : '',
            perNight: $perNight,
            cancelSteps: $timeline['steps'],
            paymentSteps: $paymentSteps,
            paymentSplit: $paymentSplit,
            featuresMax: $featuresMax,
            showWeekday: !DateHelper::formatHasWeekday($dateFormat),
        );
    }

    /** API `confirmation` → badge status (the API's own promise, nothing more). */
    public static function status(string $confirmation, bool $available = true): string
    {
        return match (strtolower(trim($confirmation))) {
            'immediate' => BookingSidebarViewModel::STATUS_INSTANT,
            'on_request' => BookingSidebarViewModel::STATUS_ON_REQUEST,
            default => $available ? BookingSidebarViewModel::STATUS_AVAILABLE : BookingSidebarViewModel::STATUS_ON_REQUEST,
        };
    }

    /**
     * Sphinx terms → the shared timeline's windows and installments.
     *
     * The API sends CUMULATIVE absolute amounts in the supplier's schedule,
     * which does not sum to the commissioned storefront price, so each value
     * becomes a percent of the schedule's final value (as offer_terms.php
     * does); the factory turns percents into storefront amounts. Cancellation
     * rules start at `since`; payment rules are due `until` (increments).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public static function terms(mixed $cancellationFees, mixed $paymentTerms): array
    {
        $cancelRules = TermsFormatter::rules($cancellationFees);
        $paymentRules = TermsFormatter::rules($paymentTerms);
        $scheduleTotal = 0.0;
        foreach (array_merge($cancelRules, $paymentRules) as $row) {
            $scheduleTotal = max($scheduleTotal, $row['amount']);
        }
        if ($scheduleTotal <= 0.0) {
            return [[], []];
        }
        $windows = [];
        foreach ($cancelRules as $row) {
            $windows[] = ['from' => $row['iso'], 'percent' => round($row['amount'] / $scheduleTotal * 100, 1)];
        }
        $installments = [];
        foreach (TermsFormatter::increments($paymentRules) as $row) {
            $installments[] = ['due' => $row['iso'], 'percent' => round($row['amount'] / $scheduleTotal * 100, 1)];
        }

        return [$windows, $installments];
    }

    /**
     * A package's or a circuit's cancellation and payment timeline, for its
     * booking form ("Anulare și plată"), as booking_terms_timeline.tpl takes
     * it: the same timeline a hotel's booking page shows (terms()), its
     * amounts in the shopper's currency, commission included, from the
     * offer's own terms (verify, quote or customize). payment_split is the
     * deposit and balance those terms allow (TermsTimelineFactory::split(),
     * the hotel page's): the form offers "Pay a deposit" from it
     * (booking_payment_split.tpl); [] when they allow none.
     *
     * @param mixed $cancellationFees the offer's raw cancellation_fees
     * @param mixed $paymentTerms the offer's raw payment_terms
     * @param float $primaryTotal its commissioned total, store primary currency
     * @param MoneyFormatter $money the storefront formatter (shopper's currency)
     * @param string $today Y-m-d
     * @param string $dateFormat the store's date format
     *
     * @return array{cancel_steps: list<array<string, mixed>>, payment_steps: list<array<string, mixed>>, payment_split: array{full: string, deposit: string, balance: string, balance_due: string}|array{}, cancel_free_until: string, cancel_full_amount: string, cancel_lines: list<string>, payment_lines: list<string>}
     */
    public static function tripTerms(
        mixed $cancellationFees,
        mixed $paymentTerms,
        float $primaryTotal,
        MoneyFormatter $money,
        string $today,
        string $dateFormat,
    ): array {
        $cancelLines = TermsFormatter::lines($cancellationFees);
        $paymentLines = TermsFormatter::lines($paymentTerms);
        if ($primaryTotal <= 0) {
            return ['cancel_steps' => [], 'payment_steps' => [], 'payment_split' => [], 'cancel_free_until' => '', 'cancel_full_amount' => '', 'cancel_lines' => $cancelLines, 'payment_lines' => $paymentLines];
        }
        [$windows, $installments] = self::terms($cancellationFees, $paymentTerms);
        $factory = new TermsTimelineFactory($money, $today, $dateFormat);
        $timeline = $factory->cancellation($windows, $primaryTotal);

        return [
            'cancel_steps' => $timeline['steps'],
            'payment_steps' => $factory->payment($installments, $primaryTotal),
            'payment_split' => $factory->split($installments, $primaryTotal),
            'cancel_free_until' => $timeline['free_until'],
            'cancel_full_amount' => $timeline['steps'] !== [] && $timeline['full_charge_now'] ? $money->format($primaryTotal) : '',
            'cancel_lines' => $cancelLines,
            'payment_lines' => $paymentLines,
        ];
    }

    /**
     * A sphinx cart line's terms for travel_core's cart / checkout booking
     * card (hook travel_core_cart_booking_card): the raw API terms the line
     * keeps in terms_raw, normalised as for the booking page, plus the
     * formatted lines as the prose fallback. Hotel, package and circuit lines
     * all keep them (add_to_cart, package_add_to_cart, circuit_add_to_cart).
     *
     * @param array<string, mixed> $extra
     * @return array{cancel_windows: list<array<string, mixed>>, payment_rows: list<array<string, mixed>>, cancel_lines: list<string>, payment_lines: list<string>}
     */
    public static function cartTerms(array $extra): array
    {
        $raw = json_decode(TypeCoerce::toString($extra['terms_raw'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];
        [$windows, $installments] = self::terms($raw['cancellation'] ?? null, $raw['payment'] ?? null);

        return [
            'cancel_windows' => $windows,
            'payment_rows' => $installments,
            'cancel_lines' => TypeCoerce::toStringList($extra['cancellation_fees'] ?? []),
            'payment_lines' => TypeCoerce::toStringList($extra['payment_terms'] ?? []),
        ];
    }

    /**
     * The hotel's identity (name, stars, place, map link) from its
     * ?:sphinx_hotels row, as the hotel booking page builds it: city and
     * country from the address, else from the destination. No row, the bare
     * header (name and product only).
     *
     * @param array<string, mixed>|null $hotelRow
     */
    public static function headerFromRow(string $hotelId, string $name, ?array $hotelRow, int $productId): HotelHeaderViewModel
    {
        if ($hotelRow === null) {
            return HotelHeaderFactory::bare($name, 0, $productId);
        }
        $city = trim(TypeCoerce::toString($hotelRow['address_city'] ?? ''));
        $country = trim(TypeCoerce::toString($hotelRow['address_country'] ?? ''));

        return HotelHeaderFactory::fromSeo(
            new HotelSeoData(
                hotelId: $hotelId,
                providerName: 'sphinx',
                name: $name !== '' ? $name : TypeCoerce::toString($hotelRow['name'] ?? ''),
                city: $city !== '' ? $city : TypeCoerce::toString($hotelRow['destination_name'] ?? ''),
                region: TypeCoerce::toString($hotelRow['region_name'] ?? ''),
                country: $country !== '' ? $country : TypeCoerce::toString($hotelRow['country_name'] ?? ''),
                latitude: TypeCoerce::toFloat($hotelRow['latitude'] ?? 0),
                longitude: TypeCoerce::toFloat($hotelRow['longitude'] ?? 0),
                address: TypeCoerce::toString($hotelRow['address'] ?? ''),
            ),
            TypeCoerce::toInt($hotelRow['classification'] ?? 0),
            $productId,
        );
    }

    /**
     * A trip's journeys for the summary, in order: each flight segment out,
     * then each one back, then each bus segment.
     *   "✈ Dus · 17.07.2027" / "15:40 București (OTP) → 17:35 Heraklion (HER)" / "TAROM RO 271"
     * A bus segment has no times or number in the offer: "Autocar" over its
     * route.
     *
     * @param array<string, mixed>|null $flight outbound / inbound: a list of
     *                                          segments (a package), or one (a circuit)
     * @param list<array<string, mixed>> $bus segments
     * @param array{out: string, back: string, bus: string} $words the localized word for each kind
     * @param callable(int): string $formatDate the store's date format
     * @return list<array{title: string, line: string, note: string}>
     */
    public static function tripLegs(?array $flight, array $bus, array $words, callable $formatDate): array
    {
        $legs = [];
        foreach (['outbound' => $words['out'], 'inbound' => $words['back']] as $key => $word) {
            $segments = $flight[$key] ?? null;
            // A circuit's quote gives one segment each way, as an object.
            if (is_array($segments) && array_key_exists('departure', $segments)) {
                $segments = [$segments];
            }
            foreach (TypeCoerce::toRowList($segments) as $segment) {
                $dep = TypeCoerce::toStringMap($segment['departure'] ?? null);
                $arr = TypeCoerce::toStringMap($segment['arrival'] ?? null);
                $depTs = (int) strtotime(TypeCoerce::toString($dep['datetime'] ?? ''));
                $arrTs = (int) strtotime(TypeCoerce::toString($arr['datetime'] ?? ''));
                $from = trim(($depTs > 0 ? date('H:i', $depTs) . ' ' : '') . TypeCoerce::toString($dep['name'] ?? ''));
                $to = trim(($arrTs > 0 ? date('H:i', $arrTs) . ' ' : '') . TypeCoerce::toString($arr['name'] ?? ''));
                $airline = TypeCoerce::toString(TypeCoerce::toStringMap($segment['airline'] ?? null)['name'] ?? '');
                $legs[] = [
                    'title' => '✈ ' . $word . ($depTs > 0 ? ' · ' . $formatDate($depTs) : ''),
                    'line' => $from . ' → ' . $to,
                    'note' => trim($airline . ' ' . TypeCoerce::toString($segment['flight_number'] ?? '')),
                ];
            }
        }
        foreach ($bus as $segment) {
            $from = TypeCoerce::toString(TypeCoerce::toStringMap($segment['departure'] ?? null)['name'] ?? '');
            $to = TypeCoerce::toString(TypeCoerce::toStringMap($segment['arrival'] ?? null)['name'] ?? '');
            if ($from === '' && $to === '') {
                continue;
            }
            $legs[] = ['title' => $words['bus'], 'line' => $from . ' → ' . $to, 'note' => ''];
        }

        return $legs;
    }

    /**
     * What the summary lists as included: the offer's own list, then each
     * transfer it does not already name.
     *
     * @param list<string> $included
     * @param list<array<string, mixed>> $transfers
     * @return list<string>
     */
    public static function tripIncludes(array $included, array $transfers): array
    {
        $out = [];
        foreach ($included as $item) {
            $item = trim($item);
            if ($item !== '' && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }
        foreach ($transfers as $transfer) {
            $title = trim(TypeCoerce::toString($transfer['title'] ?? ''));
            if ($title !== '' && !in_array($title, $out, true)) {
                $out[] = $title;
            }
        }

        return $out;
    }
}
