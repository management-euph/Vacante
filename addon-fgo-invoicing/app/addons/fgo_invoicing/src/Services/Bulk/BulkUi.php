<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Bulk;

/**
 * The language keys of the bulk page that are chosen in code rather than
 * written into the template: the title per action, and the strings the page
 * script needs (handed to it as one JSON data attribute, since CS-Cart
 * rewrites inline <script> blocks).
 *
 * Kept as data so BulkLanguageKeysTest can prove every one of them exists in
 * lang_keys.php; a missing key renders as "_fgo_invoicing.x" on the page.
 *
 * Placeholders are CS-Cart style ([count], [done], [total], [order]); the
 * script substitutes them. Romanian needs three plural forms for "N
 * invoices" (1 factură, 2-19 facturi, 20+ de facturi): count_one / _few /
 * _many, picked in the script by the CLDR Romanian rule; English simply
 * repeats the plural.
 */
final class BulkUi
{
    /** Script name => language key, the same for every action. */
    public const JS_STRINGS = [
        'count_one' => 'fgo_invoicing.count_invoices_one',
        'count_few' => 'fgo_invoicing.count_invoices_few',
        'count_many' => 'fgo_invoicing.count_invoices_many',
        'chip_to_process' => 'fgo_invoicing.chip_to_process',
        'chip_issued' => 'fgo_invoicing.chip_issued',
        'chip_done' => 'fgo_invoicing.chip_done',
        'chip_failed' => 'fgo_invoicing.chip_failed',
        'chip_skipped' => 'fgo_invoicing.chip_skipped',
        'chip_remaining' => 'fgo_invoicing.chip_remaining',
        'chip_not_processed' => 'fgo_invoicing.chip_not_processed',
        'progress_count' => 'fgo_invoicing.progress_count',
        'progress_stopping' => 'fgo_invoicing.progress_stopping',
        'progress_stopped' => 'fgo_invoicing.progress_stopped',
        'progress_done' => 'fgo_invoicing.progress_done',
        'queued' => 'fgo_invoicing.run_state_queued',
        'issued' => 'fgo_invoicing.run_state_issued',
        'done' => 'fgo_invoicing.run_state_done',
        'failed' => 'fgo_invoicing.run_state_failed',
        'skipped' => 'fgo_invoicing.run_state_skipped',
        'not_processed' => 'fgo_invoicing.run_state_not_processed',
        'emailed' => 'fgo_invoicing.run_emailed',
        'email_failed' => 'fgo_invoicing.run_email_failed',
        'transport_error' => 'fgo_invoicing.run_transport_error',
        'session_expired' => 'fgo_invoicing.run_session_expired',
        'log_in' => 'fgo_invoicing.run_log_in',
        'leave_warning' => 'fgo_invoicing.run_leave_warning',
        'retry_failed' => 'fgo_invoicing.btn_retry_failed',
        'open_pdf' => 'fgo_invoicing.open_pdf',
        'details' => 'fgo_invoicing.col_details',
    ];

    private function __construct()
    {
    }

    public static function titleKey(BulkAction $action): string
    {
        return 'fgo_invoicing.bulk_title_' . $action->value;
    }

    /** "Issue" in "Issue 6 invoices". */
    public static function verbKey(BulkAction $action): string
    {
        return 'fgo_invoicing.run_verb_' . $action->value;
    }

    /** "issuing order #5" in "3 of 6 processed · issuing order #5". */
    public static function currentKey(BulkAction $action): string
    {
        return match (true) {
            $action->issues() => 'fgo_invoicing.progress_current_issue',
            $action === BulkAction::Email => 'fgo_invoicing.progress_current_email',
            default => 'fgo_invoicing.progress_current_other',
        };
    }

    /** The row label while its request is in flight. */
    public static function runningKey(BulkAction $action): string
    {
        return $action->issues() ? 'fgo_invoicing.run_state_running_issue' : 'fgo_invoicing.run_state_running';
    }

    /**
     * Which "N invoices" form a count takes: CLDR's Romanian rule, which
     * English satisfies too (its few and many texts are the same plural).
     * bulk.js carries the same rule for the live count.
     *
     * @param int $count how many invoices the label counts
     *
     * @return 'one'|'few'|'many'
     */
    public static function pluralForm(int $count): string
    {
        if ($count === 1) {
            return 'one';
        }
        $mod = abs($count) % 100;

        return $count === 0 || ($mod >= 1 && $mod <= 19) ? 'few' : 'many';
    }

    /**
     * "Issue 6 invoices", "Anulează 20 de facturi": the start button.
     *
     * @param \Closure(string): string $translate
     */
    public static function startLabel(BulkAction $action, int $count, \Closure $translate): string
    {
        $noun = str_replace('[count]', (string) $count, $translate('fgo_invoicing.count_invoices_' . self::pluralForm($count)));

        return trim($translate(self::verbKey($action)) . ' ' . $noun);
    }

    /**
     * @param \Closure(string): string $translate
     *
     * @return array<string, string>
     */
    public static function jsStrings(BulkAction $action, \Closure $translate): array
    {
        $out = [];
        foreach (self::JS_STRINGS as $name => $key) {
            $out[$name] = $translate($key);
        }
        $out['verb'] = $translate(self::verbKey($action));
        $out['current'] = $translate(self::currentKey($action));
        $out['running'] = $translate(self::runningKey($action));

        return $out;
    }

    /**
     * Every key this class can hand out, for the language-key test.
     *
     * @return list<string>
     */
    public static function allKeys(): array
    {
        $keys = array_values(self::JS_STRINGS);
        foreach (BulkAction::cases() as $action) {
            $keys[] = self::titleKey($action);
            $keys[] = self::verbKey($action);
            $keys[] = self::currentKey($action);
            $keys[] = self::runningKey($action);
        }
        foreach (PrecheckVerdict::cases() as $verdict) {
            $keys[] = $verdict->langKey();
        }
        foreach (PrecheckReason::CODES as $code) {
            $keys[] = 'fgo_invoicing.pc_' . $code;
        }

        return array_values(array_unique($keys));
    }
}
