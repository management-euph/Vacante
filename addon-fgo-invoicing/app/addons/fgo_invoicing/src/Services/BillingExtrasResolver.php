<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Api\FgoSigner;
use Tygh\Addons\FgoInvoicing\Helpers\RomanianTaxId;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Repository\ProfileFieldCatalog;

/**
 * Fills the `fgo_billing_*` keys BillingMapper reads (CIF, Reg. Com., CNP)
 * from where a CS-Cart store actually keeps them.
 *
 * WHY: BillingMapper always read `fgo_billing_cui` / `fgo_billing_reg`, but
 * nothing ever put them into $order_info, so every company customer was
 * invoiced without a CIF. The source of truth is now CS-Cart's own custom
 * profile fields: the merchant creates "CIF", "Nr. Reg. Com." and optionally
 * "CNP" under Administration > Profile fields, the customer fills them at
 * checkout, and fn_get_order_info() returns them per order as
 * $order_info['fields'][field_id] => raw string.
 *
 * Resolution, per key, never touching a key the order already carries:
 *   a. the field the merchant picked in the add-on settings (cif_field,
 *      reg_com_field, cnp_field). A picked id the store no longer has (the
 *      field was deleted or re-created) falls through to (b): the settings
 *      form already shows such a value as "Auto-detect", and a stale id
 *      would otherwise leave the CIF empty for good;
 *   b. when that setting is empty ("Auto-detect", also what a store runs on
 *      before the setting row exists): the custom field whose description or
 *      code reads like one, diacritic-insensitive. Several matches: the one
 *      holding a value wins, then a billing/contact field over its shipping
 *      twin, then the lowest field id;
 *   c. the add-on's legacy ?:user_profiles.fgo_billing_* columns, via
 *      $order_info['profile_id'], for whatever is still empty. That source
 *      alone also supplies fgo_billing_company and fgo_billing_tip.
 *
 * Then, on the values resolved here (never on ones the order carried):
 *   - a leading "CUI:" / "C.I.F." / "CNP:" label is dropped;
 *   - a CIF with the shape of a CNP (thirteen digits; a Romanian CIF has at
 *     most ten) is a CNP typed into the CIF field, or a shared "CUI/CNP"
 *     field: it moves to fgo_billing_cnp (when that is empty) and the CIF is
 *     cleared. Left as a CIF it would invoice a private person as a company.
 *     A "CUI/CNP" field holding a real CIF is kept out of fgo_billing_cnp.
 *
 * Pure: the catalog and the legacy lookup are injected (Container wires the
 * database-backed ones), so every rule here is unit-tested without CS-Cart.
 */
final class BillingExtrasResolver
{
    public const KEY_CIF = 'fgo_billing_cui';
    public const KEY_REG_COM = 'fgo_billing_reg';
    public const KEY_CNP = 'fgo_billing_cnp';
    public const KEY_COMPANY = 'fgo_billing_company';
    public const KEY_TIP = 'fgo_billing_tip';

    /**
     * Matched against a description or code after words(): lower-case ASCII
     * words separated by single spaces ("Nr. Reg. Com." -> "nr reg com",
     * "Cod unic de înregistrare" -> "cod unic de inregistrare"). Codes have no
     * separators ("codfiscal", "NrRegCom"), hence the optional spaces.
     *
     * Every pattern is word-bounded and needs its qualifier, so "Cod poștal",
     * "Preț cu TVA inclus", "Private notes", "Cod unic client" or "Cod unic
     * de fidelitate" never read as a tax id.
     *
     * The bare word "cui" is NOT here: it is also the everyday Romanian
     * pronoun "to whom" ("Pentru cui este rezervarea?"). See BARE_CUI.
     */
    private const PATTERNS = [
        self::KEY_CIF => [
            '/\b(cif|c i f)\b/',
            '/\bcod(ul)? ?fiscal\b/',
            '/\bcod(ul)? unic( (de )?(inregistrare|identificare)\b|$)/',
            '/\b(identificare|inregistrare) fiscala\b/',
            '/\b(identificare|inregistrare) in scopuri de tva\b/',
            '/\bvat (id|no|nr|number|code)\b/',
            '/\bvat reg(istration)? (no|nr|number)\b/',
            '/\b(nr|numar|cod(ul)?) (de )?tva\b/',
            '/\btax (id|identification|number|no|nr)\b/',
        ],
        self::KEY_REG_COM => [
            '/\breg com\b/',
            '/\b(nr ?)?regcom\b/',
            '/\breg(istrul|istru)? comert(ului)?\b/',
            '/\b(nr|numar) (de )?ordine\b/',
            '/\bnr r c\b/',
            '/\btrade regist(er|ry)\b/',
            '/\bcompany reg(istration)? (number|no|nr)\b/',
            '/\bonrc\b/',
        ],
        self::KEY_CNP => [
            '/\b(cnp|c n p)\b/',
            '/\bcod(ul)? numeric personal\b/',
            '/\bpersonal (numeric|identification) (code|number)\b/',
        ],
    ];

    /**
     * A DESCRIPTION reads as a CIF through the bare word "cui" only when that
     * word essentially IS the label: "CUI", "C.U.I.", "CUI/CNP", "CUI firmă",
     * "Cod CUI", "CUI (opțional)" (parentheses are dropped first). Never
     * inside a sentence — "Pentru cui este rezervarea?", "Cui îi trimitem
     * factura?" — where it means "to whom". Field codes can hold no sentence,
     * so a code matches "cui" anywhere ("b_cui").
     */
    private const BARE_CUI = '/^((cod|codul|unic|nr|numar|cnp|cif) )*(cui|c u i)( (ul|firma|firmei|companie|companiei|societate|societatii|cnp|cif))*$/';

    /** How a field CODE (admin "Code", [_a-z0-9] only) reads as a CIF. */
    private const CODE_CUI = '/\bcui\b/';

    /**
     * The three field ids are the add-on settings (ConfigProvider::cifFieldId()
     * and siblings); 0 means auto-detect. $legacyLookup maps a profile_id to
     * its legacy fgo_billing_* values; null means there is no legacy source.
     *
     * @param (\Closure(int): array<string, mixed>)|null $legacyLookup
     */
    public function __construct(
        private readonly ProfileFieldCatalog $catalog,
        private readonly ?\Closure $legacyLookup = null,
        private readonly int $cifFieldId = 0,
        private readonly int $regComFieldId = 0,
        private readonly int $cnpFieldId = 0,
    ) {
    }

    /**
     * Production legacy source: the ?:user_profiles columns the add-on adds at
     * install, read through fn_fgo_invoicing_get_billing_extras().
     *
     * Best-effort by design: on a store where those columns were never
     * created the query fails, and a fallback that throws must not stop an
     * invoice the profile fields could have described.
     *
     * @return \Closure(int): array<string, mixed>
     */
    public static function userProfileLookup(): \Closure
    {
        return static function (int $profileId): array {
            if ($profileId <= 0 || !function_exists('fn_fgo_invoicing_get_billing_extras')) {
                return [];
            }
            try {
                return fn_fgo_invoicing_get_billing_extras($profileId);
            } catch (\Throwable) {
                return [];
            }
        };
    }

    /**
     * A copy of $orderInfo that always carries fgo_billing_cui, _reg and _cnp
     * (possibly ''), plus fgo_billing_company / _tip when the legacy columns
     * had them.
     *
     * @param array<string, mixed> $orderInfo
     *
     * @return array<string, mixed>
     */
    public function resolve(array $orderInfo): array
    {
        $out = $orderInfo;

        $hits = [];
        foreach ($this->configuredIds() as $key => $fieldId) {
            if (self::filled($orderInfo[$key] ?? null)) {
                continue;
            }
            $hits[$key] = $this->resolveKey($orderInfo, $key, $fieldId);
        }

        foreach (self::splitSharedCifCnpField($hits) as $key => $hit) {
            $out[$key] = $hit['value'];
        }

        return self::tidyIdentifiers($this->applyLegacy($out), $orderInfo);
    }

    /**
     * Whether the store has anywhere a customer could have typed this
     * identifier: the configured field, when the store still has it, or at
     * least one field auto-detection recognises for $key.
     *
     * InvoiceIssuer asks before refusing an invoice for a missing CIF / CNP:
     * on a store with no such field nobody could ever have supplied one, and
     * refusing would stop every invoice for a setting the merchant ticked
     * back when it did nothing.
     */
    public function hasSourceFor(string $key): bool
    {
        $fieldId = $this->configuredIds()[$key] ?? 0;
        $fields = $this->catalog->fields();
        if ($fieldId > 0 && isset($fields[$fieldId])) {
            return true;
        }
        foreach ($fields as $field) {
            if (self::matches($field, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int> key => configured field id (0 = auto-detect)
     */
    private function configuredIds(): array
    {
        return [
            self::KEY_CIF => $this->cifFieldId,
            self::KEY_REG_COM => $this->regComFieldId,
            self::KEY_CNP => $this->cnpFieldId,
        ];
    }

    /**
     * @param array<string, mixed> $orderInfo
     *
     * @return array{value: string, field: int}
     */
    private function resolveKey(array $orderInfo, string $key, int $fieldId): array
    {
        if ($fieldId > 0) {
            $value = $this->fieldValue($orderInfo, $fieldId);
            // A value on the order proves the field is real, so the catalog
            // is consulted only for an empty one: still in the store, it is
            // the merchant's choice and stays empty; gone, it is a stale id.
            if ($value !== '' || isset($this->catalog->fields()[$fieldId])) {
                return ['value' => $value, 'field' => $fieldId];
            }
        }

        return $this->autoDetect($orderInfo, $key);
    }

    /**
     * @param array<string, mixed> $orderInfo
     */
    private function fieldValue(array $orderInfo, int $fieldId): string
    {
        $fields = $orderInfo['fields'] ?? null;
        if (is_array($fields)) {
            $value = trim(TypeCoerce::toString($fields[$fieldId] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        // Defensive second source: a field that has a code may also surface
        // flattened as $order_info[<code>] on builds or add-ons that merge
        // profile data into the order array. Looked up only when needed, so a
        // configured field that is present costs no catalog query.
        $field = $this->catalog->fields()[$fieldId] ?? null;
        $name = $field !== null ? $field['field_name'] : '';

        return $name !== '' ? trim(TypeCoerce::toString($orderInfo[$name] ?? '')) : '';
    }

    /**
     * @param array<string, mixed> $orderInfo
     *
     * @return array{value: string, field: int}
     */
    private function autoDetect(array $orderInfo, string $key): array
    {
        $best = ['value' => '', 'field' => 0];
        $bestScore = PHP_INT_MAX;

        foreach ($this->catalog->fields() as $fieldId => $field) {
            if (!self::matches($field, $key)) {
                continue;
            }
            $value = $this->fieldValue($orderInfo, $fieldId);
            // Lower wins: a filled field beats an empty one; then a billing or
            // contact field beats a shipping twin (same label, possibly a
            // different value when the customer ships elsewhere).
            $score = ($value !== '' ? 0 : 2) + ($field['section'] === 'S' ? 1 : 0);
            if ($score < $bestScore || ($score === $bestScore && $fieldId < $best['field'])) {
                $best = ['value' => $value, 'field' => $fieldId];
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @param array{field_name: string, section: string, descriptions: list<string>} $field
     */
    private static function matches(array $field, string $key): bool
    {
        foreach ($field['descriptions'] as $description) {
            $ascii = self::ascii($description);
            $words = self::words($ascii);
            if (self::matchesAny($key, $words)) {
                return true;
            }
            if ($key === self::KEY_CIF) {
                $unbracketed = self::words(preg_replace('/\([^)]*\)?/', ' ', $ascii) ?? $ascii);
                if (preg_match(self::BARE_CUI, $words) === 1 || preg_match(self::BARE_CUI, $unbracketed) === 1) {
                    return true;
                }
            }
        }

        if ($field['field_name'] === '') {
            return false;
        }
        $code = self::words(self::ascii($field['field_name']));

        return self::matchesAny($key, $code) || ($key === self::KEY_CIF && preg_match(self::CODE_CUI, $code) === 1);
    }

    private static function matchesAny(string $key, string $words): bool
    {
        foreach (self::PATTERNS[$key] ?? [] as $pattern) {
            if (preg_match($pattern, $words) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower-case ASCII. Reuses the signer's transliteration (comma-below
     * ș/ț) and adds the legacy cedilla ş, which convertDiacritics2() must not
     * learn: it feeds the request Hash and has to match FGO's reference
     * implementation byte for byte.
     */
    private static function ascii(string $text): string
    {
        return mb_strtolower(str_replace(['ş', 'Ş'], ['s', 'S'], FgoSigner::convertDiacritics2($text)), 'UTF-8');
    }

    /** Lower-case ASCII words separated by single spaces. */
    private static function words(string $ascii): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '');
    }

    /**
     * One field feeding both CIF and CNP (a "CUI/CNP" field, or the same id
     * picked in both settings) holds one identifier, not two: keep it only
     * under the key its shape says.
     *
     * @param array<string, array{value: string, field: int}> $hits
     *
     * @return array<string, array{value: string, field: int}>
     */
    private static function splitSharedCifCnpField(array $hits): array
    {
        $cif = $hits[self::KEY_CIF] ?? null;
        $cnp = $hits[self::KEY_CNP] ?? null;
        if ($cif === null || $cnp === null || $cif['field'] <= 0 || $cif['field'] !== $cnp['field'] || $cif['value'] === '') {
            return $hits;
        }

        if (RomanianTaxId::looksLikeCnp($cif['value'])) {
            $hits[self::KEY_CIF]['value'] = '';
        } else {
            $hits[self::KEY_CNP]['value'] = '';
        }

        return $hits;
    }

    /**
     * Clean the CIF / CNP this resolver filled in (see the class docblock);
     * keys $original already carried are left exactly as they were.
     *
     * @param array<string, mixed> $out
     * @param array<string, mixed> $original
     *
     * @return array<string, mixed>
     */
    private static function tidyIdentifiers(array $out, array $original): array
    {
        $ours = [];
        foreach ([self::KEY_CIF, self::KEY_CNP] as $key) {
            $ours[$key] = !self::filled($original[$key] ?? null);
            if ($ours[$key] && isset($out[$key])) {
                $out[$key] = trim(RomanianTaxId::stripLabel(TypeCoerce::toString($out[$key])));
            }
        }

        $cif = TypeCoerce::toString($out[self::KEY_CIF] ?? '');
        if ($ours[self::KEY_CIF] && RomanianTaxId::looksLikeCnp($cif)) {
            if (!self::filled($out[self::KEY_CNP] ?? null)) {
                $out[self::KEY_CNP] = $cif;
            }
            $out[self::KEY_CIF] = '';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $out
     *
     * @return array<string, mixed>
     */
    private function applyLegacy(array $out): array
    {
        $lookup = $this->legacyLookup;
        $profileId = TypeCoerce::toInt($out['profile_id'] ?? 0);
        if ($lookup === null || $profileId <= 0) {
            return $out;
        }

        $missing = [];
        foreach ([self::KEY_CIF, self::KEY_REG_COM, self::KEY_COMPANY] as $key) {
            if (!self::filled($out[$key] ?? null)) {
                $missing[] = $key;
            }
        }
        // '' / null / 0 all read as "no tip"; any other value is the order's own.
        $tipMissing = TypeCoerce::toInt($out[self::KEY_TIP] ?? 0) === 0;
        if ($missing === [] && !$tipMissing) {
            return $out;
        }

        $legacy = $lookup($profileId);
        foreach ($missing as $key) {
            $value = trim(TypeCoerce::toString($legacy[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }
        $tip = TypeCoerce::toInt($legacy[self::KEY_TIP] ?? 0);
        if ($tipMissing && in_array($tip, [1, 2], true)) {
            $out[self::KEY_TIP] = $tip;
        }

        return $out;
    }

    /**
     * fn_get_order_info() fills every ?:user_profiles column it knows with ''
     * (fn_fill_user_fields), so the legacy keys are PRESENT but blank on every
     * real order: "set" is not "filled", and `??` chains stop at the blank.
     */
    private static function filled(mixed $value): bool
    {
        return trim(TypeCoerce::toString($value)) !== '';
    }
}
