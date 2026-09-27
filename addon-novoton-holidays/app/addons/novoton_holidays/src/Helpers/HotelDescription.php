<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

/**
 * The hotel text from a hotel_description response, as product HTML.
 *
 * Novoton sends the description as HTML ELEMENTS inside <Description>
 * (<p>, <span>, <strong>, <br/>), not as text. Every creator read it with
 * (string) $response->Description, which in SimpleXML is only the text
 * directly inside the element: whitespace. So every product was created
 * with no description, and the SEO engine, correctly, wrote none.
 *
 * The HTML is Word-pasted: inline styles (color:null, Calibri 14px) that
 * fight the storefront theme, spans wrapping spans, and empty &nbsp;
 * paragraphs. Those are dropped; the structure (paragraphs, bold, lists,
 * line breaks) stays.
 */
final class HotelDescription
{
    public static function fromResponse(mixed $response): string
    {
        if (!$response instanceof \SimpleXMLElement || !isset($response->Description)) {
            return '';
        }

        $node = $response->Description;
        $html = '';
        foreach ($node->children() as $child) {
            $html .= (string) $child->asXML();
        }
        if ($html === '') {
            // A plain-text description (no child elements) still reads as text.
            $html = htmlspecialchars((string) $node, ENT_NOQUOTES);
        }

        return self::tidy($html);
    }

    public static function tidy(string $html): string
    {
        // The feed's own HTML entities (&nbsp;, &euml;, &ldquo;) are not XML,
        // so the response cleaner escaped them once more (&amp;nbsp;); asXML()
        // keeps that. Undo it so the browser sees the entity.
        $html = str_replace('&amp;', '&', $html);

        // Inline styles pasted from Word.
        $html = (string) preg_replace('/\s+style="[^"]*"/i', '', $html);

        // Spans left with no attributes do nothing: unwrap them (nested ones too).
        do {
            $before = $html;
            $html = (string) preg_replace('#<span>(.*?)</span>#s', '$1', $html);
        } while ($html !== $before);

        // Paragraphs holding nothing but spaces / &nbsp; / a <br/>.
        $html = (string) preg_replace('#<p>(?:\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html);

        return trim($html);
    }
}
