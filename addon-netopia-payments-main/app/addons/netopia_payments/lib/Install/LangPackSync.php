<?php

declare(strict_types=1);

namespace Netopia\CsCart\Install;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Loads the addon's language variables (the `Languages::` entries of
 * var/langs/<lang>/addons/netopia_payments.po) into ?:language_values
 * when the .po files change.
 *
 * CS-Cart imports an addon's .po only at install, so texts added in a
 * `git pull` deploy show as raw `_netopia_*` keys until a reinstall. This
 * closes that gap: the files' fingerprint is compared with the one stored
 * at the last sync and, when it differs, every variable is written with
 * INSERT IGNORE: missing ones are added, and a text an admin edited under
 * Administration → Languages is never overwritten.
 *
 * A language the store has but the addon ships no .po for gets the English
 * texts, so it never shows raw keys either.
 */
final class LangPackSync
{
    public const string STAMP = 'netopia_payments_langs';

    public const string FALLBACK_LANG = 'en';

    /**
     * @param Closure(string, mixed...): mixed $dbQuery
     * @param Closure(): list<string> $installedLanguages
     * @param Closure(string): string $readStamp
     * @param Closure(string, string): void $writeStamp
     */
    public function __construct(
        private readonly string $langsDir,
        private readonly Closure $dbQuery,
        private readonly Closure $installedLanguages,
        private readonly Closure $readStamp,
        private readonly Closure $writeStamp,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The language variables of a .po file: name => text.
     *
     * @return array<string, string>
     */
    public static function parse(string $po): array
    {
        /** @var list<array{ctx: string, str: string}> $entries */
        $entries = [];
        $entry = ['ctx' => '', 'str' => ''];
        $field = '';

        foreach (preg_split('/\R/', $po) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^(msgctxt|msgid|msgstr)\s+"(.*)"$/', $line, $m) === 1) {
                if ($m[1] === 'msgctxt') {
                    $entries[] = $entry;
                    $entry = ['ctx' => '', 'str' => ''];
                }
                $field = $m[1];
                $line = '"' . $m[2] . '"';
            }
            // The keyword's own string and any continuation lines after it.
            if ($field !== '' && preg_match('/^"(.*)"$/', $line, $m) === 1) {
                if ($field === 'msgctxt') {
                    $entry['ctx'] .= stripcslashes($m[1]);
                } elseif ($field === 'msgstr') {
                    $entry['str'] .= stripcslashes($m[1]);
                }
            }
        }
        $entries[] = $entry;

        $vars = [];
        foreach ($entries as $e) {
            if (str_starts_with($e['ctx'], 'Languages::') && strlen($e['ctx']) > strlen('Languages::')) {
                $vars[substr($e['ctx'], strlen('Languages::'))] = $e['str'];
            }
        }

        return $vars;
    }

    /**
     * Runs the sync when the .po files changed since the last one. Never
     * throws: a failed sync is logged and retried on the next request.
     */
    public function syncIfChanged(): bool
    {
        try {
            $files = $this->files();
            if ($files === []) {
                return false;
            }
            $fingerprint = $this->fingerprint($files);
            if (($this->readStamp)(self::STAMP) === $fingerprint) {
                return false;
            }

            $this->apply($files);
            ($this->writeStamp)(self::STAMP, $fingerprint);

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('NETOPIA language sync skipped after error: ' . $e->getMessage(), [
                'exception_class' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * @param array<string, string> $files lang_code => path
     */
    private function apply(array $files): void
    {
        $packs = [];
        foreach ($files as $lang => $path) {
            $content = file_get_contents($path);
            $packs[$lang] = is_string($content) ? self::parse($content) : [];
        }
        $fallback = $packs[self::FALLBACK_LANG] ?? [];

        foreach (($this->installedLanguages)() as $lang) {
            $vars = $packs[$lang] ?? $fallback;
            if ($vars === []) {
                continue;
            }
            $rows = [];
            $params = [];
            foreach ($vars as $name => $value) {
                $rows[] = '(?s, ?s, ?s)';
                array_push($params, $lang, $name, $value);
            }
            ($this->dbQuery)(
                'INSERT IGNORE INTO ?:language_values (lang_code, name, value) VALUES ' . implode(', ', $rows),
                ...$params,
            );
        }
    }

    /**
     * @return array<string, string> lang_code => path, by lang_code
     */
    private function files(): array
    {
        $files = [];
        foreach (glob(rtrim($this->langsDir, '/') . '/*/addons/netopia_payments.po') ?: [] as $path) {
            $lang = basename(dirname($path, 2));
            if (preg_match('/^[a-z]{2}$/', $lang) === 1 && is_readable($path)) {
                $files[$lang] = $path;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @param array<string, string> $files
     */
    private function fingerprint(array $files): string
    {
        $parts = [];
        foreach ($files as $lang => $path) {
            $parts[] = $lang . ':' . (string) @md5_file($path);
        }
        // A newly installed store language needs its rows too.
        $parts[] = 'langs:' . implode(',', ($this->installedLanguages)());

        return md5(implode('|', $parts));
    }
}
