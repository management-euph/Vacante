<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Guards template/asset mirrors against silent drift.
 *
 * Two mirror families exist:
 *  1. THEME mirrors — novoton and travel_core ship nova_theme copies of their
 *     responsive templates AND assets (css). Editing only the responsive copy
 *     silently ships a stale nova_theme page (exactly this had already
 *     happened to 4 novoton templates when this guard was written).
 *  2. AREA copies — a template needed in more than one area (storefront
 *     theme, admin backend, mail tree) must exist identically in each:
 *     Smarty resolves templates per area, an include across areas cannot
 *     resolve. None today (the manifest's area_copy_sets).
 *
 * The mirror SET lives in scripts/mirror-manifest.php — shared with the
 * `composer mirror` sync tool, so the tool and this guard can never
 * disagree. To fix a drift failure: run `composer mirror`. To intentionally
 * fork a nova_theme file, add it to the manifest's drift_allowlist with a
 * short reason.
 */
class ThemeMirrorTest extends TestCase
{
    /** @return array{theme_roots: list<string>, drift_allowlist: array<string, string>, area_copy_sets: list<list<string>>} */
    private static function manifest(): array
    {
        $manifest = require self::repoRoot() . '/scripts/mirror-manifest.php';
        self::assertIsArray($manifest);
        self::assertArrayHasKey('theme_roots', $manifest);
        self::assertArrayHasKey('drift_allowlist', $manifest);
        self::assertArrayHasKey('area_copy_sets', $manifest);

        /** @var array{theme_roots: list<string>, drift_allowlist: array<string, string>, area_copy_sets: list<list<string>>} $manifest */
        return $manifest;
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    public function testNovaThemeMirrorsMatchResponsive(): void
    {
        $manifest = self::manifest();
        $repoRoot = self::repoRoot();
        $drifted = [];
        $orphans = [];
        $checked = 0;

        foreach ($manifest['theme_roots'] as $themesRoot) {
            $novaDir = $repoRoot . '/' . $themesRoot . '/nova_theme';
            $responsiveDir = $repoRoot . '/' . $themesRoot . '/responsive';
            self::assertDirectoryExists($novaDir, "manifest theme root without a nova_theme tree: {$themesRoot}");

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($novaDir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $relative = ltrim(substr($file->getPathname(), strlen($novaDir)), '/');
                $checked++;

                if (isset($manifest['drift_allowlist'][$relative])) {
                    continue;
                }
                $responsiveTwin = $responsiveDir . '/' . $relative;
                if (!is_file($responsiveTwin)) {
                    $orphans[] = $themesRoot . '/nova_theme/' . $relative;
                    continue;
                }
                if (file_get_contents($file->getPathname()) !== file_get_contents($responsiveTwin)) {
                    $drifted[] = $themesRoot . '/nova_theme/' . $relative;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'no nova_theme mirrors found — layout changed?');
        self::assertSame([], $orphans, "nova_theme files with NO responsive counterpart (dead copies?):\n" . implode("\n", $orphans));
        self::assertSame(
            [],
            $drifted,
            "nova_theme mirrors drifted from responsive — run `composer mirror`, or "
            . "add to the manifest drift_allowlist with a reason:\n" . implode("\n", $drifted),
        );
    }

    public function testDriftAllowlistEntriesStillExistAndStillDiffer(): void
    {
        // A stale allowlist entry (file deleted, or re-synced) silently
        // shrinks the guard — prune it from the manifest when it resolves.
        $manifest = self::manifest();
        $repoRoot = self::repoRoot();

        foreach ($manifest['drift_allowlist'] as $relative => $reason) {
            self::assertNotSame('', $reason, "drift_allowlist entry needs a reason: {$relative}");
            $found = false;
            foreach ($manifest['theme_roots'] as $themesRoot) {
                $nova = $repoRoot . '/' . $themesRoot . '/nova_theme/' . $relative;
                $responsive = $repoRoot . '/' . $themesRoot . '/responsive/' . $relative;
                if (is_file($nova) && is_file($responsive)) {
                    $found = true;
                    self::assertNotSame(
                        (string) file_get_contents($responsive),
                        (string) file_get_contents($nova),
                        "allowlisted file no longer differs — remove it from drift_allowlist: {$relative}",
                    );
                }
            }
            self::assertTrue($found, "allowlisted file missing from every theme root — prune the entry: {$relative}");
        }
    }

    public function testAreaCopiesAreIdentical(): void
    {
        $manifest = self::manifest();
        $repoRoot = self::repoRoot();
        self::assertIsList($manifest['area_copy_sets']);

        foreach ($manifest['area_copy_sets'] as $set) {
            $reference = null;
            foreach ($set as $relPath) {
                $path = $repoRoot . '/' . $relPath;
                self::assertFileExists($path, "area copy missing: {$relPath}");
                $content = (string) file_get_contents($path);
                if ($reference === null) {
                    $reference = $content;
                    continue;
                }
                self::assertSame(
                    $reference,
                    $content,
                    "area copy drifted from its reference (first path in the set): {$relPath} — run `composer mirror`",
                );
            }
        }
    }

    public function testComposerMirrorScriptIsWired(): void
    {
        $repoRoot = self::repoRoot();

        self::assertFileExists($repoRoot . '/scripts/mirror-themes.php');
        self::assertFileExists($repoRoot . '/scripts/mirror-manifest.php');

        $composer = (string) file_get_contents($repoRoot . '/composer.json');
        self::assertStringContainsString(
            '"mirror": "@php scripts/mirror-themes.php"',
            $composer,
            'the composer "mirror" script must run scripts/mirror-themes.php',
        );

        // The sync tool consumes the same manifest this guard reads.
        $script = (string) file_get_contents($repoRoot . '/scripts/mirror-themes.php');
        self::assertStringContainsString("require __DIR__ . '/mirror-manifest.php'", $script);
    }
}
