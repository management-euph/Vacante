<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Support;

use Smarty\BlockHandler\Base as BlockHandler;
use Smarty\Extension\Base as Extension;
use Smarty\FunctionHandler\Base as FunctionHandler;
use Smarty\Smarty;
use Smarty\Template;
use Tygh\Addons\EuphPagesInBlocks\View\BlockPresenter;

/**
 * Compiles and renders the add-on's templates with Smarty 5 (the engine of
 * CS-Cart 4.19 and later), with what CS-Cart adds to it emulated:
 *
 *  - auto-escaped output ({$var}; {$var nofilter} prints raw HTML), as
 *    CS-Cart sets escape_html;
 *  - {hook name="..."}...{/hook}: its content, unless an "add-on" of the
 *    test overrides it ($harness->overrides[name]), as an .override.tpl
 *    hook template does; every hook drawn is recorded;
 *  - {script src=...} and {style src=...};
 *  - __("name", [params]) from the English language pack, fn_url, and the
 *    add-on's fn_euph_pages_in_blocks_view() with a presenter of its own.
 */
final class TemplateHarness
{
    /** @var array<string, \Closure(Template): string> hook name => the "add-on" template drawn for it */
    public array $overrides = [];

    /** @var list<string> the hooks drawn, in order */
    public array $hooks = [];

    private readonly Smarty $smarty;

    public function __construct(string $area = 'storefront')
    {
        $design = dirname(__DIR__, 5) . '/design';
        $compileDir = sys_get_temp_dir() . '/euph_pib_smarty_' . getmypid() . '_' . $area;

        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir($area === 'backend' ? $design . '/backend/templates' : $design . '/themes/responsive/templates');
        $this->smarty->setCompileDir($compileDir);
        $this->smarty->setEscapeHtml(true);
        $this->smarty->setErrorReporting(E_ALL);
        $this->smarty->addExtension(new CsCartExtension($this, new BlockPresenter(static fn (string $url): string => CsCart::url($url))));
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function render(string $template, array $vars = []): string
    {
        $this->hooks = [];
        $data = $this->smarty->createData();
        foreach ($vars as $name => $value) {
            $data->assign($name, $value);
        }

        return $this->smarty->fetch($template, $data);
    }

    /** Compiles a template without drawing it: a syntax error throws. */
    public function compile(string $template): void
    {
        $tpl = $this->smarty->createTemplate($template);
        $tpl->compileTemplateSource();
    }

    /**
     * A Page content block's template drawn with its items and block.
     *
     * @param list<array<string, mixed>> $pages
     * @param array<string, mixed> $block
     */
    public function block(string $layout, array $pages, array $block): string
    {
        return $this->render('addons/euph_pages_in_blocks/blocks/page_content/' . $layout . '.tpl', ['items' => $pages, 'block' => $block]);
    }
}

/**
 * @internal the CS-Cart tags of TemplateHarness
 */
final class CsCartExtension extends Extension
{
    public function __construct(private readonly TemplateHarness $harness, private readonly BlockPresenter $presenter)
    {
    }

    #[\Override]
    public function getBlockHandler(string $blockTagName): ?\Smarty\BlockHandler\BlockHandlerInterface
    {
        return $blockTagName === 'hook' ? new HookBlock($this->harness) : null;
    }

    #[\Override]
    public function getFunctionHandler(string $functionName): ?\Smarty\FunctionHandler\FunctionHandlerInterface
    {
        return in_array($functionName, ['script', 'style'], true) ? new AssetFunction($functionName) : null;
    }

    #[\Override]
    public function getModifierCallback(string $modifierName)
    {
        return match ($modifierName) {
            '__' => static fn (string $name, array $params = []): string => LanguagePack::english()->translate($name, $params),
            'fn_url' => static fn (string $url): string => CsCart::url($url),
            'fn_euph_pages_in_blocks_view' => fn (mixed $items, mixed $block, mixed $layout = 'stacked'): array => $this->presenter->fromTemplate($items, $block, $layout),
            default => null,
        };
    }
}

/**
 * @internal {hook name="..."}...{/hook}
 */
final class HookBlock extends BlockHandler
{
    public function __construct(private readonly TemplateHarness $harness)
    {
    }

    #[\Override]
    public function handle($params, $content, Template $template, &$repeat)
    {
        $name = is_array($params) && is_string($params['name'] ?? null) ? $params['name'] : '';
        if ($content === null) {
            $this->harness->hooks[] = $name;

            return '';
        }
        $override = isset($this->harness->overrides[$name]) ? ($this->harness->overrides[$name])($template) : '';

        // As CS-Cart does: an override that prints nothing keeps the content.
        return trim($override) !== '' ? $override : (string) $content;
    }
}

/**
 * @internal {script src=...} and {style src=...}
 */
final class AssetFunction extends FunctionHandler
{
    public function __construct(private readonly string $tag)
    {
    }

    #[\Override]
    public function handle($params, Template $template)
    {
        $src = is_array($params) && is_string($params['src'] ?? null) ? $params['src'] : '';

        return $this->tag === 'script' ? '<script src="' . $src . '"></script>' : '<link rel="stylesheet" href="' . $src . '">';
    }
}
