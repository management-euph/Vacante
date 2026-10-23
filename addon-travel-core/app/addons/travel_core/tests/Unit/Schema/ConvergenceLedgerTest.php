<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Guards docs/CONVERGENCE.md: every pair recorded as contract-converged must
 * actually implement the shared travel_core contract in BOTH providers, and
 * the fully-converged shared pieces must exist. A provider class silently
 * dropping its contract (or a shared piece being deleted) fails here, and
 * the ledger points at the reasoning.
 */
final class ConvergenceLedgerTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    public function testContractConvergedPairsImplementTheSharedContracts(): void
    {
        $pairs = [
            'SecurityServiceInterface' => [
                'addon-novoton-holidays/app/addons/novoton_holidays/src/Services/SecurityService.php',
                'addon-sphinx-holidays/app/addons/sphinx_holidays/src/Services/SecurityService.php',
            ],
            'PreOrderPriceVerifierInterface' => [
                'addon-novoton-holidays/app/addons/novoton_holidays/src/Services/PreOrderPriceVerifier.php',
                'addon-sphinx-holidays/app/addons/sphinx_holidays/src/Services/PreOrderPriceVerifier.php',
            ],
            'BookingAdminProviderInterface' => [
                'addon-novoton-holidays/app/addons/novoton_holidays/src/Services/BookingAdminProvider.php',
                'addon-sphinx-holidays/app/addons/sphinx_holidays/src/Services/BookingAdminProvider.php',
            ],
            'HotelRepositoryInterface' => [
                'addon-novoton-holidays/app/addons/novoton_holidays/src/Repository/HotelRepository.php',
                'addon-sphinx-holidays/app/addons/sphinx_holidays/src/Repository/HotelRepository.php',
            ],
            'CronDispatcherInterface' => [
                'addon-novoton-holidays/app/addons/novoton_holidays/src/Cron/CronDispatcher.php',
                'addon-sphinx-holidays/app/addons/sphinx_holidays/src/Cron/CronDispatcher.php',
            ],
        ];

        foreach ($pairs as $contract => $files) {
            foreach ($files as $rel) {
                $path = self::repoRoot() . '/' . $rel;
                self::assertFileExists($path, $rel);
                $src = (string) file_get_contents($path);
                self::assertMatchesRegularExpression(
                    '/implements[^{]*\b' . preg_quote($contract, '/') . '\b/s',
                    $src,
                    "{$rel} must implement the shared {$contract} (see docs/CONVERGENCE.md)",
                );
            }
        }
    }

    /** Suppliers join one by one; each that records availability implements the contract. */
    public function testAvailabilitySourcesImplementTheContract(): void
    {
        foreach ([
            'addon-novoton-holidays/app/addons/novoton_holidays/src/Services/NovotonAvailabilitySource.php',
            'addon-eurosite/app/addons/eurosite/src/Services/EurositeAvailabilitySource.php',
            'addon-sphinx-holidays/app/addons/sphinx_holidays/src/Services/SphinxAvailabilitySource.php',
        ] as $rel) {
            $path = self::repoRoot() . '/' . $rel;
            self::assertFileExists($path, $rel);
            self::assertMatchesRegularExpression(
                '/implements[^{]*\\bHotelAvailabilitySourceInterface\\b/s',
                (string) file_get_contents($path),
                "{$rel} must implement HotelAvailabilitySourceInterface (see docs/CONVERGENCE.md)",
            );
        }
    }

    public function testFullyConvergedSharedPiecesExist(): void
    {
        foreach ([
            'src/Http/ResiliencePolicy.php',
            'src/Cron/CommandDiscovery.php',
            'src/Cron/AbstractCronCommand.php',
            'src/Helpers/CronRunLock.php',
            'src/Repository/TravelBookingMirror.php',
            'src/Services/HotelLocationLine.php',
            'src/Services/HotelMapUrl.php',
            'src/ViewModels/HotelHeaderFactory.php',
            'src/ViewModels/HotelHeaderViewModel.php',
            'src/Contracts/CacheServiceInterface.php',
            'src/Services/AvailabilityWindows.php',
            'src/Services/AvailabilityPolicy.php',
            'src/ValueObjects/AvailabilityThresholds.php',
            'src/Services/OfferTally.php',
            'src/Contracts/HotelAvailabilitySourceInterface.php',
        ] as $rel) {
            self::assertFileExists(dirname(__DIR__, 3) . '/' . $rel, $rel);
        }
    }

    public function testLedgerDocumentExistsAndReferencesThisGuard(): void
    {
        $doc = self::repoRoot() . '/docs/CONVERGENCE.md';
        self::assertFileExists($doc);
        self::assertStringContainsString('ConvergenceLedgerTest', (string) file_get_contents($doc));
    }
}
