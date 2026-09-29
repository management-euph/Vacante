<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Bulk;

/**
 * The chips above the pre-check table: "6 will be processed · 2 skipped ·
 * 1 with warnings". `toProcess` counts the rows ticked by default (the page
 * recounts live as the admin ticks and unticks); `actionable` is how many
 * could be ticked at all.
 */
final readonly class PrecheckSummary
{
    public function __construct(
        public int $total,
        public int $toProcess,
        public int $actionable,
        public int $skipped,
        public int $withWarnings,
        public int $blocked,
    ) {
    }

    /**
     * @param list<PrecheckRow> $rows
     */
    public static function of(array $rows): self
    {
        $toProcess = $actionable = $skipped = $warnings = $blocked = 0;
        foreach ($rows as $row) {
            if ($row->selected) {
                $toProcess++;
            }
            if ($row->actionable()) {
                $actionable++;
            }
            if ($row->verdict === PrecheckVerdict::Skip) {
                $skipped++;
            }
            if ($row->verdict === PrecheckVerdict::Block) {
                $blocked++;
            }
            if ($row->hasWarnings()) {
                $warnings++;
            }
        }

        return new self(count($rows), $toProcess, $actionable, $skipped, $warnings, $blocked);
    }

    /**
     * @return array{total: int, to_process: int, actionable: int, skipped: int, with_warnings: int, blocked: int}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'to_process' => $this->toProcess,
            'actionable' => $this->actionable,
            'skipped' => $this->skipped,
            'with_warnings' => $this->withWarnings,
            'blocked' => $this->blocked,
        ];
    }
}
