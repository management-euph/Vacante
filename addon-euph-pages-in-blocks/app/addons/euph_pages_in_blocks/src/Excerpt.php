<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks;

/**
 * The plain-text start of a page's HTML, for the cards and the "Read more"
 * view: no markup, no scripts or styles, entities decoded, cut at a word.
 */
final class Excerpt
{
    /** Elements whose text is not part of what the page says. */
    private const DROPPED = 'script|style|template|noscript|iframe|object|svg|select|textarea|button';

    /** Tags that end a block of text: a space keeps the words apart. */
    private const BREAKS = 'br|hr|\/p|\/div|\/li|\/h[1-6]|\/tr|\/td|\/th|\/dt|\/dd|\/blockquote|\/section|\/article|\/header|\/footer|\/figcaption|\/pre|\/table|\/ul|\/ol';

    /**
     * @param int $length the most characters of text, the ellipsis aside; 0 or less keeps it whole
     */
    public static function fromHtml(string $html, int $length): string
    {
        $text = self::text($html);
        if ($length <= 0 || mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length + 1, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
        // At the last word that ends early enough; a text with no space
        // there (one long word) is cut at the length.
        $cut = $space !== false && $space >= intdiv($length * 3, 5)
            ? mb_substr($cut, 0, $space, 'UTF-8')
            : mb_substr($cut, 0, $length, 'UTF-8');

        return (preg_replace('/[\s.,;:!?\x{2013}\x{2014}-]+$/u', '', $cut) ?? $cut) . '…';
    }

    /**
     * The text of an HTML fragment, on one line.
     */
    public static function text(string $html): string
    {
        // A pattern that fails (PCRE's backtracking limit on a huge page)
        // leaves the text as it was rather than empty.
        $html = mb_scrub($html, 'UTF-8');
        $html = preg_replace('#<(' . self::DROPPED . ')\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(?:' . self::BREAKS . ')\b[^>]*>#i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $text) ?? $text);
    }
}
