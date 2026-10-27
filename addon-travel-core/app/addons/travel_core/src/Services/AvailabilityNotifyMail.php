<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\NotifyRequestRepository;

/**
 * Sends the hotel page's "notify me" emails (docs/novoton-availability-gaps-plan.md
 * §7–8), from Travel Core's daily cron line, like the weekly review.
 *
 * A waiting request gets its ONE email once booking opened for its hotel
 * after it was made: an offer was seen (any supplier: their checks and
 * customers' searches record them), or, for a guest waiting for next
 * season's prices, prices are published for a future date. The request is
 * then done and its address removed (NotifyRequestRepository). Requests
 * older than 12 months are dropped the same way.
 *
 * It needs no supplier call: the availability records say it all. The
 * wording comes from language keys, in the guest's language, as for the
 * weekly report.
 */
final class AvailabilityNotifyMail
{
    /** Emails one run sends at most; the rest go the next day. */
    public const int PER_RUN = 200;

    /** Requests one run reads. */
    private const int READ = 2000;

    /** Hotel ids per IN (...) list. */
    private const int CHUNK = 500;

    /** @var \Closure(array<string, string>, string): bool */
    private readonly \Closure $mailer;

    /** @var \Closure(int, string): string */
    private readonly \Closure $url;

    /** @var \Closure(string, array<string, string>, string): string */
    private readonly \Closure $translate;

    /**
     * @param (\Closure(array<string, string>, string): bool)|null $mailer (mail, lang); defaults to fn_send_mail, customer area
     * @param (\Closure(int, string): string)|null $url (product id, lang): the hotel page's address
     * @param (\Closure(string, array<string, string>, string): string)|null $translate defaults to __()
     */
    public function __construct(
        private readonly NotifyRequestRepository $requests = new NotifyRequestRepository(),
        ?\Closure $mailer = null,
        ?\Closure $url = null,
        ?\Closure $translate = null,
    ) {
        $this->mailer = $mailer ?? static fn (array $mail, string $lang): bool => (bool) fn_send_mail($mail, 'C', $lang);
        $this->url = $url ?? static fn (int $productId, string $lang): string => TypeCoerce::toString(fn_url('products.view?product_id=' . $productId, 'C', 'https', $lang));
        $this->translate = $translate ?? static fn (string $key, array $params, string $lang): string => TypeCoerce::toString(__($key, $params, $lang));
    }

    /** One line for Travel Core's daily cron output. Never throws. */
    public static function runDaily(?\DateTimeImmutable $now = null): string
    {
        try {
            $r = (new self())->run($now ?? new \DateTimeImmutable(), TravelCoreConfig::getAvailabilityThresholds()->onRequestCounts);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => '[TravelAvailability] notify-me emails failed: ' . $e->getMessage()]);

            return 'Hotel availability "notify me" emails failed: ' . $e->getMessage();
        }

        return sprintf(
            'Hotel availability "notify me": %d emails sent, %d failed, %d requests still waiting, %d dropped after %d months.',
            $r['sent'],
            $r['failed'],
            $r['waiting'],
            $r['expired'],
            NotifyRequestRepository::KEEP_MONTHS,
        );
    }

    /** @return array{sent: int, failed: int, waiting: int, expired: int} */
    public function run(\DateTimeImmutable $now, bool $onRequestCounts): array
    {
        $expired = $this->requests->expireBefore($now->modify('-' . NotifyRequestRepository::KEEP_MONTHS . ' months')->format('Y-m-d H:i:s'));
        $waiting = $this->requests->waiting(self::READ);
        if ($waiting === []) {
            return ['sent' => 0, 'failed' => 0, 'waiting' => 0, 'expired' => $expired];
        }

        $records = self::records($waiting);
        $today = $now->format('Y-m-d');
        $sent = [];
        $failed = 0;
        foreach ($waiting as $request) {
            if (count($sent) + $failed >= self::PER_RUN) {
                break;
            }
            $record = $records[$request['provider'] . '|' . $request['hotel_id']] ?? null;
            $opened = self::opened($request, $record, $onRequestCounts, $today);
            $productId = $request['product_id'] > 0 ? $request['product_id'] : TypeCoerce::toInt($record['product_id'] ?? 0);
            if ($opened === null || $productId <= 0 || ($record['product_open'] ?? false) !== true) {
                continue;
            }
            $name = $request['hotel_name'] !== '' ? $request['hotel_name'] : TypeCoerce::toString($record['hotel_name'] ?? '');
            if ($this->send($request, $opened, $productId, $name)) {
                $sent[] = $request['request_id'];
            } else {
                $failed++;
            }
        }
        $this->requests->markNotified($sent);

        return ['sent' => count($sent), 'failed' => $failed, 'waiting' => count($waiting) - count($sent), 'expired' => $expired];
    }

    /**
     * Whether booking opened for the request's hotel since the request, and
     * how: 'booking' when an offer was seen since (an immediate one only,
     * when on-request offers do not count), 'prices' when the guest waits for
     * prices and some are published for a date from today on; null while
     * neither.
     *
     * @param array{created_at: string, watch_for: string} $request
     * @param array<string, mixed>|null $record the hotel's availability record
     */
    public static function opened(array $request, ?array $record, bool $onRequestCounts, string $today): ?string
    {
        if ($record === null) {
            return null;
        }
        $offer = TypeCoerce::toString($record[$onRequestCounts ? 'offer_seen_at' : 'immediate_seen_at'] ?? '');
        if ($offer !== '' && $offer > $request['created_at']) {
            return 'booking';
        }
        $pricedTo = TypeCoerce::toString($record['contract_to'] ?? '');
        if ($request['watch_for'] === 'prices' && $pricedTo !== '' && $pricedTo >= $today) {
            return 'prices';
        }

        return null;
    }

    /**
     * The records of the requests' hotels, keyed "provider|hotel_id", each
     * with whether its product can be opened (Active or Hidden: a hidden
     * hotel's page still opens from a link).
     *
     * @param list<array{request_id: int, provider: string, hotel_id: string, hotel_name: string, product_id: int, email: string, lang_code: string, watch_for: string, created_at: string}> $requests
     *
     * @return array<string, array<string, mixed>>
     */
    private static function records(array $requests): array
    {
        $byProvider = [];
        foreach ($requests as $request) {
            $byProvider[$request['provider']][$request['hotel_id']] = true;
        }
        $records = [];
        foreach ($byProvider as $provider => $hotelIds) {
            foreach (array_chunk(array_map('strval', array_keys($hotelIds)), self::CHUNK) as $chunk) {
                foreach (TypeCoerce::toRowList(db_get_array(
                    'SELECT provider, hotel_id, product_id, hotel_name, offer_seen_at, immediate_seen_at, contract_to
                     FROM ?:travel_hotel_availability WHERE provider = ?s AND hotel_id IN (?a)',
                    $provider,
                    $chunk,
                )) as $row) {
                    $records[$provider . '|' . TypeCoerce::toString($row['hotel_id'] ?? '')] = $row;
                }
            }
        }

        $productIds = [];
        foreach ($requests as $request) {
            if ($request['product_id'] > 0) {
                $productIds[] = $request['product_id'];
            }
        }
        foreach ($records as $row) {
            $productIds[] = TypeCoerce::toInt($row['product_id'] ?? 0);
        }
        $open = [];
        foreach (array_chunk(array_values(array_unique(array_filter($productIds))), self::CHUNK) as $chunk) {
            foreach (TypeCoerce::toRowList(db_get_array(
                "SELECT product_id FROM ?:products WHERE product_id IN (?n) AND status IN ('A', 'H')",
                $chunk,
            )) as $row) {
                $open[TypeCoerce::toInt($row['product_id'] ?? 0)] = true;
            }
        }
        foreach ($requests as $request) {
            $key = $request['provider'] . '|' . $request['hotel_id'];
            if (isset($records[$key])) {
                $productId = $request['product_id'] > 0 ? $request['product_id'] : TypeCoerce::toInt($records[$key]['product_id'] ?? 0);
                $records[$key]['product_open'] = isset($open[$productId]);
            }
        }

        return $records;
    }

    /**
     * @param array{request_id: int, email: string, lang_code: string, created_at: string} $request
     */
    private function send(array $request, string $opened, int $productId, string $hotelName): bool
    {
        $lang = $request['lang_code'] !== '' ? $request['lang_code'] : (defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en');
        try {
            $params = [
                '[hotel]' => $hotelName,
                '[link]' => ($this->url)($productId, $lang),
                '[date]' => DateHelper::formatStoreDate(substr($request['created_at'], 0, 10)),
            ];

            return ($this->mailer)([
                'to' => $request['email'],
                'from' => 'default_company_orders_department',
                'subject' => ($this->translate)('travel_core.notify_mail_subject_' . $opened, $params, $lang),
                'body' => ($this->translate)('travel_core.notify_mail_body_' . $opened, $params, $lang),
            ], $lang);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => '[TravelAvailability] notify-me email #' . $request['request_id'] . ' failed: ' . $e->getMessage()]);

            return false;
        }
    }
}
