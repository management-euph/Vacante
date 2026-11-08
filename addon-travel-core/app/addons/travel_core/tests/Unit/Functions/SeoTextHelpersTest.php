<?php

declare(strict_types=1);

namespace {
    // Load the procedural functions-under-test in the GLOBAL namespace, exactly
    // as CS-Cart loads them.
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }
    if (!defined('CART_LANGUAGE')) {
        define('CART_LANGUAGE', 'en');
    }
    // fn_travel_core_run_long_task() returns [CONTROLLER_STATUS_REDIRECT, $url].
    if (!defined('CONTROLLER_STATUS_REDIRECT')) {
        define('CONTROLLER_STATUS_REDIRECT', 'redirect');
    }
    // Progress-bar shim — the long-task wrapper drives CS-Cart's native bar.
    if (!function_exists('fn_set_progress')) {
        function fn_set_progress(string $type, string $data = ''): void
        {
        }
    }

    // run_long_task stays in hotels.php; the SEO text helpers moved to seo.php.
    require_once dirname(__DIR__, 3) . '/functions/hotels.php';
    require_once dirname(__DIR__, 3) . '/functions/seo.php';
}

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions {

    use PHPUnit\Framework\TestCase;

    /**
     * Characterization coverage for the pure SEO/text helpers in hotels.php,
     * pinned alongside the boundary-typing paydown that wrapped their mixed
     * inputs/returns through TypeCoerce (apply_modifier's slug arm,
     * render_seo_template's array-placeholder join, render_seo_slug's fallback,
     * and run_long_task's redirect tuple). These run without the CS-Cart
     * framework — CS-Cart's fn_generate_name() is absent, so the built-in
     * slug fallback path is exercised.
     */
    final class SeoTextHelpersTest extends TestCase
    {
        public function testApplyModifierCaseTransforms(): void
        {
            $this->assertSame('hello world', fn_travel_core_apply_modifier('Hello World', 'lower'));
            $this->assertSame('HELLO WORLD', fn_travel_core_apply_modifier('Hello World', 'upper'));
            $this->assertSame('Hello World', fn_travel_core_apply_modifier('hello world', 'title'));
            $this->assertSame('Hello world', fn_travel_core_apply_modifier('hello world', 'capitalize'));
            $this->assertSame('trimmed', fn_travel_core_apply_modifier('  trimmed  ', 'trim'));
        }

        public function testApplyModifierFirstLastAndNumeric(): void
        {
            $this->assertSame('H', fn_travel_core_apply_modifier('Hotel', 'first'));
            $this->assertSame('l', fn_travel_core_apply_modifier('Hotel', 'last'));
            $this->assertSame('5.5', fn_travel_core_apply_modifier('-5.5', 'abs'));
            $this->assertSame('5', fn_travel_core_apply_modifier('4.6', 'round'));
            $this->assertSame('bold', fn_travel_core_apply_modifier('<b>bold</b>', 'strip_tags'));
        }

        public function testApplyModifierSlugFallback(): void
        {
            // No fn_generate_name() defined → the inline fallback slugifier runs.
            $this->assertSame('hello-world', fn_travel_core_apply_modifier('Hello   World!!', 'slug'));
        }

        /** Marked letters became gaps ("Gözde Hotel" → "g-zde-hotel"). */
        public function testSlugsKeepTheLettersOfMarkedOnes(): void
        {
            $this->assertSame('gozde-hotel', fn_travel_core_slugify('Gözde Hotel'));
            $this->assertSame('calis-beach', fn_travel_core_slugify('Çalış Beach'));
            $this->assertSame('sirin-otel', fn_travel_core_slugify('Şirin Otel'));
            $this->assertSame('hotel-taranesc-sinaia', fn_travel_core_slugify('Hotel Ţărănesc Șinaia'));
            $this->assertSame('st-nicholas-hotel-and-spa', fn_travel_core_slugify("St. Nicholas' Hotel & Spa"), 'CS-Cart: "&" is "and"');
            $this->assertSame('ohara-hotel', fn_travel_core_slugify('O’Hara "Hotel"'), 'CS-Cart: quotes go');
            foreach (["O'Hara", 'O‘Hara', 'O`Hara', 'O´Hara', 'Oʼhara'] as $apostrophe) {
                $this->assertSame('ohara', fn_travel_core_slugify($apostrophe), $apostrophe . ': one word, not "o-hara"');
            }
            $this->assertSame('mcdonalds-oreilly', fn_travel_core_slugify("McDonald's O'Reilly"));
            $this->assertSame('akra-v-antalya-city-turkey', fn_travel_core_render_seo_slug('{{name}}-{{city}}-{{country}}', ['name' => 'Akra -V', 'city' => 'Antalya City', 'country' => 'Turkey']));
        }

        /**
         * On a store, CS-Cart's fn_generate_name() sees the finished address:
         * it drops a space unless its letter table turns it into a dash.
         */
        public function testCsCartsGeneratorGetsTheFinishedAddress(): void
        {
            $given = [];
            $generate = static function (string $text) use (&$given): string {
                $given[] = $text;

                return (string) preg_replace('/[^a-z0-9-.]/', '', strtolower($text)); // fn_generate_name without a table
            };

            $this->assertSame('gozde-butik-otel-belek', fn_travel_core_slugify('Gözde Butik Otel Belek', $generate));
            $this->assertSame(['gozde-butik-otel-belek'], $given);
            $this->assertSame('gozde', fn_travel_core_slugify('Gözde', static fn (): string => ''), 'an empty answer falls back');
            $this->assertFalse(function_exists('fn_generate_seo_name'));
        }

        public function testApplyModifierUnknownReturnsValueUnchanged(): void
        {
            $this->assertSame('Hotel X', fn_travel_core_apply_modifier('Hotel X', 'no_such_modifier'));
        }

        public function testRenderSeoTemplateReplacesTokensAndModifiers(): void
        {
            $out = fn_travel_core_render_seo_template(
                '{{name}} - {{city|upper}}',
                ['name' => 'Hotel X', 'city' => 'paris'],
            );

            $this->assertSame('Hotel X - PARIS', $out);
        }

        public function testRenderSeoTemplateJoinsArrayPlaceholderFirstThree(): void
        {
            // Array values are trimmed, filtered, and joined — capped at 3 items.
            $out = fn_travel_core_render_seo_template(
                'Tags: {{tags}}',
                ['tags' => [' beach ', 'pool', 'spa', 'gym']],
            );

            $this->assertSame('Tags: beach, pool, spa', $out);
        }

        public function testRenderSeoTemplateStripsLeftoverTokensAndDanglingSeparators(): void
        {
            // {{missing}} resolves to '' and the trailing " - " is cleaned up.
            $out = fn_travel_core_render_seo_template(
                '{{name}} - {{missing}}',
                ['name' => 'Hotel X'],
            );

            $this->assertSame('Hotel X', $out);
        }

        public function testRenderSeoTemplateEmptyPatternReturnsEmpty(): void
        {
            $this->assertSame('', fn_travel_core_render_seo_template('', ['name' => 'Hotel X']));
        }

        public function testRenderSeoSlugFallback(): void
        {
            $slug = fn_travel_core_render_seo_slug(
                '{{name}} {{city}}',
                ['name' => 'Hotel X', 'city' => 'Paris'],
            );

            $this->assertSame('hotel-x-paris', $slug);
        }

        public function testRenderSeoSlugEmptyRenderReturnsEmpty(): void
        {
            $this->assertSame('', fn_travel_core_render_seo_slug('', ['name' => 'Hotel X']));
        }

        public function testTruncateSeoNoLimitOrUnderLimitReturnsUnchanged(): void
        {
            $this->assertSame('Short value', fn_travel_core_truncate_seo('Short value', 0));
            $this->assertSame('Short value', fn_travel_core_truncate_seo('Short value', 50));
        }

        public function testTruncateSeoCutsAtWordBoundary(): void
        {
            $this->assertSame(
                'Hello beautiful',
                fn_travel_core_truncate_seo('Hello beautiful wonderful world', 20),
            );
        }

        public function testTruncateSeoAppendsEllipsis(): void
        {
            $this->assertSame(
                'Hello beautiful…',
                fn_travel_core_truncate_seo('Hello beautiful wonderful world', 20, '…'),
            );
        }

        public function testBuildStarEmoji(): void
        {
            $this->assertSame('', fn_travel_core_build_star_emoji(0));
            $this->assertSame('★★★', fn_travel_core_build_star_emoji(3));
            $this->assertSame('★★★★★', fn_travel_core_build_star_emoji(5));
            $this->assertSame('★★★★★', fn_travel_core_build_star_emoji(7));   // clamped high
            $this->assertSame('', fn_travel_core_build_star_emoji(-2));        // clamped low
        }

        public function testSeoFieldMapContract(): void
        {
            $map = _travel_core_seo_field_map();

            $this->assertArrayHasKey('seo_field_product_name', $map);
            $this->assertSame(['seo_product_name', 'product'], $map['seo_field_product_name']);
            $this->assertSame(['seo_name_slug', 'seo_name'], $map['seo_field_name_slug']);
            $this->assertCount(6, $map);
        }

        public function testRunLongTaskRunsTaskNotifiesAndRedirects(): void
        {
            $ran = false;
            $received = null;

            $result = fn_travel_core_run_long_task(
                'travel_core.doing_work',
                function () use (&$ran): string {
                    $ran = true;
                    return 'RESULT';
                },
                'dispatch=travel_core.done',
                function (mixed $r) use (&$received): void {
                    $received = $r;
                },
            );

            $this->assertTrue($ran);
            $this->assertSame('RESULT', $received);
            $this->assertSame([CONTROLLER_STATUS_REDIRECT, 'dispatch=travel_core.done'], $result);
        }

        public function testRunLongTaskWithoutOnResultStillRedirects(): void
        {
            $result = fn_travel_core_run_long_task(
                'travel_core.doing_work',
                static fn (): bool => true,
                'dispatch=travel_core.done',
            );

            $this->assertSame([CONTROLLER_STATUS_REDIRECT, 'dispatch=travel_core.done'], $result);
        }
    }
}
