<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services\Bulk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkAction;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkSelection;

/**
 * The pre-check page opens only a selection its admin made, kept in the
 * session under a random token: the whole selection (not just a batch), the
 * newest ten, nothing a URL could smuggle in.
 */
#[CoversClass(BulkSelection::class)]
final class BulkSelectionTest extends TestCase
{
    public function testTokensAreRandom128BitHex(): void
    {
        $a = BulkSelection::newToken();
        $b = BulkSelection::newToken();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a);
        self::assertNotSame($a, $b);
        self::assertTrue(BulkSelection::isToken($a));
        foreach (['', '1,2,3', strtoupper($a), $a . '0', '../../etc'] as $bad) {
            self::assertFalse(BulkSelection::isToken($bad), $bad);
        }
    }

    public function testASelectionComesBackAsStored(): void
    {
        $token = BulkSelection::newToken();
        $ids = range(1, 600);

        $bucket = (new BulkSelection(BulkAction::Storno, $ids, true, 1000))->storeIn(null, $token);
        $found = BulkSelection::findIn($bucket, $token);

        self::assertNotNull($found);
        self::assertSame(BulkAction::Storno, $found->action);
        self::assertSame($ids, $found->orderIds, 'all of it, not just the first batch');
        self::assertTrue($found->sendEmail);
        self::assertNull(BulkSelection::findIn($bucket, BulkSelection::newToken()));
        self::assertNull(BulkSelection::findIn($bucket, ''));
        self::assertNull(BulkSelection::findIn('garbage', $token));
    }

    public function testOnlyTheNewestTenAreKept(): void
    {
        $bucket = [];
        $tokens = [];
        for ($i = 1; $i <= 12; $i++) {
            $tokens[$i] = BulkSelection::newToken();
            $bucket = (new BulkSelection(BulkAction::Issue, [$i], null, 1000 + $i))->storeIn($bucket, $tokens[$i]);
        }

        self::assertCount(BulkSelection::KEEP, $bucket);
        self::assertNull(BulkSelection::findIn($bucket, $tokens[1]));
        self::assertNull(BulkSelection::findIn($bucket, $tokens[2]));
        self::assertSame([12], BulkSelection::findIn($bucket, $tokens[12])?->orderIds);
        self::assertSame([3], BulkSelection::findIn($bucket, $tokens[3])?->orderIds);
        self::assertSame($tokens[12], array_key_first($bucket));
    }

    /**
     * Whatever else sits in the bucket (a garbled entry, a key that is not a
     * token) is dropped on the next write, and never read as a selection.
     */
    public function testGarbageInTheBucketIsDropped(): void
    {
        $bad = BulkSelection::newToken();
        $bucket = [
            'not-a-token' => ['action' => 'delete', 'ids' => [1]],
            $bad => ['action' => 'explode', 'ids' => [1]],
            BulkSelection::newToken() => ['action' => 'delete', 'ids' => ['x', -1]],
            BulkSelection::newToken() => 'string',
        ];

        self::assertNull(BulkSelection::findIn($bucket, $bad));
        $token = BulkSelection::newToken();
        self::assertSame([$token], array_keys((new BulkSelection(BulkAction::Email, [5]))->storeIn($bucket, $token)));
    }

    public function testAnEntryIsPlainData(): void
    {
        self::assertSame(
            ['action' => 'cancel', 'ids' => [4, 2], 'send_email' => null, 'created_at' => 7],
            (new BulkSelection(BulkAction::Cancel, [4, 2], null, 7))->toEntry(),
        );
    }
}
