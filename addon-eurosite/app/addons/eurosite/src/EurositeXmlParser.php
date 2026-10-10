<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite;

/**
 * Cleans + parses Eurosite XML responses.
 *
 * Responses are UTF-8 <Response ResponseType="..."> envelopes carrying
 * <AuditInfo> + <ResponseDetails>. This parser is defensive: it strips junk
 * before the XML, tolerates bare ampersands, and returns the inner
 * <ResponseDetails> child (the endpoint payload) so API methods don't repeat
 * the unwrap. Pure and CS-Cart-free (unit-testable).
 */
final class EurositeXmlParser
{
    /**
     * Parse a raw response body into a SimpleXMLElement, or null on failure.
     */
    public function parse(string $raw): ?\SimpleXMLElement
    {
        $cleaned = $this->clean($raw);
        if ($cleaned === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($cleaned, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $xml === false ? null : $xml;
    }

    /**
     * Return the endpoint payload element — the single child of
     * <ResponseDetails> (e.g. <getHotelPriceResponse>) — or null.
     */
    public function responseDetails(string $raw): ?\SimpleXMLElement
    {
        $xml = $this->parse($raw);
        if ($xml === null) {
            return null;
        }

        $details = $xml->ResponseDetails ?? null;
        if (!$details instanceof \SimpleXMLElement) {
            return null;
        }
        foreach ($details->children() as $child) {
            return $child; // first (and only) child = the endpoint response
        }

        return null;
    }

    /**
     * Best-effort error message extraction. Eurosite signals failures in a few
     * shapes depending on endpoint (an <Error>/<Errors> node, or an <Status>/
     * <Message> text); this scans the common ones so callers can surface a
     * reason. Returns '' when the response looks successful.
     */
    public function errorMessage(string $raw): string
    {
        $xml = $this->parse($raw);
        if ($xml === null) {
            return $raw === '' ? 'Empty response' : 'Unparseable response';
        }

        // Structured <Error><ErrorId/><ErrorText/></Error> — the live
        // server's shape (e.g. the -1000 auth refusal every caller with
        // invalid credentials gets). The bare-tag scan below misses it
        // because the <Error> element's own text is only whitespace.
        $errorText = $xml->xpath('//ErrorText');
        if (is_array($errorText) && $errorText !== []) {
            $text = trim((string) $errorText[0]);
            if ($text !== '') {
                $ids = $xml->xpath('//ErrorId');
                $id = is_array($ids) && $ids !== [] ? trim((string) $ids[0]) : '';

                return $id !== '' ? $id . ': ' . $text : $text;
            }
        }

        foreach (['Error', 'Errors', 'ErrorMessage', 'Message'] as $tag) {
            $found = $xml->xpath('//' . $tag);
            if (is_array($found) && $found !== []) {
                $text = trim((string) $found[0]);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * Strip anything before the XML declaration / first tag and escape bare
     * ampersands that are not valid entity refs.
     */
    public function clean(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $pos = stripos($raw, '<?xml');
        if ($pos === false) {
            $pos = strpos($raw, '<');
        }
        if ($pos === false) {
            return '';
        }
        if ($pos > 0) {
            $raw = substr($raw, $pos);
        }

        if (!str_contains($raw, '&')) {
            return $raw;
        }
        $parts = preg_split('/(<!\[CDATA\[.*?]]>)/s', $raw, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        foreach ($parts as $part) {
            $out .= str_starts_with($part, '<![CDATA[')
                ? $part
                : (string) preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;|#)/', '&amp;', $part);
        }

        return $out;
    }
}
