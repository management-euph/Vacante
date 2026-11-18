<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Install;

use Tygh\Addons\TravelCore\Support\PoFile;

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
 *
 * Its sources are the .po files (var/langs/<lang>/addons/novoton_holidays.po),
 * then lang_keys.php and addon.xml on top. It used to read only the last two,
 * while most labels live only in the .po files: CS-Cart imports
 * those at install, so every label added to a .po afterwards never reached
 * an upgraded store, which showed the raw "_novoton_holidays.…" key.
 */
final class LanguageSeeder
{
    /**
     * Every novoton language variable: the .po files, then lang_keys.php,
     * then addon.xml <language_variables>, each over the one before.
     *
     * @return array<string, array<string, string>> name => [lang_code => value]
     */
    public static function variables(): array
    {
        $vars = [];
        foreach (self::poFiles() as $langCode => $path) {
            foreach (PoFile::languages($path) as $name => $value) {
                $vars[$name][$langCode] = $value;
            }
        }

        $keysFile = self::addonRoot() . '/lang_keys.php';
        /** @var array<string, array<string, string>> $fromKeys */
        $fromKeys = is_file($keysFile) ? (array) require $keysFile : [];
        foreach ($fromKeys as $name => $translations) {
            foreach ($translations as $langCode => $value) {
                $vars[$name][$langCode] = $value;
            }
        }

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
     * Fingerprint of the language sources (stat-based: size + mtime): a
     * changed .po reseeds the store like a changed lang_keys.php.
     */
    public static function seedHash(): string
    {
        $parts = [];
        foreach ([self::addonRoot() . '/addon.xml', self::addonRoot() . '/lang_keys.php', ...array_values(self::poFiles())] as $file) {
            $stat = @stat($file);
            $parts[] = $file . '|' . (is_array($stat) ? $stat['size'] . '|' . $stat['mtime'] : 'absent');
        }

        return md5(implode(';', $parts));
    }

    /**
     * The add-on's .po files by language, under the store's var/langs.
     *
     * @return array<string, string> lang_code => path
     */
    public static function poFiles(): array
    {
        $files = [];
        foreach (glob(dirname(self::addonRoot(), 3) . '/var/langs/*/addons/novoton_holidays.po') ?: [] as $path) {
            $files[basename(dirname($path, 2))] = $path;
        }
        ksort($files);

        return $files;
    }

    private static function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
