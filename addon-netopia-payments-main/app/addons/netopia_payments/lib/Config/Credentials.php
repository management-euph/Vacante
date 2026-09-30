<?php

declare(strict_types=1);

namespace Netopia\CsCart\Config;

use Netopia\CsCart\Key\KeyFileName;
use Netopia\CsCart\Support\Arr;
use Netopia\Payment2\Enum\PaymentMode;

/**
 * Per-mode API credentials.
 *
 * NETOPIA issues a separate API key (and a sandbox account has its own POS
 * signature) for Sandbox and Live, so the settings screen keeps one pair per
 * mode: sandbox_api_key / sandbox_pos_signature and live_api_key /
 * live_pos_signature.
 *
 * Every runtime reader (checkout, IPN, 3DS return, payment links, refunds)
 * reads the plain `api_key` / `pos_signature`. Those are the ACTIVE mode's
 * pair, copied on every save by applyActive(), so switching modes is a
 * matter of picking the mode and saving: no key is ever retyped. The
 * runtime also runs applyActive() on the params it loads, so a config saved
 * before a rule here changed still gets the resolved pair.
 *
 * NETOPIA requires the POS signature on every payment, but it never has to
 * be typed: NETOPIA's key files carry it in their names (Key\KeyFileName),
 * so an empty POS signature is taken from the mode's uploaded key file.
 *
 * Installs from before the split have only the plain pair; forMode() then
 * falls back to it for the mode that was saved, so nothing is lost.
 */
final class Credentials
{
    public const array FIELDS = ['api_key', 'pos_signature'];

    /** posSignatureSource(): typed into the settings (or saved from a key file on Save). */
    public const string SOURCE_TYPED = 'typed';

    /** posSignatureSource(): read from the name of the mode's uploaded key file. */
    public const string SOURCE_KEY_FILE = 'key_file';

    /** The key file slots whose names may carry the POS signature, public first. */
    private const array KEY_TYPES = ['public_key', 'private_key'];

    /**
     * The pair a mode uses.
     *
     * @param array<string, mixed> $params
     * @return array{api_key: string, pos_signature: string}
     */
    public static function forMode(array $params, PaymentMode $mode): array
    {
        $posSignature = self::typed($params, $mode, 'pos_signature');
        if ($posSignature === '') {
            $posSignature = self::posSignatureFromKeyFiles($params, $mode);
        }

        return ['api_key' => self::typed($params, $mode, 'api_key'), 'pos_signature' => $posSignature];
    }

    /**
     * Where the mode's POS signature comes from: typed in (SOURCE_TYPED), the
     * uploaded key file's name (SOURCE_KEY_FILE), or nowhere ('').
     *
     * @param array<string, mixed> $params
     */
    public static function posSignatureSource(array $params, PaymentMode $mode): string
    {
        if (self::typed($params, $mode, 'pos_signature') !== '') {
            return self::SOURCE_TYPED;
        }

        return self::posSignatureFromKeyFiles($params, $mode) !== '' ? self::SOURCE_KEY_FILE : '';
    }

    /**
     * The POS signature in the names of the mode's uploaded key files
     * ({mode}_public_key_file, then {mode}_private_key_file); '' when none
     * carries one.
     *
     * A file named for the other mode is skipped: it carries the other
     * mode's signature, and NETOPIA would refuse the payment with it.
     *
     * @param array<string, mixed> $params
     */
    public static function posSignatureFromKeyFiles(array $params, PaymentMode $mode): string
    {
        foreach (self::KEY_TYPES as $type) {
            $file = trim(Arr::string($params, $mode->value . '_' . $type . '_file'));
            if ($file === '' || KeyFileName::wrongMode($file, $mode) !== null) {
                continue;
            }
            $signature = KeyFileName::posSignature($file);
            if ($signature !== '') {
                return $signature;
            }
        }

        return '';
    }

    /**
     * What a pair lacks, in FIELDS order: [] when it is complete.
     *
     * @param array{api_key: string, pos_signature: string} $pair
     * @return list<string>
     */
    public static function missing(array $pair): array
    {
        $missing = [];
        foreach (self::FIELDS as $field) {
            if ($pair[$field] === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * The params with the plain pair set to the active mode's pair.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function applyActive(array $params): array
    {
        $active = self::forMode($params, PaymentMode::fromMixed($params['mode'] ?? null));
        if (self::hasSplitFields($params)) {
            return array_merge($params, $active);
        }

        // Pre-split install: the plain pair already is the saved mode's, so
        // it is kept as it is; only a missing POS signature is filled in from
        // the key file names.
        if (trim(Arr::string($params, 'pos_signature')) === '' && $active['pos_signature'] !== '') {
            $params['pos_signature'] = $active['pos_signature'];
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function hasSplitFields(array $params): bool
    {
        foreach (PaymentMode::cases() as $mode) {
            foreach (self::FIELDS as $field) {
                if (array_key_exists($mode->value . '_' . $field, $params)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The value typed for a mode's field; for a pre-split install, the plain
     * value when the mode is the one that was saved.
     *
     * @param array<string, mixed> $params
     */
    private static function typed(array $params, PaymentMode $mode, string $field): string
    {
        $value = trim(Arr::string($params, $mode->value . '_' . $field));
        if ($value === '' && !self::hasSplitFields($params) && $mode === PaymentMode::fromMixed($params['mode'] ?? null)) {
            // Pre-split install: the plain value belongs to the saved mode.
            $value = trim(Arr::string($params, $field));
        }

        return $value;
    }
}
