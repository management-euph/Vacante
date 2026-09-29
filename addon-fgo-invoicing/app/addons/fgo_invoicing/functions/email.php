<?php

declare(strict_types=1);

use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Send the customer-facing "Invoice issued" e-mail with the signed FGO PDF
 * link, through CS-Cart's mailer (Tygh::$app['mailer']).
 *
 * WHY not fn_send_mail(): that is the CS-Cart 3.x API and does not exist in
 * 4.x. This function used to start with `if (!function_exists('fn_send_mail'))
 * return false;`, so on 4.20 the invoice e-mail was never sent, silently.
 *
 * WHY area 'A': the message is built from a FILE template ('tpl'), which
 * CS-Cart's FileTemplateMessageBuilder renders through Core::displayMail(),
 * which resolves the path under the AREA's mail templates. This add-on ships
 * them in design/backend/mail/templates/addons/fgo_invoicing, i.e. the admin
 * area; with 'C' the lookup misses and send() returns false. The subject is
 * derived from the sibling invoice_issued_subj.tpl by the same builder.
 *
 * The language is the order's (the customer's), not the admin's who pressed
 * the button; CART_LANGUAGE only when the order carries none.
 *
 * Never throws and never fatals: it runs right after FGO issued the invoice,
 * and nothing about the e-mail may look like the invoice failed.
 *
 * @param array<string, mixed> $payload InvoiceMailer's payload: to, order_id,
 *                                      invoice_series, invoice_number,
 *                                      pdf_link, payment_link, customer_name,
 *                                      lang_code, company_id
 */
function fn_fgo_invoicing_send_invoice_email(array $payload): bool
{
    try {
        $to = trim(TypeCoerce::toString($payload['to'] ?? ''));
        if ($to === '' || !class_exists('Tygh')) {
            return false;
        }
        $app = Tygh::$app;
        $mailer = $app instanceof \ArrayAccess && $app->offsetExists('mailer') ? $app->offsetGet('mailer') : null;
        if (!is_object($mailer) || !method_exists($mailer, 'send')) {
            return false;
        }

        $companyId = TypeCoerce::toInt($payload['company_id'] ?? 0);
        if ($companyId <= 0) {
            $companyId = TypeCoerce::toInt(Registry::get('runtime.company_id'));
        }
        if ($companyId <= 0) {
            $companyId = 1;
        }

        $langCode = trim(TypeCoerce::toString($payload['lang_code'] ?? ''));
        if ($langCode === '') {
            $langCode = defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en';
        }

        $sent = $mailer->send([
            'to' => $to,
            'from' => 'company_orders_department',
            'data' => [
                'order_id' => TypeCoerce::toInt($payload['order_id'] ?? 0),
                'invoice_series' => TypeCoerce::toString($payload['invoice_series'] ?? ''),
                'invoice_number' => TypeCoerce::toString($payload['invoice_number'] ?? ''),
                'pdf_link' => TypeCoerce::toString($payload['pdf_link'] ?? ''),
                'payment_link' => TypeCoerce::toString($payload['payment_link'] ?? ''),
                // Not `company_name`: the mailer sets that variable to the
                // STORE's name, which the greeting would then address.
                'customer_name' => TypeCoerce::toString($payload['customer_name'] ?? ''),
            ],
            'tpl' => 'addons/fgo_invoicing/invoice_issued.tpl',
            'company_id' => $companyId,
        ], 'A', $langCode);

        return $sent === true;
    } catch (\Throwable $e) {
        if (function_exists('fn_log_event')) {
            fn_log_event('fgo_invoicing', 'runtime', [
                'message' => '[warn] email-send-failed',
                'context' => [
                    'order_id' => TypeCoerce::toInt($payload['order_id'] ?? 0),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
            ]);
        }

        return false;
    }
}
