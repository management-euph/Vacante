<?php

declare(strict_types=1);

/**
 * Runtime-seeded language keys for travel_core.
 *
 * Read by fn_travel_core_seed_language_keys() (func.php), which UPSERTs every
 * entry into ?:language_values whenever this file or addon.xml changes (hash
 * probe in init.php). That is what lets NEW or CHANGED labels reach stores that
 * are already installed — CS-Cart only imports addon.xml/.po at install time.
 *
 * addon.xml entries are merged on top of this file, so a key declared in both
 * takes its value from addon.xml. Keep the two in sync when a key exists in
 * both; use this file alone for runtime-only keys.
 *
 * Shape: 'lang.key' => ['en' => '...', 'ro' => '...']
 */

return [
    // BARE key by CS-Cart convention: the Appearance "Product detailed page
    // view" dropdown labels each blocks/product_templates/<file>.tpl with the
    // lang var named exactly after the file (no addon prefix).
    'travelcore_template' => [
        'en' => 'Travel Core Template',
        'ro' => 'Travel Core Template',
    ],
    // Reserve button on the Travel Core product template (replaces
    // add-to-cart for hotel products; anchors to the availability search).
    'travel_core.reserve_now' => [
        'en' => 'Reserve',
        'ro' => 'Rezervați acum',
    ],
    // Guest picker (React booking engine). The JS keys childrenAges / childNAge
    // resolve to these via the booking_config controller and functions/hotels.php.
    'travel_core.childrens_ages' => [
        'en' => "Children's ages at check-in",
        'ro' => 'Vârsta copilului la check-in',
    ],
    'travel_core.child_n_age' => [
        'en' => 'Select child [n] age',
        'ro' => 'Selectează vârsta copilului [n]',
    ],

    // Admin labels seeded here (not just addon.xml/.po) so the init.php hash
    // probe reseeds them on every store's next admin load — CS-Cart imports
    // addon.xml/.po only at install, so a label added later renders raw
    // ("_travel_core.hotel_name") on already-installed/upgraded stores until a
    // lang_keys.php change bumps the seed hash. Enforced by
    // Schema/AdminLangKeysSeededTest (every {__("travel_core.X")} in a backend
    // template must live in addon.xml or here).
    'travel_core.hotel_name' => [
        'en' => 'Hotel name',
        'ro' => 'Nume hotel',
    ],
    'travel_core.back' => [
        'en' => 'Back',
        'ro' => 'Înapoi',
    ],
    'travel_core.theme_default' => [
        'en' => '(theme default)',
        'ro' => '(implicit temă)',
    ],
    'travel_core.reset_to_default' => [
        'en' => 'Reset to default',
        'ro' => 'Resetare la implicit',
    ],
    'travel_core.default' => [
        'en' => 'Default',
        'ro' => 'Implicit',
    ],
    'travel_core.inherited_from_theme' => [
        'en' => 'Inherited from theme',
        'ro' => 'Moștenit din temă',
    ],
    'travel_core.appearance_preview' => [
        'en' => 'Live Preview',
        'ro' => 'Previzualizare',
    ],
    'travel_core.color_danger' => [
        'en' => 'Error / validation',
        'ro' => 'Eroare / validare',
    ],
    'travel_core.appearance_page_title' => [
        'en' => 'Booking Form Colors',
        'ro' => 'Culori formular rezervare',
    ],

    // Per-language SEO Templates admin (components/seo_lang_fields.tpl —
    // shared by both providers' SEO Templates pages).
    'travel_core.seo_fields_applied' => [
        'en' => 'Fields applied on import',
        'ro' => 'Câmpuri aplicate la import',
    ],
    'travel_core.seo_fields_applied_hint' => [
        'en' => 'Unchecked fields are skipped for every language.',
        'ro' => 'Câmpurile debifate sunt omise pentru toate limbile.',
    ],
    'travel_core.seo_per_language_hint' => [
        'en' => 'Each storefront language has its own template set. A language whose template was never saved uses the built-in default.',
        'ro' => 'Fiecare limbă a magazinului are propriul set de șabloane. O limbă fără șablon salvat folosește valoarea implicită.',
    ],
    // SEO Templates page (components/seo_templates_page.tpl + seo-templates.js).
    'travel_core.seo_templates_hint' => [
        'en' => 'Build each product\'s name, page title, description and URL from the hotel\'s data. Click in a field, then click a placeholder on the right to insert it.',
        'ro' => 'Construiți numele, titlul paginii, descrierea și URL-ul fiecărui produs din datele hotelului. Dați clic într-un câmp, apoi pe un placeholder din dreapta pentru a-l insera.',
    ],
    'travel_core.seo_bulk_apply_desc' => [
        'en' => 'Saves this page, then re-applies the templates to every linked product, in every language. Only the ticked fields change.',
        'ro' => 'Salvează pagina, apoi re-aplică șabloanele pe toate produsele legate, în toate limbile. Se schimbă doar câmpurile bifate.',
    ],
    'travel_core.seo_bulk_apply_confirm' => [
        'en' => 'Save this page and re-apply the templates to every linked product, in all languages?',
        'ro' => 'Salvați pagina și re-aplicați șabloanele pe toate produsele legate, în toate limbile?',
    ],
    'travel_core.seo_click_to_insert' => [
        'en' => 'Insert at the cursor',
        'ro' => 'Inserează la poziția cursorului',
    ],
    'travel_core.seo_click_to_insert_modifier' => [
        'en' => 'Add to the placeholder at the cursor',
        'ro' => 'Adaugă la placeholder-ul de la cursor',
    ],
    'travel_core.seo_tip_1' => [
        'en' => 'Lists (facilities, meal plans, rooms) show their first 3 items, separated by commas.',
        'ro' => 'Listele (facilități, tipuri de masă, camere) arată primele 3 elemente, separate prin virgulă.',
    ],
    'travel_core.seo_tip_2' => [
        'en' => 'A placeholder without a value is removed, and so is a separator left dangling.',
        'ro' => 'Un placeholder fără valoare este eliminat, la fel și separatorul rămas în plus.',
    ],
    'travel_core.seo_tip_3' => [
        'en' => 'New products get these templates when they are created. "Apply templates now" updates the existing ones.',
        'ro' => 'Produsele noi primesc aceste șabloane la creare. „Aplică șabloanele acum” actualizează produsele existente.',
    ],
    'travel_core.seo_tip_4' => [
        'en' => 'A placeholder takes one modifier. The title modifier turns "GRAND HOTEL VARNA" into "Grand Hotel Varna".',
        'ro' => 'Un placeholder primește un singur modificator. Modificatorul title transformă „GRAND HOTEL VARNA” în „Grand Hotel Varna”.',
    ],
    'travel_core.seo_modifiers_hint' => [
        'en' => 'Put the cursor on a placeholder in a field, then click a modifier.',
        'ro' => 'Puneți cursorul pe un placeholder dintr-un câmp, apoi dați clic pe un modificator.',
    ],
    'travel_core.seo_group_hotel' => [
        'en' => 'Hotel',
        'ro' => 'Hotel',
    ],
    'travel_core.seo_group_location' => [
        'en' => 'Location',
        'ro' => 'Locație',
    ],
    'travel_core.seo_group_contact' => [
        'en' => 'Contact',
        'ro' => 'Contact',
    ],
    'travel_core.seo_group_price' => [
        'en' => 'Price',
        'ro' => 'Preț',
    ],
    'travel_core.seo_group_other' => [
        'en' => 'Other',
        'ro' => 'Altele',
    ],
    'travel_core.seo_target_none' => [
        'en' => 'Click in a field, then click a placeholder to insert it at the cursor.',
        'ro' => 'Dați clic într-un câmp, apoi pe un placeholder pentru a-l insera la cursor.',
    ],
    'travel_core.seo_target_into' => [
        'en' => 'Inserting into [value]',
        'ro' => 'Se inserează în [value]',
    ],
    'travel_core.seo_target_fallback' => [
        'en' => 'No field was selected, so it went into [value]. Click in a field first to choose.',
        'ro' => 'Niciun câmp nu era selectat, așa că a fost inserat în [value]. Dați clic mai întâi în câmpul dorit.',
    ],
    'travel_core.seo_modifier_needs_field' => [
        'en' => 'Click inside a placeholder in a field first, then choose a modifier.',
        'ro' => 'Dați clic mai întâi într-un placeholder dintr-un câmp, apoi alegeți modificatorul.',
    ],
    'travel_core.seo_modifier_needs_token' => [
        'en' => 'Put the cursor inside or right after a placeholder, e.g. {{name}}, then click the modifier.',
        'ro' => 'Puneți cursorul în interiorul sau imediat după un placeholder, de ex. {{name}}, apoi dați clic pe modificator.',
    ],
    'travel_core.seo_modifier_replaced' => [
        'en' => 'Replaced [value]: a placeholder takes one modifier.',
        'ro' => 'A fost înlocuit [value]: un placeholder primește un singur modificator.',
    ],
    'travel_core.seo_restored' => [
        'en' => '[value]: the built-in default is back. Save to keep it.',
        'ro' => '[value]: s-a revenit la valoarea implicită. Salvați pentru a o păstra.',
    ],
    'travel_core.seo_counter_title' => [
        'en' => 'Length for the sample hotel. Aim for [value] characters or fewer.',
        'ro' => 'Lungimea pentru hotelul exemplu. Țintiți [value] caractere sau mai puțin.',
    ],
    'travel_core.seo_preview_kept' => [
        'en' => '(left as it is)',
        'ro' => '(rămâne neschimbat)',
    ],
    'travel_core.seo_problem_unknown_placeholder' => [
        'en' => 'Unknown placeholder [value]',
        'ro' => 'Placeholder necunoscut [value]',
    ],
    'travel_core.seo_problem_unknown_modifier' => [
        'en' => 'Unknown modifier [value]',
        'ro' => 'Modificator necunoscut [value]',
    ],
    'travel_core.seo_problem_one_modifier' => [
        'en' => 'A placeholder takes one modifier only',
        'ro' => 'Un placeholder primește un singur modificator',
    ],
    'travel_core.seo_problem_unbalanced' => [
        'en' => 'A placeholder is missing {{ or }}',
        'ro' => 'Unui placeholder îi lipsește {{ sau }}',
    ],
    'travel_core.seo_apply_field' => [
        'en' => 'Apply this field',
        'ro' => 'Aplică acest câmp',
    ],
    'travel_core.seo_apply_field_hint' => [
        'en' => 'Unticked fields are left as they are, in every language.',
        'ro' => 'Câmpurile debifate rămân neschimbate, în toate limbile.',
    ],
    'travel_core.seo_restore_default' => [
        'en' => 'Restore default',
        'ro' => 'Revino la implicit',
    ],
    'travel_core.seo_preview_sample' => [
        'en' => 'Sample hotel: [name]',
        'ro' => 'Hotel exemplu: [name]',
    ],
    'travel_core.seo_preview_none' => [
        'en' => 'The preview appears once there is a hotel to show.',
        'ro' => 'Previzualizarea apare când există un hotel de afișat.',
    ],
    // Placeholder descriptions on the SEO Templates page (fn_<addon>_seo_placeholders()).
    'travel_core.seo_ph_name' => [
        'en' => 'Hotel name',
        'ro' => 'Numele hotelului',
    ],
    'travel_core.seo_ph_raw_name' => [
        'en' => 'Name as sent by the API',
        'ro' => 'Numele din API',
    ],
    'travel_core.seo_ph_classification' => [
        'en' => 'Star rating',
        'ro' => 'Număr de stele',
    ],
    'travel_core.seo_ph_star_rating' => [
        'en' => 'Star rating',
        'ro' => 'Număr de stele',
    ],
    'travel_core.seo_ph_stars_emoji' => [
        'en' => 'Stars, e.g. ★★★★',
        'ro' => 'Stele, ex. ★★★★',
    ],
    'travel_core.seo_ph_hotel_type' => [
        'en' => 'Hotel type',
        'ro' => 'Tip hotel',
    ],
    'travel_core.seo_ph_property_type' => [
        'en' => 'Hotel / villa / apartment',
        'ro' => 'Hotel / vilă / apartament',
    ],
    'travel_core.seo_ph_rating' => [
        'en' => 'Guest rating',
        'ro' => 'Nota oaspeților',
    ],
    'travel_core.seo_ph_facilities' => [
        'en' => 'Top 3 facilities',
        'ro' => 'Primele 3 facilități',
    ],
    'travel_core.seo_ph_boards' => [
        'en' => 'Meal plans',
        'ro' => 'Tipuri de masă',
    ],
    'travel_core.seo_ph_rooms' => [
        'en' => 'Room types (first 3)',
        'ro' => 'Tipuri de cameră (primele 3)',
    ],
    'travel_core.seo_ph_code' => [
        'en' => 'Hotel code',
        'ro' => 'Codul hotelului',
    ],
    'travel_core.seo_ph_description' => [
        'en' => 'Description from the API',
        'ro' => 'Descrierea din API',
    ],
    'travel_core.seo_ph_image_url' => [
        'en' => 'Main image URL',
        'ro' => 'URL imagine principală',
    ],
    'travel_core.seo_ph_city' => [
        'en' => 'City / resort',
        'ro' => 'Oraș / stațiune',
    ],
    'travel_core.seo_ph_country' => [
        'en' => 'Country',
        'ro' => 'Țară',
    ],
    'travel_core.seo_ph_region' => [
        'en' => 'Region',
        'ro' => 'Regiune',
    ],
    'travel_core.seo_ph_city_code' => [
        'en' => 'Destination code',
        'ro' => 'Codul destinației',
    ],
    'travel_core.seo_ph_country_code' => [
        'en' => 'Country code',
        'ro' => 'Codul țării',
    ],
    'travel_core.seo_ph_address' => [
        'en' => 'Street address',
        'ro' => 'Adresa',
    ],
    'travel_core.seo_ph_latitude' => [
        'en' => 'Latitude',
        'ro' => 'Latitudine',
    ],
    'travel_core.seo_ph_longitude' => [
        'en' => 'Longitude',
        'ro' => 'Longitudine',
    ],
    'travel_core.seo_ph_phone' => [
        'en' => 'Phone number',
        'ro' => 'Număr de telefon',
    ],
    'travel_core.seo_ph_email' => [
        'en' => 'Email address',
        'ro' => 'Adresă de email',
    ],
    'travel_core.seo_ph_website' => [
        'en' => 'Website',
        'ro' => 'Site web',
    ],
    'travel_core.seo_ph_min_price' => [
        'en' => 'Lowest price found',
        'ro' => 'Cel mai mic preț găsit',
    ],
    'travel_core.seo_ph_currency' => [
        'en' => 'Price currency',
        'ro' => 'Moneda prețului',
    ],
    'travel_core.seo_ph_year' => [
        'en' => 'Current year',
        'ro' => 'Anul curent',
    ],

    // Shared hotel-identity header (components/hotel_header.tpl) — one key
    // pair for all four consuming surfaces instead of per-provider copies.
    'travel_core.location_show_map' => [
        'en' => 'Location - show map',
        'ro' => 'Locație - arată pe hartă',
    ],
    'travel_core.stars_rating' => [
        'en' => '[rating]-star rating',
        'ro' => 'Clasificare [rating] stele',
    ],

    // Shared cart booking-details card (components/cart_booking_details.tpl)
    // — one key set for both providers' cart/checkout cards.
    'travel_core.your_booking_details' => [
        'en' => 'Your booking details',
        'ro' => 'Detaliile rezervării',
    ],
    'travel_core.price_updated_badge' => [
        'en' => 'Price Updated',
        'ro' => 'Preț actualizat',
    ],
    'travel_core.price_dropped_badge' => [
        'en' => 'Price Dropped!',
        'ro' => 'Preț redus!',
    ],
    'travel_core.total_stay' => [
        'en' => 'Total stay',
        'ro' => 'Durata totală a sejurului',
    ],
    'travel_core.for' => [
        'en' => 'for',
        'ro' => 'pentru',
    ],
    'travel_core.occupancy' => [
        'en' => 'Occupancy',
        'ro' => 'Turiști',
    ],
    'travel_core.guest_names' => [
        'en' => 'Guest names',
        'ro' => 'Nume turiști',
    ],
    'travel_core.holder' => [
        'en' => 'Holder',
        'ro' => 'Lider rezervare',
    ],
    'travel_core.meal_plan' => [
        'en' => 'Meal plan',
        'ro' => 'Masă',
    ],
    'travel_core.save_changes' => [
        'en' => 'Save changes',
        'ro' => 'Salvează modificările',
    ],
    'travel_core.guest_details_invalid' => [
        'en' => 'Please fill in all guest names.',
        'ro' => 'Vă rugăm completați numele tuturor turiștilor.',
    ],

    // Booking-form summary sidebar (components/booking_sidebar.tpl) — the
    // shared left column of both providers' booking pages.
    'travel_core.available' => [
        'en' => 'Available',
        'ro' => 'Disponibil',
    ],
    'travel_core.on_request' => [
        'en' => 'On request',
        'ro' => 'La cerere',
    ],
    'travel_core.package' => [
        'en' => 'Package',
        'ro' => 'Pachet',
    ],
    'travel_core.you_selected' => [
        'en' => 'You selected',
        'ro' => 'Ați selectat',
    ],
    // CS-Cart plural forms ("[n] singular|[n] plural"): the count picks the
    // form, so Romanian keeps its own singular/plural nouns.
    'travel_core.n_nights' => [
        'en' => '[n] night|[n] nights',
        'ro' => '[n] noapte|[n] nopți|[n] de nopți',
    ],
    'travel_core.n_rooms' => [
        'en' => '[n] room|[n] rooms',
        'ro' => '[n] cameră|[n] camere|[n] de camere',
    ],
    'travel_core.n_adults' => [
        'en' => '[n] adult|[n] adults',
        'ro' => '[n] adult|[n] adulți|[n] de adulți',
    ],
    'travel_core.n_children' => [
        'en' => '[n] child|[n] children',
        'ro' => '[n] copil|[n] copii|[n] de copii',
    ],
    // The sentence that joins them, so word order and the connector ("and" /
    // "și") stay translatable instead of being concatenated in code.
    'travel_core.selected_line' => [
        'en' => '[nights], [rooms] for [adults]',
        'ro' => '[nights], [rooms] pentru [adults]',
    ],
    'travel_core.selected_line_with_children' => [
        'en' => '[nights], [rooms] for [adults] and [children]',
        'ro' => '[nights], [rooms] pentru [adults] și [children]',
    ],
    'travel_core.change_selection' => [
        'en' => 'Change your selection',
        'ro' => 'Schimbați-vă opțiunea',
    ],
    'travel_core.original_price' => [
        'en' => 'Original price',
        'ro' => 'Preț inițial',
    ],
    'travel_core.cancel_cost_title' => [
        'en' => 'How much will it cost to cancel?',
        'ro' => 'Cât costă să anulez?',
    ],
    'travel_core.cancel_you_will_pay' => [
        'en' => "If you cancel, you'll pay",
        'ro' => 'Dacă anulați, veți plăti',
    ],
    // "What are my booking conditions?" — the link under Add-to-Cart and its
    // modal (components/booking_conditions_modal.tpl).
    'travel_core.booking_conditions_link' => [
        'en' => 'What are my booking conditions?',
        'ro' => 'Care sunt condițiile rezervării mele?',
    ],
    'travel_core.booking_conditions_loading' => [
        'en' => 'The booking conditions are being confirmed with the provider…',
        'ro' => 'Condițiile rezervării se confirmă cu operatorul…',
    ],
    'travel_core.close' => [
        'en' => 'Close',
        'ro' => 'Închide',
    ],
    // The green line at the top of the cancellation card — same wording the
    // search-results card uses, so the promise reads identically end to end.
    'travel_core.free_cancellation_until' => [
        'en' => 'Free cancellation until',
        'ro' => 'Anulare gratuită până la',
    ],
    'travel_core.gender' => [
        'en' => 'Gender',
        'ro' => 'Gen',
    ],
    // Shared booking page: progress bar, availability, facilities, price,
    // cancellation & payment timeline, guest form and CTA.
    'travel_core.status_instant' => [
        'en' => 'Instant confirmation',
        'ro' => 'Confirmare instantă',
    ],
    'travel_core.status_stop_sale' => [
        'en' => 'Not bookable (stop sale)',
        'ro' => 'Nu se poate rezerva (stop vânzare)',
    ],
    'travel_core.show_less' => [
        'en' => 'Show less',
        'ro' => 'Mai puțin',
    ],
    'travel_core.n_more' => [
        'en' => '+[n] more',
        'ro' => '+[n] în plus',
    ],
    'travel_core.per_night_line' => [
        'en' => '[nights] · ≈ [price] / night',
        'ro' => '[nights] · ≈ [price] / noapte',
    ],
    'travel_core.cancel_payment_title' => [
        'en' => 'Cancellation & payment',
        'ro' => 'Anulare și plată',
    ],
    'travel_core.no_show' => [
        'en' => 'No-show',
        'ro' => 'Neprezentare',
    ],
    'travel_core.until_date' => [
        'en' => 'Until [date]',
        'ro' => 'Până la [date]',
    ],
    'travel_core.from_date' => [
        'en' => 'From [date]',
        'ro' => 'De la [date]',
    ],
    'travel_core.until_check_in' => [
        'en' => 'Until check-in',
        'ro' => 'Până la check-in',
    ],
    'travel_core.today' => [
        'en' => 'Today',
        'ro' => 'Azi',
    ],
    'travel_core.timeline_free' => [
        'en' => 'Free cancellation',
        'ro' => 'Anulare gratuită',
    ],
    'travel_core.you_pay' => [
        'en' => 'You pay',
        'ro' => 'Plătiți',
    ],
    'travel_core.due_now' => [
        'en' => 'now',
        'ro' => 'acum',
    ],
    'travel_core.due_by' => [
        'en' => 'by [date]',
        'ro' => 'până la [date]',
    ],
    'travel_core.booking_progress' => [
        'en' => 'Booking progress',
        'ro' => 'Etapele rezervării',
    ],
    'travel_core.step_search' => [
        'en' => 'Search',
        'ro' => 'Căutare',
    ],
    'travel_core.step_guest_details' => [
        'en' => 'Guest details',
        'ro' => 'Datele turiștilor',
    ],
    'travel_core.step_checkout' => [
        'en' => 'Checkout',
        'ro' => 'Finalizare comandă',
    ],
    'travel_core.step_confirmation' => [
        'en' => 'Confirmation',
        'ro' => 'Confirmare',
    ],
    'travel_core.guest_names_hint' => [
        'en' => 'Enter each name exactly as it appears on the guest\'s ID card or passport.',
        'ro' => 'Introduceți fiecare nume exact ca în actul de identitate sau pașaportul turistului.',
    ],
    'travel_core.guest_adult' => [
        'en' => 'Adult',
        'ro' => 'Adult',
    ],
    'travel_core.guest_child' => [
        'en' => 'Child',
        'ro' => 'Copil',
    ],
    'travel_core.dob_placeholder' => [
        'en' => 'DD/MM/YYYY',
        'ro' => 'ZZ/LL/AAAA',
    ],
    'travel_core.gender_male' => [
        'en' => 'Male',
        'ro' => 'Masculin',
    ],
    'travel_core.gender_female' => [
        'en' => 'Female',
        'ro' => 'Feminin',
    ],
    'travel_core.continue_to_checkout' => [
        'en' => 'Continue to checkout',
        'ro' => 'Continuă spre finalizare',
    ],
    // Booking button (default): the next step on the progress bar is Payment.
    'travel_core.continue_to_payment' => [
        'en' => 'Continue to payment',
        'ro' => 'Continuă spre plată',
    ],
    // Booking button when Settings -> "Skip the cart page for" ticks the add-on.
    'travel_core.continue_booking' => [
        'en' => 'Continue booking',
        'ro' => 'Continuă rezervarea',
    ],
    'travel_core.field_required' => [
        'en' => 'Please fill in this field.',
        'ro' => 'Vă rugăm să completați acest câmp.',
    ],
    'travel_core.choose_option' => [
        'en' => 'Please choose one option.',
        'ro' => 'Vă rugăm să alegeți o opțiune.',
    ],
    'travel_core.cta_note' => [
        'en' => 'You won\'t be charged yet — you\'ll review everything at checkout.',
        'ro' => 'Nu plătiți încă — veți verifica totul la finalizarea comenzii.',
    ],
    'travel_core.field_required_named' => [
        'en' => 'Please fill in the [field] field.',
        'ro' => 'Vă rugăm să completați câmpul [field].',
    ],
    'travel_core.choose_between' => [
        'en' => 'Please choose [a] or [b].',
        'ro' => 'Vă rugăm să alegeți [a] sau [b].',
    ],
    'travel_core.facility_unnamed' => [
        'en' => 'Facility #[code]',
        'ro' => 'Facilitate #[code]',
    ],
    'travel_core.who_is_staying' => [
        'en' => 'Who is staying?',
        'ro' => 'Cine se cazează?',
    ],
    'travel_core.step_guests' => [
        'en' => 'Guests',
        'ro' => 'Oaspeți',
    ],
    'travel_core.step_payment' => [
        'en' => 'Payment',
        'ro' => 'Plată',
    ],
    'travel_core.step_search_back' => [
        'en' => 'Back to your search results',
        'ro' => 'Înapoi la rezultatele căutării',
    ],
    'travel_core.step_locked_hint' => [
        'en' => 'Enter the guest details first',
        'ro' => 'Completați mai întâi datele oaspeților',
    ],
    'travel_core.step_current' => [
        'en' => 'current step',
        'ro' => 'pasul curent',
    ],
    'travel_core.fm_alias_gaps_title' => [
        'en' => 'Provider values that are never mapped',
        'ro' => 'Valori ale furnizorilor care nu sunt mapate niciodată',
    ],
    'travel_core.fm_alias_gap' => [
        'en' => '[provider] has no aliases for [feature]: its hotels get no value for this feature.',
        'ro' => '[provider] nu are aliasuri pentru [feature]: hotelurile sale nu primesc nicio valoare pentru această caracteristică.',
    ],
    'travel_core.fm_alias_gap_open' => [
        'en' => 'Open',
        'ro' => 'Deschide',
    ],
    'travel_core.fm_alias_gaps_hint' => [
        'en' => 'Each provider re-seeds its aliases on the first admin page load after an update, and on its hotel syncs; then run its "reassign features" job so existing hotels get the values.',
        'ro' => 'Fiecare furnizor își recreează aliasurile la prima încărcare a unei pagini de administrare după o actualizare și la sincronizarea hotelurilor; apoi rulați jobul „reassign features” ca hotelurile existente să primească valorile.',
    ],
    'travel_core.fm_intro' => [
        'en' => 'How each provider\'s values (stars, meals, facilities…) become CS-Cart product features. A value with no alias for its provider is never assigned.',
        'ro' => 'Cum devin valorile fiecărui furnizor (stele, masă, facilități…) caracteristici de produs în CS-Cart. O valoare fără alias pentru furnizorul ei nu este atribuită niciodată.',
    ],
    'travel_core.fm_tile_mappings' => [
        'en' => 'Mappings',
        'ro' => 'Mapări',
    ],
    'travel_core.fm_tile_mappings_hint' => [
        'en' => '[count] active',
        'ro' => '[count] active',
    ],
    'travel_core.fm_tile_no_variant' => [
        'en' => 'No CS-Cart variant',
        'ro' => 'Fără variantă CS-Cart',
    ],
    'travel_core.fm_tile_no_variant_hint' => [
        'en' => 'never shown on products',
        'ro' => 'nu apar niciodată pe produse',
    ],
    'travel_core.fm_tile_aliases' => [
        'en' => 'Provider aliases',
        'ro' => 'Aliasuri furnizori',
    ],
    'travel_core.fm_tile_aliases_hint' => [
        'en' => 'across [count] providers',
        'ro' => 'la [count] furnizori',
    ],
    'travel_core.fm_tile_raw' => [
        'en' => 'Unmapped raw values',
        'ro' => 'Valori brute nemapate',
    ],
    'travel_core.fm_tile_raw_hint' => [
        'en' => 'waiting for review',
        'ro' => 'așteaptă verificarea',
    ],
    'travel_core.fm_needs_attention' => [
        'en' => 'Needs attention',
        'ro' => 'Necesită atenție',
    ],
    'travel_core.fm_attention_no_feature' => [
        'en' => '[feature]: no CS-Cart feature selected; [count] values wait for a variant.',
        'ro' => '[feature]: nicio caracteristică CS-Cart selectată; [count] valori așteaptă o variantă.',
    ],
    'travel_core.fm_attention_choose_feature' => [
        'en' => 'Choose feature',
        'ro' => 'Alegeți caracteristica',
    ],
    'travel_core.fm_auto_hint' => [
        'en' => 'Discovered from provider data',
        'ro' => 'Descoperite din datele furnizorului',
    ],
    'travel_core.fm_batch_size' => [
        'en' => 'Hotels per batch',
        'ro' => 'Hoteluri pe lot',
    ],
    'travel_core.fm_col_feature' => [
        'en' => 'Feature',
        'ro' => 'Caracteristică',
    ],
    'travel_core.fm_col_mapped' => [
        'en' => 'Mapped',
        'ro' => 'Mapate',
    ],
    'travel_core.fm_col_no_variant' => [
        'en' => 'No variant',
        'ro' => 'Fără variantă',
    ],
    'travel_core.fm_col_auto' => [
        'en' => 'Auto',
        'ro' => 'Auto',
    ],
    'travel_core.fm_col_cs_feature' => [
        'en' => 'CS-Cart feature',
        'ro' => 'Caracteristică CS-Cart',
    ],
    'travel_core.fm_col_provider_aliases' => [
        'en' => 'Provider aliases',
        'ro' => 'Aliasuri furnizori',
    ],
    'travel_core.fm_col_values' => [
        'en' => 'Values each provider sends',
        'ro' => 'Valorile trimise de fiecare furnizor',
    ],
    'travel_core.fm_chip_count' => [
        'en' => '[count] aliases',
        'ro' => '[count] aliasuri',
    ],
    'travel_core.fm_chip_none' => [
        'en' => '[provider]: none',
        'ro' => '[provider]: niciunul',
    ],
    'travel_core.fm_chip_unused' => [
        'en' => '[provider] · not used',
        'ro' => '[provider] · nefolosit',
    ],
    'travel_core.fm_derived' => [
        'en' => 'Derived from facilities',
        'ro' => 'Derivat din facilități',
    ],
    'travel_core.fm_not_configured' => [
        'en' => 'Not configured',
        'ro' => 'Neconfigurat',
    ],
    'travel_core.fm_raw_title' => [
        'en' => 'Raw values not mapped yet',
        'ro' => 'Valori brute încă nemapate',
    ],
    'travel_core.fm_raw_text' => [
        'en' => '[count] values sent by providers match no alias.',
        'ro' => '[count] valori trimise de furnizori nu corespund niciunui alias.',
    ],
    'travel_core.fm_raw_none' => [
        'en' => 'Every scanned value is mapped.',
        'ro' => 'Toate valorile scanate sunt mapate.',
    ],
    'travel_core.fm_review' => [
        'en' => 'Review [count]',
        'ro' => 'Verificați [count]',
    ],
    'travel_core.fm_scan' => [
        'en' => 'Scan',
        'ro' => 'Scanează',
    ],
    'travel_core.fm_list_count' => [
        'en' => '[count] mappings',
        'ro' => '[count] mapări',
    ],
    'travel_core.fm_list_assigned' => [
        'en' => 'assigned to CS-Cart feature [feature]',
        'ro' => 'atribuite caracteristicii CS-Cart [feature]',
    ],
    'travel_core.fm_list_no_variant' => [
        'en' => '[count] without a CS-Cart variant',
        'ro' => '[count] fără variantă CS-Cart',
    ],
    'travel_core.fm_list_missing_title' => [
        'en' => '[provider] has no aliases here.',
        'ro' => '[provider] nu are aliasuri aici.',
    ],
    'travel_core.fm_list_missing_text' => [
        'en' => 'Its values never become [feature], so its hotels get none.',
        'ro' => 'Valorile sale nu devin niciodată [feature], așa că hotelurile sale nu primesc nimic.',
    ],
    'travel_core.fm_list_missing_manual' => [
        'en' => 'Add them on each mapping\'s page.',
        'ro' => 'Adăugați-le pe pagina fiecărei mapări.',
    ],
    'travel_core.fm_reseed_provider' => [
        'en' => 'Re-seed [provider] aliases',
        'ro' => 'Recreează aliasurile [provider]',
    ],
    'travel_core.fm_aliases_reseeded' => [
        'en' => '[provider]\'s aliases were re-seeded. If a value is still missing, add it on the mapping\'s page, then run the provider\'s "reassign features" job.',
        'ro' => 'Aliasurile [provider] au fost recreate. Dacă o valoare încă lipsește, adăugați-o pe pagina mapării, apoi rulați jobul „reassign features” al furnizorului.',
    ],
    'travel_core.fm_search_placeholder' => [
        'en' => 'Search code, name or provider value',
        'ro' => 'Căutați cod, nume sau valoare furnizor',
    ],
    'travel_core.fm_filter_provider' => [
        'en' => 'Provider',
        'ro' => 'Furnizor',
    ],
    'travel_core.fm_filter_all_providers' => [
        'en' => 'All providers',
        'ro' => 'Toți furnizorii',
    ],
    'travel_core.fm_filter_has' => [
        'en' => '[provider]: has a value',
        'ro' => '[provider]: are valoare',
    ],
    'travel_core.fm_filter_missing' => [
        'en' => '[provider]: missing a value',
        'ro' => '[provider]: fără valoare',
    ],
    'travel_core.fm_filter_any_status' => [
        'en' => 'Any status',
        'ro' => 'Orice stare',
    ],
    'travel_core.fm_created_by' => [
        'en' => 'Created by',
        'ro' => 'Creat de',
    ],
    'travel_core.fm_created_by_seed' => [
        'en' => 'Travel Core defaults',
        'ro' => 'Valori implicite Travel Core',
    ],
    'travel_core.fm_created_by_auto' => [
        'en' => 'Discovered from provider data',
        'ro' => 'Descoperit din datele furnizorului',
    ],
    'travel_core.fm_created_by_manual' => [
        'en' => 'An admin',
        'ro' => 'Un administrator',
    ],
    'travel_core.fm_select_all' => [
        'en' => 'Select all',
        'ro' => 'Selectează tot',
    ],
    'travel_core.fm_variant_kept' => [
        'en' => 'Kept: automatic matching never changes it',
        'ro' => 'Păstrată: potrivirea automată nu o schimbă niciodată',
    ],
    'travel_core.fm_no_variant' => [
        'en' => 'none',
        'ro' => 'niciuna',
    ],
    'travel_core.fm_no_alias' => [
        'en' => 'no alias',
        'ro' => 'fără alias',
    ],
    'travel_core.fm_edit_shop_title' => [
        'en' => 'What the shop shows',
        'ro' => 'Ce afișează magazinul',
    ],
    'travel_core.fm_keep_variant' => [
        'en' => 'Keep this variant: automatic matching never changes it',
        'ro' => 'Păstrează această variantă: potrivirea automată nu o schimbă niciodată',
    ],
    'travel_core.fm_advanced' => [
        'en' => 'Advanced',
        'ro' => 'Avansat',
    ],
    'travel_core.fm_last_used' => [
        'en' => 'Last used by a sync: [date]',
        'ro' => 'Ultima folosire la o sincronizare: [date]',
    ],
    'travel_core.fm_edit_providers_title' => [
        'en' => 'What each provider sends for “[name]”',
        'ro' => 'Ce trimite fiecare furnizor pentru „[name]”',
    ],
    'travel_core.fm_edit_providers_hint' => [
        'en' => 'A hotel gets this feature only when its provider\'s value matches one of these aliases.',
        'ro' => 'Un hotel primește această caracteristică doar când valoarea furnizorului său corespunde unuia dintre aceste aliasuri.',
    ],
    'travel_core.fm_card_count' => [
        'en' => '[count] aliases',
        'ro' => '[count] aliasuri',
    ],
    'travel_core.fm_card_missing' => [
        'en' => 'No alias: [provider] hotels never get this value',
        'ro' => 'Fără alias: hotelurile [provider] nu primesc niciodată această valoare',
    ],
    'travel_core.fm_card_unused' => [
        'en' => 'Not used for [feature]',
        'ro' => 'Nefolosit pentru [feature]',
    ],
    'travel_core.fm_match_exact' => [
        'en' => 'matches exactly',
        'ro' => 'corespunde exact',
    ],
    'travel_core.fm_match_prefix' => [
        'en' => 'starts with',
        'ro' => 'începe cu',
    ],
    'travel_core.fm_match_contains' => [
        'en' => 'contains',
        'ro' => 'conține',
    ],
    'travel_core.fm_alias_delete_confirm' => [
        'en' => 'Remove this alias?',
        'ro' => 'Eliminați acest alias?',
    ],
    'travel_core.fm_remove' => [
        'en' => 'Remove',
        'ro' => 'Elimină',
    ],
    'travel_core.fm_remove_alias' => [
        'en' => 'Remove alias [value]',
        'ro' => 'Elimină aliasul [value]',
    ],
    'travel_core.fm_add_anyway' => [
        'en' => 'Add an alias anyway',
        'ro' => 'Adaugă totuși un alias',
    ],
    'travel_core.fm_provider_value' => [
        'en' => '[provider] value',
        'ro' => 'Valoare [provider]',
    ],
    'travel_core.fm_add_provider_alias' => [
        'en' => 'Add [provider] alias',
        'ro' => 'Adaugă alias [provider]',
    ],
    'travel_core.fm_other_source' => [
        'en' => 'Another source',
        'ro' => 'Altă sursă',
    ],
    'travel_core.fm_unmapped_intro' => [
        'en' => 'Values providers sent that match no alias of theirs: their hotels get nothing for these. Link each one to the mapping it means, create a new mapping, or dismiss it.',
        'ro' => 'Valori trimise de furnizori care nu corespund niciunui alias al lor: hotelurile lor nu primesc nimic pentru acestea. Legați fiecare valoare de maparea pe care o reprezintă, creați o mapare nouă sau ignorați-o.',
    ],
    'travel_core.fm_unmapped_search' => [
        'en' => 'Search value or name',
        'ro' => 'Căutați valoare sau nume',
    ],
    'travel_core.fm_unmapped_any_type' => [
        'en' => 'Any feature',
        'ro' => 'Orice caracteristică',
    ],
    'travel_core.fm_unmapped_any_facility' => [
        'en' => 'Any facility',
        'ro' => 'Orice facilitate',
    ],
    'travel_core.fm_unmapped_col_value' => [
        'en' => 'Value sent',
        'ro' => 'Valoare trimisă',
    ],
    'travel_core.fm_unmapped_col_hotels' => [
        'en' => 'Hotels',
        'ro' => 'Hoteluri',
    ],
    'travel_core.fm_unmapped_col_last_seen' => [
        'en' => 'Last seen',
        'ro' => 'Văzută ultima dată',
    ],
    'travel_core.fm_unmapped_col_link' => [
        'en' => 'Link to an existing mapping',
        'ro' => 'Legați de o mapare existentă',
    ],
    'travel_core.fm_unmapped_first_seen' => [
        'en' => 'First seen [date]',
        'ro' => 'Văzută prima dată [date]',
    ],
    'travel_core.fm_unmapped_choose' => [
        'en' => 'Choose a mapping…',
        'ro' => 'Alegeți o mapare…',
    ],
    'travel_core.fm_unmapped_link_to' => [
        'en' => 'Mapping for [value]',
        'ro' => 'Maparea pentru [value]',
    ],
    'travel_core.fm_unmapped_link' => [
        'en' => 'Link',
        'ro' => 'Leagă',
    ],
    'travel_core.fm_unmapped_create' => [
        'en' => 'Create new',
        'ro' => 'Creează nouă',
    ],
    'travel_core.fm_unmapped_create_hint' => [
        'en' => 'Create a new mapping named after this value',
        'ro' => 'Creează o mapare nouă cu numele acestei valori',
    ],
    'travel_core.fm_unmapped_create_confirm' => [
        'en' => 'Create a new mapping for [value]?',
        'ro' => 'Creați o mapare nouă pentru [value]?',
    ],
    'travel_core.fm_unmapped_dismiss' => [
        'en' => 'Dismiss',
        'ro' => 'Ignoră',
    ],
    'travel_core.fm_unmapped_dismiss_hint' => [
        'en' => 'Removed from this list; it comes back if the provider sends it again.',
        'ro' => 'Eliminată din listă; revine dacă furnizorul o trimite din nou.',
    ],
    'travel_core.fm_unmapped_dismiss_confirm' => [
        'en' => 'Dismiss the selected values?',
        'ro' => 'Ignorați valorile selectate?',
    ],
    'travel_core.fm_unmapped_none' => [
        'en' => 'No unmapped values: everything the providers sent is mapped.',
        'ro' => 'Nicio valoare nemapată: tot ce au trimis furnizorii este mapat.',
    ],
    'travel_core.fm_unmapped_none_filtered' => [
        'en' => 'No values match these filters.',
        'ro' => 'Nicio valoare nu corespunde acestor filtre.',
    ],
    'travel_core.fm_unmapped_linked' => [
        'en' => '[value] is now an alias of [mapping] for [provider]. Run the provider\'s "reassign features" job so existing hotels get it.',
        'ro' => '[value] este acum un alias al [mapping] pentru [provider]. Rulați jobul „reassign features” al furnizorului ca hotelurile existente să îl primească.',
    ],
    'travel_core.fm_unmapped_link_failed' => [
        'en' => 'That value or mapping no longer exists, or they are different kinds of feature.',
        'ro' => 'Valoarea sau maparea nu mai există, ori sunt tipuri diferite de caracteristici.',
    ],
    'travel_core.fm_unmapped_dismissed' => [
        'en' => 'Dismissed [count] values.',
        'ro' => '[count] valori ignorate.',
    ],
    // Booking page H1, beside the progress bar (short so both fit one row).
    'travel_core.complete_booking_title' => [
        'en' => 'Complete Booking',
        'ro' => 'Finalizează rezervarea',
    ],
    // Price card: the supplier's deposit / balance split (information only).
    'travel_core.split_deposit' => [
        'en' => 'Deposit',
        'ro' => 'Avans',
    ],
    'travel_core.split_balance' => [
        'en' => 'Balance',
        'ro' => 'Rest de plată',
    ],
    // Booking page: "Pay in full / Pay a deposit" choice (DepositPolicy).
    'travel_core.pay_mode_legend' => [
        'en' => 'How would you like to pay?',
        'ro' => 'Cum doriți să plătiți?',
    ],
    'travel_core.pay_in_full' => [
        'en' => 'Pay in full',
        'ro' => 'Plătiți integral',
    ],
    'travel_core.pay_in_full_hint' => [
        'en' => 'Nothing more to pay',
        'ro' => 'Nu mai aveți nimic de plată',
    ],
    'travel_core.pay_deposit' => [
        'en' => 'Pay a deposit',
        'ro' => 'Plătiți un avans',
    ],
    'travel_core.pay_deposit_hint' => [
        'en' => 'Balance [balance] by [date], with any of our payment methods. We email you the payment link.',
        'ro' => 'Restul de [balance] până la [date], prin oricare dintre metodele noastre de plată. Vă trimitem linkul de plată pe email.',
    ],
    'travel_core.deposit_unavailable' => [
        'en' => 'A deposit is no longer possible for this booking (the supplier\'s payment terms changed), so the full price applies.',
        'ro' => 'Pentru această rezervare nu mai este posibilă plata unui avans (termenii de plată ai furnizorului s-au schimbat), așa că se aplică prețul integral.',
    ],
    // Deposit bookings: cart, order, emails, pay-balance page (BalanceService).
    'travel_core.deposit_cart_note' => [
        'en' => 'Paid now: the deposit. You pay the balance later from the link in your order and in our reminder emails.',
        'ro' => 'Acum plătiți avansul. Restul îl plătiți mai târziu, din linkul din comandă și din emailurile de reamintire.',
    ],
    'travel_core.paid_with_deposit' => [
        'en' => 'Paid with a deposit',
        'ro' => 'Plătit cu avans',
    ],
    'travel_core.pay_balance_btn' => [
        'en' => 'Pay balance',
        'ro' => 'Plătiți restul',
    ],
    'travel_core.pay_balance_note' => [
        'en' => 'You choose how to pay at checkout.',
        'ro' => 'Alegeți modul de plată la finalizarea comenzii.',
    ],
    'travel_core.balance_paid' => [
        'en' => 'Balance paid (order #[order_id])',
        'ro' => 'Rest achitat (comanda #[order_id])',
    ],
    'travel_core.balance_cancelled' => [
        'en' => 'Balance cancelled',
        'ro' => 'Rest anulat',
    ],
    'travel_core.balance_open' => [
        'en' => 'Balance not paid yet',
        'ro' => 'Restul nu a fost încă plătit',
    ],
    'travel_core.balance_overdue' => [
        'en' => 'Overdue',
        'ro' => 'Termen depășit',
    ],
    'travel_core.balance_pay_link' => [
        'en' => 'Customer pay link',
        'ro' => 'Link de plată pentru client',
    ],
    'travel_core.balance_payment_for' => [
        'en' => 'Balance payment for order #[order_id]',
        'ro' => 'Plata restului pentru comanda #[order_id]',
    ],
    'travel_core.balance_added_to_cart' => [
        'en' => 'The balance of order #[order_id] is ready to pay. Choose how you want to pay.',
        'ro' => 'Restul de plată pentru comanda #[order_id] este pregătit. Alegeți modul de plată.',
    ],
    'travel_core.balance_link_invalid' => [
        'en' => 'This payment link is not valid.',
        'ro' => 'Acest link de plată nu este valid.',
    ],
    'travel_core.balance_already_settled' => [
        'en' => 'This balance has already been paid or is no longer due.',
        'ro' => 'Acest rest a fost deja plătit sau nu mai este datorat.',
    ],
    'travel_core.balance_order_inactive' => [
        'en' => 'The booking of this balance is no longer active. Please contact us.',
        'ro' => 'Rezervarea pentru acest rest nu mai este activă. Vă rugăm să ne contactați.',
    ],
    'travel_core.balance_reminder_subject' => [
        'en' => 'Reminder: balance of [amount] for [hotel] due by [date]',
        'ro' => 'Reamintire: restul de [amount] pentru [hotel] până la [date]',
    ],
    'travel_core.balance_reminder_body' => [
        'en' => 'Hello [name],

Thank you for your booking at [hotel] (order #[order_id]).

Total: [total]
Deposit paid: [deposit]
Balance: [amount], due by [date]

Pay it here, with any of our payment methods:
[link]

If you have already paid, please ignore this email.',
        'ro' => 'Bună ziua, [name],

Vă mulțumim pentru rezervarea la [hotel] (comanda #[order_id]).

Total: [total]
Avans plătit: [deposit]
Rest de plată: [amount], până la [date]

Îl puteți plăti aici, prin oricare dintre metodele noastre de plată:
[link]

Dacă ați plătit deja, vă rugăm să ignorați acest email.',
    ],
    // Deposit bookings: the full price beside Deposit / Balance.
    'travel_core.split_total' => [
        'en' => 'Total',
        'ro' => 'Total',
    ],
    // Order summary with a deposit (components/deposit_totals.tpl).
    'travel_core.deposit_order_total' => [
        'en' => 'Order total',
        'ro' => 'Total comandă',
    ],
    'travel_core.deposit_paid_now' => [
        'en' => 'Deposit — paid now',
        'ro' => 'Avans — plătit acum',
    ],
    'travel_core.deposit_balance_by' => [
        'en' => 'Balance — by [date]',
        'ro' => 'Rest de plată — până la [date]',
    ],
    'travel_core.deposit_pay_now_btn' => [
        'en' => 'pay [amount] now',
        'ro' => 'plătiți acum [amount]',
    ],
    // Destination picker (components/destination_picker.tpl + destination-picker.js) and the dashboard Destinations card.
    'travel_core.dest_only_sold' => [
        'en' => 'Show sold countries only',
        'ro' => 'Doar țările vândute',
    ],
    'travel_core.dest_fold_show' => [
        'en' => 'Show them',
        'ro' => 'Arată-le',
    ],
    'travel_core.dest_fold_hide' => [
        'en' => 'Fold the countries with nothing synced',
        'ro' => 'Ascunde țările fără nimic sincronizat',
    ],
    'travel_core.dest_page_first' => [
        'en' => 'First page',
        'ro' => 'Prima pagină',
    ],
    'travel_core.dest_page_prev' => [
        'en' => 'Previous page',
        'ro' => 'Pagina anterioară',
    ],
    'travel_core.dest_page_next' => [
        'en' => 'Next page',
        'ro' => 'Pagina următoare',
    ],
    'travel_core.dest_page_last' => [
        'en' => 'Last page',
        'ro' => 'Ultima pagină',
    ],
    'travel_core.dest_summary_title' => [
        'en' => 'Whitelist summary',
        'ro' => 'Rezumatul listei',
    ],
    'travel_core.dest_save' => [
        'en' => 'Save whitelist',
        'ro' => 'Salvează lista',
    ],
    'travel_core.dest_undo' => [
        'en' => 'Undo changes',
        'ro' => 'Anulează modificările',
    ],
    'travel_core.dest_undo_short' => [
        'en' => 'Undo',
        'ro' => 'Anulează',
    ],
    'travel_core.dest_outside_label' => [
        'en' => 'Live products outside the whitelist',
        'ro' => 'Produse active în afara listei',
    ],
    'travel_core.dest_outside_button' => [
        'en' => 'Disable products outside the whitelist…',
        'ro' => 'Dezactivează produsele din afara listei…',
    ],
    'travel_core.dest_outside_confirm' => [
        'en' => 'These [n] live products are outside the saved whitelist. Disabling hides them from the store; they are not deleted and can be enabled again.',
        'ro' => 'Aceste [n] produse active sunt în afara listei salvate. Dezactivarea le ascunde din magazin; nu sunt șterse și pot fi reactivate.',
    ],
    'travel_core.dest_n_products' => [
        'en' => '[n] products',
        'ro' => '[n] produse',
    ],
    'travel_core.dest_outside_do' => [
        'en' => 'Disable [n] products',
        'ro' => 'Dezactivează [n] produse',
    ],
    'travel_core.dest_outside_note' => [
        'en' => 'Saving never changes existing products. Use the button above to disable them on purpose.',
        'ro' => 'Salvarea nu modifică niciodată produsele existente. Folosiți butonul de mai sus pentru a le dezactiva intenționat.',
    ],
    'travel_core.dest_badge_new' => [
        'en' => 'NEW',
        'ro' => 'NOU',
    ],
    'travel_core.dest_badge_new_n' => [
        'en' => '[n] new',
        'ro' => '[n] noi',
    ],
    'travel_core.dest_mode_legend' => [
        'en' => 'What we sell in [country]',
        'ro' => 'Ce vindem în [country]',
    ],
    'travel_core.dest_select_shown' => [
        'en' => 'Select shown',
        'ro' => 'Bifează cele afișate',
    ],
    'travel_core.dest_clear_shown' => [
        'en' => 'Clear shown',
        'ro' => 'Debifează cele afișate',
    ],
    'travel_core.dest_chips_label' => [
        'en' => 'Show',
        'ro' => 'Afișează',
    ],
    'travel_core.dest_chip_all' => [
        'en' => 'All',
        'ro' => 'Toate',
    ],
    'travel_core.dest_chip_sold' => [
        'en' => 'Sold',
        'ro' => 'Vândute',
    ],
    'travel_core.dest_chip_unsold' => [
        'en' => 'Not sold',
        'ro' => 'Nevândute',
    ],
    'travel_core.dest_chip_new' => [
        'en' => 'New',
        'ro' => 'Noi',
    ],
    'travel_core.dest_sort' => [
        'en' => 'Sort',
        'ro' => 'Sortare',
    ],
    'travel_core.dest_expand' => [
        'en' => 'Expand all',
        'ro' => 'Deschide tot',
    ],
    'travel_core.dest_collapse' => [
        'en' => 'Collapse all',
        'ro' => 'Închide tot',
    ],
    'travel_core.dest_fold_group' => [
        'en' => 'Show or hide the cities of [group]',
        'ro' => 'Arată sau ascunde orașele din [group]',
    ],
    'travel_core.dest_not_saved' => [
        'en' => 'Not saved',
        'ro' => 'Nesalvat',
    ],
    'travel_core.dest_live_title' => [
        'en' => 'Live products',
        'ro' => 'Produse active',
    ],
    'travel_core.dest_n_live' => [
        'en' => '[n] live',
        'ro' => '[n] active',
    ],
    'travel_core.dest_no_item_match' => [
        'en' => 'Nothing here matches the filter.',
        'ro' => 'Nimic de aici nu se potrivește filtrului.',
    ],
    'travel_core.dest_none_sold' => [
        'en' => 'Choose at least one country to sell. Nothing was saved.',
        'ro' => 'Alegeți cel puțin o țară de vândut. Nu s-a salvat nimic.',
    ],
    'travel_core.dest_save_failed' => [
        'en' => 'The whitelist could not be saved. Nothing was changed.',
        'ro' => 'Lista nu a putut fi salvată. Nu s-a modificat nimic.',
    ],
    'travel_core.dest_disabled' => [
        'en' => '[n] products disabled.',
        'ro' => '[n] produse dezactivate.',
    ],
    'travel_core.dest_w_search' => [
        'en' => 'Search countries or destinations…',
        'ro' => 'Caută țări sau destinații…',
    ],
    'travel_core.dest_w_filter' => [
        'en' => 'Filter…',
        'ro' => 'Filtrează…',
    ],
    'travel_core.dest_w_item_type' => [
        'en' => 'Destination',
        'ro' => 'Destinație',
    ],
    'travel_core.dest_w_group_type' => [
        'en' => 'Region',
        'ro' => 'Regiune',
    ],
    'travel_core.dest_w_country_type' => [
        'en' => 'Country',
        'ro' => 'Țară',
    ],
    'travel_core.dest_w_gone' => [
        'en' => 'not in feed',
        'ro' => 'nu mai e în feed',
    ],
    'travel_core.dest_w_pending' => [
        'en' => '[n] change not saved|[n] changes not saved',
        'ro' => '[n] modificare nesalvată|[n] modificări nesalvate|[n] de modificări nesalvate',
    ],
    'travel_core.dest_w_no_pending' => [
        'en' => 'All changes saved',
        'ro' => 'Toate modificările sunt salvate',
    ],
    'travel_core.dest_w_badge_all' => [
        'en' => 'ALL',
        'ro' => 'TOATE',
    ],
    'travel_core.dest_w_group_whole' => [
        'en' => 'WHOLE REGION',
        'ro' => 'TOATĂ REGIUNEA',
    ],
    'travel_core.dest_w_group_some' => [
        'en' => '[sold] of [total]',
        'ro' => '[sold] din [total]',
    ],
    'travel_core.dest_w_group_none' => [
        'en' => 'none',
        'ro' => 'niciunul',
    ],
    'travel_core.dest_w_sold' => [
        'en' => 'sold',
        'ro' => 'vândut',
    ],
    'travel_core.dest_w_not_sold' => [
        'en' => 'not sold',
        'ro' => 'nevândut',
    ],
    'travel_core.dest_w_no_match' => [
        'en' => 'No country or destination matches.',
        'ro' => 'Nicio țară sau destinație nu se potrivește.',
    ],
    'travel_core.dest_w_more' => [
        'en' => 'Show [n] more',
        'ro' => 'Arată încă [n]',
    ],
    'travel_core.dest_w_showing' => [
        'en' => 'Showing [shown] of [total]',
        'ro' => 'Se afișează [shown] din [total]',
    ],
    'travel_core.dest_w_visible' => [
        'en' => '[shown] / [total] countries',
        'ro' => '[shown] / [total] țări',
    ],
    'travel_core.dest_w_fold' => [
        'en' => '[n] more country has nothing synced and is not sold.|[n] more countries have nothing synced and are not sold.',
        'ro' => 'Încă [n] țară nu are nimic sincronizat și nu este vândută.|Încă [n] țări nu au nimic sincronizat și nu sunt vândute.|Încă [n] de țări nu au nimic sincronizat și nu sunt vândute.',
    ],
    'travel_core.dest_w_loading' => [
        'en' => 'Loading…',
        'ro' => 'Se încarcă…',
    ],
    'travel_core.dest_w_load_failed' => [
        'en' => 'This country could not be loaded. Close it and open it again to retry.',
        'ro' => 'Țara nu a putut fi încărcată. Închideți-o și deschideți-o din nou.',
    ],
    'travel_core.dest_card_col_country' => [
        'en' => 'Country',
        'ro' => 'Țară',
    ],
    'travel_core.dest_card_col_sell' => [
        'en' => 'What we sell',
        'ro' => 'Ce vindem',
    ],
    'travel_core.dest_card_col_review' => [
        'en' => 'To review',
        'ro' => 'De verificat',
    ],
    'travel_core.dest_card_edit' => [
        'en' => 'Edit destinations →',
        'ro' => 'Editează destinațiile →',
    ],
    'travel_core.dest_card_off' => [
        'en' => '[n] more countries are not sold.',
        'ro' => 'Încă [n] țări nu sunt vândute.',
    ],
    'travel_core.dest_card_none' => [
        'en' => 'No country is sold.',
        'ro' => 'Nicio țară nu este vândută.',
    ],
    'travel_core.dest_card_review' => [
        'en' => 'Review destinations',
        'ro' => 'Verifică destinațiile',
    ],

    // Cart / checkout booking card (components/cart_booking_details.tpl,
    // ViewModels/CartBookingCardFactory).
    'travel_core.price_per_night' => [
        'en' => '[price] / night',
        'ro' => '[price] / noapte',
    ],
    'travel_core.edit_guests' => [
        'en' => 'Edit guests',
        'ro' => 'Modifică turiștii',
    ],
    'travel_core.lead_guest' => [
        'en' => 'Lead guest',
        'ro' => 'Titular rezervare',
    ],
    'travel_core.guest_n' => [
        'en' => 'Guest [n]',
        'ro' => 'Turist [n]',
    ],
    'travel_core.how_you_pay' => [
        'en' => 'How you pay',
        'ro' => 'Cum plătiți',
    ],
    'travel_core.split_today' => [
        'en' => 'Today',
        'ro' => 'Astăzi',
    ],
    'travel_core.cancel_then_pay' => [
        'en' => 'After that, cancelling costs [amount]',
        'ro' => 'După aceea, anularea costă [amount]',
    ],
    'travel_core.cancel_now_costs' => [
        'en' => 'Cancelling now costs [amount]',
        'ro' => 'Anularea acum costă [amount]',
    ],
    'travel_core.cancel_now_full' => [
        'en' => 'Cancelling now costs the full price',
        'ro' => 'Anularea acum costă prețul integral',
    ],
];
