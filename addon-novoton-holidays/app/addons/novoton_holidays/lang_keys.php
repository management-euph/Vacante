<?php

declare(strict_types=1);

/**
 * Runtime-seeded language keys for novoton_holidays.
 *
 * Read by fn_novoton_holidays_seed_language_keys() (func.php), which UPSERTs
 * every entry into ?:language_values whenever this file or addon.xml changes
 * (hash probe in init.php). That is what lets NEW or CHANGED labels reach
 * stores that are already installed — CS-Cart imports the .po files only at
 * install time, so a label added later otherwise renders raw
 * ("_novoton_holidays.n_offers") on upgraded stores.
 *
 * Shape: 'lang.key' => ['en' => '...', 'ro' => '...'].
 * Plural forms use CS-Cart's "[n] singular|[n] plural" syntax; render with
 * {__("novoton_holidays.n_offers", [$count])} — CS-Cart picks the form from
 * the count and substitutes [n].
 */

return [
    // Availability badge counts (search results) — pluralized so "1 ofertă"
    // vs "2 oferte" is correct in Romanian. Values mirror the .po entries;
    // n_offers is new.
    'novoton_holidays.n_rooms' => [
        'en' => '[n] Room|[n] Rooms',
        'ro' => '[n] Cameră|[n] Camere|[n] de Camere',
    ],
    'novoton_holidays.n_offers' => [
        'en' => '[n] Offer|[n] Offers',
        'ro' => '[n] Ofertă|[n] Oferte|[n] de Oferte',
    ],
    'novoton_holidays.n_adults' => [
        'en' => '[n] Adult|[n] Adults',
        'ro' => '[n] Adult|[n] Adulți|[n] de Adulți',
    ],
    'novoton_holidays.n_children' => [
        'en' => '[n] Child|[n] Children',
        'ro' => '[n] Copil|[n] Copii|[n] de Copii',
    ],
    // Dashboard (novoton_holidays.manage) — scheduled jobs, run from the admin
    'novoton_holidays.dash_job_unknown' => [
        'en' => 'Unknown job: [job]',
        'ro' => 'Job necunoscut: [job]',
    ],
    'novoton_holidays.dash_col_job' => [
        'en' => 'Job',
        'ro' => 'Job',
    ],
    'novoton_holidays.dash_stage_reference' => [
        'en' => 'Reference data',
        'ro' => 'Date de referință',
    ],
    'novoton_holidays.dash_stage_hotels' => [
        'en' => 'Hotels',
        'ro' => 'Hoteluri',
    ],
    'novoton_holidays.dash_stage_prices' => [
        'en' => 'Prices',
        'ro' => 'Prețuri',
    ],
    'novoton_holidays.dash_stage_products' => [
        'en' => 'Products',
        'ro' => 'Produse',
    ],
    'novoton_holidays.dash_stage_upkeep' => [
        'en' => 'Bookings and upkeep',
        'ro' => 'Rezervări și întreținere',
    ],
    'novoton_holidays.dash_job_resort_list' => [
        'en' => 'Resort list',
        'ro' => 'Lista stațiunilor',
    ],
    'novoton_holidays.dash_job_resort_list_desc' => [
        'en' => 'Resort names',
        'ro' => 'Numele stațiunilor',
    ],
    'novoton_holidays.dash_job_list_facilities' => [
        'en' => 'Facilities',
        'ro' => 'Facilități',
    ],
    'novoton_holidays.dash_job_list_facilities_desc' => [
        'en' => 'The list of facilities',
        'ro' => 'Lista facilităților',
    ],
    'novoton_holidays.dash_job_hotel_list' => [
        'en' => 'Hotel list',
        'ro' => 'Lista hotelurilor',
    ],
    'novoton_holidays.dash_job_hotel_list_desc' => [
        'en' => 'Basic hotel data',
        'ro' => 'Datele de bază ale hotelurilor',
    ],
    'novoton_holidays.dash_job_hotel_info_batched' => [
        'en' => 'Hotel info',
        'ro' => 'Informații hotel',
    ],
    'novoton_holidays.dash_job_hotel_info_batched_desc' => [
        'en' => 'Hotel details: every hotel every 3 days in February–May and every 7 days otherwise, from 05:00; in between, the hotels offers_update names',
        'ro' => 'Detaliile hotelurilor: toate hotelurile la 3 zile în februarie–mai și la 7 zile în rest, de la ora 05:00; între timp, hotelurile semnalate de offers_update',
    ],
    'novoton_holidays.dash_job_hotel_facilities_batched' => [
        'en' => 'Hotel facilities',
        'ro' => 'Facilitățile hotelurilor',
    ],
    'novoton_holidays.dash_job_hotel_facilities_batched_desc' => [
        'en' => 'Which facilities each hotel has, in batches',
        'ro' => 'Ce facilități are fiecare hotel, în loturi',
    ],
    'novoton_holidays.dash_job_refresh_images' => [
        'en' => 'Hotel photos',
        'ro' => 'Fotografiile hotelurilor',
    ],
    'novoton_holidays.dash_job_refresh_images_desc' => [
        'en' => 'Each hotel\'s photos about once a week; only new photos are downloaded, and none is added twice',
        'ro' => 'Fotografiile fiecărui hotel, cam o dată pe săptămână; se descarcă doar fotografiile noi și niciuna nu se adaugă de două ori',
    ],
    'novoton_holidays.dash_job_geocode_addresses' => [
        'en' => 'Geocode streets',
        'ro' => 'Geocodare străzi',
    ],
    'novoton_holidays.dash_job_geocode_addresses_desc' => [
        'en' => 'Street address for hotels that have none (needs geocoding on in Travel Core)',
        'ro' => 'Adresa străzii pentru hotelurile care nu o au (necesită geocodarea activă în Travel Core)',
    ],
    'novoton_holidays.dash_job_sync_priceinfo_batched' => [
        'en' => 'Price info',
        'ro' => 'Informații prețuri',
    ],
    'novoton_holidays.dash_job_sync_priceinfo_batched_desc' => [
        'en' => 'Season prices: new packages and the ones offers_update names; every package once a month, from 05:00',
        'ro' => 'Prețurile de sezon: pachetele noi și cele semnalate de offers_update; toate pachetele o dată pe lună, de la ora 05:00',
    ],
    'novoton_holidays.dash_job_compute_prices' => [
        'en' => 'Compute prices',
        'ro' => 'Calcul prețuri',
    ],
    'novoton_holidays.dash_job_compute_prices_desc' => [
        'en' => 'Lowest price, seasons, early booking (no API calls)',
        'ro' => 'Prețul minim, sezoane, early booking (fără apeluri API)',
    ],
    'novoton_holidays.dash_job_recompute_calendar_prices' => [
        'en' => 'Recompute calendar',
        'ro' => 'Recalculare calendar',
    ],
    'novoton_holidays.dash_job_recompute_calendar_prices_desc' => [
        'en' => 'Rebuild the calendar prices (no API calls)',
        'ro' => 'Reconstruiește prețurile din calendar (fără apeluri API)',
    ],
    'novoton_holidays.dash_job_room_price' => [
        'en' => 'Availability check',
        'ro' => 'Verificare disponibilitate',
    ],
    'novoton_holidays.dash_job_room_price_desc' => [
        'en' => 'Weekly: asks Novoton which hotels have offers on the check dates, a seventh of the resorts each night; marks the hotels with prices',
        'ro' => 'Săptămânal: întreabă Novoton ce hoteluri au oferte la datele verificate, o șeptime din stațiuni pe noapte; marchează hotelurile cu prețuri',
    ],
    'novoton_holidays.dash_job_add_hotels_as_products' => [
        'en' => 'Add products',
        'ro' => 'Adăugare produse',
    ],
    'novoton_holidays.dash_job_add_hotels_as_products_desc' => [
        'en' => 'Creates CS-Cart products for priced hotels, except in excluded resorts',
        'ro' => 'Creează produse CS-Cart pentru hotelurile cu prețuri, în afara stațiunilor excluse',
    ],
    'novoton_holidays.dash_job_reassign_features' => [
        'en' => 'Reassign features',
        'ro' => 'Reatribuire caracteristici',
    ],
    'novoton_holidays.dash_job_reassign_features_desc' => [
        'en' => 'Re-applies stars, resort, boards and facilities to products',
        'ro' => 'Reaplică stelele, stațiunea, tipurile de masă și facilitățile pe produse',
    ],
    'novoton_holidays.dash_job_backfill_images' => [
        'en' => 'Backfill images',
        'ro' => 'Completare imagini',
    ],
    'novoton_holidays.dash_job_backfill_images_desc' => [
        'en' => 'Re-attaches images to products that synced without them',
        'ro' => 'Reatașează imaginile produselor sincronizate fără ele',
    ],
    'novoton_holidays.dash_job_backfill_descriptions' => [
        'en' => 'Backfill descriptions',
        'ro' => 'Completare descrieri',
    ],
    'novoton_holidays.dash_job_backfill_descriptions_desc' => [
        'en' => 'Fills in the description of products created without one',
        'ro' => 'Completează descrierea produselor create fără ea',
    ],
    'novoton_holidays.dash_job_offers_update' => [
        'en' => 'Offers update',
        'ro' => 'Actualizare oferte',
    ],
    'novoton_holidays.dash_job_offers_update_desc' => [
        'en' => 'Changed offers in every country sold, twice a day: marks the packages and hotels to fetch again, adds new hotels',
        'ro' => 'Ofertele modificate din fiecare țară vândută, de două ori pe zi: marchează pachetele și hotelurile de recitit, adaugă hotelurile noi',
    ],
    'novoton_holidays.dash_job_resinfo' => [
        'en' => 'Booking status',
        'ro' => 'Starea rezervărilor',
    ],
    'novoton_holidays.dash_job_resinfo_desc' => [
        'en' => 'On-request (ASK) bookings, every 15 minutes, until confirmed (OK), cancelled (ST) or sent to alternatives (RQ)',
        'ro' => 'Rezervările la cerere (ASK), la fiecare 15 minute, până la confirmare (OK), anulare (ST) sau alternative (RQ)',
    ],
    'novoton_holidays.dash_job_cleanup' => [
        'en' => 'Cleanup',
        'ro' => 'Curățenie',
    ],
    'novoton_holidays.dash_job_cleanup_desc' => [
        'en' => 'Orphan bookings, old logs, expired cache',
        'ro' => 'Rezervări orfane, jurnale vechi, cache expirat',
    ],
    'novoton_holidays.dash_job_full' => [
        'en' => 'Full sync',
        'ro' => 'Sincronizare completă',
    ],
    'novoton_holidays.dash_job_full_desc' => [
        'en' => 'Prices, booking status and cleanup in one run',
        'ro' => 'Prețuri, starea rezervărilor și curățenie într-o singură rulare',
    ],
    'novoton_holidays.dash_job_alternative_rs' => [
        'en' => 'Alternatives check',
        'ro' => 'Verificare alternative',
    ],
    'novoton_holidays.dash_job_alternative_rs_desc' => [
        'en' => 'Checks alternative requests',
        'ro' => 'Verifică cererile de alternative',
    ],
    'novoton_holidays.dash_job_alternative_rs_bookings' => [
        'en' => 'Alternatives for bookings',
        'ro' => 'Alternative pentru rezervări',
    ],
    'novoton_holidays.dash_job_alternative_rs_bookings_desc' => [
        'en' => 'Checks alternatives for pending bookings',
        'ro' => 'Verifică alternativele pentru rezervările în așteptare',
    ],
    'novoton_holidays.dash_job_notify_alternatives' => [
        'en' => 'Notify alternatives',
        'ro' => 'Notificare alternative',
    ],
    'novoton_holidays.dash_job_notify_alternatives_desc' => [
        'en' => 'Sends alternative offers to customers',
        'ro' => 'Trimite clienților ofertele alternative',
    ],
    'novoton_holidays.dash_job_expire_requests' => [
        'en' => 'Expire requests',
        'ro' => 'Expirare cereri',
    ],
    'novoton_holidays.dash_job_expire_requests_desc' => [
        'en' => 'Closes old alternative requests',
        'ro' => 'Închide cererile de alternative vechi',
    ],
    'novoton_holidays.dash_run_failed' => [
        'en' => 'Failed',
        'ro' => 'Eșuat',
    ],
    'novoton_holidays.dash_run_running' => [
        'en' => 'Running',
        'ro' => 'Rulează',
    ],
    'novoton_holidays.dash_on_demand_intro' => [
        'en' => 'Not part of the schedule: Full sync repeats jobs from the stages above, and the alternative-request jobs follow bookings.',
        'ro' => 'Nu fac parte din program: Sincronizarea completă repetă joburi din etapele de mai sus, iar joburile pentru cereri de alternative urmează rezervările.',
    ],
    'novoton_holidays.dash_xml_feed_title' => [
        'en' => 'Hotel features XML feed',
        'ro' => 'Feed XML cu caracteristicile hotelurilor',
    ],
    'novoton_holidays.dash_xml_feed_hint' => [
        'en' => 'For CS-Cart Advanced Import → “Link to file”. The address carries the cron key; Copy copies the real one.',
        'ro' => 'Pentru CS-Cart Advanced Import → „Link către fișier”. Adresa conține cheia cron; Copiază copiază cheia reală.',
    ],
    'novoton_holidays.dash_settings_jobs_moved' => [
        'en' => 'Scheduled jobs, with their crontab lines, URLs and last runs, are on the dashboard.',
        'ro' => 'Joburile programate, cu liniile crontab, URL-urile și ultimele rulări, sunt pe panoul de control.',
    ],
    'novoton_holidays.dash_open_jobs' => [
        'en' => 'Open scheduled jobs',
        'ro' => 'Deschide joburile programate',
    ],
    'novoton_holidays.dash_title' => [
        'en' => 'Novoton Holidays · v[version]',
        'ro' => 'Novoton Holidays · v[version]',
    ],
    'novoton_holidays.dash_tile_hotels' => [
        'en' => 'Hotels',
        'ro' => 'Hoteluri',
    ],
    'novoton_holidays.dash_tile_hotels_realtime' => [
        'en' => 'Real-time prices: [n]',
        'ro' => 'Prețuri în timp real: [n]',
    ],
    'novoton_holidays.dash_tile_hotels_season' => [
        'en' => 'Season prices: [n]',
        'ro' => 'Prețuri de sezon: [n]',
    ],
    'novoton_holidays.dash_tile_products' => [
        'en' => 'Products',
        'ro' => 'Produse',
    ],
    'novoton_holidays.dash_tile_products_note' => [
        'en' => 'Hotels made into CS-Cart products',
        'ro' => 'Hoteluri transformate în produse CS-Cart',
    ],
    'novoton_holidays.dash_tile_products_open' => [
        'en' => 'Open these products →',
        'ro' => 'Deschide aceste produse →',
    ],
    'novoton_holidays.dash_tile_bookings' => [
        'en' => 'Bookings',
        'ro' => 'Rezervări',
    ],
    'novoton_holidays.dash_tile_bookings_pending' => [
        'en' => '[n] pending',
        'ro' => '[n] în așteptare',
    ],
    'novoton_holidays.dash_tile_bookings_note' => [
        'en' => 'Confirmed: [confirmed] · cancelled: [cancelled]',
        'ro' => 'Confirmate: [confirmed] · anulate: [cancelled]',
    ],
    'novoton_holidays.dash_tile_bookings_open' => [
        'en' => 'Open Novoton bookings →',
        'ro' => 'Deschide rezervările Novoton →',
    ],
    'novoton_holidays.dash_attention_title' => [
        'en' => 'Needs attention ([n])',
        'ro' => 'Necesită atenție ([n])',
    ],
    'novoton_holidays.dash_attention_failed' => [
        'en' => '[job] failed on [date].',
        'ro' => '[job] a eșuat la [date].',
    ],
    'novoton_holidays.dash_attention_stalled' => [
        'en' => '[job] started on [date] and never finished.',
        'ro' => '[job] a pornit la [date] și nu s-a terminat.',
    ],
    'novoton_holidays.dash_attention_never' => [
        'en' => '[job] has never run.',
        'ro' => '[job] nu a rulat niciodată.',
    ],
    'novoton_holidays.dash_attention_never_prices' => [
        'en' => '[job] has never run, so [with] of [total] hotels have season prices.',
        'ro' => '[job] nu a rulat niciodată, așa că [with] din [total] hoteluri au prețuri de sezon.',
    ],
    'novoton_holidays.dash_attention_late' => [
        'en' => '[job] is late: it last finished on [date].',
        'ro' => '[job] este întârziat: ultima dată s-a terminat la [date].',
    ],
    'novoton_holidays.dash_attention_no_season' => [
        'en' => 'Only [with] of [total] hotels have season prices.',
        'ro' => 'Doar [with] din [total] hoteluri au prețuri de sezon.',
    ],
    'novoton_holidays.dash_attention_run' => [
        'en' => 'Run [job] now',
        'ro' => 'Rulează [job] acum',
    ],
    'novoton_holidays.dash_tools_title' => [
        'en' => 'Tools',
        'ro' => 'Instrumente',
    ],
    'novoton_holidays.dash_tools_check' => [
        'en' => 'Check',
        'ro' => 'Verificări',
    ],
    'novoton_holidays.dash_tool_check_prices' => [
        'en' => 'Check prices',
        'ro' => 'Verifică prețurile',
    ],
    'novoton_holidays.dash_tool_check_prices_hotel' => [
        'en' => 'Check prices per hotel',
        'ro' => 'Verifică prețurile pe hotel',
    ],
    'novoton_holidays.dash_tool_check_packages' => [
        'en' => 'Check packages',
        'ro' => 'Verifică pachetele',
    ],
    'novoton_holidays.dash_tool_test_api' => [
        'en' => 'Test API',
        'ro' => 'Testează API-ul',
    ],
    'novoton_holidays.dash_tool_health' => [
        'en' => 'Health check',
        'ro' => 'Verificare stare',
    ],
    'novoton_holidays.dash_tools_open' => [
        'en' => 'Open',
        'ro' => 'Deschide',
    ],
    'novoton_holidays.dash_tool_bookings' => [
        'en' => 'Bookings',
        'ro' => 'Rezervări',
    ],
    'novoton_holidays.dash_tool_alternatives' => [
        'en' => 'Alternative requests',
        'ro' => 'Cereri de alternative',
    ],
    'novoton_holidays.dash_tool_price_compare' => [
        'en' => 'Price comparison',
        'ro' => 'Comparație prețuri',
    ],
    'novoton_holidays.dash_tools_export' => [
        'en' => 'Export',
        'ro' => 'Export',
    ],
    'novoton_holidays.dash_tool_features_csv' => [
        'en' => 'Hotel features (CSV)',
        'ro' => 'Caracteristici hoteluri (CSV)',
    ],
    'novoton_holidays.dash_tool_features_xml' => [
        'en' => 'Hotel features (XML)',
        'ro' => 'Caracteristici hoteluri (XML)',
    ],
    'novoton_holidays.dash_tools_maintenance' => [
        'en' => 'Maintenance',
        'ro' => 'Întreținere',
    ],
    'novoton_holidays.dash_tool_recompute_calendar' => [
        'en' => 'Recompute calendar prices…',
        'ro' => 'Recalculează prețurile din calendar…',
    ],
    'novoton_holidays.dash_tool_recompute_calendar_confirm' => [
        'en' => 'Recompute the calendar prices of every hotel now?',
        'ro' => 'Recalculezi acum prețurile din calendar pentru toate hotelurile?',
    ],
    'novoton_holidays.dash_tool_recompute_calendar_hint' => [
        'en' => 'Asks first; runs as an admin action.',
        'ro' => 'Cere confirmare; rulează ca acțiune de administrator.',
    ],
    'novoton_holidays.dash_countries_title' => [
        'en' => 'Hotels by country',
        'ro' => 'Hoteluri pe țări',
    ],
    'novoton_holidays.dash_col_country' => [
        'en' => 'Country',
        'ro' => 'Țară',
    ],
    'novoton_holidays.dash_col_hotels' => [
        'en' => 'Hotels',
        'ro' => 'Hoteluri',
    ],
    'novoton_holidays.dash_col_realtime' => [
        'en' => 'Real-time prices',
        'ro' => 'Prețuri în timp real',
    ],
    'novoton_holidays.dash_col_season' => [
        'en' => 'Season prices',
        'ro' => 'Prețuri de sezon',
    ],
    'novoton_holidays.dash_col_products' => [
        'en' => 'Products',
        'ro' => 'Produse',
    ],
    'novoton_holidays.dash_resorts_title' => [
        'en' => 'Excluded resorts',
        'ro' => 'Stațiuni excluse',
    ],
    'novoton_holidays.dash_resorts_summary' => [
        'en' => '[excluded] of [total] resorts excluded',
        'ro' => '[excluded] din [total] stațiuni excluse',
    ],
    'novoton_holidays.dash_resorts_country_summary' => [
        'en' => '[country] [excluded] of [total]',
        'ro' => '[country] [excluded] din [total]',
    ],
    'novoton_holidays.dash_resorts_rule' => [
        'en' => 'Hotels in excluded resorts are never made into products; products already made stay as they are.',
        'ro' => 'Hotelurile din stațiunile excluse nu devin niciodată produse; produsele deja create rămân cum sunt.',
    ],
    'novoton_holidays.dash_resorts_choose' => [
        'en' => 'Choose resorts…',
        'ro' => 'Alege stațiunile…',
    ],
    'novoton_holidays.dash_resorts_pending' => [
        'en' => '[changes] not saved: [add] to exclude, [remove] to include again · affects [hotels] hotels ([products] already products; they stay)',
        'ro' => '[changes] nesalvate: [add] de exclus, [remove] de reinclus · afectează [hotels] hoteluri ([products] sunt deja produse; rămân)',
    ],
    'novoton_holidays.dash_resorts_saved' => [
        'en' => 'All changes saved',
        'ro' => 'Toate modificările sunt salvate',
    ],
    'novoton_holidays.dash_resorts_search' => [
        'en' => 'Search resorts',
        'ro' => 'Caută stațiuni',
    ],
    'novoton_holidays.dash_resorts_all' => [
        'en' => 'All [n]',
        'ro' => 'Toate [n]',
    ],
    'novoton_holidays.dash_resorts_only_excluded' => [
        'en' => 'Show excluded only',
        'ro' => 'Doar cele excluse',
    ],
    'novoton_holidays.dash_resorts_sort' => [
        'en' => 'Sort',
        'ro' => 'Sortare',
    ],
    'novoton_holidays.dash_resorts_sort_name' => [
        'en' => 'A–Z',
        'ro' => 'A–Z',
    ],
    'novoton_holidays.dash_resorts_sort_hotels' => [
        'en' => 'Most hotels',
        'ro' => 'Cele mai multe hoteluri',
    ],
    'novoton_holidays.dash_resorts_group' => [
        'en' => 'resorts: [resorts] · hotels: [hotels]',
        'ro' => 'stațiuni: [resorts] · hoteluri: [hotels]',
    ],
    'novoton_holidays.dash_resorts_excluded_word' => [
        'en' => 'excluded',
        'ro' => 'excluse',
    ],
    'novoton_holidays.dash_resorts_exclude_shown' => [
        'en' => 'Exclude all shown',
        'ro' => 'Exclude toate cele afișate',
    ],
    'novoton_holidays.dash_resorts_include_shown' => [
        'en' => 'Include all shown',
        'ro' => 'Include toate cele afișate',
    ],
    'novoton_holidays.dash_resorts_not_saved' => [
        'en' => 'Not saved yet',
        'ro' => 'Nesalvat încă',
    ],
    'novoton_holidays.dash_resorts_excluded_flag' => [
        'en' => 'Excluded',
        'ro' => 'Exclusă',
    ],
    'novoton_holidays.dash_n_hotels' => [
        'en' => '[n] hotel|[n] hotels',
        'ro' => '[n] hotel|[n] hoteluri|[n] de hoteluri',
    ],
    'novoton_holidays.dash_n_products' => [
        'en' => '[n] product|[n] products',
        'ro' => '[n] produs|[n] produse|[n] de produse',
    ],
    'novoton_holidays.dash_no_products' => [
        'en' => 'no products',
        'ro' => 'fără produse',
    ],
    'novoton_holidays.dash_resorts_no_match' => [
        'en' => 'No resorts match these filters.',
        'ro' => 'Nicio stațiune nu corespunde acestor filtre.',
    ],
    'novoton_holidays.dash_resorts_undo' => [
        'en' => 'Undo changes',
        'ro' => 'Anulează modificările',
    ],
    'novoton_holidays.dash_resorts_save' => [
        'en' => 'Save excluded resorts',
        'ro' => 'Salvează stațiunile excluse',
    ],
    'novoton_holidays.dash_resorts_none' => [
        'en' => 'No resorts yet: run the Hotel list job to load them.',
        'ro' => 'Nicio stațiune încă: rulează jobul Lista hotelurilor pentru a le încărca.',
    ],
    'novoton_holidays.dash_activity_title' => [
        'en' => 'Recent sync activity',
        'ro' => 'Activitate recentă de sincronizare',
    ],
    'novoton_holidays.dash_activity_all' => [
        'en' => 'All ([n])',
        'ro' => 'Toate ([n])',
    ],
    'novoton_holidays.dash_activity_failed' => [
        'en' => 'Failed ([n])',
        'ro' => 'Eșuate ([n])',
    ],
    'novoton_holidays.dash_activity_ok' => [
        'en' => 'OK',
        'ro' => 'OK',
    ],
    'novoton_holidays.dash_activity_none' => [
        'en' => 'No sync runs recorded.',
        'ro' => 'Nicio sincronizare înregistrată.',
    ],
    'novoton_holidays.dash_col_date' => [
        'en' => 'Date',
        'ro' => 'Data',
    ],
    'novoton_holidays.dash_col_total' => [
        'en' => 'Total',
        'ro' => 'Total',
    ],
    'novoton_holidays.dash_col_updated' => [
        'en' => 'Updated',
        'ro' => 'Actualizate',
    ],
    'novoton_holidays.dash_col_failed' => [
        'en' => 'Failed',
        'ro' => 'Eșuate',
    ],
    'novoton_holidays.dash_col_status' => [
        'en' => 'Status',
        'ro' => 'Stare',
    ],
    'novoton_holidays.dash_col_duration' => [
        'en' => 'Duration',
        'ro' => 'Durată',
    ],
    'novoton_holidays.dash_resorts_saved_n' => [
        'en' => 'Excluded resorts saved: [n]',
        'ro' => 'Stațiuni excluse salvate: [n]',
    ],
    'novoton_holidays.dash_resorts_save_failed' => [
        'en' => 'The excluded resorts could not be saved: the add-on setting did not take the new value. Nothing was changed.',
        'ro' => 'Stațiunile excluse nu au putut fi salvate: setarea add-onului nu a preluat valoarea nouă. Nu s-a modificat nimic.',
    ],

    // Destinations (the destination whitelist) — novoton_destinations.manage + the dashboard card.
    'novoton_holidays.dest_title' => [
        'en' => 'Destinations',
        'ro' => 'Destinații',
    ],
    'novoton_holidays.dest_page_title' => [
        'en' => 'Destination whitelist',
        'ro' => 'Destinații vândute (whitelist)',
    ],
    'novoton_holidays.dest_tile_countries' => [
        'en' => 'Countries sold',
        'ro' => 'Țări vândute',
    ],
    'novoton_holidays.dest_tile_resorts' => [
        'en' => 'Resorts sold',
        'ro' => 'Stațiuni vândute',
    ],
    'novoton_holidays.dest_tile_hotels' => [
        'en' => 'Hotels in scope',
        'ro' => 'Hoteluri incluse',
    ],
    'novoton_holidays.dest_tile_hotels_note' => [
        'en' => '[n] with real-time prices',
        'ro' => '[n] cu prețuri în timp real',
    ],
    'novoton_holidays.dest_tile_new' => [
        'en' => 'New resorts to review',
        'ro' => 'Stațiuni noi de verificat',
    ],
    'novoton_holidays.dest_not_configured_title' => [
        'en' => 'No whitelist saved yet.',
        'ro' => 'Nu există încă o listă salvată.',
    ],
    'novoton_holidays.dest_not_configured_body' => [
        'en' => 'Shown below is what today\'s settings sell: the selected countries, minus the excluded resorts. Nothing changes until you press Save; from then on this page decides what is synced and made into products.',
        'ro' => 'Mai jos este ce se vinde cu setările actuale: țările selectate, fără stațiunile excluse. Nimic nu se schimbă până nu apăsați Salvează; de atunci, această pagină decide ce se sincronizează și ce devine produs.',
    ],
    'novoton_holidays.dest_new_alert' => [
        'en' => '[n] new {resort|resorts} appeared in the hotel sync. {Its country is|Their countries are} set to "Only selected", so {it is|they are} not sold until you tick {it|them}.',
        'ro' => '[n] {stațiune nouă a apărut|stațiuni noi au apărut|de stațiuni noi au apărut} la sincronizarea hotelurilor. {Țara ei este|Țările lor sunt|Țările lor sunt} pe „Doar cele alese”, deci nu se {vinde|vând|vând} până nu {o bifați|le bifați|le bifați}.',
    ],
    'novoton_holidays.dest_show_new' => [
        'en' => 'Show new resorts',
        'ro' => 'Arată stațiunile noi',
    ],
    'novoton_holidays.dest_gone_alert' => [
        'en' => '[n] ticked {resort is|resorts are} no longer in the Novoton feed (shown crossed out). {It stays|They stay} ticked until you untick {it|them}.',
        'ro' => '[n] {stațiune bifată nu mai apare|stațiuni bifate nu mai apar|de stațiuni bifate nu mai apar} în fluxul Novoton (tăiate). {Rămâne bifată până o debifați|Rămân bifate până le debifați|Rămân bifate până le debifați}.',
    ],
    'novoton_holidays.dest_intro' => [
        'en' => 'Choose what we sell from Novoton. Only hotels in these destinations are synced and made into products (hotels with live products keep syncing until you disable them). "All resorts" includes resorts Novoton adds later; "Only selected" waits for you to tick them.',
        'ro' => 'Alegeți ce vindem de la Novoton. Doar hotelurile din aceste destinații se sincronizează și devin produse (hotelurile cu produse active se sincronizează în continuare până le dezactivați). „Toate stațiunile” include și stațiunile adăugate ulterior de Novoton; „Doar cele alese” așteaptă să le bifați.',
    ],
    'novoton_holidays.dest_badge_all' => [
        'en' => 'ALL',
        'ro' => 'TOATE',
    ],
    'novoton_holidays.dest_badge_off' => [
        'en' => 'NOT SOLD',
        'ro' => 'NU SE VINDE',
    ],
    'novoton_holidays.dest_badge_some' => [
        'en' => '[sold] of [total]',
        'ro' => '[sold] din [total]',
    ],
    'novoton_holidays.dest_badge_gone' => [
        'en' => 'not in feed',
        'ro' => 'nu mai e în flux',
    ],
    'novoton_holidays.dest_search' => [
        'en' => 'Search countries or resorts…',
        'ro' => 'Caută țări sau stațiuni…',
    ],
    'novoton_holidays.dest_search_resort' => [
        'en' => 'Resort',
        'ro' => 'Stațiune',
    ],
    'novoton_holidays.dest_search_sold' => [
        'en' => 'sold',
        'ro' => 'se vinde',
    ],
    'novoton_holidays.dest_search_not_sold' => [
        'en' => 'not sold',
        'ro' => 'nu se vinde',
    ],
    'novoton_holidays.dest_search_no_match' => [
        'en' => 'No country or resort matches.',
        'ro' => 'Nicio țară sau stațiune nu se potrivește.',
    ],
    'novoton_holidays.dest_country_meta' => [
        'en' => '[resorts] {resort|resorts} · [hotels] {hotel|hotels}',
        'ro' => '[resorts] {stațiune|stațiuni|de stațiuni} · [hotels] {hotel|hoteluri|de hoteluri}',
    ],
    'novoton_holidays.dest_mode_off' => [
        'en' => 'Not sold',
        'ro' => 'Nu se vinde',
    ],
    'novoton_holidays.dest_mode_all' => [
        'en' => 'All resorts',
        'ro' => 'Toate stațiunile',
    ],
    'novoton_holidays.dest_mode_specific' => [
        'en' => 'Only selected',
        'ro' => 'Doar cele alese',
    ],
    'novoton_holidays.dest_hint_off' => [
        'en' => 'Nothing is synced or sold in this country.',
        'ro' => 'În această țară nu se sincronizează și nu se vinde nimic.',
    ],
    'novoton_holidays.dest_hint_all' => [
        'en' => 'Every resort is sold, including ones Novoton adds later.',
        'ro' => 'Se vinde fiecare stațiune, inclusiv cele adăugate ulterior de Novoton.',
    ],
    'novoton_holidays.dest_hint_specific' => [
        'en' => 'Tick the resorts to sell. New resorts wait for you.',
        'ro' => 'Bifați stațiunile de vândut. Stațiunile noi vă așteaptă.',
    ],
    'novoton_holidays.dest_n_priced' => [
        'en' => '[n] with prices',
        'ro' => '[n] cu prețuri',
    ],
    'novoton_holidays.dest_no_hotels' => [
        'en' => 'No hotels are synced for [country] yet. Choose "All resorts" and the next hotel sync brings them in.',
        'ro' => 'Nu există încă hoteluri sincronizate pentru [country]. Alegeți „Toate stațiunile” și următoarea sincronizare le aduce.',
    ],
    'novoton_holidays.dest_none_sold' => [
        'en' => 'Choose at least one country to sell. Nothing was saved.',
        'ro' => 'Alegeți cel puțin o țară de vândut. Nu s-a salvat nimic.',
    ],
    'novoton_holidays.dest_saved' => [
        'en' => 'Whitelist saved: [countries] countries, [resorts] selected resorts. The next hotel sync uses it.',
        'ro' => 'Lista a fost salvată: [countries] țări, [resorts] stațiuni alese. Următoarea sincronizare o folosește.',
    ],
    'novoton_holidays.dest_save_failed' => [
        'en' => 'The whitelist could not be saved. Nothing was changed.',
        'ro' => 'Lista nu a putut fi salvată. Nu s-a modificat nimic.',
    ],
    'novoton_holidays.dest_disabled' => [
        'en' => '[n] products disabled.',
        'ro' => 'Produse dezactivate: [n].',
    ],
    'novoton_holidays.dest_settings_pointer' => [
        'en' => 'Which countries and resorts are sold is chosen on the Destinations page. Once saved there, it replaces "Selected countries" above.',
        'ro' => 'Țările și stațiunile vândute se aleg pe pagina Destinații. Odată salvată acolo, înlocuiește „Țări selectate” de mai sus.',
    ],
    'novoton_holidays.dest_settings_open' => [
        'en' => 'Open Destinations →',
        'ro' => 'Deschide Destinații →',
    ],
    'novoton_holidays.dash_dest_intro' => [
        'en' => '[countries] {country|countries}, [resorts] {resort|resorts} sold.',
        'ro' => '[countries] {țară|țări|de țări}, [resorts] {stațiune vândută|stațiuni vândute|de stațiuni vândute}.',
    ],
    'novoton_holidays.dash_dest_not_configured' => [
        'en' => 'No whitelist saved yet: this is what the selected countries and excluded resorts sell today.',
        'ro' => 'Nu există încă o listă salvată: aceasta este ce se vinde azi cu țările selectate și stațiunile excluse.',
    ],
    'novoton_holidays.dash_dest_col_hotels' => [
        'en' => 'Hotels in scope',
        'ro' => 'Hoteluri incluse',
    ],
    'novoton_holidays.dash_dest_col_live' => [
        'en' => 'Live products',
        'ro' => 'Produse active',
    ],
    'novoton_holidays.dash_dest_n_resorts' => [
        'en' => '[n] {resort|resorts}',
        'ro' => '[n] {stațiune|stațiuni|de stațiuni}',
    ],
    'novoton_holidays.dash_dest_resorts_word' => [
        'en' => 'resorts',
        'ro' => 'stațiuni',
    ],
    'novoton_holidays.dash_dest_alert_new' => [
        'en' => '[n] new resorts in [country] wait for review.',
        'ro' => '[n] stațiuni noi în [country] așteaptă verificarea.',
    ],
    'novoton_holidays.dash_dest_alert_gone' => [
        'en' => '[n] sold resorts are no longer in the Novoton feed.',
        'ro' => '[n] stațiuni vândute nu mai apar în fluxul Novoton.',
    ],
    'novoton_holidays.dash_dest_alert_outside' => [
        'en' => '[n] live products are outside the destination whitelist.',
        'ro' => '[n] produse active sunt în afara listei de destinații.',
    ],
    'novoton_holidays.dash_dest_review' => [
        'en' => 'Review destinations',
        'ro' => 'Verifică destinațiile',
    ],
    // Multi-room selection (search results and the product page)
    'novoton_holidays.mr_room' => [
        'en' => 'Room [num]',
        'ro' => 'Camera [num]',
    ],
    'novoton_holidays.mr_prices_note' => [
        'en' => 'Prices are for the whole stay, per room.',
        'ro' => 'Prețurile sunt pentru întregul sejur, per cameră.',
    ],
    'novoton_holidays.mr_not_chosen' => [
        'en' => 'Not chosen yet',
        'ro' => 'Încă nealeasă',
    ],
    'novoton_holidays.mr_progress' => [
        'en' => '[chosen] of [total] rooms chosen',
        'ro' => 'Camere alese: [chosen] din [total]',
    ],
    'novoton_holidays.mr_rooms_left' => [
        'en' => 'Rooms left to choose: [count]',
        'ro' => 'Camere rămase de ales: [count]',
    ],
    'novoton_holidays.mr_book_all' => [
        'en' => 'Book all rooms ([count])',
        'ro' => 'Rezervă toate camerele ([count])',
    ],
    'novoton_holidays.mr_same_guests' => [
        'en' => 'Apply to the other rooms with the same guests ([count])',
        'ro' => 'Aplică la celelalte camere cu aceiași turiști ([count])',
    ],
    'novoton_holidays.mr_next_room' => [
        'en' => 'Next room',
        'ro' => 'Camera următoare',
    ],
    'novoton_holidays.mr_your_selection' => [
        'en' => 'Your selection',
        'ro' => 'Selecția ta',
    ],
    'novoton_holidays.mr_savings' => [
        'en' => 'You save [amount]',
        'ro' => 'Economisești [amount]',
    ],
    'novoton_holidays.mr_guest_details_next' => [
        'en' => 'You will add the guest details on the next step.',
        'ro' => 'Datele turiștilor le completezi la pasul următor.',
    ],
    'novoton_holidays.mr_room_unnamed' => [
        'en' => 'Room',
        'ro' => 'Cameră',
    ],
    'novoton_holidays.mr_whole_stay' => [
        'en' => 'whole stay',
        'ro' => 'tot sejurul',
    ],
    // Destinations page on Travel Core's destination picker.
    'novoton_holidays.dest_filter' => [
        'en' => 'Filter resorts…',
        'ro' => 'Filtrează stațiunile…',
    ],
    'novoton_holidays.dest_fold' => [
        'en' => '[n] more country has no hotels synced and is not sold.|[n] more countries have no hotels synced and are not sold.',
        'ro' => 'Încă [n] țară nu are hoteluri sincronizate și nu este vândută.|Încă [n] țări nu au hoteluri sincronizate și nu sunt vândute.|Încă [n] de țări nu au hoteluri sincronizate și nu sunt vândute.',
    ],
    // "No availability" box on the hotel page: the season the hotel sells.
    // The key was used by search.tpl but never defined, so the raw key showed.
    'novoton_holidays.accommodation_period' => [
        'en' => 'This hotel offers accommodation from [from] to [to].',
        'ro' => 'Hotelul oferă cazare în perioada [from] – [to].',
    ],
    // Alternatives-request emails (AlternativeEmail). The request form asks
    // for no name, so the "Dear [name]," line greets a generic customer; and
    // the request has no order, so the body no longer quotes "Order #".
    'novoton_holidays.customer_greeting_name' => [
        'en' => 'customer',
        'ro' => 'client',
    ],
    'novoton_holidays.alternatives_available_body' => [
        'en' => 'We have found alternative options for your request at [hotel].',
        'ro' => 'Am găsit opțiuni alternative pentru cererea dvs. la [hotel].',
    ],
    // Email template names (Design → Email templates). The two price
    // templates had none, so the list showed "_email_template.novoton_…";
    // seeding all five also names them on stores installed before the .po.
    'email_template.novoton_holidays_import_report' => [
        'en' => 'Novoton: Hotel Import Report',
        'ro' => 'Novoton: Raport Import Hoteluri',
    ],
    'email_template.novoton_holidays_alternatives_available' => [
        'en' => 'Novoton: Alternatives Available',
        'ro' => 'Novoton: Alternative Disponibile',
    ],
    'email_template.novoton_holidays_alternatives_request' => [
        'en' => 'Novoton: Alternatives Request Confirmation',
        'ro' => 'Novoton: Confirmare Cerere Alternative',
    ],
    'email_template.novoton_holidays_price_alert' => [
        'en' => 'Novoton: Price Alert (form price below API price)',
        'ro' => 'Novoton: Alertă de preț (preț formular sub prețul API)',
    ],
    'email_template.novoton_holidays_price_discrepancy' => [
        'en' => 'Novoton: Price Discrepancy',
        'ro' => 'Novoton: Diferență de preț',
    ],
    // Checkout safety notices (audit H3, C2/H4): a cart line that could no
    // longer carry its booking was removed; a corrected price could not be
    // recorded on the booking; edited guests change a priced child age.
    'novoton_holidays.booking_removed_from_cart' => [
        'en' => 'A hotel reservation in your cart is no longer available and was removed. Please search for the stay and book it again.',
        'ro' => 'O rezervare de hotel din coșul dvs. nu mai este disponibilă și a fost eliminată. Vă rugăm să căutați din nou sejurul și să îl rezervați.',
    ],
    'novoton_holidays.booking_price_update_failed' => [
        'en' => 'The price of a hotel reservation in your cart has changed, but it could not be updated. Please try placing your order again in a moment.',
        'ro' => 'Prețul unei rezervări de hotel din coșul dvs. s-a modificat, dar nu a putut fi actualizat. Vă rugăm să încercați să plasați comanda din nou peste câteva momente.',
    ],
    'novoton_holidays.guests_do_not_match_priced_stay' => [
        'en' => 'These guest details do not match the stay that was priced (for example, a date of birth gives a child a different age). Please check the details, or search for the stay again with the correct ages and book it again.',
        'ro' => 'Datele turiștilor nu corespund sejurului pentru care a fost calculat prețul (de exemplu, data nașterii indică o altă vârstă pentru un copil). Vă rugăm să verificați datele sau să căutați din nou sejurul cu vârstele corecte și să îl rezervați.',
    ],
    // Labels the code used without ever defining them: the admin menu showed
    // "(_novoton_holidays.addon_name)" and these rendered as raw "_novoton_holidays.*".
    'novoton_holidays.addon_name' => [
        'en' => 'Novoton Holidays',
        'ro' => 'Novoton Holidays',
    ],
    'novoton_holidays.api_unavailable' => [
        'en' => 'The hotel search is temporarily unavailable. Please try again later.',
        'ro' => 'Căutarea hotelurilor este temporar indisponibilă. Vă rugăm să încercați mai târziu.',
    ],
    'novoton_holidays.search_error' => [
        'en' => 'An error occurred while searching. Please try again later.',
        'ro' => 'A apărut o eroare la căutare. Vă rugăm să încercați mai târziu.',
    ],
    'novoton_holidays.booking_update_failed' => [
        'en' => 'Could not save the guest details. Please try again.',
        'ro' => 'Datele turiștilor nu au putut fi salvate. Vă rugăm să încercați din nou.',
    ],
    'novoton_holidays.missing_required_fields' => [
        'en' => 'Please fill in all required fields.',
        'ro' => 'Vă rugăm să completați toate câmpurile obligatorii.',
    ],
    'novoton_holidays.invalid_email' => [
        'en' => 'Please enter a valid email address.',
        'ro' => 'Vă rugăm să introduceți o adresă de email validă.',
    ],
    'novoton_holidays.email_send_failed' => [
        'en' => 'The email could not be sent. Please try again.',
        'ro' => 'Emailul nu a putut fi trimis. Vă rugăm să încercați din nou.',
    ],
    'novoton_holidays.job_unknown' => [
        'en' => 'Unknown job: [job]',
        'ro' => 'Job necunoscut: [job]',
    ],
    'novoton_holidays.manage_bookings' => [
        'en' => 'Novoton Bookings',
        'ro' => 'Rezervări Novoton',
    ],
    'novoton_holidays.prices_use_search_form' => [
        'en' => 'Use the search form above to see prices and availability for your dates.',
        'ro' => 'Folosiți formularul de căutare de mai sus pentru a vedea prețurile și disponibilitatea pentru datele dorite.',
    ],
    'novoton_holidays.price_verified_at_checkout' => [
        'en' => 'The price will be verified at checkout',
        'ro' => 'Prețul va fi verificat la finalizare',
    ],
    'novoton_holidays.self_catering' => [
        'en' => 'Self Catering',
        'ro' => 'Fără masă',
    ],
    'novoton_holidays.tourists' => [
        'en' => 'Tourists',
        'ro' => 'Turiști',
    ],
    'novoton_holidays.payment_conditions' => [
        'en' => 'Payment conditions',
        'ro' => 'Condiții de plată',
    ],
    'novoton_holidays.cancellation_conditions' => [
        'en' => 'Cancellation conditions',
        'ro' => 'Condiții de anulare',
    ],
    // Booking page (travel_bookings.view), technical details: the
    // booking's exchange with Novoton (ReservationExchange).
    'novoton_holidays.bv_notes' => [
        'en' => 'Notes',
        'ro' => 'Note',
    ],
    'novoton_holidays.bv_hotel_rooms' => [
        'en' => 'Hotel rooms (hotelinfo)',
        'ro' => 'Camerele hotelului (hotelinfo)',
    ],
    'novoton_holidays.bv_request' => [
        'en' => 'Reservation request (hotel_res_RQ)',
        'ro' => 'Cererea de rezervare (hotel_res_RQ)',
    ],
    'novoton_holidays.bv_answer' => [
        'en' => 'Novoton\'s answer (api_response)',
        'ro' => 'Răspunsul Novoton (api_response)',
    ],
    'novoton_holidays.bv_request_data' => [
        'en' => 'Booking data of the request (api_request)',
        'ro' => 'Datele rezervării din cerere (api_request)',
    ],
    'novoton_holidays.bv_request_unavailable' => [
        'en' => 'The request could not be built: [error]',
        'ro' => 'Cererea nu a putut fi construită: [error]',
    ],
    // Guest form date-of-birth checks and the search button's busy label
    // (travel_core travel_i18n.tpl); never seeded before, so they rendered raw.
    'novoton_holidays.invalid_dob_format' => [
        'en' => 'Invalid format. Use DD/MM/YYYY',
        'ro' => 'Format invalid. Folosiți ZZ/LL/AAAA',
    ],
    'novoton_holidays.invalid_day' => [
        'en' => 'The day must be between 1 and 31',
        'ro' => 'Ziua trebuie să fie între 1 și 31',
    ],
    'novoton_holidays.invalid_month' => [
        'en' => 'The month must be between 1 and 12',
        'ro' => 'Luna trebuie să fie între 1 și 12',
    ],
    'novoton_holidays.invalid_year' => [
        'en' => 'Invalid year: enter the full year of birth (e.g. 1985)',
        'ro' => 'An invalid: introduceți anul nașterii complet (ex. 1985)',
    ],
    'novoton_holidays.future_date' => [
        'en' => 'The date cannot be in the future',
        'ro' => 'Data nu poate fi în viitor',
    ],
    'novoton_holidays.searching' => [
        'en' => 'Searching...',
        'ro' => 'Se caută...',
    ],
    // The hotel confirmed a booking on request: the guest's email (RequestAnswerMail).
    'novoton_holidays.request_confirmed_subject' => [
        'en' => 'The hotel confirmed your rooms: [hotel]',
        'ro' => 'Hotelul a confirmat camerele: [hotel]',
    ],
    'novoton_holidays.request_confirmed_title' => [
        'en' => 'The hotel confirmed the rooms you requested',
        'ro' => 'Hotelul a confirmat camerele cerute',
    ],
    'novoton_holidays.request_confirmed_body' => [
        'en' => 'For order #[order_id], [hotel] has confirmed these rooms for [from] – [to]:',
        'ro' => 'Pentru comanda #[order_id], [hotel] a confirmat aceste camere pentru [from] – [to]:',
    ],
    'novoton_holidays.request_confirmed_amount' => [
        'en' => 'These rooms come to [amount].',
        'ro' => 'Valoarea acestor camere: [amount].',
    ],
    'novoton_holidays.request_confirmed_pay' => [
        'en' => 'If you pay by bank transfer, you can pay order #[order_id] now: [total], with the bank details from your order email.',
        'ro' => 'Dacă plătești prin transfer bancar, poți plăti acum comanda #[order_id]: [total], cu datele bancare din emailul comenzii.',
    ],
    'novoton_holidays.request_confirmed_paid' => [
        'en' => 'We have received the payment for your order: there is nothing more to do.',
        'ro' => 'Am primit plata comenzii: nu mai ai nimic de făcut.',
    ],
    'novoton_holidays.request_confirmed_thanks' => [
        'en' => 'Thank you, and have a lovely stay!',
        'ro' => 'Îți mulțumim și îți dorim un sejur minunat!',
    ],
    // Novoton → Hotels: the sync monitor page (novoton_hotels.manage).
    'novoton_holidays.hotels_title' => [
        'en' => 'Hotels',
        'ro' => 'Hoteluri',
    ],
    'novoton_holidays.hotels_page_title' => [
        'en' => 'Novoton — Hotels',
        'ro' => 'Novoton — Hoteluri',
    ],
    'novoton_holidays.hotels_intro' => [
        'en' => 'Every hotel Novoton lists, with when its details were last synced and its CS-Cart product. Hotel data is not edited here: the syncs write it. You can queue hotels for a re-sync and enable or disable their products.',
        'ro' => 'Fiecare hotel listat de Novoton, cu data ultimei sincronizări a detaliilor și produsul său CS-Cart. Datele hotelului nu se editează aici: le scriu sincronizările. Puteți pune hoteluri în așteptare pentru resincronizare și activa sau dezactiva produsele lor.',
    ],
    'novoton_holidays.hotels_summary' => [
        'en' => 'Summary',
        'ro' => 'Sumar',
    ],
    'novoton_holidays.hotels_total' => [
        'en' => 'Hotels',
        'ro' => 'Hoteluri',
    ],
    'novoton_holidays.hotels_last_list' => [
        'en' => 'Last hotel list sync',
        'ro' => 'Ultima sincronizare a listei de hoteluri',
    ],
    'novoton_holidays.hotels_last_info' => [
        'en' => 'Last hotel details sync',
        'ro' => 'Ultima sincronizare a detaliilor de hotel',
    ],
    'novoton_holidays.hotels_search_placeholder' => [
        'en' => 'Hotel name or Novoton ID',
        'ro' => 'Numele hotelului sau ID-ul Novoton',
    ],
    'novoton_holidays.hotels_city_or_region' => [
        'en' => 'City or region',
        'ro' => 'Oraș sau regiune',
    ],
    'novoton_holidays.hotels_not_synced_in' => [
        'en' => 'Not synced in the last',
        'ro' => 'Nesincronizat în ultimele',
    ],
    'novoton_holidays.hotels_any_time' => [
        'en' => 'Any time',
        'ro' => 'Oricând',
    ],
    'novoton_holidays.hotels_n_days' => [
        'en' => '[n] days',
        'ro' => '[n] zile',
    ],
    'novoton_holidays.hotels_col_hotel' => [
        'en' => 'Hotel',
        'ro' => 'Hotel',
    ],
    'novoton_holidays.hotels_col_destination' => [
        'en' => 'Destination',
        'ro' => 'Destinație',
    ],
    'novoton_holidays.hotels_col_stars' => [
        'en' => 'Stars',
        'ro' => 'Stele',
    ],
    'novoton_holidays.hotels_col_synced' => [
        'en' => 'Last sync',
        'ro' => 'Ultima sincronizare',
    ],
    'novoton_holidays.hotels_col_status' => [
        'en' => 'Sync status',
        'ro' => 'Stare sincronizare',
    ],
    'novoton_holidays.hotels_status_active' => [
        'en' => 'Active',
        'ro' => 'Activ',
    ],
    'novoton_holidays.hotels_status_pending' => [
        'en' => 'Waiting for sync',
        'ro' => 'În așteptarea sincronizării',
    ],
    'novoton_holidays.hotels_status_error' => [
        'en' => 'API error',
        'ro' => 'Eroare API',
    ],
    'novoton_holidays.hotels_status_unmapped' => [
        'en' => 'Missing mapping',
        'ro' => 'Fără produs',
    ],
    'novoton_holidays.hotels_status_active_hint' => [
        'en' => 'Its details were synced and it has a CS-Cart product.',
        'ro' => 'Detaliile au fost sincronizate și are un produs CS-Cart.',
    ],
    'novoton_holidays.hotels_status_pending_hint' => [
        'en' => 'Its details were never fetched, or it is queued for a re-sync: the next hotel details run fetches it.',
        'ro' => 'Detaliile nu au fost preluate niciodată sau este în așteptare pentru resincronizare: următoarea rulare a detaliilor de hotel îl preia.',
    ],
    'novoton_holidays.hotels_status_error_hint' => [
        'en' => 'The last request for its details got no answer from Novoton. It is asked again within a day.',
        'ro' => 'Ultima cerere pentru detalii nu a primit răspuns de la Novoton. Se cere din nou în cel mult o zi.',
    ],
    'novoton_holidays.hotels_status_unmapped_hint' => [
        'en' => 'It has no CS-Cart product: none was created for it, or the product was deleted.',
        'ro' => 'Nu are produs CS-Cart: nu a fost creat sau produsul a fost șters.',
    ],
    'novoton_holidays.hotels_days_ago' => [
        'en' => '[n] days ago',
        'ro' => 'acum [n] zile',
    ],
    'novoton_holidays.hotels_never_synced' => [
        'en' => 'Never synced',
        'ro' => 'Nesincronizat niciodată',
    ],
    'novoton_holidays.hotels_tried' => [
        'en' => 'No answer on',
        'ro' => 'Fără răspuns la',
    ],
    'novoton_holidays.hotels_queued' => [
        'en' => 'Queued for re-sync',
        'ro' => 'În așteptare pentru resincronizare',
    ],
    'novoton_holidays.hotels_product_deleted' => [
        'en' => 'Product deleted',
        'ro' => 'Produs șters',
    ],
    'novoton_holidays.hotels_product_disabled' => [
        'en' => 'Product disabled',
        'ro' => 'Produs dezactivat',
    ],
    'novoton_holidays.hotels_product_hidden' => [
        'en' => 'Product hidden',
        'ro' => 'Produs ascuns',
    ],
    'novoton_holidays.hotels_with_selected' => [
        'en' => 'With selected',
        'ro' => 'Cu cele selectate',
    ],
    'novoton_holidays.hotels_select_page' => [
        'en' => 'Select this page',
        'ro' => 'Selectează această pagină',
    ],
    'novoton_holidays.hotels_resync' => [
        'en' => 'Force re-sync',
        'ro' => 'Forțează resincronizarea',
    ],
    'novoton_holidays.hotels_resync_hint' => [
        'en' => 'A re-sync is queued: the next hotel details run fetches the hotel from Novoton.',
        'ro' => 'Resincronizarea se pune în așteptare: următoarea rulare a detaliilor de hotel preia hotelul de la Novoton.',
    ],
    'novoton_holidays.hotels_enable_products' => [
        'en' => 'Enable products',
        'ro' => 'Activează produsele',
    ],
    'novoton_holidays.hotels_disable_products' => [
        'en' => 'Disable products',
        'ro' => 'Dezactivează produsele',
    ],
    'novoton_holidays.hotels_open_product' => [
        'en' => 'Open the CS-Cart product',
        'ro' => 'Deschide produsul CS-Cart',
    ],
    'novoton_holidays.hotels_confirm_resync' => [
        'en' => 'Queue the selected hotels for a re-sync?',
        'ro' => 'Puneți hotelurile selectate în așteptare pentru resincronizare?',
    ],
    'novoton_holidays.hotels_confirm_enable' => [
        'en' => 'Enable the products of the selected hotels?',
        'ro' => 'Activați produsele hotelurilor selectate?',
    ],
    'novoton_holidays.hotels_confirm_disable' => [
        'en' => 'Disable the products of the selected hotels?',
        'ro' => 'Dezactivați produsele hotelurilor selectate?',
    ],
    'novoton_holidays.hotels_resync_queued' => [
        'en' => '[n] hotels queued for re-sync. The next hotel details run fetches them.',
        'ro' => '[n] hoteluri puse în așteptare pentru resincronizare. Următoarea rulare a detaliilor de hotel le preia.',
    ],
    'novoton_holidays.hotels_none_selected' => [
        'en' => 'No hotel was selected.',
        'ro' => 'Niciun hotel selectat.',
    ],
    'novoton_holidays.hotels_products_enabled' => [
        'en' => '[n] products enabled.',
        'ro' => '[n] produse activate.',
    ],
    'novoton_holidays.hotels_products_disabled' => [
        'en' => '[n] products disabled.',
        'ro' => '[n] produse dezactivate.',
    ],
    'novoton_holidays.hotels_no_products_selected' => [
        'en' => 'None of the selected hotels has a product.',
        'ro' => 'Niciunul dintre hotelurile selectate nu are produs.',
    ],
];
