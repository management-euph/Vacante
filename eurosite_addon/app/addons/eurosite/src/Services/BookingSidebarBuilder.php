<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarViewModel;

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
    ): BookingSidebarViewModel {
        $checkIn = TypeCoerce::toString($snapshot['check_in'] ?? '');
        $checkOut = TypeCoerce::toString($snapshot['check_out'] ?? '');
        $checkInTs = (int) strtotime($checkIn);
        $checkOutTs = (int) strtotime($checkOut);
        $currency = TypeCoerce::toString($snapshot['currency'] ?? '');
        $total = self::money(TypeCoerce::toFloat($snapshot['price'] ?? 0), $currency);
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
        $productId = $hotelRow !== null ? TypeCoerce::toInt($hotelRow['product_id'] ?? 0) : 0;
        $roomLines = BookingSidebarFactory::roomLines($roomNames);

        return new BookingSidebarViewModel(
            imagePair: $imagePair,
            imageUrl: $hotelRow !== null ? TypeCoerce::toString($hotelRow['first_image'] ?? '') : '',
            name: TypeCoerce::toString($snapshot['product_name'] ?? ''),
            stars: $hotelRow !== null ? TypeCoerce::toInt($hotelRow['category'] ?? 0) : 0,
            // IM = Immediate; OR (On request) shows the "on request" badge.
            available: TypeCoerce::toString($snapshot['availability_code'] ?? '') !== 'OR',
            locationLine: $locationLine !== '' ? $locationLine : TypeCoerce::toString($snapshot['city_name'] ?? ''),
            checkIn: $checkInTs > 0 ? DateHelper::formatWith($checkInTs, $dateFormat) : $checkIn,
            checkInWeekday: $checkInTs > 0 ? DateHelper::formatWith($checkInTs, '%A') : '',
            checkOut: $checkOutTs > 0 ? DateHelper::formatWith($checkOutTs, $dateFormat) : $checkOut,
            checkOutWeekday: $checkOutTs > 0 ? DateHelper::formatWith($checkOutTs, '%A') : '',
            nights: DateHelper::calculateNights($checkIn, $checkOut),
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
            cancelLines: $cancelLines,
            cancelFullAmount: BookingSidebarFactory::fullChargeAmount($cancelLines, $total),
            cancelFreeUntil: self::freeUntil($fees, $today !== '' ? $today : date('Y-m-d'), $dateFormat),
            paymentLines: $paymentLines,
            roomLabel: $roomLines !== [] ? $roomLines[0]['name'] : '',
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
