<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Helpers\CnpMasker;

#[CoversClass(CnpMasker::class)]
final class CnpMaskerTest extends TestCase
{
    /**
     * @return array<string, array{?string, string}>
     */
    public static function values(): array
    {
        return [
            'null'                => [null, ''],
            'empty'               => ['', ''],
            '13 digits'           => ['1980512123456', '19805******56'],
            'spaces are ignored'  => ['198 0512 123456', '19805******56'],
            'dashes are ignored'  => ['1980512-123456', '19805******56'],
            '12 digits'           => ['198051212345', 'INVALID_LENGTH_12'],
            '14 digits'           => ['19805121234567', 'INVALID_LENGTH_14'],
            'no digits'           => ['n/a', 'INVALID_LENGTH_0'],
        ];
    }

    #[DataProvider('values')]
    public function testMask(?string $cnp, string $masked): void
    {
        self::assertSame($masked, CnpMasker::mask($cnp));
    }

    public function testTheMaskKeepsNoneOfTheMiddleDigits(): void
    {
        $masked = CnpMasker::mask('1980512987654');

        self::assertSame('19805******54', $masked);
        self::assertStringNotContainsString('1298765', $masked);
    }

    public function testAnInvalidLengthKeepsNoDigitAtAll(): void
    {
        self::assertDoesNotMatchRegularExpression('/\d{2,}/', str_replace('INVALID_LENGTH_12', '', CnpMasker::mask('198051212345')));
    }

    public function testMaskFormMasksAnIndividualsCodUnicOnly(): void
    {
        $person = ['Client[Tip]' => 'PF', 'Client[CodUnic]' => '1980512123456', 'Client[Denumire]' => 'Ion Pop'];
        $company = ['Client[Tip]' => 'PJ', 'Client[CodUnic]' => '12345678', 'Client[Denumire]' => 'ACME SRL'];

        self::assertSame(
            ['Client[Tip]' => 'PF', 'Client[CodUnic]' => '19805******56', 'Client[Denumire]' => 'Ion Pop'],
            CnpMasker::maskForm($person),
        );
        self::assertSame($company, CnpMasker::maskForm($company), 'a CIF is public and stays');
        self::assertSame(['Client[Tip]' => 'PF'], CnpMasker::maskForm(['Client[Tip]' => 'PF']), 'no CodUnic, nothing to mask');
        self::assertSame([], CnpMasker::maskForm([]));
    }

    public function testCnpInForm(): void
    {
        self::assertSame('1980512123456', CnpMasker::cnpInForm(['Client[Tip]' => 'PF', 'Client[CodUnic]' => '1980512123456']));
        self::assertSame('', CnpMasker::cnpInForm(['Client[Tip]' => 'PJ', 'Client[CodUnic]' => '12345678']));
        self::assertSame('', CnpMasker::cnpInForm(['Client[Tip]' => 'PF']));
    }

    public function testScrubReplacesTheCnpInEveryStringLeaf(): void
    {
        $response = [
            'Success' => false,
            'Message' => 'CNP 1980512123456 invalid',
            'Detalii' => ['Client' => ['CodUnic' => '1980512123456'], 'Cod' => 17],
            'Nimic' => null,
        ];

        self::assertSame(
            [
                'Success' => false,
                'Message' => 'CNP 19805******56 invalid',
                'Detalii' => ['Client' => ['CodUnic' => '19805******56'], 'Cod' => 17],
                'Nimic' => null,
            ],
            CnpMasker::scrubArray($response, '1980512123456'),
        );
        self::assertSame('CNP 19805******56 invalid', CnpMasker::scrubText('CNP 1980512123456 invalid', '1980512123456'));
    }

    public function testScrubWithNoCnpChangesNothing(): void
    {
        $data = ['Message' => 'order 1980512123456'];

        self::assertSame($data, CnpMasker::scrubArray($data, ''));
        self::assertSame('order 1980512123456', CnpMasker::scrubText('order 1980512123456', ''));
    }

    /**
     * Own process: func.php also defines the e-mail sender, whose absence
     * another suite relies on.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheProceduralHelperDelegates(): void
    {
        require_once __DIR__ . '/../../../func.php';

        self::assertSame('19805******56', fn_fgo_invoicing_mask_cnp('1980512123456'));
        self::assertSame('', fn_fgo_invoicing_mask_cnp(null));
    }
}
