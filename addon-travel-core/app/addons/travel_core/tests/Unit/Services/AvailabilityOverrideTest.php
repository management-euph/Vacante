<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Contracts\HotelAvailabilitySourceInterface;
use Tygh\Addons\TravelCore\Enums\AvailabilityMode;
use Tygh\Addons\TravelCore\Enums\HotelListCoverage;
use Tygh\Addons\TravelCore\Repository\HotelAvailabilityRepository;
use Tygh\Addons\TravelCore\Services\AvailabilityOverride;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\ValueObjects\AvailabilityCapabilities;

/**
 * An admin's decision on a hotel (docs/novoton-availability-gaps-plan.md
 * §10, "overrides win"): stored with who and when, logged, and applied to
 * that one product at once in Automatic mode; in Report only it waits.
 */
final class AvailabilityOverrideTest extends TestCase
{
    /** @var list<string> */
    private array $sql = [];

    protected function setUp(): void
    {
        DbStub::reset();
        HotelAvailabilityRepository::resetEnsured();
        DbStub::$query = function (string $query, mixed ...$params): int {
            if (preg_match('/^\s*(CREATE|ALTER) TABLE/', $query) !== 1) {
                $this->sql[] = db_quote($query, ...$params);
            }

            return 1;
        };
        DbStub::$getField = static fn (string $query): string => str_contains($query, 'SHOW COLUMNS') ? 'present' : '';
        DbStub::$getRow = static fn (string $query, mixed ...$params): array => ($params[1] ?? '') === '476'
            ? ['product_id' => '15', 'state' => 'dormant', 'override' => '']
            : [];
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        HotelAvailabilityRepository::resetEnsured();
    }

    private static function override(AvailabilityMode $mode, bool $sharedGate = true): AvailabilityOverride
    {
        $source = new class ($sharedGate) implements HotelAvailabilitySourceInterface {
            public function __construct(private readonly bool $sharedGate)
            {
            }

            #[\Override]
            public function provider(): string
            {
                return 'novoton';
            }

            #[\Override]
            public function capabilities(): AvailabilityCapabilities
            {
                return new AvailabilityCapabilities(HotelListCoverage::PerCountry, true, 'resort', false, true, true, $this->sharedGate);
            }

            #[\Override]
            public function recordingModes(): array
            {
                return [];
            }
        };

        return new AvailabilityOverride(mode: static fn (): AvailabilityMode => $mode, sources: ['novoton' => $source]);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-02-15 10:30:00');
    }

    public function testADecisionIsStoredWithWhoAndWhenAndLogged(): void
    {
        $result = self::override(AvailabilityMode::Report)->set('novoton', '476', 'keep_visible', ' Renovated, reopens in May ', 7, self::now());

        self::assertSame(['status' => 'saved', 'product_id' => 15, 'automatic' => false, 'hidden' => 0, 'shown' => 0], $result);
        $sql = implode("\n", $this->sql);
        self::assertStringContainsString("SET override = 'keep_visible', override_note = 'Renovated, reopens in May', override_by = 7, override_at = '2027-02-15 10:30:00'", $sql);
        self::assertStringContainsString("VALUES ('novoton', '476', 15, 'dormant', 'dormant', 'override_keep_visible', 7, '2027-02-15 10:30:00')", $sql);
        self::assertStringNotContainsString('UPDATE ?:products', $sql, 'Report only: no product changes');
    }

    public function testClearingADecisionLetsTheRulesDecideAgain(): void
    {
        self::override(AvailabilityMode::Report)->set('novoton', '476', '', '', 7, self::now());

        $sql = implode("\n", $this->sql);
        self::assertStringContainsString("SET override = '', override_note = '', override_by = 0, override_at = NULL", $sql);
        self::assertStringContainsString("'override_cleared'", $sql);
    }

    public function testInAutomaticModeTheDecisionAppliesToThatProductAtOnce(): void
    {
        $gateScope = [];
        DbStub::$getFields = static function (string $query, mixed ...$params) use (&$gateScope): array {
            $gateScope[] = implode(' ', array_map(static fn (mixed $p): string => is_string($p) ? $p : '', $params));

            return str_contains($query, "p.status = 'A'") ? [15] : [];
        };
        DbStub::$getArray = static fn (): array => [];

        $result = self::override(AvailabilityMode::Automatic)->set('novoton', '476', 'keep_hidden', '', 7, self::now());

        self::assertSame([true, 1], [$result['automatic'], $result['hidden']]);
        self::assertStringContainsString('AND a.product_id = 15', implode("\n", $gateScope), 'only that product');
        self::assertStringContainsString("UPDATE ?:products SET status = 'H' WHERE product_id IN (15) AND status = 'A'", implode("\n", $this->sql));
    }

    public function testASupplierWithAGateOfItsOwnWaits(): void
    {
        $result = self::override(AvailabilityMode::Automatic, false)->set('novoton', '476', 'keep_hidden', '', 7, self::now());

        self::assertFalse($result['automatic']);
        self::assertStringNotContainsString('UPDATE ?:products', implode("\n", $this->sql));
    }

    public function testNothingIsSavedForAnUnknownDecisionOrHotel(): void
    {
        self::assertSame('invalid', self::override(AvailabilityMode::Report)->set('novoton', '476', 'delete_it', '', 7, self::now())['status']);
        self::assertSame('unknown', self::override(AvailabilityMode::Report)->set('novoton', '999', 'closed', '', 7, self::now())['status']);
        self::assertSame([], $this->sql);
    }

    public function testConfirmingTheHeldHidingNeedsAutomaticMode(): void
    {
        self::assertNull(self::override(AvailabilityMode::Report)->confirmHiding('novoton', 7, self::now()));

        DbStub::$getFields = static fn (string $query): array => str_contains($query, "p.status = 'A'") ? range(1, 40) : [];
        DbStub::$getField = static fn (string $query): string => str_contains($query, 'COUNT(DISTINCT') ? '100' : 'present';
        DbStub::$getArray = static fn (): array => [];
        $result = self::override(AvailabilityMode::Automatic)->confirmHiding('novoton', 7, self::now());

        self::assertNotNull($result);
        self::assertSame([0, 40], [$result['braked'], $result['would_hide']], 'the admin confirmed: no brake');
    }
}
