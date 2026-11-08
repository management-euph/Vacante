<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * CS-Cart's own URL names (fn_generate_name) dropped the Romanian ă ș ț ş ţ
 * and the Turkish ğ ı İ, missing from its literal_converter lists ("Mărăști"
 * became "mrti"), and wrote ö as "oe". Travel Core's schema.post.php sets one
 * rule for every language: a letter with a mark becomes its plain letter,
 * as the add-ons' own addresses do. No core file is touched.
 */
final class LiteralConverterTest extends TestCase
{
    /** @return array<string, string> the list once CS-Cart applied the add-on's file to $schema */
    private static function extend(array $schema): array
    {
        /** @var array<string, string> */
        return (static function (array $schema): array {
            return require dirname(__DIR__, 3) . '/schemas/literal_converter/schema.post.php';
        })($schema);
    }

    /** As fn_generate_name() then does: strtr, lower case, keep a-z 0-9 - . , one dash */
    private static function urlName(string $name, array $schema): string
    {
        $name = (string) preg_replace('/[^a-z0-9-\.]/', '', mb_strtolower(strtr($name, $schema)));

        return (string) preg_replace('/-{2,}/', '-', $name);
    }

    public function testRomanianAndTurkishLettersBecomePlain(): void
    {
        // A slice of CS-Cart's stock lists (general.php, umlauts.php)
        $schema = self::extend([' ' => '-', '&' => '-and-', 'â' => 'a', 'î' => 'i', 'ö' => 'oe', 'ü' => 'u', 'я' => 'ya']);

        foreach (['ă' => 'a', 'Ă' => 'A', 'â' => 'a', 'Â' => 'A', 'î' => 'i', 'Î' => 'I', 'ș' => 's', 'Ș' => 'S', 'ş' => 's', 'Ş' => 'S', 'ț' => 't', 'Ț' => 'T', 'ţ' => 't', 'Ţ' => 'T'] as $letter => $plain) {
            self::assertSame($plain, $schema[$letter] ?? null, 'Romanian ' . $letter);
        }
        foreach (['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O', 'ü' => 'u', 'Ü' => 'U'] as $letter => $plain) {
            self::assertSame($plain, $schema[$letter] ?? null, 'Turkish ' . $letter);
        }
        self::assertSame('-and-', $schema['&'], "CS-Cart's punctuation stays");
        self::assertSame('ya', $schema['я'], "and its Cyrillic list");

        self::assertSame('marasti-ingust-ambru-taranesc-sinaia', self::urlName('Mărăști Îngust Âmbru Țărănesc Şinaia', $schema));
        self::assertSame('gozde-butik-otel-belek', self::urlName('Gözde Butik Otel Belek', $schema), 'ö is o, not "oe": as Booking');
        self::assertSame('agaoglu-isikli-resort-and-spa', self::urlName('Ağaoğlu Işıklı Resort & Spa', $schema));
    }

    /** One rule: the same as the add-ons' own addresses (intl Latin-ASCII), for every letter listed. */
    public function testEveryLetterFollowsTheOneRule(): void
    {
        $schema = self::extend([]);

        self::assertGreaterThan(190, count($schema));
        $latinAscii = class_exists(\Transliterator::class) ? \Transliterator::create('Any-Latin; Latin-ASCII') : null;
        foreach ($schema as $letter => $plain) {
            self::assertMatchesRegularExpression('/^\p{L}$/u', (string) $letter, 'letters only');
            self::assertMatchesRegularExpression('/^[A-Za-z]+$/', $plain, $letter . ': plain letters only');
            if ($latinAscii !== null) {
                self::assertSame($latinAscii->transliterate((string) $letter), $plain, (string) $letter);
            }
        }
    }

    public function testOnlyTheLatinListIsExtended(): void
    {
        $dir = dirname(__DIR__, 3) . '/schemas/literal_converter';

        self::assertSame(['schema.post.php'], array_values(array_diff((array) scandir($dir), ['.', '..'])), 'not general.php: with non-Latin URL names enabled the letters stay on purpose');
    }
}
