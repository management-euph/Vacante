<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

/**
 * A supplier's text, which may carry HTML, as plain text for a page that
 * escapes it: the tags gone (scripts and styles with their content), the
 * entities decoded, line breaks kept, blocks (paragraphs, list items,
 * headings) as paragraphs separated by one blank line, spaces collapsed.
 */
final class PlainText
{
    public static function fromHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $text = (string) preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text);
        $text = (string) preg_replace('#<\s*/?\s*(p|div|li|ul|ol|h[1-6]|tr|table|blockquote)\b[^>]*>#i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);

        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[ \t\f\v]+/u', ' ', $line)),
            explode("\n", $text),
        );

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /**
     * The text's paragraphs (split by blank lines), each with its own line
     * breaks.
     *
     * @return list<string>
     */
    public static function paragraphs(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split("/\n\s*\n/", $text) ?: []),
            static fn (string $paragraph): bool => $paragraph !== '',
        ));
    }
}
