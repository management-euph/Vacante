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
    'novoton_holidays.dash_jobs_title' => [
        'en' => 'Scheduled jobs',
        'ro' => 'Joburi programate',
    ],
    'novoton_holidays.dash_jobs_intro' => [
        'en' => 'In the order the jobs run: each stage needs the one before it. Run works as a signed-in admin action, so the cron key never goes into the browser. ⋯ copies a job\'s crontab line, URL or CLI command with the real key.',
        'ro' => 'În ordinea în care rulează: fiecare etapă are nevoie de cea dinainte. Rulează funcționează ca acțiune de administrator autentificat, așa că cheia cron nu ajunge niciodată în browser. ⋯ copiază linia crontab, URL-ul sau comanda CLI a jobului, cu cheia reală.',
    ],
    'novoton_holidays.dash_col_job' => [
        'en' => 'Job',
        'ro' => 'Job',
    ],
    'novoton_holidays.dash_col_schedule' => [
        'en' => 'Schedule',
        'ro' => 'Program',
    ],
    'novoton_holidays.dash_col_last_run' => [
        'en' => 'Last run',
        'ro' => 'Ultima rulare',
    ],
    'novoton_holidays.dash_col_actions' => [
        'en' => 'Actions',
        'ro' => 'Acțiuni',
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
    'novoton_holidays.dash_recommended' => [
        'en' => 'Recommended',
        'ro' => 'Recomandat',
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
        'en' => 'Hotel details, in batches that resume; later runs re-sync changed hotels',
        'ro' => 'Detaliile hotelurilor, în loturi care se reiau; rulările următoare resincronizează hotelurile modificate',
    ],
    'novoton_holidays.dash_job_hotel_facilities_batched' => [
        'en' => 'Hotel facilities',
        'ro' => 'Facilitățile hotelurilor',
    ],
    'novoton_holidays.dash_job_hotel_facilities_batched_desc' => [
        'en' => 'Which facilities each hotel has, in batches',
        'ro' => 'Ce facilități are fiecare hotel, în loturi',
    ],
    'novoton_holidays.dash_job_geocode_addresses' => [
        'en' => 'Geocode streets',
        'ro' => 'Geocodare străzi',
    ],
    'novoton_holidays.dash_job_geocode_addresses_desc' => [
        'en' => 'Street address from coordinates (needs geocoding on in Travel Core)',
        'ro' => 'Adresa străzii din coordonate (necesită geocodarea activă în Travel Core)',
    ],
    'novoton_holidays.dash_job_sync_priceinfo_batched' => [
        'en' => 'Price info',
        'ro' => 'Informații prețuri',
    ],
    'novoton_holidays.dash_job_sync_priceinfo_batched_desc' => [
        'en' => 'Season prices, in batches; a full re-sync every 7 days',
        'ro' => 'Prețurile de sezon, în loturi; resincronizare completă la 7 zile',
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
        'en' => 'Room price',
        'ro' => 'Preț cameră',
    ],
    'novoton_holidays.dash_job_room_price_desc' => [
        'en' => 'Marks the hotels that have real-time prices',
        'ro' => 'Marchează hotelurile care au prețuri în timp real',
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
    'novoton_holidays.dash_job_offers_update' => [
        'en' => 'Offers update',
        'ro' => 'Actualizare oferte',
    ],
    'novoton_holidays.dash_job_offers_update_desc' => [
        'en' => 'New and changed offers since the last run',
        'ro' => 'Ofertele noi și modificate de la ultima rulare',
    ],
    'novoton_holidays.dash_job_resinfo' => [
        'en' => 'Booking status',
        'ro' => 'Starea rezervărilor',
    ],
    'novoton_holidays.dash_job_resinfo_desc' => [
        'en' => 'Checks on-request (ASK) bookings',
        'ro' => 'Verifică rezervările la cerere (ASK)',
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
    'novoton_holidays.dash_sched_every_minutes' => [
        'en' => 'Every [n] min',
        'ro' => 'La fiecare [n] min',
    ],
    'novoton_holidays.dash_sched_hourly' => [
        'en' => 'Hourly at :[minute]',
        'ro' => 'Din oră în oră, la :[minute]',
    ],
    'novoton_holidays.dash_sched_every_hours' => [
        'en' => 'Every [n] hours at :[minute]',
        'ro' => 'La fiecare [n] ore, la :[minute]',
    ],
    'novoton_holidays.dash_sched_daily' => [
        'en' => 'Daily [time]',
        'ro' => 'Zilnic [time]',
    ],
    'novoton_holidays.dash_sched_every_days' => [
        'en' => 'Every [n] days, [time]',
        'ro' => 'La fiecare [n] zile, [time]',
    ],
    'novoton_holidays.dash_sched_weekly_sun' => [
        'en' => 'Sundays [time]',
        'ro' => 'Duminica [time]',
    ],
    'novoton_holidays.dash_sched_weekly_mon' => [
        'en' => 'Mondays [time]',
        'ro' => 'Lunea [time]',
    ],
    'novoton_holidays.dash_sched_weekly_tue' => [
        'en' => 'Tuesdays [time]',
        'ro' => 'Marțea [time]',
    ],
    'novoton_holidays.dash_sched_weekly_wed' => [
        'en' => 'Wednesdays [time]',
        'ro' => 'Miercurea [time]',
    ],
    'novoton_holidays.dash_sched_weekly_thu' => [
        'en' => 'Thursdays [time]',
        'ro' => 'Joia [time]',
    ],
    'novoton_holidays.dash_sched_weekly_fri' => [
        'en' => 'Fridays [time]',
        'ro' => 'Vinerea [time]',
    ],
    'novoton_holidays.dash_sched_weekly_sat' => [
        'en' => 'Saturdays [time]',
        'ro' => 'Sâmbăta [time]',
    ],
    'novoton_holidays.dash_sched_raw' => [
        'en' => '[cron]',
        'ro' => '[cron]',
    ],
    'novoton_holidays.dash_sched_on_demand' => [
        'en' => 'On demand',
        'ro' => 'La cerere',
    ],
    'novoton_holidays.dash_run_ok' => [
        'en' => 'On time',
        'ro' => 'La timp',
    ],
    'novoton_holidays.dash_run_late' => [
        'en' => 'Late',
        'ro' => 'Întârziat',
    ],
    'novoton_holidays.dash_run_failed' => [
        'en' => 'Failed',
        'ro' => 'Eșuat',
    ],
    'novoton_holidays.dash_run_running' => [
        'en' => 'Running',
        'ro' => 'Rulează',
    ],
    'novoton_holidays.dash_run_stalled' => [
        'en' => 'Stalled',
        'ro' => 'Blocat',
    ],
    'novoton_holidays.dash_run_never' => [
        'en' => 'No runs yet',
        'ro' => 'Nicio rulare încă',
    ],
    'novoton_holidays.dash_run_finished_at' => [
        'en' => 'Finished [date]',
        'ro' => 'Terminat [date]',
    ],
    'novoton_holidays.dash_run_started_at' => [
        'en' => 'Started [date]',
        'ro' => 'Pornit [date]',
    ],
    'novoton_holidays.dash_run_stalled_hint' => [
        'en' => 'Started [date] and never finished',
        'ro' => 'Pornit [date] și neterminat',
    ],
    'novoton_holidays.dash_run' => [
        'en' => 'Run',
        'ro' => 'Rulează',
    ],
    'novoton_holidays.dash_more_actions' => [
        'en' => 'More actions',
        'ro' => 'Mai multe acțiuni',
    ],
    'novoton_holidays.dash_copy_crontab_line' => [
        'en' => 'Copy crontab line',
        'ro' => 'Copiază linia crontab',
    ],
    'novoton_holidays.dash_copy_url' => [
        'en' => 'Copy URL',
        'ro' => 'Copiază URL-ul',
    ],
    'novoton_holidays.dash_copy_cli' => [
        'en' => 'Copy CLI command',
        'ro' => 'Copiază comanda CLI',
    ],
    'novoton_holidays.dash_copied' => [
        'en' => 'Copied',
        'ro' => 'Copiat',
    ],
    'novoton_holidays.dash_copy_failed' => [
        'en' => 'Copy failed',
        'ro' => 'Copierea a eșuat',
    ],
    'novoton_holidays.dash_check_status' => [
        'en' => 'Check status',
        'ro' => 'Verifică starea',
    ],
    'novoton_holidays.dash_force_full' => [
        'en' => 'Force full sync',
        'ro' => 'Forțează sincronizarea completă',
    ],
    'novoton_holidays.dash_force_full_confirm' => [
        'en' => 'Run a full sync of [job] now? It re-reads every hotel and makes many API calls.',
        'ro' => 'Rulezi acum sincronizarea completă pentru [job]? Recitește toate hotelurile și face multe apeluri API.',
    ],
    'novoton_holidays.dash_reset_progress' => [
        'en' => 'Reset progress…',
        'ro' => 'Resetează progresul…',
    ],
    'novoton_holidays.dash_reset_confirm' => [
        'en' => 'Reset the progress of [job]? Its next run starts again from the first hotel.',
        'ro' => 'Resetezi progresul pentru [job]? Următoarea rulare începe din nou de la primul hotel.',
    ],
    'novoton_holidays.dash_menu_needs_key' => [
        'en' => 'Set the cron key in Travel Core to copy commands.',
        'ro' => 'Setează cheia cron în Travel Core pentru a copia comenzile.',
    ],
    'novoton_holidays.dash_on_demand_title' => [
        'en' => 'Other jobs, run on demand ([n])',
        'ro' => 'Alte joburi, rulate la cerere ([n])',
    ],
    'novoton_holidays.dash_on_demand_intro' => [
        'en' => 'Not part of the schedule: Full sync repeats jobs from the stages above, and the alternative-request jobs follow bookings.',
        'ro' => 'Nu fac parte din program: Sincronizarea completă repetă joburi din etapele de mai sus, iar joburile pentru cereri de alternative urmează rezervările.',
    ],
    'novoton_holidays.dash_commands_title' => [
        'en' => 'All commands',
        'ro' => 'Toate comenzile',
    ],
    'novoton_holidays.dash_commands_hint' => [
        'en' => 'CLI (php): paste into the server crontab or cPanel → Cron Jobs. URL: for a cron service; add each one at the time shown.',
        'ro' => 'CLI (php): lipește în crontab-ul serverului sau în cPanel → Cron Jobs. URL: pentru un serviciu cron; adaugă fiecare adresă la ora arătată.',
    ],
    'novoton_holidays.dash_format_label' => [
        'en' => 'Command format',
        'ro' => 'Formatul comenzii',
    ],
    'novoton_holidays.dash_format_cli' => [
        'en' => 'CLI (php)',
        'ro' => 'CLI (php)',
    ],
    'novoton_holidays.dash_format_url' => [
        'en' => 'URL',
        'ro' => 'URL',
    ],
    'novoton_holidays.dash_copy_all' => [
        'en' => 'Copy all',
        'ro' => 'Copiază tot',
    ],
    'novoton_holidays.dash_key_hidden_note' => [
        'en' => 'The key is hidden here; Copy copies the real one. Anyone with the key can start these jobs; rotate it on Travel Core → Tools.',
        'ro' => 'Cheia e ascunsă aici; Copiază copiază cheia reală. Oricine are cheia poate porni aceste joburi; o poți schimba în Travel Core → Instrumente.',
    ],
    'novoton_holidays.dash_open_tools' => [
        'en' => 'Open Travel Core → Tools',
        'ro' => 'Deschide Travel Core → Instrumente',
    ],
    'novoton_holidays.dash_no_key_title' => [
        'en' => 'No cron key is set',
        'ro' => 'Nu este setată nicio cheie cron',
    ],
    'novoton_holidays.dash_no_key_body' => [
        'en' => 'Scheduled runs are refused until the shared cron key is set in Travel Core. Run still works from here.',
        'ro' => 'Rulările programate sunt refuzate până când cheia cron comună este setată în Travel Core. Rulează funcționează în continuare de aici.',
    ],
    'novoton_holidays.dash_set_key' => [
        'en' => 'Set it in Travel Core',
        'ro' => 'Seteaz-o în Travel Core',
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
    'novoton_holidays.dash_tile_jobs_failing' => [
        'en' => 'Failing: [n]',
        'ro' => 'Cu erori: [n]',
    ],
    'novoton_holidays.dash_tile_jobs_late' => [
        'en' => 'Late: [n]',
        'ro' => 'Întârziate: [n]',
    ],
    'novoton_holidays.dash_tile_jobs_never' => [
        'en' => 'Not run yet: [n]',
        'ro' => 'Nerulate încă: [n]',
    ],
    'novoton_holidays.dash_tile_jobs_ok' => [
        'en' => 'All on time',
        'ro' => 'Toate la timp',
    ],
    'novoton_holidays.dash_tile_jobs_note' => [
        'en' => 'On time: [ok] · late: [late] · no runs yet: [never]',
        'ro' => 'La timp: [ok] · întârziate: [late] · nerulate încă: [never]',
    ],
    'novoton_holidays.dash_tile_jobs_open' => [
        'en' => 'See the jobs ↓',
        'ro' => 'Vezi joburile ↓',
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
];
