<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Bulk;

use Tygh\Addons\FgoInvoicing\Helpers\OrderIdList;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * The orders an admin selected for a bulk action, kept in THEIR session
 * under a random token: the pre-check page is fgo_invoicing.bulk?token=...,
 * never a list of ids in the URL.
 *
 * Why a token:
 *   - a crafted link cannot open a pre-ticked mass Delete / Storno page for
 *     someone else: the selection only exists in the session of the admin
 *     who ticked the orders (and the context-menu POST that made it carries
 *     CS-Cart's security hash);
 *   - the whole selection is kept, deduplicated, not just the first batch,
 *     so "the next batch" really is the next one;
 *   - the URL stays short, and the choice of the page before (the "Email
 *     the PDF link" box of a results page whose failures are retried) comes
 *     along.
 *
 * Pure: this class only reads and rewrites the session bucket (an array)
 * it is handed; functions/bulk.php reads and writes the session itself.
 * Only the newest KEEP selections are kept, so the bucket stays small
 * however many pages are opened.
 */
final readonly class BulkSelection
{
    /** Selections kept per session, newest first. */
    public const KEEP = 10;

    /** Session key of the bucket (functions/bulk.php). */
    public const SESSION_KEY = 'fgo_invoicing_bulk_selections';

    private const TOKEN_PATTERN = '/^[0-9a-f]{32}$/';

    /**
     * @param list<int> $orderIds every selected order, deduplicated, in the list's order
     * @param bool|null $sendEmail the "Email the PDF link" choice to start with; null = the setting
     */
    public function __construct(
        public BulkAction $action,
        public array $orderIds,
        public ?bool $sendEmail = null,
        public int $createdAt = 0,
    ) {
    }

    /** A fresh token: 128 random bits, hex. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isToken(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /**
     * The bucket with this selection added under $token, trimmed to the
     * newest KEEP entries (entries that are not selections are dropped).
     *
     * @param mixed $bucket the session value, whatever it holds
     *
     * @return array<string, array{action: string, ids: list<int>, send_email: bool|null, created_at: int}>
     */
    public function storeIn(mixed $bucket, string $token): array
    {
        $kept = [];
        foreach (is_array($bucket) ? $bucket : [] as $key => $entry) {
            if (!is_string($key) || !self::isToken($key) || $key === $token) {
                continue;
            }
            $selection = self::fromEntry($entry);
            if ($selection !== null) {
                $kept[$key] = $selection->toEntry();
            }
        }
        uasort($kept, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        $kept = array_slice($kept, 0, self::KEEP - 1, true);

        return [$token => $this->toEntry()] + $kept;
    }

    /**
     * The selection stored under $token, or null (unknown token, expired
     * from the bucket, garbled entry).
     */
    public static function findIn(mixed $bucket, string $token): ?self
    {
        if (!self::isToken($token) || !is_array($bucket) || !array_key_exists($token, $bucket)) {
            return null;
        }

        return self::fromEntry($bucket[$token]);
    }

    /**
     * @return array{action: string, ids: list<int>, send_email: bool|null, created_at: int}
     */
    public function toEntry(): array
    {
        return [
            'action' => $this->action->value,
            'ids' => $this->orderIds,
            'send_email' => $this->sendEmail,
            'created_at' => $this->createdAt,
        ];
    }

    private static function fromEntry(mixed $entry): ?self
    {
        if (!is_array($entry)) {
            return null;
        }
        $action = BulkAction::tryFrom(TypeCoerce::toString($entry['action'] ?? ''));
        $ids = OrderIdList::parse($entry['ids'] ?? []);
        if ($action === null || $ids === []) {
            return null;
        }
        $sendEmail = $entry['send_email'] ?? null;

        return new self($action, $ids, is_bool($sendEmail) ? $sendEmail : null, TypeCoerce::toInt($entry['created_at'] ?? 0));
    }
}
