<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services\Bulk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Services\BillingMapper;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkAction;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkPrecheck;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckReason;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckRow;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckSummary;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckVerdict;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;

/**
 * The pre-check is what an admin reads before real fiscal documents are
 * issued, cancelled or deleted in bulk, and what bulk_run asks again right
 * before acting. Every rule is pinned here, per action.
 */
#[CoversClass(BulkPrecheck::class)]
#[CoversClass(PrecheckRow::class)]
#[CoversClass(PrecheckReason::class)]
#[CoversClass(PrecheckSummary::class)]
#[CoversClass(PrecheckVerdict::class)]
final class BulkPrecheckTest extends TestCase
{
    private const VALID_CIF = '14399840';
    private const INVALID_CIF = '12345678';
    private const VALID_CNP = '1960101123456';
    private const INVALID_CNP = '1960101123450';

    protected function setUp(): void
    {
        ConfigProvider::seed(['invoice_type' => 'Factura', 'invoice_series' => 'F']);
    }

    protected function tearDown(): void
    {
        ConfigProvider::reset();
    }

    /**
     * @param array<string, string> $statusNames
     */
    private static function precheck(
        bool $vatRequired = false,
        bool $cnpRequired = false,
        bool $cifSource = false,
        bool $cnpSource = false,
        array $statusNames = [],
        bool $seriesConfigured = true,
    ): BulkPrecheck {
        return new BulkPrecheck(new BillingMapper('RON'), $vatRequired, $cnpRequired, $cifSource, $cnpSource, $statusNames, $seriesConfigured);
    }

    /**
     * A paid Romanian individual, resolved (fgo_billing_* present, blank).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function order(array $overrides = []): array
    {
        return array_replace([
            'order_id' => 5,
            'status' => 'P',
            'total' => 249.9,
            'email' => 'ion@example.ro',
            'b_firstname' => 'Ion',
            'b_lastname' => 'Popescu',
            'b_country' => 'RO',
            'company' => '',
            'fgo_billing_company' => '',
            'fgo_billing_cui' => '',
            'fgo_billing_reg' => '',
            'fgo_billing_cnp' => '',
            'products' => [['product' => 'Sejur', 'amount' => 1, 'subtotal' => 249.9, 'tax_value' => 0]],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function company(array $overrides = []): array
    {
        return self::order(array_replace(['company' => 'SC ACME SRL', 'fgo_billing_cui' => 'RO' . self::VALID_CIF], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoice(string $status, string $series = 'F', string $number = '0002', string $pdf = 'https://api.fgo.ro/pdf/1', string $error = '', int $age = 0): array
    {
        return [
            'order_id' => 5,
            'status' => $status,
            'invoice_series' => $series,
            'invoice_number' => $number,
            'pdf_link' => $pdf,
            'last_error' => $error,
            'updated_age' => (string) $age,
        ];
    }

    /** A pending row whose request died: older than any FGO call takes. */
    private static function stalePending(): array
    {
        return self::invoice('pending', '', '', '', '', Constants::PENDING_STALE_SECONDS + 1);
    }

    /**
     * @return list<string>
     */
    private static function codes(PrecheckRow $row): array
    {
        return array_map(static fn (PrecheckReason $r): string => $r->code, $row->reasons);
    }

    private static function reason(PrecheckRow $row, string $code): PrecheckReason
    {
        foreach ($row->reasons as $reason) {
            if ($reason->code === $code) {
                return $reason;
            }
        }
        self::fail("no {$code} reason; got " . implode(', ', self::codes($row)));
    }

    // ── Issue ────────────────────────────────────────────────────────────

    public function testANewOrderIsReady(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(), null);

        self::assertSame(PrecheckVerdict::Ready, $row->verdict);
        self::assertTrue($row->selected);
        self::assertTrue($row->actionable());
        self::assertFalse($row->hasWarnings());
        self::assertSame([], $row->reasons);
        self::assertSame(5, $row->orderId);
        self::assertSame('Ion Popescu', $row->customerName, 'the name BillingMapper would send');
        self::assertSame('PF', $row->clientType);
        self::assertSame(249.9, $row->total);
        self::assertSame('P', $row->orderStatus);
        self::assertSame('', $row->invoiceStatus);
    }

    /**
     * FGO refuses an invoice without a series ("Campul 'Serie' este
     * obligatoriu"), so with none set nothing is sent: a new order and a
     * failed one are blocked, and say where to set it. An invoiced order is
     * still only skipped.
     */
    public function testWithoutAnInvoiceSeriesNothingIsSent(): void
    {
        $precheck = self::precheck(seriesConfigured: false);

        $new = $precheck->check(BulkAction::Issue, self::order(), null);
        self::assertSame(PrecheckVerdict::Block, $new->verdict);
        self::assertFalse($new->selected);
        self::assertFalse($new->actionable());
        self::assertSame(['no_invoice_series'], self::codes($new));
        self::assertSame(PrecheckReason::LEVEL_BLOCK, $new->reasons[0]->level);

        $failed = $precheck->check(BulkAction::Issue, self::order(), self::invoice('failed', '', '', '', "Campul 'Serie' este obligatoriu"));
        self::assertSame(PrecheckVerdict::Block, $failed->verdict);
        self::assertSame(['last_error', 'no_invoice_series'], self::codes($failed));

        $issued = $precheck->check(BulkAction::Issue, self::order(), self::invoice('issued'));
        self::assertSame(PrecheckVerdict::Skip, $issued->verdict);
        self::assertSame(['already_invoiced'], self::codes($issued));
    }

    public function testAnIssuedOrderIsSkippedWithItsInvoiceNumber(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(), self::invoice('issued'));

        self::assertSame(PrecheckVerdict::Skip, $row->verdict);
        self::assertFalse($row->selected);
        self::assertFalse($row->actionable());
        self::assertSame(['already_invoiced'], self::codes($row));
        self::assertSame(['[invoice]' => 'F 0002'], $row->reasons[0]->params);
        self::assertSame('F 0002', $row->invoiceLabel());
        self::assertSame('https://api.fgo.ro/pdf/1', $row->pdfLink);
    }

    public function testAFailedOrderIsRetriedAndShowsTheLastError(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(), self::invoice('failed', '', '', '', "HTTP 504\n from FGO"));

        self::assertSame(PrecheckVerdict::Retry, $row->verdict);
        self::assertTrue($row->selected);
        self::assertSame(['last_error'], self::codes($row));
        self::assertSame(PrecheckReason::LEVEL_INFO, $row->reasons[0]->level);
        self::assertSame(['[error]' => 'HTTP 504 from FGO'], $row->reasons[0]->params, 'whitespace collapsed');
        self::assertFalse($row->hasWarnings());
    }

    public function testALongLastErrorIsShortened(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(), self::invoice('failed', '', '', '', str_repeat('é', 400)));

        $shown = self::reason($row, 'last_error')->params['[error]'];
        self::assertSame(160, mb_strlen($shown));
        self::assertStringEndsWith('…', $shown);
    }

    public function testAFailedOrderWithoutAStoredErrorSaysSo(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(), self::invoice('failed', '', '', '', ''));

        self::assertSame(['[error]' => '—'], self::reason($row, 'last_error')->params);
    }

    /**
     * A pending row is what a request still talking to FGO looks like: it is
     * not offered while it is younger than the stale threshold, and the
     * verdict says so.
     */
    public function testAnOrderBeingIssuedRightNowIsSkipped(): void
    {
        foreach ([0, Constants::PENDING_STALE_SECONDS] as $age) {
            $row = self::precheck()->check(BulkAction::Issue, self::order(), self::invoice('pending', '', '', '', '', $age));

            self::assertSame(PrecheckVerdict::Skip, $row->verdict, (string) $age);
            self::assertFalse($row->actionable());
            self::assertSame(['in_progress'], self::codes($row));
        }

        $unknown = self::invoice('pending', '', '', '');
        unset($unknown['updated_age']);
        self::assertSame(['in_progress'], self::codes(self::precheck()->check(BulkAction::Issue, self::order(), $unknown)), 'an unknown age counts as fresh');
    }

    /**
     * Older than any FGO call: its request died. Offered, ticked, with a
     * warning; the issuer's atomic claim still decides.
     */
    public function testAStalePendingOrderIsRetriedWithAWarning(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(), self::stalePending());

        self::assertSame(PrecheckVerdict::Retry, $row->verdict);
        self::assertTrue($row->selected);
        self::assertSame(['stale_pending'], self::codes($row));
        self::assertSame(PrecheckReason::LEVEL_WARN, $row->reasons[0]->level);
        self::assertTrue($row->hasWarnings());
    }

    /**
     * A failed re-issue still replaces the cancelled invoice (the row keeps
     * its series and number), and says so.
     */
    public function testAFailedReissueNamesTheInvoiceItReplaces(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(), self::invoice('failed', 'F', '0002', '', 'HTTP 504'));

        self::assertSame(PrecheckVerdict::Retry, $row->verdict);
        self::assertSame(['last_error', 'reissue_of'], self::codes($row));
        self::assertSame(['[invoice]' => 'F 0002'], self::reason($row, 'reissue_of')->params);
    }

    public function testAnIssuedOrderWithoutANumberSaysDash(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(), self::invoice('issued', '', ''));

        self::assertSame(['[invoice]' => '—'], self::reason($row, 'already_invoiced')->params);
    }

    /**
     * A failed invoice on an unfinished order is not a one-click retry: it
     * is a warn row, unticked, like a fresh one on that order.
     */
    #[DataProvider('incompleteStatuses')]
    public function testAFailedInvoiceOnAnUnfinishedOrderIsAWarnRow(string $status): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(['status' => $status]), self::invoice('failed', '', '', '', 'boom'));

        self::assertSame(PrecheckVerdict::Warn, $row->verdict);
        self::assertTrue($row->actionable());
        self::assertFalse($row->selected);
        self::assertSame(['last_error', 'order_status'], self::codes($row));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formerInvoiceStates(): iterable
    {
        yield 'canceled' => ['canceled'];
        yield 'reversed' => ['reversed'];
        yield 'deleted' => ['deleted'];
    }

    #[DataProvider('formerInvoiceStates')]
    public function testReissuingAfterACancelledInvoiceIsAllowedButNotByDefault(string $state): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(), self::invoice($state));

        self::assertSame(PrecheckVerdict::Warn, $row->verdict);
        self::assertTrue($row->actionable());
        self::assertFalse($row->selected, 'a second fiscal document is never issued by default');
        self::assertSame(['previously_' . $state], self::codes($row));
        self::assertSame(['[invoice]' => 'F 0002'], $row->reasons[0]->params);
    }

    public function testAFormerInvoiceWithoutANumberIsStillExplained(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(), self::invoice('canceled', '', ''));

        self::assertSame(['[invoice]' => '—'], self::reason($row, 'previously_canceled')->params);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function incompleteStatuses(): iterable
    {
        foreach (BulkPrecheck::INCOMPLETE_STATUSES as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('incompleteStatuses')]
    public function testAnUnfinishedOrderWarnsAndIsNotSelected(string $status): void
    {
        $row = self::precheck(statusNames: ['N' => 'Incomplete'])->check(BulkAction::Issue, self::order(['status' => $status]), null);

        self::assertSame(PrecheckVerdict::Warn, $row->verdict);
        self::assertTrue($row->actionable());
        self::assertFalse($row->selected);
        self::assertSame(['order_status'], self::codes($row));
        self::assertSame(
            ['[status]' => $status === 'N' ? 'Incomplete' : $status],
            $row->reasons[0]->params,
            'the status name when known, else its code',
        );
    }

    public function testAStatusCodeIsMatchedCaseInsensitively(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(['status' => 'n']), null);

        self::assertSame(['order_status'], self::codes($row));
        self::assertSame('N', $row->orderStatus);
    }

    public function testAPaidOrCompleteOrderHasNoStatusWarning(): void
    {
        foreach (['P', 'C', 'O'] as $status) {
            $row = self::precheck()->check(BulkAction::Issue, self::order(['status' => $status]), null);
            self::assertNotContains('order_status', self::codes($row), $status);
        }
    }

    public function testACompanyWithAValidCifIsReady(): void
    {
        $row = self::precheck(vatRequired: true, cifSource: true)->check(BulkAction::Issue, self::company(), null);

        self::assertSame(PrecheckVerdict::Ready, $row->verdict);
        self::assertSame('PJ', $row->clientType);
        self::assertSame('SC ACME SRL', $row->customerName);
    }

    public function testACompanyWithoutCifWarnsWhenTheSettingIsOff(): void
    {
        $row = self::precheck(cifSource: true)->check(BulkAction::Issue, self::company(['fgo_billing_cui' => '']), null);

        self::assertSame(PrecheckVerdict::Warn, $row->verdict);
        self::assertTrue($row->selected, 'a warning alone does not untick the row');
        self::assertSame(['pj_without_cif'], self::codes($row));
    }

    public function testACompanyWithoutCifIsBlockedWhenRequiredAndTheStoreHasACifField(): void
    {
        $row = self::precheck(vatRequired: true, cifSource: true)->check(BulkAction::Issue, self::company(['fgo_billing_cui' => '']), null);

        self::assertSame(PrecheckVerdict::Block, $row->verdict);
        self::assertFalse($row->actionable());
        self::assertFalse($row->selected);
        self::assertSame(['pj_without_cif_required'], self::codes($row));
        self::assertSame(PrecheckReason::LEVEL_BLOCK, $row->reasons[0]->level);
    }

    /**
     * Mirrors InvoiceIssuer: required but no field anybody could have typed
     * a CIF into issues anyway (and logs), so the pre-check only warns.
     */
    public function testACompanyWithoutCifOnlyWarnsWhenRequiredButTheStoreHasNoCifField(): void
    {
        $row = self::precheck(vatRequired: true, cifSource: false)->check(BulkAction::Issue, self::company(['fgo_billing_cui' => '']), null);

        self::assertSame(PrecheckVerdict::Warn, $row->verdict);
        self::assertSame(['pj_without_cif'], self::codes($row));
    }

    public function testARomanianCifFailingTheChecksumWarns(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::company(['fgo_billing_cui' => 'RO' . self::INVALID_CIF]), null);

        self::assertSame(PrecheckVerdict::Warn, $row->verdict);
        self::assertSame(['cif_invalid'], self::codes($row));
        self::assertSame(['[cif]' => self::INVALID_CIF], $row->reasons[0]->params, 'as it will be sent, prefix stripped');
    }

    public function testAForeignCompanyIdIsNotHeldToTheRomanianChecksum(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::company(['b_country' => 'DE', 'fgo_billing_cui' => 'DE123456789']), null);

        self::assertSame(PrecheckVerdict::Ready, $row->verdict);
    }

    public function testAnIndividualWithoutCnpIsBlockedWhenRequiredAndTheStoreHasACnpField(): void
    {
        $row = self::precheck(cnpRequired: true, cnpSource: true)->check(BulkAction::Issue, self::order(), null);

        self::assertSame(PrecheckVerdict::Block, $row->verdict);
        self::assertSame(['pf_without_cnp_required'], self::codes($row));
    }

    public function testAnIndividualWithoutCnpWarnsWhenRequiredButTheStoreHasNoCnpField(): void
    {
        $row = self::precheck(cnpRequired: true, cnpSource: false)->check(BulkAction::Issue, self::order(), null);

        self::assertSame(PrecheckVerdict::Warn, $row->verdict);
        self::assertSame(['cnp_required_no_field'], self::codes($row));
    }

    public function testAnIndividualWithoutCnpIsReadyWhenNotRequired(): void
    {
        $row = self::precheck(cnpSource: true)->check(BulkAction::Issue, self::order(), null);

        self::assertSame(PrecheckVerdict::Ready, $row->verdict);
    }

    public function testAForeignIndividualNeedsNoCnp(): void
    {
        $row = self::precheck(cnpRequired: true, cnpSource: true)->check(BulkAction::Issue, self::order(['b_country' => 'BG']), null);

        self::assertSame(PrecheckVerdict::Ready, $row->verdict);
    }

    public function testAValidCnpIsReady(): void
    {
        $row = self::precheck(cnpRequired: true, cnpSource: true)->check(BulkAction::Issue, self::order(['fgo_billing_cnp' => self::VALID_CNP]), null);

        self::assertSame(PrecheckVerdict::Ready, $row->verdict);
    }

    public function testACnpFailingTheChecksumWarnsWithoutRepeatingIt(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(['fgo_billing_cnp' => self::INVALID_CNP]), null);

        self::assertSame(PrecheckVerdict::Warn, $row->verdict);
        self::assertSame(['cnp_invalid'], self::codes($row));
        self::assertSame([], $row->reasons[0]->params, 'a CNP is personal data');
    }

    public function testAZeroTotalWarns(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(['total' => '0.00']), null);

        self::assertSame(PrecheckVerdict::Warn, $row->verdict);
        self::assertSame(['zero_total'], self::codes($row));
        self::assertTrue($row->selected);
    }

    public function testAnOrderThatCannotBeMappedIsBlockedWithTheReason(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(['b_country' => 'ROU']), null);

        self::assertSame(PrecheckVerdict::Block, $row->verdict);
        self::assertSame(['mapping_failed'], self::codes($row));
        self::assertStringContainsString('2-letter', self::reason($row, 'mapping_failed')->params['[error]']);
        self::assertSame('', $row->clientType);
        self::assertSame('Ion Popescu', $row->customerName, 'still recognisable');
    }

    public function testABlockWinsOverWarnings(): void
    {
        $row = self::precheck(vatRequired: true, cifSource: true, statusNames: ['N' => 'Incomplete'])
            ->check(BulkAction::Issue, self::company(['fgo_billing_cui' => '', 'status' => 'N', 'total' => 0]), null);

        self::assertSame(PrecheckVerdict::Block, $row->verdict);
        self::assertSame(['order_status', 'pj_without_cif_required', 'zero_total'], self::codes($row));
        self::assertFalse($row->hasWarnings(), 'a blocked row is not counted as "with warnings"');
    }

    public function testARetryRowKeepsItsVerdictWhenItAlsoWarns(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(['total' => 0]), self::invoice('failed', '', '', '', 'boom'));

        self::assertSame(PrecheckVerdict::Retry, $row->verdict);
        self::assertSame(['last_error', 'zero_total'], self::codes($row));
        self::assertTrue($row->hasWarnings());
    }

    // ── Retry ────────────────────────────────────────────────────────────

    public function testRetryActsOnFailedAndPendingOnly(): void
    {
        $check = self::precheck();

        self::assertSame(PrecheckVerdict::Retry, $check->check(BulkAction::Retry, self::order(), self::invoice('failed', '', '', '', 'x'))->verdict);
        self::assertSame(PrecheckVerdict::Retry, $check->check(BulkAction::Retry, self::order(), self::stalePending())->verdict);
        self::assertSame(['in_progress'], self::codes($check->check(BulkAction::Retry, self::order(), self::invoice('pending', '', '', ''))));

        $none = $check->check(BulkAction::Retry, self::order(), null);
        self::assertSame(PrecheckVerdict::Skip, $none->verdict);
        self::assertSame(['not_failed'], self::codes($none));

        $canceled = $check->check(BulkAction::Retry, self::order(), self::invoice('canceled'));
        self::assertSame(PrecheckVerdict::Skip, $canceled->verdict);
        self::assertSame(['not_failed'], self::codes($canceled));

        $issued = $check->check(BulkAction::Retry, self::order(), self::invoice('issued'));
        self::assertSame(PrecheckVerdict::Skip, $issued->verdict);
        self::assertSame(['already_invoiced'], self::codes($issued));
    }

    public function testRetryAppliesTheIssueRules(): void
    {
        $row = self::precheck(vatRequired: true, cifSource: true)
            ->check(BulkAction::Retry, self::company(['fgo_billing_cui' => '']), self::invoice('failed', '', '', '', 'no CIF'));

        self::assertSame(PrecheckVerdict::Block, $row->verdict);
        self::assertSame(['last_error', 'pj_without_cif_required'], self::codes($row));
    }

    // ── Email ────────────────────────────────────────────────────────────

    public function testEmailIsReadyForAnIssuedInvoiceWithAPdfAndAnAddress(): void
    {
        $row = self::precheck()->check(BulkAction::Email, self::order(), self::invoice('issued'));

        self::assertSame(PrecheckVerdict::Ready, $row->verdict);
        self::assertTrue($row->selected);
        self::assertSame('ion@example.ro', $row->email);
    }

    /**
     * An invoice e-mailed in the last 24 hours is not sent again by default:
     * a warn row, unticked, saying when.
     */
    public function testAnInvoiceEmailedRecentlyIsNotSentAgainByDefault(): void
    {
        $invoice = self::invoice('issued') + ['emailed_at' => '2026-09-29 09:15:00', 'emailed_age' => '3600'];

        $row = self::precheck()->check(BulkAction::Email, self::order(), $invoice);

        self::assertSame(PrecheckVerdict::Warn, $row->verdict);
        self::assertTrue($row->actionable());
        self::assertFalse($row->selected);
        self::assertSame(['recently_emailed'], self::codes($row));
        self::assertSame(['[time]' => '2026-09-29 09:15:00'], $row->reasons[0]->params);
    }

    public function testAnInvoiceEmailedMoreThanADayAgoIsReady(): void
    {
        $old = self::invoice('issued') + ['emailed_at' => '2026-09-27 09:15:00', 'emailed_age' => (string) BulkPrecheck::EMAIL_RESEND_WINDOW_SECONDS];
        $never = self::invoice('issued') + ['emailed_at' => null, 'emailed_age' => null];

        self::assertSame(PrecheckVerdict::Ready, self::precheck()->check(BulkAction::Email, self::order(), $old)->verdict);
        self::assertSame(PrecheckVerdict::Ready, self::precheck()->check(BulkAction::Email, self::order(), $never)->verdict);
    }

    public function testEmailIsBlockedWithoutAValidAddress(): void
    {
        foreach (['', 'not-an-address'] as $email) {
            // BillingParty rejects a malformed address, which must not matter here.
            $row = self::precheck()->check(BulkAction::Email, self::order(['email' => $email]), self::invoice('issued'));
            self::assertSame(PrecheckVerdict::Block, $row->verdict, $email);
            self::assertSame(['no_email'], self::codes($row));
        }
    }

    public function testEmailIsBlockedWithoutAPdfLink(): void
    {
        $row = self::precheck()->check(BulkAction::Email, self::order(), self::invoice('issued', 'F', '1', ''));

        self::assertSame(PrecheckVerdict::Block, $row->verdict);
        self::assertSame(['no_pdf_link'], self::codes($row));
    }

    public function testEmailSkipsWhatIsNotInvoiced(): void
    {
        foreach ([null, self::invoice('failed'), self::invoice('pending')] as $invoice) {
            $row = self::precheck()->check(BulkAction::Email, self::order(), $invoice);
            self::assertSame(PrecheckVerdict::Skip, $row->verdict);
            self::assertSame(['not_invoiced'], self::codes($row));
        }

        $canceled = self::precheck()->check(BulkAction::Email, self::order(), self::invoice('canceled'));
        self::assertSame(['already_canceled'], self::codes($canceled));
    }

    public function testEmailDoesNotCareWhetherTheOrderCouldBeInvoicedAgain(): void
    {
        $row = self::precheck()->check(BulkAction::Email, self::order(['b_country' => 'ROU']), self::invoice('issued'));

        self::assertSame(PrecheckVerdict::Ready, $row->verdict);
        self::assertSame('', $row->clientType);
    }

    // ── Cancel / Storno / Delete ─────────────────────────────────────────

    /**
     * @return iterable<string, array{BulkAction}>
     */
    public static function invoiceActions(): iterable
    {
        yield 'cancel' => [BulkAction::Cancel];
        yield 'storno' => [BulkAction::Storno];
        yield 'delete' => [BulkAction::Delete];
    }

    #[DataProvider('invoiceActions')]
    public function testAnIssuedInvoiceWithSeriesAndNumberIsReady(BulkAction $action): void
    {
        $row = self::precheck()->check($action, self::order(), self::invoice('issued'));

        self::assertSame(PrecheckVerdict::Ready, $row->verdict);
        self::assertTrue($row->selected);
    }

    #[DataProvider('invoiceActions')]
    public function testAnIssuedInvoiceWithoutANumberIsBlocked(BulkAction $action): void
    {
        $row = self::precheck()->check($action, self::order(), self::invoice('issued', 'F', ''));

        self::assertSame(PrecheckVerdict::Block, $row->verdict);
        self::assertSame(['no_series_number'], self::codes($row));
    }

    #[DataProvider('invoiceActions')]
    public function testAnAlreadyCancelledInvoiceIsSkipped(BulkAction $action): void
    {
        foreach (['canceled', 'reversed', 'deleted'] as $state) {
            $row = self::precheck()->check($action, self::order(), self::invoice($state));
            self::assertSame(PrecheckVerdict::Skip, $row->verdict);
            self::assertSame(['already_' . $state], self::codes($row));
            self::assertSame(['[invoice]' => 'F 0002'], $row->reasons[0]->params);
        }
    }

    #[DataProvider('invoiceActions')]
    public function testNothingToActOnWithoutAnInvoice(BulkAction $action): void
    {
        foreach ([null, self::invoice('failed', '', '', ''), self::invoice('pending', '', '', '')] as $invoice) {
            $row = self::precheck()->check($action, self::order(), $invoice);
            self::assertSame(PrecheckVerdict::Skip, $row->verdict);
            self::assertSame(['not_invoiced'], self::codes($row));
        }
    }

    // ── Rows, reasons, summary ───────────────────────────────────────────

    public function testTheCustomerNameFallsBackWhenTheOrderCannotBeMapped(): void
    {
        $check = self::precheck();

        $company = $check->check(BulkAction::Email, self::order(['b_country' => 'ROU', 'b_firstname' => '', 'b_lastname' => '', 'company' => 'Firma']), null);
        self::assertSame('Firma', $company->customerName);

        $nobody = $check->check(BulkAction::Email, self::order(['b_country' => 'ROU', 'b_firstname' => '', 'b_lastname' => '']), null);
        self::assertSame('ion@example.ro', $nobody->customerName);
    }

    public function testRowToArrayCarriesWhatThePageNeeds(): void
    {
        $row = self::precheck()->check(BulkAction::Issue, self::order(), self::stalePending());

        self::assertSame([
            'order_id' => 5,
            'customer_name' => 'Ion Popescu',
            'client_type' => 'PF',
            'total' => 249.9,
            'order_status' => 'P',
            'verdict' => 'retry',
            'actionable' => true,
            'selected' => true,
            'has_warnings' => true,
            'reasons' => [['code' => 'stale_pending', 'level' => 'warn', 'params' => []]],
            'invoice_status' => 'pending',
            'invoice_label' => '',
            'pdf_link' => '',
            'email' => 'ion@example.ro',
        ], $row->toArray());
    }

    public function testTheSummaryCountsTheChips(): void
    {
        $check = self::precheck(vatRequired: true, cifSource: true);
        $rows = [
            $check->check(BulkAction::Issue, self::order(), null),                                     // ready
            $check->check(BulkAction::Issue, self::order(['total' => 0]), null),                       // warn, selected
            $check->check(BulkAction::Issue, self::order(['status' => 'N']), null),                    // warn, unticked
            $check->check(BulkAction::Issue, self::order(), self::invoice('issued')),                  // skip
            $check->check(BulkAction::Issue, self::company(['fgo_billing_cui' => '']), null),          // block
        ];

        $summary = PrecheckSummary::of($rows);

        self::assertSame(5, $summary->total);
        self::assertSame(2, $summary->toProcess);
        self::assertSame(3, $summary->actionable);
        self::assertSame(1, $summary->skipped);
        self::assertSame(2, $summary->withWarnings);
        self::assertSame(1, $summary->blocked);
        self::assertSame(
            ['total' => 5, 'to_process' => 2, 'actionable' => 3, 'skipped' => 1, 'with_warnings' => 2, 'blocked' => 1],
            $summary->toArray(),
        );
    }

    public function testVerdictsKnowWhetherTheyCanBeSent(): void
    {
        self::assertTrue(PrecheckVerdict::Ready->actionable());
        self::assertTrue(PrecheckVerdict::Retry->actionable());
        self::assertTrue(PrecheckVerdict::Warn->actionable());
        self::assertFalse(PrecheckVerdict::Skip->actionable());
        self::assertFalse(PrecheckVerdict::Block->actionable());
        self::assertSame('fgo_invoicing.verdict_warn', PrecheckVerdict::Warn->langKey());
    }

    public function testReasonsAreCodesWithALanguageKey(): void
    {
        $reason = new PrecheckReason('already_invoiced', PrecheckReason::LEVEL_INFO, ['[invoice]' => 'F 1']);

        self::assertSame('fgo_invoicing.pc_already_invoiced', $reason->langKey());
        self::assertSame(['code' => 'already_invoiced', 'level' => 'info', 'params' => ['[invoice]' => 'F 1']], $reason->toArray());
    }

    public function testAnUnknownReasonCodeIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrecheckReason('no_such_code', PrecheckReason::LEVEL_INFO);
    }

    public function testAnUnknownReasonLevelIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrecheckReason('in_progress', 'loud');
    }
}
