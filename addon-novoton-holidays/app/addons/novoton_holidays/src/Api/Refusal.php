<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Api;

/**
 * Novoton refuses a request with a reply that is one <error> element. A wrong
 * API username or password gives
 *
 *     <error><err>Invalid username or password!</err></error>
 *
 * It is well-formed XML, so it parsed like an answer that held no data: the
 * price sync stored it as the package's prices (its seasons and "from" price
 * were gone), the room_price check marked the hotel "no prices", and
 * offers_update read "no changes" and moved its baseline past the outage.
 * Only a reply whose root is <error> is a refusal; an <error> nested inside
 * another element is left to the code that reads that answer.
 */
final class Refusal
{
    /** The reason Novoton gave, or null when the reply is not a refusal. */
    public static function reason(\SimpleXMLElement $reply): ?string
    {
        if (strtolower($reply->getName()) !== 'error') {
            return null;
        }

        $reason = trim((string) ($reply->err ?? ''));
        if ($reason === '') {
            $reason = trim((string) $reply);
        }

        return $reason !== '' ? $reason : 'no reason given';
    }
}
