<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\EurositeXmlBuilder;

/**
 * Pins the Eurosite request envelope + AuditInfo against the spec
 * (Documentation/Specificatii_API_Eurosite.pdf, "Autentificarea"): every
 * request is <Request RequestType="X"> wrapping <AuditInfo> (id/user/pass/
 * time/lang) + <RequestDetails>. Output is deterministic (id + time are
 * parameters), so this parses back to valid XML.
 */
final class EurositeXmlBuilderTest extends TestCase
{
    private function builder(): EurositeXmlBuilder
    {
        return new EurositeXmlBuilder('YourUser', 'YourPassword', 'EN');
    }

    public function testEnvelopeCarriesRequestTypeAndAuditInfo(): void
    {
        $xml = $this->builder()->envelope(
            'getCountryRequest',
            '<getCountryRequest/>',
            '001',
            '2012-09-04T18:00:46',
        );

        $sx = simplexml_load_string($xml);
        self::assertNotFalse($sx, 'envelope is well-formed XML');
        self::assertSame('getCountryRequest', (string) $sx['RequestType']);
        self::assertSame('001', (string) $sx->AuditInfo->RequestId);
        self::assertSame('YourUser', (string) $sx->AuditInfo->RequestUser);
        self::assertSame('YourPassword', (string) $sx->AuditInfo->RequestPass);
        self::assertSame('2012-09-04T18:00:46', (string) $sx->AuditInfo->RequestTime);
        self::assertSame('EN', (string) $sx->AuditInfo->RequestLang);
        // The endpoint payload sits inside <RequestDetails>.
        self::assertTrue(isset($sx->RequestDetails->getCountryRequest));
    }

    public function testRoomElementEncodesOccupancyAndChildAges(): void
    {
        $b = $this->builder();

        // Adults-only room is self-closing with the occupancy attributes.
        self::assertSame('<Room Code="DB" NoAdults="2"/>', $b->roomElement(['code' => 'DB', 'adults' => 2]));

        // With children, NoChildren + a <Children><Age> list per the spec.
        $withKids = $b->roomElement(['code' => 'DB', 'adults' => 2, 'children' => [8, 3]]);
        self::assertStringContainsString('NoChildren="2"', $withKids);
        self::assertStringContainsString('<Children><Age>8</Age><Age>3</Age></Children>', $withKids);
    }

    public function testCredentialsAndSpecialCharsAreEscapedAndMaskable(): void
    {
        $b = new EurositeXmlBuilder('u&ser', 'p<ss>', 'RO');
        $xml = $b->envelope('getRoomRequest', '<getRoomRequest/>', '001', '2012-01-01T00:00:00');

        // Special chars are entity-escaped, so the envelope still parses.
        self::assertNotFalse(simplexml_load_string($xml));

        // Masking hides the password for logging.
        $masked = EurositeXmlBuilder::maskCredentials($xml);
        self::assertStringContainsString('<RequestPass>*****</RequestPass>', $masked);
        self::assertStringNotContainsString('p&lt;ss&gt;', $masked);
    }
}
