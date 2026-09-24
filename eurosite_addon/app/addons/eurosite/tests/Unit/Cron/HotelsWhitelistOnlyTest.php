<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;

/**
 * The `hotels` sync fetches the whitelisted destinations only.
 *
 * It used to add every own-offer city too, which is how a store with one
 * whitelisted country ended up with 1,202 hotels from 111 destinations.
 * Hotels outside the whitelist are hidden and kept, never deleted.
 */
final class HotelsWhitelistOnlyTest extends TestCase
{
    private static function src(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/src/' . $file);
    }

    public function testOwnOfferCitiesAreNoLongerAdded(): void
    {
        $sync = self::src('Cron/Commands/HotelsSyncCommand.php');

        self::assertStringNotContainsString('getByCountry($country, true)', $sync);
        self::assertStringNotContainsString('targetCountryCodes()', $sync);
        self::assertStringContainsString('foreach ($whitelist->getCountryCodes() as $country) {', $sync);
        self::assertStringContainsString('$whitelist->getAllowedCityCodes($country)', $sync);
    }

    public function testAnEmptyWhitelistSyncsNothingAndSaysSo(): void
    {
        $sync = self::src('Cron/Commands/HotelsSyncCommand.php');

        self::assertStringContainsString('if ($allowed === []) {', $sync);
        self::assertStringContainsString('Configure the whitelist first', $sync);
        self::assertStringContainsString('is not whitelisted. Whitelist it first.', $sync, '&city= cannot reach past the whitelist');
    }

    public function testOnlyAFullRunHidesTheRestAndOnlyByFlag(): void
    {
        $sync = self::src('Cron/Commands/HotelsSyncCommand.php');
        self::assertStringContainsString("\$hidden = \$only === '' ? \$hotelRepo->deactivateOutside(\$allowed) : 0;", $sync);

        $repo = self::src('Repository/HotelRepository.php');
        $start = (int) strpos($repo, 'public function deactivateOutside(');
        $body = substr($repo, $start, (int) strpos($repo, 'public function countActive', $start) - $start);
        self::assertStringContainsString("SET sync_status = 'inactive', inactive_reason = 'not_whitelisted'", $body);
        self::assertStringNotContainsString('DELETE', $body);
        self::assertStringContainsString('if ($allowedCityCodes === []) {', $body, 'an empty list must never hide everything');
    }

    public function testASyncedHotelIsListedAgainWhenItsDestinationReturns(): void
    {
        $repo = self::src('Repository/HotelRepository.php');

        self::assertStringContainsString("sync_status = 'active',\n                    inactive_reason = '',", $repo);
    }

    /** Everything downstream reads listed hotels only. */
    public function testTheCheckAndTheProductsReadListedHotelsOnly(): void
    {
        $repo = self::src('Repository/HotelRepository.php');
        foreach (['getAvailabilityTargets', 'getProductCandidates', 'applyAvailability'] as $method) {
            $start = (int) strpos($repo, 'public function ' . $method . '(');
            $body = substr($repo, $start, 1500);
            self::assertStringContainsString("sync_status = 'active'", $body, $method);
        }
    }
}
