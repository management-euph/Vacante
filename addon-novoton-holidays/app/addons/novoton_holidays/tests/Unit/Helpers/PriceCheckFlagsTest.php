<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;
use Tygh\Addons\NovotonHolidays\Helpers\PriceCheckFlags;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;

/**
 * A room_price check may only write what an answer proved. "No prices" for a
 * hotel the API never answered about (a failed call, an open breaker, a resort
 * that errored) made whole countries read as unsellable.
 */
#[CoversClass(PriceCheckFlags::class)]
final class PriceCheckFlagsTest extends TestCase
{
    public function testOnlyAReplyDecides(): void
    {
        self::assertTrue(PriceCheckFlags::hasPrices(new \SimpleXMLElement('<room_price><Room><Price>120</Price></Room></room_price>')));
        self::assertTrue(PriceCheckFlags::hasPrices(new \SimpleXMLElement('<r><hotel><Room><Price>1</Price></Room></hotel></r>')), 'nested shape too');
        self::assertFalse(PriceCheckFlags::hasPrices(new \SimpleXMLElement('<room_price></room_price>')));
        self::assertNull(PriceCheckFlags::hasPrices(false));
        self::assertNull(PriceCheckFlags::hasPrices(null));
        self::assertNull(
            PriceCheckFlags::hasPrices(new \SimpleXMLElement('<error><err>Invalid username or password!</err></error>')),
            'a refusal (a wrong password) is no answer: it wrote "no prices" for every hotel checked',
        );
    }

    /**
     * check_prices_hotel listed hotels with nothing priced for 7-14 July 2027
     * as "no answer (XML Parse Error: Line 2: Start tag expected, '<' not
     * found), flag unchanged": Novoton answered them with an empty document.
     */
    public function testNovotonsEmptyDocumentIsWhatTheParserRejectsOnLine2(): void
    {
        try {
            (new NovotonXmlParser())->parse("<?xml version=\"1.0\" encoding=\"windows-1251\"?>\n");
            self::fail('an empty document does not parse');
        } catch (XmlParsingException $e) {
            self::assertStringContainsString("Line 2: Start tag expected, '<' not found", $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyDocuments(): iterable
    {
        yield 'declaration and a line break' => ["<?xml version=\"1.0\" encoding=\"windows-1251\"?>\n"];
        yield 'declaration only' => ['<?xml version="1.0" encoding="windows-1251"?>'];
        yield 'a line break' => ["\n"];
        yield 'CRLF' => ["\r\n"];
        yield 'BOM and declaration' => ["\xEF\xBB\xBF<?xml version=\"1.0\"?>\r\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyDocuments')]
    public function testAnEmptyDocumentIsAnAnswerWithoutPrices(string $reply): void
    {
        self::assertTrue(PriceCheckFlags::isEmptyAnswer($reply));
        self::assertFalse(PriceCheckFlags::hasPrices(false, $reply), 'Novoton answered: nothing priced on those dates');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableReplies(): iterable
    {
        yield 'no reply at all' => [''];
        yield 'a wrong API key or ID' => ['Error: no connection to dabatase.'];
        yield 'text after the declaration' => ["<?xml version=\"1.0\"?>\nService unavailable"];
        yield 'an HTML error page' => ['<html><body>502 Bad Gateway</body>'];
        yield 'a cut-off answer' => ['<room_price><Price>120'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableReplies')]
    public function testAnyOtherUnreadableReplyIsStillNoAnswer(string $reply): void
    {
        self::assertFalse(PriceCheckFlags::isEmptyAnswer($reply));
        self::assertNull(PriceCheckFlags::hasPrices(false, $reply), 'nothing proven: the flag stays');
    }

    public function testAnEmptyAnswerHasNoHotelAndNoPrice(): void
    {
        $answer = PriceCheckFlags::emptyAnswer();

        self::assertSame([], $answer->xpath('//IdHotel'));
        self::assertFalse(PriceCheckFlags::hasPrices($answer));
    }

    public function testANoAnswerLineQuotesWhatNovotonSent(): void
    {
        self::assertSame(
            'XML Parse Error: Line 1: Start tag expected — reply: "Error: no connection to dabatase.\\n"',
            PriceCheckFlags::noAnswerReason(false, 'XML Parse Error: Line 1: Start tag expected', "Error: no connection to dabatase.\n"),
        );
        self::assertSame('invalid response (empty reply)', PriceCheckFlags::noAnswerReason(false, '', ''));
        self::assertSame(
            'Novoton: Invalid username or password!',
            PriceCheckFlags::noAnswerReason(new \SimpleXMLElement('<error><err>Invalid username or password!</err></error>'), '', ''),
        );
        self::assertStringEndsWith('…"', PriceCheckFlags::noAnswerReason(false, 'x', str_repeat('a', 200)), 'a long reply is cut');
    }

    public function testAResortCheckLeavesHotelsOfResortsThatDidNotAnswerAlone(): void
    {
        $hotels = [
            '101' => ['city' => 'SUNNY BEACH'],
            '102' => ['city' => 'Sunny Beach '],
            '201' => ['city' => 'GOLDEN SANDS'],
            '301' => ['city' => 'NESSEBAR'],
            '401' => ['city' => ''],
        ];
        // 101 and 301 were found in some answer; Sunny Beach and Golden Sands
        // answered; Nessebar's call failed; 401 has no city.
        $split = PriceCheckFlags::splitByResort($hotels, ['101' => 'SUNNY BEACH', '301' => 'NESSEBAR'], ['sunny beach', 'GOLDEN SANDS']);

        self::assertSame(['101', '301'], $split['with'], 'found in an answer: prices, whatever its own resort did');
        self::assertSame(['102', '201'], $split['without'], 'its resort answered without it: no prices (city trimmed, any case)');
        self::assertSame(['401'], $split['unanswered'], 'nothing is known about it: its flag stays');
    }

    public function testAResortWhoseCallFailedChangesNothing(): void
    {
        $split = PriceCheckFlags::splitByResort(['7' => ['city' => 'NESSEBAR'], '8' => ['city' => 'NESSEBAR']], [], []);

        self::assertSame([], $split['with']);
        self::assertSame([], $split['without']);
        self::assertSame(['7', '8'], $split['unanswered']);
    }

    public function testTheAdminChecksDefaultToTheNextSeventhOfJuly(): void
    {
        self::assertSame('2027-07-07', PriceCheckFlags::nextPeakCheckIn(new \DateTimeImmutable('2026-10-16')), 'October: next summer, not the July just gone');
        self::assertSame('2027-07-07', PriceCheckFlags::nextPeakCheckIn(new \DateTimeImmutable('2026-07-07')));
        self::assertSame('2026-07-07', PriceCheckFlags::nextPeakCheckIn(new \DateTimeImmutable('2026-07-06')));
        self::assertSame('2027-07-07', PriceCheckFlags::nextPeakCheckIn(new \DateTimeImmutable('2027-01-15')));
    }
}
