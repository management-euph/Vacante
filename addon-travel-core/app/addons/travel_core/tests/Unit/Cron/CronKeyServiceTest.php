<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Cron;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Cron\CronKeyService;
use Tygh\Registry;

/**
 * One cron secret, in Core, with a fallback that keeps existing stores alive.
 *
 * The store this came from had four independent `cron_access_key` settings:
 * three still holding the shipped `1234` and one whose row did not exist at
 * all, because the settings self-heal had never listed that add-on. Four
 * copies meant four chances to leave one unset, and nothing showed the state
 * of all four in one place.
 *
 * The fallback is the part that needs pinning hardest. The self-heal that
 * populates Core's key runs on dispatch_before_display and only in the admin
 * area — so a store that deploys this and then sees nothing but cron hits and
 * storefront traffic never gets it. Without the fallback, switching the
 * readers over would take every scheduled job on such a store down at the next
 * tick.
 */
#[CoversClass(CronKeyService::class)]
final class CronKeyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
    }

    private function clear(): void
    {
        Registry::set('addons.travel_core.cron_key', null);
        Registry::set('addons.travel_core.cron_access_key', null);
        Registry::set('addons.eurosite.cron_access_key', null);
        Registry::set('addons.sphinx_holidays.cron_access_key', null);
        Registry::set('addons.novoton_holidays.cron_access_key', null);
    }

    public function testAnUnconfiguredStoreReportsNoKey(): void
    {
        self::assertSame('', CronKeyService::get());
        self::assertFalse(CronKeyService::isConfigured());
    }

    public function testTheCoreKeyIsSharedByEveryAddon(): void
    {
        Registry::set('addons.travel_core.cron_key', 'shared-core-key');

        self::assertTrue(CronKeyService::isConfigured());
        foreach (['travel_core', 'novoton_holidays', 'sphinx_holidays', 'eurosite'] as $addon) {
            self::assertSame('shared-core-key', CronKeyService::getFor($addon));
        }
    }

    /**
     * The transitional path: a store mid-upgrade keeps authenticating with the
     * key already in its crontab.
     */
    public function testALegacyPerAddonKeyStillWorksUntilTheCoreKeyIsSet(): void
    {
        Registry::set('addons.eurosite.cron_access_key', 'eurosite-legacy');
        Registry::set('addons.sphinx_holidays.cron_access_key', 'sphinx-legacy');

        self::assertSame('eurosite-legacy', CronKeyService::getFor('eurosite'));
        self::assertSame('sphinx-legacy', CronKeyService::getFor('sphinx_holidays'));

        // An add-on with no legacy key of its own stays closed rather than
        // borrowing another add-on's.
        self::assertSame('', CronKeyService::getFor('novoton_holidays'));
    }

    /** Once Core has a key it wins outright — never both at once. */
    public function testTheCoreKeySupersedesEveryLegacyKey(): void
    {
        Registry::set('addons.travel_core.cron_key', 'the-new-one');
        Registry::set('addons.eurosite.cron_access_key', 'eurosite-legacy');

        self::assertSame('the-new-one', CronKeyService::getFor('eurosite'));
        self::assertNotSame('eurosite-legacy', CronKeyService::getFor('eurosite'));
    }

    /**
     * An empty Core key must fall through, not shadow the legacy one.
     *
     * The distinction matters because '' is the shipped state now that no
     * add-on declares a default — so on every upgraded store the Core key IS
     * empty on the first request, and that must not lock the crons out.
     */
    public function testAnEmptyCoreKeyDoesNotShadowTheLegacyOne(): void
    {
        Registry::set('addons.travel_core.cron_key', '');
        Registry::set('addons.eurosite.cron_access_key', 'eurosite-legacy');

        self::assertSame('eurosite-legacy', CronKeyService::getFor('eurosite'));
    }

    /** A key of literally "0" is a key — the auth guards agree (see CronAuthFailureTest). */
    public function testAKeyOfZeroIsAKey(): void
    {
        Registry::set('addons.travel_core.cron_key', '0');

        self::assertTrue(CronKeyService::isConfigured());
        self::assertSame('0', CronKeyService::getFor('eurosite'));
    }

    /**
     * Every provider must actually delegate, or the Core key governs the
     * endpoint while the dashboard still prints URLs built from the old one.
     */
    public function testEveryProviderConfigProviderDelegatesToTheService(): void
    {
        $repoRoot = dirname(__DIR__, 7);
        $providers = [
            'novoton_holidays' => '/addon-novoton-holidays/app/addons/novoton_holidays',
            'sphinx_holidays' => '/addon-sphinx-holidays/app/addons/sphinx_holidays',
            'eurosite' => '/eurosite_addon/app/addons/eurosite',
        ];

        foreach ($providers as $addon => $dir) {
            $src = (string) file_get_contents($repoRoot . $dir . '/src/Services/ConfigProvider.php');

            self::assertStringContainsString(
                "return CronKeyService::getFor('{$addon}');",
                $src,
                "{$addon} must read the cron key through Core, not its own settings",
            );
            self::assertStringNotContainsString(
                "getSetting('cron_access_key')",
                $src,
                "{$addon} still reads its own cron_access_key directly",
            );
        }
    }

    /**
     * Core's own entry points too — the CLI, the HTTP route and the dashboard
     * that prints the commands all have to agree on which key is live.
     */
    public function testCoreEntryPointsReadThroughTheService(): void
    {
        $root = dirname(__DIR__, 3);

        foreach ([
            '/cron.php',
            '/controllers/frontend/travel_cron.php',
            '/controllers/backend/travel_tools.php',
        ] as $rel) {
            $src = (string) file_get_contents($root . $rel);

            self::assertStringContainsString('CronKeyService::getFor(', $src, "{$rel}");
            self::assertStringNotContainsString(
                "Registry::get('addons.travel_core.cron_access_key')",
                $src,
                "{$rel} still reads the legacy key straight from the Registry",
            );
        }
    }
}
