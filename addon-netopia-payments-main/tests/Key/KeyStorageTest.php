<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Key;

use Netopia\CsCart\Exception\KeyStorageException;
use Netopia\CsCart\Key\KeyStorage;
use Netopia\Payment2\Enum\PaymentMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeyStorage::class)]
final class KeyStorageTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/netopia_test_' . bin2hex(random_bytes(6));
        mkdir($this->baseDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->baseDir);
    }

    public function testSecureDirWritesHtaccessAndIndex(): void
    {
        $storage = new KeyStorage($this->baseDir);
        $dir = $storage->dirFor(7);

        $storage->secureDir($dir);

        self::assertFileExists($dir . '.htaccess');
        self::assertFileExists($dir . 'index.html');
        self::assertStringContainsString('Deny from all', (string) file_get_contents($dir . '.htaccess'));
    }

    public function testReadFileAppliesBasenameProtection(): void
    {
        $storage = new KeyStorage($this->baseDir);
        $dir = $storage->dirFor(7);
        $storage->secureDir($dir);
        file_put_contents($dir . 'good.pem', 'CONTENTS');

        // A file planted *outside* the payment dir must not be reachable even
        // if a traversal sequence ends with a name the caller knows exists.
        file_put_contents($this->baseDir . '/secret.pem', 'SENSITIVE');

        self::assertSame('', $storage->readFile($dir, '../secret.pem'));
        self::assertSame('CONTENTS', $storage->readFile($dir, 'good.pem'));
    }

    public function testLoadFallsBackToTextareaContent(): void
    {
        $storage = new KeyStorage($this->baseDir);

        $result = $storage->load(
            ['sandbox_public_key' => 'PEM-FALLBACK'],
            'public_key',
            7,
            PaymentMode::Sandbox,
        );

        self::assertSame('PEM-FALLBACK', $result);
    }

    public function testDeleteOfMissingFileReturnsTrue(): void
    {
        $storage = new KeyStorage($this->baseDir);
        $dir = $storage->dirFor(7);

        self::assertTrue($storage->delete($dir, 'missing.pem'));
    }

    public function testDeleteRemovesExistingFile(): void
    {
        $storage = new KeyStorage($this->baseDir);
        $dir = $storage->dirFor(7);
        $storage->secureDir($dir);
        $path = $dir . 'gone.pem';
        file_put_contents($path, 'x');

        self::assertTrue($storage->delete($dir, 'gone.pem'));
        self::assertFileDoesNotExist($path);
    }

    public function testStoreUploadRejectsDisallowedExtensions(): void
    {
        $storage = new KeyStorage($this->baseDir);
        $dir = $storage->dirFor(7);
        $storage->secureDir($dir);

        $this->expectException(KeyStorageException::class);
        $storage->storeUpload(
            ['name' => 'key.php', 'tmp_name' => '/tmp/ignored', 'size' => 10, 'error' => UPLOAD_ERR_OK],
            $dir,
        );
    }

    public function testStoreUploadRejectsOversizedFiles(): void
    {
        $storage = new KeyStorage($this->baseDir);
        $dir = $storage->dirFor(7);
        $storage->secureDir($dir);

        $this->expectException(KeyStorageException::class);
        $storage->storeUpload(
            [
                'name' => 'huge.pem',
                'tmp_name' => '/tmp/ignored',
                'size' => KeyStorage::MAX_KEY_FILE_SIZE + 1,
                'error' => UPLOAD_ERR_OK,
            ],
            $dir,
        );
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
