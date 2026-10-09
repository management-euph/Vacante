<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\EurositeXmlParser;
use Tygh\Addons\Eurosite\Repository\EurositeBookingRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * What only the eurosite booking row knows about an order line, for travel
 * core's order booking card (TravelProviderRegistry::setOrderCardResolver)
 * and for its terms (setCartTermsResolver):
 *
 *  - the supplier's reference (api_ref) apart from our own (client_ref), and
 *    whether the booking ever reached Eurosite: a row still pending with no
 *    api_ref on a placed order was never submitted, and must not read as a
 *    booking Eurosite is confirming;
 *  - why a booking failed — "EXCEPTION: …", "CART_DROPPED: …" or the XML
 *    answer, through the API client's own error reader;
 *  - the cancellation fees Eurosite confirmed after booking (getBookingFees,
 *    absolute amounts per booking item) as windows of the whole booking in
 *    the store's primary currency, and the payment terms the customer was
 *    shown, as add_to_cart stored them on the line.
 */
final class OrderCardFacts
{
    public function __construct(private readonly ?EurositeBookingRepository $repo = null)
    {
    }

    /**
     * @param array<string, mixed> $extra an order or cart line's extra
     * @return array<string, mixed> empty when the line is not eurosite's
     */
    public function facts(array $extra): array
    {
        $id = TypeCoerce::toInt($extra['eurosite_booking_id'] ?? 0);
        if ($id <= 0) {
            return [];
        }

        return self::fromRow($id, $this->repository()->findById($id));
    }

    /**
     * @param array<string, mixed>|null $row ?:eurosite_bookings row
     * @return array<string, mixed>
     */
    public static function fromRow(int $bookingId, ?array $row): array
    {
        $facts = ['provider_booking_id' => (string) $bookingId];
        if ($row === null) {
            return $facts;
        }
        $apiRef = trim(TypeCoerce::toString($row['api_ref'] ?? ''));
        $status = strtolower(TypeCoerce::toString($row['status'] ?? ''));
        $facts['reference'] = $apiRef;
        $facts['our_reference'] = trim(TypeCoerce::toString($row['client_ref'] ?? ''));
        $facts['status'] = $status;
        // What Eurosite charges for the booking, in its currency.
        $supplier = TypeCoerce::toFloat($row['total_price'] ?? 0);
        if ($supplier > 0) {
            $facts['supplier_price'] = ['amount' => $supplier, 'currency' => strtoupper(TypeCoerce::toString($row['currency'] ?? 'EUR'))];
        }
        $facts['not_sent'] = $apiRef === '' && $status === TravelConstants::STATUS_PENDING
            && TypeCoerce::toInt($row['order_id'] ?? 0) > 0;
        if ($status === TravelConstants::STATUS_FAILED) {
            $facts['error'] = self::error(TypeCoerce::toString($row['api_response'] ?? ''));
        }

        return $facts;
    }

    /** The human part of a stored failure, at most 300 characters. */
    public static function error(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }
        if (preg_match('/^(?:EXCEPTION|CART_DROPPED):\s*(.*)$/s', $stored, $m) === 1) {
            $text = $m[1];
        } elseif (str_starts_with($stored, '<')) {
            $text = (new EurositeXmlParser())->errorMessage($stored);
        } else {
            $text = $stored;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return mb_strlen($text, 'UTF-8') > 300 ? mb_substr($text, 0, 299, 'UTF-8') . '…' : $text;
    }

    /**
     * The line's terms for the shared timeline: the confirmed fee schedule
     * (once booked) and the payment terms the customer saw.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed> empty when the line is not eurosite's or holds no terms
     */
    public function terms(array $extra): array
    {
        $id = TypeCoerce::toInt($extra['eurosite_booking_id'] ?? 0);
        if ($id <= 0) {
            return [];
        }
        $terms = [];
        $paymentLines = array_values(array_filter(
            array_map('trim', TypeCoerce::toStringList(is_array($extra['payment_terms'] ?? null) ? $extra['payment_terms'] : [])),
            static fn (string $line): bool => $line !== '',
        ));
        if ($paymentLines !== []) {
            $terms['payment_lines'] = $paymentLines;
        }
        $row = $this->repository()->findById($id);
        $windows = self::windows(
            TypeCoerce::toString($row['cancellation_fees_json'] ?? ''),
            static fn (string $currency): float => self::coefficient($currency),
        );
        if ($windows !== []) {
            $terms['cancel_windows'] = $windows;
        }

        return $terms;
    }

    /**
     * getBookingFees snapshot → TermsTimelineFactory windows: the items'
     * amounts added up per window (cancelling the whole booking), each in
     * the store's primary currency.
     *
     * @param \Closure(string): float $coefficient currency → primary coefficient (0 = unknown)
     * @return list<array{from: string, to: string, amount: float}>
     */
    public static function windows(string $feesJson, \Closure $coefficient): array
    {
        $snapshot = TypeCoerce::toStringMap($feesJson !== '' ? json_decode($feesJson, true) : null);
        $sums = [];
        foreach (TypeCoerce::toRowList($snapshot['items'] ?? null) as $item) {
            foreach (TypeCoerce::toRowList($item['fees'] ?? null) as $fee) {
                $from = TypeCoerce::toString($fee['from_date'] ?? '');
                $to = TypeCoerce::toString($fee['to_date'] ?? '');
                $currency = strtoupper(TypeCoerce::toString($fee['currency'] ?? 'EUR'));
                $key = $from . '|' . $to;
                $sums[$key] ??= ['from' => $from, 'to' => $to, 'amount' => 0.0];
                $sums[$key]['amount'] += EurositeProductFactory::toStorePrice(TypeCoerce::toFloat($fee['price'] ?? 0), $coefficient($currency));
            }
        }

        return array_values($sums);
    }

    private static function coefficient(string $currency): float
    {
        return function_exists('db_get_field')
            ? TypeCoerce::toFloat(db_get_field('SELECT coefficient FROM ?:currencies WHERE currency_code = ?s', $currency))
            : 0.0;
    }

    private function repository(): EurositeBookingRepository
    {
        return $this->repo ?? Container::bookings();
    }
}
