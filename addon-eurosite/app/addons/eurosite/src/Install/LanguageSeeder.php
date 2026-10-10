<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Install;

/**
 * Runtime language-key source for eurosite.
 *
 * CS-Cart imports var/langs/{en,ro}/addons/eurosite.po exactly once, at
 * install. Every label added afterwards is invisible to a store that is
 * already installed, and `__()` renders the raw key — which is what the
 * whitelist editor shipped as: "_eurosite.countries", "_eurosite.save_whitelist"
 * and friends, for the labels that were added with that page. The `[default]`
 * argument in the templates does not save it either: a missing variable comes
 * back as '_' . $key, which is a non-empty string, so no fallback fires.
 *
 * The delivery itself belongs to travel_core (LanguageDelivery, via
 * fn_travel_core_heal_language_keys) — this class is only the source, and it
 * exists as a CLASS rather than as the fn_eurosite_* helpers in func.php for
 * one reason: init.php must be able to reach it no matter which of func.php /
 * init.php CS-Cart includes first. That order is not stable across cores, and
 * a `function_exists('fn_eurosite_language_variables')` guard in init.php is
 * simply false on a core that loads init.php first — silently disabling the
 * heal on every request, with no error anywhere. fgo_invoicing hit exactly
 * that and was converted to this shape; eurosite was the last one left.
 *
 * The PSR-4 autoloader is registered in init.php above the heal block, so this
 * class resolves whether or not func.php has been loaded.
 */
final class LanguageSeeder
{
    /**
     * Force a full seed, bypassing the init.php probe's stamp.
     *
     * Used by dev/tools/seed-langs.php `force`, which is how an operator
     * repairs a store whose labels never landed without waiting for a source
     * file to change.
     */
    public static function seed(): void
    {
        if (!class_exists(\Tygh\Addons\TravelCore\Install\LanguageDelivery::class)) {
            return; // travel_core absent: nothing to deliver with
        }

        \Tygh\Addons\TravelCore\Install\LanguageDelivery::seed(
            'eurosite._lang_seed_hash',
            self::variables(),
            self::seedHash(),
        );
    }

    /**
     * Every eurosite language variable, keyed by name.
     *
     * @return array<string, array<string, string>> name => [lang_code => value]
     */
    public static function variables(): array
    {
        $keysFile = self::addonRoot() . '/lang_keys.php';
        /** @var array<string, array<string, string>> $vars */
        $vars = is_file($keysFile) ? (array) require $keysFile : [];

        return $vars;
    }

    /**
     * Fingerprint of the language sources — the self-heal seed stamp.
     *
     * stat-based (size + mtime), not a content hash: the init.php probe runs
     * it on every request. Real edits always touch size or mtime; a deploy
     * that resets mtimes merely re-arms one idempotent reseed.
     */
    public static function seedHash(): string
    {
        $parts = [];
        foreach ([self::addonRoot() . '/addon.xml', self::addonRoot() . '/lang_keys.php'] as $file) {
            $stat = @stat($file);
            $parts[] = $file . '|' . (is_array($stat) ? $stat['size'] . '|' . $stat['mtime'] : 'absent');
        }

        return md5(implode(';', $parts));
    }

    private static function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
