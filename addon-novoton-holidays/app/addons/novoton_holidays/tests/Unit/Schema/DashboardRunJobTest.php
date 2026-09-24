<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Running a cron job from the dashboard never goes through the public cron URL.
 *
 * The dashboard's Run / Check status / Force full sync / Reset were links to
 * index.php?dispatch=novoton_cron.run&access_key=…: the shared cron key went
 * into browser history and Referer headers, and a GET "Reset" could be fired
 * by any page the admin opened. novoton_holidays.run_job is an admin POST —
 * CS-Cart checks its security_hash — and needs no key.
 *
 * Source-text assertions: the controller runs inside CS-Cart, which this
 * repository does not ship.
 */
final class DashboardRunJobTest extends TestCase
{
    private static function controller(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/backend/novoton_holidays.php');
    }

    private static function component(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 6) . '/design/backend/templates/addons/novoton_holidays/components/run_job.tpl');
    }

    /** @return string the run_job handler's body */
    private static function handler(): string
    {
        $src = self::controller();
        $start = strpos($src, "if (\$mode === 'run_job') {");
        self::assertIsInt($start, 'no run_job handler');
        $end = strpos($src, "\n    }\n", $start);
        self::assertIsInt($end);

        return substr($src, $start, $end - $start);
    }

    public function testRunJobIsHandledOnlyForPost(): void
    {
        $src = self::controller();
        $post = strpos($src, "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {");
        $handler = strpos($src, "if (\$mode === 'run_job') {");
        $postEnds = strpos($src, "\n}\n", (int) $post);

        self::assertIsInt($post);
        self::assertIsInt($handler);
        self::assertIsInt($postEnds);
        self::assertGreaterThan($post, $handler);
        self::assertLessThan($postEnds, $handler, 'run_job must sit inside the POST block: a GET must not start a job');
    }

    public function testItChecksPermissionAndRunsOnlyKnownJobs(): void
    {
        $body = self::handler();

        self::assertStringContainsString("fn_check_permissions('manage_catalog', 'update', 'admin')", $body);
        self::assertStringContainsString('CronDispatcher::getAvailableModes()', $body);
        self::assertStringContainsString("preg_replace('/[^a-z0-9_]/', ''", $body);
        // Only the batched jobs' own flags are passed on; nothing else from the request.
        self::assertStringContainsString("in_array(\$action, ['status', 'force_full', 'reset'], true)", $body);
        self::assertStringContainsString('->dispatch($job, $params)', $body);
        self::assertStringNotContainsString('$_REQUEST)', $body, 'the whole request must not reach the job');
        self::assertStringNotContainsString('access_key', $body);
    }

    public function testTheFormPostsWithTheSecurityHashAndNoKey(): void
    {
        $tpl = (string) preg_replace('/\{\*.*?\*\}/s', '', self::component());

        self::assertMatchesRegularExpression('/<form[^>]*method="post"/', $tpl);
        self::assertStringContainsString('name="security_hash" value="{$security_hash}"', $tpl);
        self::assertStringContainsString('name="dispatch" value="novoton_holidays.run_job"', $tpl);
        self::assertStringContainsString('{$job|escape:html}', $tpl);
        self::assertStringNotContainsString('access_key', $tpl);
        self::assertStringNotContainsString('novoton_cron.run', $tpl);
    }
}
