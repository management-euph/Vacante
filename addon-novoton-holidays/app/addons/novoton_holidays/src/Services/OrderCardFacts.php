<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * What only the novoton booking row knows about an order line, for travel
 * core's order booking card (TravelProviderRegistry::setOrderCardResolver):
 *
 *  - the supplier reference: the confirmation, else the invoice (IdNum), else
 *    the resinfo ResNum — "NT …", as the bookings grid shows it;
 *  - why a booking failed: the submission writes the API error to `notes`;
 *  - the offer's own remarks ("remark", "important") the line carries;
 *  - the package the hotel was sold in ("ADMIRAL ***** +BEACH").
 */
final class OrderCardFacts
{
    /** Markup that separates text (a space is left where it was). */
    private const string BLOCK_TAGS = 'p|br|div|li|ul|ol|h[1-6]|tr|td|th|table|tbody|thead';
    /** Markup inside a sentence. */
    private const string INLINE_TAGS = 'b|i|u|em|strong|span|a|font|small|sup|sub';

    public function __construct(private readonly ?BookingRepository $repo = null)
    {
    }

    /**
     * @param array<string, mixed> $extra an order line's extra
     * @return array<string, mixed> empty when the line is not novoton's
     */
    public function facts(array $extra): array
    {
        if (empty($extra['novoton_booking'])) {
            return [];
        }
        $id = TypeCoerce::toInt($extra['novoton_booking_id'] ?? 0);
        $row = $id > 0 ? ($this->repo ?? new BookingRepository())->findById($id) : null;

        return self::fromRow($extra, $id, $row);
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed>|null $row ?:novoton_bookings row
     * @return array<string, mixed>
     */
    public static function fromRow(array $extra, int $bookingId, ?array $row): array
    {
        $notes = array_values(array_filter(array_map(
            self::plainText(...),
            [$extra['important'] ?? '', $extra['remark'] ?? ''],
        ), static fn (string $v): bool => $v !== ''));
        $facts = [
            'provider_booking_id' => $bookingId > 0 ? (string) $bookingId : '',
            'note' => implode(' · ', array_unique($notes)),
            'package' => trim(TypeCoerce::toString($extra['package_name'] ?? '')),
        ];
        if ($row === null) {
            return $facts;
        }
        $reference = '';
        foreach (['novoton_confirm_id', 'novoton_invoice_id', 'novoton_res_num'] as $column) {
            $reference = trim(TypeCoerce::toString($row[$column] ?? ''));
            if ($reference !== '') {
                break;
            }
        }
        $status = strtolower(TypeCoerce::toString($row['status'] ?? ''));
        $facts['reference'] = $reference !== '' ? 'NT ' . $reference : '';
        $facts['status'] = $status;
        // What Novoton charges: its answer's price, else the offer's price
        // before our commission.
        $supplier = TypeCoerce::toFloat($row['api_price'] ?? 0);
        if ($supplier <= 0) {
            $supplier = TypeCoerce::toFloat($row['base_price'] ?? 0);
        }
        if ($supplier > 0) {
            $facts['supplier_price'] = ['amount' => $supplier, 'currency' => strtoupper(TypeCoerce::toString($row['currency'] ?? 'EUR'))];
        }
        if ($status === TravelConstants::STATUS_FAILED) {
            $error = trim((string) preg_replace('/\s+/u', ' ', strip_tags(TypeCoerce::toString($row['notes'] ?? ''))));
            $facts['error'] = mb_strlen($error, 'UTF-8') > 300 ? mb_substr($error, 0, 299, 'UTF-8') . '…' : $error;
        }

        return $facts;
    }

    /**
     * The offer's remark as plain text. Novoton sends it with its markup
     * escaped and the ampersands lost ("lt;pgt;Late check-inlt;/pgt;", " amp; "),
     * sometimes properly escaped ("B&amp;B", "&gt; 20m2"): drop the tags in
     * either form — a paragraph or line break leaves a space — and decode the
     * rest. Only a known tag name closed by "gt;" is markup, and "amp;" only
     * where no letter precedes it, so "1 adult; children free" and "camp;"
     * stay as written.
     */
    public static function plainText(mixed $value): string
    {
        $tag = self::tagGap(...);
        $text = (string) preg_replace_callback(
            '/lt;\/?(' . self::BLOCK_TAGS . '|' . self::INLINE_TAGS . ')(?=[\s\/]|gt;)[^<>]{0,200}?gt;/i',
            $tag,
            TypeCoerce::toString($value),
        );
        $text = (string) preg_replace('/(?<![\p{L}\p{N}&])amp;/u', '&', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace_callback('/<\/?([a-z][a-z0-9]*)\b[^<>]*>/i', $tag, $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * What a dropped tag leaves: a space where it separated text.
     *
     * @param array<array-key, string> $m preg match, [1] = the tag name
     */
    private static function tagGap(array $m): string
    {
        return preg_match('/^(?:' . self::BLOCK_TAGS . ')$/i', $m[1] ?? '') === 1 ? ' ' : '';
    }
}
