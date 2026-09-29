<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\InvoiceMailer;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryInvoiceRepository;

/**
 * The invoice e-mail, as InvoiceIssuer (after issuing) and the bulk "Email
 * invoice to customer" action send it. The transport is recorded, not run.
 */
#[CoversClass(InvoiceMailer::class)]
final class InvoiceMailerTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private InMemoryInvoiceRepository $repo;

    /** @var array<int, array<string, mixed>> */
    private array $orders = [];

    protected function setUp(): void
    {
        $this->sent = [];
        $this->repo = new InMemoryInvoiceRepository();
        $this->orders = [
            7 => [
                'order_id' => 7,
                'email' => 'ana@example.ro',
                'b_firstname' => 'Ana',
                'b_lastname' => 'Pop',
                'lang_code' => 'RO',
                'company_id' => '2',
                'company' => '',
                'fgo_billing_company' => '',
            ],
        ];
    }

    private function mailer(bool|\Throwable $outcome = true): InvoiceMailer
    {
        return new InvoiceMailer(
            $this->repo,
            function (array $payload) use ($outcome): bool {
                $this->sent[] = $payload;
                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                return $outcome;
            },
            fn (int $id): ?array => $this->orders[$id] ?? null,
        );
    }

    public function testSendBuildsThePayloadTheMailFunctionExpects(): void
    {
        $result = $this->mailer()->send($this->orders[7], 'F', '0002', ' https://api.fgo.ro/p/2 ', 'https://pay.fgo.ro/2');

        self::assertSame(['status' => InvoiceMailer::STATUS_SENT], $result);
        self::assertSame([[
            'to' => 'ana@example.ro',
            'order_id' => 7,
            'invoice_series' => 'F',
            'invoice_number' => '0002',
            'pdf_link' => 'https://api.fgo.ro/p/2',
            'payment_link' => 'https://pay.fgo.ro/2',
            'customer_name' => 'Ana Pop',
            'lang_code' => 'ro',
            'company_id' => 2,
        ]], $this->sent);
    }

    /**
     * The greeting addresses the company on a company order. It travels as
     * customer_name: CS-Cart's mailer overwrites `company_name` with the
     * store's own name.
     */
    public function testTheGreetingNamesTheCompanyForACompanyOrder(): void
    {
        $order = $this->orders[7];
        $order['company'] = 'SC ACME SRL';

        $this->mailer()->send($order, 'F', '1', 'https://api.fgo.ro/p/1');

        self::assertSame('SC ACME SRL', $this->sent[0]['customer_name']);
        self::assertArrayNotHasKey('company_name', $this->sent[0]);
    }

    public function testNoAddressOrNoPdfIsSkippedWithoutSending(): void
    {
        $order = $this->orders[7];

        $noPdf = $this->mailer()->send($order, 'F', '1', '');
        self::assertSame(InvoiceMailer::STATUS_SKIPPED, $noPdf['status']);
        self::assertStringContainsString('PDF', $noPdf['error'] ?? '');

        $order['email'] = 'nope';
        $noEmail = $this->mailer()->send($order, 'F', '1', 'https://api.fgo.ro/p/1');
        self::assertSame(InvoiceMailer::STATUS_SKIPPED, $noEmail['status']);
        self::assertStringContainsString('e-mail', $noEmail['error'] ?? '');

        self::assertSame([], $this->sent);
    }

    public function testAMailerThatSaysNoIsAFailure(): void
    {
        $result = $this->mailer(false)->send($this->orders[7], 'F', '1', 'https://api.fgo.ro/p/1');

        self::assertSame(InvoiceMailer::STATUS_FAILED, $result['status']);
        self::assertStringContainsString('order 7', $result['error'] ?? '');
    }

    public function testAMailerThatThrowsIsAFailureNotAnException(): void
    {
        $result = $this->mailer(new \RuntimeException('SMTP down'))->send($this->orders[7], 'F', '1', 'https://api.fgo.ro/p/1');

        self::assertSame(['status' => InvoiceMailer::STATUS_FAILED, 'error' => '[RuntimeException] SMTP down'], $result);
    }

    public function testSendForOrderUsesTheStoredInvoice(): void
    {
        $this->repo->put(7, [
            'status' => 'issued',
            'invoice_series' => 'F',
            'invoice_number' => '0002',
            'pdf_link' => 'https://api.fgo.ro/p/2',
            'payment_link' => '',
        ]);

        self::assertSame(['status' => InvoiceMailer::STATUS_SENT], $this->mailer()->sendForOrder(7));
        self::assertSame('0002', $this->sent[0]['invoice_number']);
        self::assertSame('https://api.fgo.ro/p/2', $this->sent[0]['pdf_link']);
    }

    /**
     * The bulk "Email" pre-check holds back a second copy within the day:
     * only a sent e-mail is stamped, and a stamp that cannot be written does
     * not turn a sent e-mail into a failure.
     */
    public function testASentEmailIsStampedOnTheInvoiceRow(): void
    {
        $this->repo->put(7, ['status' => 'issued', 'pdf_link' => 'https://api.fgo.ro/p/2']);

        $this->mailer(false)->sendForOrder(7);
        self::assertNull($this->repo->rows[7]['emailed_at'], 'not sent, not stamped');

        $this->mailer()->sendForOrder(7);
        self::assertNotNull($this->repo->rows[7]['emailed_at']);

        $this->repo = new class () extends InMemoryInvoiceRepository {
            #[\Override]
            public function markEmailed(int $orderId): bool
            {
                throw new \RuntimeException('Unknown column emailed_at');
            }
        };
        self::assertSame(['status' => InvoiceMailer::STATUS_SENT], $this->mailer()->send($this->orders[7], 'F', '1', 'https://api.fgo.ro/p/1'));
    }

    public function testSendForOrderSkipsAnOrderWithoutAnIssuedInvoice(): void
    {
        self::assertSame(InvoiceMailer::STATUS_SKIPPED, $this->mailer()->sendForOrder(7)['status']);

        $this->repo->put(7, ['status' => 'canceled', 'pdf_link' => 'https://api.fgo.ro/p/2']);
        self::assertSame(InvoiceMailer::STATUS_SKIPPED, $this->mailer()->sendForOrder(7)['status']);

        self::assertSame([], $this->sent);
    }

    public function testSendForOrderFailsWhenTheOrderIsGone(): void
    {
        $this->repo->put(8, ['status' => 'issued', 'pdf_link' => 'https://api.fgo.ro/p/8']);

        $result = $this->mailer()->sendForOrder(8);

        self::assertSame(InvoiceMailer::STATUS_FAILED, $result['status']);
        self::assertStringContainsString('not found', $result['error'] ?? '');
    }

    /**
     * Outside CS-Cart (the unit bootstrap loads no functions/ file) the
     * production transport reports "not sent" rather than failing hard.
     */
    public function testTheProductionSenderIsInertWithoutTheMailFunction(): void
    {
        self::assertFalse(function_exists('fn_fgo_invoicing_send_invoice_email'), 'precondition');

        $send = InvoiceMailer::productionSender();

        self::assertFalse($send([
            'to' => 'a@b.ro',
            'order_id' => 1,
            'invoice_series' => 'F',
            'invoice_number' => '1',
            'pdf_link' => 'https://api.fgo.ro/p/1',
            'payment_link' => '',
            'customer_name' => '',
            'lang_code' => '',
            'company_id' => 0,
        ]));
    }
}
