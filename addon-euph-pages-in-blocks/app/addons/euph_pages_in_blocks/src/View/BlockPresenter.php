<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\View;

use Tygh\Addons\EuphPagesInBlocks\BlockSettings;
use Tygh\Addons\EuphPagesInBlocks\Cast;
use Tygh\Addons\EuphPagesInBlocks\Enum\ContentMode;
use Tygh\Addons\EuphPagesInBlocks\Enum\ItemKind;
use Tygh\Addons\EuphPagesInBlocks\Enum\Layout;
use Tygh\Addons\EuphPagesInBlocks\Enum\PageType;
use Tygh\Addons\EuphPagesInBlocks\Excerpt;

/**
 * What a Page content template draws: the block's settings and one item per
 * page, with everything the markup needs worked out here, so that the
 * templates only print.
 *
 * Each item gets a DOM id built from the block's placement and the page
 * (euph-pib-<snapping>-p<page>): stable across visits, so a link to
 * #euph-pib-12-p5 opens that page's tab or section.
 */
final class BlockPresenter
{
    /**
     * Block DOM ids handed out in this request => how many times.
     *
     * @var array<string, int>
     */
    private array $blockIds = [];

    /**
     * @param \Closure(string): string $url CS-Cart's fn_url()
     */
    public function __construct(private readonly \Closure $url)
    {
    }

    /**
     * The view for a template's {$pib = fn_euph_pages_in_blocks_view($items, $block, "tabs")}:
     * the values as Smarty hands them over.
     *
     * @return array<string, mixed> empty when there is nothing to show
     */
    public function fromTemplate(mixed $items, mixed $block, mixed $layout): array
    {
        return $this->present(Cast::rows($items), Cast::map($block), Layout::tryFrom(Cast::string($layout)) ?? Layout::Stacked);
    }

    /**
     * @param list<array<string, mixed>> $pages the block's items, as fn_euph_pages_in_blocks_get_pages() returned them
     * @param array<string, mixed> $block the block as the block manager renders it
     *
     * @return array<string, mixed> empty when there is nothing to show
     */
    public function present(array $pages, array $block, Layout $layout): array
    {
        $settings = BlockSettings::fromProperties(Cast::map($block['properties'] ?? null));
        $excerpts = $layout->alwaysExcerpts() || ($layout === Layout::Stacked && $settings->content === ContentMode::Excerpt);

        $items = [];
        foreach ($pages as $page) {
            $item = $this->item($page, $excerpts ? $settings->excerptLength : 0);
            if ($item !== null && !isset($items[$item['page_id']])) {
                $items[$item['page_id']] = $item;
            }
        }
        if ($items === []) {
            return [];
        }

        $blockId = $this->blockId($block);
        $position = 0;
        foreach ($items as $pageId => $item) {
            $item['id'] = $blockId . '-p' . $pageId;
            $item['dialog_id'] = $item['kind'] === ItemKind::Popup->value ? $item['id'] . '-dialog' : '';
            $item['selected'] = $layout === Layout::Tabs && $position === 0;
            $item['open'] = $layout === Layout::Accordion && $settings->openFirst && $position === 0;
            $items[$pageId] = $item;
            ++$position;
        }

        return [
            'id' => $blockId,
            'layout' => $layout->value,
            'show_titles' => $settings->showTitles,
            'separators' => $settings->separators,
            'excerpts' => $excerpts,
            'one_open' => $settings->oneOpen,
            'columns' => $settings->columns,
            'items' => array_values($items),
        ];
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array{page_id: int, title: string, type: string, kind: string, url: string, new_window: bool, excerpt: string, extra_hook: bool, page: array<string, mixed>}|null
     */
    private function item(array $page, int $excerptLength): ?array
    {
        $pageId = Cast::int($page['page_id'] ?? 0);
        $title = trim(Cast::string($page['page'] ?? ''));
        // A page with no name in this language is not ready to be shown.
        if ($pageId <= 0 || $title === '') {
            return null;
        }

        $type = PageType::fromCode(Cast::string($page['page_type'] ?? ''));
        if ($type === PageType::Link) {
            $link = trim(Cast::string($page['link'] ?? ''));
            // The address is the administrator's; a script address is never
            // printed into the page.
            if ($link === '' || preg_match('/^\s*(?:javascript|vbscript|data):/i', $link) === 1) {
                return null;
            }
            $kind = ItemKind::Link;
            $url = ($this->url)($link);
        } else {
            $kind = Cast::flag($page['show_in_popup'] ?? 'N') ? ItemKind::Popup : ItemKind::Content;
            $url = ($this->url)('pages.view?page_id=' . $pageId);
        }

        return [
            'page_id' => $pageId,
            'title' => $title,
            'type' => $type->slug(),
            'kind' => $kind->value,
            'url' => $url,
            'new_window' => $kind !== ItemKind::Popup && Cast::flag($page['new_window'] ?? 0),
            'excerpt' => $excerptLength > 0 ? Excerpt::fromHtml(Cast::string($page['description'] ?? ''), $excerptLength) : '',
            'extra_hook' => $type->usesExtraHook(),
            'page' => $page,
        ];
    }

    /**
     * The DOM id of the block: by its placement (the same block can be
     * placed in several layouts), unique in the page even if the same
     * placement is drawn twice.
     *
     * @param array<string, mixed> $block
     */
    private function blockId(array $block): string
    {
        $snappingId = Cast::int($block['snapping_id'] ?? 0);
        $base = $snappingId > 0 ? 'euph-pib-' . $snappingId : 'euph-pib-b' . max(0, Cast::int($block['block_id'] ?? 0));
        $count = ($this->blockIds[$base] ?? 0) + 1;
        $this->blockIds[$base] = $count;

        return $count === 1 ? $base : $base . '-' . $count;
    }
}
