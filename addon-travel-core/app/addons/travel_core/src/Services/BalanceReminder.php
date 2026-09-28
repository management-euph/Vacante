<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\BalanceRepository;

/**
 * Cron "balances" (travel_core cron.php mode=balances, daily):
 *
 *   - 7 and 2 days before the balance is due, the guest gets an email with
 *     the pay link (every payment method: the link opens the checkout);
 *   - once the due date has passed and it is still open, the orders
 *     department gets ONE alert. Nothing is cancelled automatically — the
 *     supplier's own cancellation fees apply, so staff decide.
 *
 * Only balances whose deposit order is paid (P/C) are chased. Idempotent:
 * reminders_sent / overdue_notified_at record what went out.
 */
final class BalanceReminder
{
    public const REMIND_DAYS = [7, 2];

    /** @var callable(array<string, string>, string, string): bool */
    private $mailer;

    /**
     * @param callable(array<string, string>, string, string): bool|null $mailer
     *                                                                           (mail, area, lang) — test seam; defaults to fn_send_mail
     * @param (\Closure(string): string)|null $url absolute storefront URL of a dispatch query
     */
    public function __construct(
        private readonly BalanceRepository $repo = new BalanceRepository(),
        private readonly BalanceService $balances = new BalanceService(),
        ?callable $mailer = null,
        private readonly ?\Closure $url = null,
        private readonly ?MoneyFormatter $money = null,
    ) {
        $this->mailer = $mailer ?? static fn (array $mail, string $area, string $lang): bool => (bool) fn_send_mail($mail, $area, $lang);
    }

    /** @return array{reminded: int, overdue: int} */
    public function run(string $today): array
    {
        $reminded = 0;
        $overdue = 0;
        $horizon = date('Y-m-d', (int) strtotime($today . ' +' . max(self::REMIND_DAYS) . ' days'));
        foreach ($this->repo->openDueBy($horizon) as $row) {
            $id = TypeCoerce::toInt($row['balance_id'] ?? 0);
            $due = TypeCoerce::toString($row['due_date'] ?? '');
            $daysLeft = (int) floor(((int) strtotime($due) - (int) strtotime($today)) / 86400);

            if ($daysLeft < 0) {
                if (empty($row['overdue_notified_at']) && $this->alertAdmin($row)) {
                    $this->repo->update($id, ['overdue_notified_at' => date('Y-m-d H:i:s')]);
                    $overdue++;
                }
                continue;
            }

            $sent = array_filter(explode(',', TypeCoerce::toString($row['reminders_sent'] ?? '')), static fn (string $d): bool => $d !== '');
            $due_marks = array_values(array_filter(
                self::REMIND_DAYS,
                static fn (int $d): bool => $daysLeft <= $d && !in_array((string) $d, $sent, true),
            ));
            if ($due_marks === []) {
                continue;
            }
            // Two marks at once (first run close to the date): one email.
            if ($this->remindGuest($row)) {
                $marks = array_unique(array_merge($sent, array_map('strval', $due_marks)));
                sort($marks);
                $this->repo->update($id, ['reminders_sent' => implode(',', $marks)]);
                $reminded++;
            }
        }

        return ['reminded' => $reminded, 'overdue' => $overdue];
    }

    /** @param array<string, mixed> $row */
    private function remindGuest(array $row): bool
    {
        $email = TypeCoerce::toString($row['email'] ?? '');
        if ($email === '') {
            return false;
        }
        $lang = TypeCoerce::toString($row['lang_code'] ?? '');
        $lang = $lang !== '' ? $lang : (defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en');
        $orderId = TypeCoerce::toInt($row['order_id'] ?? 0);
        $link = $this->link($this->balances->payQuery(TypeCoerce::toInt($row['balance_id'] ?? 0), $orderId));
        $params = [
            '[name]' => trim(TypeCoerce::toString($row['firstname'] ?? '')),
            '[hotel]' => TypeCoerce::toString($row['hotel_name'] ?? ''),
            '[amount]' => $this->amount($row['amount'] ?? 0),
            '[date]' => DateHelper::formatStoreDate(TypeCoerce::toString($row['due_date'] ?? '')),
            '[order_id]' => (string) $orderId,
            '[link]' => $link,
        ];

        return $this->send([
            'to' => $email,
            'from' => 'default_company_orders_department',
            'subject' => TypeCoerce::toString(__('travel_core.balance_reminder_subject', $params, $lang)),
            'body' => TypeCoerce::toString(__('travel_core.balance_reminder_body', $params, $lang)),
        ], 'C', $lang);
    }

    /** @param array<string, mixed> $row */
    private function alertAdmin(array $row): bool
    {
        $to = TypeCoerce::toString(db_get_field("SELECT value FROM ?:settings_objects WHERE name = 'company_orders_department'"));
        if ($to === '') {
            return false;
        }
        $orderId = TypeCoerce::toInt($row['order_id'] ?? 0);
        $guest = trim(TypeCoerce::toString($row['firstname'] ?? '') . ' ' . TypeCoerce::toString($row['lastname'] ?? ''));

        return $this->send([
            'to' => $to,
            'from' => 'default_company_orders_department',
            'subject' => "[Travel] Balance overdue: order #{$orderId}",
            'body' => "The balance of a booking paid with a deposit is overdue.\n\n"
                . "Order: #{$orderId}\n"
                . 'Guest: ' . $guest . ' <' . TypeCoerce::toString($row['email'] ?? '') . ">\n"
                . 'Hotel: ' . TypeCoerce::toString($row['hotel_name'] ?? '')
                . ' (check-in ' . TypeCoerce::toString($row['check_in'] ?? '') . ")\n"
                . 'Balance: ' . $this->amount($row['amount'] ?? 0)
                . ', due ' . TypeCoerce::toString($row['due_date'] ?? '') . "\n\n"
                . "Nothing was cancelled. The supplier's cancellation fees apply from their own dates.\n"
                . "Order: admin.php?dispatch=orders.details&order_id={$orderId}",
        ], 'A', defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en');
    }

    /** @param array<string, string> $mail */
    private function send(array $mail, string $area, string $lang): bool
    {
        try {
            return ($this->mailer)($mail, $area, $lang);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => '[TravelBalance] mail failed: ' . $e->getMessage()]);

            return false;
        }
    }

    private function link(string $query): string
    {
        return TypeCoerce::toString($this->url !== null ? ($this->url)($query) : fn_url($query, 'C', 'http'));
    }

    private function amount(mixed $value): string
    {
        $money = $this->money ?? MoneyFormatter::forStore();

        return html_entity_decode(strip_tags($money->format(TypeCoerce::toFloat($value))), ENT_QUOTES, 'UTF-8');
    }
}
