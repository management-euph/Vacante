<?php

declare(strict_types=1);

/**
 * composer templates — compile every add-on template with Smarty 5, the
 * engine CS-Cart 4.19.1+ ships, the way the store compiles a page the first
 * time it shows it. Compile only: nothing is rendered.
 *
 * Why: a template Smarty 5 refuses to compile takes its whole page down, and
 * string tests on the source cannot see what the compiler refuses. Example:
 * an {include} passing scope="-…" (a group's id suffix) killed the Design A
 * hotel page with several rooms — Smarty 5 keeps "scope" for a variable
 * scope and throws "illegal value … for "scope" attribute".
 *
 * What CS-Cart adds to Smarty is not here, so it is allowed by name:
 *  - its tags: the blocks and functions in CS_CART_BLOCKS / CS_CART_FUNCTIONS
 *    (a tag Smarty does not know and these lists do not name fails, as a
 *    typo like {forech} should);
 *  - its modifiers and functions in expressions ({$x|fn_url}, {__("key")}):
 *    CS-Cart resolves them as PHP functions at run time, so any fn_* name,
 *    __ and any PHP function compiles. Other names fail (a custom modifier
 *    is a compile-time fatal in CS-Cart too: SmartyCompatTest).
 * CS-Cart's prefilters do not run, so this proves what Smarty itself
 * compiles, not everything CS-Cart does on top.
 *
 * Usage: php scripts/compile-templates.php [path …]
 *   no path: every .tpl under each add-on's design/ (and mail templates);
 *   paths: those template files or directories only.
 * Exit 1 when a template does not compile.
 */

use Smarty\BlockHandler\BlockHandlerInterface;
use Smarty\Extension\Base;
use Smarty\FunctionHandler\FunctionHandlerInterface;
use Smarty\Smarty;
use Smarty\Template;

$repoRoot = dirname(__DIR__);
require $repoRoot . '/vendor/autoload.php';

/** CS-Cart's block tags ({hook name="…"}…{/hook}). */
const CS_CART_BLOCKS = ['hook', 'inline_script', 'component', 'notags'];

/** CS-Cart's function tags ({script src="…"}). */
const CS_CART_FUNCTIONS = ['script', 'style', 'include_ext', 'btn', 'dropdown', 'live_edit', 'render_block', 'render_location', 'set_id', 'split'];

/** Accepts what CS-Cart adds to Smarty, by name; renders nothing (compile only). */
final class CsCartPluginsByName extends Base
{
    public function getFunctionHandler(string $functionName): ?FunctionHandlerInterface
    {
        if (!in_array($functionName, CS_CART_FUNCTIONS, true)) {
            return null;
        }

        return new class implements FunctionHandlerInterface {
            public function handle($params, Template $template): string
            {
                return '';
            }

            public function isCacheable(): bool
            {
                return true;
            }
        };
    }

    public function getBlockHandler(string $blockTagName): ?BlockHandlerInterface
    {
        if (!in_array($blockTagName, CS_CART_BLOCKS, true)) {
            return null;
        }

        return new class implements BlockHandlerInterface {
            public function handle($params, $content, Template $template, &$repeat): string
            {
                return (string) $content;
            }

            public function isCacheable(): bool
            {
                return true;
            }
        };
    }

    public function getModifierCallback(string $modifierName): ?callable
    {
        if ($modifierName === '__' || str_starts_with($modifierName, 'fn_') || function_exists($modifierName)) {
            return static fn (mixed ...$args): string => '';
        }

        return null;
    }
}

/**
 * Every template under the given paths; no path: every add-on's design/.
 *
 * @param list<string> $paths
 * @return list<string> absolute paths, sorted
 */
function templates(string $repoRoot, array $paths): array
{
    if ($paths === []) {
        $paths = glob($repoRoot . '/*/design', GLOB_ONLYDIR) ?: [];
    }
    $found = [];
    foreach ($paths as $path) {
        $path = (string) realpath($path) ?: $path;
        if (is_file($path)) {
            $found[] = $path;
            continue;
        }
        if (!is_dir($path)) {
            fwrite(STDERR, "No such template or directory: {$path}\n");
            exit(2);
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'tpl') {
                $found[] = $file->getPathname();
            }
        }
    }
    sort($found);

    return array_values(array_unique($found));
}

$compileDir = sys_get_temp_dir() . '/vacante-smarty-' . getmypid();
$smarty = new Smarty();
$smarty->setCompileDir($compileDir);
$smarty->addExtension(new CsCartPluginsByName());

$failed = [];
$templates = templates($repoRoot, array_values(array_slice($argv, 1)));
foreach ($templates as $path) {
    try {
        $smarty->createTemplate('file:' . $path)->compileTemplateSource();
    } catch (Throwable $e) {
        // paths from the repo root: the run's own location tells nothing
        $failed[] = str_replace($repoRoot . '/', '', sprintf("%s\n    %s", $path, strtok($e->getMessage(), "\n")));
    }
}

// the compiled copies are of no use after the run (none when nothing compiled)
if (is_dir($compileDir)) {
    $compiled = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($compileDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($compiled as $file) {
        $file instanceof SplFileInfo && $file->isDir() ? rmdir($file->getPathname()) : unlink((string) $file);
    }
    rmdir($compileDir);
}

foreach ($failed as $failure) {
    echo "FAIL {$failure}\n";
}
printf("Smarty %s: %d templates compiled, %d failed\n", Smarty::SMARTY_VERSION, count($templates) - count($failed), count($failed));
exit($failed === [] ? 0 : 1);
