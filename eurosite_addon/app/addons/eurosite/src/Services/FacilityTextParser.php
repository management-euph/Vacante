<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

/**
 * Facility chips from Eurosite's own product text.
 *
 * getProductInfo has no structured facility list (the <Facilities> element
 * exists only as a SEARCH filter). The tour operator writes them into the
 * free-text <DescriptionDet> instead, as one line:
 *
 *   "…<br><br>Facilitati: aer conditionat, cablu tv, restaurant, bar, Wi-fi,
 *    grup sanitar propriu, terasa, parcare gratuita.<br><br>…"
 *
 * (live SCANDINAVIA CM, 27.09.2026 — the text arrives double-encoded:
 * "&amp;lt;br&amp;gt;"). This finds that line and splits it into chip labels,
 * keeping the operator's own words. Pure: no DB, no CS-Cart.
 */
final class FacilityTextParser
{
    /** "Facilitati:", "Facilități hotel:", "Facilities:" — at the start of a line. */
    private const HEADING = '/^\s*(?:facilit(?:a|ă)(?:t|ț|ţ)i|facilities)(?:\s+hotel)?\s*:\s*(.*)$/iu';

    /**
     * @return list<string> chip labels, first letter capitalised, de-duplicated
     */
    public static function facilities(string $descriptionDet): array
    {
        $lines = preg_split('/\R/u', self::plainText($descriptionDet)) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match(self::HEADING, $line, $m) !== 1) {
                continue;
            }
            $list = trim($m[1]);
            if ($list !== '') {
                // The list is one sentence: "…, bar. Hotelul are 3 etaje…"
                // must not turn the next sentence into chips.
                return self::items((string) preg_replace('/\.\s+\p{Lu}.*$/su', '', $list));
            }
            // "Facilitati:" alone, the items as "- piscina" lines below it.
            $bullets = [];
            foreach (array_slice($lines, $i + 1) as $next) {
                if (preg_match('/^\s*[-•*·]\s*(.+)$/u', $next, $b) === 1) {
                    $bullets[] = $b[1];
                } elseif (trim($next) !== '' || $bullets !== []) {
                    break;
                }
            }

            return self::items(implode(', ', $bullets));
        }

        return [];
    }

    /**
     * Eurosite's text fields arrive encoded twice ("&amp;lt;br&amp;gt;"):
     * decode until stable (at most 3 passes) — real HTML comes out.
     */
    public static function decode(string $raw): string
    {
        $text = $raw;
        for ($i = 0; $i < 3 && str_contains($text, '&'); $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }

        return $text;
    }

    /** Decoded, <br>/<p> as line breaks, tags stripped. */
    private static function plainText(string $raw): string
    {
        $text = self::decode($raw);
        $text = (string) preg_replace('~<\s*(br|/p|p|/li|li|/div|div)\b[^>]*>~iu', "\n", $text);
        $text = strip_tags($text);
        $text = str_replace(["\u{00A0}", "\r"], [' ', "\n"], $text);

        return $text;
    }

    /**
     * Split on commas outside parentheses; trim; drop the closing full stop.
     *
     * @return list<string>
     */
    private static function items(string $list): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (mb_str_split($list) as $ch) {
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')' && $depth > 0) {
                $depth--;
            }
            if (($ch === ',' || $ch === ';') && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $parts[] = $current;

        $out = [];
        foreach ($parts as $part) {
            $label = trim((string) preg_replace('/\s+/u', ' ', $part));
            $label = rtrim($label, " .\t");
            if ($label === '') {
                continue;
            }
            $label = mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
            if (!in_array($label, $out, true)) {
                $out[] = $label;
            }
        }

        return $out;
    }
}
