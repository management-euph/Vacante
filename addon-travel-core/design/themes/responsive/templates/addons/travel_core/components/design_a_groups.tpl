{*
    Design A hotel page, several rooms (the owner's boards 13c, phase 5 of
    docs/hotel-page-design-a-plan.md): the rooms searched grouped by their
    guests (Results\RoomGroupsBuilder), one tab per group, each tab's panel
    the room cards of design_a_results.tpl priced for its guests; then the
    bar with the rooms chosen, their total and the only booking button.

    Params:
      groups — RoomGroupsBuilder::build(), amounts and dates formatted;
      book   — where the booking of the rooms chosen goes, a GET form:
               url, fields (name => value, the supplier's), rooms_field (the
               rooms chosen as a JSON list, one entry per room: its option's
               room_json with room_num, adults, children, childrenAges) and
               total_field (their total).

    A group of several rooms is chosen in one go (its tills count the rooms)
    or, with "Aleg fiecare cameră separat" (board 13c.9), room by room: a
    strip of the group's rooms, one tab each, the tills choosing for the
    room open ("Alege pentru Camera 3"); "Aleg pentru tot grupul" goes back.

    pdp-design-a.js drives each list's filters, radios and tills as for one
    room; pdp-design-a-rooms.js the tabs, the room counts, the choices and
    their notes, the room-by-room strip, the bar and the booking. The texts
    it composes are on the section (data-t-*), with the placeholders the
    lang vars have.
*}
{$travel_pdpa_groups = $groups}
{$travel_pdpa_book = $book}
{if $travel_pdpa_groups && $travel_pdpa_groups.groups}
    {$travel_pdpa_all_rooms = __("travel_core.pdpa_m_rooms_`$travel_pdpa_groups.room_form`", ["[n]" => $travel_pdpa_groups.room_count])}
    {$travel_pdpa_all_nights = __("travel_core.pdpa_r_nights_`$travel_pdpa_groups.nights_form`", ["[n]" => $travel_pdpa_groups.nights])}
    <section class="travel-pdpa-multi" id="travel-pdpa-rooms" aria-labelledby="travel-pdpa-rooms-title" data-travel-multi
        data-rooms="{$travel_pdpa_groups.room_count}"
        data-nights="{$travel_pdpa_all_nights|escape:html}"
        data-money-symbol="{$travel_pdpa_groups.money.symbol|escape:html}"
        data-money-after="{if $travel_pdpa_groups.money.after}1{else}0{/if}"
        data-money-decimals="{$travel_pdpa_groups.money.decimals}"
        data-money-decimal="{$travel_pdpa_groups.money.decimal|escape:html}"
        data-money-thousands="{$travel_pdpa_groups.money.thousands|escape:html}"
        data-t-rooms-one="{__("travel_core.pdpa_m_rooms_one")|escape:html}"
        data-t-rooms-few="{__("travel_core.pdpa_m_rooms_few")|escape:html}"
        data-t-rooms-many="{__("travel_core.pdpa_m_rooms_many")|escape:html}"
        data-t-room="{__("travel_core.pdpa_m_room")|escape:html}"
        data-t-numbers="{__("travel_core.pdpa_m_numbers")|escape:html}"
        data-t-state-todo-one="{__("travel_core.pdpa_m_state_todo_one")|escape:html}"
        data-t-state-todo-many="{__("travel_core.pdpa_m_state_todo_many")|escape:html}"
        data-t-state-now="{__("travel_core.pdpa_m_state_now")|escape:html}"
        data-t-state-now-count="{__("travel_core.pdpa_m_state_now_count")|escape:html}"
        data-t-state-part="{__("travel_core.pdpa_m_state_part")|escape:html}"
        data-t-state-done-one="{__("travel_core.pdpa_m_state_done_one")|escape:html}"
        data-t-state-done-many="{__("travel_core.pdpa_m_state_done_many")|escape:html}"
        data-t-choose-room="{__("travel_core.pdpa_m_choose_room")|escape:html}"
        data-t-total-one="{__("travel_core.pdpa_r_total")|escape:html}"
        data-t-head-room="{__("travel_core.pdpa_m_head_room")|escape:html}"
        data-t-by-room="{__("travel_core.pdpa_m_by_room")|escape:html}"
        data-t-by-group="{__("travel_core.pdpa_m_by_group")|escape:html}"
        data-t-choose-rooms="{__("travel_core.pdpa_m_choose_rooms")|escape:html}"
        data-t-chosen="{__("travel_core.pdpa_m_chosen")|escape:html}"
        data-t-chosen-rooms="{__("travel_core.pdpa_m_chosen_rooms")|escape:html}"
        data-t-total-rooms="{__("travel_core.pdpa_m_total_rooms")|escape:html}"
        data-t-cap="{__("travel_core.pdpa_m_cap")|escape:html}"
        data-t-sold-out="{__("travel_core.pdpa_m_sold_out")|escape:html}"
        data-t-done="{__("travel_core.pdpa_m_done")|escape:html}"
        data-t-done-left="{__("travel_core.pdpa_m_done_left")|escape:html}"
        data-t-undo="{__("travel_core.pdpa_m_undo")|escape:html}"
        data-t-undo-aria="{__("travel_core.pdpa_m_undo_aria")|escape:html}"
        data-t-all-done="{__("travel_core.pdpa_m_all_done")|escape:html}"
        data-t-suggest-lead="{__("travel_core.pdpa_m_suggest_lead")|escape:html}"
        data-t-suggest="{__("travel_core.pdpa_m_suggest")|escape:html}"
        data-t-suggest-btn="{__("travel_core.pdpa_m_suggest_btn")|escape:html}"
        data-t-suggest-all="{__("travel_core.pdpa_m_suggest_all")|escape:html}"
        data-t-bar-progress="{__("travel_core.pdpa_m_bar_progress")|escape:html}"
        data-t-bar-pick="{__("travel_core.pdpa_m_bar_pick")|escape:html}"
        data-t-bar-more="{__("travel_core.pdpa_m_bar_more")|escape:html}"
        data-t-change="{__("travel_core.pdpa_m_change")|escape:html}"
        data-t-change-aria="{__("travel_core.pdpa_m_change_aria")|escape:html}"
        data-t-so-far="{__("travel_core.pdpa_m_so_far")|escape:html}"
        data-t-total="{__("travel_core.pdpa_m_total")|escape:html}"
        data-t-goto="{__("travel_core.pdpa_m_goto")|escape:html}"
        data-t-book="{__("travel_core.pdpa_m_book")|escape:html}"
        data-t-book-aria="{__("travel_core.pdpa_m_book_aria")|escape:html}"
        data-t-book-mixed="{__("travel_core.pdpa_m_book_mixed")|escape:html}"
        data-t-book-mixed-aria="{__("travel_core.pdpa_m_book_mixed_aria")|escape:html}"
        data-t-request-part="{__("travel_core.pdpa_m_request_part")|escape:html}"
        data-t-reply-for="{__("travel_core.pdpa_m_reply_48h_for")|escape:html}">
        <div class="travel-pdpa-rhead">
            <h2 class="travel-pdpa-rhead__title" id="travel-pdpa-rooms-title">{__("travel_core.pdpa_r_title")}</h2>
            <p class="travel-pdpa-rhead__count">{$travel_pdpa_all_rooms} · {$travel_pdpa_all_nights}</p>
        </div>

        <div class="travel-pdpa-gtabs" role="tablist" aria-label="{if $travel_pdpa_groups.by_room}{__("travel_core.pdpa_m_tabs")}{else}{__("travel_core.pdpa_m_tabs_groups")}{/if}">
            {foreach $travel_pdpa_groups.groups as $travel_pdpa_g}
                {$travel_pdpa_gguests = __("travel_core.pdpa_r_adults_`$travel_pdpa_g.adults_form`", ["[n]" => $travel_pdpa_g.adults])}
                {if $travel_pdpa_g.children > 0}
                    {$travel_pdpa_gkids = __("travel_core.pdpa_r_children_`$travel_pdpa_g.children_form`", ["[n]" => $travel_pdpa_g.children])}
                    {$travel_pdpa_gages = __("travel_core.pdpa_m_ages_`$travel_pdpa_g.ages_form`", ["[ages]" => $travel_pdpa_g.ages_text])}
                    {$travel_pdpa_gguests = "`$travel_pdpa_gguests` + `$travel_pdpa_gkids` `$travel_pdpa_gages`"}
                {/if}
                {if $travel_pdpa_g.size == 1}
                    {$travel_pdpa_gwho = __("travel_core.pdpa_m_room", ["[n]" => $travel_pdpa_g.rooms[0]])}
                {else}
                    {$travel_pdpa_gwho = __("travel_core.pdpa_m_rooms_`$travel_pdpa_g.size_form`", ["[n]" => $travel_pdpa_g.size])}
                {/if}
                <button type="button" class="travel-pdpa-gtab" role="tab" id="travel-pdpa-gtab-{$travel_pdpa_g.key}"
                        aria-selected="{if $travel_pdpa_g@first}true{else}false{/if}" aria-controls="travel-pdpa-gpanel-{$travel_pdpa_g.key}"
                        tabindex="{if $travel_pdpa_g@first}0{else}-1{/if}" data-travel-gtab="{$travel_pdpa_g.key}">
                    <span class="travel-pdpa-gtab__t">{$travel_pdpa_gwho} · {$travel_pdpa_gguests|escape:html}</span>
                    {if $travel_pdpa_g.size > 1}<span class="travel-pdpa-gtab__g">{__("travel_core.pdpa_m_numbers", ["[numbers]" => $travel_pdpa_g.numbers])}</span>{/if}
                    <span class="travel-pdpa-gtab__s" data-travel-gtab-state><span class="travel-pdpa-dot" aria-hidden="true"></span>{if $travel_pdpa_g.size == 1}{__("travel_core.pdpa_m_state_todo_one")}{else}{__("travel_core.pdpa_m_state_todo_many")}{/if}</span>
                </button>
            {/foreach}
        </div>

        {foreach $travel_pdpa_groups.groups as $travel_pdpa_g}
            {$travel_pdpa_gguests = __("travel_core.pdpa_r_adults_`$travel_pdpa_g.adults_form`", ["[n]" => $travel_pdpa_g.adults])}
            {if $travel_pdpa_g.children > 0}
                {$travel_pdpa_gkids = __("travel_core.pdpa_r_children_`$travel_pdpa_g.children_form`", ["[n]" => $travel_pdpa_g.children])}
                {$travel_pdpa_gages = __("travel_core.pdpa_m_ages_`$travel_pdpa_g.ages_form`", ["[ages]" => $travel_pdpa_g.ages_text])}
                {$travel_pdpa_gguests = "`$travel_pdpa_gguests` + `$travel_pdpa_gkids` `$travel_pdpa_gages`"}
            {/if}
            <div class="travel-pdpa-gpanel" role="tabpanel" id="travel-pdpa-gpanel-{$travel_pdpa_g.key}" aria-labelledby="travel-pdpa-gtab-{$travel_pdpa_g.key}" tabindex="0"
                 data-travel-group="{$travel_pdpa_g.key}" data-size="{$travel_pdpa_g.size}" data-rooms="{$travel_pdpa_g.rooms_csv}"
                 data-adults="{$travel_pdpa_g.adults}" data-ages="{$travel_pdpa_g.ages_text}" data-guests="{$travel_pdpa_gguests|escape:html}"{if !$travel_pdpa_g@first} hidden{/if}>
                <div class="travel-pdpa-gnotes" data-travel-gnotes></div>
                <div class="travel-pdpa-ghead">
                    <h3 class="travel-pdpa-ghead__t" id="travel-pdpa-ghead-{$travel_pdpa_g.key}">
                        {if $travel_pdpa_g.size == 1}
                            {__("travel_core.pdpa_m_head_room", ["[n]" => $travel_pdpa_g.rooms[0], "[guests]" => $travel_pdpa_gguests|escape:html])}
                        {else}
                            {__("travel_core.pdpa_m_head_group", ["[rooms]" => __("travel_core.pdpa_m_rooms_`$travel_pdpa_g.size_form`", ["[n]" => $travel_pdpa_g.size]), "[guests]" => $travel_pdpa_gguests|escape:html])}
                        {/if}
                    </h3>
                    {if $travel_pdpa_g.size > 1}
                        <button type="button" class="travel-pdpa-linkbtn travel-pdpa-ghead__by" data-travel-by-room aria-controls="travel-pdpa-strip-{$travel_pdpa_g.key}">{__("travel_core.pdpa_m_by_room")}</button>
                    {/if}
                    {if $travel_pdpa_g.size == 2}
                        <p class="travel-pdpa-ghead__sub" data-travel-sub-group>{__("travel_core.pdpa_m_sub_two")}</p>
                    {elseif $travel_pdpa_g.size > 2}
                        <p class="travel-pdpa-ghead__sub" data-travel-sub-group>{__("travel_core.pdpa_m_sub_many", ["[n]" => $travel_pdpa_g.size])}</p>
                    {/if}
                    {if $travel_pdpa_g.size > 1}
                        <p class="travel-pdpa-ghead__sub" data-travel-sub-rooms hidden>{__("travel_core.pdpa_m_sub_rooms")}</p>
                    {/if}
                </div>
                {if $travel_pdpa_g.size > 1}
                    {* Room by room (board 13c.9): one tab per room of the group *}
                    <div class="travel-pdpa-strip" id="travel-pdpa-strip-{$travel_pdpa_g.key}" data-travel-strip hidden>
                        <button type="button" class="travel-pdpa-strip__nav travel-pdpa-strip__nav--prev" data-travel-strip-nav="-1" tabindex="-1" aria-label="{__("travel_core.pdpa_m_strip_prev")}">{include file="addons/travel_core/components/design_a_icon.tpl" icon="chev"}</button>
                        <div class="travel-pdpa-strip__vp" data-travel-strip-vp>
                            <div class="travel-pdpa-stabs" role="tablist" aria-label="{__("travel_core.pdpa_m_strip", ["[numbers]" => $travel_pdpa_g.numbers])}">
                                {foreach $travel_pdpa_g.rooms as $travel_pdpa_n}
                                    <button type="button" class="travel-pdpa-stab" role="tab" id="travel-pdpa-stab-{$travel_pdpa_g.key}-{$travel_pdpa_n}"
                                            aria-selected="false" aria-controls="travel-pdpa-spanel-{$travel_pdpa_g.key}" tabindex="-1" data-travel-stab="{$travel_pdpa_n}">
                                        <span class="travel-pdpa-stab__t">{__("travel_core.pdpa_m_room", ["[n]" => $travel_pdpa_n])}</span>
                                        <span class="travel-pdpa-stab__g">{$travel_pdpa_gguests|escape:html}</span>
                                        <span class="travel-pdpa-stab__s" data-travel-stab-state><span class="travel-pdpa-dot" aria-hidden="true"></span>{__("travel_core.pdpa_m_state_todo_one")}</span>
                                    </button>
                                {/foreach}
                            </div>
                        </div>
                        <button type="button" class="travel-pdpa-strip__nav travel-pdpa-strip__nav--next" data-travel-strip-nav="1" tabindex="-1" aria-label="{__("travel_core.pdpa_m_strip_next")}">{include file="addons/travel_core/components/design_a_icon.tpl" icon="chev"}</button>
                    </div>
                {/if}
                <div class="travel-pdpa-spanel" id="travel-pdpa-spanel-{$travel_pdpa_g.key}" data-travel-spanel>
                    {if $travel_pdpa_g.size > 1}<p class="travel-pdpa-stephead" data-travel-stephead hidden></p>{/if}
                    {include file="addons/travel_core/components/design_a_results.tpl" results=$travel_pdpa_g.results id_suffix="-`$travel_pdpa_g.key`" group=$travel_pdpa_g}
                </div>
            </div>
        {/foreach}

        <template data-travel-icon-check>{include file="addons/travel_core/components/design_a_icon.tpl" icon="check"}</template>
        <template data-travel-icon-ok>{include file="addons/travel_core/components/design_a_icon.tpl" icon="check" size=14}</template>

        <div class="travel-pdpa-bbar" role="region" aria-label="{__("travel_core.pdpa_m_bar")}" data-travel-bar hidden>
            <div class="travel-pdpa-bbar__in">
                <div class="travel-pdpa-bbar__l">
                    <div class="travel-pdpa-bbar__prog">
                        <p class="travel-pdpa-bbar__t" role="status" data-travel-bar-progress></p>
                        <div class="travel-pdpa-meter" aria-hidden="true"><span data-travel-bar-meter></span></div>
                    </div>
                    {* More than two lines of choices (or a phone): behind "Vezi alegerile (N)" *}
                    <button type="button" class="travel-pdpa-btn travel-pdpa-btn--sec travel-pdpa-btn--sm travel-pdpa-bbar__more" data-travel-bar-more aria-expanded="false" aria-controls="travel-pdpa-bbar-picks">{__("travel_core.pdpa_m_bar")}</button>
                    <div class="travel-pdpa-bbar__picks" id="travel-pdpa-bbar-picks" data-travel-bar-picks></div>
                </div>
                <div class="travel-pdpa-bbar__r">
                    <p class="travel-pdpa-bbar__total" data-travel-bar-total></p>
                    <a class="travel-pdpa-btn travel-pdpa-btn--sec travel-pdpa-bbar__goto" href="#travel-pdpa-rooms" data-travel-bar-goto hidden></a>
                    <div class="travel-pdpa-cta travel-pdpa-bbar__cta" data-travel-bar-cta hidden>
                        <button type="button" class="travel-pdpa-btn travel-pdpa-bbar__btn" data-travel-book></button>
                        <p class="travel-pdpa-cta__note" data-travel-bar-note>{__("travel_core.pdpa_r_no_pay_yet")}</p>
                        <p class="travel-pdpa-cta__note" data-travel-bar-note-request hidden>{include file="addons/travel_core/components/design_a_icon.tpl" icon="clock" size=14}<span data-travel-bar-reply></span><button type="button" class="travel-pdpa-tip" data-travel-tip aria-expanded="false" aria-controls="travel-pdpa-bbar-tip" aria-label="{__("travel_core.pdpa_r_how_request")}">{include file="addons/travel_core/components/design_a_icon.tpl" icon="info"}</button></p>
                        <div class="travel-pdpa-tip__pop" id="travel-pdpa-bbar-tip" role="dialog" aria-labelledby="travel-pdpa-bbar-tip-h" data-travel-tip-pop hidden>
                            <p class="travel-pdpa-tip__h" id="travel-pdpa-bbar-tip-h">{__("travel_core.pdpa_r_how_request")}</p>
                            <ol class="travel-pdpa-tip__list">
                                <li><b>{__("travel_core.pdpa_m_bar_step1_lead")}</b> {__("travel_core.pdpa_m_bar_step1")}</li>
                                <li><b>{__("travel_core.pdpa_m_bar_step2_lead")}</b> {__("travel_core.pdpa_m_bar_step2")}</li>
                                <li><b>{__("travel_core.pdpa_m_bar_step3_lead")}</b> {__("travel_core.pdpa_m_bar_step3")}</li>
                            </ol>
                            <button type="button" class="travel-pdpa-tip__x" data-travel-tip-close aria-label="{__("travel_core.pdpa_r_close")}">{include file="addons/travel_core/components/design_a_icon.tpl" icon="x"}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {if $travel_pdpa_book.url}
            <form class="travel-pdpa-bookform" method="get" action="{$travel_pdpa_book.url|escape:html}" data-travel-book-form hidden>
                {foreach $travel_pdpa_book.fields as $travel_pdpa_name => $travel_pdpa_value}
                    <input type="hidden" name="{$travel_pdpa_name|escape:html}" value="{$travel_pdpa_value|escape:html}">
                {/foreach}
                <input type="hidden" name="{$travel_pdpa_book.rooms_field|escape:html}" value="" data-travel-book-rooms>
                <input type="hidden" name="{$travel_pdpa_book.total_field|escape:html}" value="" data-travel-book-total>
            </form>
        {/if}
    </section>
{/if}
