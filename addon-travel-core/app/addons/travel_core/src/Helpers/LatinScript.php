<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

/**
 * Place names in Latin letters for a Romanian storefront.
 *
 * OpenStreetMap holds Bulgarian streets and towns in Cyrillic; many have no
 * English or Romanian name, so even a lookup that asks for those answers
 * "Нептун" or "Китен". Cyrillic is written out with Bulgaria's official
 * transliteration (the Streamlined System, used on its road signs: "Kiten",
 * "Nesebar", "Sveti Vlas"); other non-Latin scripts, such as Greek, go
 * through ICU's Any-Latin when the intl extension is present. Text already
 * in Latin letters, diacritics included, is returned as it is.
 */
final class LatinScript
{
    private const array BULGARIAN = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ж' => 'zh', 'з' => 'z',
        'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
        'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sht', 'ъ' => 'a', 'ь' => 'y', 'ю' => 'yu', 'я' => 'ya',
        // Letters of neighbouring Cyrillic alphabets that turn up in OSM names.
        'ё' => 'yo', 'ы' => 'y', 'э' => 'e', 'є' => 'ye', 'і' => 'i', 'ї' => 'yi', 'ґ' => 'g',
        'ђ' => 'dj', 'ј' => 'j', 'љ' => 'lj', 'њ' => 'nj', 'ћ' => 'c', 'џ' => 'dz', 'ѓ' => 'gj', 'ќ' => 'kj', 'ѕ' => 'dz',
    ];

    public static function toLatin(string $text): string
    {
        if (preg_match('/\p{Cyrillic}/u', $text) === 1) {
            $text = (string) preg_replace_callback('/\p{Cyrillic}+/u', self::cyrillicWord(...), $text);
        }
        if (preg_match('/[^\p{Latin}\p{Common}\p{Inherited}]/u', $text) === 1 && class_exists(\Transliterator::class)) {
            $latin = \Transliterator::create('Any-Latin')?->transliterate($text);
            $text = is_string($latin) ? $latin : $text;
        }

        return $text;
    }

    /**
     * One run of Cyrillic letters; a capital keeps its case on the first
     * Latin letter ("Щ" -> "Sht"), and a word written in capitals stays in
     * capitals ("НЕСЕБЪР" -> "NESEBAR").
     *
     * @param array<array-key, string> $m
     */
    private static function cyrillicWord(array $m): string
    {
        $word = $m[0];
        $allCaps = mb_strlen($word) > 1 && mb_strtoupper($word) === $word;
        $out = '';
        foreach (mb_str_split($word) as $letter) {
            $lower = mb_strtolower($letter);
            $latin = self::BULGARIAN[$lower] ?? $letter;
            $out .= $lower === $letter ? $latin : ($allCaps ? strtoupper($latin) : ucfirst($latin));
        }

        return $out;
    }
}
