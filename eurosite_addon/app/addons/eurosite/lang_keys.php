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
    'eurosite.cron_commands' => [
        'en' => 'Scheduled jobs',
        'ro' => 'Sarcini programate',
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
        'en' => 'The access key in these URLs is the shared Travel Core cron key — treat them as secrets. Rotating it re-issues the Travel Core, Sphinx and Novoton commands too.',
        'ro' => 'Cheia de acces din aceste linkuri este cheia cron partajată din Travel Core — tratați-le ca date secrete. Schimbarea ei reemite și comenzile pentru Travel Core, Sphinx și Novoton.',
    ],
    'eurosite.catalogs_and_schedules' => [
        'en' => 'Catalogs & schedules',
        'ro' => 'Cataloage și programări',
    ],
    'eurosite.catalogs_hint' => [
        'en' => 'One row per job, in the order the full pipeline runs them. Times are server time.',
        'ro' => 'Câte un rând per sarcină, în ordinea în care le rulează sincronizarea completă. Orele sunt ora serverului.',
    ],
    'eurosite.hotels_need_whitelist' => [
        'en' => 'Nothing to fetch: no destinations are whitelisted.',
        'ro' => 'Nu are ce prelua: nicio destinație nu este în listă.',
    ],
    'eurosite.open_whitelist' => [
        'en' => 'Open the whitelist',
        'ro' => 'Deschide lista de destinații',
    ],
    'eurosite.copy_crontab_line' => [
        'en' => 'Copy crontab line',
        'ro' => 'Copiază linia de crontab',
    ],
    'eurosite.cron_no_key_title' => [
        'en' => 'No cron security key is set',
        'ro' => 'Nu este setată cheia de securitate cron',
    ],
    'eurosite.cron_no_key_body' => [
        'en' => 'Scheduled syncs answer 403 until a key exists, so there are no commands to schedule yet. Nothing is exposed — the endpoint refuses every request while the key is blank.',
        'ro' => 'Sincronizările programate răspund cu 403 până când există o cheie, deci nu există încă nicio comandă de programat. Nimic nu este expus — endpointul refuză orice cerere cât timp cheia este goală.',
    ],
    'eurosite.generate_cron_key' => [
        'en' => 'Generate a key',
        'ro' => 'Generează o cheie',
    ],
    'eurosite.set_key_in_settings' => [
        'en' => 'Set it in Travel Core settings',
        'ro' => 'Setează-o în setările Travel Core',
    ],
    'eurosite.cron_paste_hint' => [
        'en' => 'CLI (php): paste it into the server crontab or cPanel → Cron Jobs. URL: add each address to a cron service at the time shown.',
        'ro' => 'CLI (php): lipiți-l în crontab-ul serverului sau în cPanel → Cron Jobs. URL: adăugați fiecare adresă într-un serviciu cron la ora indicată.',
    ],
    'eurosite.cron_plan' => [
        'en' => 'Plan',
        'ro' => 'Plan',
    ],
    'eurosite.cron_plan_full' => [
        'en' => 'One nightly full sync',
        'ro' => 'O sincronizare completă în fiecare noapte',
    ],
    'eurosite.cron_plan_per' => [
        'en' => 'Per-catalog schedule',
        'ro' => 'Programare per catalog',
    ],
    'eurosite.recommended' => [
        'en' => 'recommended',
        'ro' => 'recomandat',
    ],
    'eurosite.cron_format' => [
        'en' => 'Format',
        'ro' => 'Format',
    ],
    'eurosite.cron_format_url' => [
        'en' => 'URL',
        'ro' => 'URL',
    ],
    'eurosite.cron_format_cli' => [
        'en' => 'CLI (php)',
        'ro' => 'CLI (php)',
    ],
    'eurosite.copy_all' => [
        'en' => 'Copy all',
        'ro' => 'Copiază tot',
    ],
    'eurosite.reveal_key' => [
        'en' => 'Reveal access key',
        'ro' => 'Afișează cheia de acces',
    ],
    'eurosite.hide_key' => [
        'en' => 'Hide access key',
        'ro' => 'Ascunde cheia de acces',
    ],
    'eurosite.key_hidden_note' => [
        'en' => 'Access key hidden — copying still copies the real value',
        'ro' => 'Cheia de acces este ascunsă — copierea copiază totuși valoarea reală',
    ],
    'eurosite.key_shown_note' => [
        'en' => 'Access key shown',
        'ro' => 'Cheia de acces este afișată',
    ],
    'eurosite.rotate_key' => [
        'en' => 'Rotate key',
        'ro' => 'Schimbă cheia',
    ],
    'eurosite.rotate_key_confirm' => [
        'en' => 'Generate a new shared cron key? Every scheduled job of every travel addon must be updated with the new URL.',
        'ro' => 'Generați o cheie cron partajată nouă? Fiecare sarcină programată a fiecărui addon de turism trebuie actualizată cu noul URL.',
    ],
    'eurosite.more_actions' => [
        'en' => 'More actions',
        'ro' => 'Mai multe acțiuni',
    ],
    'eurosite.menu_needs_key' => [
        'en' => 'Set a cron security key to copy scheduled commands.',
        'ro' => 'Setați o cheie de securitate cron pentru a copia comenzile programate.',
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
    'eurosite.back_to_results' => [
        'en' => 'Back to Results',
        'ro' => 'Înapoi la rezultate',
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
        'en' => 'No Immediate hotel on the configured dates. Find dates with offers below, then add them to the peak-season dates.',
        'ro' => 'Niciun hotel Imediat la datele configurate. Găsiți mai jos date cu oferte, apoi adăugați-le la datele de sezon.',
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
];
