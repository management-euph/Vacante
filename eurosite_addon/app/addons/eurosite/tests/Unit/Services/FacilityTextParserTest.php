<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\FacilityTextParser;

/**
 * Facility chips from Eurosite's own <DescriptionDet> text — there is no
 * structured facility list in getProductInfo.
 */
final class FacilityTextParserTest extends TestCase
{
    /** Live SCANDINAVIA CM (RO0451) description, as the API sends it (double-encoded). */
    public function testLiveDescriptionGivesTheOperatorsFacilityList(): void
    {
        $raw = (string) file_get_contents(__DIR__ . '/../../Fixtures/product_info_RO0451_description_det.txt');

        self::assertSame(
            ['Aer conditionat', 'Cablu tv', 'Restaurant', 'Bar', 'Wi-fi', 'Grup sanitar propriu', 'Terasa', 'Parcare gratuita'],
            FacilityTextParser::facilities($raw),
        );
    }

    public function testHeadingVariantsAndParentheses(): void
    {
        self::assertSame(
            ['Piscina (exterioara, incalzita)', 'Spa', 'Parcare'],
            FacilityTextParser::facilities('Despre hotel.<br>Facilități hotel: piscina (exterioara, incalzita), spa; parcare.<br>Camere: …'),
        );
        self::assertSame(['Pool', 'Gym'], FacilityTextParser::facilities('<p>Facilities: pool, gym, pool</p>'));
    }

    /** The list is one sentence: the next one on the same line is not a chip. */
    public function testTheListEndsWithItsSentence(): void
    {
        self::assertSame(
            ['Aer conditionat', 'Bar'],
            FacilityTextParser::facilities('Facilitati: aer conditionat, bar. Hotelul are 3 etaje, lift si parcare.'),
        );
    }

    /** "Facilitati:" alone, the items as bullet lines under it. */
    public function testBulletListUnderTheHeading(): void
    {
        self::assertSame(
            ['Piscina', 'Bar', 'Wi-fi'],
            FacilityTextParser::facilities('Facilitati:<br><br>- piscina<br>- bar<br>• Wi-fi<br><br>Camere: 40'),
        );
    }

    public function testNoFacilityLineGivesNoChips(): void
    {
        self::assertSame([], FacilityTextParser::facilities('Hotel situat in centru. Camere spatioase.'));
        self::assertSame([], FacilityTextParser::facilities(''));
    }
}
