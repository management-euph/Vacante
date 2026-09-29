<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services\Bulk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkAction;

#[CoversClass(BulkAction::class)]
final class BulkActionTest extends TestCase
{
    public function testMenuModesMapToActionsAndBack(): void
    {
        foreach (BulkAction::cases() as $action) {
            self::assertSame($action, BulkAction::fromMenuMode($action->menuMode()));
            self::assertSame('m_' . $action->value, $action->menuMode());
        }
    }

    public function testUnknownModesAreNoAction(): void
    {
        self::assertNull(BulkAction::fromMenuMode('issue'), 'only the m_ context-menu modes');
        self::assertNull(BulkAction::fromMenuMode('m_download_pdfs'), 'the ZIP download has no pre-check');
        self::assertNull(BulkAction::fromMenuMode('m_'));
        self::assertNull(BulkAction::fromMenuMode(''));
    }

    public function testIssueAndRetryIssueTheOthersActOnAnInvoice(): void
    {
        self::assertTrue(BulkAction::Issue->issues());
        self::assertTrue(BulkAction::Retry->issues());
        self::assertFalse(BulkAction::Email->issues());

        foreach ([BulkAction::Cancel, BulkAction::Storno, BulkAction::Delete] as $action) {
            self::assertTrue($action->actsOnIssuedInvoice());
            self::assertFalse($action->issues());
        }
        self::assertFalse(BulkAction::Email->actsOnIssuedInvoice());
        self::assertFalse(BulkAction::Issue->actsOnIssuedInvoice());
    }

    /**
     * "Retry failed" re-runs failed ISSUES through Issue: an order that
     * failed without a `failed` row (no answer, a run-time block) is ready
     * there, while Retry would skip it as "no failed attempt".
     */
    public function testRetryFailedReissuesAsIssueAndRepeatsTheRest(): void
    {
        self::assertSame(BulkAction::Issue, BulkAction::Issue->retryAction());
        self::assertSame(BulkAction::Issue, BulkAction::Retry->retryAction());
        self::assertSame(BulkAction::Cancel, BulkAction::Cancel->retryAction());
        self::assertSame(BulkAction::Email, BulkAction::Email->retryAction());
        self::assertSame('m_issue', BulkAction::Retry->retryAction()->menuMode());
    }

    /** The orders list's largest page size: a whole page fits in one run. */
    public function testTheBatchIsCappedAtTheLargestListPage(): void
    {
        self::assertSame(250, BulkAction::MAX_BATCH);
    }
}
