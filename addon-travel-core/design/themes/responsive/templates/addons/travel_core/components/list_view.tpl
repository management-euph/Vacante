{*
    The body of the Travel Core list views (blocks/product_list_templates/
    travelcore_cards_list.tpl, travelcore_cards_grid.tpl), inside CS-Cart's
    own list frame as blocks/list_templates/products_list.tpl draws it: the
    pagination pair (common/pagination.tpl opens #pagination_contents, the
    target of the sorting, view and page links), CS-Cart's sorting bar with
    its view switch, then the cards.

    Every product a supplier owns gets the Travel Core card from
    $travel_list_cards (functions/list_cards.php); any other product gets
    the same card with its CS-Cart price and a "details" button. Under the
    cards: "Showing 1–20 of 29" and "Load 9 more" ($travel_list_more), a
    link to the next page that list-load-more.js turns into "append".

    Params: travel_list_layout = list | grid
*}
{if $products}
    {script src="js/addons/travel_core/list-load-more.js"}

    {if !$no_pagination}
        {include file="common/pagination.tpl"}
    {/if}

    {if !$no_sorting}
        {include file="views/products/components/sorting.tpl"}
    {/if}

    {$travel_cards = $travel_list_cards|default:[]}
    <div class="travel-list-wrap" data-travel-list>
        <ul class="travel-list travel-list--{$travel_list_layout|default:"list"}" data-travel-list-items>
            {foreach $products as $product}
                {if isset($travel_cards[$product.product_id])}
                    {include file="addons/travel_core/components/list_card.tpl" product=$product card=$travel_cards[$product.product_id]}
                {else}
                    {include file="addons/travel_core/components/list_card.tpl" product=$product card=["kind" => "product", "stars" => 0, "place" => "", "from_price" => "", "cta" => "travel_core.list_cta_product"]}
                {/if}
            {/foreach}
        </ul>

        {$travel_more = $travel_list_more|default:[]}
        {if $travel_more}
            <div class="travel-list-more" data-travel-list-more-box>
                <p class="travel-list-more__t" aria-live="polite" data-travel-list-shown data-from="{$travel_more.from}" data-to="{$travel_more.to}" data-total="{$travel_more.total}" data-text="{__("travel_core.list_shown")|escape:html}">{__("travel_core.list_shown", ["[from]" => $travel_more.from, "[to]" => $travel_more.to, "[total]" => $travel_more.total])}</p>
                <a class="travel-list-more__btn" href="{$travel_more.url}" rel="next" data-travel-list-more>{__("travel_core.list_load_more", ["[count]" => $travel_more.count])}</a>
            </div>
        {/if}

        {if $travel_cards}
            <p class="travel-list-foot">{__("travel_core.list_footnote")}</p>
        {/if}
    </div>

    {if !$no_pagination}
        {include file="common/pagination.tpl" force_ajax=$force_ajax}
    {/if}
{/if}

{capture name="mainbox_title"}{$title}{/capture}
