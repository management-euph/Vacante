<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Cron Service
 *
 * Centralized service for cron job operations.
 * Extracted from novoton_cron.php for better maintainability.
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\ReservationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepository;
use Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

class CronService implements CronServiceInterface
{
    private readonly ReservationApiClientInterface $reservations;
    private readonly BookingRepositoryInterface $bookingRepo;
    private readonly AlternativeRequestRepositoryInterface $altRequestRepo;
    /** @var list<string> */
    private array $countries;

    /**
     * CronService only uses the reservations sub-client (getReservationInfo
     * for ASK polling, getAlternatives for alternative-request polling), so
     * the dependency is narrowed to ReservationApiClientInterface rather
     * than the whole NovotonApiKitInterface.
     *
     * The lazy fallback `(new NovotonApi())->reservations()` keeps the
     * zero-argument construction path working so existing test harnesses
     * that do `new CronService()` don't break — same pattern as
     * AlternativeRequestService from PR #5.
     */
    public function __construct(
        ?BookingRepositoryInterface $bookingRepo = null,
        ?AlternativeRequestRepositoryInterface $altRequestRepo = null,
        ?ReservationApiClientInterface $reservations = null,
    ) {
        $this->reservations = $reservations ?? (new NovotonApi())->reservations();
        $this->bookingRepo = $bookingRepo ?? new \Tygh\Addons\NovotonHolidays\Repository\BookingRepository();
        $this->altRequestRepo = $altRequestRepo ?? new AlternativeRequestRepository();
        $this->countries = fn_novoton_holidays_parse_countries();
    }

    /**
     * Check ASK status bookings
     * Polls API for status updates on pending ASK bookings
     *
     * @return array<string, mixed> Results with updated/unchanged/errors counts
     */
    public function checkAskBookings(): array
    {
        $results = [
            'processed' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'details' => [],
        ];

        $bookings = $this->bookingRepo->findByNovotonStatus(
            Constants::NOVOTON_STATUS_ON_REQUEST,
            [TravelConstants::STATUS_PENDING, TravelConstants::STATUS_ASK],
        );

        foreach ($bookings as $booking) {
            $results['processed']++;

            try {
                $idNum = ($booking['novoton_invoice_id'] ?? '') ?: ($booking['novoton_confirm_id'] ?? '');
                if (empty($idNum)) {
                    $results['errors']++;
                    continue;
                }

                $resInfo = $this->reservations->getReservationInfo(TypeCoerce::toString($idNum));

                if (!(bool) $resInfo) {
                    $results['unchanged']++;
                    continue;
                }

                $newStatus = (string)($resInfo->Status ?? '');

                if (!empty($newStatus) && $newStatus !== $booking['novoton_status']) {
                    // Status changed — route through repository to sync travel_bookings
                    $csStatus = $this->mapNovotonStatus($newStatus);

                    $this->bookingRepo->updateStatus(
                        TypeCoerce::toInt($booking['booking_id']),
                        $csStatus,
                        $newStatus,
                    );
                    // last_status_check is novoton-specific, update directly
                    $this->bookingRepo->update(
                        TypeCoerce::toInt($booking['booking_id']),
                        ['last_status_check' => date('Y-m-d H:i:s')],
                    );

                    $results['updated']++;
                    $results['details'][] = [
                        'booking_id' => $booking['booking_id'],
                        'hotel' => $booking['hotel_name'],
                        'old_status' => $booking['novoton_status'],
                        'new_status' => $newStatus,
                    ];
                } else {
                    $this->bookingRepo->update(
                        TypeCoerce::toInt($booking['booking_id']),
                        ['last_status_check' => date('Y-m-d H:i:s')],
                    );
                    $results['unchanged']++;
                }
            } catch (\Exception $e) {
                $results['errors']++;
                fn_log_event('general', 'runtime', [
                    'message' => 'Cron: Error checking ASK booking',
                    'booking_id' => $booking['booking_id'],
                    'error' => $e->getMessage(),
                ]);
            }

            usleep(Constants::API_DELAY_MODERATE);
        }

        return $results;
    }

    /**
     * Check pending alternative requests
     * Polls API for alternatives on pending requests
     *
     * @return array<string, mixed> Results
     */
    public function checkAlternatives(): array
    {
        $results = [
            'processed' => 0,
            'found' => 0,
            'notified' => 0,
            'errors' => 0,
        ];

        $pending = $this->altRequestRepo->findPendingWithApiRef(
        );
        // Decrypt encrypted PII (contact_email) for email sending
        $pending = fn_novoton_holidays_decrypt_requests_pii($pending);

        foreach ($pending as $request) {
            $results['processed']++;

            try {
                $response = $this->reservations->getAlternatives(TypeCoerce::toString($request['novoton_request_id']));

                if ((bool) $response && !empty($response->alternative)) {
                    // Found alternatives
                    $alternatives = [];
                    foreach ($response->alternative as $alt) {
                        $alternatives[] = [
                            'hotel_id' => (string)($alt->IdHotel ?? ''),
                            'hotel_name' => (string)($alt->Hotel ?? ''),
                            'price' => (string)($alt->Price ?? ''),
                            'room' => (string)($alt->Room ?? ''),
                            'board' => (string)($alt->Board ?? ''),
                        ];
                    }

                    $this->altRequestRepo->markAlternativesFound(
                        TypeCoerce::toInt($request['request_id']),
                        (string) json_encode($alternatives),
                    );

                    $results['found']++;

                    // Send notification email
                    if (!empty($request['contact_email'])) {
                        $this->sendAlternativesEmail($request, $alternatives);
                        $results['notified']++;
                    }
                }
            } catch (\Exception $e) {
                $results['errors']++;
                fn_log_event('general', 'runtime', [
                    'message' => 'Cron: Error checking alternatives',
                    'request_id' => $request['request_id'],
                    'error' => $e->getMessage(),
                ]);
            }

            usleep(Constants::API_DELAY_HEAVY);
        }

        return $results;
    }

    /**
     * Map Novoton status to CS-Cart booking status
     *
     * @param string $novotonStatus Status from API
     * @return string CS-Cart status
     */
    private function mapNovotonStatus(string $novotonStatus): string
    {
        return Constants::NOVOTON_STATUS_TO_INTERNAL[$novotonStatus] ?? TravelConstants::STATUS_PENDING;
    }

    /**
     * Send alternatives notification email
     *
     * @param array<string, mixed> $request Original request
     * @param list<array<string, mixed>> $alternatives Found alternatives
     */
    private function sendAlternativesEmail(array $request, array $alternatives): void
    {
        // Use CS-Cart mailer
        if (function_exists('fn_send_mail')) {
            fn_send_mail([
                'to' => $request['contact_email'],
                'from' => 'default_company_orders_department',
                'subj' => 'Alternative Hotels Available - ' . TypeCoerce::toString($request['hotel_name']),
                'body' => $this->formatAlternativesEmail($request, $alternatives),
            ]);

            $this->altRequestRepo->markNotified(TypeCoerce::toInt($request['request_id']));
        }
    }

    /**
     * Format alternatives email body
     *
     * @param array<string, mixed> $request Original request
     * @param list<array<string, mixed>> $alternatives Found alternatives
     * @return string Email body
     */
    private function formatAlternativesEmail(array $request, array $alternatives): string
    {
        $hotelName = TypeCoerce::toString($request['hotel_name']);
        $body = "We found alternative hotels for your request:\n\n";
        $body .= "Original hotel: {$hotelName}\n\n";
        $body .= "Available alternatives:\n";

        foreach ($alternatives as $i => $alt) {
            $altHotelName = TypeCoerce::toString($alt['hotel_name']);
            $altRoom = TypeCoerce::toString($alt['room']);
            $altBoard = TypeCoerce::toString($alt['board']);
            $altPrice = TypeCoerce::toString($alt['price']);
            $body .= ($i + 1) . ". {$altHotelName}\n";
            $body .= "   Room: {$altRoom}\n";
            $body .= "   Board: {$altBoard}\n";
            $body .= "   Price: {$altPrice}\n\n";
        }

        return $body;
    }

    /**
     * Get countries configured for sync
     *
     * @return list<string>
     */
    public function getCountries(): array
    {
        return $this->countries;
    }
}
