<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Every {script src=} / {style src=} an addon template asks for must exist.
 *
 * A 404 on a page script is the quietest failure in this repo: the page renders
 * completely, nothing logs, and only the behaviour is gone. The eurosite
 * destination whitelist shipped that way — its script sat in
 * design/backend/js/ while the template asked for "js/…" — so the editor had no
 * search, no expand, and a Save button that did nothing. Nobody could tell from
 * looking at the page.
 *
 * The two prefixes mean different directories, which is the whole trap:
 *
 *   src="js/addons/<id>/x.js"  → the DOCROOT /js/ tree
 *                                = <addon-root>/js/addons/<id>/x.js
 *   src="addons/<id>/x.js"     → the js/ or css/ dir of the AREA the template
 *                                itself lives in, i.e. design/backend/… for a
 *                                backend template and design/themes/<theme>/…
 *                                for a storefront one.
 *
 * All of these are in use here (novoton's func.js takes the first form,
 * travel_core's seo-click-insert.js and every theme stylesheet the second), and
 * all are deployed by docker/fullstore/link-addons.sh and
 * scripts/package-addons.php — so either is fine as long as the file is where
 * the template says.
 *
 * Only paths naming one of our own addons are checked. CS-Cart's own assets
 * (js/lib/…, js/tygh/…) live in the licensed kit, which is not in this
 * repository, so this test cannot say anything about them.
 */
final class AddonScriptPathsTest extends TestCase
{
    /** addon id => repo directory */
    private const ADDONS = [
        'travel_core' => 'addon-travel-core',
        'novoton_holidays' => 'addon-novoton-holidays',
        'sphinx_holidays' => 'addon-sphinx-holidays',
        'fgo_invoicing' => 'addon-fgo-invoicing',
        'eurosite' => 'eurosite_addon',
    ];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    /**
     * Every (template, src) pair found in the addons' templates.
     *
     * @return list<array{file: string, src: string}>
     */
    private static function assetReferences(): array
    {
        $out = [];
        foreach (self::ADDONS as $dir) {
            $base = self::repoRoot() . '/' . $dir . '/design';
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
            foreach ($it as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'tpl') {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                // A reference inside a {* … *} comment is not a reference.
                $src = (string) preg_replace('/\{\*.*?\*\}/s', '', $src);
                if (preg_match_all('/\{(?:script|style)\s+src="([^"{}]+)"/', $src, $m)) {
                    foreach ($m[1] as $ref) {
                        $out[] = [
                            'file' => str_replace(self::repoRoot() . '/', '', $file->getPathname()),
                            'src' => $ref,
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Where a src SHOULD be on disk, given the template that asks for it.
     *
     * @return list<string> candidate repo-relative paths (any one may exist)
     */
    private static function candidates(string $src, string $file): array
    {
        foreach (self::ADDONS as $id => $dir) {
            // Docroot form: "js/addons/<id>/…" — ships from the addon that owns
            // the id, whichever template asks for it.
            if (str_starts_with($src, 'js/addons/' . $id . '/')) {
                return [$dir . '/' . $src];
            }

            // Area-relative form: "addons/<id>/…" resolves under the js/ or
            // css/ directory of the area this template belongs to. Every addon
            // merges into ONE docroot, so a backend page may legitimately load
            // a sibling addon's asset (the SEO editor pages load travel_core's
            // seo-click-insert.js) — accept the file from any addon that ships
            // it at the same area-relative path.
            if (str_starts_with($src, 'addons/' . $id . '/')) {
                $area = self::areaSuffix($file);
                if ($area === null) {
                    return [];
                }
                $out = [];
                foreach (self::ADDONS as $candidateDir) {
                    $out[] = $candidateDir . '/' . $area . '/js/' . $src;
                    $out[] = $candidateDir . '/' . $area . '/css/' . $src;
                }

                return $out;
            }
        }

        return [];
    }

    /**
     * The design area a template lives in, relative to its addon root:
     * "design/backend" or "design/themes/<theme>".
     */
    private static function areaSuffix(string $file): ?string
    {
        if (str_contains($file, '/design/backend/')) {
            return 'design/backend';
        }
        if (preg_match('#/design/themes/([^/]+)/#', $file, $m) === 1) {
            return 'design/themes/' . $m[1];
        }

        return null;
    }

    public function testEveryAddonOwnedScriptAndStyleExists(): void
    {
        $refs = self::assetReferences();
        self::assertNotEmpty($refs, 'no {script src=} found at all — the scan itself is broken');

        $checked = 0;
        $missing = [];
        foreach ($refs as $ref) {
            $candidates = self::candidates($ref['src'], $ref['file']);
            if ($candidates === []) {
                continue; // CS-Cart core asset — not ours to verify
            }
            $checked++;
            $found = false;
            foreach ($candidates as $candidate) {
                if (is_file(self::repoRoot() . '/' . $candidate)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = sprintf(
                    '%s asks for "%s" — expected at %s',
                    $ref['file'],
                    $ref['src'],
                    implode(' or ', $candidates),
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'no addon-owned asset references were checked');
        self::assertSame(
            [],
            $missing,
            "Template asset reference(s) point at files that do not ship:\n  " . implode("\n  ", $missing),
        );
    }

    /**
     * The docroot form is the one that must NOT be used for a file that only
     * exists under design/backend/js — that is the exact eurosite mix-up, and
     * the check above would catch it, but naming it separately makes the
     * failure message say what to do.
     */
    public function testNoTemplateUsesTheDocrootPrefixForABackendOnlyFile(): void
    {
        $wrong = [];
        foreach (self::assetReferences() as $ref) {
            foreach (self::ADDONS as $id => $dir) {
                if (!str_starts_with($ref['src'], 'js/addons/' . $id . '/')) {
                    continue;
                }
                $tail = substr($ref['src'], strlen('js/'));            // addons/<id>/x.js
                $area = self::areaSuffix($ref['file']);
                $docroot = self::repoRoot() . '/' . $dir . '/' . $ref['src'];
                $areaFile = $area === null ? '' : self::repoRoot() . '/' . $dir . '/' . $area . '/js/' . $tail;
                if (!is_file($docroot) && $areaFile !== '' && is_file($areaFile)) {
                    $wrong[] = sprintf(
                        '%s: "%s" resolves to the docroot /js/ tree, but the file only exists under '
                        . 'this template\'s own area js/ dir — drop the "js/" prefix or move the file',
                        $ref['file'],
                        $ref['src'],
                    );
                }
            }
        }

        self::assertSame([], $wrong, implode("\n", $wrong));
    }
}
