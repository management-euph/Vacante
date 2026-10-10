<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Cron;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Tests\Support\SourceCode;

/**
 * Every travel add-on reports its cron to Travel Core, from the right places.
 *
 * Travel Core -> Tools shows each add-on's cron health from CronRunLog. That
 * is only as true as the reporting: an add-on whose dispatcher stops calling
 * record() shows "No runs recorded" forever, and one whose entry point stops
 * calling refused() hides the one symptom of a crontab left on an old key.
 * Both would pass every other test, so they are pinned here, for all three
 * providers at once.
 *
 * Source assertions on comment-stripped, brace-scoped bodies (SourceCode), so
 * a comment or a namesake elsewhere in the file cannot satisfy them.
 */
#[CoversNothing]
final class CronRunReportingTest extends TestCase
{
    /** addon id => [repo dir, dispatcher-relative path, HTTP controller] */
    private const array PROVIDERS = [
        'eurosite' => ['addon-eurosite/app/addons/eurosite', 'eurosite_cron.php'],
        'sphinx_holidays' => ['addon-sphinx-holidays/app/addons/sphinx_holidays', 'sphinx_cron.php'],
        'novoton_holidays' => ['addon-novoton-holidays/app/addons/novoton_holidays', 'novoton_cron.php'],
    ];

    private static function path(string $rel): string
    {
        return dirname(__DIR__, 7) . '/' . $rel;
    }

    /** Every run through a dispatcher is recorded — except read-only status/reset/debug requests. */
    public function testEveryDispatcherRecordsItsRunsUnderItsOwnAddonId(): void
    {
        foreach (self::PROVIDERS as $addon => [$dir]) {
            $body = SourceCode::body(self::path($dir . '/src/Cron/CronDispatcher.php'), 'public function dispatch(');

            self::assertStringContainsString(
                "CronRunLog::record('{$addon}', \$mode,",
                $body,
                "{$addon}'s dispatcher does not record its runs — its health would read 'no runs' forever",
            );
            self::assertStringContainsString('$isReadOnly', $body);
            // The job itself still runs, and its result is still what the caller gets.
            self::assertStringContainsString('$command->execute(', $body);
        }
    }

    /** The wrong-key refusal is the symptom of a botched rotation — every entry point must note it. */
    public function testEveryHttpEntryPointRecordsAWrongKeyRefusal(): void
    {
        foreach (self::PROVIDERS as $addon => [$dir, $http]) {
            $src = SourceCode::code(self::path($dir . '/controllers/frontend/' . $http));
            self::assertStringContainsString(
                "CronRunLog::refused('{$addon}',",
                $src,
                "{$addon}'s cron URL refuses a wrong key without telling Travel Core",
            );
        }

        $core = SourceCode::code(self::path('addon-travel-core/app/addons/travel_core/controllers/frontend/travel_cron.php'));
        self::assertStringContainsString("CronRunner::authenticate(\$storedKey, \$providedKey, 'Travel Core', 'travel_core');", $core);
    }

    /** The CLI entry points refuse through CronRunner, which records under the id they pass. */
    public function testEveryCliEntryPointPassesItsAddonIdToAuthenticate(): void
    {
        foreach (self::PROVIDERS as $addon => [$dir]) {
            $src = SourceCode::code(self::path($dir . '/cron.php'));
            self::assertMatchesRegularExpression(
                "/CronRunner::authenticate\\([^;]*'{$addon}'\\);/s",
                $src,
                "{$addon}/cron.php does not name its add-on, so CLI refusals are not recorded",
            );
        }

        $runner = SourceCode::body(
            self::path('addon-travel-core/app/addons/travel_core/src/Cron/CronRunner.php'),
            'public static function authenticate(',
        );
        self::assertStringContainsString("CronRunLog::refused(\$addon, \$providedKey !== '');", $runner);
    }

    /** Each provider declares its own cron row — Travel Core never hard-codes another add-on's jobs. */
    public function testEveryProviderDeclaresItsCronFromItsOwnInit(): void
    {
        $anchors = [
            'eurosite' => ['eurosite.manage', 'eurosite-scheduled-jobs', 'addon-eurosite/design/backend/templates/addons/eurosite/views/eurosite/manage.tpl'],
            'sphinx_holidays' => ['sphinx_holidays.manage', 'sphinx-cron-commands', 'addon-sphinx-holidays/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/manage.tpl'],
            'novoton_holidays' => ['novoton_holidays.manage', 'novoton-cron-jobs', 'addon-novoton-holidays/design/backend/templates/addons/novoton_holidays/views/novoton_holidays/manage.tpl'],
        ];

        foreach (self::PROVIDERS as $addon => [$dir]) {
            [$dashboard, $anchor, $tpl] = $anchors[$addon];
            $init = SourceCode::code(self::path($dir . '/init.php'));

            self::assertStringContainsString('TravelProviderRegistry::setCron(', $init, "{$addon} never declares its cron");
            self::assertStringContainsString("'{$addon}',", $init);
            self::assertStringContainsString("'{$dashboard}',", $init);
            self::assertStringContainsString("'{$anchor}',", $init);

            // The link must land somewhere: the anchor exists on that page.
            self::assertStringContainsString(
                'id="' . $anchor . '"',
                (string) file_get_contents(self::path($tpl)),
                "the 'Open {$addon} cron' link points at #{$anchor}, which is not on the page",
            );
        }
    }

    /** Travel Core's own jobs are recorded too, from both entry points. */
    public function testTravelCoresOwnJobsAreRecorded(): void
    {
        $http = SourceCode::code(self::path('addon-travel-core/app/addons/travel_core/controllers/frontend/travel_cron.php'));
        self::assertStringContainsString("CronRunLog::record('travel_core', 'exchange_rates',", $http);
        self::assertStringContainsString("CronRunLog::record('travel_core', 'expire_alternative_requests',", $http);

        $cli = SourceCode::code(self::path('addon-travel-core/app/addons/travel_core/cron.php'));
        self::assertMatchesRegularExpression("/CronRunLog::record\\(\\s*'travel_core',\\s*'exchange_rates',/", $cli);
    }
}
