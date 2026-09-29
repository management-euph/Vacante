<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Helpers;

use Tygh\Addons\FgoInvoicing\Constants;

/**
 * Masks the CNP (Romanian personal numeric code) wherever the add-on keeps a
 * copy of what it sent to FGO.
 *
 * WHY: for an individual, Client[CodUnic] is the CNP, and the add-on stores
 * the request/response of every attempt for diagnostics (?:fgo_invoices,
 * ?:fgo_diagnostic_logs). Those copies sit outside the order tables, where
 * CS-Cart's own data handling does not reach them. The order itself still
 * holds the real value, legally, for invoicing; the diagnostic copies only
 * need its shape.
 *
 * FGO rejections are almost never about the digits themselves but about a
 * failed checksum, a wrong customer type or a missing field. So the mask keeps
 * enough to debug that: the first 5 digits (sex/century, birth year and
 * month) and the last 2, e.g. 1980512123456 -> 19805******56. The digit count
 * and checksum verdict go to ?:fgo_diagnostic_logs as their own columns.
 */
final class CnpMasker
{
    private function __construct()
    {
    }

    /**
     * '' for no value; 13 digits -> first 5 + '******' + last 2; anything
     * else -> 'INVALID_LENGTH_<digit count>' (nothing of it is kept).
     * Non-digits are ignored, so '198 0512 123456' masks like the compact form.
     */
    public static function mask(?string $cnp): string
    {
        if ($cnp === null || $cnp === '') {
            return '';
        }

        $digits = (string) preg_replace('/\D/', '', $cnp);
        if (strlen($digits) === 13) {
            return substr($digits, 0, 5) . '******' . substr($digits, -2);
        }

        return 'INVALID_LENGTH_' . strlen($digits);
    }

    /**
     * The form sent to FGO with the individual's Client[CodUnic] masked.
     * A company's CodUnic is its CIF, public by law, and stays as sent.
     *
     * @param array<string, scalar|null> $form
     *
     * @return array<string, scalar|null>
     */
    public static function maskForm(array $form): array
    {
        if (($form['Client[Tip]'] ?? null) !== Constants::TIP_PERSON) {
            return $form;
        }
        $codUnic = $form['Client[CodUnic]'] ?? null;
        if ($codUnic === null || $codUnic === '') {
            return $form;
        }
        $form['Client[CodUnic]'] = self::mask((string) $codUnic);

        return $form;
    }

    /**
     * The CNP as it appears in the form (an individual's Client[CodUnic]),
     * or '' — the value scrub() must remove from anything stored.
     *
     * @param array<string, scalar|null> $form
     */
    public static function cnpInForm(array $form): string
    {
        if (($form['Client[Tip]'] ?? null) !== Constants::TIP_PERSON) {
            return '';
        }

        return TypeCoerce::toString($form['Client[CodUnic]'] ?? '');
    }

    /**
     * $text with every occurrence of $cnp replaced by its mask: FGO may quote
     * the value back in an error ("CNP 1980512123456 invalid").
     */
    public static function scrubText(string $text, string $cnp): string
    {
        return $cnp === '' ? $text : str_replace($cnp, self::mask($cnp), $text);
    }

    /**
     * $data (an FGO response) with scrubText() applied to every string leaf,
     * at any depth; other values are kept as they are.
     *
     * @template K of array-key
     *
     * @param array<K, mixed> $data
     *
     * @return array<K, mixed>
     */
    public static function scrubArray(array $data, string $cnp): array
    {
        if ($cnp === '') {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = match (true) {
                is_string($value) => self::scrubText($value, $cnp),
                is_array($value) => self::scrubArray($value, $cnp),
                default => $value,
            };
        }

        return $out;
    }
}
