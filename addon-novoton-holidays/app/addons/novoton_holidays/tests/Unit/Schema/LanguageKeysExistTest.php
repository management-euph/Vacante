<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Support\PoFile;

/**
 * Every novoton_holidays.* label the code asks for by name is in the .po
 * files, the source the store's labels come from (CS-Cart's import at
 * install, LanguageSeeder afterwards). A label removed from them while still
 * used would show the raw "_novoton_holidays.…" key on a new store.
 */
final class LanguageKeysExistTest extends TestCase
{
    public function testEveryLabelTheCodeAsksForExists(): void
    {
        $repo = dirname(__DIR__, 7);
        $known = PoFile::languages($repo . '/addon-novoton-holidays/var/langs/en/addons/novoton_holidays.po');
        $call = '/(?:__\(\s*|\btr\(\s*|\$t\(\s*|translate\)\(\s*)[\'"](novoton_holidays\.[a-z0-9_.]+)[\'"]/';

        $missing = [];
        foreach (['addon-novoton-holidays', 'addon-travel-core', 'addon-sphinx-holidays', 'addon-eurosite'] as $addon) {
            $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($repo . '/' . $addon, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !in_array($file->getFilename(), ['vendor', 'node_modules', 'tests'], true),
            ));
            foreach ($files as $file) {
                $path = (string) $file;
                if (preg_match('/\.(php|tpl|js|jsx)$/', $path) !== 1 || str_contains($path, 'react19-bundle')) {
                    continue;
                }
                preg_match_all($call, (string) file_get_contents($path), $m);
                foreach ($m[1] as $key) {
                    // A name the code completes at run time (…hotels_status_ . $status).
                    if (!str_ends_with($key, '_') && !isset($known[$key])) {
                        $missing[$key] = substr($path, strlen($repo) + 1);
                    }
                }
            }
        }

        self::assertSame([], $missing);
    }
}
