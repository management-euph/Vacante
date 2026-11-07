<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

use Tygh\Addons\TravelCore\Dto\Circuit\CircuitDetails;
use Tygh\Addons\TravelCore\Dto\Circuit\CircuitPage;

/**
 * Provider-specific bridge between a CS-Cart product and a circuit.
 *
 * A provider add-on that sells circuits registers an implementation with
 * TravelProviderRegistry::setCircuitProductProvider(); travel_core asks
 * TravelProviderRegistry::resolveCircuit() when a product page is not a
 * hotel's (board 16: the page's layout follows the product's kind).
 */
interface CircuitProductProviderInterface
{
    /**
     * Try to claim a product as a circuit of this provider.
     *
     * Must return null (not throw) when the product is not one of its
     * circuits, or the circuit is no longer sold.
     *
     * @param int $productId CS-Cart product_id
     * @param string $productCode CS-Cart product_code (may be empty)
     *
     * @return CircuitPage|null null when this provider does not own the product
     */
    public function resolveCircuit(int $productId, string $productCode): ?CircuitPage;

    /**
     * What the circuit's page shows under its rooms (board 16.3): its
     * program day by day, what the price includes and does not, the things
     * to know.
     *
     * Must return null (not throw) when the circuit is not one of this
     * provider's, or no longer sold.
     *
     * @param string $circuitId the provider's circuit id (CircuitPage::$circuitId)
     */
    public function circuitDetails(string $circuitId): ?CircuitDetails;
}
