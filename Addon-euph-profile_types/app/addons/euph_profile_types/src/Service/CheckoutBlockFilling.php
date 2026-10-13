<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

use Tygh\Addons\EuphProfileTypes\Cast;

/**
 * The "By profile type" filling of the checkout customer blocks.
 *
 * Each customer block of the checkout (contact information, shipping and
 * billing address) normally lists the profile fields picked in its settings.
 * With the add-on on, the blocks are switched to the add-on's filling: they
 * show the fields of the customer's type. The filling the block had is kept
 * in its content, so disabling the add-on gives it back, and enabling it
 * again switches the block back.
 */
final class CheckoutBlockFilling
{
    public const FILLING = 'euph_profile_type';
    public const PREVIOUS = 'euph_previous_filling';

    /** Checkout blocks the add-on fills => the profile field section each one shows. */
    public const BLOCKS = [
        'lite_checkout_customer_information' => 'C',
        'lite_checkout_customer_address' => 'S',
        'lite_checkout_customer_billing' => 'B',
    ];

    /**
     * @param array<array-key, mixed> $content a block's content
     *
     * @return array<array-key, mixed>|null the switched content, null when there is nothing to switch
     */
    public static function switchOn(array $content): ?array
    {
        $items = $content['items'] ?? null;
        if (!is_array($items)) {
            return null;
        }
        $filling = Cast::string($items['filling'] ?? '');
        if ($filling === self::FILLING) {
            return null;
        }
        $items[self::PREVIOUS] = $filling;
        $items['filling'] = self::FILLING;
        $content['items'] = $items;

        return $content;
    }

    /**
     * @param array<array-key, mixed> $content a block's content
     *
     * @return array<array-key, mixed>|null the restored content, null when the block is not switched
     */
    public static function switchOff(array $content): ?array
    {
        $items = $content['items'] ?? null;
        if (!is_array($items) || Cast::string($items['filling'] ?? '') !== self::FILLING) {
            return null;
        }
        $previous = Cast::string($items[self::PREVIOUS] ?? '');
        unset($items[self::PREVIOUS]);
        if ($previous !== '') {
            $items['filling'] = $previous;
        } else {
            unset($items['filling']);
        }
        $content['items'] = $items;

        return $content;
    }

    /**
     * Block content is stored serialized; JSON is read too.
     *
     * @return array<array-key, mixed>|null
     */
    public static function decode(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^a:\d+:\{/', $raw) === 1) {
            $value = @unserialize($raw, ['allowed_classes' => false]);

            return is_array($value) ? $value : null;
        }
        $value = json_decode($raw, true);

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $content
     */
    public static function encode(array $content, string $likeRaw): string
    {
        if (preg_match('/^a:\d+:\{/', $likeRaw) === 1) {
            return serialize($content);
        }
        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : serialize($content);
    }
}
