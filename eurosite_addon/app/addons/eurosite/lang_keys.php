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
    'eurosite.catalog' => [
        'en' => 'Catalog',
        'ro' => 'Catalog',
    ],
    'eurosite.rows' => [
        'en' => 'Rows',
        'ro' => 'Rânduri',
    ],
    'eurosite.last_sync' => [
        'en' => 'Last sync',
        'ro' => 'Ultima sincronizare',
    ],
    'eurosite.never_synced' => [
        'en' => 'never',
        'ro' => 'niciodată',
    ],
    'eurosite.sync_now' => [
        'en' => 'Sync now',
        'ro' => 'Sincronizează acum',
    ],
    'eurosite.sync_full' => [
        'en' => 'Run full sync',
        'ro' => 'Rulează sincronizarea completă',
    ],
    'eurosite.sync_full_confirm' => [
        'en' => 'Run the full static-data sync now? This makes many API calls.',
        'ro' => 'Rulați acum sincronizarea completă a datelor statice? Se fac multe apeluri API.',
    ],
    'eurosite.seed_menu' => [
        'en' => 'Seed storefront menu',
        'ro' => 'Creează meniul din storefront',
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
    'eurosite.whitelist_hint' => [
        'en' => 'Only whitelisted destinations are synced and searchable on the storefront. Tick a country to include all of its cities, or expand it and pick specific cities.',
        'ro' => 'Doar destinațiile active sunt sincronizate și căutabile în storefront. Bifați o țară pentru toate orașele ei sau expandați și alegeți orașe specifice.',
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
    'eurosite.no_cities' => [
        'en' => 'No cities found.',
        'ro' => 'Niciun oraș găsit.',
    ],
    'eurosite.countries' => [
        'en' => 'Countries',
        'ro' => 'Țări',
    ],
    'eurosite.cities' => [
        'en' => 'Cities',
        'ro' => 'Orașe',
    ],
    'eurosite.own_offer_cities' => [
        'en' => 'Own-offer cities',
        'ro' => 'Orașe cu oferte proprii',
    ],
    'eurosite.search_destinations' => [
        'en' => 'Search country or city...',
        'ro' => 'Caută țară sau oraș...',
    ],
    'eurosite.show_whitelisted_only' => [
        'en' => 'Show only whitelisted',
        'ro' => 'Afișează doar destinațiile selectate',
    ],
    'eurosite.shown' => [
        'en' => 'shown',
        'ro' => 'afișate',
    ],
    'eurosite.select_all_cities' => [
        'en' => 'Select all cities',
        'ro' => 'Selectează toate orașele',
    ],
    'eurosite.all_cities_included' => [
        'en' => 'ALL CITIES',
        'ro' => 'TOATE ORAȘELE',
    ],
    'eurosite.selected' => [
        'en' => 'selected',
        'ro' => 'selectate',
    ],
    'eurosite.whitelist_summary' => [
        'en' => 'Whitelist summary',
        'ro' => 'Sumar destinații',
    ],
    'eurosite.whitelisted_countries' => [
        'en' => 'Whitelisted countries',
        'ro' => 'Țări selectate',
    ],
    'eurosite.whitelisted_cities' => [
        'en' => 'Whitelisted cities',
        'ro' => 'Orașe selectate',
    ],
    'eurosite.save_whitelist' => [
        'en' => 'Save whitelist',
        'ro' => 'Salvează lista',
    ],
    'eurosite.remove_all' => [
        'en' => 'Remove all',
        'ro' => 'Elimină tot',
    ],
    'eurosite.confirm_remove_all' => [
        'en' => 'Remove all whitelisted destinations? Click Save to persist.',
        'ro' => 'Eliminați toate destinațiile selectate? Apăsați Salvează pentru a persista.',
    ],
    'eurosite.request_failed' => [
        'en' => 'Request failed.',
        'ro' => 'Cererea a eșuat.',
    ],
    'eurosite.back_to_dashboard' => [
        'en' => 'Back to dashboard',
        'ro' => 'Înapoi la panou',
    ],
    'eurosite.country' => [
        'en' => 'Country',
        'ro' => 'Țara',
    ],
    'eurosite.city' => [
        'en' => 'City / resort',
        'ro' => 'Oraș / stațiune',
    ],
    'eurosite.pick_country' => [
        'en' => '— country —',
        'ro' => '— țara —',
    ],
    'eurosite.pick_city' => [
        'en' => '— city —',
        'ro' => '— orașul —',
    ],
    'eurosite.no_destinations_configured' => [
        'en' => 'No destinations are enabled yet. Please check back soon.',
        'ro' => 'Nicio destinație activă momentan. Reveniți în curând.',
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
    'eurosite.module_packages' => [
        'en' => 'Pachete Touroperator',
        'ro' => 'Pachete Touroperator',
    ],
    'eurosite.module_transport' => [
        'en' => 'Transport Touroperator',
        'ro' => 'Transport Touroperator',
    ],
    'eurosite.module_circuits' => [
        'en' => 'Circuite Touroperator',
        'ro' => 'Circuite Touroperator',
    ],
    'eurosite.module_coming_soon' => [
        'en' => 'This Eurosite module is coming soon.',
        'ro' => 'Acest modul Eurosite va fi disponibil în curând.',
    ],
    'eurosite.module_try_hotels' => [
        'en' => 'Search hotel stays instead',
        'ro' => 'Caută sejururi hoteliere',
    ],
    'eurosite.offer_expired' => [
        'en' => 'The selected offer has expired — please search again.',
        'ro' => 'Oferta selectată a expirat — vă rugăm să căutați din nou.',
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
    'eurosite.carrier_missing' => [
        'en' => 'Booking checkout is not fully configured yet — please contact us to finish this reservation.',
        'ro' => 'Finalizarea rezervării nu este configurată complet — contactați-ne pentru a încheia rezervarea.',
    ],
    'eurosite.added_to_cart' => [
        'en' => 'Your stay was added to the cart — complete checkout to confirm the reservation.',
        'ro' => 'Sejurul a fost adăugat în coș — finalizați comanda pentru a confirma rezervarea.',
    ],
    'eurosite.no_search_results' => [
        'en' => 'No matches.',
        'ro' => 'Niciun rezultat.',
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
    'eurosite.cron_commands' => [
        'en' => 'Cron commands',
        'ro' => 'Comenzi cron',
    ],
    'eurosite.cron_col_mode' => [
        'en' => 'Mode',
        'ro' => 'Mod',
    ],
    'eurosite.cron_col_description' => [
        'en' => 'What it does',
        'ro' => 'Ce face',
    ],
    'eurosite.cron_col_schedule' => [
        'en' => 'Schedule',
        'ro' => 'Programare',
    ],
    'eurosite.cron_col_url' => [
        'en' => 'URL',
        'ro' => 'URL',
    ],
    'eurosite.cron_col_actions' => [
        'en' => 'Actions',
        'ro' => 'Acțiuni',
    ],
    'eurosite.run' => [
        'en' => 'Run',
        'ro' => 'Rulează',
    ],
    'eurosite.copy' => [
        'en' => 'Copy',
        'ro' => 'Copiază',
    ],
    'eurosite.copy_url' => [
        'en' => 'Copy URL',
        'ro' => 'Copiază URL',
    ],
    'eurosite.copy_cli' => [
        'en' => 'Copy CLI',
        'ro' => 'Copiază CLI',
    ],
    'eurosite.copied' => [
        'en' => 'Copied',
        'ro' => 'Copiat',
    ],
    'eurosite.copy_failed' => [
        'en' => 'Copy failed',
        'ro' => 'Copierea a eșuat',
    ],
    'eurosite.cron_access_key_note' => [
        'en' => 'The access key in these URLs is the addon\'s cron key — treat them as secrets.',
        'ro' => 'Cheia de acces din aceste linkuri este cheia cron a modulului — tratați-le ca date secrete.',
    ],
];
