<?php
/**
 * Language keys for the Sphinx Holidays addon.
 * Extracted from fn_sphinx_holidays_seed_language_keys() for maintainability.
 *
 * NOTE: the seeder merges these with addon.xml <language_variables>, and
 * addon.xml WINS on conflict. Settings labels/tooltips belong in addon.xml;
 * keep this file for runtime-only keys (SEO templates page, placeholders, ...)
 * that addon.xml does not declare. Editing a key here that also exists in
 * addon.xml has no effect.
 */
return [
    'sphinx_holidays.circuits_category_id' => [
        'en' => 'CS-Cart category ID for circuits',
        'ro' => 'ID categorie CS-Cart pentru circuite',
    ],
    'sphinx_holidays.circuits_category_id.tooltip' => [
        'en' => 'Root category under which circuit products are created. Country sub-categories are generated automatically.',
        'ro' => 'Categorie rădăcină pentru produse circuite. Sub-categoriile pe țări se generează automat.',
    ],
    'sphinx_holidays.experiences_category_id' => [
        'en' => 'CS-Cart category ID for experiences',
        'ro' => 'ID categorie CS-Cart pentru experiențe',
    ],
    'sphinx_holidays.experiences_category_id.tooltip' => [
        'en' => 'Root category under which experience products are created. Country sub-categories are generated automatically.',
        'ro' => 'Categorie rădăcină pentru produse experiențe. Sub-categoriile pe țări se generează automat.',
    ],
    'sphinx_holidays.product_languages' => [
        'en' => 'Product languages',
        'ro' => 'Limbi produse',
    ],
    'sphinx_holidays.product_languages.tooltip' => [
        'en' => 'Select which CS-Cart languages to create hotel product descriptions for. Hotels will only appear in the storefront for selected languages.',
        'ro' => 'Selectați pentru care limbi CS-Cart să se creeze descrierile produselor hoteliere. Hotelurile vor apărea în magazin doar pentru limbile selectate.',
    ],
    'sphinx_holidays.product_code_prefix' => [
        'en' => 'Product code prefix',
        'ro' => 'Prefix cod produs',
    ],

    // Product-link audit page (sphinx_holidays.product_links): which hotel
    // products cannot be booked because no ?:sphinx_hotels row points at them.
    'sphinx_holidays.product_links' => [
        'en' => 'Product links',
        'ro' => 'Legături produse',
    ],
    'sphinx_holidays.product_links_description' => [
        'en' => 'A hotel page shows its booking form only while a synced hotel points at the product. Products listed below are not linked, so they render as plain catalog items. Relink fetches each hotel from the Sphinx API and links it.',
        'ro' => 'O pagină de hotel afișează formularul de rezervare doar cât timp un hotel sincronizat indică produsul. Produsele de mai jos nu sunt legate, deci se afișează ca produse obișnuite. Relegarea preia fiecare hotel din API-ul Sphinx și îl leagă.',
    ],
    'sphinx_holidays.unlinked_products' => [
        'en' => 'Unlinked products',
        'ro' => 'Produse nelegate',
    ],
    'sphinx_holidays.hotel_id' => [
        'en' => 'Hotel ID',
        'ro' => 'ID hotel',
    ],
    'sphinx_holidays.all_products_linked' => [
        'en' => 'Every hotel product is linked to a synced hotel.',
        'ro' => 'Toate produsele hoteliere sunt legate de un hotel sincronizat.',
    ],
    // Which rung of the City/Region ladder produced the stored location —
    // shown as a badge on the hotels grid so a weak value is visible.
    'sphinx_holidays.location_source_tree' => [
        'en' => 'tree',
        'ro' => 'arbore',
    ],
    'sphinx_holidays.location_source_address' => [
        'en' => 'address',
        'ro' => 'adresă',
    ],
    'sphinx_holidays.location_source_geocode' => [
        'en' => 'coordinates',
        'ro' => 'coordonate',
    ],
    'sphinx_holidays.link_state_missing' => [
        'en' => 'Hotel not synced',
        'ro' => 'Hotel nesincronizat',
    ],
    'sphinx_holidays.link_state_unlinked' => [
        'en' => 'Hotel synced, link missing',
        'ro' => 'Hotel sincronizat, legătură lipsă',
    ],
    'sphinx_holidays.link_state_conflict' => [
        'en' => 'Hotel linked to product [product_id]',
        'ro' => 'Hotel legat de produsul [product_id]',
    ],
    'sphinx_holidays.product_code_prefix.tooltip' => [
        'en' => 'Prefix for CS-Cart product codes created from Sphinx hotels (e.g. SPX). The hotel ID is appended to form the full code (e.g. SPX12345).',
        'ro' => 'Prefix pentru codurile de produs CS-Cart create din hoteluri Sphinx (ex. SPX). ID-ul hotelului se adaugă pentru codul complet (ex. SPX12345).',
    ],
    'sphinx_holidays.default_product_quantity' => [
        'en' => 'Default product quantity',
        'ro' => 'Cantitate implicită produs',
    ],
    'sphinx_holidays.default_product_quantity.tooltip' => [
        'en' => 'Inventory quantity assigned to hotel products on creation. Set high to prevent \'zero inventory\' errors.',
        'ro' => 'Cantitatea de inventar atribuită produselor hotel la creare. Setați o valoare mare pentru a preveni erorile de \'inventar zero\'.',
    ],
    'sphinx_holidays.skip_no_description' => [
        'en' => 'Skip hotels without description (do not create products for hotels with empty description)',
        'ro' => 'Omite hoteluri fără descriere (nu crea produse pentru hoteluri fără descriere)',
    ],
    'sphinx_holidays.skip_no_description.tooltip' => [
        'en' => 'Do not create CS-Cart products for hotels that have an empty description from the API.',
        'ro' => 'Nu crea produse CS-Cart pentru hoteluri care nu au descriere din API.',
    ],
    'sphinx_holidays.require_immediate_availability' => [
        'en' => 'Hotels with immediate confirmation',
        'ro' => 'Hoteluri cu confirmare imediată',
    ],
    'sphinx_holidays.require_immediate_availability.tooltip' => [
        'en' => 'When enabled, only hotels with at least one confirmation=immediate offer are kept in the hotel list: the hotels cron probes each destination and deletes hotels without such an offer (hotels already linked to CS-Cart products keep the hotel: the product is set to Hidden — reachable only by direct link — and reactivated when immediate availability returns), and the storefront search shows only immediate-confirmation offers. The hotels cron accepts a per-run override: &availability_gate=0 to skip the gate, &availability_gate=1 to force it.',
        'ro' => 'Când este activ, în lista de hoteluri se păstrează doar hotelurile cu cel puțin o ofertă confirmation=immediate: cronul de hoteluri verifică fiecare destinație și șterge hotelurile fără o astfel de ofertă (hotelurile deja legate de produse CS-Cart rămân: produsul devine Ascuns — accesibil doar prin link direct — și este reactivat când revine disponibilitatea imediată), iar căutarea din storefront afișează doar ofertele cu confirmare imediată. Cronul de hoteluri acceptă o suprascriere per rulare: &availability_gate=0 pentru a omite filtrul, &availability_gate=1 pentru a-l forța.',
    ],
    'sphinx_holidays.preorder_cache_ttl' => [
        'en' => 'Checkout verify cache TTL (seconds)',
        'ro' => 'TTL cache verificare la checkout (secunde)',
    ],
    'sphinx_holidays.preorder_cache_ttl.tooltip' => [
        'en' => 'Reuse the price verified at add-to-cart for this many seconds when the customer clicks Place Order — the checkout skips one API call per hotel in the cart. 0 = always re-verify live.',
        'ro' => 'Refolosește prețul verificat la adăugarea în coș pentru acest număr de secunde când clientul apasă Plasează comanda — checkout-ul omite un apel API pentru fiecare hotel din coș. 0 = re-verifică mereu live.',
    ],
    // General Settings section headers & fields
    'sphinx_holidays.api_header' => ['en' => 'Sphinx API Settings', 'ro' => 'Setări API Sphinx'],
    'sphinx_holidays.api_base_url' => ['en' => 'API Base URL', 'ro' => 'URL bază API'],
    'sphinx_holidays.api_key' => ['en' => 'API Key (Bearer Token)', 'ro' => 'Cheie API (Bearer Token)'],
    'sphinx_holidays.enable_api_cache' => ['en' => 'Enable API response caching', 'ro' => 'Activează cache-ul răspunsurilor API'],
    'sphinx_holidays.cache_ttl_search' => ['en' => 'Search cache TTL (seconds)', 'ro' => 'TTL cache căutare (secunde)'],
    'sphinx_holidays.search_header' => ['en' => 'Search Settings', 'ro' => 'Setări Căutare'],
    'sphinx_holidays.default_currency' => ['en' => 'Default search currency', 'ro' => 'Monedă implicită căutare'],
    'sphinx_holidays.ignore_domains' => ['en' => 'Ignore domains (comma-separated supplier IDs to skip)', 'ro' => 'Domenii ignorate (ID-uri furnizori separați prin virgulă)'],
    'sphinx_holidays.search_poll_interval' => ['en' => 'Search poll interval (seconds)', 'ro' => 'Interval verificare căutare (secunde)'],
    'sphinx_holidays.search_max_polls' => ['en' => 'Maximum search polls before timeout', 'ro' => 'Număr maxim de verificări înainte de timeout'],
    'sphinx_holidays.pricing_header' => ['en' => 'Pricing', 'ro' => 'Prețuri'],
    'sphinx_holidays.commission' => ['en' => 'Commission percentage', 'ro' => 'Procentaj comision'],
    'sphinx_holidays.product_header' => ['en' => 'Product Creation & Mapping', 'ro' => 'Creare & Mapare Produse'],
    'sphinx_holidays.hotels_category_id' => ['en' => 'CS-Cart category ID for Sphinx hotels', 'ro' => 'ID categorie CS-Cart pentru hoteluri Sphinx'],
    'sphinx_holidays.hotels_category_id.tooltip' => ['en' => 'Root category under which hotel products are created. Country sub-categories are generated automatically.', 'ro' => 'Categorie rădăcină în care se creează produsele hoteliere. Sub-categoriile pe țări se generează automat.'],
    'sphinx_holidays.packages_category_id' => ['en' => 'CS-Cart category ID for Sphinx packages', 'ro' => 'ID categorie CS-Cart pentru pachete Sphinx'],
    'sphinx_holidays.packages_category_id.tooltip' => ['en' => 'Root category under which package products are created. Country sub-categories are generated automatically.', 'ro' => 'Categorie rădăcină pentru produse pachete. Sub-categoriile pe țări se generează automat.'],
    'sphinx_holidays.resilience_header' => ['en' => 'API Resilience', 'ro' => 'Reziliență API'],
    'sphinx_holidays.api_max_retries' => ['en' => 'Maximum retries on failure', 'ro' => 'Număr maxim de reîncercări'],
    'sphinx_holidays.api_retry_delay_ms' => ['en' => 'Initial retry delay (ms)', 'ro' => 'Întârziere inițială reîncercare (ms)'],
    'sphinx_holidays.api_retry_multiplier' => ['en' => 'Retry delay multiplier', 'ro' => 'Multiplicator întârziere reîncercare'],
    'sphinx_holidays.circuit_breaker_threshold' => ['en' => 'Circuit breaker threshold (failures before open)', 'ro' => 'Prag circuit breaker (eșecuri înainte de deschidere)'],
    'sphinx_holidays.circuit_breaker_timeout' => ['en' => 'Circuit breaker timeout (seconds)', 'ro' => 'Timeout circuit breaker (secunde)'],
    'sphinx_holidays.cron_header' => ['en' => 'Cron Settings', 'ro' => 'Setări Cron'],
    'sphinx_holidays.cron_access_key' => ['en' => 'Cron access key (URL: index.php?dispatch=sphinx_cron.run&access_key=KEY&mode=hotels)', 'ro' => 'Cheie acces cron (URL: index.php?dispatch=sphinx_cron.run&access_key=KEY&mode=hotels)'],
    'sphinx_holidays.cron_access_key.tooltip' => ['en' => 'Access key for cron URL: index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=hotels', 'ro' => 'Cheie acces pentru URL cron: index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=hotels'],
    'sphinx_holidays.cron_commands' => ['en' => 'Cron Commands', 'ro' => 'Comenzi Cron'],
    'sphinx_holidays.debug_header' => ['en' => 'Debug', 'ro' => 'Depanare'],
    'sphinx_holidays.debug_logging' => ['en' => 'Enable debug logging', 'ro' => 'Activează logare depanare'],
    'sphinx_holidays.search_metrics' => ['en' => 'Enable search timing metrics', 'ro' => 'Activează metrici de timp pentru căutare'],
    'sphinx_holidays.addon_settings' => [
        'en' => 'Settings',
        'ro' => 'Setări',
    ],
    'sphinx_holidays.classification' => [
        'en' => 'Classification',
        'ro' => 'Clasificare',
    ],
    'sphinx_holidays.link_status' => [
        'en' => 'Link Status',
        'ro' => 'Status Legătură',
    ],
    'sphinx_holidays.linked' => [
        'en' => 'Linked',
        'ro' => 'Legat',
    ],
    'sphinx_holidays.orphan' => [
        'en' => 'Orphan',
        'ro' => 'Orfan',
    ],
    'sphinx_holidays.product' => [
        'en' => 'Product',
        'ro' => 'Produs',
    ],
    'sphinx_holidays.unclassified' => [
        'en' => 'Unclassified',
        'ro' => 'Neclasificat',
    ],
    'sphinx_holidays.bulk_activate' => [
        'en' => 'Activate Selected',
        'ro' => 'Activează Selectate',
    ],
    'sphinx_holidays.bulk_deactivate' => [
        'en' => 'Deactivate Selected',
        'ro' => 'Dezactivează Selectate',
    ],
    'sphinx_holidays.bulk_delete' => [
        'en' => 'Delete Selected',
        'ro' => 'Șterge Selectate',
    ],
    'sphinx_holidays.bulk_sync_images' => [
        'en' => 'Sync Images',
        'ro' => 'Sincronizează Imagini',
    ],
    'sphinx_holidays.bulk_delete_confirm' => [
        'en' => 'Are you sure you want to delete the selected hotels?',
        'ro' => 'Sigur doriți să ștergeți hotelurile selectate?',
    ],
    'sphinx_holidays.selected_count_label' => [
        'en' => 'selected',
        'ro' => 'selectate',
    ],
    'sphinx_holidays.deselect_all' => [
        'en' => 'Deselect all',
        'ro' => 'Deselectează tot',
    ],
    'sphinx_holidays.bulk_select_all' => [
        'en' => 'All',
        'ro' => 'Toate',
    ],
    'sphinx_holidays.bulk_select_none' => [
        'en' => 'None',
        'ro' => 'Niciunul',
    ],
    'sphinx_holidays.bulk_actions' => [
        'en' => 'Actions',
        'ro' => 'Acțiuni',
    ],
    'sphinx_holidays.bulk_change_to_active' => [
        'en' => 'Change to Active',
        'ro' => 'Schimbă în Activ',
    ],
    'sphinx_holidays.bulk_change_to_disabled' => [
        'en' => 'Change to Disabled',
        'ro' => 'Schimbă în Dezactivat',
    ],
    // ── Search results availability badge (novoton-parity vocabulary) ──
    'sphinx_holidays.available' => [
        'en' => 'Available',
        'ro' => 'Disponibil',
    ],
    'sphinx_holidays.room' => [
        'en' => 'room',
        'ro' => 'cameră',
    ],
    'sphinx_holidays.rooms' => [
        'en' => 'rooms',
        'ro' => 'camere',
    ],
    'sphinx_holidays.offer' => [
        'en' => 'offer',
        'ro' => 'ofertă',
    ],
    'sphinx_holidays.offers' => [
        'en' => 'offers',
        'ro' => 'oferte',
    ],
    'sphinx_holidays.for' => [
        'en' => 'for',
        'ro' => 'pentru',
    ],
    'sphinx_holidays.adult' => [
        'en' => 'adult',
        'ro' => 'adult',
    ],
    'sphinx_holidays.adults' => [
        'en' => 'adults',
        'ro' => 'adulți',
    ],
    'sphinx_holidays.child' => [
        'en' => 'child',
        'ro' => 'copil',
    ],
    'sphinx_holidays.children' => [
        'en' => 'children',
        'ro' => 'copii',
    ],
    'sphinx_holidays.location_show_map' => [
        'en' => 'Location - show map',
        'ro' => 'Locație - arată pe hartă',
    ],
    // ── Circuits & package routes (static-data admin grids) ──
    'sphinx_holidays.circuits' => [
        'en' => 'Circuits',
        'ro' => 'Circuite',
    ],
    'sphinx_holidays.package_routes' => [
        'en' => 'Package routes',
        'ro' => 'Rute pachete',
    ],
    'sphinx_holidays.sync_now' => [
        'en' => 'Sync now',
        'ro' => 'Sincronizează acum',
    ],
    'sphinx_holidays.sync_circuits' => [
        'en' => 'Sync Circuits',
        'ro' => 'Sincronizează Circuite',
    ],
    'sphinx_holidays.sync_package_routes' => [
        'en' => 'Sync Package Routes',
        'ro' => 'Sincronizează Rute Pachete',
    ],
    'sphinx_holidays.sync_circuits_confirm' => [
        'en' => 'Sync the circuit catalog from the Sphinx API now?',
        'ro' => 'Sincronizați acum catalogul de circuite din API-ul Sphinx?',
    ],
    'sphinx_holidays.sync_package_routes_confirm' => [
        'en' => 'Sync package routes from the Sphinx API now?',
        'ro' => 'Sincronizați acum rutele de pachete din API-ul Sphinx?',
    ],
    'sphinx_holidays.no_circuits' => [
        'en' => 'No circuits found.',
        'ro' => 'Niciun circuit găsit.',
    ],
    'sphinx_holidays.no_package_routes' => [
        'en' => 'No package routes found.',
        'ro' => 'Nicio rută de pachet găsită.',
    ],
    'sphinx_holidays.all' => [
        'en' => 'All',
        'ro' => 'Toate',
    ],
    'sphinx_holidays.transport_type' => [
        'en' => 'Transport',
        'ro' => 'Transport',
    ],
    'sphinx_holidays.transport_flight' => [
        'en' => 'Flight',
        'ro' => 'Avion',
    ],
    'sphinx_holidays.transport_bus' => [
        'en' => 'Bus',
        'ro' => 'Autocar',
    ],
    'sphinx_holidays.duration' => [
        'en' => 'Duration',
        'ro' => 'Durată',
    ],
    'sphinx_holidays.min_price' => [
        'en' => 'From price',
        'ro' => 'Preț de la',
    ],
    'sphinx_holidays.nights_short' => [
        'en' => 'nights',
        'ro' => 'nopți',
    ],
    'sphinx_holidays.days_short' => [
        'en' => 'days',
        'ro' => 'zile',
    ],
    'sphinx_holidays.departure' => [
        'en' => 'Departure',
        'ro' => 'Plecare',
    ],
    'sphinx_holidays.arrival' => [
        'en' => 'Arrival',
        'ro' => 'Sosire',
    ],
    'sphinx_holidays.available_dates' => [
        'en' => 'Available dates',
        'ro' => 'Date disponibile',
    ],
    'sphinx_holidays.images_synced' => [
        'en' => 'Images synced',
        'ro' => 'Imagini sincronizate',
    ],
    'sphinx_holidays.hotels_updated' => [
        'en' => 'Hotels updated',
        'ro' => 'Hoteluri actualizate',
    ],
    'sphinx_holidays.hotels_deleted' => [
        'en' => 'Hotels deleted',
        'ro' => 'Hoteluri șterse',
    ],
    'sphinx_holidays.no_hotels_selected' => [
        'en' => 'No hotels selected',
        'ro' => 'Niciun hotel selectat',
    ],
    'sphinx_holidays.all_classifications' => [
        'en' => 'All classifications',
        'ro' => 'Toate clasificările',
    ],
    'sphinx_holidays.all_property_types' => [
        'en' => 'All types',
        'ro' => 'Toate tipurile',
    ],
    'sphinx_holidays.all_link_statuses' => [
        'en' => 'All',
        'ro' => 'Toate',
    ],
    'sphinx_holidays.with_selected' => [
        'en' => 'With selected',
        'ro' => 'Cu cele selectate',
    ],
    'sphinx_holidays.skipped_hotels' => [
        'en' => 'Skipped hotels',
        'ro' => 'Hoteluri omise',
    ],
    'sphinx_holidays.retry_skipped' => [
        'en' => 'Retry skipped hotels',
        'ro' => 'Reîncearcă hotelurile omise',
    ],
    'sphinx_holidays.retry_skipped_confirm' => [
        'en' => 'This will reset all skipped hotels so they can be processed again. Continue?',
        'ro' => 'Aceasta va reseta toate hotelurile omise pentru a fi procesate din nou. Continuați?',
    ],
    'sphinx_holidays.skipped_reset' => [
        'en' => '[count] skipped hotel(s) have been reset and are now eligible for product creation.',
        'ro' => '[count] hotel(uri) omise au fost resetate și sunt acum eligibile pentru crearea de produse.',
    ],
    'sphinx_holidays.no_skipped_hotels' => [
        'en' => 'No skipped hotels found.',
        'ro' => 'Nu s-au găsit hoteluri omise.',
    ],
    // SEO Templates section
    'sphinx_holidays.seo_templates' => [
        'en' => 'SEO Templates',
        'ro' => 'Șabloane SEO',
    ],
    'sphinx_holidays.seo_placeholders_info' => [
        'en' => 'Use the placeholders listed in the sidebar to build your SEO templates.',
        'ro' => 'Folosiți placeholder-ele din bara laterală pentru a construi șabloanele SEO.',
    ],
    // Sidebar placeholder reference (used in settings/seo_templates.tpl)
    'sphinx_holidays.available_placeholders' => [
        'en' => 'Available Placeholders',
        'ro' => 'Placeholder-e Disponibile',
    ],
    'sphinx_holidays.seo_placeholders_title' => [
        'en' => 'Sphinx placeholders to use',
        'ro' => 'Placeholder-e Sphinx disponibile',
    ],
    'sphinx_holidays.placeholders_hint' => [
        'en' => 'Use these tags in your SEO templates:',
        'ro' => 'Folosiți aceste tag-uri în șabloanele SEO:',
    ],
    'sphinx_holidays.placeholders_example' => [
        'en' => 'Example: Book {{name}} in {{city}}, {{country}}. {{classification}}-star {{property_type}} with {{facilities}}.',
        'ro' => 'Exemplu: Rezervă {{name}} în {{city}}, {{country}}. {{property_type}} {{classification}} stele cu {{facilities}}.',
    ],
    'sphinx_holidays.ph_name' => ['en' => 'Hotel name', 'ro' => 'Nume hotel'],
    'sphinx_holidays.ph_classification' => ['en' => 'Star rating', 'ro' => 'Clasificare stele'],
    'sphinx_holidays.ph_city' => ['en' => 'City / resort', 'ro' => 'Oraș / stațiune'],
    'sphinx_holidays.ph_country' => ['en' => 'Country', 'ro' => 'Țară'],
    'sphinx_holidays.ph_region' => ['en' => 'Region', 'ro' => 'Regiune'],
    'sphinx_holidays.ph_property_type' => ['en' => 'Hotel / villa / apt', 'ro' => 'Hotel / vilă / apt'],
    'sphinx_holidays.ph_description' => ['en' => 'API description', 'ro' => 'Descriere API'],
    'sphinx_holidays.ph_rating' => ['en' => 'Guest rating', 'ro' => 'Rating oaspeți'],
    'sphinx_holidays.ph_facilities' => ['en' => 'Top 3 facilities', 'ro' => 'Top 3 facilități'],
    'sphinx_holidays.ph_boards' => ['en' => 'Meal plans', 'ro' => 'Tipuri masă'],
    'sphinx_holidays.ph_image_url' => ['en' => 'Main image URL', 'ro' => 'URL imagine principală'],
    'sphinx_holidays.ph_address' => ['en' => 'Street address', 'ro' => 'Adresă stradă'],
    'sphinx_holidays.ph_phone' => ['en' => 'Phone number', 'ro' => 'Număr telefon'],
    'sphinx_holidays.ph_email' => ['en' => 'Email address', 'ro' => 'Adresă email'],
    'sphinx_holidays.ph_website' => ['en' => 'Website URL', 'ro' => 'URL website'],
    'sphinx_holidays.seo_product_name' => [
        'en' => 'Product name',
        'ro' => 'Nume produs',
    ],
    'sphinx_holidays.seo_product_name.tooltip' => [
        'en' => 'Template for the product name.',
        'ro' => 'Șablon pentru numele produsului.',
    ],
    'sphinx_holidays.seo_page_title' => [
        'en' => 'Page title',
        'ro' => 'Titlu pagină',
    ],
    'sphinx_holidays.seo_page_title.tooltip' => [
        'en' => 'Template for the HTML page title (SEO). Google typically truncates around 60 characters.',
        'ro' => 'Șablon pentru titlul paginii HTML (SEO). Google trunchiază de obicei la ~60 caractere.',
    ],
    'sphinx_holidays.seo_meta_description' => [
        'en' => 'Meta description',
        'ro' => 'Meta descriere',
    ],
    'sphinx_holidays.seo_meta_description.tooltip' => [
        'en' => 'Template for the meta description tag. Google truncates around 160 characters.',
        'ro' => 'Șablon pentru tag-ul meta description. Google trunchiază la ~160 caractere.',
    ],
    'sphinx_holidays.seo_meta_keywords' => [
        'en' => 'Meta keywords',
        'ro' => 'Meta cuvinte cheie',
    ],
    'sphinx_holidays.seo_meta_keywords.tooltip' => [
        'en' => 'Template for the meta keywords tag.',
        'ro' => 'Șablon pentru tag-ul meta keywords.',
    ],
    'sphinx_holidays.seo_name_slug' => [
        'en' => 'SEO URL slug',
        'ro' => 'URL SEO (slug)',
    ],
    'sphinx_holidays.seo_name_slug.tooltip' => [
        'en' => 'Template for the SEO-friendly URL slug. Result is automatically sanitized.',
        'ro' => 'Șablon pentru slug-ul URL SEO. Rezultatul este sanitizat automat.',
    ],
    'sphinx_holidays.seo_full_description' => [
        'en' => 'Full description (optional)',
        'ro' => 'Descriere completă (opțional)',
    ],
    'sphinx_holidays.seo_full_description.tooltip' => [
        'en' => 'Optional template to wrap or replace the API description. Leave empty to use the raw API description as-is.',
        'ro' => 'Șablon opțional pentru a înfășura sau înlocui descrierea API. Lăsați gol pentru a folosi descrierea API în forma originală.',
    ],
    'sphinx_holidays.per_night' => [
        'en' => 'night',
        'ro' => 'noapte',
    ],

    // ── Storefront booking & catalog runtime keys (previously missing:
    //    rendered as raw `_sphinx_holidays.*` labels) ──
    'sphinx_holidays.add_to_cart_btn' => [
        'en' => 'Add to Cart',
        'ro' => 'Adaugă în coș',
    ],
    'sphinx_holidays.added_to_cart' => [
        'en' => 'Hotel booking added to cart.',
        'ro' => 'Rezervarea hotelului a fost adăugată în coș.',
    ],
    'sphinx_holidays.additional_services' => [
        'en' => 'Additional Services',
        'ro' => 'Servicii adiționale',
    ],
    'sphinx_holidays.addon_name' => [
        'en' => 'Sphinx Holidays',
        'ro' => 'Sphinx Holidays',
    ],
    'sphinx_holidays.booking_data_missing' => [
        'en' => 'Booking data not available. Please search again.',
        'ro' => 'Datele rezervării nu sunt disponibile. Vă rugăm căutați din nou.',
    ],
    'sphinx_holidays.booking_error' => [
        'en' => 'An error occurred. Please try again.',
        'ro' => 'A apărut o eroare. Vă rugăm încercați din nou.',
    ],
    'sphinx_holidays.bus_transport' => [
        'en' => 'Bus transport',
        'ro' => 'Transport cu autocarul',
    ],
    'sphinx_holidays.check_availability' => [
        'en' => 'Check Availability',
        'ro' => 'Verifică disponibilitatea',
    ],
    'sphinx_holidays.circuit_added_to_cart' => [
        'en' => 'Circuit booking added to cart.',
        'ro' => 'Rezervarea circuitului a fost adăugată în coș.',
    ],
    'sphinx_holidays.circuits_found' => [
        'en' => 'Circuits found',
        'ro' => 'Circuite găsite',
    ],
    'sphinx_holidays.cron_key_not_set' => [
        // Names Travel Core because the key moved there: one shared cron
        // secret for all three providers. Without the location this reads as
        // "something is wrong" with nowhere to go, and the field it used to
        // mean is no longer on this addon's settings page.
        'en' => 'Cron key not set — set it in Settings → Travel Core → Cron security key. '
            . 'It is shared by every travel addon.',
        'ro' => 'Cheia cron nu este setată — setați-o în Setări → Travel Core → Cheie de securitate cron. '
            . 'Este partajată de toate addon-urile de turism.',
    ],
    'sphinx_holidays.date' => [
        'en' => 'Date',
        'ro' => 'Data',
    ],
    'sphinx_holidays.departure_from' => [
        'en' => 'Departure from',
        'ro' => 'Plecare din',
    ],
    'sphinx_holidays.duplicate_booking' => [
        'en' => 'A booking for this offer is already pending.',
        'ro' => 'O rezervare pentru această ofertă este deja în curs.',
    ],
    'sphinx_holidays.experience_added_to_cart' => [
        'en' => 'Experience booking added to cart.',
        'ro' => 'Rezervarea experienței a fost adăugată în coș.',
    ],
    'sphinx_holidays.experiences_found' => [
        'en' => 'Experiences found',
        'ro' => 'Experiențe găsite',
    ],
    'sphinx_holidays.flight_info' => [
        'en' => 'Flight Information',
        'ro' => 'Informații zbor',
    ],
    'sphinx_holidays.from' => [
        'en' => 'from',
        'ro' => 'de la',
    ],
    'sphinx_holidays.get_quote' => [
        'en' => 'Get Quote',
        'ro' => 'Cere ofertă',
    ],
    'sphinx_holidays.hours' => [
        'en' => 'hours',
        'ro' => 'ore',
    ],
    'sphinx_holidays.inbound' => [
        'en' => 'Return',
        'ro' => 'Retur',
    ],
    'sphinx_holidays.included' => [
        'en' => 'Included',
        'ro' => 'Inclus',
    ],
    'sphinx_holidays.instant_confirmation' => [
        'en' => 'Instant confirmation',
        'ro' => 'Confirmare instantă',
    ],
    'sphinx_holidays.invalid_circuit' => [
        'en' => 'Invalid circuit selection.',
        'ro' => 'Selecție circuit invalidă.',
    ],
    'sphinx_holidays.invalid_experience' => [
        'en' => 'Invalid experience selection.',
        'ro' => 'Selecție experiență invalidă.',
    ],
    'sphinx_holidays.invalid_offer' => [
        'en' => 'Invalid offer selected.',
        'ro' => 'Ofertă selectată invalidă.',
    ],
    'sphinx_holidays.loading_deals' => [
        'en' => 'Loading deals...',
        'ro' => 'Se încarcă ofertele...',
    ],
    'sphinx_holidays.mandatory' => [
        'en' => 'Mandatory',
        'ro' => 'Obligatoriu',
    ],
    'sphinx_holidays.meal' => [
        'en' => 'Meal',
        'ro' => 'Masă',
    ],
    'sphinx_holidays.next_dates' => [
        'en' => 'Next dates',
        'ro' => 'Următoarele date',
    ],
    'sphinx_holidays.no_circuit_quotes' => [
        'en' => 'No quotes available for this circuit. Please try different dates.',
        'ro' => 'Nu există oferte pentru acest circuit. Încercați alte date.',
    ],
    'sphinx_holidays.no_circuits_found' => [
        'en' => 'No circuits found. Please try different filters.',
        'ro' => 'Nu s-au găsit circuite. Încercați alte filtre.',
    ],
    'sphinx_holidays.no_deals_available' => [
        'en' => 'No deals available at this time.',
        'ro' => 'Nu există oferte disponibile momentan.',
    ],
    'sphinx_holidays.no_experience_quotes' => [
        'en' => 'No quotes available for this experience. Please try a different date.',
        'ro' => 'Nu există oferte pentru această experiență. Încercați o altă dată.',
    ],
    'sphinx_holidays.no_experiences_found' => [
        'en' => 'No experiences found. Please try different filters.',
        'ro' => 'Nu s-au găsit experiențe. Încercați alte filtre.',
    ],
    'sphinx_holidays.no_packages_found' => [
        'en' => 'No packages found for your search criteria. Please try different dates or destination.',
        'ro' => 'Nu s-au găsit pachete pentru criteriile dvs. Încercați alte date sau altă destinație.',
    ],
    'sphinx_holidays.not_included' => [
        'en' => 'Not Included',
        'ro' => 'Neinclus',
    ],
    'sphinx_holidays.offer_no_longer_available' => [
        'en' => 'This offer is no longer available. Please search again.',
        'ro' => 'Această ofertă nu mai este disponibilă. Vă rugăm căutați din nou.',
    ],
    // Verify-service outage (5xx/transport), as opposed to a genuine offer
    // expiry: "search again" advice would be false — fresh offers cannot
    // verify either while the service is down.
    'sphinx_holidays.booking_system_unavailable' => [
        'en' => 'The booking system is temporarily unavailable. Please try again in a few minutes.',
        'ro' => 'Sistemul de rezervări este momentan indisponibil. Vă rugăm încercați din nou în câteva minute.',
    ],
    'sphinx_holidays.terms_outage' => [
        'en' => 'The conditions cannot be displayed right now. Please try again in a few minutes.',
        'ro' => 'Condițiile nu pot fi afișate momentan. Vă rugăm încercați din nou în câteva minute.',
    ],
    'sphinx_holidays.terms_partial' => [
        'en' => 'The detailed payment and cancellation schedule cannot be loaded right now. The cancellation status below comes from the search result and is accurate.',
        'ro' => 'Programul detaliat de plată și anulare nu poate fi încărcat momentan. Statusul de anulare de mai jos provine din rezultatul căutării și este corect.',
    ],
    'sphinx_holidays.offer_unavailable' => [
        'en' => 'This offer is no longer available.',
        'ro' => 'Această ofertă nu mai este disponibilă.',
    ],
    'sphinx_holidays.outbound' => [
        'en' => 'Outbound',
        'ro' => 'Dus',
    ],
    'sphinx_holidays.package_added_to_cart' => [
        'en' => 'Package booking added to cart.',
        'ro' => 'Rezervarea pachetului a fost adăugată în coș.',
    ],
    'sphinx_holidays.packages_found' => [
        'en' => 'Packages found',
        'ro' => 'Pachete găsite',
    ],
    'sphinx_holidays.participant_details' => [
        'en' => 'Participant Details',
        'ro' => 'Detalii participanți',
    ],
    'sphinx_holidays.participants' => [
        'en' => 'Participants',
        'ro' => 'Participanți',
    ],
    'sphinx_holidays.price_unavailable' => [
        'en' => 'Price not available.',
        'ro' => 'Prețul nu este disponibil.',
    ],
    'sphinx_holidays.product_not_found' => [
        'en' => 'Experience product not found.',
        'ro' => 'Produsul nu a fost găsit.',
    ],
    'sphinx_holidays.rate_limit_exceeded' => [
        'en' => 'Too many booking requests. Please try again later.',
        'ro' => 'Prea multe cereri de rezervare. Încercați mai târziu.',
    ],
    'sphinx_holidays.return' => [
        'en' => 'Return',
        'ro' => 'Retur',
    ],
    'sphinx_holidays.searching_please_wait' => [
        'en' => 'Searching for live offers…',
        'ro' => 'Căutăm oferte în timp real…',
    ],
    'sphinx_holidays.total_price' => [
        'en' => 'Total price',
        'ro' => 'Preț total',
    ],
    'sphinx_holidays.transfers' => [
        'en' => 'Transfers',
        'ro' => 'Transferuri',
    ],
    'sphinx_holidays.transport' => [
        'en' => 'Transport',
        'ro' => 'Transport',
    ],
    'sphinx_holidays.whitelist_save_failed' => [
        'en' => 'Whitelist save failed',
        'ro' => 'Salvarea whitelist-ului a eșuat',
    ],
    'sphinx_holidays.includes_taxes' => [
        'en' => 'Includes taxes and commissions',
        'ro' => 'Include taxe și comisioane',
    ],
    'sphinx_holidays.stars_rating' => [
        'en' => '[rating]-star rating',
        'ro' => 'Clasificare [rating] stele',
    ],
    'sphinx_holidays.pkg_destination' => [
        'en' => 'Destination',
        'ro' => 'Destinație',
    ],
    'sphinx_holidays.pkg_departure_city' => [
        'en' => 'Departure city',
        'ro' => 'Oraș de plecare',
    ],
    'sphinx_holidays.pkg_transport' => [
        'en' => 'Transport',
        'ro' => 'Transport',
    ],
    'sphinx_holidays.pkg_departure_date' => [
        'en' => 'Departure date',
        'ro' => 'Data plecării',
    ],
    'sphinx_holidays.pkg_nights' => [
        'en' => 'Nights',
        'ro' => 'Nopți',
    ],
    'sphinx_holidays.pkg_adults' => [
        'en' => 'Adults',
        'ro' => 'Adulți',
    ],
    'sphinx_holidays.pkg_children' => [
        'en' => 'Children',
        'ro' => 'Copii',
    ],
    'sphinx_holidays.pkg_children_ages' => [
        'en' => 'Children ages',
        'ro' => 'Vârstele copiilor',
    ],
    'sphinx_holidays.pkg_children_ages_hint' => [
        'en' => 'e.g. 5,9',
        'ro' => 'ex. 5,9',
    ],
    'sphinx_holidays.pkg_any' => [
        'en' => 'Any',
        'ro' => 'Oricare',
    ],
    'sphinx_holidays.pkg_loading' => [
        'en' => 'Loading...',
        'ro' => 'Se încarcă...',
    ],
    'sphinx_holidays.pkg_search' => [
        'en' => 'Search packages',
        'ro' => 'Caută pachete',
    ],

    // ── Search-card terms modal (payment/cancellation timelines) ──────────
    // These MUST live here (not only in the .po files): the self-heal seeder
    // reads lang_keys.php + addon.xml and re-seeds installed stores when
    // their hash changes — .po files are only imported on install. Keys left
    // .po-only render as raw "_sphinx_holidays.*" on existing stores.
    'sphinx_holidays.cancellation_and_payment_terms' => [
        'en' => 'Payment and cancellation terms',
        'ro' => 'Condiții de Plată și Anulare',
    ],
    'sphinx_holidays.free_cancellation_until' => [
        // The date shown is the FIRST PENALTY date (earliest `since`), so the
        // copy must read "before" — "until" would imply the date is still free.
        'en' => 'Free cancellation before',
        'ro' => 'Anulare gratuită înainte de',
    ],
    'sphinx_holidays.terms_due_by' => [
        'en' => 'Amount due',
        'ro' => 'De achitat',
    ],
    'sphinx_holidays.terms_penalty' => [
        'en' => 'Penalty',
        'ro' => 'Penalizare',
    ],
    'sphinx_holidays.terms_non_refundable' => [
        'en' => 'Non-refundable',
        'ro' => 'Nerambursabil',
    ],
    'sphinx_holidays.terms_until' => [
        'en' => 'until',
        'ro' => 'până la',
    ],
    'sphinx_holidays.terms_from' => [
        'en' => 'from',
        'ro' => 'de la',
    ],

    // Availability badge counts — CS-Cart plural forms ([n] singular|[n] plural)
    // so Romanian renders "1 ofertă" / "2 oferte" correctly. Rendered with
    // {__("sphinx_holidays.n_offers", [$count])}.
    'sphinx_holidays.n_rooms' => [
        'en' => '[n] Room|[n] Rooms',
        'ro' => '[n] Cameră|[n] Camere|[n] de Camere',
    ],
    'sphinx_holidays.n_offers' => [
        'en' => '[n] Offer|[n] Offers',
        'ro' => '[n] Ofertă|[n] Oferte|[n] de Oferte',
    ],
    'sphinx_holidays.n_adults' => [
        'en' => '[n] Adult|[n] Adults',
        'ro' => '[n] Adult|[n] Adulți|[n] de Adulți',
    ],
    'sphinx_holidays.n_children' => [
        'en' => '[n] Child|[n] Children',
        'ro' => '[n] Copil|[n] Copii|[n] de Copii',
    ],

    'sphinx_holidays.edit_booking' => [
        'en' => 'Edit booking',
        'ro' => 'Editează rezervarea',
    ],
    'sphinx_holidays.booking_updated' => [
        'en' => 'Booking details updated.',
        'ro' => 'Detaliile rezervării au fost actualizate.',
    ],
    'sphinx_holidays.complete_booking' => [
        'en' => 'Complete Your Booking',
        'ro' => 'Finalizează rezervarea',
    ],
    // Multi-room offers: one line per room on the search result card
    'sphinx_holidays.sx_room' => [
        'en' => 'Room [num]',
        'ro' => 'Camera [num]',
    ],
    'sphinx_holidays.sx_adults_one' => [
        'en' => '1 adult',
        'ro' => '1 adult',
    ],
    'sphinx_holidays.sx_adults_many' => [
        'en' => '[count] adults',
        'ro' => '[count] adulți',
    ],
    'sphinx_holidays.sx_children_one' => [
        'en' => '1 child',
        'ro' => '1 copil',
    ],
    'sphinx_holidays.sx_children_many' => [
        'en' => '[count] children',
        'ro' => '[count] copii',
    ],
    'sphinx_holidays.sx_total_for_rooms' => [
        'en' => 'Total for [count] rooms',
        'ro' => 'Total pentru [count] camere',
    ],
    // Destination whitelist (Travel Core's destination picker) and the dashboard Destinations card.
    'sphinx_holidays.dest_mode_off' => [
        'en' => 'Not sold',
        'ro' => 'Nu se vinde',
    ],
    'sphinx_holidays.dest_mode_all' => [
        'en' => 'All destinations',
        'ro' => 'Toate destinațiile',
    ],
    'sphinx_holidays.dest_mode_specific' => [
        'en' => 'Only selected',
        'ro' => 'Doar cele bifate',
    ],
    'sphinx_holidays.dest_hint_off' => [
        'en' => 'Nothing is synced or sold in this country.',
        'ro' => 'Nimic nu se sincronizează și nu se vinde în această țară.',
    ],
    'sphinx_holidays.dest_hint_all' => [
        'en' => 'Every region and city is sold, including ones Sphinx adds later.',
        'ro' => 'Se vând toate regiunile și orașele, inclusiv cele adăugate ulterior de Sphinx.',
    ],
    'sphinx_holidays.dest_hint_specific' => [
        'en' => 'Tick a whole region (cities Sphinx adds to it later come with it) or single cities. New destinations wait for you.',
        'ro' => 'Bifați o regiune întreagă (orașele adăugate ulterior de Sphinx vin odată cu ea) sau orașe separate. Destinațiile noi vă așteaptă.',
    ],
    'sphinx_holidays.dest_badge_off' => [
        'en' => 'NOT SOLD',
        'ro' => 'NU SE VINDE',
    ],
    'sphinx_holidays.dest_badge_all' => [
        'en' => 'ALL',
        'ro' => 'TOATE',
    ],
    'sphinx_holidays.dest_badge_some' => [
        'en' => '[groups_sold] of [groups] regions',
        'ro' => '[groups_sold] din [groups] regiuni',
    ],
    'sphinx_holidays.dest_tile_countries' => [
        'en' => 'Countries sold',
        'ro' => 'Țări vândute',
    ],
    'sphinx_holidays.dest_tile_cities' => [
        'en' => 'Cities sold',
        'ro' => 'Orașe vândute',
    ],
    'sphinx_holidays.dest_tile_cities_note' => [
        'en' => 'in [n] regions',
        'ro' => 'în [n] regiuni',
    ],
    'sphinx_holidays.dest_tile_hotels' => [
        'en' => 'Hotels in scope',
        'ro' => 'Hoteluri incluse',
    ],
    'sphinx_holidays.dest_tile_hotels_note' => [
        'en' => '[n] live products',
        'ro' => '[n] produse active',
    ],
    'sphinx_holidays.dest_tile_circuits' => [
        'en' => 'Circuits in scope',
        'ro' => 'Circuite incluse',
    ],
    'sphinx_holidays.dest_tile_circuits_note' => [
        'en' => '[n] package routes',
        'ro' => '[n] rute de pachete',
    ],
    'sphinx_holidays.dest_tile_new' => [
        'en' => 'New destinations to review',
        'ro' => 'Destinații noi de verificat',
    ],
    'sphinx_holidays.dest_regions_sold' => [
        'en' => 'Regions sold',
        'ro' => 'Regiuni vândute',
    ],
    'sphinx_holidays.dest_not_configured' => [
        'en' => '<strong>No whitelist saved yet.</strong> Nothing is synced or sold from Sphinx until you choose at least one country and save.',
        'ro' => '<strong>Nicio listă salvată încă.</strong> Nu se sincronizează și nu se vinde nimic de la Sphinx până nu alegeți cel puțin o țară și salvați.',
    ],
    'sphinx_holidays.dest_new_alert' => [
        'en' => '[n] new destinations appeared in the destinations sync. They are not sold until you tick them.',
        'ro' => '[n] destinații noi au apărut la sincronizarea destinațiilor. Nu se vând până nu le bifați.',
    ],
    'sphinx_holidays.dest_show_new' => [
        'en' => 'Show new destinations',
        'ro' => 'Arată destinațiile noi',
    ],
    'sphinx_holidays.dest_intro' => [
        'en' => 'Choose what we sell from Sphinx. Only hotels, circuits and package routes in these destinations are synced and made into products; live products stay until you disable them. "All destinations" and a ticked region include what Sphinx adds later; single cities are only themselves.',
        'ro' => 'Alegeți ce vindem de la Sphinx. Doar hotelurile, circuitele și rutele de pachete din aceste destinații se sincronizează și devin produse; produsele active rămân până le dezactivați. „Toate destinațiile" și o regiune bifată includ și ce adaugă ulterior Sphinx; orașele bifate separat sunt doar ele însele.',
    ],
    'sphinx_holidays.dest_all_continents' => [
        'en' => 'All continents',
        'ro' => 'Toate continentele',
    ],
    'sphinx_holidays.dest_feature_note' => [
        'en' => 'Saving also adds the sold regions to Feature Mappings, for the storefront\'s region filter.',
        'ro' => 'Salvarea adaugă și regiunile vândute în Feature Mappings, pentru filtrul de regiuni din magazin.',
    ],
    'sphinx_holidays.dest_country_meta' => [
        'en' => '[regions] regions · [cities] cities · [hotels] hotels',
        'ro' => '[regions] regiuni · [cities] orașe · [hotels] hoteluri',
    ],
    'sphinx_holidays.dest_country_meta_none' => [
        'en' => '[regions] regions · [cities] cities · hotels not synced yet',
        'ro' => '[regions] regiuni · [cities] orașe · hoteluri nesincronizate încă',
    ],
    'sphinx_holidays.dest_region_meta' => [
        'en' => '[cities] cities · [hotels] hotels',
        'ro' => '[cities] orașe · [hotels] hoteluri',
    ],
    'sphinx_holidays.dest_city_meta' => [
        'en' => '[hotels] hotels',
        'ro' => '[hotels] hoteluri',
    ],
    'sphinx_holidays.dest_city_not_synced' => [
        'en' => 'Hotels not synced yet',
        'ro' => 'Hoteluri nesincronizate încă',
    ],
    'sphinx_holidays.dest_n_circuits' => [
        'en' => '[n] circuit|[n] circuits',
        'ro' => '[n] circuit|[n] circuite|[n] de circuite',
    ],
    'sphinx_holidays.dest_circuit_label' => [
        'en' => 'Circuit: [name]',
        'ro' => 'Circuit: [name]',
    ],
    'sphinx_holidays.dest_no_destinations' => [
        'en' => 'No destinations are synced for [country] yet.',
        'ro' => 'Nu există încă destinații sincronizate pentru [country].',
    ],
    'sphinx_holidays.dest_search' => [
        'en' => 'Search countries, regions or cities…',
        'ro' => 'Caută țări, regiuni sau orașe…',
    ],
    'sphinx_holidays.dest_filter' => [
        'en' => 'Filter cities…',
        'ro' => 'Filtrează orașele…',
    ],
    'sphinx_holidays.dest_item_type' => [
        'en' => 'City',
        'ro' => 'Oraș',
    ],
    'sphinx_holidays.dest_group_type' => [
        'en' => 'Region',
        'ro' => 'Regiune',
    ],
    'sphinx_holidays.dest_no_match' => [
        'en' => 'No country, region or city matches.',
        'ro' => 'Nicio țară, regiune sau oraș nu se potrivește.',
    ],
    'sphinx_holidays.dest_fold' => [
        'en' => '[n] more country has no destinations synced and is not sold.|[n] more countries have no destinations synced and are not sold.',
        'ro' => 'Încă [n] țară nu are destinații sincronizate și nu se vinde.|Încă [n] țări nu au destinații sincronizate și nu se vând.|Încă [n] de țări nu au destinații sincronizate și nu se vând.',
    ],
    'sphinx_holidays.dest_catalog' => [
        'en' => 'Sphinx catalog: [continents] continents, [countries] countries, [regions] regions, [cities] cities',
        'ro' => 'Catalogul Sphinx: [continents] continente, [countries] țări, [regions] regiuni, [cities] orașe',
    ],
    'sphinx_holidays.dest_last_synced' => [
        'en' => 'destinations synced [at]',
        'ro' => 'destinații sincronizate la [at]',
    ],
    'sphinx_holidays.dest_card_title' => [
        'en' => 'Destinations',
        'ro' => 'Destinații',
    ],
    'sphinx_holidays.dest_card_intro' => [
        'en' => '[countries] countries, [cities] cities sold.',
        'ro' => '[countries] țări, [cities] orașe vândute.',
    ],
    'sphinx_holidays.dest_card_cities' => [
        'en' => '[n] cities',
        'ro' => '[n] orașe',
    ],
    'sphinx_holidays.dest_col_circuits' => [
        'en' => 'Circuits',
        'ro' => 'Circuite',
    ],
    'sphinx_holidays.dest_col_live' => [
        'en' => 'Live products',
        'ro' => 'Produse active',
    ],
    'sphinx_holidays.dest_alert_new' => [
        'en' => '[n] new destinations in [country] wait for review.',
        'ro' => '[n] destinații noi din [country] așteaptă verificarea.',
    ],
    'sphinx_holidays.dest_alert_outside' => [
        'en' => '[n] live products are outside the destination whitelist.',
        'ro' => '[n] produse active sunt în afara listei de destinații.',
    ],
];
