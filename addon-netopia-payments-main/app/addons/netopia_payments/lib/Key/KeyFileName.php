<?php

declare(strict_types=1);

namespace Netopia\CsCart\Key;

use Netopia\Payment2\Enum\PaymentMode;

/**
 * What NETOPIA's key file names say about the key inside.
 *
 * NETOPIA names every key file after the mode and the POS signature of the
 * point of sale it belongs to:
 *
 *   sandbox.39EG-NK6H-N6LV-IVP3-SLJC.public.cer
 *   sandbox.39EG-NK6H-N6LV-IVP3-SLJCprivate.key
 *   sandbox.39EG-NK6H-N6LV-IVP3-SLJC.2048.public.txt
 *   live.XXXX-XXXX-XXXX-XXXX-XXXX.public.cer
 *
 * So the POS signature does not have to be typed once the key files are
 * uploaded, and a sandbox file dropped into a live slot (or the other way
 * round) is caught before it is stored: live payment notifications are
 * signed with the live key, so a sandbox key in the live slot means no live
 * order is ever confirmed.
 *
 * Only the name is read, never the file: a renamed file simply says nothing.
 */
final class KeyFileName
{
    /** A POS signature at the start of the name or right after a "." or "/". */
    private const string POS_SIGNATURE_PATTERN = '~(?:^|[./])([A-Z0-9]{4}(?:-[A-Z0-9]{4}){4})~i';

    /**
     * The POS signature the name carries, upper-case; '' when it carries none.
     */
    public static function posSignature(string $fileName): string
    {
        return preg_match(self::POS_SIGNATURE_PATTERN, $fileName, $match) === 1 ? strtoupper($match[1]) : '';
    }

    /**
     * The mode the name starts with ("sandbox." or "live."); null when it names none.
     */
    public static function mode(string $fileName): ?PaymentMode
    {
        $name = strtolower(basename(str_replace('\\', '/', trim($fileName))));
        foreach (PaymentMode::cases() as $mode) {
            if (str_starts_with($name, $mode->value . '.')) {
                return $mode;
            }
        }

        return null;
    }

    /**
     * The file's mode when it is NOT the slot's: a "sandbox." file in a live
     * slot, or a "live." file in a sandbox slot. Null when the file fits the
     * slot, or its name does not say which mode it is for.
     */
    public static function wrongMode(string $fileName, PaymentMode $slotMode): ?PaymentMode
    {
        $fileMode = self::mode($fileName);

        return $fileMode !== null && $fileMode !== $slotMode ? $fileMode : null;
    }
}
