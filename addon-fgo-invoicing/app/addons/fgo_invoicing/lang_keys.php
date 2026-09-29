<?php

/**
 * Language keys for the FGO Invoicing addon.
 *
 * NOTE: the seeder merges these with addon.xml <language_variables>, and
 * addon.xml WINS on conflict. Settings labels/tooltips belong in addon.xml;
 * keep this file for runtime-only keys (admin invoice pages, order-details
 * panel, customer e-mail) that addon.xml does not declare. Editing a key here
 * that also exists in addon.xml has no effect.
 *
 * Kept byte-parity with var/langs/<lang>/addons/fgo_invoicing.po by
 * LanguageKeysParityTest — the .po is what CS-Cart imports at install time,
 * this file is what the runtime self-heal seeds into ?:language_values on
 * stores that were installed before a key existed.
 */

return [
    // ── Admin: invoice list + detail pages ──────────────────────────────
    'fgo_invoicing.manage_title' => [
        'en' => 'FGO Invoices',
        'ro' => 'Facturi FGO',
    ],
    'fgo_invoicing.banner_sandbox' => [
        'en' => 'Sandbox mode is active. Invoices issued here are not fiscal.',
        'ro' => 'Modul sandbox este activ. Facturile emise aici nu sunt fiscale.',
    ],
    'fgo_invoicing.banner_production' => [
        'en' => 'Production mode is active. Invoices are real and fiscal.',
        'ro' => 'Modul producție este activ. Facturile sunt reale și fiscale.',
    ],
    'fgo_invoicing.no_invoices' => [
        'en' => 'No FGO invoices yet.',
        'ro' => 'Nicio factură FGO încă.',
    ],
    'fgo_invoicing.invoice_series' => [
        'en' => 'Series',
        'ro' => 'Serie',
    ],
    'fgo_invoicing.invoice_number' => [
        'en' => 'Number',
        'ro' => 'Număr',
    ],
    'fgo_invoicing.pdf_link' => [
        'en' => 'PDF link',
        'ro' => 'Link PDF',
    ],
    'fgo_invoicing.payment_link' => [
        'en' => 'Payment link',
        'ro' => 'Link plată',
    ],
    'fgo_invoicing.last_error' => [
        'en' => 'Last error',
        'ro' => 'Ultima eroare',
    ],
    'fgo_invoicing.message' => [
        'en' => 'Message',
        'ro' => 'Mesaj',
    ],
    'fgo_invoicing.retry_count' => [
        'en' => 'Retries',
        'ro' => 'Reîncercări',
    ],
    'fgo_invoicing.summary' => [
        'en' => 'Summary',
        'ro' => 'Sumar',
    ],
    'fgo_invoicing.actions' => [
        'en' => 'Actions',
        'ro' => 'Acțiuni',
    ],
    'fgo_invoicing.awb' => [
        'en' => 'AWB',
        'ro' => 'AWB',
    ],

    // ── Admin: buttons ──────────────────────────────────────────────────
    'fgo_invoicing.btn_issue' => [
        'en' => 'Issue invoice',
        'ro' => 'Emite factura',
    ],
    'fgo_invoicing.btn_cancel' => [
        'en' => 'Cancel invoice',
        'ro' => 'Anulează factura',
    ],
    'fgo_invoicing.btn_storno' => [
        'en' => 'Storno invoice',
        'ro' => 'Stornează factura',
    ],
    'fgo_invoicing.btn_delete' => [
        'en' => 'Delete invoice',
        'ro' => 'Șterge factura',
    ],
    'fgo_invoicing.btn_attach_awb' => [
        'en' => 'Attach AWB',
        'ro' => 'Atașează AWB',
    ],
    'fgo_invoicing.btn_view_panel' => [
        'en' => 'Open invoice',
        'ro' => 'Deschide factura',
    ],

    // ── Admin: confirmations ────────────────────────────────────────────
    'fgo_invoicing.confirm_issue' => [
        'en' => 'Issue (or re-issue) the FGO invoice for this order?',
        'ro' => 'Emiteți (sau reemiteți) factura FGO pentru această comandă?',
    ],
    'fgo_invoicing.confirm_cancel' => [
        'en' => 'Cancel the FGO invoice for this order?',
        'ro' => 'Anulați factura FGO pentru această comandă?',
    ],
    'fgo_invoicing.confirm_storno' => [
        'en' => 'Issue a storno (reversal) for this invoice?',
        'ro' => 'Emiteți un storno pentru această factură?',
    ],
    'fgo_invoicing.confirm_delete' => [
        'en' => 'Delete this invoice on FGO? This cannot be undone.',
        'ro' => 'Ștergeți această factură din FGO? Acțiunea este ireversibilă.',
    ],

    // ── Admin: order-details panel + notifications ──────────────────────
    'fgo_invoicing.invoice_for_order' => [
        'en' => 'FGO invoice for order',
        'ro' => 'Factură FGO pentru comanda',
    ],
    'fgo_invoicing.invoice' => [
        'en' => 'FGO Invoice',
        'ro' => 'Factură FGO',
    ],
    'fgo_invoicing.no_invoice_yet' => [
        'en' => 'No FGO invoice has been issued for this order yet.',
        'ro' => 'Nu a fost emisă încă nicio factură FGO pentru această comandă.',
    ],
    'fgo_invoicing.no_invoice_for_order' => [
        'en' => 'No FGO invoice exists for that order.',
        'ro' => 'Nu există nicio factură FGO pentru acea comandă.',
    ],
    'fgo_invoicing.invoice_issued' => [
        'en' => 'FGO invoice issued.',
        'ro' => 'Factură FGO emisă.',
    ],
    'fgo_invoicing.invoice_failed' => [
        'en' => 'FGO issue failed',
        'ro' => 'Emiterea FGO a eșuat',
    ],
    'fgo_invoicing.action_succeeded' => [
        'en' => 'FGO action completed.',
        'ro' => 'Acțiune FGO finalizată.',
    ],
    'fgo_invoicing.connection_ok' => [
        'en' => 'Connection OK',
        'ro' => 'Conexiune OK',
    ],
    'fgo_invoicing.connection_failed' => [
        'en' => 'Connection failed',
        'ro' => 'Conexiune eșuată',
    ],
    'fgo_invoicing.missing_order_id' => [
        'en' => 'Missing order id.',
        'ro' => 'Lipsește id-ul comenzii.',
    ],
    'fgo_invoicing.awb_attached' => [
        'en' => 'AWB attached.',
        'ro' => 'AWB atașat.',
    ],
    'fgo_invoicing.open_pdf' => [
        'en' => 'Open PDF',
        'ro' => 'Deschide PDF',
    ],
    'fgo_invoicing.open_payment' => [
        'en' => 'Open payment page',
        'ro' => 'Deschide pagina de plată',
    ],
    'fgo_invoicing.request_payload' => [
        'en' => 'Request payload',
        'ro' => 'Payload cerere',
    ],
    'fgo_invoicing.response_payload' => [
        'en' => 'Response payload',
        'ro' => 'Payload răspuns',
    ],

    // ── Admin: settings form (runtime-built options, not settings labels) ─
    // The empty option of the cif_field / reg_com_field / cnp_field selectors,
    // rendered by functions/settings_variants.php.
    'fgo_invoicing.profile_field_auto_detect' => [
        'en' => 'Auto-detect by field name',
        'ro' => 'Detectare automată după numele câmpului',
    ],

    // ── Customer e-mail ─────────────────────────────────────────────────
    'fgo_invoicing.email_subject' => [
        'en' => 'Your invoice',
        'ro' => 'Factura dumneavoastră',
    ],
    'fgo_invoicing.email_greeting' => [
        'en' => 'Hello',
        'ro' => 'Bună ziua',
    ],
    'fgo_invoicing.email_body_intro' => [
        'en' => 'Your fiscal invoice has been issued for order',
        'ro' => 'Factura dumneavoastră fiscală a fost emisă pentru comanda',
    ],
    'fgo_invoicing.email_pdf_button' => [
        'en' => 'View PDF invoice',
        'ro' => 'Vezi factura PDF',
    ],
    'fgo_invoicing.email_payment_link' => [
        'en' => 'Pay this invoice online',
        'ro' => 'Plătește factura online',
    ],
    'fgo_invoicing.email_signoff' => [
        'en' => 'Thank you for your order!',
        'ro' => 'Vă mulțumim pentru comandă!',
    ],

    // ── Admin: orders list, "FGO invoice" bulk-action menu ────────────────
    'fgo_invoicing.menu_fgo_invoice' => [
        'en' => 'FGO invoice',
        'ro' => 'Factură FGO',
    ],
    'fgo_invoicing.menu_issue' => [
        'en' => 'Issue invoices',
        'ro' => 'Emite facturi',
    ],
    'fgo_invoicing.menu_retry' => [
        'en' => 'Retry failed',
        'ro' => 'Reîncearcă facturile eșuate',
    ],
    'fgo_invoicing.menu_download_pdfs' => [
        'en' => 'Download PDFs (ZIP)',
        'ro' => 'Descarcă PDF-urile (ZIP)',
    ],
    'fgo_invoicing.menu_email' => [
        'en' => 'Email invoice to customer',
        'ro' => 'Trimite factura clientului pe e-mail',
    ],
    'fgo_invoicing.menu_cancel' => [
        'en' => 'Cancel invoice (Anulare)',
        'ro' => 'Anulează factura',
    ],
    'fgo_invoicing.menu_storno' => [
        'en' => 'Reverse invoice (Storno)',
        'ro' => 'Stornează factura',
    ],
    'fgo_invoicing.menu_delete' => [
        'en' => 'Delete invoice',
        'ro' => 'Șterge factura',
    ],
    'fgo_invoicing.menu_open_log' => [
        'en' => 'Open FGO invoice log',
        'ro' => 'Deschide jurnalul facturilor FGO',
    ],

    // ── Admin: orders list, FGO column ────────────────────────────────────
    'fgo_invoicing.col_fgo' => [
        'en' => 'FGO',
        'ro' => 'FGO',
    ],
    'fgo_invoicing.col_not_invoiced' => [
        'en' => 'Not invoiced',
        'ro' => 'Nefacturată',
    ],
    'fgo_invoicing.col_failed' => [
        'en' => 'FGO failed',
        'ro' => 'Eroare FGO',
    ],
    'fgo_invoicing.col_details' => [
        'en' => 'Details',
        'ro' => 'Detalii',
    ],
    'fgo_invoicing.state_issued' => [
        'en' => 'Issued',
        'ro' => 'Emisă',
    ],
    'fgo_invoicing.state_failed' => [
        'en' => 'Failed',
        'ro' => 'Eșuată',
    ],
    'fgo_invoicing.state_pending' => [
        'en' => 'Pending',
        'ro' => 'În curs',
    ],
    'fgo_invoicing.state_canceled' => [
        'en' => 'Canceled',
        'ro' => 'Anulată',
    ],
    'fgo_invoicing.state_reversed' => [
        'en' => 'Reversed (storno)',
        'ro' => 'Stornată',
    ],
    'fgo_invoicing.state_deleted' => [
        'en' => 'Deleted',
        'ro' => 'Ștearsă',
    ],

    // ── Admin: bulk pre-check / results page ──────────────────────────────
    'fgo_invoicing.bulk_title_issue' => [
        'en' => 'Issue FGO invoices',
        'ro' => 'Emitere facturi FGO',
    ],
    'fgo_invoicing.bulk_title_retry' => [
        'en' => 'Retry failed FGO invoices',
        'ro' => 'Reîncercare facturi FGO eșuate',
    ],
    'fgo_invoicing.bulk_title_email' => [
        'en' => 'Email FGO invoices to customers',
        'ro' => 'Trimitere facturi FGO clienților',
    ],
    'fgo_invoicing.bulk_title_cancel' => [
        'en' => 'Cancel FGO invoices (Anulare)',
        'ro' => 'Anulare facturi FGO',
    ],
    'fgo_invoicing.bulk_title_storno' => [
        'en' => 'Reverse FGO invoices (Storno)',
        'ro' => 'Stornare facturi FGO',
    ],
    'fgo_invoicing.bulk_title_delete' => [
        'en' => 'Delete FGO invoices',
        'ro' => 'Ștergere facturi FGO',
    ],
    'fgo_invoicing.env_sandbox' => [
        'en' => 'Sandbox',
        'ro' => 'Sandbox',
    ],
    'fgo_invoicing.env_production' => [
        'en' => 'Production',
        'ro' => 'Producție',
    ],
    'fgo_invoicing.bulk_intro' => [
        'en' => 'Selected orders: [count]. Review the pre-check before anything is sent to FGO.',
        'ro' => 'Comenzi selectate: [count]. Verificați pre-verificarea înainte ca ceva să fie trimis către FGO.',
    ],
    'fgo_invoicing.bulk_truncated' => [
        'en' => 'Selected orders: [selected]. One run handles only the first [max]: run the action again for the rest.',
        'ro' => 'Comenzi selectate: [selected]. O rulare le procesează doar pe primele [max]: rulați din nou acțiunea pentru restul.',
    ],
    'fgo_invoicing.bulk_missing' => [
        'en' => 'Left out because they no longer exist: [count] of the selected orders.',
        'ro' => 'Omise pentru că nu mai există: [count] dintre comenzile selectate.',
    ],
    'fgo_invoicing.bulk_nothing_selected' => [
        'en' => 'Select at least one order first.',
        'ro' => 'Selectați mai întâi cel puțin o comandă.',
    ],
    'fgo_invoicing.bulk_nothing_found' => [
        'en' => 'None of the selected orders could be loaded.',
        'ro' => 'Niciuna dintre comenzile selectate nu a putut fi încărcată.',
    ],
    'fgo_invoicing.chip_to_process' => [
        'en' => '[count] will be processed',
        'ro' => 'Vor fi procesate: [count]',
    ],
    'fgo_invoicing.chip_skipped' => [
        'en' => '[count] skipped',
        'ro' => 'Omise: [count]',
    ],
    'fgo_invoicing.chip_warnings' => [
        'en' => '[count] with warnings',
        'ro' => 'Cu avertismente: [count]',
    ],
    'fgo_invoicing.chip_blocked' => [
        'en' => '[count] blocked',
        'ro' => 'Blocate: [count]',
    ],
    'fgo_invoicing.chip_issued' => [
        'en' => '[count] issued',
        'ro' => 'Emise: [count]',
    ],
    'fgo_invoicing.chip_done' => [
        'en' => '[count] done',
        'ro' => 'Finalizate: [count]',
    ],
    'fgo_invoicing.chip_failed' => [
        'en' => '[count] failed',
        'ro' => 'Eșuate: [count]',
    ],
    'fgo_invoicing.chip_remaining' => [
        'en' => '[count] remaining',
        'ro' => 'Rămase: [count]',
    ],
    'fgo_invoicing.snap_document_type' => [
        'en' => 'Document type',
        'ro' => 'Tip document',
    ],
    'fgo_invoicing.snap_series' => [
        'en' => 'Series',
        'ro' => 'Serie',
    ],
    'fgo_invoicing.snap_series_default' => [
        'en' => 'FGO default series',
        'ro' => 'Seria implicită din FGO',
    ],
    'fgo_invoicing.snap_issue_date' => [
        'en' => 'Issue date',
        'ro' => 'Data emiterii',
    ],
    'fgo_invoicing.snap_today' => [
        'en' => '[date] (today)',
        'ro' => '[date] (azi)',
    ],
    'fgo_invoicing.snap_currency' => [
        'en' => 'Currency',
        'ro' => 'Monedă',
    ],
    'fgo_invoicing.snap_currency_primary' => [
        'en' => '[currency] (store primary currency)',
        'ro' => '[currency] (moneda principală a magazinului)',
    ],
    'fgo_invoicing.select_all' => [
        'en' => 'Select all orders that can be processed',
        'ro' => 'Selectează toate comenzile care pot fi procesate',
    ],
    'fgo_invoicing.th_order' => [
        'en' => 'Order',
        'ro' => 'Comandă',
    ],
    'fgo_invoicing.th_customer' => [
        'en' => 'Customer',
        'ro' => 'Client',
    ],
    'fgo_invoicing.th_type' => [
        'en' => 'Type',
        'ro' => 'Tip',
    ],
    'fgo_invoicing.th_total' => [
        'en' => 'Total',
        'ro' => 'Total',
    ],
    'fgo_invoicing.th_precheck' => [
        'en' => 'Pre-check',
        'ro' => 'Pre-verificare',
    ],
    'fgo_invoicing.th_result' => [
        'en' => 'Result',
        'ro' => 'Rezultat',
    ],
    'fgo_invoicing.type_pf' => [
        'en' => 'Individual (persoană fizică)',
        'ro' => 'Persoană fizică',
    ],
    'fgo_invoicing.type_pj' => [
        'en' => 'Company (persoană juridică)',
        'ro' => 'Persoană juridică',
    ],
    'fgo_invoicing.bulk_email_option' => [
        'en' => 'Email the PDF link to each customer after issuing',
        'ro' => 'Trimite linkul PDF fiecărui client după emitere',
    ],
    'fgo_invoicing.bulk_footer_note' => [
        'en' => 'Orders are sent one at a time (FGO accepts about one request per second). Keep this window open until it finishes.',
        'ro' => 'Comenzile sunt trimise pe rând (FGO acceptă aproximativ o cerere pe secundă). Păstrați această fereastră deschisă până la final.',
    ],
    'fgo_invoicing.bulk_keep_open' => [
        'en' => 'Keep this window open. Orders already processed stay processed if you stop.',
        'ro' => 'Păstrați această fereastră deschisă. Comenzile deja procesate rămân procesate dacă opriți.',
    ],
    'fgo_invoicing.btn_stop' => [
        'en' => 'Stop after current order',
        'ro' => 'Oprește după comanda curentă',
    ],
    'fgo_invoicing.btn_back_to_orders' => [
        'en' => 'Back to orders',
        'ro' => 'Înapoi la comenzi',
    ],
    'fgo_invoicing.btn_retry_failed' => [
        'en' => 'Retry failed ([count])',
        'ro' => 'Reîncearcă eșuatele ([count])',
    ],

    // ── Admin: bulk run (page script strings, handed over as JSON) ────────
    'fgo_invoicing.run_verb_issue' => [
        'en' => 'Issue',
        'ro' => 'Emite',
    ],
    'fgo_invoicing.run_verb_retry' => [
        'en' => 'Retry',
        'ro' => 'Reîncearcă',
    ],
    'fgo_invoicing.run_verb_email' => [
        'en' => 'Email',
        'ro' => 'Trimite',
    ],
    'fgo_invoicing.run_verb_cancel' => [
        'en' => 'Cancel',
        'ro' => 'Anulează',
    ],
    'fgo_invoicing.run_verb_storno' => [
        'en' => 'Reverse',
        'ro' => 'Stornează',
    ],
    'fgo_invoicing.run_verb_delete' => [
        'en' => 'Delete',
        'ro' => 'Șterge',
    ],
    'fgo_invoicing.count_invoices_one' => [
        'en' => '1 invoice',
        'ro' => '1 factură',
    ],
    'fgo_invoicing.count_invoices_few' => [
        'en' => '[count] invoices',
        'ro' => '[count] facturi',
    ],
    'fgo_invoicing.count_invoices_many' => [
        'en' => '[count] invoices',
        'ro' => '[count] de facturi',
    ],
    'fgo_invoicing.progress_count' => [
        'en' => '[done] of [total] processed',
        'ro' => '[done] din [total] procesate',
    ],
    'fgo_invoicing.progress_current_issue' => [
        'en' => 'issuing order #[order]',
        'ro' => 'se emite comanda #[order]',
    ],
    'fgo_invoicing.progress_current_email' => [
        'en' => 'emailing order #[order]',
        'ro' => 'se trimite e-mailul pentru comanda #[order]',
    ],
    'fgo_invoicing.progress_current_other' => [
        'en' => 'processing order #[order]',
        'ro' => 'se procesează comanda #[order]',
    ],
    'fgo_invoicing.progress_stopping' => [
        'en' => 'stopping after the current order…',
        'ro' => 'se oprește după comanda curentă…',
    ],
    'fgo_invoicing.progress_stopped' => [
        'en' => 'Stopped: [done] of [total] processed.',
        'ro' => 'Oprit: [done] din [total] procesate.',
    ],
    'fgo_invoicing.progress_done' => [
        'en' => 'Done: [done] of [total] processed.',
        'ro' => 'Gata: [done] din [total] procesate.',
    ],
    'fgo_invoicing.run_state_queued' => [
        'en' => 'Queued',
        'ro' => 'În așteptare',
    ],
    'fgo_invoicing.run_state_running_issue' => [
        'en' => 'Issuing…',
        'ro' => 'Se emite…',
    ],
    'fgo_invoicing.run_state_running' => [
        'en' => 'Working…',
        'ro' => 'Se procesează…',
    ],
    'fgo_invoicing.run_state_issued' => [
        'en' => 'Issued',
        'ro' => 'Emisă',
    ],
    'fgo_invoicing.run_state_done' => [
        'en' => 'Done',
        'ro' => 'Finalizat',
    ],
    'fgo_invoicing.run_state_failed' => [
        'en' => 'Failed',
        'ro' => 'Eșuat',
    ],
    'fgo_invoicing.run_state_skipped' => [
        'en' => 'Skipped',
        'ro' => 'Omisă',
    ],
    'fgo_invoicing.run_state_not_processed' => [
        'en' => 'Not processed',
        'ro' => 'Neprocesată',
    ],
    'fgo_invoicing.run_emailed' => [
        'en' => 'PDF link emailed to the customer',
        'ro' => 'Linkul PDF a fost trimis clientului',
    ],
    'fgo_invoicing.run_email_failed' => [
        'en' => 'Issued, but the e-mail to the customer was not sent',
        'ro' => 'Emisă, dar e-mailul către client nu a fost trimis',
    ],
    'fgo_invoicing.run_transport_error' => [
        'en' => 'No valid answer from the server (network error or expired session). Check the order before retrying.',
        'ro' => 'Niciun răspuns valid de la server (eroare de rețea sau sesiune expirată). Verificați comanda înainte de reîncercare.',
    ],
    'fgo_invoicing.run_leave_warning' => [
        'en' => 'FGO invoices are still being processed. If you leave, the run stops after the current order.',
        'ro' => 'Facturile FGO sunt încă în procesare. Dacă părăsiți pagina, rularea se oprește după comanda curentă.',
    ],

    // ── Admin: bulk pre-check verdicts ────────────────────────────────────
    'fgo_invoicing.verdict_ready' => [
        'en' => 'Ready',
        'ro' => 'Pregătită',
    ],
    'fgo_invoicing.verdict_retry' => [
        'en' => 'Retry',
        'ro' => 'Reîncercare',
    ],
    'fgo_invoicing.verdict_warn' => [
        'en' => 'Check first',
        'ro' => 'Verificați',
    ],
    'fgo_invoicing.verdict_skip' => [
        'en' => 'Skipped',
        'ro' => 'Omisă',
    ],
    'fgo_invoicing.verdict_block' => [
        'en' => 'Blocked',
        'ro' => 'Blocată',
    ],

    // ── Admin: bulk pre-check reasons (fgo_invoicing.pc_<PrecheckReason code>) ───
    'fgo_invoicing.pc_already_invoiced' => [
        'en' => 'Already invoiced · [invoice]',
        'ro' => 'Deja facturată · [invoice]',
    ],
    'fgo_invoicing.pc_last_error' => [
        'en' => 'Last attempt failed: [error]',
        'ro' => 'Ultima încercare a eșuat: [error]',
    ],
    'fgo_invoicing.pc_pending' => [
        'en' => 'A previous attempt is still marked as in progress and may still be running: wait a minute before retrying.',
        'ro' => 'O încercare anterioară apare încă în curs și poate rula încă: așteptați un minut înainte de reîncercare.',
    ],
    'fgo_invoicing.pc_previously_canceled' => [
        'en' => 'Its invoice [invoice] was canceled: issuing creates a new invoice.',
        'ro' => 'Factura [invoice] a fost anulată: emiterea creează o factură nouă.',
    ],
    'fgo_invoicing.pc_previously_reversed' => [
        'en' => 'Its invoice [invoice] was reversed (storno): issuing creates a new invoice.',
        'ro' => 'Factura [invoice] a fost stornată: emiterea creează o factură nouă.',
    ],
    'fgo_invoicing.pc_previously_deleted' => [
        'en' => 'Its invoice [invoice] was deleted in FGO: issuing creates a new invoice.',
        'ro' => 'Factura [invoice] a fost ștearsă din FGO: emiterea creează o factură nouă.',
    ],
    'fgo_invoicing.pc_order_status' => [
        'en' => 'Order status: [status]. Check the order before invoicing it.',
        'ro' => 'Starea comenzii: [status]. Verificați comanda înainte de facturare.',
    ],
    'fgo_invoicing.pc_pj_without_cif' => [
        'en' => 'Company (PJ) without CIF. FGO may reject it.',
        'ro' => 'Persoană juridică (PJ) fără CIF. FGO o poate respinge.',
    ],
    'fgo_invoicing.pc_pj_without_cif_required' => [
        'en' => 'Company (PJ) without CIF, which the settings require: add the CIF to the order, then retry.',
        'ro' => 'Persoană juridică (PJ) fără CIF, obligatoriu conform setărilor: adăugați CIF-ul în comandă, apoi reîncercați.',
    ],
    'fgo_invoicing.pc_cif_invalid' => [
        'en' => 'The CIF [cif] fails the Romanian check digit.',
        'ro' => 'CIF-ul [cif] nu trece verificarea cifrei de control.',
    ],
    'fgo_invoicing.pc_pf_without_cnp_required' => [
        'en' => 'Individual (PF) without CNP, which the settings require: add the CNP to the order, then retry.',
        'ro' => 'Persoană fizică (PF) fără CNP, obligatoriu conform setărilor: adăugați CNP-ul în comandă, apoi reîncercați.',
    ],
    'fgo_invoicing.pc_cnp_required_no_field' => [
        'en' => 'The settings require a CNP, but the store has no CNP field: it will be issued without one.',
        'ro' => 'Setările cer CNP, dar magazinul nu are un câmp pentru CNP: factura va fi emisă fără el.',
    ],
    'fgo_invoicing.pc_cnp_invalid' => [
        'en' => 'The CNP fails the check digit.',
        'ro' => 'CNP-ul nu trece verificarea cifrei de control.',
    ],
    'fgo_invoicing.pc_zero_total' => [
        'en' => 'The order total is 0.',
        'ro' => 'Totalul comenzii este 0.',
    ],
    'fgo_invoicing.pc_mapping_failed' => [
        'en' => 'The order cannot be turned into an FGO invoice: [error]',
        'ro' => 'Comanda nu poate fi transformată în factură FGO: [error]',
    ],
    'fgo_invoicing.pc_not_failed' => [
        'en' => 'No failed attempt to retry.',
        'ro' => 'Nicio încercare eșuată de reluat.',
    ],
    'fgo_invoicing.pc_not_invoiced' => [
        'en' => 'Not invoiced yet.',
        'ro' => 'Încă nefacturată.',
    ],
    'fgo_invoicing.pc_no_email' => [
        'en' => 'The customer has no valid e-mail address.',
        'ro' => 'Clientul nu are o adresă de e-mail validă.',
    ],
    'fgo_invoicing.pc_no_pdf_link' => [
        'en' => 'FGO returned no PDF link for this invoice.',
        'ro' => 'FGO nu a returnat un link PDF pentru această factură.',
    ],
    'fgo_invoicing.pc_already_canceled' => [
        'en' => 'The invoice [invoice] is already canceled.',
        'ro' => 'Factura [invoice] este deja anulată.',
    ],
    'fgo_invoicing.pc_already_reversed' => [
        'en' => 'The invoice [invoice] is already reversed (storno).',
        'ro' => 'Factura [invoice] este deja stornată.',
    ],
    'fgo_invoicing.pc_already_deleted' => [
        'en' => 'The invoice [invoice] is already deleted.',
        'ro' => 'Factura [invoice] este deja ștearsă.',
    ],
    'fgo_invoicing.pc_no_series_number' => [
        'en' => 'The invoice has no series or number stored, so FGO cannot identify it.',
        'ro' => 'Factura nu are serie sau număr salvate, deci FGO nu o poate identifica.',
    ],
    'fgo_invoicing.pc_order_not_found' => [
        'en' => 'The order no longer exists.',
        'ro' => 'Comanda nu mai există.',
    ],
    'fgo_invoicing.pc_unknown_action' => [
        'en' => 'Unknown FGO bulk action.',
        'ro' => 'Acțiune FGO în masă necunoscută.',
    ],

    // ── Admin: PDF ZIP download ───────────────────────────────────────────
    'fgo_invoicing.zip_unavailable' => [
        'en' => 'The PHP zip extension (ZipArchive) is not available on this server, so no ZIP can be built.',
        'ro' => 'Extensia PHP zip (ZipArchive) nu este disponibilă pe acest server, deci nu se poate crea arhiva ZIP.',
    ],
    'fgo_invoicing.zip_nothing' => [
        'en' => 'None of the selected orders has an issued FGO invoice with a PDF.',
        'ro' => 'Niciuna dintre comenzile selectate nu are o factură FGO emisă cu PDF.',
    ],
    'fgo_invoicing.zip_failed' => [
        'en' => 'The ZIP archive could not be created',
        'ro' => 'Arhiva ZIP nu a putut fi creată',
    ],
    'fgo_invoicing.zip_all_failed' => [
        'en' => 'No PDF could be downloaded from FGO. Failed orders: [orders]',
        'ro' => 'Niciun PDF nu a putut fi descărcat din FGO. Comenzi eșuate: [orders]',
    ],
    'fgo_invoicing.zip_some_failed' => [
        'en' => 'Some PDFs could not be downloaded and are missing from the ZIP (see missing-pdfs.txt inside it): [orders]',
        'ro' => 'Unele PDF-uri nu au putut fi descărcate și lipsesc din ZIP (vedeți missing-pdfs.txt din arhivă): [orders]',
    ],
    'fgo_invoicing.zip_missing_note' => [
        'en' => 'These invoice PDFs could not be downloaded from FGO:',
        'ro' => 'Aceste PDF-uri de facturi nu au putut fi descărcate din FGO:',
    ],

    // ── Admin: invoice page — issue attempts (?:fgo_diagnostic_logs) ─────
    'fgo_invoicing.diagnostics_title' => [
        'en' => 'Issue attempts',
        'ro' => 'Încercări de emitere',
    ],
    'fgo_invoicing.diagnostics_intro' => [
        'en' => 'Every attempt to issue this invoice, newest first. The CNP is stored masked; the full value stays on the order.',
        'ro' => 'Fiecare încercare de emitere a acestei facturi, cele mai recente primele. CNP-ul este stocat mascat; valoarea completă rămâne pe comandă.',
    ],
    'fgo_invoicing.diag_date' => [
        'en' => 'Date',
        'ro' => 'Data',
    ],
    'fgo_invoicing.diag_response_code' => [
        'en' => 'FGO response',
        'ro' => 'Răspuns FGO',
    ],
    'fgo_invoicing.diag_cnp_length' => [
        'en' => 'CNP length',
        'ro' => 'Lungime CNP',
    ],
    'fgo_invoicing.diag_cnp_checksum' => [
        'en' => 'CNP checksum',
        'ro' => 'Cifra de control CNP',
    ],
    'fgo_invoicing.diag_error_message' => [
        'en' => 'Message',
        'ro' => 'Mesaj',
    ],
    'fgo_invoicing.diag_checksum_ok' => [
        'en' => 'valid',
        'ro' => 'validă',
    ],
    'fgo_invoicing.diag_checksum_bad' => [
        'en' => 'invalid',
        'ro' => 'invalidă',
    ],
    'fgo_invoicing.diag_no_cnp' => [
        'en' => 'no CNP sent',
        'ro' => 'fără CNP trimis',
    ],
    'fgo_invoicing.diag_none' => [
        'en' => 'No issue attempts recorded yet.',
        'ro' => 'Nicio încercare de emitere înregistrată încă.',
    ],
    'fgo_invoicing.diag_payload' => [
        'en' => 'Request sent (CNP masked)',
        'ro' => 'Cerere trimisă (CNP mascat)',
    ],
];
