<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;

/**
 * Verifies hotel room prices via the Novoton API.
 *
 * Extracted from BookingService (SRP) — isolates the external API call,
 * commission application, and terms extraction into a focused service.
 *
 * Complements PreOrderPriceVerifier (which handles session-cached checks
 * at checkout time). This service handles the full API verification
 * during booking creation.
 *
 * Depends only on the pricing sub-client — a focused 9-method contract —
 * rather than the full 46-method NovotonApi facade.
 *
 * @package NovotonHolidays
 * @since   3.4.0
 */
class PriceVerificationService implements PriceVerificationServiceInterface
{
    private readonly PricingApiClientInterface $pricing;
    private readonly bool $debug;

    public function __construct(PricingApiClientInterface $pricing)
    {
        $this->pricing = $pricing;
        $this->debug = ConfigProvider::isDebugLogging();
    }

    /**
     * Verify price via room_price API and extract terms.
     *
     * Calls the Novoton room_price API with the given parameters, takes the
     * price of the offer being booked (same room, board and — when given —
     * package, as PreOrderPriceVerifier::lineOffer picks it), applies
     * commission, and extracts terms of payment/cancellation from the response.
     * The first <Price> of the answer can belong to another package, so a
     * booking whose own offer is gone fails instead of taking that price.
     *
     * @param array<string, mixed> $params {hotel_id, room_id, board_id, check_in, check_out, adults, children_ages: int[], package_name?: string}
     * @return array{success: bool, total_price: float, base_price: float, terms_of_payment: string, terms_of_cancellation: string, remark: string, important: string, error: string}
     */
    #[\Override]
    public function verifyPrice(array $params): array
    {
        $priceParams = [
            'hotel_id' => $params['hotel_id'],
            'room_id' => $params['room_id'] ?? '',
            'board_id' => $params['board_id'] ?? '',
            'star_rating' => '',
            'check_in' => $params['check_in'],
            'check_out' => $params['check_out'],
            'adults' => PriceInfoFormatter::toInt($params['adults'] ?? 2),
            'children' => $params['children_ages'] ?? [],
        ];

        $priceData = $this->pricing->getRoomPrice($priceParams);
        $offer = $priceData instanceof \SimpleXMLElement
            ? PreOrderPriceVerifier::lineOffer(RoomOfferRows::fromXml($priceData), $params)['row']
            : null;

        if (!$priceData instanceof \SimpleXMLElement || $offer === null) {
            $this->log('Price verification failed', [
                'hotel_id' => $params['hotel_id'],
                'room_id' => $params['room_id'] ?? '',
                'package_name' => $params['package_name'] ?? '',
                'children_ages' => $params['children_ages'] ?? [],
            ]);
            return [
                'success' => false,
                'total_price' => 0,
                'base_price' => 0,
                'terms_of_payment' => '',
                'terms_of_cancellation' => '',
                'remark' => '',
                'important' => '',
                'error' => 'price_verification_failed',
            ];
        }

        $rawPrice = $offer['price'];
        $totalPrice = $this->pricing->applyCommission($rawPrice);

        // Extract terms
        $termsOfPayment = '';
        $termsOfCancellation = '';
        $tp = $priceData->xpath('//TermsOfPayment');
        $tc = $priceData->xpath('//TermsOfCancellation');
        if (!empty($tp[0])) {
            $termsOfPayment = (string) $tp[0]->asXML();
        }
        if (!empty($tc[0])) {
            $termsOfCancellation = (string) $tc[0]->asXML();
        }

        return [
            'success' => true,
            'total_price' => $totalPrice,
            'base_price' => $rawPrice,
            'terms_of_payment' => $termsOfPayment,
            'terms_of_cancellation' => $termsOfCancellation,
            'remark' => isset($priceData->remark) ? (string) $priceData->remark : '',
            'important' => isset($priceData->Important) ? (string) $priceData->Important : '',
            'error' => '',
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->debug) {
            fn_log_event('general', 'runtime', array_merge(
                ['message' => 'NovotonPrice: ' . $message],
                $context,
            ));
        }
    }
}
