<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Install;

use Netopia\CsCart\Install\LangPackSync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LangPackSync::class)]
final class LangPackSyncTest extends TestCase
{
    private string $dir;

    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $queries = [];

    /** @var array<string, string> */
    private array $stamps = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/netopia_langs_' . bin2hex(random_bytes(6));
        foreach (['en' => 'Order statuses', 'ro' => 'Statusuri comandă'] as $lang => $text) {
            mkdir($this->dir . '/' . $lang . '/addons', 0o750, true);
            file_put_contents($this->dir . '/' . $lang . '/addons/netopia_payments.po', self::po($text));
        }
    }

    protected function tearDown(): void
    {
        foreach (['en', 'ro'] as $lang) {
            @unlink($this->dir . '/' . $lang . '/addons/netopia_payments.po');
            @rmdir($this->dir . '/' . $lang . '/addons');
            @rmdir($this->dir . '/' . $lang);
        }
        @rmdir($this->dir);
    }

    public function testParseKeepsOnlyLanguageVariables(): void
    {
        $vars = LangPackSync::parse(self::po('Order statuses'));

        self::assertSame([
            'netopia_tab_statuses' => 'Order statuses',
            'netopia_quote' => 'Say "hi"',
            'netopia_long' => 'First part, second part',
        ], $vars);
    }

    public function testSyncsOnceAndInsertsWithoutOverwriting(): void
    {
        $sync = $this->sync(['en', 'ro', 'de']);

        self::assertTrue($sync->syncIfChanged());
        self::assertCount(3, $this->queries);
        foreach ($this->queries as $q) {
            self::assertStringStartsWith('INSERT IGNORE INTO ?:language_values', $q['sql']);
        }
        self::assertContains('Statusuri comandă', $this->queries[1]['params']);
        // A store language without its own .po gets the English texts.
        self::assertSame('de', $this->queries[2]['params'][0]);
        self::assertContains('Order statuses', $this->queries[2]['params']);

        // Same files: nothing to do.
        self::assertFalse($sync->syncIfChanged());
        self::assertCount(3, $this->queries);
    }

    public function testAChangedFileSyncsAgain(): void
    {
        $sync = $this->sync(['en']);
        $sync->syncIfChanged();
        file_put_contents($this->dir . '/en/addons/netopia_payments.po', self::po('Statuses'));

        self::assertTrue($sync->syncIfChanged());
        self::assertCount(2, $this->queries);
    }

    public function testADatabaseErrorNeverEscapes(): void
    {
        $sync = new LangPackSync(
            langsDir: $this->dir,
            dbQuery: static function (): never {
                throw new \RuntimeException('db down');
            },
            installedLanguages: static fn (): array => ['en'],
            readStamp: fn (string $k): string => $this->stamps[$k] ?? '',
            writeStamp: function (string $k, string $v): void {
                $this->stamps[$k] = $v;
            },
        );

        self::assertFalse($sync->syncIfChanged());
        self::assertSame([], $this->stamps, 'a failed sync is retried: no stamp written');
    }

    /**
     * @param list<string> $langs
     */
    private function sync(array $langs): LangPackSync
    {
        return new LangPackSync(
            langsDir: $this->dir,
            dbQuery: function (string $sql, mixed ...$params): bool {
                $this->queries[] = ['sql' => $sql, 'params' => $params];

                return true;
            },
            installedLanguages: static fn (): array => $langs,
            readStamp: fn (string $k): string => $this->stamps[$k] ?? '',
            writeStamp: function (string $k, string $v): void {
                $this->stamps[$k] = $v;
            },
        );
    }

    private static function po(string $text): string
    {
        return "msgid \"\"\nmsgstr \"Project-Id-Version: x\\n\"\n\"Language: en\\n\"\n\n"
            . "msgctxt \"Addons::name::netopia_payments\"\nmsgid \"NETOPIA\"\nmsgstr \"NETOPIA\"\n\n"
            . "msgctxt \"Languages::netopia_tab_statuses\"\nmsgid \"Order statuses\"\nmsgstr \"{$text}\"\n\n"
            . "msgctxt \"Languages::netopia_quote\"\nmsgid \"Say \\\"hi\\\"\"\nmsgstr \"Say \\\"hi\\\"\"\n\n"
            . "msgctxt \"Languages::netopia_long\"\nmsgid \"\"\n\"First part, \"\n\"second part\"\nmsgstr \"\"\n\"First part, \"\n\"second part\"\n";
    }
}
