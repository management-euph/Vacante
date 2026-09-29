<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Install;

use Closure;
use Netopia\CsCart\Install\Seeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Seeder::class)]
final class SeederTest extends TestCase
{
    public function testEnsureSeededSkipsWritesWhenLabelsPresent(): void
    {
        $queries = [];

        $seeder = $this->makeSeeder(
            queries:       $queries,
            getFieldReply: static fn (): string|false => 'already-there',
        );

        $seeder->ensureSeeded();

        self::assertSame([], $queries, 'no writes when the marker label row exists');
    }

    public function testEnsureSeededInsertsLabelsInASingleAtomicReplace(): void
    {
        $queries = [];

        $seeder = $this->makeSeeder(
            queries:       $queries,
            getFieldReply: static fn (): string|false => false,
        );

        $seeder->ensureSeeded();

        $labelWrites = array_values(array_filter(
            $queries,
            static fn (array $q): bool => str_contains($q['sql'], 'REPLACE INTO ?:language_values'),
        ));
        self::assertCount(1, $labelWrites, 'label seeding is a single atomic REPLACE');

        $params = $labelWrites[0]['params'];
        self::assertContains('Payment Link', $params);
        self::assertContains('Amount', $params);
        self::assertContains('Charged Amount', $params);
        self::assertContains('Payment link time', $params);
        self::assertContains('Link plată', $params);
        self::assertContains('Sumă', $params);
        self::assertContains('Sumă plătită', $params);
        self::assertContains('Oră link plată', $params);

        // Row names must match the payment_info field keys verbatim — CS-Cart
        // 4.19 resolves labels by direct lookup.
        self::assertContains('netopia_payment_link', $params);
        self::assertContains('netopia_amount', $params);
        self::assertContains('netopia_start_amount', $params);
        self::assertContains('netopia_payment_link_at', $params);
        self::assertNotContains('_netopia_payment_link', $params);
        self::assertNotContains('_netopia_amount', $params);
        self::assertNotContains('_netopia_start_amount', $params);
    }

    public function testSeederNeverTouchesTemplateEmailsTable(): void
    {
        // Regression guard: the `netopia_payment_retry` row is owned by the
        // <email_templates> block in addon.xml (Exim importer). The Seeder
        // must not write to ?:template_emails under any circumstances.
        $queries = [];

        $seeder = $this->makeSeeder(
            queries:       $queries,
            getFieldReply: static fn (): string|false => false,
        );

        $seeder->ensureSeeded();
        $seeder->seedLabelsOnInstall();

        foreach ($queries as $q) {
            self::assertStringNotContainsStringIgnoringCase(
                '?:template_emails',
                $q['sql'],
                'Seeder must not issue SQL against ?:template_emails',
            );
        }
    }

    public function testEnsureSeededIsIdempotentWithinTheSameRequest(): void
    {
        $getFieldCalls = 0;
        $queries = [];

        $seeder = $this->makeSeeder(
            queries: $queries,
            getFieldReply: function () use (&$getFieldCalls): string|false {
                $getFieldCalls++;
                return 'already-there';
            },
        );

        $seeder->ensureSeeded();
        $firstRoundCalls = $getFieldCalls;
        $seeder->ensureSeeded();
        $seeder->ensureSeeded();

        self::assertSame($firstRoundCalls, $getFieldCalls, 'second/third calls short-circuit on the in-process guard');
        self::assertSame([], $queries);
    }

    public function testEnsureSeededSwallowsDbErrorsSoTheRequestKeepsGoing(): void
    {
        $seeder = new Seeder(
            dbQuery:    static fn (): mixed => null,
            dbGetField: static function (): string|false {
                throw new \RuntimeException('simulated DB outage');
            },
        );

        $seeder->ensureSeeded();

        self::assertTrue(true, 'no exception propagated');
    }

    public function testSeedLabelsOnInstallAlwaysWrites(): void
    {
        $queries = [];

        $seeder = $this->makeSeeder(
            queries: $queries,
            getFieldReply: static fn (): string|false => 'already-there',
        );

        $seeder->seedLabelsOnInstall();

        self::assertCount(1, $queries);
        self::assertStringContainsString('REPLACE INTO ?:language_values', $queries[0]['sql']);
    }

    /**
     * @param array<int, array{sql: string, params: array<int, mixed>}> $queries
     */
    private function makeSeeder(
        array &$queries = [],
        ?Closure $getFieldReply = null,
    ): Seeder {
        return new Seeder(
            dbQuery:    function (string $sql, mixed ...$params) use (&$queries): mixed {
                $queries[] = ['sql' => $sql, 'params' => $params];
                return null;
            },
            dbGetField: $getFieldReply ?? static fn (): string|false => false,
        );
    }
}
