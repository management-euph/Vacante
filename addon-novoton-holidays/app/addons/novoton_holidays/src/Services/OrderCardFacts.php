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
            static fn (mixed $v): string => trim((string) preg_replace('/\s+/u', ' ', strip_tags(TypeCoerce::toString($v)))),
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
        if ($status === TravelConstants::STATUS_FAILED) {
            $error = trim((string) preg_replace('/\s+/u', ' ', strip_tags(TypeCoerce::toString($row['notes'] ?? ''))));
            $facts['error'] = mb_strlen($error, 'UTF-8') > 300 ? mb_substr($error, 0, 299, 'UTF-8') . '…' : $error;
        }

        return $facts;
    }
}
