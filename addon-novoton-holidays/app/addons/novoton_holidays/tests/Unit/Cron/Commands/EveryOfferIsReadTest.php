<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\TestCase;

/**
 * offers_update returns one <Offer> per changed hotel. Both readers wrapped
 * the node set as [$response->Offer], a one-element list, so only the first
 * changed hotel was ever handled.
 */
final class EveryOfferIsReadTest extends TestCase
{
    private const string XML = '<Response><Offer><IdHotel>11</IdHotel></Offer><Offer><IdHotel>22</IdHotel></Offer><Offer><IdHotel>33</IdHotel></Offer></Response>';

    public function testTheOldWrapKeptOnlyTheFirstOffer(): void
    {
        $response = new \SimpleXMLElement(self::XML);

        $ids = [];
        foreach ([$response->Offer] as $offer) {
            $ids[] = (string) $offer->IdHotel;
        }

        self::assertSame(['11'], $ids);
    }

    public function testIteratingTheNodeSetReadsEveryOffer(): void
    {
        $response = new \SimpleXMLElement(self::XML);

        $ids = [];
        foreach ($response->Offer as $offer) {
            $ids[] = (string) $offer->IdHotel;
        }

        self::assertSame(['11', '22', '33'], $ids);
    }

    /**
     * The incremental hotelinfo run no longer reads offers_update: the job
     * leaves its findings on the rows (ChangedHotelDetector reads those).
     *
     * @return iterable<string, array{string}>
     */
    public static function readers(): iterable
    {
        yield 'offers_update' => ['src/Cron/Commands/OffersUpdateCommand.php'];
    }

    /** The admin "Check offers" button counted [$response->Offer]: always one. */
    public function testTheAdminCheckCountsEveryOffer(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Services/AdminCronService.php');

        self::assertStringNotContainsString('[$response->Offer]', $src);
        self::assertStringContainsString('count($response->Offer)', $src);
        self::assertSame(3, count((new \SimpleXMLElement(self::XML))->Offer));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readers')]
    public function testNoReaderWrapsTheNodeSet(string $file): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/' . $file);

        self::assertStringNotContainsString('$offers = [$response->Offer];', $src);
        self::assertStringContainsString('foreach ($response->Offer as $offerNode)', $src);
    }
}
