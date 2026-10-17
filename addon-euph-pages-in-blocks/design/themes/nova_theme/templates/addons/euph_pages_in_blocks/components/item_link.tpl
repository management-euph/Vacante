{*
    A link to a page of a Page content block: to a link page's address (in
    a new window when the page says so), to a popup page in CS-Cart's dialog
    (cm-dialog-opener loads the page into the div #item.dialog_id the
    layout draws), or to the page's own view.

    Params: item (an item of fn_euph_pages_in_blocks_view()), class, text
    (empty: the page's name), label (the link's accessible name; empty: its
    text).
*}
{$class = $class|default:""}
{$text = $text|default:""}
{$label = $label|default:""}
{strip}
{if $item.kind == "popup"}
    <a href="{$item.url}" class="cm-dialog-opener cm-dialog-auto-size{if $class} {$class}{/if}" data-ca-target-id="{$item.dialog_id}" data-ca-dialog-title="{$item.title}" rel="nofollow"{if $label} aria-label="{$label}"{/if}>
        {if $text}{$text}{else}{$item.title}{/if}
    </a>
{else}
    <a href="{$item.url}"{if $class} class="{$class}"{/if}{if $item.new_window} target="_blank" rel="noopener"{/if}{if $label} aria-label="{$label}"{/if}>
        {if $text}{$text}{else}{$item.title}{/if}
        {if $item.new_window}<span class="euph-pib__sr-only">&nbsp;{__("euph_pages_in_blocks.new_window")}</span>{/if}
    </a>
{/if}
{/strip}
