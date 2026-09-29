<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Log;

use Netopia\CsCart\Log\FileLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

#[CoversClass(FileLogger::class)]
final class FileLoggerTest extends TestCase
{
    private string $tmpDir;

    #[\Override]
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/netopia-filelogger-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Best-effort cleanup. Don't fail the test if a file lingers.
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testWritesOneJsonLinePerCallToDateStampedFile(): void
    {
        $logger = new FileLogger($this->tmpDir, 'netopia_payments');

        $logger->warning('hello', ['order_id' => 42, 'reason' => 'silent-success']);

        $expectedPath = $this->tmpDir . '/netopia_payments-' . date('Y-m-d') . '.log';
        self::assertFileExists($expectedPath);

        $contents = (string) file_get_contents($expectedPath);
        self::assertStringEndsWith("\n", $contents, 'each line must end with newline so jq/grep treat lines independently');

        $line = json_decode(trim($contents), true);
        self::assertIsArray($line);
        self::assertSame(LogLevel::WARNING, $line['level']);
        self::assertSame('hello', $line['message']);
        self::assertSame(['order_id' => 42, 'reason' => 'silent-success'], $line['context']);
        self::assertArrayHasKey('ts', $line);
        // ISO 8601 with timezone — the date('c') format.
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $line['ts']);
    }

    public function testAppendsSuccessiveLinesToTheSameFile(): void
    {
        $logger = new FileLogger($this->tmpDir);

        $logger->info('first');
        $logger->error('second');

        $contents = (string) file_get_contents($this->tmpDir . '/netopia_payments-' . date('Y-m-d') . '.log');
        $lines = array_values(array_filter(explode("\n", $contents)));
        self::assertCount(2, $lines, 'second log must append, not overwrite');
        self::assertStringContainsString('first', $lines[0]);
        self::assertStringContainsString('second', $lines[1]);
    }

    public function testNonWritableDirIsCachedAtConstructAndLogsAreSilentlyDropped(): void
    {
        // A path that doesn't exist — is_writable returns false.
        $logger = new FileLogger($this->tmpDir . '/does-not-exist');

        // Must not throw. Must not create files. Must not warn.
        $logger->warning('this should disappear');

        self::assertEmpty(glob($this->tmpDir . '/does-not-exist/*') ?: []);
    }

    public function testEmptyLogDirIsRejectedAtConstructAndLogsAreNoOp(): void
    {
        $logger = new FileLogger('');
        $logger->error('drop me'); // must not throw, must not file_put_contents

        self::expectNotToPerformAssertions();
    }

    public function testOversizeContextIsTruncatedRatherThanThrown(): void
    {
        $logger = new FileLogger($this->tmpDir);

        // 16 KB blob — over the 8 KB context cap.
        $oversize = str_repeat('A', 16 * 1024);
        $logger->warning('oversize', ['blob' => $oversize]);

        $contents = (string) file_get_contents($this->tmpDir . '/netopia_payments-' . date('Y-m-d') . '.log');
        self::assertStringContainsString('[truncated]', $contents);
        // Truncated line is still a valid line — i.e. terminated with a newline.
        self::assertStringEndsWith("\n", $contents);
    }
}
