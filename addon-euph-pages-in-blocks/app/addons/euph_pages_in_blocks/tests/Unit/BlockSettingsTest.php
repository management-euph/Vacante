<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\EuphPagesInBlocks\BlockSchema;
use Tygh\Addons\EuphPagesInBlocks\BlockSettings;
use Tygh\Addons\EuphPagesInBlocks\Enum\ContentMode;
use Tygh\Addons\EuphPagesInBlocks\Enum\Layout;

final class BlockSettingsTest extends TestCase
{
    public function testABlockWithNoSettingsGetsTheDefaults(): void
    {
        $settings = BlockSettings::fromProperties([]);

        self::assertEquals(new BlockSettings(), $settings);
        self::assertTrue($settings->showTitles);
        self::assertTrue($settings->separators);
        self::assertSame(ContentMode::Full, $settings->content);
        self::assertSame(200, $settings->excerptLength);
        self::assertFalse($settings->openFirst);
        self::assertTrue($settings->oneOpen);
        self::assertSame(3, $settings->columns);
    }

    public function testTheDefaultsAreTheSchemas(): void
    {
        $properties = [];
        foreach (Layout::cases() as $layout) {
            foreach (BlockSchema::settings($layout) as $name => $setting) {
                $properties[$name] = $setting['default_value'];
            }
        }

        self::assertEquals(new BlockSettings(), BlockSettings::fromProperties($properties));
    }

    public function testSavedValuesAreRead(): void
    {
        $settings = BlockSettings::fromProperties([
            'template' => 'addons/euph_pages_in_blocks/blocks/page_content/stacked.tpl',
            BlockSchema::SHOW_TITLES => 'N',
            BlockSchema::SEPARATORS => 'N',
            BlockSchema::CONTENT => 'excerpt',
            BlockSchema::EXCERPT_LENGTH => '120',
            BlockSchema::OPEN_FIRST => 'Y',
            BlockSchema::ONE_OPEN => 'N',
            BlockSchema::COLUMNS => '4',
        ]);

        self::assertFalse($settings->showTitles);
        self::assertFalse($settings->separators);
        self::assertSame(ContentMode::Excerpt, $settings->content);
        self::assertSame(120, $settings->excerptLength);
        self::assertTrue($settings->openFirst);
        self::assertFalse($settings->oneOpen);
        self::assertSame(4, $settings->columns);
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

    #[\PHPUnit\Framework\Attributes\DataProvider('excerptLengths')]
    public function testTheExcerptLengthStaysInRange(mixed $saved, int $expected): void
    {
        self::assertSame($expected, BlockSettings::fromProperties([BlockSchema::EXCERPT_LENGTH => $saved])->excerptLength);
    }

    public function testUnknownChoicesFallBackToTheDefaults(): void
    {
        $settings = BlockSettings::fromProperties([BlockSchema::CONTENT => 'summary', BlockSchema::COLUMNS => '7']);

        self::assertSame(ContentMode::Full, $settings->content);
        self::assertSame(3, $settings->columns);
    }
}
