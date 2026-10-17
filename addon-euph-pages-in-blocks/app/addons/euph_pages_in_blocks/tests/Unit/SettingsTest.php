<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\Tests\Support\Schema;

/**
 * The template settings of a block, read from its properties.
 */
final class SettingsTest extends TestCase
{
    private const DEFAULTS = [
        'show_titles' => true,
        'separators' => true,
        'content' => 'full',
        'excerpt_length' => 200,
        'open_first' => false,
        'one_open' => true,
        'columns' => 3,
    ];

    public function testABlockWithNoSettingsGetsTheDefaults(): void
    {
        self::assertSame(self::DEFAULTS, fn_euph_pages_in_blocks_settings([]));
    }

    public function testTheDefaultsAreTheSchemas(): void
    {
        $properties = [];
        foreach (Schema::LAYOUTS as $layout) {
            foreach (Schema::settings($layout) as $name => $setting) {
                $properties[$name] = $setting['default_value'];
            }
        }

        self::assertCount(7, $properties);
        self::assertSame(self::DEFAULTS, fn_euph_pages_in_blocks_settings($properties));
    }

    public function testSavedValuesAreRead(): void
    {
        self::assertSame([
            'show_titles' => false,
            'separators' => false,
            'content' => 'excerpt',
            'excerpt_length' => 120,
            'open_first' => true,
            'one_open' => false,
            'columns' => 4,
        ], fn_euph_pages_in_blocks_settings([
            'template' => 'addons/euph_pages_in_blocks/blocks/page_content/stacked.tpl',
            'euph_pib_show_titles' => 'N',
            'euph_pib_separators' => 'N',
            'euph_pib_content' => 'excerpt',
            'euph_pib_excerpt_length' => '120',
            'euph_pib_open_first' => 'Y',
            'euph_pib_one_open' => 'N',
            'euph_pib_columns' => '4',
        ]));
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function excerptLengths(): iterable
    {
        yield 'empty' => ['', 200];
        yield 'zero' => ['0', 200];
        yield 'negative' => ['-5', 200];
        yield 'text' => ['about 100', 200];
        yield 'too short' => ['10', 40];
        yield 'too long' => ['50000', 2000];
        yield 'spaces' => [' 300 ', 300];
        yield 'int' => [150, 150];
    }

    #[DataProvider('excerptLengths')]
    public function testTheExcerptLengthStaysInRange(mixed $saved, int $expected): void
    {
        self::assertSame($expected, fn_euph_pages_in_blocks_settings(['euph_pib_excerpt_length' => $saved])['excerpt_length']);
    }

    public function testUnknownChoicesFallBackToTheDefaults(): void
    {
        $settings = fn_euph_pages_in_blocks_settings(['euph_pib_content' => 'summary', 'euph_pib_columns' => '7']);

        self::assertSame('full', $settings['content']);
        self::assertSame(3, $settings['columns']);
    }
}
