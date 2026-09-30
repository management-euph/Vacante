<?php

declare(strict_types=1);

namespace Netopia\CsCart\Key;

use Netopia\CsCart\Support\Arr;
use Netopia\Payment2\Enum\PaymentMode;

/**
 * Puts key files back in the slot of the mode their name is for.
 *
 * Uploads are checked since KeyFileName was added, but a sandbox.* file
 * stored in a live slot before that (or the other way round) left the
 * sandbox slots empty: sandbox checkout found no POS signature and sandbox
 * notifications no public key, although the right files were uploaded.
 *
 * A misplaced file (with its stored PEM) moves to its own mode's slot of the
 * same type when that slot is empty; an occupied slot is never overwritten,
 * the key card keeps warning about the misplaced file instead.
 *
 * Pure: works on processor params only; the files stay where they are
 * (all slots share the payment's key directory, so only the slot changes).
 */
final class KeySlots
{
    private const array TYPES = ['public_key', 'private_key'];

    /**
     * @param array<string, mixed> $params processor params
     * @return array<string, mixed> the params with misplaced key files moved
     */
    public static function relocate(array $params): array
    {
        foreach (self::moves($params) as $move) {
            $params[$move['to'] . '_file'] = $params[$move['from'] . '_file'];
            $params[$move['to']] = $params[$move['from']] ?? '';
            $params[$move['from'] . '_file'] = '';
            $params[$move['from']] = '';
        }

        return $params;
    }

    /**
     * The slot moves relocate() makes: from slot => to slot (e.g.
     * live_public_key => sandbox_public_key), with the file name.
     *
     * @param array<string, mixed> $params
     * @return list<array{from: string, to: string, file: string}>
     */
    public static function moves(array $params): array
    {
        $moves = [];
        $taken = [];
        foreach (PaymentMode::cases() as $slotMode) {
            foreach (self::TYPES as $type) {
                $from = $slotMode->value . '_' . $type;
                $file = trim(Arr::string($params, $from . '_file'));
                $fileMode = $file !== '' ? KeyFileName::wrongMode($file, $slotMode) : null;
                if ($fileMode === null) {
                    continue;
                }
                $to = $fileMode->value . '_' . $type;
                $free = trim(Arr::string($params, $to . '_file')) === '' && trim(Arr::string($params, $to)) === '';
                if ($free && !isset($taken[$to])) {
                    $taken[$to] = true;
                    $moves[] = ['from' => $from, 'to' => $to, 'file' => $file];
                }
            }
        }

        return $moves;
    }
}
