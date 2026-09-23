<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Install;

/**
 * Runtime language-key SOURCE for novoton_holidays; travel_core's
 * LanguageDelivery does the writing.
 *
 * A class, not the fn_novoton_holidays_language_* helpers in func.php, for one
 * reason: init.php must reach it no matter which of init.php / func.php
 * CS-Cart includes first. Older cores include init.php FIRST, and there the
 * heal called fn_novoton_holidays_language_seed_hash() before func.php had
 * defined it — the self-heal guard swallowed the Error on every request, and
 * no label added after install ever reached the store. fgo_invoicing
 * (54fbc36), eurosite and sphinx_holidays were converted to this shape for
 * the same reason.
 *
 * init.php registers the NovotonHolidays autoloader above the heal block, so
 * this resolves whether or not func.php has been loaded.
 */
final class LanguageSeeder
{
    /**
     * Every novoton language variable: lang_keys.php, then addon.xml
     * <language_variables>.
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
     * Fingerprint of the language sources (stat-based: size + mtime).
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
