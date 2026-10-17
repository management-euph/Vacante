{** block-description:tmpl_euph_pib_cards **}
{*
    Page content block, template "Cards": a card per page, with its name
    and the start of its text, the whole card a link to the page (or to its
    address, for a link page; to its popup, for a popup page). One card per
    row on phones, two on tablets, "Cards per row" on wide screens.

    One link per card, its name's: the card's other text is not a second
    stop for the keyboard or a screen reader.
*}
{$pib = fn_euph_pages_in_blocks_view($items, $block, "cards")}
{if $pib}
    <div class="euph-pib euph-pib--cards euph-pib--cols-{$pib.columns}" id="{$pib.id}">
        <ul class="euph-pib__cards" role="list">
            {foreach $pib.items as $item}
                <li class="euph-pib__card euph-pib__item euph-pib__item--{$item.type}" id="{$item.id}">
                    <h3 class="euph-pib__card-title">
                        {include file="addons/euph_pages_in_blocks/components/item_link.tpl" item=$item class="euph-pib__card-link" text="" label=""}
                    </h3>
                    {if $item.excerpt}
                        <p class="euph-pib__excerpt">{$item.excerpt}</p>
                    {/if}
                    <span class="euph-pib__more" aria-hidden="true">{__("euph_pages_in_blocks.read_more")}</span>
                    {if $item.kind == "popup"}
                        {include file="addons/euph_pages_in_blocks/components/popup_target.tpl" item=$item}
                    {/if}
                </li>
            {/foreach}
        </ul>
    </div>
{/if}
