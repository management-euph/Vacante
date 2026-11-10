<?php

declare(strict_types=1);

/**
 * Runtime-seeded language keys for eurosite (novoton pattern).
 *
 * Read by fn_eurosite_language_variables() (func.php); the init.php heal
 * UPSERTs every entry into ?:language_values whenever this file or addon.xml
 * changes. This is what lets NEW or CHANGED labels reach stores that are
 * already installed — CS-Cart imports the .po files only at install time, so
 * a label added later otherwise renders raw ("_eurosite.dashboard").
 *
 * Keep in sync with var/langs/{en,ro}/addons/eurosite.po (this file was
 * generated from them; the po files remain the install-time source).
 *
 * Shape: 'lang.key' => ['en' => '...', 'ro' => '...'].
 */

return [
    'eurosite.addon_name' => [
        'en' => 'Eurosite Touring',
        'ro' => 'Eurosite Touring',
    ],
    'eurosite.dashboard' => [
        'en' => 'Dashboard',
        'ro' => 'Panou de control',
    ],
    'eurosite.dashboard_title' => [
        'en' => 'Eurosite Touring',
        'ro' => 'Eurosite Touring',
    ],
    'eurosite.bookings' => [
        'en' => 'Bookings',
        'ro' => 'Rezervări',
    ],
    'eurosite.destination_whitelist' => [
        'en' => 'Destination whitelist',
        'ro' => 'Listă destinații active',
    ],
    'eurosite.api_connection' => [
        'en' => 'API connection',
        'ro' => 'Conexiune API',
    ],
    'eurosite.api_not_configured' => [
        'en' => 'Eurosite API credentials are not configured — enter them in the addon settings. All catalogs and searches will fail with error -1000 until then.',
        'ro' => 'Credențialele API Eurosite nu sunt configurate — introduceți-le în setările addon-ului. Toate cataloagele și căutările vor eșua cu eroarea -1000 până atunci.',
    ],
    'eurosite.api_user' => [
        'en' => 'API user',
        'ro' => 'Utilizator API',
    ],
    'eurosite.test_connection' => [
        'en' => 'Test API connection',
        'ro' => 'Testează conexiunea API',
    ],
    'eurosite.addon_settings' => [
        'en' => 'Addon settings',
        'ro' => 'Setări addon',
    ],
    'eurosite.catalogs' => [
        'en' => 'Static-data catalogs',
        'ro' => 'Cataloage de date statice',
    ],
    'eurosite.never_synced' => [
        'en' => 'never',
        'ro' => 'niciodată',
    ],
    'eurosite.recent_bookings' => [
        'en' => 'Recent bookings',
        'ro' => 'Rezervări recente',
    ],
    'eurosite.all_bookings' => [
        'en' => 'All Eurosite bookings',
        'ro' => 'Toate rezervările Eurosite',
    ],
    'eurosite.no_bookings' => [
        'en' => 'No bookings yet.',
        'ro' => 'Nicio rezervare încă.',
    ],
    'eurosite.hotel' => [
        'en' => 'Hotel',
        'ro' => 'Hotel',
    ],
    'eurosite.check_in' => [
        'en' => 'Check-in',
        'ro' => 'Check-in',
    ],
    'eurosite.total' => [
        'en' => 'Total',
        'ro' => 'Total',
    ],
    'eurosite.status' => [
        'en' => 'Status',
        'ro' => 'Status',
    ],
    'eurosite.order' => [
        'en' => 'Order',
        'ro' => 'Comandă',
    ],
    'eurosite.created' => [
        'en' => 'Created',
        'ro' => 'Creat',
    ],
    'eurosite.cron_jobs' => [
        'en' => 'Cron jobs',
        'ro' => 'Joburi cron',
    ],
    'eurosite.cron_hint' => [
        'en' => 'Schedule these from the server crontab / cPanel. CLI equivalent:',
        'ro' => 'Programați aceste joburi din crontab / cPanel. Echivalent CLI:',
    ],
    'eurosite.open' => [
        'en' => 'Open',
        'ro' => 'Deschide',
    ],
    'eurosite.whitelist_title' => [
        'en' => 'Eurosite — Destination whitelist',
        'ro' => 'Eurosite — Listă destinații active',
    ],
    'eurosite.whitelist_needs_countries' => [
        'en' => 'The country catalog has not been synced yet — run the \'countries\' sync from the dashboard first. City lists fall back to live API calls.',
        'ro' => 'Catalogul de țări nu a fost încă sincronizat — rulați mai întâi sincronizarea "countries" din panou. Listele de orașe se încarcă direct din API.',
    ],
    'eurosite.pick_cities' => [
        'en' => 'pick specific cities',
        'ro' => 'alege orașe specifice',
    ],
    'eurosite.cities_selected' => [
        'en' => 'cities',
        'ro' => 'orașe',
    ],
    'eurosite.countries' => [
        'en' => 'Countries',
        'ro' => 'Țări',
    ],
    'eurosite.cities' => [
        'en' => 'Cities',
        'ro' => 'Orașe',
    ],
    'eurosite.search_destinations' => [
        'en' => 'Search country or city...',
        'ro' => 'Caută țară sau oraș...',
    ],
    'eurosite.save_whitelist' => [
        'en' => 'Save whitelist',
        'ro' => 'Salvează lista',
    ],
    'eurosite.destination_not_available' => [
        'en' => 'This destination is not available for booking.',
        'ro' => 'Această destinație nu este disponibilă pentru rezervare.',
    ],
    'eurosite.search_failed' => [
        'en' => 'The Eurosite search service did not answer. Please try again later.',
        'ro' => 'Serviciul de căutare Eurosite nu a răspuns. Încercați din nou mai târziu.',
    ],
    'eurosite.no_offers_found' => [
        'en' => 'No offers found for the selected destination and dates. Try different dates.',
        'ro' => 'Nu am găsit oferte pentru destinația și perioada selectate. Încercați alte date.',
    ],
    'eurosite.cancellation_and_payment_terms' => [
        'en' => 'Condiții de Anulare și Plată',
        'ro' => 'Condiții de Anulare și Plată',
    ],
    'eurosite.book_now' => [
        'en' => 'Rezervă',
        'ro' => 'Rezervă',
    ],
    'eurosite.payment_terms' => [
        'en' => 'Termeni de plată',
        'ro' => 'Termeni de plată',
    ],
    'eurosite.cancellation_terms' => [
        'en' => 'Condiții de anulare',
        'ro' => 'Condiții de anulare',
    ],
    'eurosite.fees_confirmed_at_booking' => [
        'en' => 'Condițiile de anulare vor fi confirmate la rezervare.',
        'ro' => 'Condițiile de anulare vor fi confirmate la rezervare.',
    ],
    'eurosite.offer_expired' => [
        'en' => 'The selected offer has expired — please search again.',
        'ro' => 'Oferta selectată a expirat — vă rugăm să căutați din nou.',
    ],
    'eurosite.offer_stop_sale' => [
        'en' => 'This offer is on stop sale and cannot be booked — please choose another.',
        'ro' => 'Această ofertă este în stop sale și nu poate fi rezervată — vă rugăm să alegeți alta.',
    ],
    'eurosite.stop_sale' => [
        'en' => 'Stop sale — not bookable',
        'ro' => 'Stop sale — nu se poate rezerva',
    ],
    'eurosite.guests' => [
        'en' => 'Guests',
        'ro' => 'Turiști',
    ],
    'eurosite.adult' => [
        'en' => 'Adult',
        'ro' => 'Adult',
    ],
    'eurosite.adults' => [
        'en' => 'adulți',
        'ro' => 'adulți',
    ],
    'eurosite.child' => [
        'en' => 'Child',
        'ro' => 'Copil',
    ],
    'eurosite.children' => [
        'en' => 'copii',
        'ro' => 'copii',
    ],
    'eurosite.years' => [
        'en' => 'years',
        'ro' => 'ani',
    ],
    'eurosite.first_name' => [
        'en' => 'First name',
        'ro' => 'Prenume',
    ],
    'eurosite.last_name' => [
        'en' => 'Last name',
        'ro' => 'Nume',
    ],
    'eurosite.date_of_birth' => [
        'en' => 'Date of birth',
        'ro' => 'Data nașterii',
    ],
    'eurosite.gender_male' => [
        'en' => 'M',
        'ro' => 'M',
    ],
    'eurosite.gender_female' => [
        'en' => 'F',
        'ro' => 'F',
    ],
    'eurosite.contact' => [
        'en' => 'Contact',
        'ro' => 'Contact',
    ],
    'eurosite.continue_to_checkout' => [
        'en' => 'Continuă spre checkout',
        'ro' => 'Continuă spre checkout',
    ],
    'eurosite.your_stay' => [
        'en' => 'Sejurul tău',
        'ro' => 'Sejurul tău',
    ],
    'eurosite.on_request_note' => [
        'en' => 'Disponibilitate la cerere — rezervarea se confirmă ulterior.',
        'ro' => 'Disponibilitate la cerere — rezervarea se confirmă ulterior.',
    ],
    'eurosite.guests_invalid' => [
        'en' => 'Please fill in every guest (children need a date of birth).',
        'ro' => 'Completați datele fiecărui turist (copiii au nevoie de data nașterii).',
    ],
    'eurosite.contact_invalid' => [
        'en' => 'Please provide a valid e-mail address and phone number.',
        'ro' => 'Introduceți o adresă de e-mail validă și un număr de telefon.',
    ],
    'eurosite.hotel_not_bookable' => [
        'en' => 'This hotel cannot be booked online yet — please contact us to book it.',
        'ro' => 'Acest hotel nu poate fi rezervat online încă — contactați-ne pentru a-l rezerva.',
    ],
    'eurosite.cart_line_dropped' => [
        'en' => 'This stay could not be added to the cart — please try again or contact us.',
        'ro' => 'Acest sejur nu a putut fi adăugat în coș — încercați din nou sau contactați-ne.',
    ],
    'eurosite.added_to_cart' => [
        'en' => 'Your stay was added to the cart — complete checkout to confirm the reservation.',
        'ro' => 'Sejurul a fost adăugat în coș — finalizați comanda pentru a confirma rezervarea.',
    ],
    'eurosite.whitelist_names_missing' => [
        'en' => 'The country catalog has codes but no names. Run the \'countries\' sync to refill it.',
        'ro' => 'Catalogul de țări are coduri, dar nu și denumiri. Rulați sincronizarea „countries” pentru a-l reface.',
    ],
    'eurosite.sync_countries' => [
        'en' => 'Sync countries',
        'ro' => 'Sincronizează țările',
    ],
    'eurosite.last_synced' => [
        'en' => 'Last synced',
        'ro' => 'Ultima sincronizare',
    ],
    'eurosite.hotels_need_whitelist' => [
        'en' => 'Nothing to fetch: no destinations are whitelisted.',
        'ro' => 'Nu are ce prelua: nicio destinație nu este în listă.',
    ],
    'eurosite.open_whitelist' => [
        'en' => 'Open the whitelist',
        'ro' => 'Deschide lista de destinații',
    ],
    // The dashboard's scheduled jobs on Travel Core's shared cards
    // (Services\CronPlanBuilder, components/job_extra.tpl): stages, jobs, plans, counts.
    'eurosite.jobs_stage_catalogs' => [
        'en' => 'Catalogs',
        'ro' => 'Cataloage',
    ],
    'eurosite.jobs_stage_hotels' => [
        'en' => 'Hotel details and availability',
        'ro' => 'Detaliile hotelurilor și disponibilitatea',
    ],
    'eurosite.jobs_stage_products' => [
        'en' => 'Products',
        'ro' => 'Produse',
    ],
    'eurosite.jobs_stage_upkeep' => [
        'en' => 'Bookings and clean-up',
        'ro' => 'Rezervări și curățenie',
    ],
    'eurosite.jobs_job_full' => [
        'en' => 'All catalogs, each when due',
        'ro' => 'Toate cataloagele, fiecare când îi vine rândul',
    ],
    'eurosite.jobs_job_full_desc' => [
        'en' => 'Runs the seven catalogs below in order, each only when it is due: the monthly ones on the 1st, the hotels and the package routes once a week. One nightly line instead of seven.',
        'ro' => 'Rulează în ordine cele șapte cataloage de mai jos, fiecare doar când îi vine rândul: cele lunare pe 1, hotelurile și rutele pachetelor o dată pe săptămână. O singură linie pe noapte în loc de șapte.',
    ],
    'eurosite.jobs_job_countries' => [
        'en' => 'Countries',
        'ro' => 'Țări',
    ],
    'eurosite.jobs_job_countries_desc' => [
        'en' => 'The countries Eurosite offers.',
        'ro' => 'Țările oferite de Eurosite.',
    ],
    'eurosite.jobs_job_own_cities' => [
        'en' => 'Cities with own offers',
        'ro' => 'Orașe cu oferte proprii',
    ],
    'eurosite.jobs_job_own_cities_desc' => [
        'en' => 'The cities with the operator\'s own offers. Runs before Cities, so their countries get their cities even before anything is whitelisted.',
        'ro' => 'Orașele cu ofertele proprii ale operatorului. Rulează înaintea Orașelor, ca țările lor să-și primească orașele chiar înainte să fie ceva în lista de destinații.',
    ],
    'eurosite.jobs_job_cities' => [
        'en' => 'Cities',
        'ro' => 'Orașe',
    ],
    'eurosite.jobs_job_cities_desc' => [
        'en' => 'The cities of the countries you sell, and of those with own offers.',
        'ro' => 'Orașele țărilor pe care le vinzi și ale celor cu oferte proprii.',
    ],
    'eurosite.jobs_job_hotels' => [
        'en' => 'Hotels',
        'ro' => 'Hoteluri',
    ],
    'eurosite.jobs_job_hotels_desc' => [
        'en' => 'The operator\'s hotels and their rooms, in the whitelisted destinations.',
        'ro' => 'Hotelurile operatorului și camerele lor, din destinațiile din listă.',
    ],
    'eurosite.jobs_job_package_routes' => [
        'en' => 'Package routes',
        'ro' => 'Rutele pachetelor',
    ],
    'eurosite.jobs_job_package_routes_desc' => [
        'en' => 'Coach and plane routes for packages: destinations, departure cities and their dates.',
        'ro' => 'Rutele cu autocarul și cu avionul pentru pachete: destinațiile, orașele de plecare și datele lor.',
    ],
    'eurosite.jobs_job_room_types' => [
        'en' => 'Room types',
        'ro' => 'Tipuri de cameră',
    ],
    'eurosite.jobs_job_room_types_desc' => [
        'en' => 'The catalog of room types.',
        'ro' => 'Catalogul tipurilor de cameră.',
    ],
    'eurosite.jobs_job_tags' => [
        'en' => 'Offer tags',
        'ro' => 'Etichete de ofertă',
    ],
    'eurosite.jobs_job_tags_desc' => [
        'en' => 'The catalog of offer tags. It may be empty.',
        'ro' => 'Catalogul etichetelor de ofertă. Poate fi gol.',
    ],
    'eurosite.jobs_job_product_info' => [
        'en' => 'Hotel details',
        'ro' => 'Detaliile hotelurilor',
    ],
    'eurosite.jobs_job_product_info_desc' => [
        'en' => 'Each synced hotel\'s details from Eurosite, kept for 30 days: a run fetches the missing and older ones, up to the "Hotel details per night" setting.',
        'ro' => 'Detaliile fiecărui hotel sincronizat, de la Eurosite, păstrate 30 de zile: o rulare le aduce pe cele lipsă și pe cele mai vechi, până la limita din setarea „Detalii hoteluri pe noapte”.',
    ],
    'eurosite.jobs_job_availability' => [
        'en' => 'Availability',
        'ro' => 'Disponibilitate',
    ],
    'eurosite.jobs_job_availability_desc' => [
        'en' => 'Which hotels in the whitelisted destinations have an Immediate offer, on the near and the peak-season dates. Hides the products of the others and shows them again when they have one.',
        'ro' => 'Ce hoteluri din destinațiile din listă au ofertă Imediat, la datele apropiate și în sezonul de vârf. Ascunde produsele celorlalte și le arată din nou când au una.',
    ],
    'eurosite.jobs_job_add_products' => [
        'en' => 'Add products',
        'ro' => 'Adaugă produse',
    ],
    'eurosite.jobs_job_add_products_desc' => [
        'en' => 'A product for each hotel with an Immediate offer and pictures, in the whitelisted destinations.',
        'ro' => 'Un produs pentru fiecare hotel cu ofertă Imediat și poze, din destinațiile din listă.',
    ],
    'eurosite.jobs_job_update_products' => [
        'en' => 'Update products',
        'ro' => 'Actualizează produsele',
    ],
    'eurosite.jobs_job_update_products_desc' => [
        'en' => 'Brings the Eurosite products up to date: price, description, pictures and features.',
        'ro' => 'Aduce la zi produsele Eurosite: prețul, descrierea, pozele și caracteristicile.',
    ],
    'eurosite.jobs_job_booking_status' => [
        'en' => 'Booking confirmations',
        'ro' => 'Confirmările rezervărilor',
    ],
    'eurosite.jobs_job_booking_status_desc' => [
        'en' => 'Checks the bookings waiting for Eurosite\'s confirmation, on their schedule: 30 minutes after booking, then every 30 minutes for 2 hours, and again 8 hours later.',
        'ro' => 'Verifică rezervările care așteaptă confirmarea Eurosite, după programul lor: la 30 de minute după rezervare, apoi la fiecare 30 de minute timp de 2 ore și din nou după 8 ore.',
    ],
    'eurosite.jobs_job_cleanup' => [
        'en' => 'Clean-up',
        'ro' => 'Curățenie',
    ],
    'eurosite.jobs_job_cleanup_desc' => [
        'en' => 'Trims the sync log and removes the details of hotels that no longer exist.',
        'ro' => 'Scurtează jurnalul sincronizărilor și șterge detaliile hotelurilor care nu mai există.',
    ],
    'eurosite.jobs_full_force' => [
        'en' => 'Run every catalog now',
        'ro' => 'Rulează acum toate cataloagele',
    ],
    'eurosite.jobs_full_force_confirm' => [
        'en' => 'Run every catalog now, also the ones not due yet? It makes many API calls.',
        'ro' => 'Rulezi acum toate cataloagele, și pe cele care nu sunt încă la rând? Face multe apeluri API.',
    ],
    'eurosite.jobs_on_demand_intro' => [
        'en' => 'Jobs that are not on the schedule: run them from here when needed.',
        'ro' => 'Joburi care nu sunt în program: rulează-le de aici când e nevoie.',
    ],
    'eurosite.jobs_unknown' => [
        'en' => 'Unknown job: [job]',
        'ro' => 'Job necunoscut: [job]',
    ],
    'eurosite.jobs_plan_full' => [
        'en' => 'Catalogs in one nightly run (recommended)',
        'ro' => 'Cataloagele într-o singură rulare pe noapte (recomandat)',
    ],
    'eurosite.jobs_plan_full_hint' => [
        'en' => 'One line at 01:00 runs the catalogs, each only when it is due; the other jobs keep their own lines. Install one plan only: both would run every catalog twice.',
        'ro' => 'O linie la 01:00 rulează cataloagele, fiecare doar când îi vine rândul; celelalte joburi își păstrează liniile lor. Instalează un singur plan: amândouă ar rula fiecare catalog de două ori.',
    ],
    'eurosite.jobs_plan_per' => [
        'en' => 'Each catalog on its own line',
        'ro' => 'Fiecare catalog pe linia lui',
    ],
    'eurosite.jobs_plan_per_hint' => [
        'en' => 'Each catalog at its own time, to move one of them elsewhere. Install one plan only: both would run every catalog twice.',
        'ro' => 'Fiecare catalog la ora lui, ca să poți muta unul dintre ele. Instalează un singur plan: amândouă ar rula fiecare catalog de două ori.',
    ],
    'eurosite.jobs_count_countries' => [
        'en' => 'Countries: [n]',
        'ro' => 'Țări: [n]',
    ],
    'eurosite.jobs_count_own_cities' => [
        'en' => 'Cities with own offers: [n]',
        'ro' => 'Orașe cu oferte proprii: [n]',
    ],
    'eurosite.jobs_count_cities' => [
        'en' => 'Cities: [n]',
        'ro' => 'Orașe: [n]',
    ],
    'eurosite.jobs_count_hotels' => [
        'en' => 'Hotels: [n]',
        'ro' => 'Hoteluri: [n]',
    ],
    'eurosite.jobs_count_package_routes' => [
        'en' => 'Package routes: [n]',
        'ro' => 'Rute de pachete: [n]',
    ],
    'eurosite.jobs_count_room_types' => [
        'en' => 'Room types: [n]',
        'ro' => 'Tipuri de cameră: [n]',
    ],
    'eurosite.jobs_count_tags' => [
        'en' => 'Offer tags: [n]',
        'ro' => 'Etichete de ofertă: [n]',
    ],
    'eurosite.jobs_count_cache' => [
        'en' => 'Hotels with details: [n]',
        'ro' => 'Hoteluri cu detalii: [n]',
    ],
    'eurosite.jobs_count_immediate' => [
        'en' => 'Hotels with an Immediate offer: [n]',
        'ro' => 'Hoteluri cu ofertă Imediat: [n]',
    ],
    'eurosite.jobs_count_products' => [
        'en' => 'Products: [n]',
        'ro' => 'Produse: [n]',
    ],
    'eurosite.package_routes' => [
        'en' => 'Package routes',
        'ro' => 'Rutele pachetelor',
    ],
    'eurosite.package_routes_title' => [
        'en' => 'Eurosite — Package routes',
        'ro' => 'Eurosite — Rutele pachetelor',
    ],
    'eurosite.routes_count' => [
        'en' => '[n] package routes',
        'ro' => '[n] rute de pachete',
    ],
    'eurosite.sync_now' => [
        'en' => 'Sync now',
        'ro' => 'Sincronizează acum',
    ],
    'eurosite.transport' => [
        'en' => 'Transport',
        'ro' => 'Transport',
    ],
    'eurosite.all_transports' => [
        'en' => 'All',
        'ro' => 'Toate',
    ],
    'eurosite.transport_plane' => [
        'en' => 'Plane',
        'ro' => 'Avion',
    ],
    'eurosite.transport_bus' => [
        'en' => 'Coach',
        'ro' => 'Autocar',
    ],
    'eurosite.departure' => [
        'en' => 'Departure',
        'ro' => 'Plecare',
    ],
    'eurosite.arrival' => [
        'en' => 'Arrival',
        'ro' => 'Sosire',
    ],
    'eurosite.duration' => [
        'en' => 'Duration',
        'ro' => 'Durată',
    ],
    'eurosite.available_dates' => [
        'en' => 'Available dates',
        'ro' => 'Date disponibile',
    ],
    'eurosite.routes_nights' => [
        'en' => '[nights] nights',
        'ro' => '[nights] nopți',
    ],
    'eurosite.routes_nights_from_setting' => [
        'en' => 'from the settings',
        'ro' => 'din setări',
    ],
    'eurosite.routes_more_dates' => [
        'en' => '+[n] more',
        'ro' => '+încă [n]',
    ],
    'eurosite.routes_no_dates_ahead' => [
        'en' => 'No dates ahead',
        'ro' => 'Nicio dată de acum înainte',
    ],
    'eurosite.no_routes_match' => [
        'en' => 'No route matches this search.',
        'ro' => 'Nicio rută nu se potrivește acestei căutări.',
    ],
    'eurosite.no_package_routes' => [
        'en' => 'No package routes yet. Sync now fetches them from Eurosite; if none come back, ask Eurosite to enable packages (Pachete) for your XML user.',
        'ro' => 'Încă nu există rute de pachete. „Sincronizează acum” le aduce de la Eurosite; dacă nu vine niciuna, cereți Eurosite să activeze pachetele (Pachete) pentru utilizatorul XML.',
    ],
    'eurosite.package_kind_flight' => [
        'en' => 'Package · flight + hotel',
        'ro' => 'Pachet · avion + hotel',
    ],
    'eurosite.package_kind_bus' => [
        'en' => 'Package · coach + hotel',
        'ro' => 'Pachet · autocar + hotel',
    ],
    'eurosite.package_outbound' => [
        'en' => 'Outbound',
        'ro' => 'Dus',
    ],
    'eurosite.package_return' => [
        'en' => 'Return',
        'ro' => 'Retur',
    ],
    'eurosite.package_coach' => [
        'en' => 'Coach',
        'ro' => 'Autocar',
    ],
    'eurosite.package_unavailable' => [
        'en' => 'Eurosite no longer has this package as it was offered. Please choose a departure again.',
        'ro' => 'Eurosite nu mai are acest pachet așa cum a fost oferit. Vă rugăm să alegeți din nou o plecare.',
    ],
    'eurosite.package_price_changed' => [
        'en' => 'Eurosite\'s price for this package has changed: it is now [price].',
        'ro' => 'Prețul Eurosite pentru acest pachet s-a schimbat: acum este [price].',
    ],
    'eurosite.package_dob_required' => [
        'en' => 'Every traveller needs a date of birth: the airline or the coach operator asks for the passenger list.',
        'ro' => 'Fiecare călător are nevoie de data nașterii: compania aeriană sau operatorul de autocar cere lista pasagerilor.',
    ],
    // The dashboard's At a glance tiles, Needs attention and Tools
    // (Services\DashboardSummary, views/eurosite/manage.tpl).
    'eurosite.dash_tile_hotels' => [
        'en' => 'Hotels in the destinations sold',
        'ro' => 'Hoteluri în destinațiile vândute',
    ],
    'eurosite.dash_tile_hotels_cities' => [
        'en' => 'Cities sold: [n]',
        'ro' => 'Orașe vândute: [n]',
    ],
    'eurosite.dash_tile_hotels_synced' => [
        'en' => 'Last hotel sync: [date]',
        'ro' => 'Ultima sincronizare a hotelurilor: [date]',
    ],
    'eurosite.dash_tile_hotels_never' => [
        'en' => 'The hotels have not been synced yet',
        'ro' => 'Hotelurile nu au fost sincronizate încă',
    ],
    'eurosite.dash_tile_offers' => [
        'en' => 'With an Immediate offer',
        'ro' => 'Cu ofertă Imediat',
    ],
    'eurosite.dash_tile_offers_note' => [
        'en' => 'On request: [or] · stop sale: [st] · no offer: [none]',
        'ro' => 'La cerere: [or] · stop vânzare: [st] · fără ofertă: [none]',
    ],
    'eurosite.dash_tile_offers_unchecked' => [
        'en' => 'Not checked yet: [n]',
        'ro' => 'Neverificate încă: [n]',
    ],
    'eurosite.dash_tile_offers_checked' => [
        'en' => 'Last check: [date]',
        'ro' => 'Ultima verificare: [date]',
    ],
    'eurosite.dash_tile_offers_never' => [
        'en' => 'Availability has not been checked yet',
        'ro' => 'Disponibilitatea nu a fost verificată încă',
    ],
    'eurosite.dash_tile_offers_open' => [
        'en' => 'See them',
        'ro' => 'Vezi-le',
    ],
    'eurosite.dash_tile_products' => [
        'en' => 'Products',
        'ro' => 'Produse',
    ],
    'eurosite.dash_tile_products_live' => [
        'en' => 'Live in the destinations you sell: [n]',
        'ro' => 'Active în destinațiile pe care le vinzi: [n]',
    ],
    'eurosite.dash_tile_products_hidden' => [
        'en' => 'Hidden, no Immediate offer: [n]',
        'ro' => 'Ascunse, fără ofertă Imediat: [n]',
    ],
    'eurosite.dash_tile_products_waiting' => [
        'en' => 'Immediate, no product yet: [n]',
        'ro' => 'Cu ofertă Imediat, încă fără produs: [n]',
    ],
    'eurosite.dash_tile_products_open' => [
        'en' => 'See the hotels with a product',
        'ro' => 'Vezi hotelurile cu produs',
    ],
    'eurosite.dash_attention_title' => [
        'en' => 'Needs attention ([n])',
        'ro' => 'Necesită atenție ([n])',
    ],
    'eurosite.dash_attention_refused' => [
        'en' => 'A cron request with a wrong key was refused on [date]: the crontab is probably still on an old key.',
        'ro' => 'O cerere cron cu o cheie greșită a fost refuzată pe [date]: crontab-ul folosește probabil încă o cheie veche.',
    ],
    'eurosite.dash_attention_copy_commands' => [
        'en' => 'Copy the commands again',
        'ro' => 'Copiază din nou comenzile',
    ],
    'eurosite.dash_attention_nothing_sold' => [
        'en' => 'No destination is whitelisted: the hotel sync has nothing to fetch, and nothing can be sold.',
        'ro' => 'Nicio destinație nu este în listă: sincronizarea hotelurilor nu are ce prelua și nu se poate vinde nimic.',
    ],
    'eurosite.dash_attention_no_immediate' => [
        'en' => 'No listed hotel has an Immediate offer on the configured dates, so none can become a product.',
        'ro' => 'Niciun hotel din listă nu are ofertă Imediat la datele configurate, deci niciunul nu poate deveni produs.',
    ],
    'eurosite.dash_attention_failed' => [
        'en' => '[job] failed on [date].',
        'ro' => '[job] a eșuat pe [date].',
    ],
    'eurosite.dash_attention_stalled' => [
        'en' => '[job] started on [date] and never finished.',
        'ro' => '[job] a pornit pe [date] și nu s-a mai terminat.',
    ],
    'eurosite.dash_attention_never' => [
        'en' => '[job] has never run.',
        'ro' => '[job] nu a rulat niciodată.',
    ],
    'eurosite.dash_attention_late' => [
        'en' => '[job] is late: it last finished on [date]. Schedule: [schedule].',
        'ro' => '[job] întârzie: s-a terminat ultima dată pe [date]. Program: [schedule].',
    ],
    'eurosite.dash_attention_run' => [
        'en' => 'Run [job]',
        'ro' => 'Rulează [job]',
    ],
    'eurosite.dash_tools_title' => [
        'en' => 'Tools',
        'ro' => 'Instrumente',
    ],
    'eurosite.dash_tools_intro' => [
        'en' => 'Syncing is each job\'s Run, under Scheduled jobs.',
        'ro' => 'Sincronizările se pornesc cu Rulează, la fiecare job din Joburi programate.',
    ],
    // The dashboard's Recent sync activity (Services\SyncActivity, components/activity.tpl).
    'eurosite.dash_activity_title' => [
        'en' => 'Recent sync activity',
        'ro' => 'Activitate recentă de sincronizare',
    ],
    'eurosite.dash_activity_filter' => [
        'en' => 'Which runs',
        'ro' => 'Ce rulări',
    ],
    'eurosite.dash_activity_all' => [
        'en' => 'All ([n])',
        'ro' => 'Toate ([n])',
    ],
    'eurosite.dash_activity_problems' => [
        'en' => 'Problems ([n])',
        'ro' => 'Probleme ([n])',
    ],
    'eurosite.dash_activity_col_date' => [
        'en' => 'Date',
        'ro' => 'Data',
    ],
    'eurosite.dash_activity_col_job' => [
        'en' => 'Job',
        'ro' => 'Job',
    ],
    'eurosite.dash_activity_col_items' => [
        'en' => 'Synced',
        'ro' => 'Sincronizate',
    ],
    'eurosite.dash_activity_col_failed' => [
        'en' => 'Failed',
        'ro' => 'Eșuate',
    ],
    'eurosite.dash_activity_col_status' => [
        'en' => 'Status',
        'ro' => 'Stare',
    ],
    'eurosite.dash_activity_col_duration' => [
        'en' => 'Duration',
        'ro' => 'Durată',
    ],
    'eurosite.dash_activity_items' => [
        'en' => '[n] of [total]',
        'ro' => '[n] din [total]',
    ],
    'eurosite.dash_activity_ok' => [
        'en' => 'Completed',
        'ro' => 'Finalizată',
    ],
    'eurosite.dash_activity_partial' => [
        'en' => 'Partly done',
        'ro' => 'Parțial',
    ],
    'eurosite.dash_activity_failed' => [
        'en' => 'Failed',
        'ro' => 'Eșuată',
    ],
    'eurosite.dash_activity_stalled' => [
        'en' => 'Never finished',
        'ro' => 'Neterminată',
    ],
    'eurosite.dash_activity_running' => [
        'en' => 'Running',
        'ro' => 'În curs',
    ],
    'eurosite.dash_activity_show_error' => [
        'en' => 'Show full error',
        'ro' => 'Arată eroarea completă',
    ],
    'eurosite.dash_activity_none' => [
        'en' => 'No sync runs logged yet.',
        'ro' => 'Nicio sincronizare înregistrată încă.',
    ],
    'eurosite.dash_activity_no_problems' => [
        'en' => 'No problems in the log.',
        'ro' => 'Nicio problemă în jurnal.',
    ],
    // Eurosite → Hotels, and the product jobs
    'eurosite.hotels' => [
        'en' => 'Hotels',
        'ro' => 'Hoteluri',
    ],
    'eurosite.hotels_title' => [
        'en' => 'Eurosite — Hotels',
        'ro' => 'Eurosite — Hoteluri',
    ],
    'eurosite.hotels_intro' => [
        'en' => 'Hotels from your whitelisted destinations, with what the live price search says about them. Only hotels with an Immediate offer can become products.',
        'ro' => 'Hotelurile din destinațiile active, cu ce spune despre ele căutarea live de prețuri. Doar hotelurile cu ofertă Imediată pot deveni produse.',
    ],
    'eurosite.hotels_tile_listed' => [
        'en' => 'In the whitelist',
        'ro' => 'În lista de destinații',
    ],
    'eurosite.hotels_in_n_destinations' => [
        'en' => 'hotels in [n] destinations',
        'ro' => 'hoteluri în [n] destinații',
    ],
    'eurosite.hotels_tile_checked' => [
        'en' => 'Checked for availability',
        'ro' => 'Verificate pentru disponibilitate',
    ],
    'eurosite.hotels_last_check' => [
        'en' => 'last check [when]',
        'ro' => 'ultima verificare [when]',
    ],
    'eurosite.hotels_never_checked' => [
        'en' => 'not checked yet',
        'ro' => 'neverificate încă',
    ],
    'eurosite.hotels_can_become_products' => [
        'en' => 'can become products',
        'ro' => 'pot deveni produse',
    ],
    'eurosite.hotels_n_not_fetched' => [
        'en' => '[n] not fetched yet',
        'ro' => '[n] încă neaduse',
    ],
    'eurosite.products' => [
        'en' => 'Products',
        'ro' => 'Produse',
    ],
    'eurosite.hotels_n_hidden_by_check' => [
        'en' => '[n] hidden: no Immediate offer',
        'ro' => '[n] ascunse: fără ofertă Imediată',
    ],
    'eurosite.hotels_all_visible' => [
        'en' => 'all visible',
        'ro' => 'toate vizibile',
    ],
    'eurosite.hotels_none_created' => [
        'en' => 'none created yet',
        'ro' => 'niciunul creat încă',
    ],
    'eurosite.hotels_whitelist_note' => [
        'en' => 'Only hotels from whitelisted destinations are listed, checked and made into products.',
        'ro' => 'Doar hotelurile din destinațiile active sunt listate, verificate și transformate în produse.',
    ],
    'eurosite.hotels_hidden_note' => [
        'en' => '[n] hotels from [d] destinations outside it are hidden and kept; whitelist a destination and they return on the next hotels sync.',
        'ro' => '[n] hoteluri din [d] destinații din afara listei sunt ascunse și păstrate; activați o destinație și revin la următoarea sincronizare a hotelurilor.',
    ],
    'eurosite.edit_whitelist' => [
        'en' => 'Edit whitelist',
        'ro' => 'Editează lista de destinații',
    ],
    'eurosite.hotels_last_sync' => [
        'en' => 'last hotels sync [when]',
        'ro' => 'ultima sincronizare a hotelurilor [when]',
    ],
    'eurosite.hotels_no_root_category' => [
        'en' => 'Choose "CS-Cart category ID for Eurosite hotels" in the add-on settings before creating products: products go under it, then country, then destination.',
        'ro' => 'Alegeți „ID categorie CS-Cart pentru hoteluri Eurosite” în setările addon-ului înainte de a crea produse: produsele se creează sub ea, apoi pe țară, apoi pe destinație.',
    ],
    'eurosite.destination' => [
        'en' => 'Destination',
        'ro' => 'Destinație',
    ],
    'eurosite.all_destinations' => [
        'en' => 'All destinations',
        'ro' => 'Toate destinațiile',
    ],
    'eurosite.hotels_search_placeholder' => [
        'en' => 'Hotel name or code',
        'ro' => 'Nume sau cod hotel',
    ],
    'eurosite.hotels_all' => [
        'en' => 'All',
        'ro' => 'Toate',
    ],
    'eurosite.col_hotel' => [
        'en' => 'Hotel',
        'ro' => 'Hotel',
    ],
    'eurosite.col_stars' => [
        'en' => 'Stars',
        'ro' => 'Stele',
    ],
    'eurosite.col_availability' => [
        'en' => 'Availability',
        'ro' => 'Disponibilitate',
    ],
    'eurosite.col_images' => [
        'en' => 'Images',
        'ro' => 'Imagini',
    ],
    'eurosite.col_price' => [
        'en' => 'From (stay checked)',
        'ro' => 'De la (sejurul verificat)',
    ],
    'eurosite.col_product' => [
        'en' => 'Product',
        'ro' => 'Produs',
    ],
    'eurosite.avail_im' => [
        'en' => 'Immediate',
        'ro' => 'Imediat',
    ],
    'eurosite.avail_or' => [
        'en' => 'On request',
        'ro' => 'La cerere',
    ],
    'eurosite.avail_st' => [
        'en' => 'Stop sale',
        'ro' => 'Stop vânzare',
    ],
    'eurosite.avail_none' => [
        'en' => 'No offer',
        'ro' => 'Fără ofertă',
    ],
    'eurosite.avail_unchecked' => [
        'en' => 'Not checked',
        'ro' => 'Neverificat',
    ],
    'eurosite.hotels_season' => [
        'en' => 'season',
        'ro' => 'sezon',
    ],
    'eurosite.hotels_gross' => [
        'en' => 'gross',
        'ro' => 'brut',
    ],
    'eurosite.images_with' => [
        'en' => 'With images',
        'ro' => 'Cu imagini',
    ],
    'eurosite.images_without' => [
        'en' => 'Without images',
        'ro' => 'Fără imagini',
    ],
    'eurosite.images_not_fetched' => [
        'en' => 'Not fetched yet',
        'ro' => 'Încă neaduse',
    ],
    'eurosite.images_none' => [
        'en' => 'No pictures',
        'ro' => 'Fără poze',
    ],
    'eurosite.images_cover' => [
        'en' => 'Cover image',
        'ro' => 'Imagine de copertă',
    ],
    'eurosite.images_n_pictures' => [
        'en' => '[n] pictures',
        'ro' => '[n] poze',
    ],
    'eurosite.product_yes' => [
        'en' => 'Products',
        'ro' => 'Produse',
    ],
    'eurosite.product_no' => [
        'en' => 'Not products',
        'ro' => 'Fără produs',
    ],
    'eurosite.product_hidden_by_check' => [
        'en' => 'Hidden: no Immediate offer',
        'ro' => 'Ascuns: fără ofertă Imediată',
    ],
    'eurosite.product_hidden' => [
        'en' => 'Hidden',
        'ro' => 'Ascuns',
    ],
    'eurosite.product_disabled' => [
        'en' => 'Disabled',
        'ro' => 'Dezactivat',
    ],
    'eurosite.product_missing' => [
        'en' => 'Product deleted',
        'ro' => 'Produs șters',
    ],
    'eurosite.product_details_first' => [
        'en' => 'details fetched first',
        'ro' => 'detaliile se aduc întâi',
    ],
    'eurosite.hotels_selected' => [
        'en' => 'selected',
        'ro' => 'selectate',
    ],
    'eurosite.hotels_will_be_skipped' => [
        'en' => 'will be skipped',
        'ro' => 'vor fi omise',
    ],
    'eurosite.hotels_select_eligible' => [
        'en' => 'Select all that can become products',
        'ro' => 'Selectează toate care pot deveni produse',
    ],
    'eurosite.hotels_clear' => [
        'en' => 'Clear',
        'ro' => 'Golește',
    ],
    'eurosite.hotels_select_page' => [
        'en' => 'Select this page',
        'ro' => 'Selectează pagina',
    ],
    'eurosite.hotels_check_now' => [
        'en' => 'Check availability now',
        'ro' => 'Verifică disponibilitatea acum',
    ],
    'eurosite.hotels_create_products' => [
        'en' => 'Create products',
        'ro' => 'Creează produse',
    ],
    'eurosite.hotels_confirm_create' => [
        'en' => 'Create [n] products? Hotels that cannot become products are skipped, with the reason.',
        'ro' => 'Creați [n] produse? Hotelurile care nu pot deveni produse sunt omise, cu motivul.',
    ],
    'eurosite.hotels_confirm_check' => [
        'en' => 'Check availability for the destinations of the selected hotels now? This can take a minute per destination.',
        'ro' => 'Verificați acum disponibilitatea pentru destinațiile hotelurilor selectate? Poate dura un minut pe destinație.',
    ],
    'eurosite.hotels_confirm_check_all' => [
        'en' => 'No hotel is selected: check every listed destination now? This can take several minutes.',
        'ro' => 'Niciun hotel selectat: verificați acum toate destinațiile listate? Poate dura câteva minute.',
    ],
    'eurosite.hotels_select_first' => [
        'en' => 'Select the hotels first.',
        'ro' => 'Selectați întâi hotelurile.',
    ],
    'eurosite.hotels_checked' => [
        'en' => 'Availability checked.',
        'ro' => 'Disponibilitatea a fost verificată.',
    ],
    'eurosite.hotels_check_failed' => [
        'en' => 'The availability check failed:',
        'ro' => 'Verificarea disponibilității a eșuat:',
    ],
    'eurosite.hotels_created_n' => [
        'en' => '[n] products created',
        'ro' => '[n] produse create',
    ],
    'eurosite.hotels_linked_n' => [
        'en' => '[n] linked to existing products',
        'ro' => '[n] legate de produse existente',
    ],
    'eurosite.hotels_failed_n' => [
        'en' => '[n] failed (see the log)',
        'ro' => '[n] eșuate (vedeți jurnalul)',
    ],
    'eurosite.hotels_skipped' => [
        'en' => 'Skipped:',
        'ro' => 'Omise:',
    ],
    'eurosite.hotels_no_match' => [
        'en' => 'No hotel matches these filters.',
        'ro' => 'Niciun hotel nu corespunde filtrelor.',
    ],
    'eurosite.hotels_empty' => [
        'en' => 'No hotels listed yet. Whitelist destinations, then run the hotels sync from the dashboard.',
        'ro' => 'Niciun hotel listat încă. Activați destinații, apoi rulați sincronizarea hotelurilor din panoul de control.',
    ],
    'eurosite.open_hotel_list' => [
        'en' => 'Open hotel list',
        'ro' => 'Deschide lista de hoteluri',
    ],
    'eurosite.skip_already_product' => [
        'en' => 'already a product',
        'ro' => 'este deja produs',
    ],
    'eurosite.skip_not_whitelisted' => [
        'en' => 'destination not whitelisted',
        'ro' => 'destinație inactivă',
    ],
    'eurosite.skip_not_checked' => [
        'en' => 'availability not checked yet',
        'ro' => 'disponibilitate neverificată',
    ],
    'eurosite.skip_on_request' => [
        'en' => 'On request only: not bookable immediately',
        'ro' => 'Doar la cerere: nu se poate rezerva imediat',
    ],
    'eurosite.skip_stop_sale' => [
        'en' => 'Stop sale: not bookable at all',
        'ro' => 'Stop vânzare: nu se poate rezerva',
    ],
    'eurosite.skip_no_offer' => [
        'en' => 'no offer on the checked dates',
        'ro' => 'nicio ofertă la datele verificate',
    ],
    'eurosite.skip_no_images' => [
        'en' => 'no images',
        'ro' => 'fără imagini',
    ],
    'eurosite.skip_no_root_category' => [
        'en' => 'hotels root category not set',
        'ro' => 'categoria rădăcină nu este setată',
    ],
    'eurosite.skip_category_failed' => [
        'en' => 'category could not be created',
        'ro' => 'categoria nu a putut fi creată',
    ],
    'eurosite.skip_creation_failed' => [
        'en' => 'product could not be created',
        'ro' => 'produsul nu a putut fi creat',
    ],
    // Eurosite → Hotels: mockup layout
    'eurosite.hotels_sort_by_images_hint' => [
        'en' => 'Ascending puts hotels without images first.',
        'ro' => 'Crescător pune întâi hotelurile fără imagini.',
    ],
    'eurosite.hotels_summary' => [
        'en' => 'Summary',
        'ro' => 'Rezumat',
    ],
    'eurosite.hotels_details_hint' => [
        'en' => 'The hotel details (pictures, description) are fetched by the product_info job, or when a product is created.',
        'ro' => 'Detaliile hotelului (poze, descriere) sunt aduse de jobul product_info sau la crearea produsului.',
    ],
    'eurosite.product_can_create' => [
        'en' => 'Can become a product',
        'ro' => 'Poate deveni produs',
    ],
    // Whitelist: own-hotels filter
    // Whitelist: own cities per country
    // Whitelist: select all own cities
    // SEO Templates page (Travel Core's shared page)
    'eurosite.seo_templates' => [
        'en' => 'SEO Templates',
        'ro' => 'Șabloane SEO',
    ],
    'eurosite.seo_templates_title' => [
        'en' => 'Eurosite — SEO Templates',
        'ro' => 'Eurosite — Șabloane SEO',
    ],
    'eurosite.seo_placeholders_title' => [
        'en' => 'Eurosite placeholders',
        'ro' => 'Placeholder-e Eurosite',
    ],
    'eurosite.complete_booking' => [
        'en' => 'Complete Your Booking',
        'ro' => 'Finalizează rezervarea',
    ],
    'eurosite.cancel_fee_line' => [
        'en' => 'Between [from] - [to]: [value] penalty',
        'ro' => 'Între [from] - [to]: penalizare [value]',
    ],
    'eurosite.add_to_cart' => [
        'en' => 'Add to Cart',
        'ro' => 'Adaugă în coș',
    ],
    'eurosite.too_many_rooms' => [
        'en' => 'You can book up to [count] rooms at once. Please search for fewer rooms, or contact us.',
        'ro' => 'Puteți rezerva cel mult [count] camere o dată. Căutați pentru mai puține camere sau contactați-ne.',
    ],
    // Multi-room offers: one line per room on the result card
    'eurosite.room_n' => [
        'en' => 'Room [num]',
        'ro' => 'Camera [num]',
    ],
    'eurosite.adults_one' => [
        'en' => '1 adult',
        'ro' => '1 adult',
    ],
    'eurosite.adults_many' => [
        'en' => '[count] adults',
        'ro' => '[count] adulți',
    ],
    'eurosite.children_one' => [
        'en' => '1 child',
        'ro' => '1 copil',
    ],
    'eurosite.children_many' => [
        'en' => '[count] children',
        'ro' => '[count] copii',
    ],
    'eurosite.total_for_rooms' => [
        'en' => 'Total for [count] rooms',
        'ro' => 'Total pentru [count] camere',
    ],
    'eurosite.probe_title' => [
        'en' => 'Find dates with offers',
        'ro' => 'Găsește date cu oferte',
    ],
    'eurosite.probe_hint' => [
        'en' => 'Read-only: asks Eurosite, destination by destination, for check-ins every 14 days over the next months and lists the dates with Immediate, On request and Stop sale hotels, plus the first five Immediate hotels. Nothing is stored. Leave the destinations empty to check every synced one of the country; put the dates that answer in the peak-season dates setting, then run availability.',
        'ro' => 'Doar citire: întreabă Eurosite, destinație cu destinație, pentru check-in la fiecare 14 zile în lunile următoare și listează datele cu hoteluri Imediat, La cerere și Stop vânzări, plus primele cinci hoteluri Imediat. Nu se salvează nimic. Lăsați destinațiile goale pentru a verifica toate destinațiile sincronizate ale țării; puneți datele care răspund în setarea datelor de sezon, apoi rulați availability.',
    ],
    'eurosite.probe_country' => [
        'en' => 'Country',
        'ro' => 'Țară',
    ],
    'eurosite.probe_cities' => [
        'en' => 'Destinations',
        'ro' => 'Destinații',
    ],
    'eurosite.probe_from' => [
        'en' => 'First check-in',
        'ro' => 'Primul check-in',
    ],
    'eurosite.probe_nights' => [
        'en' => 'Nights',
        'ro' => 'Nopți',
    ],
    'eurosite.probe_months' => [
        'en' => 'Months',
        'ro' => 'Luni',
    ],
    'eurosite.probe_run' => [
        'en' => 'Search offers',
        'ro' => 'Caută oferte',
    ],
    'eurosite.probe_none_hint' => [
        'en' => 'No Immediate hotel on the configured dates. Find dates with offers, then add them to the peak-season dates.',
        'ro' => 'Niciun hotel Imediat la datele configurate. Găsiți date cu oferte, apoi adăugați-le la datele de sezon.',
    ],
    'eurosite.probe_cities_all' => [
        'en' => 'empty = all synced',
        'ro' => 'gol = toate sincronizate',
    ],
    // Destination whitelist (Travel Core's destination picker) and the dashboard Destinations card.
    'eurosite.dest_mode_off' => [
        'en' => 'Not sold',
        'ro' => 'Nu se vinde',
    ],
    'eurosite.dest_mode_all' => [
        'en' => 'All cities',
        'ro' => 'Toate orașele',
    ],
    'eurosite.dest_mode_own' => [
        'en' => 'Own cities',
        'ro' => 'Orașe proprii',
    ],
    'eurosite.dest_mode_specific' => [
        'en' => 'Only selected',
        'ro' => 'Doar cele bifate',
    ],
    'eurosite.dest_hint_off' => [
        'en' => 'Nothing is synced or sold in this country.',
        'ro' => 'Nimic nu se sincronizează și nu se vinde în această țară.',
    ],
    'eurosite.dest_hint_all' => [
        'en' => 'Every city is sold, including ones Eurosite adds later.',
        'ro' => 'Se vând toate orașele, inclusiv cele adăugate ulterior de Eurosite.',
    ],
    'eurosite.dest_hint_own' => [
        'en' => 'Every own-offer city is sold, including ones Eurosite adds later. The other cities are not sold.',
        'ro' => 'Se vând toate orașele cu oferte proprii, inclusiv cele adăugate ulterior de Eurosite. Celelalte orașe nu se vând.',
    ],
    'eurosite.dest_hint_specific' => [
        'en' => 'Tick the cities to sell. New cities wait for you.',
        'ro' => 'Bifați orașele de vândut. Orașele noi vă așteaptă.',
    ],
    'eurosite.dest_badge_off' => [
        'en' => 'NOT SOLD',
        'ro' => 'NU SE VINDE',
    ],
    'eurosite.dest_badge_all' => [
        'en' => 'ALL',
        'ro' => 'TOATE',
    ],
    'eurosite.dest_badge_own' => [
        'en' => 'OWN ([sold])',
        'ro' => 'PROPRII ([sold])',
    ],
    'eurosite.dest_badge_some' => [
        'en' => '[sold] of [total]',
        'ro' => '[sold] din [total]',
    ],
    'eurosite.dest_tile_countries' => [
        'en' => 'Countries sold',
        'ro' => 'Țări vândute',
    ],
    'eurosite.dest_tile_cities' => [
        'en' => 'Cities sold',
        'ro' => 'Orașe vândute',
    ],
    'eurosite.dest_tile_cities_note' => [
        'en' => '[n] with own offers',
        'ro' => '[n] cu oferte proprii',
    ],
    'eurosite.dest_tile_hotels' => [
        'en' => 'Hotels in scope',
        'ro' => 'Hoteluri incluse',
    ],
    'eurosite.dest_tile_hotels_note' => [
        'en' => '[priced] with prices · [instant] instant',
        'ro' => '[priced] cu prețuri · [instant] imediat',
    ],
    'eurosite.dest_tile_new' => [
        'en' => 'New cities to review',
        'ro' => 'Orașe noi de verificat',
    ],
    'eurosite.dest_not_configured' => [
        'en' => '<strong>No whitelist saved yet.</strong> Nothing is synced or sold from Eurosite until you choose at least one country and save.',
        'ro' => '<strong>Nicio listă salvată încă.</strong> Nu se sincronizează și nu se vinde nimic de la Eurosite până nu alegeți cel puțin o țară și salvați.',
    ],
    'eurosite.dest_new_alert' => [
        'en' => '[n] new {city|cities} appeared in the cities sync. {It is|They are} not sold until you tick {it|them}.',
        'ro' => '[n] {oraș nou a apărut|orașe noi au apărut|de orașe noi au apărut} la sincronizarea orașelor. Nu se {vinde|vând|vând} până nu {îl bifați|le bifați|le bifați}.',
    ],
    'eurosite.dest_show_new' => [
        'en' => 'Show new cities',
        'ro' => 'Arată orașele noi',
    ],
    'eurosite.dest_gone_alert' => [
        'en' => '[n] ticked {city is|cities are} no longer in the Eurosite catalog (crossed out). {It stays|They stay} ticked until you untick {it|them}.',
        'ro' => '[n] {oraș bifat nu mai este|orașe bifate nu mai sunt|de orașe bifate nu mai sunt} în catalogul Eurosite (tăiate). {Rămâne bifat până îl debifați|Rămân bifate până le debifați|Rămân bifate până le debifați}.',
    ],
    'eurosite.dest_intro' => [
        'en' => 'Choose what we sell from Eurosite. Only hotels in these destinations are synced and shown on the storefront; hotels outside are hidden at the next hotel sync, and live products stay until you disable them. "All cities" and "Own cities" include cities Eurosite adds later; "Only selected" waits for you to tick them.',
        'ro' => 'Alegeți ce vindem de la Eurosite. Doar hotelurile din aceste destinații se sincronizează și apar în magazin; cele din afară se ascund la următoarea sincronizare a hotelurilor, iar produsele active rămân până le dezactivați. „Toate orașele" și „Orașe proprii" includ și orașele adăugate ulterior de Eurosite; „Doar cele bifate" așteaptă să le bifați.',
    ],
    'eurosite.dest_country_meta' => [
        'en' => '[cities] {city|cities} · [hotels] {hotel|hotels}',
        'ro' => '[cities] {oraș|orașe|de orașe} · [hotels] {hotel|hoteluri|de hoteluri}',
    ],
    'eurosite.dest_country_meta_none' => [
        'en' => '[cities] {city|cities} · hotels not synced yet',
        'ro' => '[cities] {oraș|orașe|de orașe} · hoteluri nesincronizate încă',
    ],
    'eurosite.dest_no_cities' => [
        'en' => 'No cities are synced for [country] yet. Choose "All cities" and the next cities sync brings them in.',
        'ro' => 'Nu există încă orașe sincronizate pentru [country]. Alegeți „Toate orașele" și următoarea sincronizare le aduce.',
    ],
    'eurosite.dest_city_meta' => [
        'en' => '[hotels] {hotel|hotels} · [priced] with prices · [instant] instant',
        'ro' => '[hotels] {hotel|hoteluri|de hoteluri} · [priced] cu prețuri · [instant] imediat',
    ],
    'eurosite.dest_city_not_synced' => [
        'en' => 'Hotels not synced yet',
        'ro' => 'Hoteluri nesincronizate încă',
    ],
    'eurosite.dest_city_gone' => [
        'en' => 'No longer in the Eurosite catalog',
        'ro' => 'Nu mai este în catalogul Eurosite',
    ],
    'eurosite.dest_own_n' => [
        'en' => '★ [n] own',
        'ro' => '★ [n] proprii',
    ],
    'eurosite.dest_own' => [
        'en' => '★ own',
        'ro' => '★ propriu',
    ],
    'eurosite.dest_own_title' => [
        'en' => 'Cities with the operator\'s own offers',
        'ro' => 'Orașe cu ofertele proprii ale operatorului',
    ],
    'eurosite.dest_filter_own' => [
        'en' => 'Countries with own offers only',
        'ro' => 'Doar țările cu oferte proprii',
    ],
    'eurosite.dest_chip_own' => [
        'en' => '★ Own',
        'ro' => '★ Proprii',
    ],
    'eurosite.dest_select_own' => [
        'en' => '★ Select own cities',
        'ro' => '★ Bifează orașele proprii',
    ],
    'eurosite.dest_sort_own' => [
        'en' => 'Own first',
        'ro' => 'Întâi cele proprii',
    ],
    'eurosite.dest_sort_az' => [
        'en' => 'A–Z',
        'ro' => 'A–Z',
    ],
    'eurosite.dest_sort_hotels' => [
        'en' => 'Most hotels',
        'ro' => 'Cele mai multe hoteluri',
    ],
    'eurosite.dest_search' => [
        'en' => 'Search countries, cities or codes…',
        'ro' => 'Caută țări, orașe sau coduri…',
    ],
    'eurosite.dest_filter' => [
        'en' => 'Filter cities…',
        'ro' => 'Filtrează orașele…',
    ],
    'eurosite.dest_item_type' => [
        'en' => 'City',
        'ro' => 'Oraș',
    ],
    'eurosite.dest_gone' => [
        'en' => 'not in catalog',
        'ro' => 'nu mai e în catalog',
    ],
    'eurosite.dest_no_match' => [
        'en' => 'No country or city matches.',
        'ro' => 'Nicio țară sau oraș nu se potrivește.',
    ],
    'eurosite.dest_fold' => [
        'en' => '[n] more country has no cities synced and is not sold.|[n] more countries have no cities synced and are not sold.',
        'ro' => 'Încă [n] țară nu are orașe sincronizate și nu se vinde.|Încă [n] țări nu au orașe sincronizate și nu se vând.|Încă [n] de țări nu au orașe sincronizate și nu se vând.',
    ],
    'eurosite.dest_catalog' => [
        'en' => 'Country catalog: [countries] countries, [cities] cities synced ([own] with own offers)',
        'ro' => 'Catalogul de țări: [countries] țări, [cities] orașe sincronizate ([own] cu oferte proprii)',
    ],
    'eurosite.dest_saved' => [
        'en' => 'Whitelist saved: [countries] countries, [cities] selected cities. The next hotel sync uses it.',
        'ro' => 'Lista a fost salvată: [countries] țări, [cities] orașe bifate. Următoarea sincronizare a hotelurilor o folosește.',
    ],
    'eurosite.dest_card_title' => [
        'en' => 'Destinations',
        'ro' => 'Destinații',
    ],
    'eurosite.dest_card_intro' => [
        'en' => '[countries] countries, [cities] cities sold.',
        'ro' => '[countries] țări, [cities] orașe vândute.',
    ],
    'eurosite.dest_card_not_configured' => [
        'en' => 'No whitelist saved yet: nothing is synced or sold.',
        'ro' => 'Nicio listă salvată încă: nu se sincronizează și nu se vinde nimic.',
    ],
    'eurosite.dest_col_instant' => [
        'en' => 'Instant',
        'ro' => 'Imediat',
    ],
    'eurosite.dest_col_live' => [
        'en' => 'Live products',
        'ro' => 'Produse active',
    ],
    'eurosite.dest_card_n_cities' => [
        'en' => '[n] cities',
        'ro' => '[n] orașe',
    ],
    'eurosite.dest_card_cities' => [
        'en' => 'cities',
        'ro' => 'orașe',
    ],
    'eurosite.dest_alert_new' => [
        'en' => '[n] new {city|cities} in [country] {waits|wait} for review.',
        'ro' => '[n] {oraș nou|orașe noi|de orașe noi} din [country] {așteaptă|așteaptă|așteaptă} verificarea.',
    ],
    'eurosite.dest_alert_gone' => [
        'en' => '[n] sold {city is|cities are} no longer in the Eurosite catalog.',
        'ro' => '[n] {oraș vândut nu mai este|orașe vândute nu mai sunt|de orașe vândute nu mai sunt} în catalogul Eurosite.',
    ],
    'eurosite.dest_alert_outside' => [
        'en' => '[n] live {product is|products are} outside the destination whitelist.',
        'ro' => '[n] {produs activ este|produse active sunt|de produse active sunt} în afara listei de destinații.',
    ],
    // Room types (getRoomRequest, GCode) as offers show them: RoomTypeNames.
    'eurosite.room_type_ap' => [
        'en' => 'Apartment',
        'ro' => 'Apartament',
    ],
    'eurosite.room_type_d2x' => [
        'en' => 'Double room with 2 children',
        'ro' => 'Cameră dublă cu 2 copii',
    ],
    'eurosite.room_type_d3c' => [
        'en' => 'Double room, 3 adults, 1 child under 2 years',
        'ro' => 'Cameră dublă, 3 adulți, 1 copil sub 2 ani',
    ],
    'eurosite.room_type_d3x' => [
        'en' => 'Double room, 3 adults, 1 child over 2 years',
        'ro' => 'Cameră dublă, 3 adulți, 1 copil peste 2 ani',
    ],
    'eurosite.room_type_db' => [
        'en' => 'Double room',
        'ro' => 'Cameră dublă',
    ],
    'eurosite.room_type_db3' => [
        'en' => 'Double room, 3 adults',
        'ro' => 'Cameră dublă, 3 adulți',
    ],
    'eurosite.room_type_dbc' => [
        'en' => 'Double room with child under 2 years',
        'ro' => 'Cameră dublă cu copil sub 2 ani',
    ],
    'eurosite.room_type_dbx' => [
        'en' => 'Double room with child over 2 years',
        'ro' => 'Cameră dublă cu copil peste 2 ani',
    ],
    'eurosite.room_type_q' => [
        'en' => 'Quad room',
        'ro' => 'Cameră cvadruplă',
    ],
    'eurosite.room_type_s2x' => [
        'en' => 'Single room used as double, with 2 children',
        'ro' => 'Cameră single folosită ca dublă, cu 2 copii',
    ],
    'eurosite.room_type_sb' => [
        'en' => 'Single room',
        'ro' => 'Cameră single',
    ],
    'eurosite.room_type_sbc' => [
        'en' => 'Single room with child under 2 years',
        'ro' => 'Cameră single cu copil sub 2 ani',
    ],
    'eurosite.room_type_sd' => [
        'en' => 'Studio',
        'ro' => 'Studio',
    ],
    'eurosite.room_type_tb' => [
        'en' => 'Twin room (separate beds)',
        'ro' => 'Cameră twin (paturi separate)',
    ],
    'eurosite.room_type_tbc' => [
        'en' => 'Twin room with child under 2 years',
        'ro' => 'Cameră twin cu copil sub 2 ani',
    ],
    'eurosite.room_type_tbx' => [
        'en' => 'Twin room with child over 2 years',
        'ro' => 'Cameră twin cu copil peste 2 ani',
    ],
    'eurosite.room_type_tr' => [
        'en' => 'Triple room',
        'ro' => 'Cameră triplă',
    ],
    'eurosite.room_type_trc' => [
        'en' => 'Triple room with child under 2 years',
        'ro' => 'Cameră triplă cu copil sub 2 ani',
    ],
    'eurosite.room_type_ts' => [
        'en' => 'Twin room for sole use',
        'ro' => 'Cameră twin în regim single',
    ],
    'eurosite.room_type_tsc' => [
        'en' => 'Twin room for sole use, with child under 2 years',
        'ro' => 'Cameră twin în regim single, cu copil sub 2 ani',
    ],
    'eurosite.room_type_tsx' => [
        'en' => 'Twin room for sole use, with child over 2 years',
        'ro' => 'Cameră twin în regim single, cu copil peste 2 ani',
    ],
    'eurosite.room_type_vl' => [
        'en' => 'Villa',
        'ro' => 'Vilă',
    ],
    // Meal plans as offers show them, read from the hotel's own text: MealPlanNames.
    'eurosite.meal_plan_ultra_all_inclusive' => [
        'en' => 'Ultra all inclusive',
        'ro' => 'Ultra all inclusive',
    ],
    'eurosite.meal_plan_all_inclusive' => [
        'en' => 'All inclusive',
        'ro' => 'All inclusive',
    ],
    'eurosite.meal_plan_full_board' => [
        'en' => 'Full board',
        'ro' => 'Pensiune completă',
    ],
    'eurosite.meal_plan_half_board' => [
        'en' => 'Half board',
        'ro' => 'Demipensiune',
    ],
    'eurosite.meal_plan_breakfast' => [
        'en' => 'Breakfast',
        'ro' => 'Mic dejun',
    ],
    'eurosite.meal_plan_self_catering' => [
        'en' => 'Self catering',
        'ro' => 'Self catering',
    ],
    'eurosite.meal_plan_meal_vouchers' => [
        'en' => 'Meal vouchers',
        'ro' => 'Bonuri valorice',
    ],
    'eurosite.meal_plan_room_only' => [
        'en' => 'Room only',
        'ro' => 'Fără masă',
    ],
];
