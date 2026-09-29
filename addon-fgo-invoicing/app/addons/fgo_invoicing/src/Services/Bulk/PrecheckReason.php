<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Bulk;

/**
 * One line of explanation under a pre-check verdict ("Already invoiced ·
 * F 0002", "Company (PJ) without CIF").
 *
 * A code, not a sentence: BulkPrecheck and BulkRunner stay free of CS-Cart
 * (no __()), and the controller translates `fgo_invoicing.pc_<code>` with
 * the params, which are CS-Cart placeholders ('[invoice]' => 'F 0002') so
 * __($key, $params) substitutes them as it does for any core string.
 */
final readonly class PrecheckReason
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARN = 'warn';
    public const LEVEL_BLOCK = 'block';

    /**
     * Every code a reason can carry. Each needs a `fgo_invoicing.pc_<code>`
     * language key; BulkLanguageKeysTest walks this list.
     */
    public const CODES = [
        'already_invoiced',
        'last_error',
        'pending',
        'previously_canceled',
        'previously_reversed',
        'previously_deleted',
        'order_status',
        'pj_without_cif',
        'pj_without_cif_required',
        'cif_invalid',
        'pf_without_cnp_required',
        'cnp_required_no_field',
        'cnp_invalid',
        'zero_total',
        'mapping_failed',
        'not_failed',
        'not_invoiced',
        'no_email',
        'no_pdf_link',
        'already_canceled',
        'already_reversed',
        'already_deleted',
        'no_series_number',
        'order_not_found',
        'unknown_action',
    ];

    /**
     * @param array<string, string> $params CS-Cart placeholders, e.g. ['[invoice]' => 'F 0002']
     */
    public function __construct(
        public string $code,
        public string $level,
        public array $params = [],
    ) {
        if (!in_array($code, self::CODES, true)) {
            throw new \InvalidArgumentException('Unknown pre-check reason code "' . $code . '"');
        }
        if (!in_array($level, [self::LEVEL_INFO, self::LEVEL_WARN, self::LEVEL_BLOCK], true)) {
            throw new \InvalidArgumentException('Unknown pre-check reason level "' . $level . '"');
        }
    }

    public function langKey(): string
    {
        return 'fgo_invoicing.pc_' . $this->code;
    }

    /**
     * @return array{code: string, level: string, params: array<string, string>}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'level' => $this->level, 'params' => $this->params];
    }
}
