{*
 * Shared per-room guest cards — the body booking_guest_cards.tpl renders for
 * each room, extracted so a provider that owns its room scaffolding (novoton:
 * per-room price banners + room-guest-section wrappers its re-price JS
 * targets) reuses the SAME guest-card markup instead of duplicating it.
 *
 * POST field contract (identical across providers, parsed by each
 * add_to_cart): guests[room{N}_{type}_{i}][last_name|first_name|type|age|
 * room|is_holder|dob]. Roomless (participant) mode drops the room{N}_ prefix
 * and the [room] hidden — those key names reach the provider API, so the
 * wire contract must not change.
 *
 * Parameters (gb_ = guest body):
 *   gb_room                (array, required) one rooms_data entry: adults,
 *                          children, childrenAges[].
 *   gb_room_num            (int, required) 1-based room number.
 *   gb_room_idx            (int, required) 0-based index — the booking
 *                          holder is the first adult of room index 0.
 *   gb_label_prefix        (string, default 'travel_core') langvar namespace.
 *   gb_prefill             (map, default []) name/DOB prefill keyed like the
 *                          input names ("room1_adult_1" => [last_name,
 *                          first_name, dob]) — edit mode only; absent, every
 *                          value attribute resolves to ''.
 *   gb_roomless            (bool, default false) participant mode: field
 *                          names guests[adult_{i}] with NO room prefix.
 *   gb_show_adult_dob      (bool, default true) render adult DOB fields.
 *   gb_adult_dob_required  (bool, default false) manifests (package/circuit)
 *                          need it; hotel adult DOB stays optional.
 *   gb_child_dob_required  (bool, default true)
 *   gb_guard_expected_ages (bool, default false) stamp data-expected-age so
 *                          the shared JS blocks a DOB that contradicts the
 *                          searched age (fixed-price providers like sphinx).
 *   gb_extra_class         (string, default '') provider CSS hook.
 *   gb_adult_age_value     (string, default '') when set, emit a hidden
 *                          [age] with this value for each adult (novoton's
 *                          pricing API expects one).
 *   gb_child_dob_onblur    (string, default '') JS function name bound to
 *                          child DOB blur for live age recheck + re-price
 *                          (novoton); also emits the dob_info_/dob_error_
 *                          message areas that function writes to.
 *   gb_seq_offset          (int, default -1) -1: per-room numbering
 *                          ("Adult 1"); >= 0: booking-wide sequential labels
 *                          ("3. Adult") offset by guests in earlier rooms.
 *   gb_adult_sublabel      (string, default '') note under each adult label
 *                          (novoton: bed type).
 *   gb_gender_options      (map, default []) value => label; when set, each
 *                          adult gets REQUIRED guests[...][gender] radios with
 *                          explicit labels and nothing preselected (eurosite:
 *                          TGender B/F). Empty — no field, markup unchanged.
 *   gb_child_gender        (string, default '') when set, a hidden
 *                          guests[...][gender] with this value per child
 *                          (eurosite: C).
 *
 * @package TravelCore
 *}
{$_gp = $gb_label_prefix|default:'travel_core'}
{$_prefill = $gb_prefill|default:[]}
{$_roomless = $gb_roomless|default:false}
{$_room_num = $gb_room_num|default:1}
{$_room_idx = $gb_room_idx|default:0}
{$_show_adult_dob = $gb_show_adult_dob|default:true}
{$_child_dob_required = $gb_child_dob_required|default:true}
{$_extra_class = $gb_extra_class|default:''}
{$_adult_age_value = $gb_adult_age_value|default:''}
{$_dob_onblur = $gb_child_dob_onblur|default:''}
{$_seq_off = $gb_seq_offset|default:-1}
{$_adult_sublabel = $gb_adult_sublabel|default:''}
{$_gender_options = $gb_gender_options|default:[]}
{$_child_gender = $gb_child_gender|default:''}
{* Field-name prefix: '' in roomless (participant) mode, room{N}_ otherwise *}
{if $_roomless}{$_npfx = ''}{else}{$_npfx = "room`$_room_num`_"}{/if}

{* Adults *}
{assign var="room_adults" value=$gb_room.adults|default:1}
{section name="adult" start=1 loop=$room_adults+1}
    {$_i = $smarty.section.adult.index}
    <div class="guest-entry guest-entry-adult">
        <div class="travel-guest-label">
            {* "Adult 1" — travel_core.guest_adult is the capitalised card
               label; travel_core.adult stays the lowercase word used
               mid-sentence elsewhere ("2 adult"). *}
            {if $_seq_off >= 0}{$_seq_n = $_seq_off+$_i}{__("`$_gp`.guest_adult")} {$_seq_n}{else}{__("`$_gp`.guest_adult")} {$_i}{/if}
            {if $_room_idx == 0 && $_i == 1} <span class="travel-holder-tag">{__("`$_gp`.main_guest")|default:"Main Guest"}</span>{/if}
        </div>
        {if $_adult_sublabel}<div class="travel-guest-sublabel">{$_adult_sublabel}</div>{/if}
        <div class="travel-guest-grid">
            {* Nume (last name) FIRST, Prenume second — novoton field order *}
            <div class="travel-guest-field">
                <label for="guest_r{$_room_num}_a{$_i}_last">{__("`$_gp`.last_name")|default:"Last Name"}<span class="travel-guest-required" aria-hidden="true">*</span></label>
                {$_pf_key = "`$_npfx`adult_`$_i`"}
                <input type="text" id="guest_r{$_room_num}_a{$_i}_last"
                       name="guests[{$_npfx}adult_{$_i}][last_name]"
                       value="{$_prefill.$_pf_key.last_name|default:''|escape:html}"
                       class="ty-input-text" required aria-required="true" pattern=".*\S.*" autocomplete="family-name">
                <input type="hidden" name="guests[{$_npfx}adult_{$_i}][type]" value="adult">
                {if $_adult_age_value != ''}
                <input type="hidden" name="guests[{$_npfx}adult_{$_i}][age]" value="{$_adult_age_value}">
                {/if}
                {if !$_roomless}
                <input type="hidden" name="guests[{$_npfx}adult_{$_i}][room]" value="{$_room_num}">
                {/if}
                {if $_room_idx == 0 && $_i == 1}
                    <input type="hidden" name="guests[{$_npfx}adult_1][is_holder]" value="1">
                {/if}
            </div>
            <div class="travel-guest-field">
                <label for="guest_r{$_room_num}_a{$_i}_first">{__("`$_gp`.first_name")|default:"First Name"}<span class="travel-guest-required" aria-hidden="true">*</span></label>
                <input type="text" id="guest_r{$_room_num}_a{$_i}_first"
                       name="guests[{$_npfx}adult_{$_i}][first_name]"
                       value="{$_prefill.$_pf_key.first_name|default:''|escape:html}"
                       class="ty-input-text" required aria-required="true" pattern=".*\S.*" autocomplete="given-name">
            </div>
            {if $_gender_options}
            <div class="travel-guest-field travel-guest-field--gender">
                <span class="travel-guest-field-label" id="guest_r{$_room_num}_a{$_i}_gender">{__("`$_gp`.gender")|default:"Gender"}<span class="travel-guest-required" aria-hidden="true">*</span></span>
                <div class="travel-guest-radios" role="radiogroup" aria-labelledby="guest_r{$_room_num}_a{$_i}_gender">
                    {foreach $_gender_options as $_g_val => $_g_label}
                        <label class="travel-guest-radio"><input type="radio" name="guests[{$_npfx}adult_{$_i}][gender]" value="{$_g_val|escape:html}" required{if $_prefill.$_pf_key.gender|default:'' == $_g_val} checked{/if}> {$_g_label|escape:html}</label>
                    {/foreach}
                </div>
            </div>
            {/if}
            {if $_show_adult_dob}
            <div class="travel-guest-field travel-guest-field--dob">
                <label for="guest_r{$_room_num}_a{$_i}_dob">{__("`$_gp`.date_of_birth")|default:"Date of Birth"}{if $gb_adult_dob_required|default:false}<span class="travel-guest-required" aria-hidden="true">*</span>{/if}</label>
                <input type="tel" id="guest_r{$_room_num}_a{$_i}_dob"
                       name="guests[{$_npfx}adult_{$_i}][dob]"
                       value="{$_prefill.$_pf_key.dob|default:''|escape:html}"
                       class="ty-input-text dob-masked-input js-dob-basics" placeholder="{__("`$_gp`.dob_placeholder")|escape:html}" maxlength="10"
                       inputmode="numeric" autocomplete="off"
                       {if $gb_adult_dob_required|default:false}required aria-required="true"{/if}
                       onkeydown="TravelBooking.handleDobKeydown(event)"
                       oninput="TravelBooking.applyDobMask(this)">
            </div>
            {/if}
        </div>
    </div>
{/section}

{* Children *}
{assign var="room_children" value=$gb_room.children|default:0}
{if $room_children > 0}
    {assign var="room_child_ages" value=$gb_room.childrenAges|default:[]}
    {section name="child" start=1 loop=$room_children+1}
        {$_i = $smarty.section.child.index}
        {assign var="child_age" value=$room_child_ages[$_i-1]|default:0}
        <div class="guest-entry guest-entry-child" data-original-age="{$child_age}">
            <div class="travel-guest-label travel-guest-label--child">
                {__("`$_gp`.guest_child")} {$_i}
                {* The shared DOB JS (dob-validation.js / provider re-price
                   modules) rewrites this span by id when a DOB implies a
                   different age at check-in. *}
                <span class="travel-guest-age-note" id="child_age_display_r{$_room_num}_c{$_i}"> ({$child_age} {if $child_age == 1}{__("`$_gp`.year_old")|default:"year old"}{else}{__("`$_gp`.years_old")|default:"years old"}{/if})</span>
            </div>
            {if $_dob_onblur}
            {* Age info message area written by the onblur recheck function *}
            <div id="dob_info_r{$_room_num}_c{$_i}" class="dob-info-message travel-dob-info" style="display: none;"></div>
            {/if}
            <div class="travel-guest-grid">
                {* Nume (last name) FIRST, Prenume second — novoton field order *}
                <div class="travel-guest-field">
                    <label for="guest_r{$_room_num}_c{$_i}_last">{__("`$_gp`.last_name")|default:"Last Name"}<span class="travel-guest-required" aria-hidden="true">*</span></label>
                    {$_pf_key = "`$_npfx`child_`$_i`"}
                    <input type="text" id="guest_r{$_room_num}_c{$_i}_last"
                           name="guests[{$_npfx}child_{$_i}][last_name]"
                           value="{$_prefill.$_pf_key.last_name|default:''|escape:html}"
                           class="ty-input-text" required aria-required="true" pattern=".*\S.*" autocomplete="off">
                    <input type="hidden" name="guests[{$_npfx}child_{$_i}][type]" id="child_type_r{$_room_num}_c{$_i}" value="child">
                    <input type="hidden" name="guests[{$_npfx}child_{$_i}][age]" id="child_age_r{$_room_num}_c{$_i}" value="{$child_age}">
                    {if $_child_gender != ''}
                    <input type="hidden" name="guests[{$_npfx}child_{$_i}][gender]" value="{$_child_gender|escape:html}">
                    {/if}
                    {if !$_roomless}
                    <input type="hidden" name="guests[{$_npfx}child_{$_i}][room]" value="{$_room_num}">
                    {/if}
                </div>
                <div class="travel-guest-field">
                    <label for="guest_r{$_room_num}_c{$_i}_first">{__("`$_gp`.first_name")|default:"First Name"}<span class="travel-guest-required" aria-hidden="true">*</span></label>
                    <input type="text" id="guest_r{$_room_num}_c{$_i}_first"
                           name="guests[{$_npfx}child_{$_i}][first_name]"
                           value="{$_prefill.$_pf_key.first_name|default:''|escape:html}"
                           class="ty-input-text" required aria-required="true" pattern=".*\S.*" autocomplete="off">
                </div>
                <div class="travel-guest-field travel-guest-field--dob">
                    <label for="dob_r{$_room_num}_c{$_i}">{__("`$_gp`.date_of_birth")|default:"Date of Birth"}{if $_child_dob_required}<span class="travel-guest-required" aria-hidden="true">*</span>{/if}</label>
                    {* data-expected-age arms the shared JS + server guard: the DOB
                       must imply THIS age at check-in (the age the offer was priced
                       for). Fixed-price providers only — novoton re-prices instead
                       via the gb_child_dob_onblur recheck. *}
                    <input type="tel" id="dob_r{$_room_num}_c{$_i}"
                           name="guests[{$_npfx}child_{$_i}][dob]"
                           value="{$_prefill.$_pf_key.dob|default:''|escape:html}"
                           class="ty-input-text dob-masked-input js-dob-basics" placeholder="{__("`$_gp`.dob_placeholder")|escape:html}" maxlength="10"
                           inputmode="numeric" autocomplete="off"
                           {if $_child_dob_required}required aria-required="true"{/if}
                           {if $gb_guard_expected_ages|default:false}data-expected-age="{$child_age}"{/if}
                           onkeydown="TravelBooking.handleDobKeydown(event)"
                           oninput="TravelBooking.applyDobMask(this)"
                           {if $_dob_onblur}onblur="{$_dob_onblur}('r{$_room_num}_c{$_i}', {$child_age})"{/if}>
                </div>
            </div>
            {if $_dob_onblur}
            <div id="dob_error_r{$_room_num}_c{$_i}" class="dob-validation-error" style="display: none;"></div>
            {/if}
        </div>
    {/section}
{/if}
