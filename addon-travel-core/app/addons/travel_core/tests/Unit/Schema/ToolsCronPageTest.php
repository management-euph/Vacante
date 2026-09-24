<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The Tools & Cron page's contracts that the rest of the suite does not pin.
 *
 * Source-text assertions: the template renders inside CS-Cart's Smarty, which
 * this repository does not ship.
 */
final class ToolsCronPageTest extends TestCase
{
    private static function tpl(string $name): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 6) . '/design/backend/templates/addons/travel_core/views/travel_tools/' . $name,
        );
    }

    /** Admin AJAX navigation runs only the scripts inside the mainbox capture. */
    public function testTheScriptLoadsInsideTheMainboxCapture(): void
    {
        $tpl = self::tpl('manage.tpl');

        $open = strpos($tpl, '{capture name="mainbox"}');
        $script = strpos($tpl, '{script src="js/addons/travel_core/tools-cron.js"}');
        self::assertIsInt($open);
        self::assertIsInt($script);
        $close = strpos($tpl, '{/capture}', $script);
        self::assertIsInt($close);
        self::assertGreaterThan($open, $script);
        self::assertFalse(strpos(substr($tpl, $open, $script - $open), '{/capture}'), 'the script is outside the capture');
    }

    /**
     * A job's error text is whatever the job threw — a remote API's message
     * included — so it is printed escaped, never as markup.
     */
    public function testAJobErrorIsPrintedEscaped(): void
    {
        $tpl = self::tpl('manage.tpl');

        self::assertStringContainsString('{$core_job.record.error|escape:html}', $tpl);
        self::assertDoesNotMatchRegularExpression('/\{\$core_job\.record\.error\}/', $tpl);
    }

    /**
     * Provider rows come from the providers; the page names none of them.
     * A disabled provider must leave no row behind.
     */
    public function testThePageHardCodesNoProvider(): void
    {
        $tpl = (string) preg_replace('/\{\*.*?\*\}/s', '', self::tpl('manage.tpl'));

        foreach (['eurosite', 'sphinx', 'novoton'] as $provider) {
            self::assertStringNotContainsStringIgnoringCase($provider, $tpl);
        }
        self::assertStringContainsString('{foreach from=$provider_rows item=row}', $tpl);
    }

    /** Every health state reads in words, not colour alone. */
    public function testEveryHealthStateHasAWordedLabel(): void
    {
        $health = self::tpl('components/health.tpl');

        foreach (['refused', 'failed', 'stalled', 'quiet', 'never'] as $state) {
            self::assertStringContainsString("\$health.state == \"{$state}\"", $health);
            self::assertStringContainsString("travel_core.tools_health_{$state}\")", $health);
        }
        self::assertStringContainsString('travel_core.tools_health_ok")', $health);
    }
}
