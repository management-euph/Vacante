<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Install;

/**
 * Runtime language-key SOURCE for travel_core: what the labels are, and the
 * fingerprint that says when they changed. LanguageDelivery does the writing.
 *
 * WHY THIS IS A CLASS. init.php's language heal used to reach these through
 * fn_travel_core_language_variables() / fn_travel_core_language_seed_hash(),
 * both defined in func.php — a file init.php never loads. Whether they exist
 * when init.php runs depends on the order CS-Cart includes an addon's files,
 * and older cores include init.php FIRST. There the heal called an undefined
 * function, the self-heal guard swallowed the Error, and it happened on every
 * request: no label added after install ever reached the store.
 *
 * That is exactly what the Tools page showed after the cron-key move —
 * "_travel_core.tools_cron_key_title" and friends, rendered raw — while every
 * label that existed at install time rendered fine. fgo_invoicing (54fbc36)
 * and eurosite hit the same thing and were converted to this shape; this
 * closes it for travel_core.
 *
 * A class resolves no matter which file loaded first, because init.php
 * registers the TravelCore PSR-4 autoloader before the heal block. It has no
 * dependencies of its own, so func.php can also require it directly at install
 * time, when no autoloader exists.
 */
final class LanguageSeeder
{
    /**
     * Every travel_core language variable, from both sources, merged.
     *
     * lang_keys.php first, then addon.xml <language_variables> — the same
     * union and precedence fn_travel_core_language_variables() always had.
     *
     * @return array<string, array<string, string>> name => [lang_code => value]
     */
    public static function variables(): array
    {
        $keysFile = self::addonRoot() . '/lang_keys.php';
        /** @var array<string, array<string, string>> $vars */
        $vars = is_file($keysFile) ? (array) require $keysFile : [];

        $xml = @simplexml_load_file(self::addonRoot() . '/addon.xml');
        if ($xml !== false && isset($xml->language_variables)) {
            foreach ($xml->language_variables->item as $item) {
                $name = (string) $item['id'];
                $langCode = (string) $item['lang'];
                if ($name === '' || $langCode === '') {
                    continue;
                }
                $vars[$name][$langCode] = (string) $item;
            }
        }

        return $vars;
    }

    /**
     * Fingerprint of the language sources — the value stamped onto every
     * installed language once its labels are verified in the database.
     *
     * stat-based (size + mtime), not a content hash: addon.xml alone is
     * hundreds of KB and this runs on every request. It only has to answer
     * "did the sources change?"; whether the ROWS are present is a separate
     * question, and LanguageDelivery::isCurrent() asks the database directly.
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
