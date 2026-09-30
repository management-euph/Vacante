<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Admin email for the checkout price outcomes the installed
 * novoton_holidays_price_discrepancy template cannot express. Rendering them
 * through that template produced a false "PRICE ALERT - Form Price Above API
 * ... allowed at the higher form price".
 */
final class PriceOutcomeEmail
{
    /**
     * Mail the outcome to the admin; false (logged) when sending fails.
     *
     * @param array<string, mixed> $data
     * @param \Closure(array<string, mixed>): bool $deliver sends one mail (CS-Cart's mailer, from functions/email.php)
     */
    public static function send(string $type, array $data, string $adminEmail, \Closure $deliver): bool
    {
        $message = self::build($type, $data);
        try {
            return $deliver([
                'to' => $adminEmail,
                'from' => 'default_company_orders_department',
                'subj' => $message['subject'],
                'body' => $message['body'],
            ]);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', 'Failed to send price discrepancy email: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Subject and HTML body of the checkout outcomes the discrepancy template
     * cannot express:
     *   - offer_missing: the booked package (or room) is gone from the live
     *     answer; the order went through at the price the customer was shown,
     *     never at another package's price — the booking needs a manual check;
     *   - price_absorbed: the live price rose within the absorb allowance and
     *     the merchant absorbs the difference.
     *
     * @param array<string, mixed> $data notification data (see fn_novoton_holidays_send_price_discrepancy_email)
     * @return array{subject: string, body: string}
     */
    public static function build(string $type, array $data): array
    {
        $pif = PriceInfoFormatter::class;
        $currency = ConfigProvider::getApiCurrency();
        $esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $money = static fn (mixed $amount): string => $currency . ' ' . number_format($pif::toFloat($amount), 2);

        $hotelName = $pif::toScalar($data['hotel_name'] ?? '');
        $children = $pif::toInt($data['children'] ?? 0);
        $guests = $pif::toInt($data['adults'] ?? 2) . ' adults'
            . ($children > 0 ? ', ' . $children . ' children (ages: ' . $pif::toScalar($data['children_ages'] ?? '') . ')' : '');

        $details = [
            'Hotel' => $hotelName . ' (ID: ' . $pif::toScalar($data['hotel_id'] ?? '') . ')',
            'Package' => $pif::toScalar($data['package_name'] ?? ''),
            'Room' => $pif::toScalar($data['room_id'] ?? ''),
            'Board' => $pif::toScalar($data['board_id'] ?? ''),
            'Check-in' => $pif::toScalar($data['check_in'] ?? ''),
            'Check-out' => $pif::toScalar($data['check_out'] ?? ''),
            'Guests' => $guests,
        ];

        if ($type === 'offer_missing') {
            $headline = 'OFFER NO LONGER AVAILABLE - Order Allowed at the Shown Price';
            $intro = 'At checkout the live room_price answer no longer offered the booked package for this room and board. '
                . 'The cart was <strong>not</strong> re-priced to another package: the order proceeded at the price the customer was shown. '
                . 'Please check the booking with Novoton before it is confirmed.';
            $offered = [];
            foreach (is_array($data['offered'] ?? null) ? $data['offered'] : [] as $line) {
                $offered[] = $pif::toScalar($line);
            }
            $details['Price charged (with commission)'] = $money($data['form_price'] ?? 0);
            $details['Offered now for this room/board (raw)'] = $offered !== [] ? implode('; ', $offered) : 'nothing';
        } else {
            $headline = 'PRICE INCREASE ABSORBED - Customer Paid the Shown Price';
            $intro = 'The live API price was higher than the price shown to the customer, but within the absorb allowance '
                . '(travel_core checkout setting). The order proceeded at the shown price; the difference is absorbed.';
            $details['Shown price (with commission)'] = $money($data['form_price'] ?? 0);
            $details['API price (with commission)'] = $money($data['api_price'] ?? 0);
            $details['API price (raw / net)'] = $money($data['api_price_raw'] ?? 0);
            $details['Difference'] = $money($data['difference'] ?? 0) . ' (' . $pif::toFloat($data['percent'] ?? 0) . '%)';
        }

        $rows = '';
        foreach ($details as $label => $value) {
            $rows .= '<tr><td style="padding: 8px; border: 1px solid #ddd; width: 220px;"><strong>' . $esc($label) . ':</strong></td>'
                . '<td style="padding: 8px; border: 1px solid #ddd;">' . $esc($value) . '</td></tr>';
        }

        return [
            'subject' => '[Novoton] ' . $headline . ' - ' . $hotelName,
            'body' => '<h2 style="color: #e67e00;">' . $esc($headline) . '</h2>'
                . '<p>' . $intro . '</p>'
                . '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">' . $rows . '</table>'
                . '<p style="color: #666; font-size: 12px;">Timestamp: ' . $esc(date('d.m.Y H:i:s')) . '</p>'
                . '<p style="color: #666; font-size: 12px;">This alert was generated by the pre-order price verification system (pre_place_order hook).</p>',
        ];
    }
}
