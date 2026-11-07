<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\BookingAlert;
use Tygh\Addons\SphinxHolidays\Services\BookOutcome;

/**
 * The orders department hears of every booking Sphinx did not confirm:
 * refused (failed, to retry once the cause is fixed), or without a clear
 * answer (pending: it may exist at Sphinx, not to book again unchecked).
 */
#[CoversClass(BookingAlert::class)]
final class BookingAlertTest extends TestCase
{
    /** @var list<array<string, string>> */
    private array $sent = [];

    private function alert(string $to = 'orders@example.ro', ?\Closure $mailer = null): BookingAlert
    {
        $this->sent = [];

        return new BookingAlert(
            static fn (string $key, array $params): string => $key . ' ' . json_encode($params, JSON_UNESCAPED_UNICODE),
            $mailer ?? function (array $mail): bool {
                $this->sent[] = $mail;

                return true;
            },
            static fn (): string => $to,
        );
    }

    public function testAConfirmedBookingSendsNothing(): void
    {
        $alert = $this->alert();

        self::assertNull($alert->compose(BookOutcome::of(['status' => 'confirmed'], false, ''), 10, 501, 'Hotel Ana'));
        self::assertFalse($alert->send(BookOutcome::of(['status' => 'confirmed'], false, ''), 10, 501, 'Hotel Ana'));
        self::assertSame([], $this->sent);
    }

    public function testARefusalTellsTheOrdersDepartmentWhyAndThatItCanBeRetried(): void
    {
        $alert = $this->alert();

        self::assertTrue($alert->send(BookOutcome::of(null, false, 'Client error HTTP 422: price changed'), 10, 501, 'Hotel Ana'));
        self::assertCount(1, $this->sent);
        $mail = $this->sent[0];
        self::assertSame('orders@example.ro', $mail['to']);
        self::assertSame('default_company_orders_department', $mail['from']);
        self::assertStringStartsWith('sphinx_holidays.book_alert_subject ', $mail['subject']);
        self::assertStringStartsWith('sphinx_holidays.book_alert_failed ', $mail['body']);
        foreach (['"[booking_id]":"10"', '"[order_id]":"501"', '"[what]":"Hotel Ana"', '"[reason]":"Client error HTTP 422: price changed"'] as $param) {
            self::assertStringContainsString($param, $mail['body']);
        }
    }

    public function testNoClearAnswerSaysTheBookingMayExist(): void
    {
        $alert = $this->alert();

        $alert->send(BookOutcome::of(null, true, 'Server error HTTP 502'), 11, 502, 'package');

        self::assertStringStartsWith('sphinx_holidays.book_alert_pending ', $this->sent[0]['body'] ?? '');
    }

    public function testWithoutAnOrdersEmailNothingIsSent(): void
    {
        $alert = $this->alert('');

        self::assertFalse($alert->send(BookOutcome::of(null, false, 'refused'), 10, 501, 'Hotel Ana'));
        self::assertSame([], $this->sent);
    }

    /** An alert never breaks the booking flow it reports on. */
    public function testAMailFailureIsSwallowed(): void
    {
        $alert = $this->alert('orders@example.ro', static function (array $mail): bool {
            throw new \RuntimeException('SMTP down');
        });

        self::assertFalse($alert->send(BookOutcome::of(null, false, 'refused'), 10, 501, 'Hotel Ana'));
    }

    public function testTheDefaultWordingComesFromTheLanguageKeys(): void
    {
        $keys = require dirname(__DIR__, 3) . '/lang_keys.php';

        foreach (['sphinx_holidays.book_alert_subject', 'sphinx_holidays.book_alert_failed', 'sphinx_holidays.book_alert_pending'] as $key) {
            self::assertArrayHasKey($key, $keys);
            foreach (['en', 'ro'] as $lang) {
                foreach (['[booking_id]', '[order_id]'] as $placeholder) {
                    self::assertStringContainsString($placeholder, $keys[$key][$lang], "{$key} [{$lang}]");
                }
            }
        }
        foreach (['en', 'ro'] as $lang) {
            self::assertStringContainsString('[reason]', $keys['sphinx_holidays.book_alert_failed'][$lang]);
            self::assertStringContainsString('[reason]', $keys['sphinx_holidays.book_alert_pending'][$lang]);
        }
    }
}
