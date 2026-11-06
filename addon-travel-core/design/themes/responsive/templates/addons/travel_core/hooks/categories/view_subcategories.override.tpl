{*
    The destination shortcuts above the hotel list (board 15, decision 7),
    in place of CS-Cart's subcategory links, inside its
    <ul class="subcategories"> (views/categories/components/subcategories.tpl):
    the busiest destinations first, with their hotel count, the rest behind
    "All destinations (N)" (a checkbox, so no script). Only on a category
    drawn with the Travel Core list ($travel_list_destinations,
    functions/list_cards.php); elsewhere this is empty and CS-Cart's own
    links show.
*}
{$travel_dest = $travel_list_destinations|default:[]}
{if $travel_dest}
    {foreach $travel_dest.visible as $travel_dest_item}
        <li class="travel-dest"><a class="travel-dest__chip" href="{"categories.view?category_id=`$travel_dest_item.category_id`"|fn_url}">{$travel_dest_item.name}<span class="travel-dest__n">{$travel_dest_item.count}</span></a></li>
    {/foreach}
    {foreach $travel_dest.more as $travel_dest_item}
        <li class="travel-dest travel-dest--more"><a class="travel-dest__chip" href="{"categories.view?category_id=`$travel_dest_item.category_id`"|fn_url}">{$travel_dest_item.name}<span class="travel-dest__n">{$travel_dest_item.count}</span></a></li>
    {/foreach}
    {if $travel_dest.more}
        <li class="travel-dest travel-dest--all">
            <input type="checkbox" class="travel-dest__toggle" id="travel_dest_all_{$category_data.category_id}">
            <label class="travel-dest__all" for="travel_dest_all_{$category_data.category_id}">
                <span class="travel-dest__open">{__("travel_core.list_all_destinations", ["[n]" => $travel_dest.total])}</span>
                <span class="travel-dest__close">{__("travel_core.list_fewer_destinations")}</span>
            </label>
        </li>
    {/if}
{/if}
