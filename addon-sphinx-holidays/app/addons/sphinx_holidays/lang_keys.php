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
        'en' => 'When enabled, only hotels with at least one confirmation=immediate offer become products and stay visible: the hotels cron probes each destination. A hotel without such an offer and without a product is kept, marked as waiting, and becomes a product once an immediate offer appears (the Hotels grid hides waiting hotels unless you choose them in the Availability filter). A hotel already linked to a CS-Cart product has its product set to Hidden — reachable only by direct link — and reactivated when immediate availability returns. If you activate such a product by hand, it stays active; the gate takes it over again once the hotel has an immediate offer. The storefront search shows only immediate-confirmation offers. The hotels cron accepts a per-run override: &availability_gate=0 to skip the gate, &availability_gate=1 to force it.',
        'ro' => 'Când este activ, doar hotelurile cu cel puțin o ofertă confirmation=immediate devin produse și rămân vizibile: cronul de hoteluri verifică fiecare destinație. Un hotel fără o astfel de ofertă și fără produs este păstrat, marcat ca fiind în așteptare, și devine produs când apare o ofertă imediată (grila Hoteluri ascunde hotelurile în așteptare, dacă nu le alegeți în filtrul Disponibilitate). Un hotel deja legat de un produs CS-Cart are produsul setat ca Ascuns — accesibil doar prin link direct — și este reactivat când revine disponibilitatea imediată. Dacă activați manual un astfel de produs, acesta rămâne activ; filtrul îl preia din nou după ce hotelul are o ofertă imediată. Căutarea din storefront afișează doar ofertele cu confirmare imediată. Cronul de hoteluri acceptă o suprascriere per rulare: &availability_gate=0 pentru a omite filtrul, &availability_gate=1 pentru a-l forța.',
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
    'sphinx_holidays.availability_filter' => [
        'en' => 'Availability',
        'ro' => 'Disponibilitate',
    ],
    'sphinx_holidays.availability_immediate' => [
        'en' => 'Immediate confirmation',
        'ro' => 'Confirmare imediată',
    ],
    'sphinx_holidays.availability_waiting' => [
        'en' => 'Waiting for an immediate offer',
        'ro' => 'În așteptarea unei oferte imediate',
    ],
    'sphinx_holidays.availability_all' => [
        'en' => 'All hotels',
        'ro' => 'Toate hotelurile',
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
    'sphinx_holidays.ph_rating' => ['en' => 'Guest rating', 'ro' => 'Rating turiști'],
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
    'sphinx_holidays.booking_not_bound' => [
        'en' => 'Your booking for "[hotel]" could not be confirmed and was removed from the cart. Please book it again.',
        'ro' => 'Rezervarea pentru „[hotel]” nu a putut fi confirmată și a fost eliminată din coș. Vă rugăm să o rezervați din nou.',
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
    'sphinx_holidays.trip_kind_flight' => [
        'en' => 'Package · flight + hotel',
        'ro' => 'Pachet · avion + hotel',
    ],
    'sphinx_holidays.trip_kind_bus' => [
        'en' => 'Package · coach + hotel',
        'ro' => 'Pachet · autocar + hotel',
    ],
    'sphinx_holidays.trip_kind_hotel' => [
        'en' => 'Package',
        'ro' => 'Pachet',
    ],
    'sphinx_holidays.trip_kind_circuit' => [
        'en' => 'Circuit',
        'ro' => 'Circuit',
    ],
    'sphinx_holidays.trip_return' => [
        'en' => 'Return',
        'ro' => 'Întoarcere',
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
        'en' => 'in [n] {region|regions}',
        'ro' => 'în [n] {regiune|regiuni|de regiuni}',
    ],
    'sphinx_holidays.dest_tile_hotels' => [
        'en' => 'Hotels in scope',
        'ro' => 'Hoteluri incluse',
    ],
    'sphinx_holidays.dest_tile_hotels_note' => [
        'en' => '[n] live {product|products}',
        'ro' => '[n] {produs activ|produse active|de produse active}',
    ],
    'sphinx_holidays.dest_tile_circuits' => [
        'en' => 'Circuits in scope',
        'ro' => 'Circuite incluse',
    ],
    'sphinx_holidays.dest_tile_circuits_note' => [
        'en' => '[n] package {route|routes}',
        'ro' => '[n] {rută de pachete|rute de pachete|de rute de pachete}',
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
        'en' => '[n] new {destination|destinations} appeared in the destinations sync. {It is|They are} not sold until you tick {it|them}.',
        'ro' => '[n] {destinație nouă a apărut|destinații noi au apărut|de destinații noi au apărut} la sincronizarea destinațiilor. Nu se {vinde|vând|vând} până nu {o bifați|le bifați|le bifați}.',
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
        'en' => '[regions] {region|regions} · [cities] {city|cities} · [hotels] {hotel|hotels}',
        'ro' => '[regions] {regiune|regiuni|de regiuni} · [cities] {oraș|orașe|de orașe} · [hotels] {hotel|hoteluri|de hoteluri}',
    ],
    'sphinx_holidays.dest_country_meta_none' => [
        'en' => '[regions] {region|regions} · [cities] {city|cities} · hotels not synced yet',
        'ro' => '[regions] {regiune|regiuni|de regiuni} · [cities] {oraș|orașe|de orașe} · hoteluri nesincronizate încă',
    ],
    'sphinx_holidays.dest_region_meta' => [
        'en' => '[cities] {city|cities} · [hotels] {hotel|hotels}',
        'ro' => '[cities] {oraș|orașe|de orașe} · [hotels] {hotel|hoteluri|de hoteluri}',
    ],
    'sphinx_holidays.dest_city_meta' => [
        'en' => '[hotels] {hotel|hotels}',
        'ro' => '[hotels] {hotel|hoteluri|de hoteluri}',
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
        'en' => '[countries] {country|countries}, [cities] {city|cities} sold.',
        'ro' => '[countries] {țară|țări|de țări}, [cities] {oraș vândut|orașe vândute|de orașe vândute}.',
    ],
    'sphinx_holidays.dest_card_cities' => [
        'en' => '[n] {city|cities}',
        'ro' => '[n] {oraș|orașe|de orașe}',
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
        'en' => '[n] new {destination|destinations} in [country] {waits|wait} for review.',
        'ro' => '[n] {destinație nouă|destinații noi|de destinații noi} din [country] {așteaptă|așteaptă|așteaptă} verificarea.',
    ],
    'sphinx_holidays.dest_alert_outside' => [
        'en' => '[n] live {product is|products are} outside the destination whitelist.',
        'ro' => '[n] {produs activ este|produse active sunt|de produse active sunt} în afara listei de destinații.',
    ],
    // The email to the orders department when Sphinx does not confirm a
    // booking (Services\BookingAlert).
    'sphinx_holidays.book_alert_subject' => [
        'en' => 'Sphinx booking #[booking_id] not confirmed (order #[order_id])',
        'ro' => 'Rezervarea Sphinx #[booking_id] nu e confirmată (comanda #[order_id])',
    ],
    'sphinx_holidays.book_alert_failed' => [
        'en' => 'Sphinx did not make booking #[booking_id] ([what]) of order #[order_id]: [reason]. It is marked failed: once the cause is fixed, retry it under Travel → Bookings.',
        'ro' => 'Sphinx nu a făcut rezervarea #[booking_id] ([what]) din comanda #[order_id]: [reason]. E marcată eșuată: după ce cauza e rezolvată, o puteți reîncerca din Turism → Rezervări.',
    ],
    'sphinx_holidays.book_alert_pending' => [
        'en' => 'Sphinx gave no clear answer for booking #[booking_id] ([what]) of order #[order_id]: [reason]. It may exist at Sphinx: it stays pending, and the status sync looks it up by the order number. Do not book it again before checking it in Sphinx.',
        'ro' => 'Sphinx nu a dat un răspuns clar pentru rezervarea #[booking_id] ([what]) din comanda #[order_id]: [reason]. Poate exista deja la Sphinx: rămâne în așteptare, iar sincronizarea stărilor o caută după numărul comenzii. Nu o rezervați din nou înainte de a o verifica în Sphinx.',
    ],
    // Package add-to-cart: packages/book wants each traveller's gender and
    // each child's birth date.
    'sphinx_holidays.traveller_details_required' => [
        'en' => 'Please choose each traveller\'s gender, and fill in each child\'s date of birth.',
        'ro' => 'Alegeți genul fiecărui turist și completați data nașterii fiecărui copil.',
    ],
    // Scheduled jobs (Cron\CronPlan: stages, jobs, Sphinx's own ⋯ menu items)
    // and the dashboard's Queue images for a scope and Check one hotel cards.
    'sphinx_holidays.jobs_stage_reference' => [
        'en' => 'Reference data',
        'ro' => 'Date de referință',
    ],
    'sphinx_holidays.jobs_stage_catalogue' => [
        'en' => 'Catalogue',
        'ro' => 'Catalog',
    ],
    'sphinx_holidays.jobs_stage_products' => [
        'en' => 'Products',
        'ro' => 'Produse',
    ],
    'sphinx_holidays.jobs_stage_locations' => [
        'en' => 'Locations and product updates',
        'ro' => 'Locații și actualizarea produselor',
    ],
    'sphinx_holidays.jobs_stage_images' => [
        'en' => 'Images',
        'ro' => 'Imagini',
    ],
    'sphinx_holidays.jobs_stage_upkeep' => [
        'en' => 'Bookings and upkeep',
        'ro' => 'Rezervări și întreținere',
    ],
    'sphinx_holidays.jobs_job_destinations' => [
        'en' => 'Destinations',
        'ro' => 'Destinații',
    ],
    'sphinx_holidays.jobs_job_destinations_desc' => [
        'en' => 'Countries, regions and cities from Sphinx. Resumes where it stopped.',
        'ro' => 'Țările, regiunile și orașele din Sphinx. Continuă de unde a rămas.',
    ],
    'sphinx_holidays.jobs_job_hotels' => [
        'en' => 'Hotels',
        'ro' => 'Hoteluri',
    ],
    'sphinx_holidays.jobs_job_hotels_desc' => [
        'en' => 'Hotels in the destinations you sell. Force full sync re-reads every hotel, coordinates too.',
        'ro' => 'Hotelurile din destinațiile pe care le vinzi. Sincronizarea completă le recitește pe toate, inclusiv coordonatele.',
    ],
    'sphinx_holidays.jobs_job_circuits' => [
        'en' => 'Circuits',
        'ro' => 'Circuite',
    ],
    'sphinx_holidays.jobs_job_circuits_desc' => [
        'en' => 'The circuit catalogue, incremental. Force full sync re-reads every circuit.',
        'ro' => 'Catalogul de circuite, incremental. Sincronizarea completă le recitește pe toate.',
    ],
    'sphinx_holidays.jobs_job_package_routes' => [
        'en' => 'Package routes',
        'ro' => 'Rute pentru pachete',
    ],
    'sphinx_holidays.jobs_job_package_routes_desc' => [
        'en' => 'Flight and bus routes for packages.',
        'ro' => 'Rutele de zbor și de autocar pentru pachete.',
    ],
    'sphinx_holidays.jobs_job_add_products' => [
        'en' => 'Create products',
        'ro' => 'Creează produse',
    ],
    'sphinx_holidays.jobs_job_add_products_desc' => [
        'en' => 'A product for each hotel that has none yet. A hotel without an immediate offer waits.',
        'ro' => 'Un produs pentru fiecare hotel care nu are încă unul. Un hotel fără ofertă imediată așteaptă.',
    ],
    'sphinx_holidays.jobs_job_discover_boards' => [
        'en' => 'Meal plans',
        'ro' => 'Tipuri de masă',
    ],
    'sphinx_holidays.jobs_job_discover_boards_desc' => [
        'en' => 'Which boards each hotel offers, from the live search API. Batched; resumes where it stopped.',
        'ro' => 'Tipurile de masă oferite de fiecare hotel, din căutarea live. Pe loturi; continuă de unde a rămas.',
    ],
    'sphinx_holidays.jobs_job_assign_boards' => [
        'en' => 'Assign boards',
        'ro' => 'Atribuie tipurile de masă',
    ],
    'sphinx_holidays.jobs_job_assign_boards_desc' => [
        'en' => 'Puts the boards found onto the hotels\' products. Batched; resumes where it stopped.',
        'ro' => 'Pune tipurile de masă găsite pe produsele hotelurilor. Pe loturi; continuă de unde a rămas.',
    ],
    'sphinx_holidays.jobs_job_backfill_hotel_locations' => [
        'en' => 'Hotel locations',
        'ro' => 'Locațiile hotelurilor',
    ],
    'sphinx_holidays.jobs_job_backfill_hotel_locations_desc' => [
        'en' => 'Re-reads City and Region for stored hotels from the destination tree. No API calls.',
        'ro' => 'Recitește orașul și regiunea hotelurilor salvate din arborele de destinații. Fără apeluri API.',
    ],
    'sphinx_holidays.jobs_job_geocode_hotels' => [
        'en' => 'Geocode hotels',
        'ro' => 'Geocodare hoteluri',
    ],
    'sphinx_holidays.jobs_job_geocode_hotels_desc' => [
        'en' => 'City, Region and street from coordinates (OpenStreetMap, one request a second, 100 hotels a run), when the tree and the address cannot tell. Needs geocoding on in Travel Core.',
        'ro' => 'Orașul, regiunea și strada din coordonate (OpenStreetMap, o cerere pe secundă, 100 de hoteluri pe rulare), când arborele și adresa nu le dau. Necesită geocodarea activă în Travel Core.',
    ],
    'sphinx_holidays.jobs_job_update_products' => [
        'en' => 'Update products',
        'ro' => 'Actualizează produsele',
    ],
    'sphinx_holidays.jobs_job_update_products_desc' => [
        'en' => 'Carries changed hotel data onto the products: stars, City, Region, boards. Costs nothing when nothing changed.',
        'ro' => 'Duce pe produse datele schimbate ale hotelurilor: stele, oraș, regiune, tipuri de masă. Nu costă nimic când nu s-a schimbat nimic.',
    ],
    'sphinx_holidays.jobs_job_enrich_hotel_data' => [
        'en' => 'Image data',
        'ro' => 'Datele imaginilor',
    ],
    'sphinx_holidays.jobs_job_enrich_hotel_data_desc' => [
        'en' => 'Fills in the image list of hotels that have none, from the hotel detail API. A hotel Sphinx has no images for is asked again after 30 days.',
        'ro' => 'Completează lista de imagini a hotelurilor care nu au, din API-ul de detalii. Un hotel pentru care Sphinx nu are imagini e întrebat din nou după 30 de zile.',
    ],
    'sphinx_holidays.jobs_job_sync_images' => [
        'en' => 'Queue images',
        'ro' => 'Pune imaginile în coadă',
    ],
    'sphinx_holidays.jobs_job_sync_images_desc' => [
        'en' => 'Queues each hotel\'s images from the database. No API calls. For fewer hotels: Queue images for a scope, below.',
        'ro' => 'Pune în coadă imaginile fiecărui hotel, din baza de date. Fără apeluri API. Pentru mai puține hoteluri: Pune imaginile în coadă pentru un domeniu, mai jos.',
    ],
    'sphinx_holidays.jobs_job_process_image_queue' => [
        'en' => 'Download images',
        'ro' => 'Descarcă imaginile',
    ],
    'sphinx_holidays.jobs_job_process_image_queue_desc' => [
        'en' => 'Downloads and attaches the next 50 queued images.',
        'ro' => 'Descarcă și atașează următoarele 50 de imagini din coadă.',
    ],
    'sphinx_holidays.jobs_job_order_status' => [
        'en' => 'Booking statuses',
        'ro' => 'Statusurile rezervărilor',
    ],
    'sphinx_holidays.jobs_job_order_status_desc' => [
        'en' => 'Checks each Sphinx booking\'s status through the Orders API.',
        'ro' => 'Verifică statusul fiecărei rezervări Sphinx prin API-ul de comenzi.',
    ],
    'sphinx_holidays.jobs_job_cleanup' => [
        'en' => 'Cleanup',
        'ro' => 'Curățenie',
    ],
    'sphinx_holidays.jobs_job_cleanup_desc' => [
        'en' => 'Expired cache, orphan bookings and old sync logs.',
        'ro' => 'Cache expirat, rezervări orfane și jurnale de sincronizare vechi.',
    ],
    'sphinx_holidays.jobs_job_reassign_features' => [
        'en' => 'Reassign features',
        'ro' => 'Reatribuie caracteristicile',
    ],
    'sphinx_holidays.jobs_job_reassign_features_desc' => [
        'en' => 'Re-assigns every product feature (stars, City, Region, boards…) of every linked hotel: the catch-all when something looks stale.',
        'ro' => 'Reatribuie toate caracteristicile produselor (stele, oraș, regiune, tipuri de masă…) pentru fiecare hotel legat: soluția generală când ceva pare învechit.',
    ],
    'sphinx_holidays.jobs_job_full' => [
        'en' => 'Full sync',
        'ro' => 'Sincronizare completă',
    ],
    'sphinx_holidays.jobs_job_full_desc' => [
        'en' => 'Every sync in one run: repeats the jobs above.',
        'ro' => 'Toate sincronizările într-o singură rulare: repetă joburile de mai sus.',
    ],
    'sphinx_holidays.jobs_job_add_circuit_products' => [
        'en' => 'Circuit products',
        'ro' => 'Produse pentru circuite',
    ],
    'sphinx_holidays.jobs_job_add_circuit_products_desc' => [
        'en' => 'Products for the synced circuits that have none yet, so they can be sold.',
        'ro' => 'Produse pentru circuitele sincronizate care nu au încă unul, ca să poată fi vândute.',
    ],
    'sphinx_holidays.jobs_job_experiences' => [
        'en' => 'Experiences',
        'ro' => 'Experiențe',
    ],
    'sphinx_holidays.jobs_job_experiences_desc' => [
        'en' => 'The experience catalogue from the Sphinx static API.',
        'ro' => 'Catalogul de experiențe din API-ul static Sphinx.',
    ],
    'sphinx_holidays.jobs_job_calendar_prices' => [
        'en' => 'Calendar prices',
        'ro' => 'Prețurile din calendar',
    ],
    'sphinx_holidays.jobs_job_calendar_prices_desc' => [
        'en' => 'The lowest price on each date of the next 60 days, for the booking engine\'s calendar and the products\' prices.',
        'ro' => 'Cel mai mic preț pe fiecare dată din următoarele 60 de zile, pentru calendarul motorului de rezervare și prețurile produselor.',
    ],
    'sphinx_holidays.jobs_job_sync_and_upload_images' => [
        'en' => 'Queue and download images',
        'ro' => 'Coadă și descărcare imagini',
    ],
    'sphinx_holidays.jobs_job_sync_and_upload_images_desc' => [
        'en' => 'Queue images and Download images in one run.',
        'ro' => 'Pune imaginile în coadă și le descarcă, într-o singură rulare.',
    ],
    'sphinx_holidays.jobs_job_deduplicate' => [
        'en' => 'Merge duplicate hotels',
        'ro' => 'Unește hotelurile duplicate',
    ],
    'sphinx_holidays.jobs_job_deduplicate_desc' => [
        'en' => 'Finds and merges duplicate hotels: same name, type, classification and region.',
        'ro' => 'Găsește și unește hotelurile duplicate: același nume, tip, clasificare și regiune.',
    ],
    'sphinx_holidays.jobs_job_audit_facilities' => [
        'en' => 'Audit facilities',
        'ro' => 'Verifică facilitățile',
    ],
    'sphinx_holidays.jobs_job_audit_facilities_desc' => [
        'en' => 'Lists the facility IDs in Sphinx hotels that have no mapping. A report; changes nothing.',
        'ro' => 'Listează ID-urile de facilități din hotelurile Sphinx care nu au corespondență. Un raport; nu schimbă nimic.',
    ],
    'sphinx_holidays.jobs_on_demand_intro' => [
        'en' => 'Not part of the schedule: each one runs when you start it. Full sync repeats the jobs above.',
        'ro' => 'Nu fac parte din program: fiecare rulează când îl pornești. Sincronizarea completă repetă joburile de mai sus.',
    ],
    'sphinx_holidays.jobs_requeue_images' => [
        'en' => 'Queue all images again…',
        'ro' => 'Pune din nou toate imaginile în coadă…',
    ],
    'sphinx_holidays.jobs_requeue_images_confirm' => [
        'en' => 'Queue the images of every hotel again, including the ones that already have them?',
        'ro' => 'Pui din nou în coadă imaginile tuturor hotelurilor, inclusiv ale celor care le au deja?',
    ],
    'sphinx_holidays.jobs_retry_failed_images' => [
        'en' => 'Retry failed images',
        'ro' => 'Reîncearcă imaginile eșuate',
    ],
    'sphinx_holidays.jobs_unknown' => [
        'en' => 'Unknown job: [job]',
        'ro' => 'Job necunoscut: [job]',
    ],
    'sphinx_holidays.jobs_scope_title' => [
        'en' => 'Queue images for a scope',
        'ro' => 'Pune imaginile în coadă pentru un domeniu',
    ],
    'sphinx_holidays.jobs_scope_intro' => [
        'en' => 'Queue images takes every country you sell; here it runs once, for fewer hotels. The log opens in a new tab.',
        'ro' => 'Pune imaginile în coadă ia toate țările pe care le vinzi; aici rulează o dată, pentru mai puține hoteluri. Jurnalul se deschide într-un tab nou.',
    ],
    'sphinx_holidays.jobs_scope_which' => [
        'en' => 'Which hotels',
        'ro' => 'Ce hoteluri',
    ],
    'sphinx_holidays.jobs_scope_all' => [
        'en' => 'Every country you sell',
        'ro' => 'Toate țările pe care le vinzi',
    ],
    'sphinx_holidays.jobs_scope_strict' => [
        'en' => 'Only the destinations you picked',
        'ro' => 'Doar destinațiile alese',
    ],
    'sphinx_holidays.jobs_scope_country' => [
        'en' => 'One country',
        'ro' => 'O țară',
    ],
    'sphinx_holidays.jobs_scope_destination' => [
        'en' => 'One destination',
        'ro' => 'O destinație',
    ],
    'sphinx_holidays.jobs_scope_region' => [
        'en' => 'One region',
        'ro' => 'O regiune',
    ],
    'sphinx_holidays.jobs_scope_country_label' => [
        'en' => 'Country (for One country)',
        'ro' => 'Țara (pentru O țară)',
    ],
    'sphinx_holidays.jobs_scope_id' => [
        'en' => 'Destination or region ID',
        'ro' => 'ID-ul destinației sau al regiunii',
    ],
    'sphinx_holidays.jobs_scope_limit' => [
        'en' => 'At most this many hotels',
        'ro' => 'Cel mult atâtea hoteluri',
    ],
    'sphinx_holidays.jobs_scope_force' => [
        'en' => 'Queue hotels that already have images again',
        'ro' => 'Pune din nou în coadă și hotelurile care au deja imagini',
    ],
    'sphinx_holidays.jobs_scope_schedule_hint' => [
        'en' => 'To schedule a scope, pick it above and press Copy URL or Copy CLI command: they copy Queue images\' command with the scope\'s parameters, for the crontab or a cron service.',
        'ro' => 'Pentru a programa un domeniu, alege-l mai sus și apasă Copiază URL-ul sau Copiază comanda CLI: copiază comanda jobului Pune imaginile în coadă cu parametrii domeniului, pentru crontab sau un serviciu cron.',
    ],
    'sphinx_holidays.jobs_images_how' => [
        'en' => 'How image sync works',
        'ro' => 'Cum funcționează sincronizarea imaginilor',
    ],
    'sphinx_holidays.jobs_images_step_enrich' => [
        'en' => 'Image data reads, from the hotel detail API, the image list of each hotel that has none; a hotel Sphinx has no images for is asked again after 30 days.',
        'ro' => 'Datele imaginilor citesc din API-ul de detalii lista de imagini a fiecărui hotel care nu are; un hotel pentru care Sphinx nu are imagini e întrebat din nou după 30 de zile.',
    ],
    'sphinx_holidays.jobs_images_step_queue' => [
        'en' => 'Queue images adds one queue row per image, from the database; images already queued are skipped, so it is safe to run again.',
        'ro' => 'Pune imaginile în coadă adaugă un rând în coadă pentru fiecare imagine, din baza de date; imaginile deja în coadă sunt sărite, deci poate rula din nou fără grijă.',
    ],
    'sphinx_holidays.jobs_images_step_download' => [
        'en' => 'Download images takes the next 50 rows every 2 minutes: it downloads each image, attaches it to the product and marks the row done or failed.',
        'ro' => 'Descarcă imaginile ia următoarele 50 de rânduri la fiecare 2 minute: descarcă fiecare imagine, o atașează la produs și marchează rândul ca terminat sau eșuat.',
    ],
    'sphinx_holidays.jobs_check_title' => [
        'en' => 'Check one hotel',
        'ro' => 'Verifică un hotel',
    ],
    'sphinx_holidays.jobs_check_intro' => [
        'en' => 'Each check opens in a new tab as a signed-in admin action, with no key in its address.',
        'ro' => 'Fiecare verificare se deschide într-un tab nou, ca acțiune de administrator autentificat, fără cheie în adresă.',
    ],
    'sphinx_holidays.jobs_check_hotel' => [
        'en' => 'Hotel ID or name',
        'ro' => 'ID-ul sau numele hotelului',
    ],
    'sphinx_holidays.jobs_check_hotel_hint' => [
        'en' => 'Search API also takes a name (kazbek dubrovnik); the other checks need the hotel\'s ID.',
        'ro' => 'API-ul de căutare acceptă și un nume (kazbek dubrovnik); celelalte verificări au nevoie de ID-ul hotelului.',
    ],
    'sphinx_holidays.jobs_check_search' => [
        'en' => 'Search API',
        'ro' => 'API-ul de căutare',
    ],
    'sphinx_holidays.jobs_check_images' => [
        'en' => 'Images',
        'ro' => 'Imagini',
    ],
    'sphinx_holidays.jobs_check_seo' => [
        'en' => 'SEO fields',
        'ro' => 'Câmpuri SEO',
    ],
    'sphinx_holidays.jobs_check_features' => [
        'en' => 'Product features',
        'ro' => 'Caracteristicile produsului',
    ],
    'sphinx_holidays.jobs_check_attach' => [
        'en' => 'Images: also attach them to the product',
        'ro' => 'Imagini: le atașează și la produs',
    ],
    'sphinx_holidays.jobs_check_attach_confirm' => [
        'en' => 'Attach this hotel\'s images to its product?',
        'ro' => 'Atașezi imaginile acestui hotel la produsul lui?',
    ],
    'sphinx_holidays.jobs_check_apply' => [
        'en' => 'SEO fields: also write the missing ones',
        'ro' => 'Câmpuri SEO: le scrie și pe cele lipsă',
    ],
    'sphinx_holidays.jobs_check_apply_confirm' => [
        'en' => 'Write this hotel\'s missing SEO fields?',
        'ro' => 'Scrii câmpurile SEO lipsă ale acestui hotel?',
    ],
    // The dashboard's At a glance tiles, Needs attention and Tools card
    // (Services\DashboardSummary, views/sphinx_holidays/manage.tpl).
    'sphinx_holidays.dash_tile_hotels' => [
        'en' => 'Hotels in scope',
        'ro' => 'Hoteluri în destinațiile vândute',
    ],
    'sphinx_holidays.dash_tile_hotels_cities' => [
        'en' => 'Cities sold: [n]',
        'ro' => 'Orașe vândute: [n]',
    ],
    'sphinx_holidays.dash_tile_hotels_synced' => [
        'en' => 'Last hotel sync: [date]',
        'ro' => 'Ultima sincronizare a hotelurilor: [date]',
    ],
    'sphinx_holidays.dash_tile_hotels_open' => [
        'en' => 'Open hotels',
        'ro' => 'Deschide hotelurile',
    ],
    'sphinx_holidays.dash_tile_products' => [
        'en' => 'Products',
        'ro' => 'Produse',
    ],
    'sphinx_holidays.dash_tile_products_note' => [
        'en' => 'Linked to a Sphinx hotel · live in the destinations you sell: [n]',
        'ro' => 'Legate de un hotel Sphinx · active în destinațiile pe care le vinzi: [n]',
    ],
    'sphinx_holidays.dash_tile_products_open' => [
        'en' => 'See the hotels with a product',
        'ro' => 'Vezi hotelurile cu produs',
    ],
    'sphinx_holidays.dash_tile_unlinked' => [
        'en' => 'Hotels without a product',
        'ro' => 'Hoteluri fără produs',
    ],
    'sphinx_holidays.dash_tile_unlinked_value' => [
        'en' => '[n] of [total]',
        'ro' => '[n] din [total]',
    ],
    'sphinx_holidays.dash_tile_unlinked_waiting' => [
        'en' => 'Waiting for an immediate offer: [n]',
        'ro' => 'Așteaptă o ofertă imediată: [n]',
    ],
    'sphinx_holidays.dash_tile_unlinked_skipped' => [
        'en' => 'skipped: [n]',
        'ro' => 'omise: [n]',
    ],
    'sphinx_holidays.dash_tile_unlinked_ready' => [
        'en' => 'ready to create: [n]',
        'ro' => 'gata de creat: [n]',
    ],
    'sphinx_holidays.dash_tile_unlinked_open' => [
        'en' => 'See which',
        'ro' => 'Vezi care',
    ],
    'sphinx_holidays.dash_attention_title' => [
        'en' => 'Needs attention ([n])',
        'ro' => 'Necesită atenție ([n])',
    ],
    'sphinx_holidays.dash_attention_refused' => [
        'en' => 'A cron request with a wrong key was refused on [date]: the crontab is probably still on an old key.',
        'ro' => 'O cerere cron cu o cheie greșită a fost refuzată pe [date]: crontab-ul folosește probabil încă o cheie veche.',
    ],
    'sphinx_holidays.dash_attention_copy_commands' => [
        'en' => 'Copy the commands again',
        'ro' => 'Copiază din nou comenzile',
    ],
    'sphinx_holidays.dash_attention_failed' => [
        'en' => '[job] failed on [date].',
        'ro' => '[job] a eșuat pe [date].',
    ],
    'sphinx_holidays.dash_attention_stalled' => [
        'en' => '[job] started on [date] and never finished.',
        'ro' => '[job] a pornit pe [date] și nu s-a mai terminat.',
    ],
    'sphinx_holidays.dash_attention_never' => [
        'en' => '[job] has never run.',
        'ro' => '[job] nu a rulat niciodată.',
    ],
    'sphinx_holidays.dash_attention_late' => [
        'en' => '[job] is late: it last finished on [date]. Schedule: [schedule].',
        'ro' => '[job] întârzie: s-a terminat ultima dată pe [date]. Program: [schedule].',
    ],
    'sphinx_holidays.dash_attention_run' => [
        'en' => 'Run [job]',
        'ro' => 'Rulează [job]',
    ],
    'sphinx_holidays.dash_attention_skipped' => [
        'en' => 'Hotels skipped, with no product: [n].',
        'ro' => 'Hoteluri omise, fără produs: [n].',
    ],
    'sphinx_holidays.dash_tools_title' => [
        'en' => 'Tools',
        'ro' => 'Instrumente',
    ],
    'sphinx_holidays.dash_tools_intro' => [
        'en' => 'Syncing now is each job\'s Run, under Scheduled jobs: it opens the job\'s log in a new window and leaves this page where it is.',
        'ro' => 'Sincronizarea imediată se face cu Rulează, la fiecare job din Joburi programate: deschide jurnalul jobului într-o fereastră nouă și lasă pagina aceasta neschimbată.',
    ],
    'sphinx_holidays.dash_tools_fix' => [
        'en' => 'Fix products',
        'ro' => 'Repară produsele',
    ],
    'sphinx_holidays.dash_tools_fix_hint' => [
        'en' => 'Create products and Re-link existing products show here only when there is something to create or re-link.',
        'ro' => 'Creare Produse și Reconectează produsele existente apar aici doar când e ceva de creat sau de reconectat.',
    ],
    // The dashboard's Recent sync activity table
    // (Services\SyncActivity, components/activity.tpl).
    'sphinx_holidays.dash_activity_title' => [
        'en' => 'Recent sync activity',
        'ro' => 'Activitate recentă de sincronizare',
    ],
    'sphinx_holidays.dash_activity_filter' => [
        'en' => 'Which runs',
        'ro' => 'Ce rulări',
    ],
    'sphinx_holidays.dash_activity_all' => [
        'en' => 'All ([n])',
        'ro' => 'Toate ([n])',
    ],
    'sphinx_holidays.dash_activity_problems' => [
        'en' => 'Problems ([n])',
        'ro' => 'Probleme ([n])',
    ],
    'sphinx_holidays.dash_activity_col_date' => [
        'en' => 'Date',
        'ro' => 'Data',
    ],
    'sphinx_holidays.dash_activity_col_job' => [
        'en' => 'Job',
        'ro' => 'Job',
    ],
    'sphinx_holidays.dash_activity_col_run' => [
        'en' => 'Run',
        'ro' => 'Rulare',
    ],
    'sphinx_holidays.dash_activity_col_items' => [
        'en' => 'Items',
        'ro' => 'Elemente',
    ],
    'sphinx_holidays.dash_activity_col_not_synced' => [
        'en' => 'Not synced',
        'ro' => 'Nesincronizate',
    ],
    'sphinx_holidays.dash_activity_col_status' => [
        'en' => 'Status',
        'ro' => 'Stare',
    ],
    'sphinx_holidays.dash_activity_col_duration' => [
        'en' => 'Duration',
        'ro' => 'Durată',
    ],
    'sphinx_holidays.dash_activity_full' => [
        'en' => 'Full',
        'ro' => 'Completă',
    ],
    'sphinx_holidays.dash_activity_incremental' => [
        'en' => 'Incremental',
        'ro' => 'Incrementală',
    ],
    'sphinx_holidays.dash_activity_ok' => [
        'en' => 'Completed',
        'ro' => 'Finalizată',
    ],
    'sphinx_holidays.dash_activity_partial' => [
        'en' => 'Partly done',
        'ro' => 'Parțial',
    ],
    'sphinx_holidays.dash_activity_failed' => [
        'en' => 'Failed',
        'ro' => 'Eșuată',
    ],
    'sphinx_holidays.dash_activity_stalled' => [
        'en' => 'Never finished',
        'ro' => 'Neterminată',
    ],
    'sphinx_holidays.dash_activity_running' => [
        'en' => 'Running',
        'ro' => 'În curs',
    ],
    'sphinx_holidays.dash_activity_rate_limited' => [
        'en' => 'Rate-limited requests: [n]',
        'ro' => 'Cereri limitate de API: [n]',
    ],
    'sphinx_holidays.dash_activity_show_error' => [
        'en' => 'Show full error',
        'ro' => 'Arată eroarea completă',
    ],
    'sphinx_holidays.dash_activity_none' => [
        'en' => 'No sync runs logged yet.',
        'ro' => 'Nicio sincronizare înregistrată încă.',
    ],
    'sphinx_holidays.dash_activity_no_problems' => [
        'en' => 'No problems in the log.',
        'ro' => 'Nicio problemă în jurnal.',
    ],
];
