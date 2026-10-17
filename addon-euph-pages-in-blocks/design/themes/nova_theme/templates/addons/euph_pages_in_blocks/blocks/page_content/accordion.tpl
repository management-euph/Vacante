{** block-description:tmpl_euph_pib_accordion **}
{*
    Page content block, template "Accordion": a section per page that opens
    on its name, as in a list of questions and answers.

    Native <details> sections: they open and close without the script, by
    keyboard too. With "Keep only one section open" they share a name, so
    the browser closes the others (the script does it where the browser
    does not). An address ending in #<section id> opens that section.
*}
{$pib = fn_euph_pages_in_blocks_view($items, $block, "accordion")}
{if $pib}
    <div class="euph-pib euph-pib--accordion" id="{$pib.id}"{if $pib.one_open} data-euph-pib-one-open{/if}>
        {foreach $pib.items as $item}
            <details class="euph-pib__section euph-pib__item euph-pib__item--{$item.type}" id="{$item.id}"{if $pib.one_open} name="{$pib.id}"{/if}{if $item.open} open{/if}>
                <summary class="euph-pib__summary"><span class="euph-pib__summary-title">{$item.title}</span></summary>
                <div class="euph-pib__section-body">
                    {if $item.kind == "content"}
                        {include file="addons/euph_pages_in_blocks/components/item_body.tpl" item=$item pib=$pib}
                    {else}
                        <p class="euph-pib__action">
                            {include file="addons/euph_pages_in_blocks/components/item_link.tpl" item=$item class="ty-btn ty-btn__secondary" text=__("euph_pages_in_blocks.open_page") label=""}
                        </p>
                        {if $item.kind == "popup"}
                            {include file="addons/euph_pages_in_blocks/components/popup_target.tpl" item=$item}
                        {/if}
                    {/if}
                </div>
            </details>
        {/foreach}
    </div>
{/if}
