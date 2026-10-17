{** block-description:tmpl_euph_pib_tabs **}
{*
    Page content block, template "Tabs": a tab per page, the page's content
    in its panel.

    The tab row is drawn hidden: without the script the panels show one
    under another, each with its name. The script
    (js/addons/euph_pages_in_blocks/func.js) shows the row, gives it the
    tabs roles and keeps one panel open; the arrow keys, Home and End move
    between the tabs, and an address ending in #<panel id> opens that tab.
*}
{$pib = fn_euph_pages_in_blocks_view($items, $block, "tabs")}
{if $pib}
    <div class="euph-pib euph-pib--tabs" id="{$pib.id}" data-euph-pib-tabs>
        <div class="euph-pib__tablist" hidden>
            {foreach $pib.items as $item}
                <button type="button" class="euph-pib__tab" id="{$item.id}-tab" data-euph-pib-target="{$item.id}"{if $item.selected} aria-selected="true"{/if}>{$item.title}</button>
            {/foreach}
        </div>
        {foreach $pib.items as $item}
            <div class="euph-pib__panel euph-pib__item euph-pib__item--{$item.type}" id="{$item.id}">
                <h3 class="euph-pib__title euph-pib__panel-title">{$item.title}</h3>
                {if $item.kind == "content"}
                    {include file="addons/euph_pages_in_blocks/components/item_body.tpl" item=$item pib=$pib}
                {else}
                    <p class="euph-pib__action">
                        {include file="addons/euph_pages_in_blocks/components/item_link.tpl" item=$item class="ty-btn ty-btn__secondary" text=__("euph_pages_in_blocks.open_page") label=""}
                    </p>
                    {if $item.kind == "popup"}
                        <div id="{$item.dialog_id}" class="hidden" title="{$item.title}"></div>
                    {/if}
                {/if}
            </div>
        {/foreach}
    </div>
{/if}
