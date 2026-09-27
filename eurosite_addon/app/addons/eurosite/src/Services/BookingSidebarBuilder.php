<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarViewModel;
use Tygh\Addons\TravelCore\ViewModels\TermsTimelineFactory;

/**
 * Builds the booking page's summary sidebar for eurosite — the same shared
 * travel_core sidebar sphinx and novoton render (components/booking_sidebar.tpl),
 * fed from eurosite's own data: the server-side offer snapshot
 * (OfferContextStore) for the stay, the ?:eurosite_hotels row for stars /
 * image / product link, and getItemFees for the cancellation schedule.
 *
 * Pure — every CS-Cart lookup (image pair, country name, labels, the store
 * date format) is resolved by the controller and passed in, so this stays
 * unit-testable without a store.
 */
final class BookingSidebarBuilder
{
    /**
     * @param array<string, mixed> $snapshot OfferContextStore::get() payload
     * @param array<string, mixed>|null $hotelRow ?:eurosite_hotels row
     * @param list<array{type: string, from_date: string, to_date: string, value: float, is_percent: bool}> $fees
     * @param list<string> $paymentLines
     * @param array<string, mixed> $imagePair CS-Cart main pair of the linked product
     * @param string $feeLineTemplate localized "[from] … [to] … [value]" line
     * @param string $dateFormat store date format (TravelCoreConfig::getDateFormat())
     * @param MoneyFormatter|null $money the storefront formatter (shopper's currency);
     *                                   null = the offer currency as "1.798,00 €"
     * @param float $coefficient offer currency → store primary, exactly as the
     *                           cart line computes it (EurositeProductFactory::toStorePrice)
     */
    public static function build(
        array $snapshot,
        ?array $hotelRow,
        array $fees,
        array $paymentLines,
        string $locationLine = '',
        array $imagePair = [],
        string $feeLineTemplate = '[from] - [to]: [value]',
        string $today = '',
        string $dateFormat = '%d.%m.%Y',
        ?MoneyFormatter $money = null,
        float $coefficient = 1.0,
    ): BookingSidebarViewModel {
        $checkIn = TypeCoerce::toString($snapshot['check_in'] ?? '');
        $checkOut = TypeCoerce::toString($snapshot['check_out'] ?? '');
        $checkInTs = (int) strtotime($checkIn);
        $checkOutTs = (int) strtotime($checkOut);
        $currency = TypeCoerce::toString($snapshot['currency'] ?? '');
        $money ??= new MoneyFormatter([
            'symbol' => $currency === 'EUR' ? '€' : $currency, 'after' => 'Y',
            'decimals' => 2, 'decimals_separator' => ',', 'thousands_separator' => '.',
        ]);
        $today = $today !== '' ? $today : date('Y-m-d');
        // Everything the page shows is on the cart-line scale (store primary).
        $totalPrimary = EurositeProductFactory::toStorePrice(TypeCoerce::toFloat($snapshot['price'] ?? 0), $coefficient);
        $oldPrimary = EurositeProductFactory::toStorePrice(TypeCoerce::toFloat($snapshot['old_price'] ?? 0), $coefficient);
        $total = $money->format($totalPrimary);
        $nights = DateHelper::calculateNights($checkIn, $checkOut);
        $perNight = BookingSidebarFactory::perNight($money->toDisplay($totalPrimary), $nights);
        $childrenAges = TypeCoerce::toIntList($snapshot['children_ages'] ?? []);
        $adults = max(1, TypeCoerce::toInt($snapshot['adults'] ?? 2));

        $roomNames = [];
        foreach (TypeCoerce::toRowList($snapshot['rooms'] ?? null) as $room) {
            $roomNames[] = ['room_name' => TypeCoerce::toString($room['name'] ?? '')];
        }
        $mealNames = [];
        foreach (TypeCoerce::toRowList($snapshot['meals'] ?? null) as $meal) {
            $name = trim(TypeCoerce::toString($meal['name'] ?? ''));
            if ($name !== '') {
                $mealNames[] = $name;
            }
        }

        $cancelLines = self::cancelLines($fees, $currency, $feeLineTemplate, $dateFormat);

        // getItemFees windows → the shared timeline (each window a percent of
        // the stay, or an absolute amount in the offer currency).
        $windows = [];
        foreach ($fees as $fee) {
            $windows[] = [
                'from' => $fee['from_date'],
                'to' => $fee['to_date'],
                'percent' => $fee['is_percent'] ? $fee['value'] : null,
                'amount' => $fee['is_percent'] ? null : EurositeProductFactory::toStorePrice($fee['value'], $coefficient),
            ];
        }
        $timeline = (new TermsTimelineFactory($money, $today, $dateFormat))->cancellation($windows, $totalPrimary, $nights);
        $productId = $hotelRow !== null ? TypeCoerce::toInt($hotelRow['product_id'] ?? 0) : 0;
        $roomLines = BookingSidebarFactory::roomLines($roomNames);

        return new BookingSidebarViewModel(
            imagePair: $imagePair,
            imageUrl: $hotelRow !== null ? TypeCoerce::toString($hotelRow['first_image'] ?? '') : '',
            name: TypeCoerce::toString($snapshot['product_name'] ?? ''),
            stars: $hotelRow !== null ? TypeCoerce::toInt($hotelRow['category'] ?? 0) : TypeCoerce::toInt($snapshot['category'] ?? 0),
            // IM = Immediate; OR (On request) shows the "on request" badge.
            available: TypeCoerce::toString($snapshot['availability_code'] ?? '') !== 'OR',
            availabilityStatus: self::status(TypeCoerce::toString($snapshot['availability_code'] ?? '')),
            locationLine: $locationLine !== '' ? $locationLine : TypeCoerce::toString($snapshot['city_name'] ?? ''),
            checkIn: $checkInTs > 0 ? DateHelper::formatWith($checkInTs, $dateFormat) : $checkIn,
            checkInWeekday: $checkInTs > 0 ? DateHelper::formatWith($checkInTs, '%A') : '',
            checkOut: $checkOutTs > 0 ? DateHelper::formatWith($checkOutTs, $dateFormat) : $checkOut,
            checkOutWeekday: $checkOutTs > 0 ? DateHelper::formatWith($checkOutTs, '%A') : '',
            nights: $nights,
            rooms: 1,
            adults: $adults,
            children: count($childrenAges),
            roomLines: $roomLines,
            boardName: implode(', ', array_unique($mealNames)),
            changeUrl: BookingSidebarFactory::changeSelectionUrl($productId, [
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => $adults,
                'children' => count($childrenAges),
                'children_ages' => implode(',', $childrenAges),
                'rooms' => 1,
            ]),
            productId: $productId,
            total: $total,
            // The price before the offer's reduction + the offer's own text
            // ("Reducere Oferta Speciala 15% pana la 31.12.2026").
            oldTotal: $oldPrimary > $totalPrimary ? $money->format($oldPrimary) : '',
            cancelLines: $cancelLines,
            cancelFullAmount: $timeline['full_charge_now'] ? $total : '',
            cancelFreeUntil: $timeline['free_until'],
            paymentLines: $paymentLines,
            roomLabel: $roomLines !== [] ? $roomLines[0]['name'] : '',
            discountLabel: $oldPrimary > $totalPrimary ? TypeCoerce::toString($snapshot['offer_description'] ?? '') : '',
            perNight: $perNight !== null ? $money->formatDisplay($perNight) : '',
            cancelSteps: $timeline['steps'],
            showWeekday: !DateHelper::formatHasWeekday($dateFormat),
        );
    }

    /**
     * getItemFees rows → display lines in the store date format.
     *
     * @param list<array{type: string, from_date: string, to_date: string, value: float, is_percent: bool}> $fees
     * @return list<string>
     */
    public static function cancelLines(array $fees, string $currency, string $template, string $dateFormat = '%d.%m.%Y'): array
    {
        $lines = [];
        foreach ($fees as $fee) {
            $value = $fee['is_percent']
                ? rtrim(rtrim(number_format($fee['value'], 2, '.', ''), '0'), '.') . '%'
                : self::money($fee['value'], $currency);
            $lines[] = strtr($template, [
                '[from]' => self::storeDate($fee['from_date'], $dateFormat),
                '[to]' => self::storeDate($fee['to_date'], $dateFormat),
                '[value]' => $value,
            ]);
        }

        return $lines;
    }

    /**
     * The last free day: the day before the earliest penalty starts — '' when
     * the first penalty is already in force (or there is no schedule, since
     * "no rows" means the terms are unknown, not free).
     *
     * @param list<array{type: string, from_date: string, to_date: string, value: float, is_percent: bool}> $fees
     */
    public static function freeUntil(array $fees, string $today, string $dateFormat = '%d.%m.%Y'): string
    {
        $earliest = null;
        foreach ($fees as $fee) {
            $from = DateHelper::parseDate(trim($fee['from_date']));
            if ($from !== null && $fee['value'] > 0 && ($earliest === null || $from < $earliest)) {
                $earliest = $from;
            }
        }
        if ($earliest === null || $earliest <= $today) {
            return '';
        }

        return DateHelper::formatWith((int) strtotime($earliest . ' -1 day'), $dateFormat);
    }

    /**
     * The badge status from what the API says: IM Immediate → instant
     * confirmation, OR → on request, ST → stop sale.
     */
    public static function status(string $availabilityCode): string
    {
        return match ($availabilityCode) {
            'IM' => BookingSidebarViewModel::STATUS_INSTANT,
            'OR' => BookingSidebarViewModel::STATUS_ON_REQUEST,
            'ST' => BookingSidebarViewModel::STATUS_STOP_SALE,
            default => BookingSidebarViewModel::STATUS_AVAILABLE,
        };
    }

    /** Same money shape sphinx's sidebar uses ("1.798,00 €"). */
    public static function money(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . ($currency === 'EUR' ? '€' : $currency);
    }

    private static function storeDate(string $date, string $dateFormat): string
    {
        $iso = DateHelper::parseDate(trim($date));

        return $iso !== null ? DateHelper::formatWith((int) strtotime($iso), $dateFormat) : $date;
    }
}
