<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Packages Novoton refuses to give a price list for, remembered between runs.
 *
 * Novoton answers priceinfo for some packages with
 * <error><err>Invalid parameter!</err></error>, every time (hotel 476
 * "ADMIRAL ***** +BEACH [SPO 1 - 2 NIGHTS]", 2026-10-19). Since dad0346 a
 * refusal no longer replaces the stored prices, but nothing remembered it:
 * the package kept its old synced_at, so every cron tick found it "stale",
 * started an incremental run, asked again, retried, and logged the same error.
 *
 * A refusal of one package is recorded here, in CS-Cart's storage_data (no
 * schema change). Incremental runs leave the package alone for the full-sync
 * interval and the next full sync asks once more; an answer clears the record.
 * A refusal of the whole account, such as a wrong username or password, is not
 * about the package and is never recorded: otherwise every package would wait
 * for the next full sync after the password was fixed.
 */
final class PriceInfoRefusals
{
    public const string STORAGE_KEY = 'novoton_priceinfo_refused';

    /** Novoton's reasons that concern one package, lower-case, without the trailing "!". */
    private const array PACKAGE_REASONS = ['invalid parameter'];

    /** A record older than this is dropped the next time the list is written. */
    private const int KEEP_SECONDS = 30 * 86400;

    /** @var \Closure(): string */
    private readonly \Closure $read;

    /** @var \Closure(string): void */
    private readonly \Closure $write;

    /** @var array<string, array{at: int, reason: string}>|null "hotelId/packageId" => refusal, loaded once */
    private ?array $records = null;

    /**
     * @param (\Closure(): string)|null $read the stored JSON (default: fn_get_storage_data)
     * @param (\Closure(string): void)|null $write store the JSON; '' deletes it (default: fn_set_storage_data)
     */
    public function __construct(?\Closure $read = null, ?\Closure $write = null)
    {
        $this->read = $read ?? static fn (): string => function_exists('fn_get_storage_data')
            ? TypeCoerce::toString(fn_get_storage_data(self::STORAGE_KEY))
            : '';
        $this->write = $write ?? static function (string $json): void {
            if (function_exists('fn_set_storage_data')) {
                fn_set_storage_data(self::STORAGE_KEY, $json);
            }
        };
    }

    /** Whether Novoton's refusal reason concerns the package itself, not the account. */
    public static function isAboutThePackage(string $reason): bool
    {
        return in_array(strtolower(rtrim(trim($reason), '!. ')), self::PACKAGE_REASONS, true);
    }

    public function record(string $item, string $reason, int $now): void
    {
        $records = $this->load();
        $records[$item] = ['at' => $now, 'reason' => $reason];
        $this->save($records, $now);
    }

    /** An answer came: the package is asked like any other again. */
    public function forget(string $item, int $now): void
    {
        $records = $this->load();
        if (!isset($records[$item])) {
            return;
        }
        unset($records[$item]);
        $this->save($records, $now);
    }

    /**
     * @return array<string, string> "hotelId/packageId" => reason, for refusals within $seconds before $now
     */
    public function refusedWithin(int $seconds, int $now): array
    {
        $recent = [];
        foreach ($this->load() as $item => $refusal) {
            if ($refusal['at'] > $now - $seconds) {
                $recent[$item] = $refusal['reason'];
            }
        }

        return $recent;
    }

    /**
     * @return array<string, array{at: int, reason: string}>
     */
    private function load(): array
    {
        if ($this->records !== null) {
            return $this->records;
        }

        $records = [];
        $decoded = json_decode(($this->read)(), true);
        foreach (is_array($decoded) ? $decoded : [] as $item => $refusal) {
            if (!is_array($refusal)) {
                continue;
            }
            $at = TypeCoerce::toInt($refusal['at'] ?? 0);
            if ($at > 0) {
                $records[(string) $item] = ['at' => $at, 'reason' => TypeCoerce::toString($refusal['reason'] ?? '')];
            }
        }

        return $this->records = $records;
    }

    /**
     * @param array<string, array{at: int, reason: string}> $records
     */
    private function save(array $records, int $now): void
    {
        $this->records = array_filter($records, static fn (array $refusal): bool => $refusal['at'] > $now - self::KEEP_SECONDS);
        ($this->write)($this->records === [] ? '' : (string) json_encode($this->records));
    }
}
