<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\EurositeXmlParser;
use Tygh\Addons\Eurosite\Repository\EurositeBookingRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;

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
 *    absolute amounts per booking item) as windows of the whole booking — a
 *    share of what the booking costs, so the order line's price (converted
 *    on the day it was booked) carries them at that day's rate — and the
 *    payment terms the customer was shown, as add_to_cart stored them on the
 *    line (a line from before that: the setting, as it was shown then).
 */
final class OrderCardFacts
{
    /**
     * @param (\Closure(): list<string>)|null $currentTerms the payment terms setting as lines
     *                                                      (default: ConfigProvider's)
     */
    public function __construct(
        private readonly ?EurositeBookingRepository $repo = null,
        private readonly ?\Closure $currentTerms = null,
    ) {
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
     * (once booked) and the payment terms the customer saw — stored on the
     * line since add_to_cart snapshots them; an older line has none, and
     * gets the setting (what its pages showed until then).
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
        $stored = array_key_exists('payment_terms', $extra)
            ? TypeCoerce::toStringList(is_array($extra['payment_terms']) ? $extra['payment_terms'] : [])
            : $this->currentTerms();
        $paymentLines = array_values(array_filter(
            array_map('trim', $stored),
            static fn (string $line): bool => $line !== '',
        ));
        if ($paymentLines !== []) {
            $terms['payment_lines'] = $paymentLines;
        }
        $row = $this->repository()->findById($id);
        $rates = [];
        $windows = self::windows(
            TypeCoerce::toString($row['cancellation_fees_json'] ?? ''),
            TypeCoerce::toFloat($row['total_price'] ?? 0),
            strtoupper(TypeCoerce::toString($row['currency'] ?? '')),
            static function (string $currency) use (&$rates): float {
                return $rates[$currency] ??= self::coefficient($currency);
            },
        );
        if ($windows !== []) {
            $terms['cancel_windows'] = $windows;
        }

        return $terms;
    }

    /**
     * getBookingFees snapshot → TermsTimelineFactory windows: the items'
     * amounts added up per window (cancelling the whole booking). In the
     * booking's own currency they become a share of its total, which the
     * timeline applies to the order line's price — converted the day it was
     * booked, so a 100% fee reads as the full price whatever the rate is
     * today. Fees in another currency (or no total) stay amounts, converted
     * into the store's primary currency at today's rate.
     *
     * @param float $total the booking's total_price, in $currency
     * @param \Closure(string): float $coefficient currency → primary coefficient (0 = unknown)
     * @return list<array{from: string, to: string, percent: float}>|list<array{from: string, to: string, amount: float}>
     */
    public static function windows(string $feesJson, float $total, string $currency, \Closure $coefficient): array
    {
        $snapshot = TypeCoerce::toStringMap($feesJson !== '' ? json_decode($feesJson, true) : null);
        $fees = [];
        foreach (TypeCoerce::toRowList($snapshot['items'] ?? null) as $item) {
            foreach (TypeCoerce::toRowList($item['fees'] ?? null) as $fee) {
                $fees[] = [
                    'from' => TypeCoerce::toString($fee['from_date'] ?? ''),
                    'to' => TypeCoerce::toString($fee['to_date'] ?? ''),
                    'currency' => strtoupper(TypeCoerce::toString($fee['currency'] ?? 'EUR')),
                    'price' => TypeCoerce::toFloat($fee['price'] ?? 0),
                ];
            }
        }
        $share = $total > 0 && $currency !== '' && $fees !== []
            && array_filter($fees, static fn (array $fee): bool => $fee['currency'] !== $currency) === [];

        $sums = [];
        foreach ($fees as $fee) {
            $key = $fee['from'] . '|' . $fee['to'];
            $sums[$key] ??= ['from' => $fee['from'], 'to' => $fee['to'], 'sum' => 0.0];
            $sums[$key]['sum'] += $share
                ? $fee['price']
                : EurositeProductFactory::toStorePrice($fee['price'], $coefficient($fee['currency']));
        }
        if ($share) {
            return array_values(array_map(
                static fn (array $w): array => ['from' => $w['from'], 'to' => $w['to'], 'percent' => round($w['sum'] / $total * 100, 4)],
                $sums,
            ));
        }

        return array_values(array_map(
            static fn (array $w): array => ['from' => $w['from'], 'to' => $w['to'], 'amount' => $w['sum']],
            $sums,
        ));
    }

    private static function coefficient(string $currency): float
    {
        return function_exists('db_get_field')
            ? TypeCoerce::toFloat(db_get_field('SELECT coefficient FROM ?:currencies WHERE currency_code = ?s', $currency))
            : 0.0;
    }

    /** @return list<string> */
    private function currentTerms(): array
    {
        return $this->currentTerms !== null
            ? ($this->currentTerms)()
            : BookingSidebarFactory::termLines(ConfigProvider::getPaymentTermsText());
    }

    private function repository(): EurositeBookingRepository
    {
        return $this->repo ?? Container::bookings();
    }
}
