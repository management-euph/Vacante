<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Helpers\RomanianTaxId;

/**
 * The CIF / CNP checksums the bulk pre-check will rely on.
 *
 * Valid identifiers are DERIVED here — a body plus the check digit this test
 * computes independently from the published algorithm — rather than copied
 * from real companies or people. Each valid case is then broken one digit at
 * a time, so a validator that ignored the check digit could not pass.
 */
#[CoversClass(RomanianTaxId::class)]
final class RomanianTaxIdTest extends TestCase
{
    /** Append the CIF check digit (key 753217532, (sum * 10) % 11, 10 -> 0). */
    private static function cif(string $body): string
    {
        $padded = str_pad($body, 9, '0', STR_PAD_LEFT);
        $key = '753217532';
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $padded[$i] * (int) $key[$i];
        }
        $check = ($sum * 10) % 11;

        return $body . ($check === 10 ? '0' : (string) $check);
    }

    /** Append the CNP check digit (key 279146358279, sum % 11, 10 -> 1). */
    private static function cnp(string $first12): string
    {
        $key = '279146358279';
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $first12[$i] * (int) $key[$i];
        }
        $check = $sum % 11;

        return $first12 . ($check === 10 ? '1' : (string) $check);
    }

    /** The same identifier with its check digit changed, so it must fail. */
    private static function breakCheckDigit(string $id): string
    {
        $last = (int) substr($id, -1);

        return substr($id, 0, -1) . (string) (($last + 1) % 10);
    }

    // ── CIF ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function cifBodies(): array
    {
        return [
            'shortest (2 digits)' => ['1'],
            'typical 8 digits'    => ['1234567'],
            'longest (10 digits)' => ['987654321'],
            'another 8 digits'    => ['1590082'],
            'another 7 digits'    => ['400000'],
            'check digit 10->0'   => [self::bodyWhoseCifCheckIsTen()],
        ];
    }

    /**
     * A body whose (sum * 10) % 11 is 10, so the "10 becomes 0" branch is
     * exercised rather than assumed. Found by search, not hand-picked.
     */
    private static function bodyWhoseCifCheckIsTen(): string
    {
        $key = '753217532';
        for ($n = 1000000; $n < 1001000; $n++) {
            $padded = str_pad((string) $n, 9, '0', STR_PAD_LEFT);
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $sum += (int) $padded[$i] * (int) $key[$i];
            }
            if (($sum * 10) % 11 === 10) {
                return (string) $n;
            }
        }

        throw new \LogicException('no body found in range');
    }

    #[DataProvider('cifBodies')]
    public function testAComputedCifIsValidWithOrWithoutTheRoPrefix(string $body): void
    {
        $cif = self::cif($body);

        self::assertTrue(RomanianTaxId::isValidCif($cif), $cif);
        self::assertTrue(RomanianTaxId::isValidCif('RO' . $cif), 'RO' . $cif);
        self::assertTrue(RomanianTaxId::isValidCif('ro ' . $cif), 'lower-case prefix and a space');
    }

    #[DataProvider('cifBodies')]
    public function testACifWithAWrongCheckDigitIsInvalid(string $body): void
    {
        $broken = self::breakCheckDigit(self::cif($body));

        self::assertFalse(RomanianTaxId::isValidCif($broken), $broken);
        self::assertFalse(RomanianTaxId::isValidCif('RO' . $broken));
    }

    public function testSeparatorsCustomersTypeAreIgnored(): void
    {
        $cif = self::cif('1234567');
        $spaced = 'RO ' . substr($cif, 0, 3) . ' ' . substr($cif, 3, 3) . '.' . substr($cif, 6);

        self::assertTrue(RomanianTaxId::isValidCif($spaced), $spaced);
        self::assertTrue(RomanianTaxId::isValidCif(' ' . substr($cif, 0, 4) . '-' . substr($cif, 4) . ' '));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCifs(): array
    {
        return [
            'empty'           => [''],
            'prefix only'     => ['RO'],
            'one digit'       => ['7'],
            'eleven digits'   => ['12345678901'],
            'letters inside'  => ['12A45678'],
            'foreign prefix'  => ['DE123456789'],
            'prefix twice'    => ['RORO12345678'],
            // Weighted sum 0 "matches" check digit 0; a CUI never starts with 0.
            'all zeros (2)'   => ['00'],
            'all zeros (10)'  => ['0000000000'],
            'RO + zeros'      => ['RO00000000'],
            'leading zero'    => ['0' . self::cif('1234567')],
        ];
    }

    #[DataProvider('malformedCifs')]
    public function testMalformedCifsAreInvalid(string $cif): void
    {
        self::assertFalse(RomanianTaxId::isValidCif($cif), $cif);
    }

    // ── CNP ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function cnpBodies(): array
    {
        return [
            'male, 1900s'       => ['196010112345'],
            'female, 1900s'     => ['285123140012'],
            'male, 2000s'       => ['501022912399'],
            'resident, foreign' => ['700061500001'],
            'check digit 10->1' => [self::bodyWhoseCnpSumIsTenModEleven()],
        ];
    }

    /**
     * A body whose weighted sum is 10 (mod 11), so the "10 becomes 1" branch
     * is exercised rather than assumed. Found by search, not hand-picked.
     */
    private static function bodyWhoseCnpSumIsTenModEleven(): string
    {
        $key = '279146358279';
        for ($n = 100000000000; $n < 100000001000; $n++) {
            $body = (string) $n;
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int) $body[$i] * (int) $key[$i];
            }
            if ($sum % 11 === 10) {
                return $body;
            }
        }

        throw new \LogicException('no body found in range');
    }

    #[DataProvider('cnpBodies')]
    public function testAComputedCnpIsValid(string $first12): void
    {
        $cnp = self::cnp($first12);

        self::assertTrue(RomanianTaxId::isValidCnp($cnp), $cnp);
        self::assertTrue(RomanianTaxId::isValidCnp(' ' . substr($cnp, 0, 7) . ' ' . substr($cnp, 7) . ' '));
        self::assertTrue(RomanianTaxId::looksLikeCnp($cnp));
    }

    #[DataProvider('cnpBodies')]
    public function testACnpWithAWrongCheckDigitIsInvalid(string $first12): void
    {
        $broken = self::breakCheckDigit(self::cnp($first12));

        self::assertFalse(RomanianTaxId::isValidCnp($broken), $broken);
        self::assertTrue(RomanianTaxId::looksLikeCnp($broken), 'shape alone still says CNP');
    }

    public function testTheTenBecomesOneBranchIsReallyTaken(): void
    {
        self::assertSame('1', substr(self::cnp(self::bodyWhoseCnpSumIsTenModEleven()), -1));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCnps(): array
    {
        return [
            'empty'           => [''],
            'twelve digits'   => ['196010112345'],
            'fourteen digits' => ['19601011234567'],
            'letters'         => ['19601011234AB'],
            // A computed check digit, so only the zero sex digit is wrong.
            'sex digit 0'     => [self::cnp('096010112345')],
        ];
    }

    #[DataProvider('malformedCnps')]
    public function testMalformedCnpsAreInvalid(string $cnp): void
    {
        self::assertFalse(RomanianTaxId::isValidCnp($cnp), $cnp);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    public function testCompactUppercasesAndDropsSeparators(): void
    {
        self::assertSame('RO12345678', RomanianTaxId::compact(" ro 123.456-78\t"));
        self::assertSame('RO12345678', RomanianTaxId::compact('RO 123456–78'), 'an en dash pasted from a document');
        self::assertSame('RO12345678', RomanianTaxId::compact("RO\u{00A0}12345678"), 'a non-breaking space');
        self::assertSame('J40/1/2020', RomanianTaxId::compact('j40/1/2020'), 'slashes are kept');
        self::assertSame('', RomanianTaxId::compact(''));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function labelledIds(): array
    {
        return [
            'CUI:'       => ['CUI: RO14399840', 'RO14399840'],
            'CIF'        => ['CIF RO 14399840', 'RO 14399840'],
            'C.U.I.'     => ['C.U.I. 14399840', '14399840'],
            'c.i.f.:'    => ['c.i.f.: 14399840', '14399840'],
            'CNP:'       => ['CNP: 1960101123456', '1960101123456'],
            'label only' => ['CUI', ''],
            'no label'   => ['RO14399840', 'RO14399840'],
            // letters that merely start like a label are part of the value
            'CUIUL'      => ['Cuiul', 'Cuiul'],
            'CIFRO'      => ['CIFRO12', 'CIFRO12'],
        ];
    }

    #[DataProvider('labelledIds')]
    public function testALeadingLabelIsStripped(string $typed, string $expected): void
    {
        self::assertSame($expected, RomanianTaxId::stripLabel($typed));
        self::assertSame(RomanianTaxId::compact($expected), RomanianTaxId::compact($typed), 'compact() strips it too');
    }

    public function testLooksLikeCnpIsAboutShapeOnly(): void
    {
        self::assertTrue(RomanianTaxId::looksLikeCnp('196 0101 123456'));
        self::assertFalse(RomanianTaxId::looksLikeCnp('RO12345678'));
        self::assertFalse(RomanianTaxId::looksLikeCnp('123456789012'));
        self::assertFalse(RomanianTaxId::looksLikeCnp(''));
    }
}
