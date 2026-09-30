<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Formatting Functions
 *
 * Thin fn_* shells for display/XML formatting. The bodies live in
 * src/Services/DisplayFormatter (labels, names, dates, prices) and
 * src/Services/ProviderXmlParser (terms XML parsing) — this file only
 * resolves the framework-bound inputs (Registry date format, CART_LANGUAGE,
 * currency registry) and delegates, so the logic is unit-testable.
 *
 * @package NovotonHolidays
 * @since 2.8.0
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\NovotonHolidays\Services\DisplayFormatter;
use Tygh\Addons\NovotonHolidays\Services\ProviderXmlParser;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Registry;

/**
 * The CS-Cart date format from Admin > Settings > Appearance (strftime style).
 */
function fn_novoton_holidays_date_format(): string
{
    return TypeCoerce::toString(Registry::get('settings.Appearance.date_format'));
}

/**
 * Format date using CS-Cart's configured date format.
 *
 * @param string|int $date Date string or timestamp
 */
function fn_novoton_holidays_format_date(string|int $date): string
{
    return DisplayFormatter::formatDate($date, fn_novoton_holidays_date_format());
}

/**
 * Localized board label: "Demipensiune (HB +)". Unknown codes return the raw
 * code bare (booking_form.php's fallback detection relies on that).
 */
function fn_novoton_holidays_board_label(string $boardId): string
{
    return DisplayFormatter::boardLabel($boardId);
}

/**
 * Format board name for display — same label everywhere (cart hooks, order
 * hooks, booking records, emails, search card).
 */
function fn_novoton_holidays_format_board_name(string $boardId): string
{
    return DisplayFormatter::boardLabel($boardId);
}

/**
 * Resolve the storefront display language for provider-text localization:
 * explicit override → CART_LANGUAGE → 'ro'.
 *
 * @param string|null $lang Explicit language override (mainly for tests)
 */
function fn_novoton_holidays_display_lang(?string $lang = null): string
{
    $cartLanguage = defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : null;

    return DisplayFormatter::resolveLang($lang, $cartLanguage);
}

/**
 * Localize the provider "MoreInfo" free-text (RO storefront only; unknown
 * phrases pass through unchanged).
 */
function fn_novoton_holidays_more_info_label(string $text, ?string $lang = null): string
{
    return DisplayFormatter::moreInfoLabel($text, fn_novoton_holidays_display_lang($lang));
}

/**
 * Localize recurring English phrases in the provider "remark" free-text
 * (RO storefront only).
 */
function fn_novoton_holidays_format_remark(string $remark, ?string $lang = null): string
{
    return DisplayFormatter::formatRemark($remark, fn_novoton_holidays_display_lang($lang));
}

/**
 * Format room type code for display (RoomType value object is the single
 * source of truth).
 */
function fn_novoton_holidays_format_room_type(string $roomId, string $roomType = ''): string
{
    return \Tygh\Addons\TravelCore\ValueObjects\RoomType::formatRoomLabel($roomId, $roomType);
}

/**
 * Normalize room code — ensures + sign between occupancy numbers.
 *
 * @param string $roomCode Room code from API
 */
function fn_novoton_holidays_normalize_room_code($roomCode): string
{
    return \Tygh\Addons\TravelCore\ValueObjects\RoomType::normalizeRoomCode($roomCode);
}

/**
 * Normalize resort/city name for comparison.
 *
 * @param string $name Resort or city name
 */
function fn_novoton_holidays_normalize_resort_name($name): string
{
    return DisplayFormatter::normalizeResortName(TypeCoerce::toString($name));
}

/**
 * Parse an XML string that may be wrapped in CDATA.
 *
 * @param string $xml_string Raw XML or CDATA string
 */
function fn_novoton_holidays_parse_xml_string($xml_string): ?\SimpleXMLElement
{
    return ProviderXmlParser::parseXmlString(TypeCoerce::toString($xml_string));
}

/**
 * Parse payment terms from XML string.
 *
 * @param string $xml_string XML terms string
 * @return list<array<string, mixed>> Parsed terms data
 */
function fn_novoton_holidays_parse_payment_terms($xml_string): array
{
    return ProviderXmlParser::parsePaymentTerms(TypeCoerce::toString($xml_string), fn_novoton_holidays_date_format());
}

/**
 * Parse cancellation terms from XML string.
 *
 * @param string $xml_string XML terms string
 * @param string $check_in Check-in date for relative calculations
 * @return list<array<string, mixed>> Parsed cancellation terms
 */
function fn_novoton_holidays_parse_cancellation_terms($xml_string, $check_in = ''): array
{
    return ProviderXmlParser::parseCancellationTerms(TypeCoerce::toString($xml_string), TypeCoerce::toString($check_in));
}

/**
 * Format payment terms with calculated amounts for display
 *
 * Uses CS-Cart's currency coefficient for conversion to match sitewide
 * price display (same approach as room prices on search results).
 *
 * Format: "10% (150 EUR) - due by 05.03.2026"
 *
 * @param string $xml_string Raw XML string with payment terms
 * @param float $total_price Total booking price in primary currency (EUR)
 * @param string $currency_code Display currency code (default: EUR)
 * @param float $coefficient Currency conversion coefficient (default: 1.0)
 * @param string $currency_symbol Currency symbol for display (default: '')
 * @return string Formatted payment terms with amounts
 */
function fn_novoton_holidays_format_payment_terms_with_amounts($xml_string, $total_price, $currency_code = 'EUR', $coefficient = 1.0, $currency_symbol = ''): string
{
    $terms = fn_novoton_holidays_parse_payment_terms($xml_string);

    if (empty($terms)) {
        return '';
    }

    // Narrow loosely-typed inputs at the boundary.
    $currency_code = TypeCoerce::toString($currency_code);
    $currency_symbol = TypeCoerce::toString($currency_symbol);
    $total_price = TypeCoerce::toFloat($total_price);
    $coefficient = TypeCoerce::toFloat($coefficient);

    // Resolve currency symbol from CS-Cart registry if not provided
    if (empty($currency_symbol) && !empty($currency_code)) {
        $currencies = TypeCoerce::toStringMap(\Tygh\Registry::get('currencies'));
        $currency = TypeCoerce::toStringMap($currencies[$currency_code] ?? null);
        if (!empty($currency['symbol'])) {
            $currency_symbol = TypeCoerce::toString($currency['symbol']);
        } else {
            $currency_symbol = $currency_code;
        }
        // Also resolve coefficient from registry if still default
        if ($coefficient === 1.0 && !empty($currency['coefficient'])) {
            $coefficient = TypeCoerce::toFloat($currency['coefficient']);
        }
    }

    $lines = [];

    foreach ($terms as $term) {
        $percent = isset($term['percent']) ? TypeCoerce::toFloat($term['percent']) : 0.0;
        $date = TypeCoerce::toString($term['date'] ?? '');

        // Calculate amount from percentage and convert to display currency
        $amount = ($percent / 100) * $total_price * $coefficient;

        // Use consistent price formatting (with sup tag for decimals)
        $formatted_amount = fn_novoton_holidays_format_price($amount, 1.0, $currency_symbol);
        $percent_display = number_format($percent, 0);

        if (!empty($date)) {
            $formatted_date = fn_novoton_holidays_format_date($date);
            $lines[] = __('novoton_holidays.payment_percent_amount_until', [
                '[percent]' => $percent_display,
                '[amount]' => $formatted_amount,
                '[date]' => $formatted_date
            ]);
        } elseif (!empty($term['is_on_booking'])) {
            $lines[] = __('novoton_holidays.payment_percent_amount_on_booking', [
                '[percent]' => $percent_display,
                '[amount]' => $formatted_amount
            ]);
        } else {
            $lines[] = "{$percent_display}% ({$formatted_amount})";
        }
    }

    return implode("\n", TypeCoerce::toStringList($lines));
}

/**
 * Format payment terms for display.
 *
 * @param string $xml_string Raw XML string
 */
function fn_novoton_holidays_format_payment_terms($xml_string): string
{
    return \Tygh\Addons\NovotonHolidays\Services\TermsFormatter::formatPaymentTerms((string) $xml_string);
}

/**
 * Format cancellation terms for display.
 *
 * @param string $xml_string Raw XML string
 * @param string $check_in Check-in date
 */
function fn_novoton_holidays_format_cancellation_terms($xml_string, $check_in = ''): string
{
    return \Tygh\Addons\NovotonHolidays\Services\TermsFormatter::formatCancellationTerms((string) $xml_string, $check_in);
}

/**
 * Get free cancellation date from terms.
 *
 * @param string $xml_string Raw XML string
 */
function fn_novoton_holidays_get_free_cancellation_date($xml_string): ?string
{
    return ProviderXmlParser::freeCancellationDate(TypeCoerce::toString($xml_string));
}

/**
 * Format hotel display name: Title Case + append property type for short names.
 *
 * @param string $hotel_name  Raw hotel name (usually UPPERCASE from API)
 * @param string $detected_property_type  Property type code from PropertyTypeDetector
 */
function fn_novoton_holidays_format_hotel_display_name(string $hotel_name, string $detected_property_type = ''): string
{
    return DisplayFormatter::formatHotelDisplayName($hotel_name, $detected_property_type, _nvt_property_type_detector());
}

/**
 * Build hotel title in standard format.
 *
 * @param string $hotel_name Hotel name
 * @param string $city City
 * @param string $country Country
 * @param int|string $year Year
 */
function fn_novoton_holidays_build_hotel_title($hotel_name, $city, $country, $year): string
{
    return DisplayFormatter::buildHotelTitle(
        TypeCoerce::toString($hotel_name),
        TypeCoerce::toString($city),
        TypeCoerce::toString($country),
        TypeCoerce::toString($year),
        _nvt_property_type_detector(),
    );
}

/**
 * Convert XML to array recursively.
 *
 * @param \SimpleXMLElement|string $xml XML object or string
 * @return array<string, mixed> Converted array
 */
function fn_novoton_holidays_xml_to_array($xml): array
{
    return ProviderXmlParser::xmlToArray($xml);
}

/**
 * Format a price for display (thousands dot, decimals as <sup>, integer when
 * "Round hotel prices" is on).
 *
 * @param float|string $amount      Raw price in primary currency
 * @param float|string $coefficient Currency conversion coefficient
 * @param string       $symbol      Currency symbol or code to append
 */
function fn_novoton_holidays_format_price($amount, $coefficient = 1.0, string $symbol = ''): string
{
    return DisplayFormatter::formatPrice($amount, $coefficient, $symbol);
}

/**
 * An amount in the API currency (a booking's total_price), written exactly as
 * the cart shows the same booking to the shopper.
 *
 * add_to_cart converts the API price to the store's primary currency for the
 * cart line; the cart then shows it in the header currency. A price-change
 * toast built with fn_format_price() on the raw API amount skipped the first
 * step, so it quoted a different figure than the cart for the same stay.
 *
 * @param float $amount Amount in the API currency (e.g. EUR)
 */
function fn_novoton_holidays_format_api_amount_for_shopper(float $amount): string
{
    $primary = defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : 'EUR';

    return \Tygh\Addons\TravelCore\Services\MoneyFormatter::forStore()->format(
        _nvt_currency_service()->convertFromApiCurrency($amount, $primary),
    );
}
