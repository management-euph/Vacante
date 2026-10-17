<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Support;

/**
 * A language pack of the add-on (var/langs/<lang>/addons/euph_pages_in_blocks.po),
 * read as CS-Cart reads it: msgctxt => msgstr.
 */
final class LanguagePack
{
    public const ADDON = 'euph_pages_in_blocks';

    /** @var array<string, self> */
    private static array $loaded = [];

    /**
     * @param array<string, string> $entries msgctxt => msgstr
     * @param array<string, string> $sources msgctxt => msgid
     */
    private function __construct(public readonly array $entries, public readonly array $sources)
    {
    }

    public static function english(): self
    {
        return self::load('en');
    }

    public static function load(string $lang): self
    {
        if (!isset(self::$loaded[$lang])) {
            $file = self::file($lang);
            $source = is_file($file) ? (string) file_get_contents($file) : '';
            preg_match_all('/^msgctxt "((?:[^"\\\\]|\\\\.)*)"\nmsgid "((?:[^"\\\\]|\\\\.)*)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"$/m', $source, $matches, PREG_SET_ORDER);
            $entries = [];
            $sources = [];
            foreach ($matches as $match) {
                if (isset($entries[$match[1]])) {
                    throw new \LogicException("duplicate {$match[1]} in {$lang}");
                }
                $entries[$match[1]] = stripcslashes($match[3]);
                $sources[$match[1]] = stripcslashes($match[2]);
            }
            self::$loaded[$lang] = new self($entries, $sources);
        }

        return self::$loaded[$lang];
    }

    public static function file(string $lang): string
    {
        return dirname(__DIR__, 5) . '/var/langs/' . $lang . '/addons/' . self::ADDON . '.po';
    }

    /**
     * A language variable as __() gives it: the text with its placeholders
     * replaced; the name, like CS-Cart's "_name", when it is missing.
     *
     * @param array<string, mixed> $params
     */
    public function translate(string $name, array $params = []): string
    {
        $text = $this->entries['Languages::' . $name] ?? '_' . $name;
        foreach ($params as $placeholder => $value) {
            $text = str_replace($placeholder, is_scalar($value) ? (string) $value : '', $text);
        }

        return $text;
    }

    /**
     * @return array<string, string> name => text of the Languages:: entries
     */
    public function languageVariables(): array
    {
        $variables = [];
        foreach ($this->entries as $context => $text) {
            if (str_starts_with($context, 'Languages::')) {
                $variables[substr($context, strlen('Languages::'))] = $text;
            }
        }

        return $variables;
    }
}
