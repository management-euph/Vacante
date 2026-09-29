<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services\Pdf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\Pdf\InvoicePdfZipper;

/**
 * The "Download PDFs (ZIP)" archive, built for real with ZipArchive in a
 * temporary directory. Entry names come from FGO data and must never become
 * a path ("../", "/") inside the archive, nor collide.
 */
#[CoversClass(InvoicePdfZipper::class)]
final class InvoicePdfZipperTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fgo-zip-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * @return iterable<string, array{int, string, string, string}>
     */
    public static function names(): iterable
    {
        yield 'series and number' => [5, 'F', '0002', 'F-0002.pdf'];
        yield 'path characters' => [5, '../../etc', 'x/y', 'etc-x_y.pdf'];
        yield 'spaces and dots' => [5, 'FCT 2026', '12.3', 'FCT_2026-12_3.pdf'];
        yield 'romanian diacritics' => [5, 'ÎNȘT', '1', 'INST-1.pdf'];
        yield 'series only' => [5, 'F', '', 'F.pdf'];
        yield 'number only' => [5, '', '0007', '0007.pdf'];
        yield 'nothing usable' => [42, '///', '..', 'order-42.pdf'];
        yield 'empty' => [9, '', '', 'order-9.pdf'];
    }

    #[DataProvider('names')]
    public function testEntryNamesAreSanitised(int $orderId, string $series, string $number, string $expected): void
    {
        self::assertSame($expected, InvoicePdfZipper::entryName($orderId, $series, $number));
    }

    public function testAVeryLongNameIsCut(): void
    {
        $name = InvoicePdfZipper::entryName(1, str_repeat('A', 300), '1');

        self::assertLessThanOrEqual(104, strlen($name));
        self::assertStringEndsWith('.pdf', $name);
    }

    #[RequiresPhpExtension('zip')]
    public function testTheArchiveHoldsEveryPdfUnderAUniqueName(): void
    {
        $path = $this->dir . '/out.zip';
        $zipper = new InvoicePdfZipper($path, $this->dir);

        self::assertSame('F-0002.pdf', $zipper->addPdf(1, 'F', '0002', '%PDF one'));
        self::assertSame('F-0002-2.pdf', $zipper->addPdf(2, 'F/', '0002', '%PDF two'), 'sanitised into the same name');
        self::assertSame('f-0002-3.pdf', $zipper->addPdf(3, 'f', '0002', '%PDF three'), 'case-insensitive clash');
        self::assertSame('order-4.pdf', $zipper->addPdf(4, '', '', '%PDF four'));
        self::assertSame('missing_pdfs.txt', $zipper->addText('missing pdfs.txt', "#9: HTTP 404\r\n"), 'text names are sanitised too');
        self::assertSame(5, $zipper->close());
        self::assertSame(5, $zipper->close(), 'closing twice is harmless');

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        sort($names);
        self::assertSame(['F-0002-2.pdf', 'F-0002.pdf', 'f-0002-3.pdf', 'missing_pdfs.txt', 'order-4.pdf'], $names);
        self::assertSame('%PDF two', $zip->getFromName('F-0002-2.pdf'));
        self::assertSame("#9: HTTP 404\r\n", $zip->getFromName('missing_pdfs.txt'));
        $zip->close();

        self::assertSame([$path], glob($this->dir . '/*'), 'the temporary PDF files are gone');
    }

    #[RequiresPhpExtension('zip')]
    public function testNothingAddedWritesNoArchive(): void
    {
        $path = $this->dir . '/empty.zip';
        $zipper = new InvoicePdfZipper($path, $this->dir);

        self::assertSame(0, $zipper->close());
        self::assertFileDoesNotExist($path);
    }

    #[RequiresPhpExtension('zip')]
    public function testNothingCanBeAddedAfterClosing(): void
    {
        $zipper = new InvoicePdfZipper($this->dir . '/closed.zip', $this->dir);
        $zipper->addPdf(1, 'F', '1', '%PDF');
        $zipper->close();

        $this->expectException(\RuntimeException::class);
        $zipper->addPdf(2, 'F', '2', '%PDF');
    }

    #[RequiresPhpExtension('zip')]
    public function testTemporaryFilesAreRemovedEvenWhenTheArchiveIsNeverWritten(): void
    {
        $zipper = new InvoicePdfZipper($this->dir . '/abandoned.zip', $this->dir);
        $zipper->addPdf(1, 'F', '1', '%PDF');
        self::assertCount(1, glob($this->dir . '/fgo-pdf-*') ?: []);

        unset($zipper);

        self::assertSame([], glob($this->dir . '/fgo-pdf-*') ?: []);
    }

    #[RequiresPhpExtension('zip')]
    public function testAnArchiveThatCannotBeCreatedIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        new InvoicePdfZipper($this->dir . '/no/such/dir/out.zip', $this->dir);
    }

    public function testAvailabilityFollowsTheExtension(): void
    {
        self::assertSame(class_exists(\ZipArchive::class), InvoicePdfZipper::isAvailable());
    }
}
