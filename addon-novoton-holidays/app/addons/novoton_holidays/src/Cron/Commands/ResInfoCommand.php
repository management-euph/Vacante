<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\AskBookingPoller;
use Tygh\Addons\NovotonHolidays\Services\Container;

/**
 * Bookings still on request (ASK): asks Novoton about each, every 15
 * minutes, until the answer is OK, ST or RQ (AskBookingPoller).
 */
class ResInfoCommand extends AbstractCronCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['resinfo'];
    }

    public static function getDescription(): string
    {
        return 'Check ASK booking statuses via resinfo API';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $this->output('Checking ASK bookings status...');
        $this->output('');

        $poller = new AskBookingPoller(
            $this->api,
            Container::getInstance()->bookingRepository(),
            function (string $line): void {
                $this->output($line);
            },
        );
        $stats = $poller->poll();

        $this->logComplete('resinfo', $stats);

        // A run where Novoton answered for no booking at all failed: it is
        // shown as such on the dashboard.
        if ($stats['errors'] > 0 && $stats['checked'] === 0) {
            return ['success' => false, 'error' => 'Novoton did not answer resinfo', 'stats' => $stats];
        }

        return ['success' => true, 'stats' => $stats];
    }
}
