<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services\Bulk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkAction;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkUi;

/**
 * The strings the bulk page picks in code. Their existence as language keys
 * is BulkLanguageKeysTest's job; this pins how they are chosen.
 */
#[CoversClass(BulkUi::class)]
final class BulkUiTest extends TestCase
{
    /** Romanian texts, where the three plural forms actually differ. */
    private const RO = [
        'fgo_invoicing.count_invoices_one' => '1 factură',
        'fgo_invoicing.count_invoices_few' => '[count] facturi',
        'fgo_invoicing.count_invoices_many' => '[count] de facturi',
        'fgo_invoicing.run_verb_issue' => 'Emite',
        'fgo_invoicing.run_verb_cancel' => 'Anulează',
    ];

    private static function translate(): \Closure
    {
        return static fn (string $key): string => self::RO[$key] ?? '_' . $key;
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function plurals(): iterable
    {
        yield '0' => [0, 'few'];
        yield '1' => [1, 'one'];
        yield '2' => [2, 'few'];
        yield '19' => [19, 'few'];
        yield '20' => [20, 'many'];
        yield '99' => [99, 'many'];
        yield '100' => [100, 'many'];
        yield '101' => [101, 'few'];
        yield '119' => [119, 'few'];
        yield '120' => [120, 'many'];
    }

    #[DataProvider('plurals')]
    public function testThePluralFollowsTheRomanianRule(int $count, string $form): void
    {
        self::assertSame($form, BulkUi::pluralForm($count));
    }

    public function testTheStartButtonReadsNaturally(): void
    {
        self::assertSame('Emite 1 factură', BulkUi::startLabel(BulkAction::Issue, 1, self::translate()));
        self::assertSame('Emite 6 facturi', BulkUi::startLabel(BulkAction::Issue, 6, self::translate()));
        self::assertSame('Anulează 20 de facturi', BulkUi::startLabel(BulkAction::Cancel, 20, self::translate()));
    }

    public function testThePageScriptGetsTheSharedAndTheActionStrings(): void
    {
        $issue = BulkUi::jsStrings(BulkAction::Issue, self::translate());
        $email = BulkUi::jsStrings(BulkAction::Email, self::translate());

        foreach (array_keys(BulkUi::JS_STRINGS) as $name) {
            self::assertArrayHasKey($name, $issue);
        }
        self::assertSame('Emite', $issue['verb']);
        self::assertSame('_fgo_invoicing.progress_current_issue', $issue['current']);
        self::assertSame('_fgo_invoicing.run_state_running_issue', $issue['running']);
        self::assertSame('_fgo_invoicing.progress_current_email', $email['current']);
        self::assertSame('_fgo_invoicing.run_state_running', $email['running']);
        self::assertSame('_fgo_invoicing.progress_current_other', BulkUi::jsStrings(BulkAction::Delete, self::translate())['current']);
    }

    public function testKeysPerAction(): void
    {
        self::assertSame('fgo_invoicing.bulk_title_storno', BulkUi::titleKey(BulkAction::Storno));
        self::assertSame('fgo_invoicing.run_verb_retry', BulkUi::verbKey(BulkAction::Retry));
        self::assertSame('fgo_invoicing.progress_current_issue', BulkUi::currentKey(BulkAction::Retry));
    }

    public function testAllKeysAreListedOnce(): void
    {
        $keys = BulkUi::allKeys();

        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertContains('fgo_invoicing.pc_already_invoiced', $keys);
        self::assertContains('fgo_invoicing.verdict_block', $keys);
        self::assertContains('fgo_invoicing.bulk_title_delete', $keys);
        self::assertContains('fgo_invoicing.count_invoices_many', $keys);
    }
}
