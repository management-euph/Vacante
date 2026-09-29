<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Functions;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Tests\Support\LogStub;

/**
 * REGRESSION: the invoice e-mail was sent through fn_send_mail(), which
 * CS-Cart 4.x does not have, so it was never sent. It now goes through
 * Tygh::$app['mailer']->send() with the add-on's FILE template, which only
 * resolves in the admin mail area ('A'), in the ORDER's language.
 *
 * Every case runs in its own process: \Tygh is a global class.
 */
#[CoversNothing]
final class InvoiceEmailFunctionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function payload(array $overrides = []): array
    {
        return array_replace([
            'to' => 'ana@example.ro',
            'order_id' => 7,
            'invoice_series' => 'F',
            'invoice_number' => '0002',
            'pdf_link' => 'https://api.fgo.ro/p/2',
            'payment_link' => '',
            'customer_name' => 'Ana Pop',
            'lang_code' => 'ro',
            'company_id' => 3,
        ], $overrides);
    }

    private static function boot(bool $withMailer = true): ?\FgoTestMailer
    {
        require_once dirname(__DIR__, 2) . '/Fixtures/tygh_app_stub.php';
        require_once dirname(__DIR__, 3) . '/functions/email.php';
        $mailer = $withMailer ? new \FgoTestMailer() : null;
        \Tygh::$app = new \ArrayObject($withMailer ? ['mailer' => $mailer] : []);

        return $mailer;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testItSendsTheFileTemplateInTheAdminMailAreaInTheOrdersLanguage(): void
    {
        $mailer = self::boot();
        self::assertNotNull($mailer);

        self::assertTrue(fn_fgo_invoicing_send_invoice_email(self::payload()));

        self::assertCount(1, $mailer->sent);
        $sent = $mailer->sent[0];
        self::assertSame('A', $sent['area'], 'design/backend/mail/templates is the admin area');
        self::assertSame('ro', $sent['lang']);
        self::assertSame('addons/fgo_invoicing/invoice_issued.tpl', $sent['message']['tpl']);
        self::assertSame('ana@example.ro', $sent['message']['to']);
        self::assertSame('company_orders_department', $sent['message']['from']);
        self::assertSame(3, $sent['message']['company_id']);
        self::assertSame([
            'order_id' => 7,
            'invoice_series' => 'F',
            'invoice_number' => '0002',
            'pdf_link' => 'https://api.fgo.ro/p/2',
            'payment_link' => '',
            'customer_name' => 'Ana Pop',
        ], $sent['message']['data']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutAnOrderLanguageTheStoreLanguageIsUsed(): void
    {
        define('CART_LANGUAGE', 'en');
        $mailer = self::boot();
        self::assertNotNull($mailer);

        fn_fgo_invoicing_send_invoice_email(self::payload(['lang_code' => '', 'company_id' => 0]));

        self::assertSame('en', $mailer->sent[0]['lang']);
        self::assertSame(1, $mailer->sent[0]['message']['company_id'], 'the historical fallback');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAMailerThatSaysNoOrThrowsIsFalseNeverAnException(): void
    {
        $mailer = self::boot();
        self::assertNotNull($mailer);

        $mailer->outcome = false;
        self::assertFalse(fn_fgo_invoicing_send_invoice_email(self::payload()));

        $mailer->outcome = new \RuntimeException('SMTP down');
        self::assertFalse(fn_fgo_invoicing_send_invoice_email(self::payload()));
        self::assertContains('[warn] email-send-failed', LogStub::messages());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoMailerOrNoAddressIsFalse(): void
    {
        self::boot(withMailer: false);
        self::assertFalse(fn_fgo_invoicing_send_invoice_email(self::payload()));

        $mailer = self::boot();
        self::assertNotNull($mailer);
        self::assertFalse(fn_fgo_invoicing_send_invoice_email(self::payload(['to' => ''])));
        self::assertSame([], $mailer->sent);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOutsideCsCartItIsFalse(): void
    {
        require_once dirname(__DIR__, 3) . '/functions/email.php';
        self::assertFalse(class_exists('Tygh', false), 'precondition');

        self::assertFalse(fn_fgo_invoicing_send_invoice_email(self::payload()));
    }

    /**
     * CS-Cart's mailer sets the template variable $company_name to the
     * STORE's name; greeting with it would greet the customer as the shop.
     */
    public function testTheGreetingUsesCustomerNameNotCompanyName(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 6) . '/design/backend/mail/templates/addons/fgo_invoicing/invoice_issued.tpl');

        self::assertStringContainsString('{$customer_name}', $tpl);
        self::assertStringNotContainsString('{$company_name}', $tpl);
        self::assertFileExists(dirname(__DIR__, 6) . '/design/backend/mail/templates/addons/fgo_invoicing/invoice_issued_subj.tpl');
    }
}
