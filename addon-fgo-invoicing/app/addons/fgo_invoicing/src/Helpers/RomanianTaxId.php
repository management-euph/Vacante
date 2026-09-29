<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Helpers;

/**
 * Normalisation and checksum validation of Romanian tax identifiers.
 *
 * Pure and dependency-free, so the issuing path, the bulk pre-check and any
 * future checkout validation all agree on what a CIF or a CNP looks like.
 *
 * The checksums are NOT enforced when issuing yet: FGO validates CIFs itself
 * when ValideazaCodUnicRo is on, and refusing an invoice on a local rule that
 * has not met real customer data would block genuine orders. The bulk
 * pre-check is the first intended consumer.
 *
 *  - CIF / CUI: an optional "RO" prefix (VAT payer), then 2..10 digits. The
 *    last digit is a check digit over the others, right-aligned against the
 *    key 753217532: check = (sum * 10) % 11, where 10 becomes 0.
 *  - CNP: 13 digits. The 13th is a check digit over the first twelve against
 *    the key 279146358279: check = sum % 11, where 10 becomes 1. The first
 *    digit (sex and century) is never 0.
 */
final class RomanianTaxId
{
    private const CIF_KEY = '753217532';
    private const CNP_KEY = '279146358279';

    private function __construct()
    {
    }

    /**
     * Drop a leading "CUI:" / "CIF" / "C.U.I." / "CNP:" label that customers
     * copy along with the identifier ("CIF: RO12345678" -> "RO12345678").
     * Only a label followed by a non-letter counts, so an identifier that
     * merely starts with those letters is left alone.
     */
    public static function stripLabel(string $value): string
    {
        return preg_replace('/^\s*(?:c\.?\s?u\.?\s?i|c\.?\s?i\.?\s?f|c\.?\s?n\.?\s?p)\b\.?\s*[:=]?\s*/iu', '', $value) ?? $value;
    }

    /**
     * Upper-case, drop a leading label (stripLabel) and the separators
     * customers type into an identifier: "RO 123 456 78", "ro-123.456.78",
     * "RO123456–78" (an en dash pasted from a document) and "CIF: RO12345678"
     * are all "RO12345678".
     */
    public static function compact(string $value): string
    {
        return strtoupper(preg_replace('/[\s.\-\x{2010}-\x{2015}]+/u', '', self::stripLabel($value)) ?? '');
    }

    /**
     * A Romanian CUI never starts with 0, so a zero-led body ("00",
     * "0000000000", the placeholder customers type most) is rejected even
     * though its weighted sum, 0, happens to "match" a check digit of 0.
     */
    public static function isValidCif(string $cif): bool
    {
        $digits = self::compact($cif);
        if (str_starts_with($digits, 'RO')) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^[1-9]\d{1,9}$/', $digits) !== 1) {
            return false;
        }

        $body = str_pad(substr($digits, 0, -1), strlen(self::CIF_KEY), '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0, $n = strlen(self::CIF_KEY); $i < $n; $i++) {
            $sum += (int) $body[$i] * (int) self::CIF_KEY[$i];
        }
        $expected = ($sum * 10) % 11;

        return ($expected === 10 ? 0 : $expected) === (int) substr($digits, -1);
    }

    public static function isValidCnp(string $cnp): bool
    {
        $digits = self::compact($cnp);
        if (preg_match('/^[1-9]\d{12}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        for ($i = 0, $n = strlen(self::CNP_KEY); $i < $n; $i++) {
            $sum += (int) $digits[$i] * (int) self::CNP_KEY[$i];
        }
        $expected = $sum % 11;

        return ($expected === 10 ? 1 : $expected) === (int) $digits[12];
    }

    /**
     * True when the value has the SHAPE of a CNP (13 digits), checksum aside.
     * A Romanian CIF is at most ten digits, so this is what tells the two
     * apart when a store collects both in one "CUI/CNP" field.
     */
    public static function looksLikeCnp(string $value): bool
    {
        return preg_match('/^\d{13}$/', self::compact($value)) === 1;
    }
}
