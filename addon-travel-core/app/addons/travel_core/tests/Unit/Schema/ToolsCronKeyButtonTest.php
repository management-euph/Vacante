<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The Tools page must be able to MINT the cron key, not just display it.
 *
 * Every store that got this wrong got it wrong the same way: the key was a
 * free-text field an operator had to invent a value for, so three of the four
 * kept the shipped `1234` and the fourth was never set at all. A button that
 * produces 16 random bytes removes the decision.
 *
 * Source-text assertions: the controller runs inside CS-Cart's dispatcher and
 * writes through Tygh\Settings, neither of which exists in this repository.
 */
final class ToolsCronKeyButtonTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    private static function controller(): string
    {
        return self::src('controllers/backend/travel_tools.php');
    }

    private static function template(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 6)
            . '/design/backend/templates/addons/travel_core/views/travel_tools/manage.tpl',
        );
    }

    public function testTheButtonPostsToAModeThatMintsThroughTheService(): void
    {
        $tpl = self::template();
        $controller = self::controller();

        self::assertStringContainsString('"travel_tools.generate_cron_key"|fn_url', $tpl);
        self::assertStringContainsString("if (\$mode === 'generate_cron_key')", $controller);
        self::assertStringContainsString('CronKeyService::generate()', $controller);

        // The mode must be inside the POST guard. A cron key mintable by GET
        // is mintable by any link, image or prefetch that reaches an
        // authenticated admin's browser.
        $postPos = strpos($controller, "\$_SERVER['REQUEST_METHOD'] === 'POST'");
        $modePos = strpos($controller, "\$mode === 'generate_cron_key'");
        self::assertIsInt($postPos);
        self::assertIsInt($modePos);
        self::assertLessThan($modePos, $postPos);

        // CS-Cart's CSRF token, exactly as the page's other POST forms carry it.
        $formPos = strpos($tpl, '"travel_tools.generate_cron_key"|fn_url');
        self::assertIsInt($formPos);
        self::assertStringContainsString(
            'name="security_hash" value="{$security_hash}"',
            substr($tpl, $formPos, 400),
        );
    }

    /**
     * A failed mint must say so. generate() returns '' rather than throwing,
     * and the mode that ignored that would redirect to a page still showing
     * the old key while the operator believed they had rotated.
     */
    public function testAFailedMintIsReportedAsAnError(): void
    {
        $controller = self::controller();

        $pos = strpos($controller, "\$mode === 'generate_cron_key'");
        self::assertIsInt($pos);
        $block = substr($controller, $pos, 2000);

        self::assertStringContainsString("if (\$fresh === '') {", $block);
        self::assertStringContainsString("fn_set_notification('E'", $block);
        // And a SUCCESS is a warning, not a notice: rotating just invalidated
        // every crontab entry on the server.
        self::assertStringContainsString("fn_set_notification('W'", $block);
    }

    /**
     * The new key must never be put in the notification.
     *
     * CS-Cart stores notifications per user and replays them on the next page;
     * a secret in one outlives the page that needs it. The page redirected to
     * prints the key and the URLs built from it, which is where it belongs.
     */
    public function testTheMintedKeyIsNotEchoedIntoTheNotification(): void
    {
        $controller = self::controller();

        $pos = strpos($controller, "\$mode === 'generate_cron_key'");
        self::assertIsInt($pos);
        $block = substr($controller, $pos, 2000);

        self::assertStringNotContainsString('. $fresh', $block);
        self::assertStringNotContainsString('{$fresh}', $block);
    }

    /**
     * REGRESSION: the page carried an "open in a new tab" link whose href was
     * the cron URL — key and all.
     *
     * Following it wrote the secret into the browser's history, and any
     * redirect out of the job would have carried it in a Referer header to
     * whatever it redirected to. The "Run Now" button beside it already did
     * the same work over an authenticated admin POST, so the link bought
     * nothing.
     */
    public function testNoLinkNavigatesToAUrlCarryingTheKey(): void
    {
        $tpl = self::template();

        // Comments describe the removal; they must not satisfy the assertion.
        $stripped = (string) preg_replace('/\{\*.*?\*\}/s', '', $tpl);

        self::assertStringNotContainsString('href="{$job.url}"', $stripped);
        self::assertStringNotContainsString('target="_blank"', $stripped);

        // The URLs are still PRINTED — they are what the operator pastes into
        // the crontab. Displaying is not navigating.
        self::assertStringContainsString('{$job.url}', $stripped);
    }

    /**
     * A store with no key gets the button, not just a link to the settings page.
     *
     * The settings field is where this went wrong: it is a password input an
     * operator has to invent a value for. Sending them there and nowhere else
     * is what the page used to do.
     */
    public function testTheNoKeyStateOffersTheButton(): void
    {
        $tpl = self::template();

        $elsePos = strpos($tpl, '{else}');
        self::assertIsInt($elsePos);
        $noKey = substr($tpl, $elsePos, 900);

        self::assertStringContainsString('travel_core.tools_no_cron_key', $noKey);
        self::assertStringContainsString('"travel_tools.generate_cron_key"|fn_url', $noKey);
        self::assertStringContainsString('travel_core.tools_cron_key_generate', $noKey);
    }

    /**
     * The page must say when it is showing a LEGACY key rather than the shared
     * one, because on that store the other providers may still be on keys of
     * their own — the exact state this change exists to end.
     */
    public function testThePageDistinguishesTheSharedKeyFromTheLegacyFallback(): void
    {
        $controller = self::controller();
        $tpl = self::template();

        self::assertStringContainsString('CronKeyService::isConfigured()', $controller);
        self::assertStringContainsString("\$view->assign('cron_key_is_shared'", $controller);
        self::assertStringContainsString('{if !$cron_key_is_shared}', $tpl);
        self::assertStringContainsString('travel_core.tools_cron_key_legacy', $tpl);
    }

    /**
     * Every form that mints the SHARED key carries a CSRF token — including
     * Eurosite's, which predates this change.
     *
     * Eurosite's dashboard has had a generate button for a while, and neither
     * of its forms carried a security_hash. That was a gap when the button
     * rotated only Eurosite's own key; repointing it at the shared key raised
     * the payoff, because one forged POST now breaks the scheduled jobs of all
     * three providers at once.
     */
    public function testEveryFormThatMintsTheSharedKeyIsCsrfProtected(): void
    {
        $eurosite = (string) file_get_contents(
            dirname(__DIR__, 7)
            . '/eurosite_addon/design/backend/templates/addons/eurosite/views/eurosite/manage.tpl',
        );

        $forms = substr_count($eurosite, 'value="eurosite.generate_cron_key"');
        self::assertGreaterThanOrEqual(2, $forms, 'the eurosite generate forms have moved or gone');
        self::assertSame(
            $forms,
            substr_count($eurosite, 'name="security_hash" value="{$security_hash}"'),
            'an eurosite form posts generate_cron_key without a CSRF token',
        );

        // Travel Core's own two forms, same rule.
        $tpl = self::template();
        self::assertSame(
            substr_count($tpl, '"travel_tools.generate_cron_key"|fn_url'),
            substr_count($tpl, 'name="security_hash" value="{$security_hash}"')
                - substr_count($tpl, '"travel_tools.`$job.run_action`"|fn_url'),
            'a Travel Core generate form posts without a CSRF token',
        );
    }

    /**
     * The rotation notice must not send the operator to a page that does not
     * have what it promises.
     *
     * It used to say "copy the commands below ... for every travel addon", but
     * this page lists Travel Core's own cron jobs only — the Eurosite, Sphinx
     * and Novoton commands live on their own dashboards, and they broke too.
     * An operator who followed it literally would have re-copied one job and
     * believed they were done.
     */
    public function testTheRotationNoticeNamesWhereTheOtherCommandsActuallyAre(): void
    {
        $xml = self::src('addon.xml');

        $pos = strpos($xml, '<item lang="en" id="travel_core.tools_cron_key_rotated">');
        self::assertIsInt($pos);
        $en = substr($xml, $pos, 400);

        self::assertStringNotContainsString('the commands below', $en);
        foreach (['Eurosite', 'Sphinx', 'Novoton'] as $provider) {
            self::assertStringContainsString($provider, $en, "the notice never mentions {$provider}");
        }
    }

    /**
     * Every label the page asks for must be seeded, or it renders as a raw key
     * on an already-installed store (AdminLangKeysSeededTest bans the class;
     * this names the specific ones, so a half-done rename fails here too).
     */
    public function testTheNewLabelsShipInBothLanguages(): void
    {
        $xml = self::src('addon.xml');

        foreach ([
            'tools_cron_key_title',
            'tools_cron_key_desc',
            'tools_cron_key_generate',
            'tools_cron_key_rotate',
            'tools_cron_key_rotate_confirm',
            'tools_cron_key_rotated',
            'tools_cron_key_failed',
            'tools_cron_key_legacy',
        ] as $key) {
            foreach (['en', 'ro'] as $lang) {
                self::assertStringContainsString(
                    "<item lang=\"{$lang}\" id=\"travel_core.{$key}\">",
                    $xml,
                    "travel_core.{$key} is missing its {$lang} label",
                );
            }
        }
    }

    /**
     * Rotating is destructive to a running schedule, so it asks first.
     *
     * Not on the no-key branch: there is nothing to break there, and a
     * confirmation on the one action that fixes a broken store is friction for
     * no benefit.
     */
    public function testRotatingAnExistingKeyAsksForConfirmationFirst(): void
    {
        $tpl = self::template();

        $rotatePos = strpos($tpl, 'travel_core.tools_cron_key_rotate"');
        self::assertIsInt($rotatePos);
        $rotate = substr($tpl, max(0, $rotatePos - 400), 500);
        self::assertStringContainsString('cm-confirm', $rotate);
        self::assertStringContainsString('data-ca-confirm-text', $rotate);

        $elsePos = strpos($tpl, '{else}');
        self::assertIsInt($elsePos);
        self::assertStringNotContainsString('cm-confirm', substr($tpl, $elsePos, 900));
    }
}
