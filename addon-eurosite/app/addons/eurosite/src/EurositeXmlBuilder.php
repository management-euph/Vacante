<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Builds the Eurosite XML request envelope.
 *
 * Every Eurosite request has the same shape (see Documentation/
 * Specificatii_API_Eurosite.pdf, "Autentificarea" + "Module XML"):
 *
 *   <Request RequestType="{type}">
 *     <AuditInfo>
 *       <RequestId>...</RequestId>
 *       <RequestUser>...</RequestUser>
 *       <RequestPass>...</RequestPass>
 *       <RequestTime>2012-09-04T18:00:46</RequestTime>
 *       <RequestLang>EN</RequestLang>
 *     </AuditInfo>
 *     <RequestDetails>{endpoint payload}</RequestDetails>
 *   </Request>
 *
 * This class owns the envelope + AuditInfo + escaping; the API client passes
 * the RequestType and the inner <RequestDetails> payload. Pure and
 * side-effect free (RequestId/RequestTime are parameters so the output is
 * deterministic and unit-testable).
 */
final class EurositeXmlBuilder
{
    public function __construct(
        private readonly string $user,
        private readonly string $password,
        private readonly string $lang = 'RO',
    ) {
    }

    /**
     * Wrap an endpoint payload in the full <Request> envelope.
     *
     * @param string $requestType e.g. "getHotelPriceRequest"
     * @param string $detailsXml the inner XML placed inside <RequestDetails>
     * @param string $requestId caller-supplied id (kept out of the class so
     *                          output stays deterministic for tests)
     * @param string $requestTime ISO-8601 datetime; '' lets the caller default
     */
    public function envelope(string $requestType, string $detailsXml, string $requestId, string $requestTime): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Request RequestType="' . self::attr($requestType) . '">' . "\n"
            . '  <AuditInfo>' . "\n"
            . '    <RequestId>' . self::esc($requestId) . '</RequestId>' . "\n"
            . '    <RequestUser>' . self::esc($this->user) . '</RequestUser>' . "\n"
            . '    <RequestPass>' . self::esc($this->password) . '</RequestPass>' . "\n"
            . '    <RequestTime>' . self::esc($requestTime) . '</RequestTime>' . "\n"
            . '    <RequestLang>' . self::esc($this->lang) . '</RequestLang>' . "\n"
            . '  </AuditInfo>' . "\n"
            . '  <RequestDetails>' . "\n"
            . $detailsXml . "\n"
            . '  </RequestDetails>' . "\n"
            . '</Request>';
    }

    /**
     * Build a single <Room> element for a search/booking payload.
     *
     * @param array<array-key, mixed> $room {code, adults, children:int[] (ages)}
     */
    public function roomElement(array $room): string
    {
        $code = self::attr(TypeCoerce::toString($room['code'] ?? ''));
        $adults = max(1, TypeCoerce::toInt($room['adults'] ?? 2));
        $childAges = TypeCoerce::toIntList($room['children'] ?? []);

        $attrs = 'Code="' . $code . '" NoAdults="' . $adults . '"';
        if ($childAges !== []) {
            $attrs .= ' NoChildren="' . count($childAges) . '"';
            $inner = '';
            foreach ($childAges as $age) {
                $inner .= '<Age>' . $age . '</Age>';
            }
            return '<Room ' . $attrs . '><Children>' . $inner . '</Children></Room>';
        }

        return '<Room ' . $attrs . '/>';
    }

    /** Escape text content for safe XML embedding. */
    public static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Escape an attribute value (same rules; kept separate for readability). */
    public static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Mask <RequestPass> before logging or displaying a request body.
     */
    public static function maskCredentials(string $xml): string
    {
        return (string) preg_replace('/<RequestPass>.*?<\/RequestPass>/', '<RequestPass>*****</RequestPass>', $xml);
    }
}
