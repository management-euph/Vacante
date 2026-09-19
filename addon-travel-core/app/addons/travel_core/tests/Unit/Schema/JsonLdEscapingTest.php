<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The hotel JSON-LD block is printed raw into a <script> element, so its
 * encoding flags are a security control.
 *
 * product_detail_bottom.post.tpl renders:
 *
 *     <script type="application/ld+json">
 *     {$travel_hotel_schema_json nofilter}
 *     </script>
 *
 * and every value inside that JSON — hotel name, description, address,
 * telephone, website — comes from a provider feed (Novoton/Sphinx XML) or the
 * admin, i.e. not from us. With JSON_UNESCAPED_SLASHES and no JSON_HEX_TAG, a
 * hotel whose name contains "</script>" closes the element early and anything
 * after it executes as page script, on every product page for that hotel —
 * stored XSS sourced from a supplier feed.
 *
 * JSON_HEX_TAG escapes < and > as < / >. That is still valid JSON
 * (and valid JSON-LD: consumers unescape it), and it makes the breakout
 * impossible regardless of what the feed sends.
 *
 * These pins are source-level because the surrounding function needs Tygh's
 * view and a hotel repository to run.
 */
final class JsonLdEscapingTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    private static function read(string $relative): string
    {
        $path = self::repoRoot() . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private const HOOKS = 'addon-travel-core/app/addons/travel_core/hooks/cart_hooks.php';

    private const TEMPLATE = 'addon-travel-core/design/themes/responsive/templates/addons/travel_core/'
        . 'hooks/products/product_detail_bottom.post.tpl';

    public function testSchemaJsonIsEncodedWithHexTag(): void
    {
        $src = self::read(self::HOOKS);

        self::assertSame(
            1,
            preg_match(
                '/assign\(\s*\R?\s*\'travel_hotel_schema_json\',\s*\R?\s*json_encode\(\$schema,([^)]*)\)/',
                $src,
                $m,
            ),
            'the travel_hotel_schema_json assign no longer matches — re-check its encoding flags by hand',
        );

        self::assertStringContainsString(
            'JSON_HEX_TAG',
            $m[1],
            'the JSON-LD block is printed raw inside <script>; without JSON_HEX_TAG a hotel name '
            . 'containing "</script>" becomes stored XSS',
        );
    }

    public function testTheTemplateStillPrintsItRaw(): void
    {
        // If this ever stops being true the test above can be retired — but
        // until then the flag is the only thing standing between a provider
        // feed and the page's script context.
        $tpl = self::read(self::TEMPLATE);

        self::assertStringContainsString('{$travel_hotel_schema_json nofilter}', $tpl);
        self::assertStringContainsString('application/ld+json', $tpl);
    }

    /**
     * Demonstrates the actual behaviour the flag buys, independent of our code:
     * the encoder, not a template filter, is what neutralises the payload.
     */
    public function testHexTagNeutralisesAClosingScriptTagInFeedData(): void
    {
        $schema = [
            '@type' => 'Hotel',
            'name' => 'Hotel </script><script>alert(1)</script>',
        ];

        $unsafe = (string) json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $safe = (string) json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        self::assertStringContainsString('</script>', $unsafe, 'sanity: this is the vector');
        self::assertStringNotContainsString('</script>', $safe);
        self::assertStringNotContainsString('<', $safe);
        self::assertStringNotContainsString('>', $safe);

        // Still the same data once parsed.
        self::assertSame($schema, json_decode($safe, true));
    }
}
