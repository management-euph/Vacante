<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Services\InvoiceCanceler;
use Tygh\Addons\FgoInvoicing\Tests\Support\FakeFgoApi;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryInvoiceRepository;

/**
 * Cancel / storno / delete act on an issued invoice only, and record the new
 * state only while the row is still `issued`: of two requests racing on the
 * same invoice, the second is told, not written.
 */
#[CoversClass(InvoiceCanceler::class)]
final class InvoiceCancelerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function actions(): iterable
    {
        yield 'cancel' => ['cancel', 'cancelInvoice', Constants::STATUS_CANCELED];
        yield 'storno' => ['storno', 'stornoInvoice', Constants::STATUS_REVERSED];
        yield 'delete' => ['delete', 'deleteInvoice', Constants::STATUS_DELETED];
    }

    #[DataProvider('actions')]
    public function testAnIssuedInvoiceIsActedOnAndRecorded(string $action, string $call, string $state): void
    {
        $repo = (new InMemoryInvoiceRepository())->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002']);
        $api = new FakeFgoApi();

        self::assertSame(['status' => 'ok'], (new InvoiceCanceler($api, $repo))->{$action}(5));
        self::assertSame([[$call, 'F', '0002']], $api->calls);
        self::assertSame($state, $repo->rows[5]['status']);
    }

    #[DataProvider('actions')]
    public function testAnInvoiceThatIsNotIssuedIsNotSentToFgo(string $action): void
    {
        $repo = (new InMemoryInvoiceRepository())->put(5, ['status' => 'canceled', 'invoice_series' => 'F', 'invoice_number' => '0002']);
        $api = new FakeFgoApi();

        $result = (new InvoiceCanceler($api, $repo))->{$action}(5);

        self::assertSame('invalid', $result['status']);
        self::assertStringContainsString('is not issued (status: canceled)', $result['error'] ?? '');
        self::assertSame([], $api->calls);
    }

    /**
     * Two admins storno and cancel the same invoice at once: both calls reach
     * FGO, the first write wins, the second reports the race instead of
     * overwriting the state.
     */
    public function testALostRaceIsReportedAndTheOtherStateKept(): void
    {
        $repo = new class () extends InMemoryInvoiceRepository {
            #[\Override]
            public function markCanceled(int $orderId): bool
            {
                $this->rows[$orderId]['status'] = Constants::STATUS_REVERSED; // the storno landed first

                return parent::markCanceled($orderId);
            }
        };
        $repo->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002']);

        $result = (new InvoiceCanceler(new FakeFgoApi(), $repo))->cancel(5);

        self::assertSame('conflict', $result['status']);
        self::assertStringContainsString('FGO accepted the cancellation (Anulare) of invoice F 0002 (order 5)', $result['error'] ?? '');
        self::assertStringContainsString('(now: reversed)', $result['error'] ?? '');
        self::assertSame(Constants::STATUS_REVERSED, $repo->rows[5]['status']);
    }

    public function testAnFgoRefusalChangesNothing(): void
    {
        $repo = (new InMemoryInvoiceRepository())->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002']);
        $api = new FakeFgoApi();
        $api->invoiceActionError = 'Factura nu poate fi anulata';

        self::assertSame(['status' => 'failed', 'error' => 'Factura nu poate fi anulata'], (new InvoiceCanceler($api, $repo))->cancel(5));
        self::assertSame(Constants::STATUS_ISSUED, $repo->rows[5]['status']);
    }

    public function testAwbNeedsAnInvoiceNumberAndAValue(): void
    {
        $repo = (new InMemoryInvoiceRepository())->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002']);
        $canceler = new InvoiceCanceler(new FakeFgoApi(), $repo);

        self::assertSame('invalid', $canceler->attachAwb(5, '')['status']);
        self::assertSame('invalid', $canceler->attachAwb(6, 'AWB1')['status']);
        self::assertSame(['status' => 'ok'], $canceler->attachAwb(5, 'AWB1'));
        self::assertSame('AWB1', $repo->rows[5]['awb']);
    }
}
