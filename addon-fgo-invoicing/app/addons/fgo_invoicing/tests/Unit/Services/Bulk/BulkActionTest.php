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

    public function testRetryFailedRetriesIssuesAndRepeatsTheRest(): void
    {
        self::assertSame(BulkAction::Retry, BulkAction::Issue->retryAction());
        self::assertSame(BulkAction::Retry, BulkAction::Retry->retryAction());
        self::assertSame(BulkAction::Cancel, BulkAction::Cancel->retryAction());
        self::assertSame(BulkAction::Email, BulkAction::Email->retryAction());
    }

    public function testTheBatchIsCappedAtOneHundred(): void
    {
        self::assertSame(100, BulkAction::MAX_BATCH);
    }
}
