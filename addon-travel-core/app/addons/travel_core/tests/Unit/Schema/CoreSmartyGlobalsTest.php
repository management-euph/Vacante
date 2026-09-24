<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * No add-on assigns a Smarty variable that CS-Cart's own page frame prints.
 *
 * The backend index.tpl ends every admin page with
 * {$stats|default:"" nofilter}. The Novoton dashboard assigned its hotel and
 * booking figures as 'stats' — an array — so every visit printed a literal
 * "Array" under the page. 'countries' is a core [code => name] map used on
 * the whole page; overwriting it shadows core data. This scans every add-on's
 * backend controllers for those names.
 */
final class CoreSmartyGlobalsTest extends TestCase
{
    /** Names CS-Cart's admin frame reads for itself. */
    private const array RESERVED = ['stats', 'countries', 'content', 'page_title', 'navigation'];

    public function testNoBackendControllerAssignsAReservedName(): void
    {
        $root = dirname(__DIR__, 7);
        $offenders = [];
        foreach (glob($root . '/*/app/addons/*/controllers/backend/*.php') ?: [] as $file) {
            $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*|#[^\n]*~s', '', (string) file_get_contents($file));
            foreach (self::RESERVED as $name) {
                if (preg_match('/->assign\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,/', $code) === 1) {
                    $offenders[] = substr($file, strlen($root) + 1) . " assigns '{$name}'";
                }
            }
        }

        self::assertSame([], $offenders, "CS-Cart's admin frame prints or reads these; use an add-on prefix:\n" . implode("\n", $offenders));
    }
}
