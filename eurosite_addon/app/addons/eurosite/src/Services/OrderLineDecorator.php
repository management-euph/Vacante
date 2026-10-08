<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Repository\EurositeBookingRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;

/**
 * get_order_info for eurosite lines: adds the fields the shared order
 * booking block (travel_core components/order_booking_details.tpl) reads but
 * a eurosite cart line never stored.
 *
 * The cart line carries the stay (hotel, dates, rooms, guests); the rest
 * lives on the ?:eurosite_bookings row: the meal plan, the supplier reference
 * and status, and the cancellation fees Eurosite confirmed after booking.
 *
 * Only ADDS derived keys. eurosite_booking_id, children_ages and rooms_data
 * are left as stored: the booking submission reads the order through
 * fn_get_order_info too, and an admin order edit may write these extras back.
 * The values that change over a booking's life (status, reference, fees, the
 * View Booking id) are recomputed on every read, never kept.
 */
final class OrderLineDecorator
{
    public const array STATUSES = [
        TravelConstants::STATUS_PENDING,
        TravelConstants::STATUS_CONFIRMED,
        TravelConstants::STATUS_CANCELLED,
        TravelConstants::STATUS_FAILED,
    ];

    /**
     * @param array{fee_line: string, ref_line: string, statuses: array<string, string>, payment_terms: string, date_format: string} $context
     */
    public function __construct(
        private readonly EurositeBookingRepository $repo,
        private readonly array $context,
    ) {
    }

    /**
     * The labels and settings decorate() needs, from the running store.
     *
     * @return array{fee_line: string, ref_line: string, statuses: array<string, string>, payment_terms: string, date_format: string}
     */
    public static function storeContext(): array
    {
        $statuses = [];
        foreach (self::STATUSES as $status) {
            $statuses[$status] = TypeCoerce::toString(__('eurosite.booking_status_' . $status));
        }

        return [
            'fee_line' => TypeCoerce::toString(__('eurosite.cancel_fee_line')),
            'ref_line' => TypeCoerce::toString(__('eurosite.order_booking_ref')),
            'statuses' => $statuses,
            'payment_terms' => ConfigProvider::getPaymentTermsText(),
            'date_format' => TravelCoreConfig::getDateFormat(),
        ];
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

        $bookings = $this->repo->findByIds($ids);
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
                $this->context,
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
     * @param array<string, mixed>|null $booking its ?:eurosite_bookings row
     * @param int $surrogateId its ?:travel_bookings id, 0 = no link
     * @param array{fee_line: string, ref_line: string, statuses: array<string, string>, payment_terms: string, date_format: string} $context
     * @return array<string, mixed>
     */
    public static function decorate(array $extra, ?array $booking, int $surrogateId, array $context): array
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

        // Payment terms are the add-on setting (the API has no payment
        // schedule) — the same lines the booking page showed.
        if (empty($extra['payment_terms'])) {
            $lines = self::lines($context['payment_terms']);
            if ($lines !== []) {
                $extra['payment_terms'] = $lines;
            }
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

        $fees = self::cancellationLines(
            TypeCoerce::toString($booking['cancellation_fees_json'] ?? ''),
            $context['fee_line'],
            $context['date_format'],
        );
        if ($fees !== []) {
            $extra['cancellation_fees'] = $fees;
        }

        $reference = TypeCoerce::toString($booking['api_ref'] ?? '');
        if ($reference === '') {
            $reference = TypeCoerce::toString($booking['client_ref'] ?? '');
        }
        $status = TypeCoerce::toString($booking['status'] ?? '');
        if ($reference !== '' || $status !== '') {
            $line = strtr($context['ref_line'], [
                '[ref]' => $reference !== '' ? $reference : '—',
                '[status]' => $context['statuses'][$status] ?? ucfirst($status),
            ]);
            // No status: drop the empty "()" the label leaves behind.
            $extra['eurosite_ref_line'] = trim((string) preg_replace('/\s*\(\s*\)/u', '', $line));
        }

        return $extra;
    }

    /**
     * The fee schedule Eurosite confirmed for the booking (getBookingFees,
     * absolute amounts per booking item) as one line per window, the items'
     * amounts added up — what cancelling the whole booking costs.
     *
     * @return list<string>
     */
    public static function cancellationLines(string $feesJson, string $template, string $dateFormat): array
    {
        $decoded = $feesJson !== '' ? json_decode($feesJson, true) : null;
        $snapshot = TypeCoerce::toStringMap($decoded);
        $windows = [];
        foreach (TypeCoerce::toRowList($snapshot['items'] ?? null) as $item) {
            foreach (TypeCoerce::toRowList($item['fees'] ?? null) as $fee) {
                $from = TypeCoerce::toString($fee['from_date'] ?? '');
                $to = TypeCoerce::toString($fee['to_date'] ?? '');
                $currency = TypeCoerce::toString($fee['currency'] ?? '');
                $key = $from . '|' . $to . '|' . $currency;
                $windows[$key] ??= ['from' => $from, 'to' => $to, 'currency' => $currency, 'price' => 0.0];
                $windows[$key]['price'] += TypeCoerce::toFloat($fee['price'] ?? 0);
            }
        }

        $lines = [];
        foreach ($windows as $window) {
            $lines[] = BookingSidebarBuilder::cancelLines([[
                'type' => 'cancellation',
                'from_date' => $window['from'],
                'to_date' => $window['to'],
                'value' => $window['price'],
                'is_percent' => false,
            ]], $window['currency'], $template, $dateFormat)[0];
        }

        return $lines;
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

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $line): bool => $line !== ''));
    }
}
