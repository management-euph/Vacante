{*
    One room card of the Design A results (RoomResultsBuilder RoomView), on
    three columns (board 13b): the room | its options, then what they all
    share, said once | the till, the chosen option's total with the room's
    only button. A tablet moves the till under the options; a phone stacks
    all three and pins the till to the bottom of the screen while the card
    is in view (design-a.css): __offer holds the options and the till, so
    the till sticks only while they are on screen.
    Included by design_a_results.tpl with $travel_pdpa_room set.
*}
{$travel_pdpa_room_id = "travel-pdpa-`$travel_pdpa_room.key`"}
<li class="travel-pdpa-rcard" id="{$travel_pdpa_room_id}"
    data-travel-room
    data-room-key="{$travel_pdpa_room.key}"
    data-from="{$travel_pdpa_room.from}"
    data-from-text="{$travel_pdpa_room.from_text|escape:html}"
    data-name="{$travel_pdpa_room.name|escape:html}"
    data-meals="{$travel_pdpa_room.meals}"
    {if $travel_pdpa_room.more}hidden{/if}>
    <div class="travel-pdpa-rcard__info">
        {if $travel_pdpa_room.cheapest}<span class="travel-pdpa-cue travel-pdpa-rcard__cue">{__("travel_core.pdpa_r_cheapest")}</span>{/if}
        <div class="travel-pdpa-rcard__nm">
            <h3 class="travel-pdpa-rcard__name">{$travel_pdpa_room.title|escape:html}</h3>
            {if $travel_pdpa_room.code}<p class="travel-pdpa-rcard__code"><span class="travel-pdpa-sr">{__("travel_core.pdpa_r_room_code")}: </span>{$travel_pdpa_room.code|escape:html}</p>{/if}
        </div>
        {if $travel_pdpa_room.on_request || $travel_pdpa_room.immediate || $travel_pdpa_room.left > 0}
            <div class="travel-pdpa-rcard__pills">
                {if $travel_pdpa_room.on_request}
                    <span class="travel-pdpa-pill travel-pdpa-pill--info">{include file="addons/travel_core/components/design_a_icon.tpl" icon="clock" size=14}{__("travel_core.pdpa_r_on_request_tag")}</span>
                {else}
                    {if $travel_pdpa_room.immediate}
                        <span class="travel-pdpa-pill travel-pdpa-pill--ok">{include file="addons/travel_core/components/design_a_icon.tpl" icon="zap" size=14}{__("travel_core.pdpa_r_immediate")}</span>
                    {/if}
                    {if $travel_pdpa_room.left == 1}
                        <span class="travel-pdpa-pill travel-pdpa-pill--bad">{include file="addons/travel_core/components/design_a_icon.tpl" icon="alert" size=14}{__("travel_core.pdpa_r_last_room")}</span>
                    {elseif $travel_pdpa_room.left > 1 && $travel_pdpa_room.left <= 3}
                        <span class="travel-pdpa-pill travel-pdpa-pill--warn">{include file="addons/travel_core/components/design_a_icon.tpl" icon="alert" size=14}{__("travel_core.pdpa_r_few_left", ["[n]" => $travel_pdpa_room.left])}</span>
                    {elseif $travel_pdpa_room.left > 3}
                        <span class="travel-pdpa-pill travel-pdpa-pill--muted">{include file="addons/travel_core/components/design_a_icon.tpl" icon="key" size=14}{__("travel_core.pdpa_r_rooms_left_`$travel_pdpa_room.left_form`", ["[n]" => $travel_pdpa_room.left])}</span>
                    {/if}
                {/if}
            </div>
        {/if}
        {if $travel_pdpa_room.capacity > 0}
            <p class="travel-pdpa-rfacts2"><span>{include file="addons/travel_core/components/design_a_icon.tpl" icon="users"}{__("travel_core.pdpa_r_capacity_`$travel_pdpa_room.capacity_form`", ["[n]" => $travel_pdpa_room.capacity])}</span></p>
        {/if}
        {if $travel_pdpa_room.facilities}
            {$travel_pdpa_amen_count = $travel_pdpa_room.facilities|count}
            {$travel_pdpa_amen_more = $travel_pdpa_amen_count - 6}
            <ul class="travel-pdpa-amen" aria-label="{__("travel_core.pdpa_r_amenities")}" tabindex="-1" data-travel-amen>
                {foreach $travel_pdpa_room.facilities as $travel_pdpa_facility name=travel_pdpa_amen}
                    <li{if $smarty.foreach.travel_pdpa_amen.index >= 6} data-travel-amen-more hidden{/if}>{$travel_pdpa_facility|escape:html}</li>
                {/foreach}
            </ul>
            {if $travel_pdpa_amen_more > 0}
                <button type="button" class="travel-pdpa-linkbtn travel-pdpa-rcard__amen" data-travel-amen-all aria-expanded="false" aria-label="{__("travel_core.pdpa_r_all_amenities", ["[n]" => $travel_pdpa_amen_more])}: {$travel_pdpa_room.title|escape:html}">{__("travel_core.pdpa_r_all_amenities", ["[n]" => $travel_pdpa_amen_more])}</button>
            {/if}
        {/if}
    </div>

    <div class="travel-pdpa-rcard__offer">
    <div class="travel-pdpa-rcard__mid">
        {if $travel_pdpa_room.single}
            <div class="travel-pdpa-opts" role="group" aria-label="{__("travel_core.pdpa_r_only_meal")}">
                {include file="addons/travel_core/components/design_a_option.tpl" travel_pdpa_option=$travel_pdpa_room.options[0]}
            </div>
        {else}
            <fieldset class="travel-pdpa-opts">
                <legend class="travel-pdpa-sr">{__("travel_core.pdpa_r_choose_meal")}</legend>
                {foreach $travel_pdpa_room.options as $travel_pdpa_option}
                    {include file="addons/travel_core/components/design_a_option.tpl"}
                {/foreach}
            </fieldset>
        {/if}
        {if $travel_pdpa_room.package || $travel_pdpa_room.deal || $travel_pdpa_room.cancel || $travel_pdpa_room.payment || $travel_pdpa_room.terms}
            <div class="travel-pdpa-rterms">
                {include file="addons/travel_core/components/design_a_package.tpl" package=$travel_pdpa_room.package deal=$travel_pdpa_room.deal shared=true}
                {if $travel_pdpa_room.cancel}{include file="addons/travel_core/components/design_a_cancel.tpl" cancel=$travel_pdpa_room.cancel}{/if}
                {if $travel_pdpa_room.payment}{include file="addons/travel_core/components/design_a_payment.tpl" payment=$travel_pdpa_room.payment}{/if}
                {foreach $travel_pdpa_room.terms as $travel_pdpa_set}
                    {include file="addons/travel_core/components/design_a_terms.tpl" set=$travel_pdpa_set chosen=$travel_pdpa_room.chosen label=$travel_pdpa_room.title}
                {/foreach}
            </div>
        {/if}
    </div>

    <div class="travel-pdpa-rcard__till">
        {foreach $travel_pdpa_room.options as $travel_pdpa_option}
            {include file="addons/travel_core/components/design_a_till.tpl"}
        {/foreach}
    </div>
    </div>
    <p class="travel-pdpa-sr" aria-live="polite" data-travel-announce></p>
</li>
