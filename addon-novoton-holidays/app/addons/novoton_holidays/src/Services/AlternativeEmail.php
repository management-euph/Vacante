<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The two customer emails of an alternatives request ("we received your
 * request", "we found alternatives"), on the templates addon.xml registers
 * in <email_templates> (area C).
 *
 * The senders used codes registered nowhere (novoton_alternatives_*), the
 * admin area and .tpl fallbacks that were never written, so neither email
 * ever reached a customer. The templates greet [name] and list each
 * alternative's hotel and room, which the senders did not pass either.
 */
final class AlternativeEmail
{
    public const string REQUEST_RECEIVED = 'novoton_holidays_alternatives_request';

    public const string ALTERNATIVES_FOUND = 'novoton_holidays_alternatives_available';

    /** Both are customer emails: area C templates. */
    public const string AREA = 'C';

    /**
     * The mailer's parameters for one of the two templates.
     *
     * @param array<string, mixed> $data template variables
     *
     * @return array{to: string, from: string, data: array<string, mixed>, template_code: string}
     */
    public static function params(string $templateCode, string $to, array $data): array
    {
        return [
            'to' => $to,
            'from' => 'default_company_orders_department',
            'data' => $data,
            'template_code' => $templateCode,
        ];
    }

    /**
     * What the "alternatives found" template reads: the request's hotel and
     * dates, a name to greet, and each alternative with a hotel name and a
     * room (alternative_RS sends only ids: IdHotel, IdRoom, IdBoard).
     *
     * @param array<string, mixed> $request decrypted request row
     * @param array<array-key, mixed> $alternatives as stored in alternatives_data
     * @param callable(string): string $hotelName hotel_id => name, '' when unknown
     *
     * @return array<string, mixed>
     */
    public static function foundData(array $request, array $alternatives, string $greeting, callable $hotelName): array
    {
        $requestHotelId = TypeCoerce::toString($request['hotel_id'] ?? '');
        $requestHotelName = TypeCoerce::toString($request['hotel_name'] ?? '');

        $rows = [];
        foreach ($alternatives as $alternative) {
            if (!is_array($alternative)) {
                continue;
            }
            $hotelId = TypeCoerce::toString($alternative['hotel_id'] ?? '');
            $name = $hotelId === '' || $hotelId === $requestHotelId ? $requestHotelName : $hotelName($hotelId);
            $rows[] = $alternative + [
                'hotel_name' => $name !== '' ? $name : $requestHotelName,
                'room_type' => trim(TypeCoerce::toString($alternative['room_id'] ?? '') . ' ' . TypeCoerce::toString($alternative['board_id'] ?? '')),
            ];
        }

        return [
            'customer_name' => $greeting,
            'hotel_name' => $requestHotelName,
            'check_in' => $request['check_in'] ?? '',
            'check_out' => $request['check_out'] ?? '',
            'nights' => $request['nights'] ?? '',
            'adults' => $request['adults'] ?? '',
            'children' => $request['children'] ?? '',
            'alternatives' => $rows,
            'request_id' => $request['request_id'] ?? 0,
            'request' => $request,
        ];
    }

    /**
     * "We received your request".
     *
     * @param array<string, mixed> $data hotel_name, check_in, check_out, … of the request
     */
    public static function sendReceived(string $to, array $data): bool
    {
        return fn_novoton_holidays_send_mail(
            self::params(self::REQUEST_RECEIVED, $to, $data + ['customer_name' => self::greeting()]),
            self::AREA,
        );
    }

    /**
     * "We found alternatives", for a decrypted request row.
     *
     * @param array<string, mixed> $request
     * @param array<array-key, mixed> $alternatives
     */
    public static function sendFound(array $request, array $alternatives): bool
    {
        $to = TypeCoerce::toString($request['contact_email'] ?? '');
        if ($to === '') {
            return false;
        }

        $hotels = Container::getInstance()->hotelRepository();
        $data = self::foundData(
            $request,
            $alternatives,
            self::greeting(),
            static fn (string $hotelId): string => TypeCoerce::toString($hotels->findByIdAsDto($hotelId)?->name),
        );
        $data['company_data'] = fn_get_company_data(fn_get_default_company_id());

        return fn_novoton_holidays_send_mail(self::params(self::ALTERNATIVES_FOUND, $to, $data), self::AREA);
    }

    /** The form asks for no name: "Dear customer," rather than "Dear ,". */
    private static function greeting(): string
    {
        return TypeCoerce::toString(__('novoton_holidays.customer_greeting_name'));
    }
}
