{** block-description:tmpl_euph_pib_stacked **}
{*
    Page content block, template "One under another": each page with its
    name and its content, or, with Show set to the excerpt, the start of its
    text and a "Read more" link. A link page or a popup page shows as its
    name, linked.

    Line 1 names the template in the block's Template list (a language
    variable). The view is worked out by fn_euph_pages_in_blocks_view()
    (func.php): this template only prints it. A popup page's link opens
    CS-Cart's dialog, which loads the page into the hidden div after it.
*}
{$pib = fn_euph_pages_in_blocks_view($items, $block, "stacked")}
{if $pib}
    <div class="euph-pib euph-pib--stacked{if $pib.separators} euph-pib--separators{/if}" id="{$pib.id}">
        {foreach $pib.items as $item}
            <div class="euph-pib__item euph-pib__item--{$item.type}" id="{$item.id}">
                {if $item.kind == "content"}
                    {if $pib.show_titles}
                        <h3 class="euph-pib__title">{$item.title}</h3>
                    {/if}
                    {include file="addons/euph_pages_in_blocks/components/item_body.tpl" item=$item pib=$pib}
                {else}
                    <h3 class="euph-pib__title">
                        {include file="addons/euph_pages_in_blocks/components/item_link.tpl" item=$item class="euph-pib__title-link" text="" label=""}
                    </h3>
                    {if $item.kind == "popup"}
                        <div id="{$item.dialog_id}" class="hidden" title="{$item.title}"></div>
                    {/if}
                {/if}
            </div>
        {/foreach}
    </div>
{/if}
