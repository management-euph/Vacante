<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The weekly hotel availability email to the orders department
 * (docs/novoton-availability-gaps-plan.md §6): each supplier's hotels per
 * state and what changed since the last review.
 *
 * The wording comes from language keys, which the admin can edit under
 * Translations: a registered email template would reach a store only at
 * install, and this project updates stores by pulling code. Sent only when
 * a review classified something new or changed a state; a quiet week sends
 * nothing.
 */
final class AvailabilityReportMail
{
    /** @var \Closure(string, array<string, string>): string */
    private \Closure $translate;

    /** @var \Closure(array<string, string>): bool */
    private \Closure $mailer;

    /**
     * @param (\Closure(string, array<string, string>): string)|null $translate test seam; defaults to __()
     * @param (\Closure(array<string, string>): bool)|null $mailer test seam; defaults to fn_send_mail, admin area
     */
    public function __construct(?\Closure $translate = null, ?\Closure $mailer = null)
    {
        $this->translate = $translate ?? static fn (string $key, array $params): string => TypeCoerce::toString(__($key, $params));
        $this->mailer = $mailer ?? static fn (array $mail): bool => (bool) fn_send_mail($mail, 'A');
    }

    /**
     * The subject and body for a review summary; null when there is nothing new.
     *
     * @param array{at: string, providers: array<string, array{mode: string, counts: array<string, int>, changed: int, first: int, changes: list<array{hotel_id: string, hotel_name: string, product_id: int, from: string, to: string, reason: string}>}>} $summary
     * @param array<string, string> $labels provider name => label
     *
     * @return array{subject: string, body: string}|null
     */
    public function compose(array $summary, array $labels, string $pageUrl): ?array
    {
        $changed = 0;
        $first = 0;
        foreach ($summary['providers'] as $provider) {
            $changed += $provider['changed'];
            $first += $provider['first'];
        }
        if ($changed === 0 && $first === 0) {
            return null;
        }

        $t = $this->translate;
        $lines = [$t('travel_core.availability_mail_intro', []), ''];
        foreach ($summary['providers'] as $name => $provider) {
            $lines[] = '== ' . ($labels[$name] ?? $name) . ' ==';
            foreach ($provider['counts'] as $state => $count) {
                if ($count > 0) {
                    $lines[] = '  ' . $t('travel_core.availability_state_' . $state, []) . ': ' . $count;
                }
            }
            if ($provider['first'] > 0) {
                $lines[] = '  ' . $t('travel_core.availability_mail_first', ['[n]' => (string) $provider['first']]);
            }
            if ($provider['changes'] !== []) {
                $lines[] = '  ' . $t('travel_core.availability_mail_changes', []) . ':';
                foreach ($provider['changes'] as $change) {
                    $lines[] = '  - ' . ($change['hotel_name'] !== '' ? $change['hotel_name'] : '#' . $change['hotel_id'])
                        . ': ' . $t('travel_core.availability_state_' . $change['from'], [])
                        . ' -> ' . $t('travel_core.availability_state_' . $change['to'], []);
                }
                $more = $provider['changed'] - count($provider['changes']);
                if ($more > 0) {
                    $lines[] = '  ' . $t('travel_core.availability_mail_more', ['[n]' => (string) $more]);
                }
            }
            $lines[] = '';
        }
        $lines[] = $t('travel_core.availability_mail_page', ['[url]' => $pageUrl]);

        return [
            // The first review changes nothing yet; it says so instead of "0 changes".
            'subject' => $changed > 0
                ? $t('travel_core.availability_mail_subject', ['[changed]' => (string) $changed])
                : $t('travel_core.availability_mail_subject_first', ['[n]' => (string) $first]),
            'body' => implode("\n", $lines),
        ];
    }

    /**
     * Sends the review's email, if there is one, to the orders department.
     *
     * @param array{at: string, providers: array<string, array{mode: string, counts: array<string, int>, changed: int, first: int, changes: list<array{hotel_id: string, hotel_name: string, product_id: int, from: string, to: string, reason: string}>}>} $summary
     * @param array<string, string> $labels
     */
    public function send(array $summary, array $labels, string $pageUrl, string $to): bool
    {
        $mail = $this->compose($summary, $labels, $pageUrl);
        if ($mail === null || $to === '') {
            return false;
        }

        try {
            return ($this->mailer)([
                'to' => $to,
                'from' => 'default_company_orders_department',
                'subject' => $mail['subject'],
                'body' => $mail['body'],
            ]);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => '[TravelAvailability] weekly email failed: ' . $e->getMessage()]);

            return false;
        }
    }
}
