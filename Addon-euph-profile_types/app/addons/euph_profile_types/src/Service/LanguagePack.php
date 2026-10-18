<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Service;

/**
 * The texts of the add-on's language packs (var/langs/<lang>/addons/*.po).
 *
 * CS-Cart reads them at install only, so a text changed or added by an update
 * of the add-on would never reach a store that already has it: the add-on
 * writes them again itself when the packs change
 * (fn_euph_profile_types_refresh_texts()).
 */
final class LanguagePack
{
    private const PREFIX = 'Languages::';

    /**
     * The language variables of a pack: name => text, from the entries whose
     * context is "Languages::<name>". An empty translation falls back to the
     * original text, as CS-Cart does.
     *
     * @return array<string, string>
     */
    public static function variables(string $po): array
    {
        $variables = [];
        $entry = [];
        $part = null;
        foreach (preg_split('/\R/', $po) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^(msgctxt|msgid|msgstr)\s+"(.*)"$/', $line, $match) === 1) {
                if ($match[1] === 'msgctxt') {
                    $variables = self::add($variables, $entry);
                    $entry = [];
                }
                $part = $match[1];
                $entry[$part] = stripcslashes($match[2]);
            } elseif ($part !== null && preg_match('/^"(.*)"$/', $line, $match) === 1) {
                $entry[$part] = ($entry[$part] ?? '') . stripcslashes($match[1]);
            } elseif ($line === '' || str_starts_with($line, '#')) {
                $part = null;
            }
        }

        return self::add($variables, $entry);
    }

    /**
     * The rows of ?:language_values for the store's languages: each language
     * its own pack, the texts it lacks from the default pack.
     *
     * @param array<string, array<string, string>> $packs language code => variables
     * @param list<string> $languages the store's language codes
     *
     * @return list<array{lang_code: string, name: string, value: string}>
     */
    public static function rows(array $packs, array $languages, string $defaultLanguage): array
    {
        $default = $packs[$defaultLanguage] ?? [];
        $rows = [];
        foreach ($languages as $language) {
            foreach (($packs[$language] ?? []) + $default as $name => $value) {
                $rows[] = ['lang_code' => $language, 'name' => $name, 'value' => $value];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, string> $entry
     *
     * @return array<string, string>
     */
    private static function add(array $variables, array $entry): array
    {
        $context = $entry['msgctxt'] ?? '';
        if (!str_starts_with($context, self::PREFIX) || !isset($entry['msgstr'])) {
            return $variables;
        }
        $name = substr($context, strlen(self::PREFIX));
        if ($name !== '') {
            $variables[$name] = $entry['msgstr'] !== '' ? $entry['msgstr'] : ($entry['msgid'] ?? '');
        }

        return $variables;
    }
}
