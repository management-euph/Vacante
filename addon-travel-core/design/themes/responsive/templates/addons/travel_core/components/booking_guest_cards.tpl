{*
 * Shared guest-entry cards for provider booking forms.
 *
 * One source of the per-adult / per-child name + DOB cards both providers'
 * booking forms render. Loops the rooms and delegates each room's cards to
 * booking_guest_room_body.tpl — providers that render their own per-room
 * scaffolding (novoton's price banners) include that room body directly
 * with the same gb_* parameters.
 *
 * The POST field contract is guests[room{N}_{type}_{i}][...] — identical
 * across providers so each add_to_cart parses the same structure.
 *
 * Parameters:
 *   guest_rooms       (array,  required) rooms_data — each: adults, children,
 *                                        childrenAges[], room_name, board_name.
 *   guest_num_rooms   (int,    default 1) show per-room headers when > 1.
 *   guest_label_prefix(string, default 'travel_core') langvar namespace for labels.
 *   guest_extra_class (string, default '') optional provider CSS hook on the wrapper.
 *   show_adult_dob    (bool,   default true) render an adult DOB field.
 *   adult_dob_required(bool,   default false) mark the adult DOB required
 *                                        (package/circuit manifests need it;
 *                                        hotel adult DOB stays optional).
 *   child_dob_required(bool,   default true) mark child DOB required.
 *   guard_expected_ages(bool,  default false) stamp data-expected-age on child
 *                                        DOB inputs so the shared JS blocks a
 *                                        DOB that contradicts the searched age
 *                                        (fixed-price providers like sphinx).
 *   guest_roomless    (bool,   default false) participant mode (experiences):
 *                                        field names guests[adult_{i}] /
 *                                        guests[child_{i}] with NO room{N}_
 *                                        prefix and NO [room] hidden — these
 *                                        key names reach the provider API, so
 *                                        the wire contract must not change.
 *                                        Room headers never render.
 *   guest_prefill     (map,    default []) name/DOB prefill keyed like the
 *                                        input names (edit mode only).
 *   guest_gender_options       (map, default []) value => label: REQUIRED
 *                                        gender radios for each adult.
 *   guest_child_gender_options (map, default []) the same for each child
 *                                        (sphinx packages: m/f, every traveller).
 *
 * @package TravelCore
 *}
{$_gp = $guest_label_prefix|default:'travel_core'}
{$_show_adult_dob = $show_adult_dob|default:true}
{$_child_dob_required = $child_dob_required|default:true}
{$_num_rooms = $guest_num_rooms|default:1}
{$_extra_class = $guest_extra_class|default:''}
{$_roomless = $guest_roomless|default:false}
{* Optional name prefill (edit mode): map keyed like the input names —
   "{npfx}adult_1" / "{npfx}child_2" => [last_name, first_name, dob]. Empty
   by default, so non-edit renders are byte-identical. *}
{$_prefill = $guest_prefill|default:[]}

{foreach $guest_rooms as $room_idx => $room}
    {assign var="room_num" value=$room_idx+1}

    {* Room header — only when the booking spans more than one room *}
    {if $_num_rooms > 1 && !$_roomless}
        <div class="travel-room-header {$_extra_class}">
            <strong>{__("`$_gp`.room")|default:"Room"} {$room_num}</strong>
            {if $room.room_name} &mdash; {$room.room_name|escape:html}{/if}
            {if $room.board_name} ({$room.board_name|escape:html}){/if}
        </div>
    {/if}

    {include file="addons/travel_core/components/booking_guest_room_body.tpl"
        gb_room=$room
        gb_room_num=$room_num
        gb_room_idx=$room_idx
        gb_label_prefix=$_gp
        gb_prefill=$_prefill
        gb_roomless=$_roomless
        gb_show_adult_dob=$_show_adult_dob
        gb_adult_dob_required=$adult_dob_required|default:false
        gb_child_dob_required=$_child_dob_required
        gb_guard_expected_ages=$guard_expected_ages|default:false
        gb_gender_options=$guest_gender_options|default:[]
        gb_child_gender_options=$guest_child_gender_options|default:[]
        gb_extra_class=$_extra_class}
{/foreach}
