<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\AlternativeEmail;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class AlternativesCommand extends AbstractCronCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['alternative_rs', 'alternative_rs_bookings', 'notify_alternatives', 'expire_requests'];
    }

    public static function getDescription(): string
    {
        return 'Alternative request management (check, notify, expire)';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $mode = $this->params['_mode'] ?? 'alternative_rs';

        switch ($mode) {
            case 'alternative_rs':
                return $this->checkAlternatives();
            case 'alternative_rs_bookings':
                return $this->checkBookingAlternatives();
            case 'notify_alternatives':
                return $this->notifyAlternatives();
            case 'expire_requests':
                return $this->expireRequests();
        }

        return ['success' => false, 'error' => 'Unknown sub-mode'];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkAlternatives(): array
    {
        $this->output('Checking alternative_RS for pending requests...');
        $this->output('');

        $altRepo = Container::getInstance()->alternativeRequestRepository();
        $pending = $altRepo->findPendingOlderThan(24);
        $pending = fn_novoton_holidays_decrypt_requests_pii($pending);

        if (empty($pending)) {
            $this->output('No pending requests older than 24 hours found.');
            return ['success' => true, 'stats' => ['checked' => 0]];
        }

        $this->output('Found ' . count($pending) . ' pending requests to check.');
        $this->output('');

        $found = 0;
        $emailed = 0;

        foreach ($pending as $request) {
            $requestId = TypeCoerce::toInt($request['request_id']);
            $novotonRequestId = TypeCoerce::toString($request['novoton_request_id']);
            $this->output("Checking request #{$requestId} (IdNum: {$novotonRequestId})... ", false);

            $response = $this->api->reservations()->getAlternatives($novotonRequestId);

            if (!(bool) $response || !isset($response->alternative)) {
                $this->output('no response');
                usleep(Constants::API_DELAY_MODERATE);
                continue;
            }

            $alternatives = [];
            foreach ($response->alternative as $alt) {
                $alternatives[] = [
                    'res_num' => (string)($alt->ResNum ?? ''),
                    'hotel_id' => (string)($alt->IdHotel ?? ''),
                    'package_name' => (string)($alt->PackageName ?? ''),
                    'room_id' => (string)($alt->IdRoom ?? ''),
                    'check_in' => (string)($alt->CheckIn ?? ''),
                    'check_out' => (string)($alt->CheckOut ?? ''),
                    'board_id' => (string)($alt->IdBoard ?? ''),
                    'quota' => (string)($alt->Quota ?? ''),
                    'total' => (string)($alt->Total ?? ''),
                ];
            }

            if (empty($alternatives)) {
                $this->output('no alternatives yet');
                usleep(Constants::API_DELAY_MODERATE);
                continue;
            }

            $altRepo->markAlternativesFound($requestId, (string) json_encode($alternatives));
            $found++;
            $this->output('FOUND ' . count($alternatives) . ' alternatives', false);

            // Send email notification
            if (!empty($request['contact_email'])) {
                $sent = $this->sendAlternativeEmail($request, $alternatives);
                if ($sent) {
                    $emailed++;
                    $altRepo->markNotified($requestId);
                    $this->output(' -> Email sent');
                } else {
                    $this->output(' -> Email FAILED');
                }
            } else {
                $this->output('');
            }

            usleep(Constants::API_DELAY_MODERATE);
        }

        $this->output('');
        $this->output('Checked: ' . count($pending));
        $this->output("Found alternatives: {$found}");
        $this->output("Emails sent: {$emailed}");

        return ['success' => true, 'stats' => ['checked' => count($pending), 'found' => $found, 'emailed' => $emailed]];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkBookingAlternatives(): array
    {
        $this->output('Checking alternatives for RQ status bookings...');
        $this->output('');

        $bookingRepo = Container::getInstance()->bookingRepository();
        $bookings = $bookingRepo->findRqWithoutAlternatives();

        if (empty($bookings)) {
            $this->output('No RQ bookings to check.');
            return ['success' => true, 'stats' => ['checked' => 0]];
        }

        $this->output('Found ' . count($bookings) . ' RQ bookings to check.');
        $this->output('');

        foreach ($bookings as $booking) {
            $bookingId = TypeCoerce::toInt($booking['booking_id']);
            $this->output("Booking #{$bookingId}... ", false);

            // Reservation reference: confirmation id from booking, else the
            // ResNum learned via resinfo (same precedence as ResInfoCommand).
            $reservationRef = TypeCoerce::toString($booking['novoton_confirm_id'] ?? '')
                ?: TypeCoerce::toString($booking['novoton_res_num'] ?? '');

            if ($reservationRef !== '') {
                $this->api->reservations()->getAlternatives($reservationRef);
                $bookingRepo->update($bookingId, ['alternatives_requested' => 1]);
                $this->output('checked');
            } else {
                $this->output('no reservation ID');
            }
            usleep(Constants::API_DELAY_MODERATE);
        }

        return ['success' => true, 'stats' => ['checked' => count($bookings)]];
    }

    /**
     * @return array<string, mixed>
     */
    private function notifyAlternatives(): array
    {
        $this->output('Sending notifications for found alternatives...');
        $this->output('');

        $altRepo = Container::getInstance()->alternativeRequestRepository();
        $requests = $altRepo->findUnnotified();
        $requests = fn_novoton_holidays_decrypt_requests_pii($requests);

        if (empty($requests)) {
            $this->output('No requests with alternatives to notify.');
            return ['success' => true, 'stats' => ['notified' => 0]];
        }

        $this->output('Found ' . count($requests) . ' requests to notify.');
        $this->output('');

        $notified = 0;
        foreach ($requests as $request) {
            $requestId = TypeCoerce::toInt($request['request_id']);
            $contactEmail = TypeCoerce::toString($request['contact_email']);
            $this->output("Request #{$requestId} ({$contactEmail})... ", false);

            $alternatives = json_decode(TypeCoerce::toString($request['alternatives_data']), true);
            if (empty($alternatives)) {
                $this->output('no alternatives data');
                continue;
            }

            $sent = $this->sendAlternativeEmail($request, TypeCoerce::toRowList($alternatives));
            if ($sent) {
                $altRepo->markNotified($requestId);
                $notified++;
                $this->output('SENT');
            } else {
                $this->output('FAILED');
            }
        }

        $this->output('');
        $this->output("Notified: {$notified}");
        return ['success' => true, 'stats' => ['notified' => $notified]];
    }

    /**
     * @return array<string, mixed>
     */
    private function expireRequests(): array
    {
        $days = TypeCoerce::toInt($this->getParam('days', 30));
        $this->output("Expiring requests older than {$days} days...");

        $altRepo = Container::getInstance()->alternativeRequestRepository();
        $result = $altRepo->expireOlderThan($days);

        $this->output("Expired {$result} requests.");
        return ['success' => true, 'stats' => ['expired' => $result]];
    }

    /**
     * @param array<string, mixed> $request
     * @param list<array<string, mixed>> $alternatives
     */
    private function sendAlternativeEmail(array $request, array $alternatives): bool
    {
        try {
            return AlternativeEmail::sendFound($request, $alternatives);
        } catch (\Exception) {
            return false;
        }
    }
}
