<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Pdf;

use Tygh\Addons\FgoInvoicing\Api\FgoSigner;

/**
 * Builds the "Download PDFs (ZIP)" archive.
 *
 * Entry names come from the invoice series and number ("F-0002.pdf"),
 * transliterated and reduced to [A-Za-z0-9_-]: they are FGO data, and a
 * series holding "../" or a slash must not become a path inside the archive.
 * Two invoices that would share a name (the same number in two series that
 * differ only in dropped characters) get "-2", "-3"; an invoice with neither
 * series nor number falls back to "order-<id>.pdf".
 *
 * Each PDF goes to a temporary file and is added by path, so the archive is
 * assembled without holding every PDF in memory at once (up to 100 files of
 * up to 15 MB each). close() writes the archive and removes the temporary
 * files.
 */
final class InvoicePdfZipper
{
    private const NAME_MAX = 100;

    private readonly \ZipArchive $zip;

    /** @var array<string, true> lower-cased names already in the archive */
    private array $names = [];

    /** @var list<string> */
    private array $tempFiles = [];

    private int $entries = 0;

    private bool $closed = false;

    /**
     * @throws \RuntimeException when ZipArchive is missing or the archive cannot be created
     */
    public function __construct(
        string $zipPath,
        private readonly ?string $tempDir = null,
    ) {
        if (!self::isAvailable()) {
            throw new \RuntimeException('The PHP zip extension (ZipArchive) is not available');
        }
        // ZipArchive only notices an unwritable target at close(), after
        // every PDF has been downloaded: fail before any of that.
        $target = dirname($zipPath);
        if (!is_dir($target) || !is_writable($target)) {
            throw new \RuntimeException('Cannot create the ZIP archive: its directory is not writable');
        }
        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException('Cannot create the ZIP archive (ZipArchive error ' . $opened . ')');
        }
        $this->zip = $zip;
    }

    /**
     * An archive never closed is discarded, not half-written: ZipArchive
     * would otherwise try to write it on destruction, from temporary files
     * that are about to go.
     */
    public function __destruct()
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->zip->unchangeAll();
            $this->zip->close();
        }
        $this->removeTempFiles();
    }

    public static function isAvailable(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * The archive name for an invoice, extension included, before
     * de-duplication.
     */
    public static function entryName(int $orderId, string $series, string $number): string
    {
        $series = self::clean($series);
        $number = self::clean($number);
        $base = $series !== '' && $number !== '' ? $series . '-' . $number : $series . $number;
        if ($base === '') {
            $base = 'order-' . max(0, $orderId);
        }

        return substr($base, 0, self::NAME_MAX) . '.pdf';
    }

    /**
     * @return string the name the PDF got inside the archive
     *
     * @throws \RuntimeException
     */
    public function addPdf(int $orderId, string $series, string $number, string $pdf): string
    {
        $this->assertOpen();
        $name = $this->unique(self::entryName($orderId, $series, $number));

        $dir = $this->tempDir ?? sys_get_temp_dir();
        $tmp = tempnam($dir, 'fgo-pdf-');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create a temporary file for the PDF');
        }
        $this->tempFiles[] = $tmp;
        if (file_put_contents($tmp, $pdf) === false) {
            throw new \RuntimeException('Cannot write a temporary file for the PDF');
        }
        if (!$this->zip->addFile($tmp, $name)) {
            throw new \RuntimeException('Cannot add ' . $name . ' to the ZIP archive');
        }
        $this->names[strtolower($name)] = true;
        $this->entries++;

        return $name;
    }

    /**
     * A plain-text note inside the archive (the list of PDFs that could not
     * be downloaded, so a missing file is explained where it is missed).
     *
     * @throws \RuntimeException
     */
    public function addText(string $name, string $content): string
    {
        $this->assertOpen();
        $name = $this->unique(self::clean(pathinfo($name, PATHINFO_FILENAME)) . '.txt');
        if (!$this->zip->addFromString($name, $content)) {
            throw new \RuntimeException('Cannot add ' . $name . ' to the ZIP archive');
        }
        $this->names[strtolower($name)] = true;
        $this->entries++;

        return $name;
    }

    /**
     * Write the archive. Returns the number of entries.
     *
     * @throws \RuntimeException
     */
    public function close(): int
    {
        if ($this->closed) {
            return $this->entries;
        }
        $this->closed = true;
        try {
            if ($this->entries > 0 && !$this->zip->close()) {
                throw new \RuntimeException('Cannot write the ZIP archive');
            }
        } finally {
            $this->removeTempFiles();
        }

        return $this->entries;
    }

    private function unique(string $name): string
    {
        if (!isset($this->names[strtolower($name)])) {
            return $name;
        }
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        for ($n = 2; ; $n++) {
            $candidate = $stem . '-' . $n . ($extension !== '' ? '.' . $extension : '');
            if (!isset($this->names[strtolower($candidate)])) {
                return $candidate;
            }
        }
    }

    private static function clean(string $part): string
    {
        $ascii = FgoSigner::convertDiacritics2(trim($part));
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $ascii) ?? '';

        return trim(preg_replace('/_{2,}/', '_', $safe) ?? $safe, '_-');
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('The ZIP archive is already closed');
        }
    }

    private function removeTempFiles(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }
}
