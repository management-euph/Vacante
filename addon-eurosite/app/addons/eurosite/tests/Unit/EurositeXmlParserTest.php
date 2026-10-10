<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\EurositeXmlParser;

/**
 * Pins the parser against a REAL response captured from the live web
 * service (laguna.touringit.ro, 2026-07-25): the server's auth refusal.
 * Every caller with invalid/placeholder credentials gets this exact shape
 * (verified 2026-08-04: valid credentials answer fine from any host), so
 * it is the first response any unconfigured deployment sees — the parser
 * must surface it as a readable error, not a mystery payload.
 */
final class EurositeXmlParserTest extends TestCase
{
    private const LIVE_AUTH_REFUSAL = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<Response ResponseType="Error">
  <AuditInfo>
    <ResponseId>266037478</ResponseId>
    <RequestId>32502</RequestId>
    <ResponseTime>2026-07-25T18:26:54</ResponseTime>
  </AuditInfo>
  <ResponseDetails>
    <Errors>
      <Error>
        <ErrorId>-1000</ErrorId>
        <ErrorText>You are not authorised to access this server!</ErrorText>
      </Error>
    </Errors>
  </ResponseDetails>
</Response>
XML;

    public function testLiveAuthRefusalSurfacesAsAReadableError(): void
    {
        $parser = new EurositeXmlParser();

        $message = $parser->errorMessage(self::LIVE_AUTH_REFUSAL);
        self::assertSame('-1000: You are not authorised to access this server!', $message);

        // The unwrap still works — callers receive the <Errors> payload,
        // whose ErrorId identifies the refusal.
        $details = $parser->responseDetails(self::LIVE_AUTH_REFUSAL);
        self::assertNotNull($details);
        self::assertSame('Errors', $details->getName());
        self::assertSame('-1000', (string) $details->Error->ErrorId);
    }

    public function testSuccessfulResponseYieldsNoErrorMessage(): void
    {
        $parser = new EurositeXmlParser();
        $ok = '<?xml version="1.0" encoding="utf-8"?>'
            . '<Response ResponseType="getCountryResponse"><AuditInfo/>'
            . '<ResponseDetails><getCountryResponse>'
            . '<Country><CountryCode>AL</CountryCode><CountryName>Albania</CountryName></Country>'
            . '</getCountryResponse></ResponseDetails></Response>';

        self::assertSame('', $parser->errorMessage($ok));
        $details = $parser->responseDetails($ok);
        self::assertNotNull($details);
        self::assertSame('getCountryResponse', $details->getName());
    }
}
