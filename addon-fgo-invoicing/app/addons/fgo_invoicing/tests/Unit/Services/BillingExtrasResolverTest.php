<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\BillingExtrasResolver;
use Tygh\Addons\FgoInvoicing\Tests\Support\DbStub;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryProfileFieldCatalog;

/**
 * REGRESSION: CIF / Reg. Com. / CNP never reached FGO. BillingMapper read
 * fgo_billing_cui / fgo_billing_reg, but nothing ever put them into
 * $order_info, so every company customer was invoiced without a CIF.
 *
 * The resolver fills them from CS-Cart's custom profile fields, which
 * fn_get_order_info() returns as $order_info['fields'][field_id]. Pinned here:
 * the configured field wins, auto-detection finds the obvious labels (and
 * none of the look-alikes), the legacy columns only fill gaps, and nothing
 * the order already carries is overwritten.
 */
#[CoversClass(BillingExtrasResolver::class)]
final class BillingExtrasResolverTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * @param array<int, string> $fields field_id => value, as fn_get_order_info() returns them
     *
     * @return array<string, mixed>
     */
    private static function order(array $fields = [], array $extra = []): array
    {
        return array_replace(['order_id' => 1234, 'profile_id' => 0, 'fields' => $fields], $extra);
    }

    // ── (a) configured field ─────────────────────────────────────────────

    public function testConfiguredFieldsAreReadTrimmedWithoutQueryingTheCatalog(): void
    {
        $catalog = new InMemoryProfileFieldCatalog();
        $resolver = new BillingExtrasResolver($catalog, null, cifFieldId: 12, regComFieldId: 13, cnpFieldId: 14);

        $out = $resolver->resolve(self::order([12 => '  RO12345678 ', 13 => 'J40/1/2020', 14 => ' 1960101123456']));

        self::assertSame('RO12345678', $out['fgo_billing_cui']);
        self::assertSame('J40/1/2020', $out['fgo_billing_reg']);
        self::assertSame('1960101123456', $out['fgo_billing_cnp']);
        self::assertSame(0, $catalog->reads, 'a configured field that is present needs no catalog lookup');
    }

    public function testConfiguredFieldFallsBackToItsCodeOnTheOrderArray(): void
    {
        $catalog = (new InMemoryProfileFieldCatalog())->add(12, ['Anything'], 'fgo_cif');
        $resolver = new BillingExtrasResolver($catalog, null, cifFieldId: 12);

        $out = $resolver->resolve(self::order([], ['fgo_cif' => ' RO777 ']));

        self::assertSame('RO777', $out['fgo_billing_cui']);
    }

    /**
     * The merchant picked a field: another field that merely LOOKS like a CIF
     * must not be substituted when that one is empty.
     */
    public function testConfiguredFieldThatIsEmptyIsNotReplacedByAutoDetection(): void
    {
        $catalog = InMemoryProfileFieldCatalog::withDescriptions([12 => 'Tax', 13 => 'CUI']);
        $resolver = new BillingExtrasResolver($catalog, null, cifFieldId: 12);

        $out = $resolver->resolve(self::order([13 => 'RO999']));

        self::assertSame('', $out['fgo_billing_cui']);
    }

    /**
     * A picked field the store no longer has (deleted, or re-created under a
     * new id) must not leave the CIF empty for good: the settings form
     * already shows such a value as "Auto-detect", so behave like it.
     */
    public function testAConfiguredFieldTheStoreNoLongerHasFallsBackToAutoDetection(): void
    {
        $catalog = InMemoryProfileFieldCatalog::withDescriptions([13 => 'CUI', 14 => 'Nr. Reg. Com.']);
        $resolver = new BillingExtrasResolver($catalog, null, cifFieldId: 99, regComFieldId: 98);

        $out = $resolver->resolve(self::order([13 => 'RO999', 14 => 'J40/9/2020']));

        self::assertSame('RO999', $out['fgo_billing_cui']);
        self::assertSame('J40/9/2020', $out['fgo_billing_reg']);
    }

    // ── (b) auto-detection ───────────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function recognisedLabels(): array
    {
        return [
            'CIF'                          => ['CIF', 'fgo_billing_cui'],
            'CUI'                          => ['CUI', 'fgo_billing_cui'],
            'dotted C.U.I.'                => ['C.U.I.', 'fgo_billing_cui'],
            'Cod fiscal'                   => ['Cod fiscal', 'fgo_billing_cui'],
            'Codul fiscal al firmei'       => ['Codul fiscal al firmei', 'fgo_billing_cui'],
            'Cod unic de înregistrare'     => ['Cod unic de înregistrare', 'fgo_billing_cui'],
            'Cod de înregistrare fiscală'  => ['Cod de înregistrare fiscală', 'fgo_billing_cui'],
            'Cod de identificare fiscală'  => ['Cod de identificare fiscală', 'fgo_billing_cui'],
            'VAT ID'                       => ['VAT ID', 'fgo_billing_cui'],
            'VAT number'                   => ['VAT number', 'fgo_billing_cui'],
            'VAT No.'                      => ['VAT No.', 'fgo_billing_cui'],
            'Nr. TVA'                      => ['Nr. TVA', 'fgo_billing_cui'],
            'Număr de TVA'                 => ['Număr de TVA', 'fgo_billing_cui'],
            'Tax ID'                       => ['Tax ID', 'fgo_billing_cui'],
            'Reg. Com.'                    => ['Reg. Com.', 'fgo_billing_reg'],
            'Nr. Reg. Com.'                => ['Nr. Reg. Com.', 'fgo_billing_reg'],
            'Nr. ord. reg. com.'           => ['Nr. ord. reg. com.', 'fgo_billing_reg'],
            'Registrul Comerţului (cedilla)' => ['Nr. Registrul Comerţului', 'fgo_billing_reg'],
            'Număr de ordine'              => ['Număr de ordine', 'fgo_billing_reg'],
            'Trade register number'        => ['Trade register number', 'fgo_billing_reg'],
            'CNP'                          => ['CNP', 'fgo_billing_cnp'],
            'Cod numeric personal'         => ['Cod numeric personal', 'fgo_billing_cnp'],
            'Personal numeric code'        => ['Personal numeric code', 'fgo_billing_cnp'],
            // the statutory term (Codul fiscal) and other common spellings
            'Cod de înreg. în scopuri TVA' => ['Cod de înregistrare în scopuri de TVA', 'fgo_billing_cui'],
            'Număr de înreg. în scop. TVA' => ['Număr de înregistrare în scopuri de TVA', 'fgo_billing_cui'],
            'Cod unic (ends the label)'    => ['Cod unic', 'fgo_billing_cui'],
            'VAT registration number'      => ['VAT registration number', 'fgo_billing_cui'],
            'VAT Reg. No.'                 => ['VAT Reg. No.', 'fgo_billing_cui'],
            'Tax number'                   => ['Tax number', 'fgo_billing_cui'],
            'CUI firmă'                    => ['CUI firmă', 'fgo_billing_cui'],
            'CUI (opțional)'               => ['CUI (opțional)', 'fgo_billing_cui'],
            'Cod CUI'                      => ['Cod CUI', 'fgo_billing_cui'],
            'CUI:'                         => ['CUI:', 'fgo_billing_cui'],
            'Nr. R.C.'                     => ['Nr. R.C.', 'fgo_billing_reg'],
            'RegCom'                       => ['RegCom', 'fgo_billing_reg'],
            'dotted C.N.P.'                => ['C.N.P.', 'fgo_billing_cnp'],
        ];
    }

    #[DataProvider('recognisedLabels')]
    public function testAutoDetectRecognisesTheUsualLabels(string $description, string $key): void
    {
        $resolver = new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions([21 => $description]));

        $out = $resolver->resolve(self::order([21 => 'VALUE']));

        self::assertSame('VALUE', $out[$key], "'{$description}' should feed {$key}");
        foreach (['fgo_billing_cui', 'fgo_billing_reg', 'fgo_billing_cnp'] as $other) {
            if ($other !== $key) {
                self::assertSame('', $out[$other], "'{$description}' must not also feed {$other}");
            }
        }
    }

    /**
     * Look-alikes that must never be read as a tax id: sending a postal code
     * or a free-text note as CodUnic is worse than sending nothing.
     *
     * @return array<string, array{string}>
     */
    public static function lookAlikeLabels(): array
    {
        return [
            'Cod poștal'          => ['Cod poștal'],
            'Cod postal'          => ['Cod postal'],
            'TVA inclus'          => ['Preț cu TVA inclus'],
            'Plătitor de TVA'     => ['Plătitor de TVA'],
            'Private notes'       => ['Private notes'],
            'Specific requests'   => ['Specific requests'],
            'Circuit preferat'    => ['Circuit preferat'],
            'VAT alone'           => ['VAT'],
            'Cod client'          => ['Cod client'],
            'Număr comandă'       => ['Număr comandă'],
            'Registration date'   => ['Registration date'],
            // "cui" is also the Romanian pronoun "to whom"
            'Pentru cui…?'        => ['Pentru cui este rezervarea?'],
            'Cui îi trimitem…?'   => ['Cui îi trimitem factura?'],
            // "cod unic" without "de înregistrare / identificare"
            'Cod unic client'     => ['Cod unic client'],
            'Cod unic fidelitate' => ['Cod unic de fidelitate'],
        ];
    }

    #[DataProvider('lookAlikeLabels')]
    public function testAutoDetectIgnoresLookAlikes(string $description): void
    {
        $resolver = new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions([21 => $description]));

        $out = $resolver->resolve(self::order([21 => 'VALUE']));

        self::assertSame('', $out['fgo_billing_cui'], $description);
        self::assertSame('', $out['fgo_billing_reg'], $description);
        self::assertSame('', $out['fgo_billing_cnp'], $description);
    }

    /**
     * Field codes are [_a-z0-9] only: no separators, and the b_/s_ prefix of
     * a billing-and-shipping pair. A code holds no sentence, so "cui" counts
     * anywhere in it.
     *
     * @return array<string, array{string, string}>
     */
    public static function recognisedCodes(): array
    {
        return [
            'b_cui'     => ['b_cui', 'fgo_billing_cui'],
            'codfiscal' => ['codfiscal', 'fgo_billing_cui'],
            'regcom'    => ['regcom', 'fgo_billing_reg'],
            'NrRegCom'  => ['NrRegCom', 'fgo_billing_reg'],
            'b_cnp'     => ['b_cnp', 'fgo_billing_cnp'],
        ];
    }

    #[DataProvider('recognisedCodes')]
    public function testAutoDetectRecognisesTheUsualFieldCodes(string $code, string $key): void
    {
        $resolver = new BillingExtrasResolver((new InMemoryProfileFieldCatalog())->add(22, [], $code));

        $out = $resolver->resolve(self::order([22 => 'VALUE']));

        self::assertSame('VALUE', $out[$key], "code '{$code}' should feed {$key}");
    }

    public function testAutoDetectMatchesADescriptionInAnyLanguageOrTheFieldCode(): void
    {
        $catalog = (new InMemoryProfileFieldCatalog())
            ->add(30, ['Identificator', 'Tax ID'])
            ->add(31, ['Număr'], 'b_reg_com');
        $resolver = new BillingExtrasResolver($catalog);

        $out = $resolver->resolve(self::order([30 => 'RO1', 31 => 'J1']));

        self::assertSame('RO1', $out['fgo_billing_cui'], 'the English description matches');
        self::assertSame('J1', $out['fgo_billing_reg'], 'the field code matches');
    }

    public function testAutoDetectPrefersAFilledFieldThenTheLowestId(): void
    {
        $catalog = InMemoryProfileFieldCatalog::withDescriptions([40 => 'CIF', 41 => 'CUI', 42 => 'Cod fiscal']);
        $resolver = new BillingExtrasResolver($catalog);

        self::assertSame(
            'RO41',
            $resolver->resolve(self::order([40 => ' ', 41 => 'RO41', 42 => 'RO42']))['fgo_billing_cui'],
            'the empty lowest-id field loses to a filled one; among filled, the lowest id wins',
        );
    }

    /**
     * A field created in "billing and shipping" exists twice. When the
     * customer ships elsewhere the twins can differ: the invoice wants the
     * billing one, whatever the ids.
     */
    public function testAutoDetectPrefersTheBillingTwinOverTheShippingTwin(): void
    {
        $catalog = (new InMemoryProfileFieldCatalog())
            ->add(50, ['CIF'], '', 'S')
            ->add(51, ['CIF'], '', 'B');
        $resolver = new BillingExtrasResolver($catalog);

        $out = $resolver->resolve(self::order([50 => 'RO-SHIP', 51 => 'RO-BILL']));

        self::assertSame('RO-BILL', $out['fgo_billing_cui']);
    }

    public function testAutoDetectFindsNothingWhenNoFieldMatches(): void
    {
        $resolver = new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions([60 => 'Observații']));

        $out = $resolver->resolve(self::order([60 => 'RO123']));

        self::assertSame('', $out['fgo_billing_cui']);
    }

    // ── one field, two identifiers ───────────────────────────────────────

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function sharedFieldValues(): array
    {
        return [
            'a CNP'             => ['1960101123456', '', '1960101123456'],
            'a CNP with spaces' => ['196 0101 123456', '', '196 0101 123456'],
            'a CIF'             => ['RO12345678', 'RO12345678', ''],
        ];
    }

    #[DataProvider('sharedFieldValues')]
    public function testASharedCuiCnpFieldFeedsOnlyTheKeyItsShapeSays(string $value, string $cui, string $cnp): void
    {
        $resolver = new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions([70 => 'CUI/CNP']));

        $out = $resolver->resolve(self::order([70 => $value]));

        self::assertSame($cui, $out['fgo_billing_cui']);
        self::assertSame($cnp, $out['fgo_billing_cnp']);
    }

    public function testTheSameFieldPickedForCifAndCnpIsSplitToo(): void
    {
        $resolver = new BillingExtrasResolver(new InMemoryProfileFieldCatalog(), null, cifFieldId: 71, cnpFieldId: 71);

        $out = $resolver->resolve(self::order([71 => '2960101123456']));

        self::assertSame('', $out['fgo_billing_cui'], 'a private customer must not turn into a company');
        self::assertSame('2960101123456', $out['fgo_billing_cnp']);
    }

    /**
     * REGRESSION: a CNP typed into a CIF-only field ("CUI" is what many
     * Romanian stores ask everyone for) made the private customer a company
     * with CodUnic = their CNP. Thirteen digits cannot be a Romanian CIF.
     */
    public function testACnpTypedIntoACifOnlyFieldBecomesTheCnp(): void
    {
        $resolver = new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions([72 => 'CUI']));

        $out = $resolver->resolve(self::order([72 => ' 1960101123456 ']));

        self::assertSame('', $out['fgo_billing_cui']);
        self::assertSame('1960101123456', $out['fgo_billing_cnp']);
    }

    public function testACnpShapedCifIsClearedButDoesNotReplaceAResolvedCnp(): void
    {
        $resolver = new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions([73 => 'CIF', 74 => 'CNP']));

        $out = $resolver->resolve(self::order([73 => '1960101123456', 74 => '2960101123456']));

        self::assertSame('', $out['fgo_billing_cui']);
        self::assertSame('2960101123456', $out['fgo_billing_cnp'], 'the CNP field keeps its own value');
    }

    /**
     * Customers paste the label along with the id; it must not reach FGO.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function labelledValues(): array
    {
        return [
            'CUI: prefix'   => ['CUI: RO14399840', 'RO14399840', ''],
            'C.I.F. prefix' => ['C.I.F. 14399840', '14399840', ''],
            'CNP: prefix'   => ['CNP: 1960101123456', '', '1960101123456'],
        ];
    }

    #[DataProvider('labelledValues')]
    public function testALeadingLabelIsDroppedFromTheValue(string $typed, string $cui, string $cnp): void
    {
        $resolver = new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions([75 => 'CUI/CNP']));

        $out = $resolver->resolve(self::order([75 => $typed]));

        self::assertSame($cui, $out['fgo_billing_cui']);
        self::assertSame($cnp, $out['fgo_billing_cnp']);
    }

    public function testALegacyCuiHoldingACnpBecomesTheCnp(): void
    {
        $resolver = new BillingExtrasResolver(
            new InMemoryProfileFieldCatalog(),
            static fn (int $id): array => ['fgo_billing_cui' => '1960101123456', 'fgo_billing_tip' => 2],
        );

        $out = $resolver->resolve(self::order([], ['profile_id' => 9]));

        self::assertSame('', $out['fgo_billing_cui']);
        self::assertSame('1960101123456', $out['fgo_billing_cnp']);
    }

    // ── source detection (for client_vat_required / client_cnp_required) ─

    public function testHasSourceForIsTheConfiguredFieldOrAnAutoDetectMatch(): void
    {
        $catalog = InMemoryProfileFieldCatalog::withDescriptions([12 => 'Anything', 20 => 'Cod fiscal']);

        $configured = new BillingExtrasResolver($catalog, null, cifFieldId: 12, cnpFieldId: 12);
        self::assertTrue($configured->hasSourceFor(BillingExtrasResolver::KEY_CIF), 'the configured field');
        self::assertTrue($configured->hasSourceFor(BillingExtrasResolver::KEY_CNP), 'the configured field, whatever its label');

        $auto = new BillingExtrasResolver($catalog);
        self::assertTrue($auto->hasSourceFor(BillingExtrasResolver::KEY_CIF), '"Cod fiscal" is detected');
        self::assertFalse($auto->hasSourceFor(BillingExtrasResolver::KEY_CNP), 'no field reads like a CNP');

        $stale = new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions([20 => 'Observații']), null, cifFieldId: 99);
        self::assertFalse($stale->hasSourceFor(BillingExtrasResolver::KEY_CIF), 'a field id the store no longer has is no source');

        $none = new BillingExtrasResolver(new InMemoryProfileFieldCatalog());
        self::assertFalse($none->hasSourceFor(BillingExtrasResolver::KEY_CIF));
    }

    // ── (c) legacy columns ───────────────────────────────────────────────

    public function testLegacyColumnsFillOnlyWhatIsStillEmpty(): void
    {
        $calls = [];
        $legacy = static function (int $profileId) use (&$calls): array {
            $calls[] = $profileId;

            return [
                'fgo_billing_cui' => 'RO-LEGACY',
                'fgo_billing_reg' => ' J-LEGACY ',
                'fgo_billing_company' => 'Legacy SRL',
                'fgo_billing_tip' => 1,
            ];
        };
        $resolver = new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions([80 => 'CIF']), $legacy);

        $out = $resolver->resolve(self::order([80 => 'RO-FIELD'], [
            'profile_id' => 9,
            // what fn_get_order_info() leaves in these columns: present, blank
            'fgo_billing_company' => '',
            'fgo_billing_tip' => '',
        ]));

        self::assertSame([9], $calls, 'one lookup, for the order profile');
        self::assertSame('RO-FIELD', $out['fgo_billing_cui'], 'the profile field wins over the legacy column');
        self::assertSame('J-LEGACY', $out['fgo_billing_reg']);
        self::assertSame('Legacy SRL', $out['fgo_billing_company']);
        self::assertSame(1, $out['fgo_billing_tip']);
        self::assertSame('', $out['fgo_billing_cnp'], 'there is no legacy CNP column');
    }

    public function testLegacyLookupIsSkippedForGuestsAndWhenNothingIsMissing(): void
    {
        $calls = 0;
        $legacy = static function (int $profileId) use (&$calls): array {
            $calls++;

            return ['fgo_billing_tip' => 7];
        };
        $resolver = new BillingExtrasResolver(new InMemoryProfileFieldCatalog(), $legacy);

        $resolver->resolve(self::order([], ['profile_id' => 0]));
        self::assertSame(0, $calls, 'guest orders have no profile');

        $full = $resolver->resolve(self::order([], [
            'profile_id' => 9,
            'fgo_billing_cui' => 'RO1',
            'fgo_billing_reg' => 'J1',
            'fgo_billing_company' => 'ACME',
            'fgo_billing_tip' => 2,
        ]));
        self::assertSame(0, $calls, 'nothing to fill, nothing to read');
        self::assertSame(2, $full['fgo_billing_tip']);

        $out = $resolver->resolve(self::order([], ['profile_id' => 9]));
        self::assertSame(1, $calls);
        self::assertArrayNotHasKey('fgo_billing_tip', $out, 'an out-of-range legacy tip is ignored');
    }

    public function testANonBlankTipIsTheOrdersOwnEvenWhenUnexpected(): void
    {
        $resolver = new BillingExtrasResolver(
            new InMemoryProfileFieldCatalog(),
            static fn (int $id): array => ['fgo_billing_tip' => 1],
        );

        self::assertSame('3', $resolver->resolve(self::order([], ['profile_id' => 9, 'fgo_billing_tip' => '3']))['fgo_billing_tip']);
        self::assertSame(1, $resolver->resolve(self::order([], ['profile_id' => 9, 'fgo_billing_tip' => '0']))['fgo_billing_tip']);
    }

    public function testProductionLegacyLookupReadsTheUserProfileColumns(): void
    {
        require_once __DIR__ . '/../../../functions/profile_fields.php';
        DbStub::$row = [
            'fgo_billing_cui' => 'RO5',
            'fgo_billing_reg' => 'J5',
            'fgo_billing_company' => 'Five SRL',
            'fgo_billing_tip' => '1',
        ];

        $lookup = BillingExtrasResolver::userProfileLookup();

        self::assertSame([], $lookup(0), 'no profile, no query');
        self::assertSame([], DbStub::calls());

        $row = $lookup(5);
        self::assertSame('RO5', $row['fgo_billing_cui']);
        self::assertSame(1, $row['fgo_billing_tip']);
        self::assertCount(1, DbStub::calls('FROM ?:user_profiles'));
    }

    // ── invariants ───────────────────────────────────────────────────────

    public function testKeysTheOrderAlreadyCarriesAreNeverOverwritten(): void
    {
        $resolver = new BillingExtrasResolver(
            InMemoryProfileFieldCatalog::withDescriptions([90 => 'CIF', 91 => 'Nr. Reg. Com.', 92 => 'CNP']),
            static fn (int $id): array => ['fgo_billing_company' => 'Legacy SRL', 'fgo_billing_tip' => 2],
        );

        $out = $resolver->resolve(self::order([90 => 'RO-FIELD', 91 => 'J-FIELD', 92 => '1960101123456'], [
            'profile_id' => 9,
            'fgo_billing_cui' => 'RO-SET',
            'fgo_billing_reg' => 'J-SET',
            'fgo_billing_cnp' => 'CNP-SET',
            'fgo_billing_company' => 'Set SRL',
            'fgo_billing_tip' => 1,
        ]));

        self::assertSame('RO-SET', $out['fgo_billing_cui']);
        self::assertSame('J-SET', $out['fgo_billing_reg']);
        self::assertSame('CNP-SET', $out['fgo_billing_cnp']);
        self::assertSame('Set SRL', $out['fgo_billing_company']);
        self::assertSame(1, $out['fgo_billing_tip']);

        $carried = $resolver->resolve(self::order([], ['fgo_billing_cui' => 'CUI: 1960101123456']));
        self::assertSame('CUI: 1960101123456', $carried['fgo_billing_cui'], 'not even tidied');
        self::assertSame('', $carried['fgo_billing_cnp']);
    }

    /**
     * fn_get_order_info() puts '' in every ?:user_profiles column it knows,
     * so "present" is not "filled": a blank key is resolved like a missing one.
     */
    public function testBlankKeysAreResolvedLikeMissingOnes(): void
    {
        $resolver = new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions([95 => 'CUI']));

        $out = $resolver->resolve(self::order([95 => 'RO95'], ['fgo_billing_cui' => '  ']));

        self::assertSame('RO95', $out['fgo_billing_cui']);
    }

    public function testAnEmptyOrderComesBackWithBlankKeysAndIsOtherwiseUntouched(): void
    {
        $resolver = new BillingExtrasResolver(new InMemoryProfileFieldCatalog(), static fn (int $id): array => []);

        self::assertSame(
            ['fgo_billing_cui' => '', 'fgo_billing_reg' => '', 'fgo_billing_cnp' => ''],
            $resolver->resolve([]),
        );

        $order = ['order_id' => 5, 'fields' => 'not-an-array', 'email' => 'a@b.ro'];
        $out = $resolver->resolve($order);
        self::assertSame('a@b.ro', $out['email']);
        self::assertSame('not-an-array', $out['fields']);
        self::assertSame('', $out['fgo_billing_cui']);
    }
}
