<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Log;

use Netopia\CsCart\Log\CompositeLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;

#[CoversClass(CompositeLogger::class)]
final class CompositeLoggerTest extends TestCase
{
    public function testFansOutASingleCallToEveryInnerLogger(): void
    {
        $a = new SpyLogger();
        $b = new SpyLogger();

        $composite = new CompositeLogger([$a, $b]);
        $composite->warning('payload', ['order_id' => 42]);

        self::assertCount(1, $a->calls);
        self::assertSame(LogLevel::WARNING, $a->calls[0]['level']);
        self::assertSame('payload', $a->calls[0]['message']);
        self::assertSame(['order_id' => 42], $a->calls[0]['context']);

        self::assertCount(1, $b->calls);
        self::assertSame('payload', $b->calls[0]['message']);
    }

    public function testOneFailingInnerDoesNotSuppressTheOthers(): void
    {
        $thrower = new class extends AbstractLogger {
            #[\Override]
            public function log($level, $message, array $context = []): void
            {
                throw new RuntimeException('inner blew up');
            }
        };
        $survivor = new SpyLogger();

        $composite = new CompositeLogger([$thrower, $survivor]);
        $composite->error('still-must-deliver');

        self::assertCount(1, $survivor->calls, 'survivor must receive the call after thrower failed');
    }

    public function testEmptyInnerListIsANoOp(): void
    {
        $composite = new CompositeLogger([]);
        $composite->info('falls into the void');

        self::expectNotToPerformAssertions();
    }
}
