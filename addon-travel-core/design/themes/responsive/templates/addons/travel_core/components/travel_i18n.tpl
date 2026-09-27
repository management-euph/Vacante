{*
 * Travel Core — shared client-side i18n emitter.
 *
 * THE single source of the translation map consumed by travel_core's booking
 * JS (dob-validation.js, booking-form-validation.js, multiroom-booking.js,
 * the React engine): they all resolve strings via
 *   window.TravelTranslations || window.NovotonTranslations
 * This partial writes both (NovotonTranslations is kept as a back-compat
 * alias). It replaces the hand-maintained window.NovotonTranslations blocks
 * that had been copy-pasted — and had drifted — across novoton search.tpl,
 * booking_form.tpl and blocks/homepage_booking.tpl (×2 themes).
 *
 * Keys use the historical novoton_holidays.* language ids with English
 * |default: fallbacks, so a sphinx-only install (no novoton keys) degrades
 * gracefully to English rather than breaking.
 *
 * Currency/coefficient are per-request display values — pass them from the
 * booking page:
 *   {include file="addons/travel_core/components/travel_i18n.tpl"
 *            travel_i18n_currency=$novoton_display_symbol
 *            travel_i18n_coeff=$novoton_display_coefficient}
 * They default to the store's primary currency / 1 when omitted.
 *
 * @package TravelCore
 *}
<script>
window.TravelTranslations = Object.assign(window.TravelTranslations || {ldelim}{rdelim}, {ldelim}
    availability: "{__('novoton_holidays.availability')|default:'Availability'}",
    bookYourStay: "{__('novoton_holidays.book_your_stay')|default:'Book Your Stay'}",
    checkIn: "{__('novoton_holidays.check_in')|default:'Check-in'}",
    checkOut: "{__('novoton_holidays.check_out')|default:'Check-out'}",
    selectDates: "{__('novoton_holidays.select_dates')|default:'Select dates'}",
    guests: "{__('novoton_holidays.guests')|default:'Guests'}",
    adult: "{__('novoton_holidays.adult')|default:'adult'}",
    adults: "{__('novoton_holidays.adults')|default:'adults'}",
    children: "{__('novoton_holidays.children')|default:'children'}",
    rooms: "{__('novoton_holidays.rooms')|default:'rooms'}",
    done: "{__('novoton_holidays.done')|default:'Done'}",
    room: "{__('novoton_holidays.room')|default:'Room'}",
    search: "{__('novoton_holidays.search')|default:'Search'}",
    addRoom: "{__('novoton_holidays.add_room')|default:'Add Room'}",
    childrenAges: "{__('novoton_holidays.childrens_ages')|default:"Children's ages"}",
    selectAge: "{__('novoton_holidays.select_age')|default:'Select age'}",
    yearsOld: "{__('novoton_holidays.years_old')|default:'years old'}",
    yearOld: "{__('novoton_holidays.year_old')|default:'year old'}",
    night: "{__('novoton_holidays.night')|default:'night'}",
    nights: "{__('novoton_holidays.nights')|default:'nights'}",
    applyChanges: "{__('novoton_holidays.apply_changes')|default:'Apply changes'}",
    selected: "{__('novoton_holidays.selected')|default:'selected'}",
    january: "{__('novoton_holidays.january')|default:'January'}",
    february: "{__('novoton_holidays.february')|default:'February'}",
    march: "{__('novoton_holidays.march')|default:'March'}",
    april: "{__('novoton_holidays.april')|default:'April'}",
    may: "{__('novoton_holidays.may')|default:'May'}",
    june: "{__('novoton_holidays.june')|default:'June'}",
    july: "{__('novoton_holidays.july')|default:'July'}",
    august: "{__('novoton_holidays.august')|default:'August'}",
    september: "{__('novoton_holidays.september')|default:'September'}",
    october: "{__('novoton_holidays.october')|default:'October'}",
    november: "{__('novoton_holidays.november')|default:'November'}",
    december: "{__('novoton_holidays.december')|default:'December'}",
    mon: "{__('novoton_holidays.mon')|default:'Mo'}",
    tue: "{__('novoton_holidays.tue')|default:'Tu'}",
    wed: "{__('novoton_holidays.wed')|default:'We'}",
    thu: "{__('novoton_holidays.thu')|default:'Th'}",
    fri: "{__('novoton_holidays.fri')|default:'Fr'}",
    sat: "{__('novoton_holidays.sat')|default:'Sa'}",
    sun: "{__('novoton_holidays.sun')|default:'Su'}",
    selectCheckOut: "{__('novoton_holidays.select_check_out')|default:'Select check-out date'}",
    selectedSingular: "{__('novoton_holidays.selected_singular')|default:'selected'}",
    childAge: "{__('novoton_holidays.child_age')|default:"Child's age at check-in"}",
    dobCannotBeFuture: "{__('novoton_holidays.dob_cannot_be_future')|default:'Data nasterii nu poate fi in viitor'}",
    child: "{__('novoton_holidays.child')|default:'child'}",
    childLabel: "{__('novoton_holidays.child_label')|default:'Child'}",
    ageOfChild: "{__('novoton_holidays.age_of_child')|default:'Age of child'}",
    checkInPast: "{__('novoton_holidays.check_in_past')|default:'Check-in date cannot be in the past'}",
    includesOnRequest: "{__('novoton_holidays.includes_on_request')|default:'(includes on-request)'}",
    of: "{__('novoton_holidays.of')|default:'of'}",
    pleaseSelectAllRooms: "{__('novoton_holidays.please_select_all_rooms')|default:'Please select a room type for each room'}",
    remove: "{__('novoton_holidays.remove')|default:'Remove'}",
    changeSearch: "{__('novoton_holidays.change_search')|default:'Change search'}",
    searching: "{__('novoton_holidays.searching')|default:'Searching...'}",
    available: "{__('novoton_holidays.available')|default:'Available'}",
    offer: "{__('novoton_holidays.offer')|default:'offer'}",
    offers: "{__('novoton_holidays.offers')|default:'offers'}",
    pleaseEnterDates: "{__('novoton_holidays.please_enter_dates')|default:'Please select check-in and check-out dates'}",
    selectDatesMessage: "{__('novoton_holidays.select_dates_message')|default:"Select dates to see this property's availability and prices"}",
    selectCheckIn: "{__('novoton_holidays.select_check_in')|default:'Select check-in date'}",
    selectMissingAges: "{__('novoton_holidays.select_missing_ages')|default:'Select age ([count] missing)'}",
    selectAgeForOneChild: "{__('novoton_holidays.select_age_for_one_child')|default:'Select age for 1 child (Room [rooms]).'}",
    selectAgeForChildren: "{__('novoton_holidays.select_age_for_children')|default:'Select age for [count] children (Room [rooms]).'}",
    whereAreYouGoing: "{__('novoton_holidays.where_going')|default:'Where are you going?'}",
    priceIncreased: '{__("novoton_holidays.price_increased")|default:"Price increased"|escape:"javascript"}',
    priceDecreased: '{__("novoton_holidays.price_decreased")|default:"Price decreased"|escape:"javascript"}',
    priceRecalculating: '{__("novoton_holidays.price_recalculating")|default:"Recalculating price..."|escape:"javascript"}',
    priceUpdated: '{__("novoton_holidays.price_updated")|default:"Pretul a fost actualizat:"|escape:"javascript"}',
    ageLabel: '{__("novoton_holidays.age_label")|default:"ani"|escape:"javascript"}',
    ageLabelSingular: '{__("novoton_holidays.age_label_singular")|default:"an"|escape:"javascript"}',
    roomChangedTitle: '{__("novoton_holidays.room_changed_title")|default:"Camera s-a modificat"|escape:"javascript"}',
    roomChangedDueToAge: '{__("novoton_holidays.room_changed_due_to_age")|default:"Camera selectata nu este disponibila pentru varsta copilului introdusa."|escape:"javascript"}',
    originalRoom: '{__("novoton_holidays.original_room")|default:"Camera selectata"|escape:"javascript"}',
    newRoom: '{__("novoton_holidays.new_room")|default:"Camera noua"|escape:"javascript"}',
    priceChange: '{__("novoton_holidays.price_change")|default:"Modificare pret"|escape:"javascript"}',
    goBackToSearch: '{__("novoton_holidays.go_back_to_search")|default:"Inapoi la cautare"|escape:"javascript"}',
    continueWithNewRoom: '{__("novoton_holidays.continue_with_new_room")|default:"Continua cu noua camera"|escape:"javascript"}',
    roomUpdated: '{__("novoton_holidays.room_updated")|default:"Camera a fost actualizata:"|escape:"javascript"}',
    invalidDobFormat: '{__("novoton_holidays.invalid_dob_format")|default:"Format invalid. Folositi ZZ/LL/AAAA"|escape:"javascript"}',
    invalidDay: '{__("novoton_holidays.invalid_day")|default:"Ziua trebuie sa fie intre 1 si 31"|escape:"javascript"}',
    invalidMonth: '{__("novoton_holidays.invalid_month")|default:"Luna trebuie sa fie intre 1 si 12"|escape:"javascript"}',
    invalidYear: '{__("novoton_holidays.invalid_year")|default:"An invalid"|escape:"javascript"}',
    futureDate: '{__("novoton_holidays.future_date")|default:"Data nu poate fi in viitor"|escape:"javascript"}',
    notChild: '{__("novoton_holidays.not_child")|default:"La check-in, copilul va avea"|escape:"javascript"}',
    mustBeUnder18: '{__("travel_core.must_be_under_18")|default:"Trebuie sa fie sub 18 ani."|escape:"javascript"}',
    fieldRequired: '{__("travel_core.field_required")|escape:"javascript"}',
    chooseOption: '{__("travel_core.choose_option")|escape:"javascript"}',
    fieldRequiredNamed: '{__("travel_core.field_required_named")|escape:"javascript"}',
    chooseBetween: '{__("travel_core.choose_between")|escape:"javascript"}',
    nightsMany: '{__("novoton_holidays.nights_many")|default:"nights"|escape:"javascript"}',
    loading: '{__("novoton_holidays.loading")|default:"Loading..."|escape:"javascript"}',
    calendarPriceFooter: '{__("novoton_holidays.calendar_price_footer")|default:"Approximate prices in %s for a 1-night stay"|escape:"javascript"}',
    childAgeMismatch: '{__("travel_core.child_age_mismatch")|default:"The child [guest] will be [actual] years old at check-in, but the offer was priced for age [declared]. The search was re-run with the correct ages — please choose an offer again."|escape:"javascript"}',
    currency: '{$travel_i18n_currency|default:$smarty.const.CART_PRIMARY_CURRENCY|escape:"javascript"}',
    currencyCoeff: {$travel_i18n_coeff|default:1},
    {* Round hotel prices (travel_core setting): client-side price writes must
       mirror the server formatter — whole numbers when on. *}
    roundPrices: {if $addons.travel_core.round_prices == 'Y'}true{else}false{/if}
{rdelim});
{* Back-compat alias — legacy code reads window.NovotonTranslations directly *}
window.NovotonTranslations = window.TravelTranslations;
</script>
