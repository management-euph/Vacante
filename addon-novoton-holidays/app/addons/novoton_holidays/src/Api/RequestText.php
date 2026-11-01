<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\TravelCore\Helpers\LatinScript;

/**
 * Text the store writes into a Novoton request, in plain Latin letters.
 *
 * Every request is declared windows-1251 (ApiClientBase::xmlHeader) but is
 * built and posted as UTF-8, and windows-1251 has no Romanian letters: a
 * guest named "Ștefan" or a remark "Vă rugăm camere alăturate" would reach
 * Novoton garbled. So guest names, holders, remarks and comments are
 * written without diacritics ("Stefan", "Va rugam camere alaturate"),
 * Cyrillic in Bulgaria's official transliteration (LatinScript: "Николай
 * Иванов" → "Nikolay Ivanov"), and, with the intl extension, any other
 * letter as ICU's Latin-ASCII spells it ("Müller-Groß" → "Muller-Gross")
 * while what has no Latin spelling at all (an emoji) is left out.
 *
 * Only what is sent changes: the booking keeps the names and notes as the
 * guest typed them.
 */
final class RequestText
{
    /** Romanian letters (both comma and cedilla forms) and the euro sign, with or without intl. */
    private const array LETTERS = [
        'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
        '€' => 'EUR',
    ];

    public static function latin(string $text): string
    {
        $text = strtr(LatinScript::toLatin($text), self::LETTERS);
        if (preg_match('/[^\x00-\x7F]/', $text) !== 1 || !class_exists(\Transliterator::class)) {
            return $text;
        }
        $ascii = \Transliterator::create('Latin-ASCII')?->transliterate($text);
        if (!is_string($ascii)) {
            return $text;
        }
        $ascii = (string) preg_replace('/[^\x00-\x7F]+/', '', $ascii);

        return trim((string) preg_replace('/ {2,}/', ' ', $ascii));
    }
}
