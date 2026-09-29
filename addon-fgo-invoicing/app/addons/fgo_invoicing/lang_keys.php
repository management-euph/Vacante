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
];
