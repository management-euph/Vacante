<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;

/**
 * What Travel Core -> Tools shows about cron: Travel Core's own job, and one
 * summary row per provider add-on.
 *
 * The provider rows come from TravelProviderRegistry::getCronProviders() —
 * what each provider declared from its own init.php — so nothing here names
 * another add-on's jobs. Job counts come from the provider's dispatcher, the
 * list its cron actually accepts; the dashboards hand-list only some of them
 * (Sphinx shows 20 of 28, Novoton 16 of 23), so counting those would be wrong.
 *
 * No Registry, no Tygh::$app (phpstan-disallowed-calls): the controller reads
 * the config and passes it in.
 */
final class CronOverview
{
    /** Travel Core's scheduled job on the page; expire_alternative_requests is HTTP-only and stays off it. */
    public const string CORE_JOB = 'exchange_rates';

    /** Every mode Travel Core's cron entry points record under 'travel_core'. */
    public const array CORE_MODES = ['exchange_rates', 'expire_alternative_requests', 'balances'];

    /**
     * The command that runs Travel Core's job, in both forms.
     *
     * @return array{url: string, cli: string}
     */
    public static function coreCommands(string $key, string $baseUrl, string $dirRoot): array
    {
        $url = rtrim($baseUrl, '/') . '/index.php?dispatch=travel_cron.run&access_key=' . $key
            . '&cron_mode=' . self::CORE_JOB;

        return [
            // The plain address, for a cron service or the browser; the CLI
            // line is the one a crontab or cPanel job runs.
            'url' => $url,
            'cli' => 'php ' . rtrim($dirRoot, '/') . '/app/addons/travel_core/cron.php access_key=' . $key
                . ' mode=' . self::CORE_JOB,
        ];
    }

    /**
     * Travel Core's own cron health, across every mode it records.
     *
     * @return array{state: string, failed: list<string>, stalled: list<string>, running: list<string>, last: array{mode: string, at: int, ok: bool}|null, wrong_key_at: int, last_scheduled_ok: int}
     */
    public static function coreHealth(int $now): array
    {
        return self::assess('travel_core', self::CORE_MODES, $now);
    }

    /**
     * The last record of Travel Core's scheduled job, for its row.
     *
     * @return array{started?: int, finished?: int, ok?: bool, error?: string, via?: string}
     */
    public static function coreJobRecord(): array
    {
        return CronRunLog::get('travel_core', self::CORE_JOB);
    }

    /**
     * One row per provider that declared its cron.
     *
     * @param array<string, array{name: string, label: string, addon: string, dispatcher: class-string<CronDispatcherInterface>, dashboard: string, anchor: string}> $providers
     *
     * @return list<array{addon: string, label: string, jobs: int, dashboard: string, anchor: string, health: array{state: string, failed: list<string>, stalled: list<string>, running: list<string>, last: array{mode: string, at: int, ok: bool}|null, wrong_key_at: int, last_scheduled_ok: int}}>
     */
    public static function providerRows(array $providers, int $now): array
    {
        $rows = [];
        foreach ($providers as $p) {
            $modes = self::modesOf($p['dispatcher']);
            $rows[] = [
                'addon' => $p['addon'],
                'label' => $p['label'],
                'jobs' => count($modes),
                'dashboard' => $p['dashboard'],
                'anchor' => $p['anchor'],
                'health' => self::assess($p['addon'], $modes, $now),
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $modes
     *
     * @return array{state: string, failed: list<string>, stalled: list<string>, running: list<string>, last: array{mode: string, at: int, ok: bool}|null, wrong_key_at: int, last_scheduled_ok: int}
     */
    private static function assess(string $addon, array $modes, int $now): array
    {
        $records = [];
        foreach ($modes as $mode) {
            $records[$mode] = CronRunLog::get($addon, $mode);
        }

        return CronHealth::assess($records, CronRunLog::lastWrongKeyRefusal($addon), $now);
    }

    /**
     * A provider's job types, never throwing: a dispatcher that cannot list
     * them is a row with no jobs, not a broken Tools page.
     *
     * @param class-string<CronDispatcherInterface> $dispatcher
     *
     * @return list<string>
     */
    private static function modesOf(string $dispatcher): array
    {
        try {
            return array_map('strval', array_keys($dispatcher::getAvailableModes()));
        } catch (\Throwable) {
            return [];
        }
    }
}
