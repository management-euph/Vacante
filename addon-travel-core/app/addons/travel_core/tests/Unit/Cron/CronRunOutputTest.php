<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;
use Tygh\Addons\TravelCore\Cron\CronRunOutput;

/**
 * A job's log reads the same from a provider's public cron URL and from its
 * dashboard's Run (Sphinx's and Eurosite's run_job): both print it through
 * CronRunOutput, with the lines their cron endpoints always printed.
 */
final class CronRunOutputTest extends TestCase
{
    /**
     * A dispatcher that answers $result and notes in $calls what it was asked to run.
     *
     * @param array<string, mixed> $result
     * @param \ArrayObject<int, array{string, array<string, mixed>}>|null $calls
     */
    private static function dispatcher(array $result, ?\ArrayObject $calls = null): CronDispatcherInterface
    {
        return new class ($result, $calls) implements CronDispatcherInterface {
            /**
             * @param array<string, mixed> $result
             * @param \ArrayObject<int, array{string, array<string, mixed>}>|null $calls
             */
            public function __construct(private readonly array $result, private readonly ?\ArrayObject $calls)
            {
            }

            public function hasMode(string $mode): bool
            {
                return true;
            }

            /**
             * @param array<string, mixed> $params
             *
             * @return array<string, mixed>
             */
            public function dispatch(string $mode, array $params = []): array
            {
                $this->calls?->append([$mode, $params]);

                return $this->result;
            }

            /** @return array<string, string> */
            public static function getAvailableModes(): array
            {
                return [];
            }
        };
    }

    /**
     * What dispatch() prints, its timestamps as [T].
     *
     * @param array<string, mixed> $params
     */
    private static function printed(CronDispatcherInterface $dispatcher, string $mode = 'hotels', array $params = []): string
    {
        ob_start();
        try {
            CronRunOutput::dispatch($dispatcher, $mode, $params);
        } finally {
            $out = (string) ob_get_clean();
        }

        return (string) preg_replace('/\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\]/', '[T]', $out);
    }

    public function testTheFirstLineNamesTheProviderAndTheJob(): void
    {
        self::assertStringEndsWith("] Eurosite Cron Started - Mode: full\n", CronRunOutput::startLine('Eurosite', 'full'));
        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\] Sphinx Cron Started - Mode: hotels\n$/',
            CronRunOutput::startLine('Sphinx', 'hotels'),
        );
    }

    public function testTheJobGetsItsModeAndParametersAsGiven(): void
    {
        /** @var \ArrayObject<int, array{string, array<string, mixed>}> $calls */
        $calls = new \ArrayObject();
        self::printed(self::dispatcher(['success' => true], $calls), 'geocode_hotels', ['limit' => '100', 'force' => '1']);

        self::assertSame([['geocode_hotels', ['limit' => '100', 'force' => '1']]], $calls->getArrayCopy());
    }

    public function testACompletedRunWithItsFigures(): void
    {
        $out = self::printed(self::dispatcher([
            'success' => true,
            'stats' => ['synced' => 12, 'total' => 20, 'skipped' => 3, 'failed' => 1, 'duration_ms' => 2540],
        ]));

        self::assertSame("\n[T] Cron job completed successfully.\nStats: 12/20 synced, 3 skipped, 1 failed (2.5s)\n", $out);
    }

    public function testAddedCountsWhenTheJobReportsNoSyncedFigure(): void
    {
        $out = self::printed(self::dispatcher(['success' => true, 'stats' => ['added' => 5, 'total' => 9]]));

        self::assertSame("\n[T] Cron job completed successfully.\nStats: 5/9 synced\n", $out);
    }

    public function testAFailedRunPrintsItsError(): void
    {
        $out = self::printed(self::dispatcher(['success' => false, 'error' => 'API down']));

        self::assertSame("\n[T] Cron job finished with errors.\nError: API down\n", $out);
    }

    public function testABusyJobSaysSoInsteadOfFailing(): void
    {
        $out = self::printed(self::dispatcher(['success' => false, 'busy' => true, 'message' => "Mode 'hotels' is already running. Try again later."]));
        self::assertSame("\n[T] Mode 'hotels' is already running. Try again later.\n", $out);

        $out = self::printed(self::dispatcher(['success' => false, 'busy' => true]), 'circuits');
        self::assertSame("\n[T] Mode 'circuits' is already running.\n", $out);
    }
}
