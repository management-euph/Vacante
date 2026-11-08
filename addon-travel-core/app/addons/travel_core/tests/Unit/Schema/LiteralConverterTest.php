<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * CS-Cart's own URL names (fn_generate_name) dropped the Romanian ă ș ț ş ţ
 * and the Turkish ğ ı İ, missing from its literal_converter lists:
 * "Mărăști" became "mrti". Travel Core adds them, without touching the
 * core files.
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

    public function testTheMissingLettersAreAdded(): void
    {
        $schema = self::extend([' ' => '-', 'â' => 'a', 'ö' => 'oe']);

        foreach (['ă' => 'a', 'Ă' => 'A', 'ș' => 's', 'Ș' => 'S', 'ş' => 's', 'Ş' => 'S', 'ț' => 't', 'Ț' => 'T', 'ţ' => 't', 'Ţ' => 'T', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I'] as $letter => $plain) {
            self::assertSame($plain, $schema[$letter] ?? null, $letter);
        }
        self::assertSame('oe', $schema['ö'], "CS-Cart's own entries are kept");
        self::assertSame('-', $schema[' ']);

        // As fn_generate_name() then does: strtr, lower case, keep a-z 0-9 - .
        $name = (string) preg_replace('/[^a-z0-9-\.]/', '', mb_strtolower(strtr('Mărăști Ağaoğlu Işıklı Țărănesc Şinaia', $schema)));
        self::assertSame('marasti-agaoglu-isikli-taranesc-sinaia', $name);
    }

    public function testOnlyTheLatinListIsExtended(): void
    {
        $dir = dirname(__DIR__, 3) . '/schemas/literal_converter';

        self::assertSame(['schema.post.php'], array_values(array_diff((array) scandir($dir), ['.', '..'])), 'not general.php: with non-Latin URL names enabled the letters stay on purpose');
    }
}
