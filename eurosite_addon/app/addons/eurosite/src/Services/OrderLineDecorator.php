<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Repository\EurositeBookingRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;

/**
 * get_order_info for eurosite lines: adds the stay fields a eurosite cart
 * line never stored, for the order booking card (travel_core
 * OrderBookingCardFactory) and the order emails: the meal plan (on the
 * ?:eurosite_bookings row), the children count, every room's name,
 * "Last, First" guest names, and in the admin the unified View Booking id.
 * The reference, status, failure and terms come from OrderCardFacts.
 *
 * Only ADDS derived keys. eurosite_booking_id, children_ages and rooms_data
 * are left as stored: the booking submission reads the order through
 * fn_get_order_info too, and an admin order edit may write these extras back.
 */
final class OrderLineDecorator
{
    public function __construct(private readonly EurositeBookingRepository $repo)
    {
    }

    /**
     * Decorate every eurosite line of an order: two reads for the whole
     * order (booking rows, and in the admin the travel_bookings ids).
     *
     * @param array<array-key, mixed> $order fn_get_order_info() output
     * @param bool $admin the View Booking id is resolved for the admin only
     *                    (the storefront links nowhere)
     * @return array<array-key, mixed>
     */
    public function decorateOrder(array $order, bool $admin): array
    {
        $products = is_array($order['products'] ?? null) ? $order['products'] : [];
        $ids = [];
        foreach ($products as $product) {
            $id = is_array($product) ? self::bookingId($product) : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return $order;
        }

        $bookings = $this->repo->mealsByIds($ids);
        $surrogates = $admin ? $this->repo->surrogateIds($ids) : [];
        foreach ($products as $key => $product) {
            if (!is_array($product)) {
                continue;
            }
            $id = self::bookingId($product);
            if ($id <= 0) {
                continue;
            }
            $product['extra'] = self::decorate(
                TypeCoerce::toStringMap($product['extra'] ?? null),
                $bookings[$id] ?? null,
                $surrogates[$id] ?? 0,
            );
            $products[$key] = $product;
        }
        $order['products'] = $products;

        return $order;
    }

    /**
     * One line's extra with the display fields added. Pure.
     *
     * @param array<string, mixed> $extra the line's extra, after travel_core's
     *                                    get_order_info (dates formatted,
     *                                    guests_data an array)
     * @param array<string, mixed>|null $booking its ?:eurosite_bookings meal columns
     * @param int $surrogateId its ?:travel_bookings id, 0 = no link
     * @return array<string, mixed>
     */
    public static function decorate(array $extra, ?array $booking, int $surrogateId): array
    {
        // The line stores the children's ages only ("7,4"); the block shows
        // children when it has a count.
        if (!isset($extra['children'])) {
            $extra['children'] = count(self::ages(TypeCoerce::toString($extra['children_ages'] ?? '')));
        }

        // Several rooms: room_name holds the first one only, so name them all
        // ("2x Double Room, Triple Room").
        $rooms = self::rooms($extra['rooms_data'] ?? null);
        if (count($rooms) > 1 && TypeCoerce::toString($extra['room_type_display'] ?? '') === '') {
            $names = array_map(
                static fn (array $line): string => $line['qty'] > 1 ? $line['qty'] . 'x ' . $line['name'] : $line['name'],
                BookingSidebarFactory::roomLines($rooms),
            );
            if ($names !== []) {
                $extra['room_type_display'] = implode(', ', $names);
            }
        }

        // Eurosite names travellers "Last / First"; the other providers'
        // lines read "Last, First".
        if (is_array($extra['guests_data'] ?? null)) {
            $guests = $extra['guests_data'];
            foreach ($guests as $i => $guest) {
                if (is_array($guest) && is_string($guest['display_name'] ?? null)) {
                    $guest['display_name'] = self::displayName($guest['display_name']);
                    $guests[$i] = $guest;
                }
            }
            $extra['guests_data'] = $guests;
        }

        if ($surrogateId > 0) {
            $extra['travel_surrogate_id'] = $surrogateId;
        }

        if ($booking === null) {
            return $extra;
        }

        if (TypeCoerce::toString($extra['board_name'] ?? '') === '') {
            $board = TypeCoerce::toString($booking['meal_name'] ?? '');
            if ($board === '') {
                $board = TypeCoerce::toString($booking['board_id'] ?? '');
            }
            if ($board !== '') {
                $extra['board_name'] = $board;
            }
        }

        return $extra;
    }

    /** "Popescu / Ion" -> "Popescu, Ion"; anything else as given. */
    public static function displayName(string $name): string
    {
        $parts = array_map('trim', explode('/', $name, 2));

        return count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' ? $parts[0] . ', ' . $parts[1] : $name;
    }

    /**
     * @param array<array-key, mixed> $product
     */
    private static function bookingId(array $product): int
    {
        $extra = is_array($product['extra'] ?? null) ? $product['extra'] : [];

        return TypeCoerce::toInt($extra['eurosite_booking_id'] ?? 0);
    }

    /**
     * rooms_data as stored: a JSON string at add-to-cart, an array once the
     * cart hook decoded it.
     *
     * @return list<array<string, mixed>>
     */
    private static function rooms(mixed $roomsData): array
    {
        if (is_string($roomsData)) {
            $roomsData = $roomsData !== '' ? json_decode($roomsData, true) : null;
        }

        return TypeCoerce::toRowList($roomsData);
    }

    /**
     * @return list<string>
     */
    private static function ages(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $age): bool => $age !== ''));
    }
}
