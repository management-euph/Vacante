<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Bulk;

/**
 * The pre-check's answer for one order, as the page shows it.
 *
 *   ready  nothing stands in the way
 *   retry  a previous attempt failed (or is still pending); this repeats it
 *   warn   it can go, but look first (the admin may untick it)
 *   skip   nothing to do: already invoiced, not invoiced, already cancelled
 *   block  it must not go: FGO would refuse it, or the settings forbid it
 */
enum PrecheckVerdict: string
{
    case Ready = 'ready';
    case Retry = 'retry';
    case Warn = 'warn';
    case Skip = 'skip';
    case Block = 'block';

    /** Whether the order may be sent at all; skip and block rows have no checkbox. */
    public function actionable(): bool
    {
        return $this === self::Ready || $this === self::Retry || $this === self::Warn;
    }

    public function langKey(): string
    {
        return 'fgo_invoicing.verdict_' . $this->value;
    }
}
